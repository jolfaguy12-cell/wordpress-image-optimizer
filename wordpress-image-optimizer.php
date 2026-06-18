<?php
/**
 * Plugin Name: WooCommerce Image Optimizer
 * Description: Converts WooCommerce product images to WebP via a remote processing server. Async queue-based, zero page-load overhead. No local heavy processing.
 * Version:     2.5.3
 * Author:      jolfaguy12-cell
 * License:     GPL-2.0+
 * Text Domain: woo-image-optimizer
 * Requires Plugins: woocommerce
 * Plugin URI:  https://github.com/jolfaguy12-cell/wordpress-image-optimizer
 */

defined( 'ABSPATH' ) || exit;

define( 'WOO_IMG_OPT_VERSION', '2.5.3' );
define( 'WOO_IMG_OPT_FILE',    __FILE__ );
define( 'WOO_IMG_OPT_DIR',     plugin_dir_path( __FILE__ ) );
define( 'WOO_IMG_OPT_URL',     plugin_dir_url( __FILE__ ) );

require_once WOO_IMG_OPT_DIR . 'includes/class-settings.php';
require_once WOO_IMG_OPT_DIR . 'includes/class-queue.php';
require_once WOO_IMG_OPT_DIR . 'includes/class-api-client.php';
require_once WOO_IMG_OPT_DIR . 'includes/class-woocommerce.php';
require_once WOO_IMG_OPT_DIR . 'includes/class-db-updater.php';
require_once WOO_IMG_OPT_DIR . 'includes/class-processor.php';
require_once WOO_IMG_OPT_DIR . 'includes/class-cron.php';
require_once WOO_IMG_OPT_DIR . 'includes/class-restore.php';
require_once WOO_IMG_OPT_DIR . 'includes/class-admin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WOO_IMG_OPT_DIR . 'includes/class-cli.php';
	WP_CLI::add_command( 'woo-optimizer', 'Woo_Image_Optimizer_CLI' );
}

/**
 * Singleton factory. Returns all wired-up component instances.
 *
 * @return array{settings:Woo_Image_Optimizer_Settings, queue:Woo_Image_Optimizer_Queue, api:Woo_Image_Optimizer_API_Client, woocommerce:Woo_Image_Optimizer_WooCommerce, db:Woo_Image_Optimizer_DB_Updater, processor:Woo_Image_Optimizer_Processor, cron:Woo_Image_Optimizer_Cron, restore:Woo_Image_Optimizer_Restore, admin:Woo_Image_Optimizer_Admin}
 */
function woo_image_optimizer(): array {
	static $instance = null;

	if ( null !== $instance ) {
		return $instance;
	}

	$settings = new Woo_Image_Optimizer_Settings();
	$queue    = new Woo_Image_Optimizer_Queue();

	$api = new Woo_Image_Optimizer_API_Client(
		(string) $settings->get( 'api_url', '' ),
		(string) $settings->get( 'api_key', '' )
	);

	$woocommerce = new Woo_Image_Optimizer_WooCommerce( $queue, $settings );
	$db          = new Woo_Image_Optimizer_DB_Updater();

	$processor = new Woo_Image_Optimizer_Processor( $queue, $api, $db, $settings );
	$cron      = new Woo_Image_Optimizer_Cron( $processor, $settings );
	$restore   = new Woo_Image_Optimizer_Restore( $api, $queue );

	$admin = new Woo_Image_Optimizer_Admin(
		$settings,
		$queue,
		$processor,
		$restore,
		$woocommerce
	);

	$instance = compact( 'settings', 'queue', 'api', 'woocommerce', 'db', 'processor', 'cron', 'restore', 'admin' );

	return $instance;
}

add_action( 'plugins_loaded', 'woo_image_optimizer' );

// -----------------------------------------------------------------------
// Activation / Deactivation
// -----------------------------------------------------------------------

register_activation_hook( __FILE__, function (): void {
	Woo_Image_Optimizer_Queue::create_table();
	Woo_Image_Optimizer_Cron::activate();
} );

register_deactivation_hook( __FILE__, function (): void {
	Woo_Image_Optimizer_Cron::deactivate();
} );
