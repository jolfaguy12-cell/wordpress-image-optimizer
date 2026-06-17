<?php
defined( 'ABSPATH' ) || exit;

/**
 * Restores an attachment to its original file using the Server 2 backup.
 */
class Woo_Image_Optimizer_Restore {

	private Woo_Image_Optimizer_API_Client $api;
	private Woo_Image_Optimizer_Queue      $queue;

	public function __construct( Woo_Image_Optimizer_API_Client $api, Woo_Image_Optimizer_Queue $queue ) {
		$this->api   = $api;
		$this->queue = $queue;
	}

	/**
	 * Full restore flow for one attachment.
	 *
	 * @return true|WP_Error
	 */
	public function restore( int $attachment_id ) {
		$backup_key    = get_post_meta( $attachment_id, '_woo_optimizer_backup_key', true );
		$original_file = get_post_meta( $attachment_id, '_woo_optimizer_original_file', true );
		$original_mime = get_post_meta( $attachment_id, '_woo_optimizer_original_mime', true );

		if ( ! $backup_key ) {
			return new WP_Error( 'no_backup', "No backup key found for attachment #{$attachment_id}" );
		}
		if ( ! $original_file ) {
			return new WP_Error( 'no_original_path', "Original file path not stored for attachment #{$attachment_id}" );
		}

		// 1. Download original from Server 2
		$binary = $this->api->get_backup( $backup_key );
		if ( is_wp_error( $binary ) ) {
			return $binary;
		}

		// 2. Resolve filesystem paths
		$upload_dir         = wp_upload_dir();
		$original_full_path = trailingslashit( $upload_dir['basedir'] ) . ltrim( $original_file, '/' );
		$current_full_path  = get_attached_file( $attachment_id ); // current = .webp

		// Ensure the target directory exists
		wp_mkdir_p( dirname( $original_full_path ) );

		// 3. Write original file back
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( file_put_contents( $original_full_path, $binary ) === false ) {
			return new WP_Error( 'write_failed', "Could not write original file: {$original_full_path}" );
		}
		unset( $binary ); // free download buffer before thumbnail regeneration

		// 4. Collect current WebP thumbnail filenames BEFORE resetting metadata
		$current_meta   = wp_get_attachment_metadata( $attachment_id );
		$webp_size_files = [];
		if ( ! empty( $current_meta['sizes'] ) && ! empty( $current_meta['file'] ) ) {
			$subdir = trailingslashit( $upload_dir['basedir'] . '/' . dirname( $current_meta['file'] ) );
			foreach ( $current_meta['sizes'] as $size_data ) {
				$fn = $size_data['file'] ?? '';
				if ( $fn && strtolower( pathinfo( $fn, PATHINFO_EXTENSION ) ) === 'webp' ) {
					$webp_size_files[] = $subdir . $fn;
				}
			}
		}

		// 5. Regenerate metadata + thumbnails from the original
		$new_meta = wp_generate_attachment_metadata( $attachment_id, $original_full_path );
		if ( ! is_wp_error( $new_meta ) && ! empty( $new_meta ) ) {
			wp_update_attachment_metadata( $attachment_id, $new_meta );
		}

		// 6. Reset _wp_attached_file to original relative path
		update_post_meta( $attachment_id, '_wp_attached_file', $original_file );

		// 7. Reset post mime_type
		$mime = $original_mime ?: $this->mime_from_path( $original_full_path );
		wp_update_post( [
			'ID'             => $attachment_id,
			'post_mime_type' => $mime,
		] );

		// 8. Delete the .webp file that replaced the original
		if ( $current_full_path && $current_full_path !== $original_full_path && file_exists( $current_full_path ) ) {
			wp_delete_file( $current_full_path );
		}

		// 9. Delete WebP thumbnail size files
		foreach ( $webp_size_files as $webp_file ) {
			if ( file_exists( $webp_file ) ) {
				wp_delete_file( $webp_file );
			}
		}

		// 10. Clear all optimizer postmeta
		delete_post_meta( $attachment_id, '_woo_optimizer_status' );
		delete_post_meta( $attachment_id, '_woo_optimizer_backup_key' );
		delete_post_meta( $attachment_id, '_woo_optimizer_saved_bytes' );
		delete_post_meta( $attachment_id, '_woo_optimizer_original_size' );
		delete_post_meta( $attachment_id, '_woo_optimizer_optimized_size' );
		delete_post_meta( $attachment_id, '_woo_optimizer_optimized_at' );
		delete_post_meta( $attachment_id, '_woo_optimizer_original_file' );
		delete_post_meta( $attachment_id, '_woo_optimizer_original_mime' );

		// 11. Remove from queue (so it can be re-queued if needed)
		global $wpdb;
		$wpdb->delete(
			Woo_Image_Optimizer_Queue::table_name(),
			[ 'attachment_id' => $attachment_id ],
			[ '%d' ]
		);

		return true;
	}

	private function mime_from_path( string $path ): string {
		$map = [
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
		];
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return $map[ $ext ] ?? 'image/jpeg';
	}
}
