<?php
/**
 * Plugin Name: Behdashtik Image Optimizer
 * Description: Lightweight image optimization with WebP conversion, bulk processing, and smart compression. No external API required.
 * Version:     1.0.0
 * Author:      Behdashtik
 * License:     GPL-2.0+
 * Text Domain: bdsk-optimizer
 */

defined( 'ABSPATH' ) || exit;

define( 'BDSK_OPT_VERSION', '1.0.0' );
define( 'BDSK_OPT_FILE',    __FILE__ );
define( 'BDSK_OPT_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BDSK_OPT_URL',     plugin_dir_url( __FILE__ ) );

require_once BDSK_OPT_DIR . 'includes/class-settings.php';
require_once BDSK_OPT_DIR . 'includes/class-engine.php';
require_once BDSK_OPT_DIR . 'includes/class-admin.php';
require_once BDSK_OPT_DIR . 'includes/class-bulk.php';
require_once BDSK_OPT_DIR . 'includes/class-webp.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once BDSK_OPT_DIR . 'includes/class-cli.php';
    WP_CLI::add_command( 'bdsk-optimizer', 'BDSK_Optimizer_CLI' );
}

function bdsk_optimizer() {
    static $instance = null;
    if ( null === $instance ) {
        $settings = new BDSK_Optimizer_Settings();
        $engine   = new BDSK_Optimizer_Engine( $settings->get_all() );
        $instance = [
            'settings' => $settings,
            'engine'   => $engine,
            'admin'    => new BDSK_Optimizer_Admin( $settings, $engine ),
            'bulk'     => new BDSK_Optimizer_Bulk( $engine ),
            'webp'     => new BDSK_Optimizer_WebP( $settings ),
        ];
    }
    return $instance;
}
add_action( 'plugins_loaded', 'bdsk_optimizer' );

register_activation_hook( __FILE__, function () {
    $backup_dir = WP_CONTENT_DIR . '/uploads/bdsk-optimizer-backups';
    wp_mkdir_p( $backup_dir );
    // Protect backup dir from direct access
    file_put_contents( $backup_dir . '/.htaccess', 'deny from all' );
    file_put_contents( $backup_dir . '/index.php', '<?php // silence' );
} );
