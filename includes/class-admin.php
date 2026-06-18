<?php
defined( 'ABSPATH' ) || exit;

class Woo_Image_Optimizer_Admin {

	private Woo_Image_Optimizer_Settings    $settings;
	private Woo_Image_Optimizer_Queue       $queue;
	private Woo_Image_Optimizer_Processor   $processor;
	private Woo_Image_Optimizer_Restore     $restore;
	private Woo_Image_Optimizer_WooCommerce $woocommerce;

	public function __construct(
		Woo_Image_Optimizer_Settings    $settings,
		Woo_Image_Optimizer_Queue       $queue,
		Woo_Image_Optimizer_Processor   $processor,
		Woo_Image_Optimizer_Restore     $restore,
		Woo_Image_Optimizer_WooCommerce $woocommerce
	) {
		$this->settings    = $settings;
		$this->queue       = $queue;
		$this->processor   = $processor;
		$this->restore     = $restore;
		$this->woocommerce = $woocommerce;

		add_action( 'admin_menu',            [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'admin_post_woo_img_opt_save', [ $this, 'save_settings' ] );

		// Media library column
		add_filter( 'manage_media_columns',       [ $this, 'media_column' ] );
		add_action( 'manage_media_custom_column', [ $this, 'media_column_content' ], 10, 2 );

		// AJAX handlers
		add_action( 'wp_ajax_woo_optimizer_queue_all',       [ $this, 'ajax_queue_all' ] );
		add_action( 'wp_ajax_woo_optimizer_batch',           [ $this, 'ajax_batch' ] );
		add_action( 'wp_ajax_woo_optimizer_stats',           [ $this, 'ajax_stats' ] );
		add_action( 'wp_ajax_woo_optimizer_restore',         [ $this, 'ajax_restore' ] );
		add_action( 'wp_ajax_woo_optimizer_retry_failed',    [ $this, 'ajax_retry_failed' ] );
		add_action( 'wp_ajax_woo_optimizer_test_connection', [ $this, 'ajax_test_connection' ] );
	}

	// -----------------------------------------------------------------------
	// Menu & Assets
	// -----------------------------------------------------------------------

	public function add_menu(): void {
		add_media_page(
			__( 'WooCommerce Image Optimizer', 'woo-image-optimizer' ),
			__( 'WooCommerce Image Optimizer', 'woo-image-optimizer' ),
			'manage_options',
			'woo-image-optimizer',
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_scripts( string $hook ): void {
		if ( 'media_page_woo-image-optimizer' !== $hook && 'upload' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'woo-image-optimizer',
			WOO_IMG_OPT_URL . 'assets/css/admin.css',
			[],
			WOO_IMG_OPT_VERSION
		);

		wp_enqueue_script(
			'woo-image-optimizer',
			WOO_IMG_OPT_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			WOO_IMG_OPT_VERSION,
			true
		);

		wp_localize_script( 'woo-image-optimizer', 'wooImgOpt', [
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'woo_img_opt_nonce' ),
			'pollInterval' => 10000,
			'i18n'         => [
				'queuing'    => __( 'Queuing images…', 'woo-image-optimizer' ),
				'processing' => __( 'Processing…', 'woo-image-optimizer' ),
				'done'       => __( 'All done!', 'woo-image-optimizer' ),
				'paused'     => __( 'Paused', 'woo-image-optimizer' ),
				'cronNote'   => __( 'Background cron is processing — checking progress every %ds.', 'woo-image-optimizer' ),
				'testConn'   => [
					'testing' => __( 'Testing…', 'woo-image-optimizer' ),
					'ok'      => __( 'Connected', 'woo-image-optimizer' ),
					'error'   => __( 'Error', 'woo-image-optimizer' ),
				],
			],
		] );
	}

	// -----------------------------------------------------------------------
	// Settings page
	// -----------------------------------------------------------------------

	public function save_settings(): void {
		check_admin_referer( 'woo_img_opt_settings' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$old_interval = (int) $this->settings->get( 'cron_interval', 120 );
		$this->settings->save( $_POST );
		$new_interval = (int) $this->settings->get( 'cron_interval', 120 );

		if ( $old_interval !== $new_interval ) {
			Woo_Image_Optimizer_Cron::reschedule( $new_interval );
		}

		wp_safe_redirect( add_query_arg(
			[ 'page' => 'woo-image-optimizer', 'saved' => '1' ],
			admin_url( 'upload.php' )
		) );
		exit;
	}

	public function render_page(): void {
		$stats          = $this->queue->get_stats();
		$s              = $this->settings->get_all();
		$api_configured = ! empty( $s['api_url'] ) && ! empty( $s['api_key'] );
		$done           = (int) $stats['done'];
		$pending        = (int) $stats['pending'];
		$failed         = (int) $stats['failed'];
		$total          = (int) $stats['total'];
		$eligible       = $done + $pending + $failed;
		$pct            = $eligible > 0 ? (int) round( $done / $eligible * 100 ) : 0;
		?>
		<div class="wrap wio-wrap">
			<h1><?php esc_html_e( 'WooCommerce Image Optimizer', 'woo-image-optimizer' ); ?></h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'woo-image-optimizer' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $api_configured ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'Enter your Server 2 API URL and API Key below to start optimizing.', 'woo-image-optimizer' ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Stat Cards -->
			<div class="wio-stats-row">
				<div class="wio-stat-card wio-stat-card--total">
					<div class="wio-stat-icon">
						<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="1.5" y="1.5" width="13" height="13" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M4 5.5h8M4 8h8M4 10.5h5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
					</div>
					<span class="wio-stat-number" id="wio-stat-total"><?php echo esc_html( $total ); ?></span>
					<span class="wio-stat-label"><?php esc_html_e( 'Total Queued', 'woo-image-optimizer' ); ?></span>
				</div>
				<div class="wio-stat-card wio-stat-card--done">
					<div class="wio-stat-icon">
						<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M13 4.5L6.5 11 3 7.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
					</div>
					<span class="wio-stat-number" id="wio-stat-done"><?php echo esc_html( $done ); ?></span>
					<span class="wio-stat-label"><?php esc_html_e( 'Optimized', 'woo-image-optimizer' ); ?></span>
				</div>
				<div class="wio-stat-card wio-stat-card--pending">
					<div class="wio-stat-icon">
						<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.5"/><path d="M8 4.5V8l2.5 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
					</div>
					<span class="wio-stat-number" id="wio-stat-pending"><?php echo esc_html( $pending ); ?></span>
					<span class="wio-stat-label"><?php esc_html_e( 'Pending', 'woo-image-optimizer' ); ?></span>
				</div>
				<div class="wio-stat-card wio-stat-card--failed">
					<div class="wio-stat-icon">
						<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.5"/><path d="M5.5 5.5l5 5M10.5 5.5l-5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
					</div>
					<span class="wio-stat-number" id="wio-stat-failed"><?php echo esc_html( $failed ); ?></span>
					<span class="wio-stat-label"><?php esc_html_e( 'Failed', 'woo-image-optimizer' ); ?></span>
				</div>
			</div>

			<!-- Savings Box -->
			<div class="wio-savings-box">
				<span class="wio-savings-icon" aria-hidden="true">
					<svg width="18" height="18" viewBox="0 0 18 18" fill="none"><path d="M9 3v10M5 9l4 4 4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 15h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
				</span>
				<?php esc_html_e( 'Total space saved:', 'woo-image-optimizer' ); ?>
				<strong id="wio-stat-saved"><?php echo esc_html( size_format( $stats['saved_bytes'] ) ); ?></strong>
			</div>

			<!-- Progress Bar -->
			<div class="wio-progress-wrap" id="wio-progress-wrap">
				<div class="wio-progress-track">
					<div class="wio-progress-fill" id="wio-progress-fill" style="width:<?php echo esc_attr( $pct ); ?>%"></div>
				</div>
				<div class="wio-progress-text" id="wio-progress-text">
					<?php printf( esc_html__( '%1$d of %2$d optimized (%3$d%%)', 'woo-image-optimizer' ), $done, $eligible, $pct ); ?>
				</div>
			</div>

			<!-- Bulk Actions -->
			<div class="wio-bulk-wrap">
				<?php if ( $api_configured ) : ?>
					<button class="button button-primary button-large" id="wio-start">
						<?php esc_html_e( 'Optimize All Product Images', 'woo-image-optimizer' ); ?>
					</button>
					<button class="button button-large" id="wio-pause" style="display:none"><?php esc_html_e( 'Pause', 'woo-image-optimizer' ); ?></button>
					<button class="button button-large" id="wio-resume" style="display:none"><?php esc_html_e( 'Resume', 'woo-image-optimizer' ); ?></button>
					<button class="button button-large wio-btn-retry" id="wio-retry-failed"<?php echo $failed === 0 ? ' style="display:none"' : ''; ?>>
						<?php esc_html_e( 'Retry Failed', 'woo-image-optimizer' ); ?> (<span id="wio-retry-count"><?php echo esc_html( $failed ); ?></span>)
					</button>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Configure API settings below to enable optimization.', 'woo-image-optimizer' ); ?></p>
				<?php endif; ?>
			</div>

			<!-- Processing Log -->
			<div class="wio-log" id="wio-log" style="display:none">
				<h3><?php esc_html_e( 'Processing Log', 'woo-image-optimizer' ); ?></h3>
				<ul id="wio-log-list"></ul>
			</div>

			<hr>

			<!-- Settings -->
			<h2><?php esc_html_e( 'Settings', 'woo-image-optimizer' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="woo_img_opt_save">
				<?php wp_nonce_field( 'woo_img_opt_settings' ); ?>

				<div class="wio-settings-grid">

					<!-- Connection (spans both columns) -->
					<div class="wio-settings-card wio-settings-span2">
						<h3 class="wio-settings-card-title"><?php esc_html_e( 'Connection', 'woo-image-optimizer' ); ?></h3>
						<div class="wio-field">
							<label for="wio-api-url"><?php esc_html_e( 'Server 2 API URL', 'woo-image-optimizer' ); ?></label>
							<input type="url" id="wio-api-url" name="api_url" value="<?php echo esc_attr( $s['api_url'] ); ?>" class="regular-text" placeholder="https://imgoptimizer.behdashtik.ir">
							<p class="description"><?php esc_html_e( 'Base URL of the remote image processing server.', 'woo-image-optimizer' ); ?></p>
						</div>
						<div class="wio-field">
							<label for="wio-api-key"><?php esc_html_e( 'API Key', 'woo-image-optimizer' ); ?></label>
							<input type="password" id="wio-api-key" name="api_key" value="<?php echo esc_attr( $s['api_key'] ); ?>" class="regular-text" autocomplete="new-password">
							<p class="description"><?php esc_html_e( 'Bearer token from Server 2 (WOO_IMG_API_KEY). Keep this secret.', 'woo-image-optimizer' ); ?></p>
						</div>
						<div class="wio-field wio-test-connection-row">
							<button type="button" class="button" id="wio-test-connection">
								<?php esc_html_e( 'Test Connection', 'woo-image-optimizer' ); ?>
							</button>
							<span id="wio-connection-status" class="wio-connection-status"></span>
						</div>
					</div>

					<!-- Optimization -->
					<div class="wio-settings-card">
						<h3 class="wio-settings-card-title"><?php esc_html_e( 'Optimization', 'woo-image-optimizer' ); ?></h3>
						<div class="wio-field">
							<label for="wio-webp-quality">
								<?php esc_html_e( 'WebP Quality', 'woo-image-optimizer' ); ?>
								<span class="wio-hint">(1–100)</span>
							</label>
							<input type="number" id="wio-webp-quality" name="webp_quality" value="<?php echo esc_attr( $s['webp_quality'] ); ?>" min="1" max="100" style="width:70px">
							<p class="description"><?php esc_html_e( 'Default 82. WebP 82 ≈ JPEG 90 visually.', 'woo-image-optimizer' ); ?></p>
						</div>
						<div class="wio-field">
							<label><?php esc_html_e( 'Max Dimensions', 'woo-image-optimizer' ); ?></label>
							<div class="wio-inline-row">
								<input type="number" name="max_width" value="<?php echo esc_attr( $s['max_width'] ); ?>" min="0" style="width:80px">
								<span>&times;</span>
								<input type="number" name="max_height" value="<?php echo esc_attr( $s['max_height'] ); ?>" min="0" style="width:80px">
								<span>px</span>
							</div>
							<p class="description"><?php esc_html_e( 'Images larger than this are scaled down. Set 0 for no limit.', 'woo-image-optimizer' ); ?></p>
						</div>
						<div class="wio-field">
							<label for="wio-batch-size"><?php esc_html_e( 'Batch Size', 'woo-image-optimizer' ); ?></label>
							<input type="number" id="wio-batch-size" name="batch_size" value="<?php echo esc_attr( $s['batch_size'] ); ?>" min="1" max="50" style="width:70px">
							<p class="description"><?php esc_html_e( 'Jobs per cron run. Default 3. Use 1–2 on shared hosting.', 'woo-image-optimizer' ); ?></p>
						</div>
						<div class="wio-field">
							<label for="wio-cron-interval"><?php esc_html_e( 'Cron Interval', 'woo-image-optimizer' ); ?></label>
							<select id="wio-cron-interval" name="cron_interval">
								<option value="60"  <?php selected( (int) $s['cron_interval'], 60  ); ?>><?php esc_html_e( 'Every 1 minute (fast)',        'woo-image-optimizer' ); ?></option>
								<option value="120" <?php selected( (int) $s['cron_interval'], 120 ); ?>><?php esc_html_e( 'Every 2 minutes (recommended)', 'woo-image-optimizer' ); ?></option>
								<option value="300" <?php selected( (int) $s['cron_interval'], 300 ); ?>><?php esc_html_e( 'Every 5 minutes (shared host)', 'woo-image-optimizer' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'How often the background cron processes images. Use 2–5 min on shared hosting to reduce server load.', 'woo-image-optimizer' ); ?></p>
						</div>
						<div class="wio-field">
							<label>
								<input type="checkbox" name="auto_optimize" value="1" <?php checked( $s['auto_optimize'] ); ?>>
								<?php esc_html_e( 'Auto-queue on product image change', 'woo-image-optimizer' ); ?>
							</label>
						</div>
					</div>

					<!-- Backup -->
					<div class="wio-settings-card">
						<h3 class="wio-settings-card-title"><?php esc_html_e( 'Backup', 'woo-image-optimizer' ); ?></h3>
						<div class="wio-field">
							<label>
								<input type="checkbox" name="backup_retention_enabled" value="1" id="wio-retention-toggle" <?php checked( $s['backup_retention_enabled'] ); ?>>
								<?php esc_html_e( 'Auto-delete backups after', 'woo-image-optimizer' ); ?>
							</label>
							<div class="wio-inline-row" style="margin-top:6px">
								<input type="number" name="backup_retention_days" value="<?php echo esc_attr( $s['backup_retention_days'] ); ?>" min="1" style="width:70px">
								<span><?php esc_html_e( 'days', 'woo-image-optimizer' ); ?></span>
							</div>
							<p class="description"><?php esc_html_e( 'When enabled, backups on Server 2 are deleted after the specified number of days. Default: disabled.', 'woo-image-optimizer' ); ?></p>
						</div>
					</div>

				</div><!-- .wio-settings-grid -->

				<p class="submit" style="margin-top:20px">
					<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'woo-image-optimizer' ); ?>">
				</p>
			</form>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// AJAX handlers
	// -----------------------------------------------------------------------

	public function ajax_queue_all(): void {
		check_ajax_referer( 'woo_img_opt_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$ids     = $this->woocommerce->get_all_unoptimized_ids();
		$queued  = 0;
		$skipped = 0;

		foreach ( $ids as $attachment_id ) {
			$product_id = $this->woocommerce->get_product_id_for_attachment( $attachment_id );
			if ( $this->queue->enqueue( $attachment_id, $product_id ) ) {
				$queued++;
			} else {
				$skipped++;
			}
		}

		wp_send_json_success( [
			'queued'  => $queued,
			'skipped' => $skipped,
			'total'   => $queued + $skipped,
			'stats'   => $this->queue->get_stats(),
		] );
	}

	public function ajax_batch(): void {
		check_ajax_referer( 'woo_img_opt_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$batch_size = (int) $this->settings->get( 'batch_size', 5 );
		$result     = $this->processor->process_batch( $batch_size );
		$stats      = $this->queue->get_stats();

		wp_send_json_success( [
			'processed'   => $result['processed'],
			'errors'      => $result['errors'],
			'saved_bytes' => $result['saved_bytes'],
			'done'        => $stats['pending'] === 0 && $stats['processing'] === 0,
			'stats'       => $stats,
		] );
	}

	public function ajax_stats(): void {
		check_ajax_referer( 'woo_img_opt_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		wp_send_json_success( $this->queue->get_stats() );
	}

	public function ajax_restore(): void {
		check_ajax_referer( 'woo_img_opt_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$attachment_id = (int) ( $_POST['attachment_id'] ?? 0 );
		if ( ! $attachment_id ) {
			wp_send_json_error( 'Invalid attachment ID' );
		}

		$result = $this->restore->restore( $attachment_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( [ 'message' => "Attachment #{$attachment_id} restored." ] );
	}

	public function ajax_retry_failed(): void {
		check_ajax_referer( 'woo_img_opt_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$reset = $this->queue->retry_all_failed();

		wp_send_json_success( [
			'reset' => $reset,
			'stats' => $this->queue->get_stats(),
		] );
	}

	public function ajax_test_connection(): void {
		check_ajax_referer( 'woo_img_opt_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$api_url = $this->settings->get( 'api_url' );
		$api_key = $this->settings->get( 'api_key' );

		if ( empty( $api_url ) || empty( $api_key ) ) {
			wp_send_json_error( [ 'message' => 'Enter API URL and Key first.', 'ms' => 0 ] );
		}

		$start    = microtime( true );
		$response = wp_remote_get(
			trailingslashit( $api_url ) . 'backup/wio-connection-test',
			[
				'headers'   => [ 'Authorization' => 'Bearer ' . $api_key ],
				'timeout'   => 10,
				'sslverify' => true,
			]
		);
		$elapsed_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [
				'message' => 'Unreachable: ' . $response->get_error_message(),
				'ms'      => $elapsed_ms,
			] );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code === 401 ) {
			wp_send_json_error( [
				'message' => 'Invalid API key (401 Unauthorized).',
				'ms'      => $elapsed_ms,
			] );
		}

		// 404 = server reachable, key valid, backup key doesn't exist — expected.
		wp_send_json_success( [
			'message' => 'Connected',
			'code'    => $code,
			'ms'      => $elapsed_ms,
		] );
	}

	// -----------------------------------------------------------------------
	// Media library column
	// -----------------------------------------------------------------------

	public function media_column( array $columns ): array {
		$columns['woo_img_optimizer'] = __( 'Img Optimizer', 'woo-image-optimizer' );
		return $columns;
	}

	public function media_column_content( string $column, int $post_id ): void {
		if ( 'woo_img_optimizer' !== $column ) {
			return;
		}

		$status = get_post_meta( $post_id, '_woo_optimizer_status', true );

		if ( $status === 'done' ) {
			$saved = (int) get_post_meta( $post_id, '_woo_optimizer_saved_bytes', true );
			$at    = get_post_meta( $post_id, '_woo_optimizer_optimized_at', true );
			echo '<span class="wio-col-done" title="' . esc_attr( $at ) . '">&#10003; ' . esc_html( size_format( $saved ) ) . ' saved</span>';
			echo ' <button class="button button-small wio-restore-btn" data-id="' . esc_attr( $post_id ) . '" title="Restore original">&#8617;</button>';
			return;
		}

		if ( $status === 'failed' ) {
			echo '<span class="wio-col-failed">&#10007; Failed</span>';
			return;
		}

		// Check if in processing queue
		if ( $this->queue->is_queued( $post_id ) ) {
			echo '<span class="wio-col-queued">&#8987; Queued</span>';
			return;
		}

		echo '<span class="wio-col-na">—</span>';
	}
}
