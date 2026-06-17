<?php
defined( 'ABSPATH' ) || exit;

class BDSK_Optimizer_Engine {

    private $settings;
    private $backup_dir;

    public function __construct( array $settings ) {
        $this->settings   = $settings;
        $this->backup_dir = WP_CONTENT_DIR . '/uploads/bdsk-optimizer-backups';
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    public function optimize_attachment( $attachment_id ) {
        $file = get_attached_file( $attachment_id );

        if ( ! $file || ! file_exists( $file ) ) {
            return new WP_Error( 'file_not_found', 'File not found: ' . $file );
        }

        $mime = get_post_mime_type( $attachment_id );
        if ( ! $this->is_supported_mime( $mime ) ) {
            return new WP_Error( 'unsupported_type', 'Unsupported type: ' . $mime );
        }

        if ( $this->is_excluded( $file ) ) {
            return new WP_Error( 'excluded', 'File is in excluded path: ' . $file );
        }

        if ( $this->settings['backup_originals'] ) {
            $backup = $this->backup_file( $file, $attachment_id );
            if ( is_wp_error( $backup ) ) {
                return $backup;
            }
        }

        $results = [];

        $orig = $this->optimize_file( $file, $mime );
        if ( is_wp_error( $orig ) ) {
            return $orig;
        }
        $results['original'] = $orig;

        if ( $this->settings['optimize_thumbnails'] ) {
            $meta = wp_get_attachment_metadata( $attachment_id );
            if ( ! empty( $meta['sizes'] ) ) {
                $dir = dirname( $file );
                foreach ( $meta['sizes'] as $size_name => $size_data ) {
                    $thumb = $dir . '/' . $size_data['file'];
                    if ( file_exists( $thumb ) && ! $this->is_excluded( $thumb ) ) {
                        $res = $this->optimize_file( $thumb, $mime );
                        if ( ! is_wp_error( $res ) ) {
                            $results['sizes'][ $size_name ] = $res;
                        }
                    }
                }
            }
        }

        $total_saved = $this->sum_saved( $results );

        update_post_meta( $attachment_id, '_bdsk_optimizer', [
            'version'      => BDSK_OPT_VERSION,
            'optimized_at' => current_time( 'mysql' ),
            'engine'       => class_exists( 'Imagick' ) ? 'imagick' : 'gd',
            'results'      => $results,
            'total_saved'  => $total_saved,
        ] );

        return [
            'results'     => $results,
            'total_saved' => $total_saved,
        ];
    }

    public function restore_attachment( $attachment_id ) {
        $file        = get_attached_file( $attachment_id );
        $backup_file = $this->backup_path( $attachment_id, $file );

        if ( ! file_exists( $backup_file ) ) {
            return new WP_Error( 'no_backup', 'No backup for attachment ' . $attachment_id );
        }

        if ( ! copy( $backup_file, $file ) ) {
            return new WP_Error( 'restore_failed', 'Could not restore file' );
        }

        // Remove generated WebP
        foreach ( [ $file ] as $f ) {
            $webp = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $f );
            if ( $webp !== $f && file_exists( $webp ) ) {
                @unlink( $webp );
            }
        }

        delete_post_meta( $attachment_id, '_bdsk_optimizer' );

        return true;
    }

    public function get_stats() {
        global $wpdb;

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(ID) FROM {$wpdb->posts}
             WHERE post_type='attachment' AND post_mime_type IN ('image/jpeg','image/png','image/gif')"
        );

