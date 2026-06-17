<?php
defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI commands for WooCommerce Image Optimizer.
 *
 * Usage:
 *   wp woo-optimizer run
 *   wp woo-optimizer stats
 *   wp woo-optimizer queue-all [--dry-run]
 *   wp woo-optimizer restore <attachment_id>
 */
class Woo_Image_Optimizer_CLI {

	/**
	 * Process pending jobs from the queue (one batch).
	 *
	 * @when after_wp_load
	 */
	public function run( array $args, array $assoc_args ): void {
		$inst       = woo_image_optimizer();
		$batch_size = (int) $inst['settings']->get( 'batch_size', 5 );

		WP_CLI::line( "Processing up to {$batch_size} pending jobs…" );

		$result = $inst['processor']->process_batch( $batch_size );

		WP_CLI::success( sprintf(
			'Done. Processed: %d | Errors: %d | Saved: %s',
			$result['processed'],
			count( $result['errors'] ),
			size_format( $result['saved_bytes'] )
		) );

		foreach ( $result['errors'] as $err ) {
			WP_CLI::warning( $err );
		}
	}

	/**
	 * Show queue statistics.
	 *
	 * @when after_wp_load
	 */
	public function stats( array $args, array $assoc_args ): void {
		$stats = woo_image_optimizer()['queue']->get_stats();

		WP_CLI::line( '' );
		WP_CLI\Utils\format_items( 'table', [
			[ 'Metric' => 'Total queued',   'Value' => $stats['total'] ],
			[ 'Metric' => 'Done',           'Value' => $stats['done'] ],
			[ 'Metric' => 'Pending',        'Value' => $stats['pending'] ],
			[ 'Metric' => 'Processing',     'Value' => $stats['processing'] ],
			[ 'Metric' => 'Failed',         'Value' => $stats['failed'] ],
			[ 'Metric' => 'Bytes saved',    'Value' => size_format( $stats['saved_bytes'] ) ],
		], [ 'Metric', 'Value' ] );
		WP_CLI::line( '' );
	}

	/**
	 * Enqueue all unoptimized WooCommerce product images.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List images that would be queued without actually queuing them.
	 *
	 * @subcommand queue-all
	 * @when after_wp_load
	 */
	public function queue_all( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );
		$inst    = woo_image_optimizer();

		WP_CLI::line( 'Scanning for unoptimized WooCommerce product images…' );

		$ids = $inst['woocommerce']->get_all_unoptimized_ids();

		if ( empty( $ids ) ) {
			WP_CLI::success( 'No unoptimized product images found.' );
			return;
		}

		WP_CLI::line( 'Found ' . count( $ids ) . ' image(s).' );

		if ( $dry_run ) {
			WP_CLI::warning( 'Dry run — no changes will be made.' );
			foreach ( $ids as $id ) {
				WP_CLI::line( sprintf( '  [%d] %s', $id, get_the_title( $id ) ) );
			}
			return;
		}

		$queued  = 0;
		$skipped = 0;

		foreach ( $ids as $attachment_id ) {
			$product_id = $inst['woocommerce']->get_product_id_for_attachment( $attachment_id );
			if ( $inst['queue']->enqueue( $attachment_id, $product_id ) ) {
				$queued++;
			} else {
				$skipped++;
			}
		}

		WP_CLI::success( "Queued: {$queued} | Already queued/done: {$skipped}" );
		WP_CLI::line( 'Run `wp woo-optimizer run` to process, or wait for WP-Cron (every minute).' );
	}

	/**
	 * Restore an attachment from Server 2 backup.
	 *
	 * ## OPTIONS
	 *
	 * <attachment_id>
	 * : The attachment post ID.
	 *
	 * @when after_wp_load
	 */
	public function restore( array $args, array $assoc_args ): void {
		$id     = (int) ( $args[0] ?? 0 );
		$result = woo_image_optimizer()['restore']->restore( $id );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( "[{$id}] Restored from Server 2 backup." );
	}

	/**
	 * Reset all failed jobs back to pending so they will be retried.
	 *
	 * @subcommand retry-failed
	 * @when after_wp_load
	 */
	public function retry_failed( array $args, array $assoc_args ): void {
		$reset = woo_image_optimizer()['queue']->retry_all_failed();

		if ( 0 === $reset ) {
			WP_CLI::line( 'No failed jobs found.' );
			return;
		}

		WP_CLI::success( "Reset {$reset} failed job(s) back to pending. Run `wp woo-optimizer run` to process." );
	}
}
