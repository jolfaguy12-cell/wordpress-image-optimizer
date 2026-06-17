<?php
defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI commands for Behdashtik Image Optimizer.
 *
 * Usage:
 *   wp bdsk-optimizer stats
 *   wp bdsk-optimizer bulk [--batch=5] [--dry-run]
 *   wp bdsk-optimizer single <attachment_id>
 *   wp bdsk-optimizer restore <attachment_id>
 *   wp bdsk-optimizer stress-test [--images=50] [--batch=5]
 */
class BDSK_Optimizer_CLI {

    /**
     * Show optimization statistics.
     *
     * @when after_wp_load
     */
    public function stats( $args, $assoc_args ) {
        $engine = bdsk_optimizer()['engine'];
        $stats  = $engine->get_stats();

        WP_CLI::line( '' );
        WP_CLI\Utils\format_items( 'table', [
            [ 'Metric' => 'Total images',      'Value' => $stats['total'] ],
            [ 'Metric' => 'Optimized',         'Value' => $stats['optimized'] . ' (' . $stats['percent'] . '%)' ],
            [ 'Metric' => 'Remaining',         'Value' => $stats['unoptimized'] ],
            [ 'Metric' => 'Total bytes saved', 'Value' => $stats['saved_human'] ],
            [ 'Metric' => 'Engine',            'Value' => $stats['engine'] ],
        ], [ 'Metric', 'Value' ] );
        WP_CLI::line( '' );
    }

    /**
     * Bulk optimize all unoptimized images.
     *
     * ## OPTIONS
     *
     * [--batch=<n>]
     * : Images per batch. Default: 5.
     *
     * [--dry-run]
     * : List images that would be processed without actually processing them.
     *
     * @when after_wp_load
     */
    public function bulk( $args, $assoc_args ) {
        $engine    = bdsk_optimizer()['engine'];
        $batch     = (int) ( $assoc_args['batch'] ?? 5 );
        $dry_run   = isset( $assoc_args['dry-run'] );
        $stats     = $engine->get_stats();
        $total     = $stats['unoptimized'];

        if ( $total === 0 ) {
            WP_CLI::success( 'All images are already optimized.' );
            return;
        }

        WP_CLI::line( "Found {$total} unoptimized images." );

        if ( $dry_run ) {
            WP_CLI::warning( 'Dry run — no files will be modified.' );
        }

        $progress  = WP_CLI\Utils\make_progress_bar( 'Optimizing', $total );
        $processed = 0;
        $saved     = 0;
        $errors    = 0;

        while ( true ) {
            $ids = $engine->get_unoptimized_ids( $batch );

            if ( empty( $ids ) ) {
                break;
            }

            foreach ( $ids as $id ) {
                if ( $dry_run ) {
                    WP_CLI::line( "  Would optimize: [{$id}] " . get_the_title( $id ) );
                } else {
                    $result = $engine->optimize_attachment( (int) $id );

                    if ( is_wp_error( $result ) ) {
                        WP_CLI::warning( "  [{$id}] " . $result->get_error_message() );
                        $errors++;
                    } else {
                        $saved += $result['total_saved'];
                        $processed++;
                    }
                }

                $progress->tick();
            }

            // Avoid memory accumulation on very large libraries
            if ( function_exists( 'gc_collect_cycles' ) ) {
                gc_collect_cycles();
            }
        }

        $progress->finish();

        if ( ! $dry_run ) {
            WP_CLI::success( sprintf(
                'Done. Processed: %d | Errors: %d | Saved: %s',
                $processed,
                $errors,
                size_format( $saved )
            ) );
        }
    }

    /**
     * Optimize a single attachment.
     *
     * ## OPTIONS
     *
     * <attachment_id>
     * : The attachment post ID.
     *
     * @when after_wp_load
     */
    public function single( $args, $assoc_args ) {
        $id     = (int) ( $args[0] ?? 0 );
        $engine = bdsk_optimizer()['engine'];
        $result = $engine->optimize_attachment( $id );

        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }

