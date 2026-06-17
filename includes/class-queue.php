<?php
defined( 'ABSPATH' ) || exit;

class Woo_Image_Optimizer_Queue {

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'woo_optimizer_queue';
	}

	public static function create_table(): void {
		global $wpdb;
		$table      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT UNSIGNED NOT NULL,
			product_id    BIGINT UNSIGNED NOT NULL,
			status        ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
			attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
			error_msg     TEXT NULL,
			created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			INDEX idx_status (status),
			INDEX idx_attachment (attachment_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public function enqueue( int $attachment_id, int $product_id ): bool {
		global $wpdb;

		if ( $this->is_queued( $attachment_id ) ) {
			return false;
		}

		return (bool) $wpdb->insert(
			self::table_name(),
			[
				'attachment_id' => $attachment_id,
				'product_id'    => $product_id,
				'status'        => 'pending',
				'attempts'      => 0,
			],
			[ '%d', '%d', '%s', '%d' ]
		);
	}

	/**
	 * Picks $limit pending jobs, marks them processing, and returns their rows.
	 */
	public function dequeue_batch( int $limit ): array {
		global $wpdb;
		$table = self::table_name();

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			 SET status = 'processing', attempts = attempts + 1, updated_at = NOW()
			 WHERE status = 'pending'
			 ORDER BY created_at ASC
			 LIMIT %d",
			$limit
		) );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE status = 'processing'
				 ORDER BY created_at ASC
				 LIMIT %d",
				$limit
			)
		);
	}

	public function mark_done( int $id ): void {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			[ 'status' => 'done' ],
			[ 'id' => $id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	public function mark_failed( int $id, string $error_msg = '' ): void {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			[ 'status' => 'failed', 'error_msg' => $error_msg ],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	public function retry( int $id ): void {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			[ 'status' => 'pending' ],
			[ 'id' => $id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Resets jobs stuck in 'processing' for more than 5 minutes back to 'pending'.
	 */
	public function reset_stale(): void {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query(
			"UPDATE {$table}
			 SET status = 'pending', updated_at = NOW()
			 WHERE status = 'processing'
			   AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
			   AND attempts < 3"
		);
	}

	public function is_queued( int $attachment_id ): bool {
		global $wpdb;
		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . self::table_name() . "
			 WHERE attachment_id = %d AND status IN ('pending','processing','done')",
			$attachment_id
		) );
		return (int) $count > 0;
	}

	public function get_stats(): array {
		global $wpdb;
		$table = self::table_name();

		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status"
		);

		$stats = [ 'pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0, 'total' => 0 ];
		foreach ( $rows as $row ) {
			if ( isset( $stats[ $row->status ] ) ) {
				$stats[ $row->status ] = (int) $row->cnt;
			}
			$stats['total'] += (int) $row->cnt;
		}

		$stats['saved_bytes'] = (int) $wpdb->get_var(
			"SELECT COALESCE(SUM(CAST(meta_value AS UNSIGNED)), 0)
			 FROM {$wpdb->postmeta}
			 WHERE meta_key = '_woo_optimizer_saved_bytes'"
		);

		return $stats;
	}

	public function drop_table(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table_name() );
	}
}