        $optimized = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key='_bdsk_optimizer'"
        );

        $saved_bytes = (int) $wpdb->get_var(
            "SELECT SUM(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->postmeta}
             WHERE meta_key='_bdsk_optimizer_saved'"
        );

        // Fallback: sum from serialized meta (slower, used once)
        if ( ! $saved_bytes ) {
            $rows = $wpdb->get_col(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_bdsk_optimizer'"
            );
            foreach ( $rows as $row ) {
                $data = maybe_unserialize( $row );
                if ( is_array( $data ) && isset( $data['total_saved'] ) ) {
                    $saved_bytes += (int) $data['total_saved'];
                }
            }
        }

        return [
            'total'       => $total,
            'optimized'   => $optimized,
            'unoptimized' => max( 0, $total - $optimized ),
            'percent'     => $total > 0 ? round( $optimized / $total * 100 ) : 0,
            'saved_bytes' => $saved_bytes,
            'saved_human' => size_format( $saved_bytes ),
            'engine'      => class_exists( 'Imagick' ) ? 'Imagick ' . Imagick::getVersion()['versionString'] : 'GD ' . GD_VERSION,
        ];
    }

    public function get_unoptimized_ids( $limit = 5, $offset = 0 ) {
        global $wpdb;

        return $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_bdsk_optimizer'
             WHERE p.post_type = 'attachment'
               AND p.post_mime_type IN ('image/jpeg','image/png','image/gif')
               AND pm.meta_id IS NULL
             ORDER BY p.ID DESC
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        ) );
    }

    // -----------------------------------------------------------------------
    // Core optimization
    // -----------------------------------------------------------------------

    private function optimize_file( $file, $mime ) {
        $original_size = filesize( $file );

        if ( class_exists( 'Imagick' ) ) {
            $result = $this->process_imagick( $file, $mime );
        } else {
            $result = $this->process_gd( $file, $mime );
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        clearstatcache( true, $file );
        $new_size    = filesize( $file );
        $saved_bytes = max( 0, $original_size - $new_size );

        return [
            'file'          => basename( $file ),
            'original_size' => $original_size,
            'new_size'      => $new_size,
            'saved_bytes'   => $saved_bytes,
            'saved_percent' => $original_size > 0 ? round( $saved_bytes / $original_size * 100, 1 ) : 0,
            'webp_file'     => $result['webp_file'] ?? null,
            'webp_size'     => $result['webp_size'] ?? 0,
        ];
    }

    private function process_imagick( $file, $mime ) {
        try {
            $image = new Imagick( $file );

            // Handle multi-frame GIF
            if ( $mime === 'image/gif' ) {
                $image = $image->coalesceImages();
            }

            $image->autoOrient();

            if ( $this->settings['strip_metadata'] ) {
                $image->stripImage();
            }

            $this->resize_imagick( $image );

            switch ( $mime ) {
                case 'image/jpeg':
                    $image->setImageFormat( 'JPEG' );
                    $image->setImageCompression( Imagick::COMPRESSION_JPEG );
                    $image->setImageCompressionQuality( $this->jpeg_quality() );
                    $image->setInterlaceScheme( Imagick::INTERLACE_JPEG );
                    $image->setSamplingFactors( [ '2x2', '1x1', '1x1' ] );
                    break;

                case 'image/png':
                    $image->setImageFormat( 'PNG' );
                    $image->setOption( 'png:compression-level', (string) $this->settings['png_compression'] );
                    break;
            }

            $image->writeImage( $file );

            $webp_file = null;
            $webp_size = 0;

            if ( $this->settings['convert_webp'] && in_array( $mime, [ 'image/jpeg', 'image/png' ], true ) ) {
                $webp_file = $this->webp_path( $file );
                $webp      = clone $image;
                $webp->setImageFormat( 'WebP' );
                $webp->setOption( 'webp:lossless', $this->settings['compression_level'] === 'lossless' ? 'true' : 'false' );
                $webp->setImageCompressionQuality( $this->settings['webp_quality'] );
                $webp->writeImage( $webp_file );
                $webp->destroy();
                clearstatcache( true, $webp_file );
                $webp_size = file_exists( $webp_file ) ? filesize( $webp_file ) : 0;
            }

            $image->destroy();

            return compact( 'webp_file', 'webp_size' );

        } catch ( ImagickException $e ) {
            return new WP_Error( 'imagick_error', $e->getMessage() );
        }
    }

    private function process_gd( $file, $mime ) {
        $image = null;

        switch ( $mime ) {
            case 'image/jpeg': $image = @imagecreatefromjpeg( $file ); break;
            case 'image/png':  $image = @imagecreatefrompng( $file );  break;
            default: return new WP_Error( 'gd_unsupported', 'GD: unsupported type ' . $mime );
        }

        if ( ! $image ) {
            return new WP_Error( 'gd_load', 'GD could not load: ' . $file );
        }

        $image = $this->resize_gd( $image );

        // Fix transparency for PNG
        if ( $mime === 'image/png' ) {
            imagesavealpha( $image, true );
        }

        switch ( $mime ) {
            case 'image/jpeg':
                imagejpeg( $image, $file, $this->jpeg_quality() );
                break;
            case 'image/png':
                imagepng( $image, $file, min( 9, $this->settings['png_compression'] ) );
                break;
        }

        $webp_file = null;
        $webp_size = 0;

        if ( $this->settings['convert_webp'] && in_array( $mime, [ 'image/jpeg', 'image/png' ], true ) ) {
            $webp_file = $this->webp_path( $file );
            imagewebp( $image, $webp_file, $this->settings['webp_quality'] );
            clearstatcache( true, $webp_file );
            $webp_size = file_exists( $webp_file ) ? filesize( $webp_file ) : 0;
        }

        imagedestroy( $image );

        return compact( 'webp_file', 'webp_size' );
    }

    // -----------------------------------------------------------------------
    // Resize helpers
    // -----------------------------------------------------------------------

    private function resize_imagick( Imagick $image ) {
        $max_w = (int) $this->settings['max_width'];
        $max_h = (int) $this->settings['max_height'];

        if ( $max_w <= 0 && $max_h <= 0 ) {
            return;
        }

        $w = $image->getImageWidth();
        $h = $image->getImageHeight();

        if ( ( $max_w > 0 && $w > $max_w ) || ( $max_h > 0 && $h > $max_h ) ) {
            $image->thumbnailImage( $max_w ?: 0, $max_h ?: 0, true );
        }
    }

    private function resize_gd( $image ) {
        $max_w = (int) $this->settings['max_width'];
        $max_h = (int) $this->settings['max_height'];

        if ( $max_w <= 0 && $max_h <= 0 ) {
            return $image;
        }

        $w = imagesx( $image );
        $h = imagesy( $image );

        if ( ( $max_w > 0 && $w > $max_w ) || ( $max_h > 0 && $h > $max_h ) ) {
            $max_w = $max_w ?: PHP_INT_MAX;
            $max_h = $max_h ?: PHP_INT_MAX;
            $ratio = min( $max_w / $w, $max_h / $h );
            $nw    = (int) round( $w * $ratio );
            $nh    = (int) round( $h * $ratio );
            $new   = imagecreatetruecolor( $nw, $nh );
            imagecopyresampled( $new, $image, 0, 0, 0, 0, $nw, $nh, $w, $h );
            imagedestroy( $image );
            return $new;
        }

        return $image;
    }

    // -----------------------------------------------------------------------
    // Backup / restore helpers
    // -----------------------------------------------------------------------

    private function backup_file( $file, $attachment_id ) {
        if ( ! wp_mkdir_p( $this->backup_dir ) ) {
            return new WP_Error( 'backup_dir', 'Cannot create backup dir' );
        }

        $dest = $this->backup_path( $attachment_id, $file );

        if ( ! file_exists( $dest ) && ! copy( $file, $dest ) ) {
            return new WP_Error( 'backup_failed', 'Cannot backup: ' . $file );
        }

        return $dest;
    }

    private function backup_path( $attachment_id, $file ) {
        return $this->backup_dir . '/' . $attachment_id . '_' . basename( $file );
    }

    // -----------------------------------------------------------------------
    // Utility
    // -----------------------------------------------------------------------

    private function jpeg_quality() {
        switch ( $this->settings['compression_level'] ) {
            case 'ultra-lossy': return max( 40, (int) $this->settings['jpeg_quality'] - 20 );
            case 'lossless':    return 100;
            default:            return (int) $this->settings['jpeg_quality'];
        }
    }

    private function webp_path( $file ) {
        return preg_replace( '/\.(jpe?g|png)$/i', '.webp', $file );
    }

    private function is_supported_mime( $mime ) {
        return in_array( $mime, [ 'image/jpeg', 'image/png', 'image/gif' ], true );
    }

    private function is_excluded( $file ) {
        $excluded = $this->settings['excluded_paths'] ?? [];
        foreach ( $excluded as $pattern ) {
            if ( str_contains( $file, $pattern ) ) {
                return true;
            }
        }
        return false;
    }

    private function sum_saved( array $results ) {
        $total = 0;
        if ( isset( $results['original']['saved_bytes'] ) ) {
            $total += $results['original']['saved_bytes'];
        }
        foreach ( $results['sizes'] ?? [] as $size ) {
            $total += $size['saved_bytes'] ?? 0;
        }
        return $total;
    }
}
