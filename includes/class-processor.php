<?php
defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates a single queue job end-to-end:
 *   backup → optimize → write WebP → delete original → update DB → mark done
 */
class Woo_Image_Optimizer_Processor {

	private Woo_Image_Optimizer_Queue      $queue;
	private Woo_Image_Optimizer_API_Client $api;
	private Woo_Image_Optimizer_DB_Updater $db;
	private Woo_Image_Optimizer_Settings   $settings;

	public function __construct(
		Woo_Image_Optimizer_Queue      $queue,
		Woo_Image_Optimizer_API_Client $api,
		Woo_Image_Optimizer_DB_Updater $db,
		Woo_Image_Optimizer_Settings   $settings
	) {
		$this->queue    = $queue;
		$this->api      = $api;
		$this->db       = $db;
		$this->settings = $settings;
	}

	/**
	 * Process $count pending jobs from the queue.
	 *
	 * @return array{processed:int,errors:string[],saved_bytes:int}
	 */
	public function process_batch( int $count ): array {
		$this->queue->reset_stale();

		$jobs = $this->queue->dequeue_batch( $count );

		$processed   = 0;
		$errors      = [];
		$saved_bytes = 0;

		foreach ( $jobs as $job ) {
			$result = $this->process_job( $job );

			if ( is_wp_error( $result ) ) {
				$errors[] = "Attachment #{$job->attachment_id}: " . $result->get_error_message();
				error_log( '[WooImageOptimizer] ' . $result->get_error_message() );
			} else {
				$processed++;
				$saved_bytes += $result['saved_bytes'] ?? 0;
			}
		}

		return compact( 'processed', 'errors', 'saved_bytes' );
	}

	/**
	 * Process one queue job.
	 *
	 * @return array{saved_bytes:int}|WP_Error
	 */
	public function process_job( object $job ) {
		$attachment_id = (int) $job->attachment_id;
		$job_id        = (int) $job->id;
		$attempts      = (int) $job->attempts;

		// --- 1. Resolve file path ---
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			// Detect corrupted state: _wp_attached_file points to a missing .webp from a prior
			// partial optimization. Report clearly rather than retrying silently.
			$attached = get_post_meta( $attachment_id, '_wp_attached_file', true );
			$suffix   = strtolower( pathinfo( (string) $attached, PATHINFO_EXTENSION ) );
			if ( $suffix === 'webp' ) {
				return $this->fail_job( $job_id, 3, // force to failed immediately
					"Attachment #{$attachment_id}: _wp_attached_file points to a missing WebP ({$attached}). " .
					'The file was deleted without restoring the original. Fix the attachment meta manually.'
				);
			}
			return $this->fail_job( $job_id, $attempts, "File not found for attachment #{$attachment_id}: {$file_path}" );
		}

		// --- 2. Backup (skip if already backed up from a previous attempt) ---
		$backup_key = get_post_meta( $attachment_id, '_woo_optimizer_backup_key', true );
		if ( ! $backup_key ) {
			$backup_result = $this->api->backup( $file_path, $attachment_id );
			if ( is_wp_error( $backup_result ) ) {
				return $this->fail_job( $job_id, $attempts, $backup_result->get_error_message() );
			}
			$backup_key = $backup_result['backup_key'];
			// Store immediately — if optimize fails later, restore is still possible.
			update_post_meta( $attachment_id, '_woo_optimizer_backup_key', $backup_key );
		}

		// --- 3. Optimize ---
		$opt_result = $this->api->optimize(
			$file_path,
			$attachment_id,
			$this->settings->get( 'webp_quality', 82 ),
			$this->settings->get( 'max_width',     2048 ),
			$this->settings->get( 'max_height',    2048 )
		);
		if ( is_wp_error( $opt_result ) ) {
			return $this->fail_job( $job_id, $attempts, $opt_result->get_error_message() );
		}

		// --- 4. Decode & write WebP ---
		$webp_binary = base64_decode( $opt_result['webp_file'], true );
		if ( $webp_binary === false || $webp_binary === '' ) {
			return $this->fail_job( $job_id, $attempts, 'Server returned invalid base64 WebP data' );
		}

		$webp_path = $this->webp_path( $file_path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( file_put_contents( $webp_path, $webp_binary ) === false ) {
			return $this->fail_job( $job_id, $attempts, "Failed to write WebP file: {$webp_path}" );
		}
		unset( $webp_binary ); // free decoded buffer before thumbnail regeneration

		// --- 5. Capture original metadata BEFORE any DB changes (for thumbnail cleanup) ---
		$original_meta = wp_get_attachment_metadata( $attachment_id );

		// Also store original _wp_attached_file for restore
		$original_attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		update_post_meta( $attachment_id, '_woo_optimizer_original_file', $original_attached_file );
		update_post_meta( $attachment_id, '_woo_optimizer_original_mime', get_post_mime_type( $attachment_id ) );

		// --- 6. Delete original file (safely backed up on Server 2) ---
		wp_delete_file( $file_path );

		// --- 7. Update WordPress DB ---
		$db_result = $this->db->update_all(
			$attachment_id,
			$opt_result,
			$backup_key,
			$webp_path,
			is_array( $original_meta ) ? $original_meta : []
		);

		if ( is_wp_error( $db_result ) ) {
			return $this->fail_job( $job_id, $attempts, $db_result->get_error_message() );
		}

		// --- 8. Mark done ---
		$this->queue->mark_done( $job_id );

		return [ 'saved_bytes' => (int) ( $opt_result['saved_bytes'] ?? 0 ) ];
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	private function webp_path( string $original_path ): string {
		return preg_replace( '/\.(jpe?g|png|gif)$/i', '.webp', $original_path );
	}

	/** @return WP_Error */
	private function fail_job( int $job_id, int $attempts, string $message ) {
		if ( $attempts >= 3 ) {
			$this->queue->mark_failed( $job_id, $message );
		} else {
			$this->queue->retry( $job_id );
		}
		return new WP_Error( 'job_failed', $message );
	}
}
