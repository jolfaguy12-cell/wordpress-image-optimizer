<?php
defined( 'ABSPATH' ) || exit;

class BDSK_Optimizer_WebP {

    private $settings;

    public function __construct( BDSK_Optimizer_Settings $settings ) {
        $this->settings = $settings;

        if ( $settings->get( 'serve_webp' ) ) {
            add_filter( 'wp_get_attachment_image_src', [ $this, 'rewrite_src' ],    20, 4 );
            add_filter( 'wp_get_attachment_url',       [ $this, 'rewrite_url' ],    20, 2 );
            add_filter( 'wp_calculate_image_srcset',   [ $this, 'rewrite_srcset' ], 20 );
        }
    }

    // Rewrite a single attachment src if WebP exists and browser supports it
    public function rewrite_src( $image, $attachment_id, $size, $icon ) {
        if ( ! $image || ! $this->browser_accepts_webp() ) {
            return $image;
        }

        $url = $image[0];
        if ( $this->webp_url_exists( $url ) ) {
            $image[0] = $this->url_to_webp( $url );
        }

        return $image;
    }

    public function rewrite_url( $url, $attachment_id ) {
        if ( ! $this->browser_accepts_webp() ) {
            return $url;
        }

        if ( $this->webp_url_exists( $url ) ) {
            return $this->url_to_webp( $url );
        }

        return $url;
    }

    public function rewrite_srcset( $sources ) {
        if ( ! $this->browser_accepts_webp() || ! is_array( $sources ) ) {
            return $sources;
        }

        foreach ( $sources as $width => $source ) {
            $url = $source['url'];
            if ( $this->webp_url_exists( $url ) ) {
                $sources[ $width ]['url'] = $this->url_to_webp( $url );
            }
        }

        return $sources;
    }

    // -----------------------------------------------------------------------
    // .htaccess rules (Apache — for main site on shared hosting)
    // -----------------------------------------------------------------------

    public static function get_htaccess_rules() {
        return '# BEGIN BDSK WebP
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteCond %{HTTP_ACCEPT} image/webp
  RewriteCond %{REQUEST_FILENAME} \.(jpe?g|png)$
  RewriteCond %{REQUEST_FILENAME}\.webp -f
  RewriteRule ^(.+)\.(jpe?g|png)$ $1.webp [T=image/webp,E=accept:1,L]
</IfModule>
<IfModule mod_headers.c>
  Header append Vary Accept env=REDIRECT_accept
</IfModule>
# END BDSK WebP';
    }

    public static function get_nginx_rules() {
        return 'map $http_accept $bdsk_webp_suffix {
    default "";
    "~*image/webp" ".webp";
}

location ~* ^(/wp-content/uploads/.+)\.(jpe?g|png)$ {
    add_header Vary Accept;
    try_files $uri$bdsk_webp_suffix $uri =404;
}';
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function browser_accepts_webp() {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return str_contains( $accept, 'image/webp' );
    }

    private function url_to_webp( $url ) {
        return preg_replace( '/\.(jpe?g|png)(\?.*)?$/i', '.webp$2', $url );
    }

    private function webp_url_exists( $url ) {
        $webp_url  = $this->url_to_webp( $url );
        $webp_path = $this->url_to_path( $webp_url );
        return $webp_path && file_exists( $webp_path );
    }

    private function url_to_path( $url ) {
        $upload_dir = wp_upload_dir();
        $base_url   = $upload_dir['baseurl'];
        $base_dir   = $upload_dir['basedir'];

        if ( str_starts_with( $url, $base_url ) ) {
            return $base_dir . substr( $url, strlen( $base_url ) );
        }

        return null;
    }
}
