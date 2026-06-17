<?php
defined( 'ABSPATH' ) || exit;

class Woo_Image_Optimizer_Cron {

	const HOOK          = 'woo_optimizer_cron_tick';
	const SCHEDULE      = 'woo_optimizer_every_minute';
	const LOCK_TRANSIENT = 'woo_optimizer_lock';
	const LOCK_TTL      = 25; // seconds — safe below 30s shared-hosting limit

	private Woo_Image_Optimizer_Processor $processor;
	private Woo_Image_Optimizer_Settings  $settings;

	public function __construct( Woo_Image_Optimizer_Processor $processor, Woo_Image_Optimizer_Settings $settings ) {
		$this->processor = $processor;
		$this->settings  = $settings;

		add_filter( 'cron_schedules', [ $this, 'add_schedule' ] );
		add_action( self::HOOK,       [ $this, 'tick' ] );
	}

	public function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = [
			'interval' => 60,
			'display'  => __( 'Every Minute', 'woo-image-optimizer' ),
		];
		return $schedules;
	}

	public static function activate(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), self::SCHEDULE, self::HOOK );
		}
	}

	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	public function tick(): void {
		// Transient-based lock: prevents overlapping cron runs.
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return;
		}
		set_transient( self::LOCK_TRANSIENT, 1, self::LOCK_TTL );

		try {
			$batch_size = (int) $this->settings->get( 'batch_size', 5 );
			$this->processor->process_batch( $batch_size );
		} finally {
			delete_transient( self::LOCK_TRANSIENT );
		}
	}
}
