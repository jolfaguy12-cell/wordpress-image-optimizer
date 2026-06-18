<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles all WordPress database writes that follow a successful optimization.
 * Only touches what the flow document specifies — nothing else.
 */
class Woo_Image_Optimizer_DB_Updater {

	/**
	 * Run every DB update after optimization succeeds.
	 *
	 * @param int    $attachment_id
	 * @param array  $api_response   Decoded JSON from Server 2 /optimize endpoint.
	 * @param string $backup_key     Backup key stored on Server 2.
	 * @param string $webp_full_path Absolute path of the new .webp file on disk.
	 * @param array  $original_meta  Attachment metadata captured BEFORE the update (for old thumb cleanup).
	 * @return true|WP_Error
	 */
	public function update_all(
		int $attachment_id,
		array $api_response,
		string $backup_key,
		string $webp_full_path,
		array $original_meta
	) {
		$upload_dir = wp_upload_dir();

		// 7.1 — _wp_attached_file → relative path to new .webp
		$relative_webp = ltrim( str_replace( $upload_dir['basedir'], '', $webp_full_path ), '/\\' );
		update_post_meta( $attachment_id, '_wp_attached_file', $relative_webp );

		// 8 — Regenerate all thumbnail sizes from the new WebP.
		// Disable big-image scaling for this call: our WebP is already web-sized (≤ max_width/max_height),
		// and the scaling hook would overwrite _wp_attached_file with a "*-scaled.webp" path, corrupting
		// all future meta reads and restores.
		add_filter( 'big_image_size_threshold', '__return_false' );
		$new_meta = wp_generate_attachment_metadata( $attachment_id, $webp_full_path );
		remove_filter( 'big_image_size_threshold', '__return_false' );
		// Re-assert the correct path: any hook that ran inside the call may have changed it.
		update_post_meta( $attachment_id, '_wp_attached_file', $relative_webp );
		if ( ! is_wp_error( $new_meta ) && ! empty( $new_meta ) ) {
			wp_update_attachment_metadata( $attachment_id, $new_meta );
		}

		// 7.3 — Update post mime_type
		wp_update_post( [
			'ID'             => $attachment_id,
			'post_mime_type' => 'image/webp',
		] );

		// 7.4 — Store optimizer metadata
		$this->store_optimizer_meta( $attachment_id, $backup_key, $api_response );

		// Delete old .jpg/.png thumbnail size files (user confirmed: delete them)
		$this->delete_old_thumbnail_files( $upload_dir['basedir'], $original_meta );

		return true;
	}

	/**
	 * Store all _woo_optimizer_* postmeta.
	 */
	public function store_optimizer_meta( int $attachment_id, string $backup_key, array $api_response ): void {
		update_post_meta( $attachment_id, '_woo_optimizer_status',         'done' );
		update_post_meta( $attachment_id, '_woo_optimizer_backup_key',     $backup_key );
		update_post_meta( $attachment_id, '_woo_optimizer_saved_bytes',    (int) ( $api_response['saved_bytes']     ?? 0 ) );
		update_post_meta( $attachment_id, '_woo_optimizer_original_size',  (int) ( $api_response['original_size']  ?? 0 ) );
		update_post_meta( $attachment_id, '_woo_optimizer_optimized_size', (int) ( $api_response['optimized_size'] ?? 0 ) );
		update_post_meta( $attachment_id, '_woo_optimizer_optimized_at',   current_time( 'mysql' ) );
	}

	/**
	 * Delete old .jpg/.png size files after WebP thumbnails have been regenerated.
	 *
	 * @param string $basedir       Absolute path to uploads base dir.
	 * @param array  $original_meta Metadata array saved before optimization (contains 'file' and 'sizes').
	 */
	public function delete_old_thumbnail_files( string $basedir, array $original_meta ): void {
		if ( empty( $original_meta['sizes'] ) || empty( $original_meta['file'] ) ) {
			return;
		}

		// Sizes are relative to the same year/month subdir as the original
		$subdir = trailingslashit( $basedir . '/' . dirname( $original_meta['file'] ) );

		foreach ( $original_meta['sizes'] as $size_data ) {
			$filename = $size_data['file'] ?? '';
			if ( ! $filename ) {
				continue;
			}
			$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, [ 'jpg', 'jpeg', 'png', 'gif' ], true ) ) {
				continue;
			}
			$full_path = $subdir . $filename;
			if ( file_exists( $full_path ) ) {
				wp_delete_file( $full_path );
			}
		}
	}
}
