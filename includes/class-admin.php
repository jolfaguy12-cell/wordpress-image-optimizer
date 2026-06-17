<?php
defined( 'ABSPATH' ) || exit;

class BDSK_Optimizer_Admin {

    private $settings;
    private $engine;

    public function __construct( BDSK_Optimizer_Settings $settings, BDSK_Optimizer_Engine $engine ) {
        $this->settings = $settings;
        $this->engine   = $engine;

        add_action( 'admin_menu',             [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_scripts' ] );
        add_action( 'admin_post_bdsk_optimizer_save', [ $this, 'save_settings' ] );

        // Media library column
        add_filter( 'manage_media_columns',       [ $this, 'media_column' ] );
        add_action( 'manage_media_custom_column', [ $this, 'media_column_content' ], 10, 2 );
        add_filter( 'attachment_fields_to_edit',  [ $this, 'attachment_fields' ], 10, 2 );
    }

    public function add_menu() {
        add_media_page(
            'WordPress Image Optimizer',
            'WordPress Image Optimizer',
            'upload_files',
            'bdsk-optimizer',
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_scripts( $hook ) {
        if ( 'media_page_bdsk-optimizer' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'bdsk-optimizer',
            BDSK_OPT_URL . 'assets/css/admin.css',
            [],
            BDSK_OPT_VERSION
        );

        wp_enqueue_script(
            'bdsk-optimizer',
            BDSK_OPT_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            BDSK_OPT_VERSION,
            true
        );

        wp_localize_script( 'bdsk-optimizer', 'bdskOptimizer', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'bdsk_optimizer_nonce' ),
            'batchSize' => (int) $this->settings->get( 'batch_size', 5 ),
            'i18n'      => [
                'processing' => __( 'Processing…', 'bdsk-optimizer' ),
                'done'       => __( 'All images optimized!', 'bdsk-optimizer' ),
                'paused'     => __( 'Paused', 'bdsk-optimizer' ),
                'error'      => __( 'Error', 'bdsk-optimizer' ),
            ],
        ] );
    }

    public function save_settings() {
        check_admin_referer( 'bdsk_optimizer_settings' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        $this->settings->save( $_POST );

        wp_redirect( add_query_arg( [ 'page' => 'bdsk-optimizer', 'saved' => '1' ], admin_url( 'upload.php' ) ) );
        exit;
    }

    public function render_page() {
        $stats = $this->engine->get_stats();
        $s     = $this->settings->get_all();
        ?>
        <div class="wrap bdsk-optimizer-wrap">
            <h1>WordPress Image Optimizer</h1>

            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
            <?php endif; ?>

            <!-- Stats Bar -->
            <div class="bdsk-stats-bar">
                <div class="bdsk-stat">
                    <span class="bdsk-stat-number"><?php echo esc_html( $stats['total'] ); ?></span>
                    <span class="bdsk-stat-label">Total Images</span>
                </div>
                <div class="bdsk-stat">
                    <span class="bdsk-stat-number"><?php echo esc_html( $stats['optimized'] ); ?></span>
                    <span class="bdsk-stat-label">Optimized</span>
                </div>
                <div class="bdsk-stat">
                    <span class="bdsk-stat-number bdsk-unoptimized"><?php echo esc_html( $stats['unoptimized'] ); ?></span>
                    <span class="bdsk-stat-label">Remaining</span>
                </div>
                <div class="bdsk-stat">
                    <span class="bdsk-stat-number bdsk-saved"><?php echo esc_html( $stats['saved_human'] ); ?></span>
                    <span class="bdsk-stat-label">Saved</span>
                </div>
                <div class="bdsk-stat bdsk-stat-engine">
                    <span class="bdsk-stat-label">Engine</span>
                    <span class="bdsk-engine-badge"><?php echo esc_html( $stats['engine'] ); ?></span>
                </div>
            </div>

            <!-- Progress Bar -->
            <div class="bdsk-progress-wrap" id="bdsk-progress-wrap" style="display:none">
                <div class="bdsk-progress-bar-track">
                    <div class="bdsk-progress-bar-fill" id="bdsk-progress-fill"></div>
                </div>
                <div class="bdsk-progress-text" id="bdsk-progress-text">0 / <?php echo esc_html( $stats['unoptimized'] ); ?></div>
            </div>

            <!-- Bulk Actions -->
            <div class="bdsk-bulk-wrap">
                <?php if ( $stats['unoptimized'] > 0 ) : ?>
                    <button class="button button-primary button-large" id="bdsk-start">
                        Optimize <?php echo esc_html( $stats['unoptimized'] ); ?> Remaining Images
                    </button>
                    <button class="button button-large" id="bdsk-pause" style="display:none">Pause</button>
                    <button class="button button-large" id="bdsk-resume" style="display:none">Resume</button>
                <?php else : ?>
                    <p class="bdsk-all-done">All images are optimized!</p>
                <?php endif; ?>
            </div>

            <!-- Log -->
            <div class="bdsk-log" id="bdsk-log" style="display:none">
                <h3>Processing Log</h3>
                <ul id="bdsk-log-list"></ul>
            </div>

            <hr>

            <!-- Settings -->
            <h2>Settings</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="bdsk_optimizer_save">
                <?php wp_nonce_field( 'bdsk_optimizer_settings' ); ?>

                <table class="form-table bdsk-settings-table">
                    <tr>
                        <th>Compression Mode</th>
                        <td>
                            <select name="compression_level">
                                <option value="lossy" <?php selected( $s['compression_level'], 'lossy' ); ?>>Lossy (Recommended — best size/quality balance)</option>
                                <option value="ultra-lossy" <?php selected( $s['compression_level'], 'ultra-lossy' ); ?>>Ultra Lossy (Smallest files, visible quality drop)</option>
                                <option value="lossless" <?php selected( $s['compression_level'], 'lossless' ); ?>>Lossless (No quality loss, larger files)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>JPEG Quality <span class="bdsk-hint">(1–100)</span></th>
                        <td>
                            <input type="number" name="jpeg_quality" value="<?php echo esc_attr( $s['jpeg_quality'] ); ?>" min="1" max="100" class="small-text">
                            <p class="description">Default: 82. Visible difference below 70.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>WebP Quality <span class="bdsk-hint">(1–100)</span></th>
                        <td>
                            <input type="number" name="webp_quality" value="<?php echo esc_attr( $s['webp_quality'] ); ?>" min="1" max="100" class="small-text">
                            <p class="description">Default: 80. WebP at 80 ≈ JPEG at 85–90 visually.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>PNG Compression <span class="bdsk-hint">(0–9)</span></th>
                        <td>
                            <input type="number" name="png_compression" value="<?php echo esc_attr( $s['png_compression'] ); ?>" min="0" max="9" class="small-text">
                            <p class="description">9 = maximum compression (lossless). Default: 6.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Max Image Dimensions</th>
                        <td>
                            <input type="number" name="max_width" value="<?php echo esc_attr( $s['max_width'] ); ?>" min="0" class="small-text"> ×
                            <input type="number" name="max_height" value="<?php echo esc_attr( $s['max_height'] ); ?>" min="0" class="small-text"> px
                            <p class="description">Images larger than this will be resized on optimize. Set 0 to disable.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Batch Size</th>
                        <td>
                            <input type="number" name="batch_size" value="<?php echo esc_attr( $s['batch_size'] ); ?>" min="1" max="20" class="small-text">
                            <p class="description">Images to process per AJAX request. Lower if you hit timeouts. Default: 5.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Options</th>
                        <td>
                            <label><input type="checkbox" name="convert_webp" value="1" <?php checked( $s['convert_webp'] ); ?>> Generate WebP versions of JPEG/PNG</label><br>
                            <label><input type="checkbox" name="serve_webp" value="1" <?php checked( $s['serve_webp'] ); ?>> Serve WebP to supporting browsers (PHP rewrite)</label><br>
                            <label><input type="checkbox" name="auto_optimize" value="1" <?php checked( $s['auto_optimize'] ); ?>> Auto-optimize images on upload</label><br>
                            <label><input type="checkbox" name="optimize_thumbnails" value="1" <?php checked( $s['optimize_thumbnails'] ); ?>> Optimize all thumbnail sizes</label><br>
                            <label><input type="checkbox" name="backup_originals" value="1" <?php checked( $s['backup_originals'] ); ?>> Backup originals before optimizing</label><br>
                            <label><input type="checkbox" name="strip_metadata" value="1" <?php checked( $s['strip_metadata'] ); ?>> Strip EXIF/IPTC metadata</label>
                        </td>
                    </tr>
                    <tr>
                        <th>Excluded Paths</th>
                        <td>
                            <textarea name="excluded_paths" rows="4" class="large-text"><?php echo esc_textarea( implode( "\n", $s['excluded_paths'] ) ); ?></textarea>
                            <p class="description">One path fragment per line. Images whose path contains any of these will be skipped.</p>
                        </td>
                    </tr>
                </table>

                <p class="submit"><input type="submit" class="button button-primary" value="Save Settings"></p>
            </form>

            <!-- WebP Server Rules -->
            <hr>
            <h2>WebP Server Rules</h2>
            <p>For the best WebP performance on the main site (Apache/cPanel), add these rules to your <code>.htaccess</code>:</p>
            <textarea class="large-text code" rows="10" readonly onclick="this.select()"><?php echo esc_textarea( BDSK_Optimizer_WebP::get_htaccess_rules() ); ?></textarea>

            <p>For this Nginx dev server, add to the <code>server {}</code> block:</p>
            <textarea class="large-text code" rows="8" readonly onclick="this.select()"><?php echo esc_textarea( BDSK_Optimizer_WebP::get_nginx_rules() ); ?></textarea>
        </div>
        <?php
    }

    // Media library column
    public function media_column( $columns ) {
        $columns['bdsk_optimizer'] = 'Optimizer';
        return $columns;
    }

    public function media_column_content( $column, $post_id ) {
        if ( 'bdsk_optimizer' !== $column ) {
            return;
        }

        $mime = get_post_mime_type( $post_id );
        if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/gif' ], true ) ) {
            echo '<span class="bdsk-col-na">—</span>';
            return;
        }

        $meta = get_post_meta( $post_id, '_bdsk_optimizer', true );

        if ( $meta ) {
            $saved = size_format( $meta['total_saved'] ?? 0 );
            echo '<span class="bdsk-col-done" title="Optimized ' . esc_attr( $meta['optimized_at'] ?? '' ) . '">✓ ' . esc_html( $saved ) . ' saved</span>';
        } else {
            echo '<button class="button button-small bdsk-optimize-single" data-id="' . esc_attr( $post_id ) . '">Optimize</button>';
        }
    }

    public function attachment_fields( $fields, $post ) {
        $meta = get_post_meta( $post->ID, '_bdsk_optimizer', true );

        if ( $meta ) {
            $fields['bdsk_optimizer'] = [
                'label' => 'Optimizer',
                'input' => 'html',
                'html'  => '<span style="color:green">✓ Optimized — ' . esc_html( size_format( $meta['total_saved'] ?? 0 ) ) . ' saved</span>',
            ];
        }

        return $fields;
    }
}
