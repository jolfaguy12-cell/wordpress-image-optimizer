<?php
// Only run when WordPress triggers this via plugin deletion.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Drop the queue table
$table = $wpdb->prefix . 'woo_optimizer_queue';
$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Remove plugin settings
delete_option( 'woo_optimizer_settings' );
