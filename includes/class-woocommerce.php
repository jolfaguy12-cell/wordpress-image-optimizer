<?php
defined( 'ABSPATH' ) || exit;

class Woo_Image_Optimizer_WooCommerce {

	private Woo_Image_Optimizer_Queue    $queue;
	private Woo_Image_Optimizer_Settings $settings;

	private const PRODUCT_TYPES = [ 'product', 'product_variation' ];

	public function __construct( Woo_Image_Optimizer_Queue $queue, Woo_Image_Optimizer_Settings $settings ) {
		$this->queue    = $queue;
		$this->settings = $settings;

		add_action( 'updated_post_meta', [ $this, 'on_meta_updated' ], 10, 4 );
		add_action( 'added_post_meta',   [ $this, 'on_meta_updated' ], 10, 4 );
	}

	// -----------------------------------------------------------------------
	// Upload hook
	// -----------------------------------------------------------------------

	public function on_meta_updated( $meta_id, int $post_id, string $meta_key, $meta_value ): void {
		if ( ! $this->settings->get( 'auto_optimize' ) ) {
			return;
		}

		if ( $meta_key === '_thumbnail_id' ) {
			$this->queue_featured_image( $post_id, (int) $meta_value );
			return;
		}

		if ( $meta_key === '_product_image_gallery' ) {
			$this->queue_gallery( $post_id, (string) $meta_value );
		}
	}

	private function queue_featured_image( int $post_id, int $attachment_id ): void {
		if ( $attachment_id <= 0 ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, self::PRODUCT_TYPES, true ) ) {
			return;
		}
		if ( $this->should_skip( $attachment_id ) ) {
			return;
		}
		$this->queue->enqueue( $attachment_id, $post_id );
	}

	private function queue_gallery( int $product_id, string $gallery_value ): void {
		$post = get_post( $product_id );
		if ( ! $post || $post->post_type !== 'product' ) {
			return;
		}
		$ids = array_filter( array_map( 'absint', explode( ',', $gallery_value ) ) );
		foreach ( $ids as $attachment_id ) {
			if ( $this->should_skip( $attachment_id ) ) {
				continue;
			}
			$this->queue->enqueue( $attachment_id, $product_id );
		}
	}

	// -----------------------------------------------------------------------
	// Skip rules
	// -----------------------------------------------------------------------

	public function should_skip( int $attachment_id ): bool {
		if ( $this->is_avif( $attachment_id ) ) {
			return true;
		}
		if ( $this->is_webp( $attachment_id ) ) {
			return true;
		}
		return get_post_meta( $attachment_id, '_woo_optimizer_status', true ) === 'done';
	}

	private function is_avif( int $attachment_id ): bool {
		if ( get_post_mime_type( $attachment_id ) === 'image/avif' ) {
			return true;
		}
		$file = get_attached_file( $attachment_id );
		return $file && strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) === 'avif';
	}

	private function is_webp( int $attachment_id ): bool {
		if ( get_post_mime_type( $attachment_id ) === 'image/webp' ) {
			return true;
		}
		$file = get_attached_file( $attachment_id );
		return $file && strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) === 'webp';
	}

	// -----------------------------------------------------------------------
	// Bulk discovery
	// -----------------------------------------------------------------------

	/**
	 * Returns all attachment IDs that are WooCommerce product images and not yet optimized.
	 *
	 * @return int[]
	 */
	public function get_all_unoptimized_ids(): array {
		global $wpdb;

		// Featured images of products and variations
		$featured = $wpdb->get_col(
			"SELECT DISTINCT CAST(pm.meta_value AS UNSIGNED)
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 INNER JOIN {$wpdb->posts} att ON att.ID = CAST(pm.meta_value AS UNSIGNED)
			 LEFT JOIN {$wpdb->postmeta} opt
			       ON opt.post_id = CAST(pm.meta_value AS UNSIGNED)
			      AND opt.meta_key = '_woo_optimizer_status'
			 WHERE pm.meta_key = '_thumbnail_id'
			   AND p.post_type IN ('product','product_variation')
			   AND p.post_status NOT IN ('trash','auto-draft')
			   AND pm.meta_value > 0
			   AND att.post_mime_type NOT IN ('image/webp','image/avif')
			   AND (opt.meta_value IS NULL OR opt.meta_value != 'done')"
		);

		// Gallery images
		$gallery_rows = $wpdb->get_results(
			"SELECT pm.post_id AS product_id, pm.meta_value AS gallery
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_product_image_gallery'
			   AND p.post_type = 'product'
			   AND p.post_status NOT IN ('trash','auto-draft')
			   AND pm.meta_value != ''"
		);

		$gallery_ids = [];
		foreach ( $gallery_rows as $row ) {
			$ids         = array_filter( array_map( 'absint', explode( ',', $row->gallery ) ) );
			$gallery_ids = array_merge( $gallery_ids, $ids );
		}
		$gallery_ids = array_unique( $gallery_ids );

		// Filter out already-optimized gallery IDs
		if ( ! empty( $gallery_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $gallery_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$done = $wpdb->get_col( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = '_woo_optimizer_status'
				   AND meta_value = 'done'
				   AND post_id IN ({$placeholders})",
				...$gallery_ids
			) );
			$gallery_ids = array_diff( $gallery_ids, array_map( 'intval', $done ) );
		}

		$all = array_values( array_unique( array_filter( array_merge(
			array_map( 'intval', $featured ),
			array_values( $gallery_ids )
		) ) ) );

		// SQL already excludes done/webp/avif for featured; apply all skip rules to gallery too.
		return array_values( array_filter( $all, function ( int $id ) {
			return ! $this->should_skip( $id );
		} ) );
	}

	/**
	 * Find the product (or variation) that owns a given attachment.
	 */
	public function get_product_id_for_attachment( int $attachment_id ): int {
		global $wpdb;

		$product_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.post_id
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_thumbnail_id'
			   AND pm.meta_value = %d
			   AND p.post_type IN ('product','product_variation')
			   AND p.post_status != 'trash'
			 LIMIT 1",
			$attachment_id
		) );

		if ( $product_id ) {
			return (int) $product_id;
		}

		$product_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.post_id
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_product_image_gallery'
			   AND FIND_IN_SET(%d, pm.meta_value) > 0
			   AND p.post_type = 'product'
			   AND p.post_status != 'trash'
			 LIMIT 1",
			$attachment_id
		) );

		return (int) $product_id;
	}
}
