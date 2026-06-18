<?php
defined( 'ABSPATH' ) || exit;

class Woo_Image_Optimizer_Cron {

	const HOOK           = 'woo_optimizer_cron_tick';
	const LOCK_TRANSIENT = 'woo_optimizer_lock';
	const LOCK_TTL       = 25; // seconds — safe below 30s shared-hosting limit

	private Woo_Image_Optimizer_Processor $processor;
	private Woo_Image_Optimizer_Settings  $settings;

	public function __construct( Woo_Image_Optimizer_Processor $processor, Woo_Image_Optimizer_Settings $settings ) {
		$this->processor = $processor;
		$this->settings  = $settings;

		add_filter( 'cron_schedules', [ $this, 'add_schedules' ] );
		add_action( self::HOOK,       [ $this, 'tick' ] );
		add_action( 'init',           [ $this, 'ensure_scheduled' ] );
	}

	// Self-healing: reschedule on every page load if the cron went missing.
	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::activate();
		}
	}

	public function add_schedules( array $schedules ): array {
		$schedules['woo_optimizer_every_minute'] = [
			'interval' => 60,
			'display'  => __( 'Every Minute', 'woo-image-optimizer' ),
		];
		$schedules['woo_optimizer_every_2min'] = [
			'interval' => 120,
			'display'  => __( 'Every 2 Minutes', 'woo-image-optimizer' ),
		];
		$schedules['woo_optimizer_every_5min'] = [
			'interval' => 300,
			'display'  => __( 'Every 5 Minutes', 'woo-image-optimizer' ),
		];
		return $schedules;
	}

	private static function schedule_name( int $interval ): string {
		$map = [
			60  => 'woo_optimizer_every_minute',
			120 => 'woo_optimizer_every_2min',
			300 => 'woo_optimizer_every_5min',
		];
		return $map[ $interval ] ?? 'woo_optimizer_every_2min';
	}

	public static function activate(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			$settings = (array) get_option( Woo_Image_Optimizer_Settings::OPTION_KEY, [] );
			$interval = (int) ( $settings['cron_interval'] ?? 120 );
			wp_schedule_event( time(), self::schedule_name( $interval ), self::HOOK );
		}
	}

	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	public static function reschedule( int $new_interval ): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
		wp_schedule_event( time(), self::schedule_name( $new_interval ), self::HOOK );
	}

	public function tick(): void {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return;
		}
		set_transient( self::LOCK_TRANSIENT, 1, self::LOCK_TTL );

		try {
			$batch_size = (int) $this->settings->get( 'batch_size', 3 );
			$this->processor->process_batch( $batch_size );
		} finally {
			delete_transient( self::LOCK_TRANSIENT );
		}
	}
}
