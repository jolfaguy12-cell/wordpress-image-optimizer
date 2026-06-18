<?php
defined( 'ABSPATH' ) || exit;

class Woo_Image_Optimizer_Settings {

	const OPTION_KEY = 'woo_optimizer_settings';

	private array $data;

	public function __construct() {
		$this->data = array_merge( $this->get_defaults(), (array) get_option( self::OPTION_KEY, [] ) );
	}

	public function get( string $key, $default = null ) {
		return $this->data[ $key ] ?? $default;
	}

	public function get_all(): array {
		return $this->data;
	}

	public function save( array $input ): void {
		$clean = [];

		$clean['api_url'] = esc_url_raw( trim( $input['api_url'] ?? '' ) );
		$clean['api_key'] = sanitize_text_field( $input['api_key'] ?? '' );

		$clean['webp_quality'] = max( 1, min( 100, (int) ( $input['webp_quality'] ?? 82 ) ) );
		$clean['max_width']    = max( 0, (int) ( $input['max_width'] ?? 2048 ) );
		$clean['max_height']   = max( 0, (int) ( $input['max_height'] ?? 2048 ) );
		$clean['batch_size']   = max( 1, min( 50, (int) ( $input['batch_size'] ?? 5 ) ) );

		$clean['auto_optimize']            = ! empty( $input['auto_optimize'] );
		$clean['backup_retention_enabled'] = ! empty( $input['backup_retention_enabled'] );
		$clean['backup_retention_days']    = max( 1, (int) ( $input['backup_retention_days'] ?? 30 ) );

		$raw_interval = (int) ( $input['cron_interval'] ?? 120 );
		$clean['cron_interval'] = in_array( $raw_interval, [ 60, 120, 300 ], true ) ? $raw_interval : 120;

		update_option( self::OPTION_KEY, $clean );
		$this->data = $clean;
	}

	private function get_defaults(): array {
		return [
			'api_url'                   => '',
			'api_key'                   => '',
			'webp_quality'              => 82,
			'max_width'                 => 2048,
			'max_height'                => 2048,
			'batch_size'                => 3,
			'cron_interval'             => 120,
			'auto_optimize'             => true,
			'backup_retention_enabled'  => false,
			'backup_retention_days'     => 30,
		];
	}
}
