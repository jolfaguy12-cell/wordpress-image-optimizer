<?php
defined( 'ABSPATH' ) || exit;

class BDSK_Optimizer_Bulk {

    private $engine;

    public function __construct( BDSK_Optimizer_Engine $engine ) {
        $this->engine = $engine;

        add_action( 'wp_ajax_bdsk_optimizer_bulk',    [ $this, 'ajax_bulk' ] );
        add_action( 'wp_ajax_bdsk_optimizer_single',  [ $this, 'ajax_single' ] );
        add_action( 'wp_ajax_bdsk_optimizer_restore', [ $this, 'ajax_restore' ] );
        add_action( 'wp_ajax_bdsk_optimizer_stats',   [ $this, 'ajax_stats' ] );

        // Auto-optimize on upload
        add_filter( 'wp_generate_attachment_metadata', [ $this, 'auto_optimize' ], 20, 2 );
    }

    public function auto_optimize( $metadata, $attachment_id ) {
        $settings = bdsk_optimizer()['settings'];
        if ( ! $settings->get( 'auto_optimize' ) ) {
            return $metadata;
        }

        $mime = get_post_mime_type( $attachment_id );
        if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/gif' ], true ) ) {
            return $metadata;
        }

        $this->engine->optimize_attachment( $attachment_id );

        return $metadata;
    }

    public function ajax_bulk() {
        check_ajax_referer( 'bdsk_optimizer_nonce', 'nonce' );

        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $batch_size = (int) ( $_POST['batch_size'] ?? 5 );
        $batch_size = min( 20, max( 1, $batch_size ) );

        $ids = $this->engine->get_unoptimized_ids( $batch_size );

        if ( empty( $ids ) ) {
            wp_send_json_success( [ 'done' => true ] );
        }

        $processed = [];
        $errors    = [];

        foreach ( $ids as $id ) {
            $result = $this->engine->optimize_attachment( (int) $id );

            if ( is_wp_error( $result ) ) {
                $errors[] = [ 'id' => $id, 'error' => $result->get_error_message() ];
            } else {
                $processed[] = [
                    'id'          => $id,
                    'title'       => get_the_title( $id ),
                    'saved_bytes' => $result['total_saved'],
                    'saved_human' => size_format( $result['total_saved'] ),
                ];
            }
        }

        $stats = $this->engine->get_stats();

        wp_send_json_success( [
            'done'        => false,
            'processed'   => $processed,
            'errors'      => $errors,
            'stats'       => $stats,
        ] );
    }

    public function ajax_single() {
        check_ajax_referer( 'bdsk_optimizer_nonce', 'nonce' );

        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $id     = (int) ( $_POST['attachment_id'] ?? 0 );
        $result = $this->engine->optimize_attachment( $id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( [
            'id'          => $id,
            'saved_bytes' => $result['total_saved'],
            'saved_human' => size_format( $result['total_saved'] ),
        ] );
    }

    public function ajax_restore() {
        check_ajax_referer( 'bdsk_optimizer_nonce', 'nonce' );

        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $id     = (int) ( $_POST['attachment_id'] ?? 0 );
        $result = $this->engine->restore_attachment( $id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( [ 'id' => $id, 'restored' => true ] );
    }

    public function ajax_stats() {
        check_ajax_referer( 'bdsk_optimizer_nonce', 'nonce' );

        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        wp_send_json_success( $this->engine->get_stats() );
    }
}
