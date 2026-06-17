<?php
defined( 'ABSPATH' ) || exit;

class BDSK_Optimizer_Settings {

    const OPTION_KEY = 'bdsk_optimizer_settings';

    private static $defaults = [
        'compression_level'   => 'lossy',
        'jpeg_quality'        => 82,
        'webp_quality'        => 80,
        'png_compression'     => 6,
        'convert_webp'        => true,
        'serve_webp'          => true,
        'auto_optimize'       => true,
        'optimize_thumbnails' => true,
        'backup_originals'    => true,
        'strip_metadata'      => true,
        'max_width'           => 2048,
        'max_height'          => 2048,
        'excluded_paths'      => [],
        'batch_size'          => 5,
    ];

    private $data;

    public function __construct() {
        $saved      = get_option( self::OPTION_KEY, [] );
        $this->data = wp_parse_args( $saved, self::$defaults );
    }

    public function get( $key, $default = null ) {
        return $this->data[ $key ] ?? $default;
    }

    public function get_all() {
        return $this->data;
    }

    public function save( array $input ) {
        $clean = [];

        $clean['compression_level']   = in_array( $input['compression_level'] ?? '', [ 'lossless', 'lossy', 'ultra-lossy' ], true )
                                        ? $input['compression_level'] : 'lossy';
        $clean['jpeg_quality']        = min( 100, max( 1, (int) ( $input['jpeg_quality'] ?? 82 ) ) );
        $clean['webp_quality']        = min( 100, max( 1, (int) ( $input['webp_quality'] ?? 80 ) ) );
        $clean['png_compression']     = min( 9,   max( 0, (int) ( $input['png_compression'] ?? 6 ) ) );
        $clean['max_width']           = max( 0, (int) ( $input['max_width'] ?? 2048 ) );
        $clean['max_height']          = max( 0, (int) ( $input['max_height'] ?? 2048 ) );
        $clean['batch_size']          = min( 20, max( 1, (int) ( $input['batch_size'] ?? 5 ) ) );
        $clean['convert_webp']        = ! empty( $input['convert_webp'] );
        $clean['serve_webp']          = ! empty( $input['serve_webp'] );
        $clean['auto_optimize']       = ! empty( $input['auto_optimize'] );
        $clean['optimize_thumbnails'] = ! empty( $input['optimize_thumbnails'] );
        $clean['backup_originals']    = ! empty( $input['backup_originals'] );
        $clean['strip_metadata']      = ! empty( $input['strip_metadata'] );

        $excluded = [];
        if ( ! empty( $input['excluded_paths'] ) ) {
            foreach ( explode( "\n", $input['excluded_paths'] ) as $line ) {
                $line = trim( $line );
                if ( $line ) {
                    $excluded[] = $line;
                }
            }
        }
        $clean['excluded_paths'] = $excluded;

        $this->data = array_merge( $this->data, $clean );
        update_option( self::OPTION_KEY, $this->data, false );

        return $this->data;
    }

    public static function get_defaults() {
        return self::$defaults;
    }
}