        WP_CLI::success( sprintf(
            '[%d] Optimized. Saved: %s',
            $id,
            size_format( $result['total_saved'] )
        ) );
    }

    /**
     * Restore an attachment from backup.
     *
     * ## OPTIONS
     *
     * <attachment_id>
     * : The attachment post ID.
     *
     * @when after_wp_load
     */
    public function restore( $args, $assoc_args ) {
        $id     = (int) ( $args[0] ?? 0 );
        $engine = bdsk_optimizer()['engine'];
        $result = $engine->restore_attachment( $id );

        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }

        WP_CLI::success( "[{$id}] Restored from backup." );
    }

    /**
     * Stress-test the optimizer and report throughput.
     *
     * ## OPTIONS
     *
     * [--images=<n>]
     * : Number of images to process. Default: 50.
     *
     * [--batch=<n>]
     * : Batch size. Default: 5.
     *
     * @subcommand stress-test
     * @when after_wp_load
     */
    public function stress_test( $args, $assoc_args ) {
        $engine     = bdsk_optimizer()['engine'];
        $image_limit = (int) ( $assoc_args['images'] ?? 50 );
        $batch      = (int) ( $assoc_args['batch'] ?? 5 );

        WP_CLI::line( "Stress test: {$image_limit} images, batch size {$batch}" );
        WP_CLI::line( str_repeat( '-', 60 ) );

        $ids       = $engine->get_unoptimized_ids( $image_limit );
        $total     = count( $ids );

        if ( $total === 0 ) {
            WP_CLI::warning( 'No unoptimized images found. Run "wp bdsk-optimizer stats" first.' );
            return;
        }

        $start_time   = microtime( true );
        $start_memory = memory_get_usage( true );
        $processed    = 0;
        $errors       = 0;
        $total_saved  = 0;
        $batch_times  = [];

        foreach ( array_chunk( $ids, $batch ) as $chunk ) {
            $batch_start = microtime( true );
            $batch_saved = 0;

            foreach ( $chunk as $id ) {
                $result = $engine->optimize_attachment( (int) $id );

                if ( is_wp_error( $result ) ) {
                    $errors++;
                } else {
                    $processed++;
                    $batch_saved  += $result['total_saved'];
                    $total_saved  += $result['total_saved'];
                }
            }

            $batch_time    = round( ( microtime( true ) - $batch_start ) * 1000 );
            $batch_times[] = $batch_time;
            $mem_mb        = round( memory_get_usage( true ) / 1024 / 1024, 1 );

            WP_CLI::line( sprintf(
                '  Batch %d/%d — %dms — saved %s — mem %sMB',
                (int) ceil( $processed / $batch ),
                (int) ceil( $total / $batch ),
                $batch_time,
                size_format( $batch_saved ),
                $mem_mb
            ) );

            if ( function_exists( 'gc_collect_cycles' ) ) {
                gc_collect_cycles();
            }
        }

        $elapsed     = round( microtime( true ) - $start_time, 2 );
        $peak_memory = round( memory_get_peak_usage( true ) / 1024 / 1024, 1 );
        $avg_batch   = count( $batch_times ) > 0 ? round( array_sum( $batch_times ) / count( $batch_times ) ) : 0;
        $throughput  = $elapsed > 0 ? round( $processed / $elapsed, 1 ) : 0;

        WP_CLI::line( str_repeat( '-', 60 ) );
        WP_CLI\Utils\format_items( 'table', [
            [ 'Metric' => 'Images processed',  'Value' => $processed ],
            [ 'Metric' => 'Errors',            'Value' => $errors ],
            [ 'Metric' => 'Total saved',       'Value' => size_format( $total_saved ) ],
            [ 'Metric' => 'Total time',        'Value' => $elapsed . 's' ],
            [ 'Metric' => 'Throughput',        'Value' => $throughput . ' images/sec' ],
            [ 'Metric' => 'Avg batch time',    'Value' => $avg_batch . 'ms' ],
            [ 'Metric' => 'Peak memory',       'Value' => $peak_memory . ' MB' ],
        ], [ 'Metric', 'Value' ] );
    }
}
