<?php
/**
 * Metamanager Database Class
 *
 * Single, authoritative database schema and query layer.
 * One table. One schema. No conflicts.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MM_DB
 */
class MM_DB {

	/**
	 * Full (prefixed) table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . MM_JOB_TABLE;
	}

	/**
	 * Create or update the jobs table using dbDelta.
	 * Safe to call on every admin_init — dbDelta only applies changes.
	 */
	public static function create_or_update_table(): void {
		global $wpdb;

		$table          = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT(20) UNSIGNED NOT NULL,
			image_name    VARCHAR(255)        NOT NULL DEFAULT '',
			job_type      VARCHAR(32)         NOT NULL DEFAULT '',
			file_path     TEXT                NOT NULL,
			size          VARCHAR(64)         NOT NULL DEFAULT '',
			dimensions    VARCHAR(32)         NOT NULL DEFAULT '',
			bytes_before  BIGINT(20) UNSIGNED          DEFAULT NULL,
			bytes_after   BIGINT(20) UNSIGNED          DEFAULT NULL,
			status        VARCHAR(32)         NOT NULL DEFAULT 'pending',
			submitted_at  DATETIME            NOT NULL,
			completed_at  DATETIME                     DEFAULT NULL,
			details       LONGTEXT                     DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_attachment (attachment_id),
			KEY idx_job_type   (job_type),
			KEY idx_status     (status)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Insert a completed or failed job result into the DB.
	 *
	 * Expected keys in $job:
	 *   attachment_id, image_name, job_type, file_path, size, dimensions,
	 *   status, submitted_at, completed_at, details (optional array).
	 *
	 * @param array $job Associative array of job data.
	 */
	public static function log_job( array $job ): void {
		global $wpdb;

		$table = self::table_name();

		$bytes_before = isset( $job['bytes_before'] ) && (int) $job['bytes_before'] > 0 ? (int) $job['bytes_before'] : null;
		$bytes_after  = isset( $job['bytes_after'] )  && (int) $job['bytes_after']  > 0 ? (int) $job['bytes_after']  : null;

		$data    = [
			'attachment_id' => absint( $job['attachment_id'] ?? 0 ),
			'image_name'    => sanitize_text_field( $job['image_name'] ?? '' ),
			'job_type'      => sanitize_key( $job['job_type'] ?? 'unknown' ),
			'file_path'     => sanitize_text_field( $job['file_path'] ?? '' ),
			'size'          => sanitize_key( $job['size'] ?? '' ),
			'dimensions'    => sanitize_text_field( $job['dimensions'] ?? '' ),
			'status'        => sanitize_key( $job['status'] ?? 'completed' ),
			'submitted_at'  => sanitize_text_field( $job['submitted_at'] ?? current_time( 'mysql' ) ),
			'completed_at'  => sanitize_text_field( $job['completed_at'] ?? current_time( 'mysql' ) ),
			'details'       => wp_json_encode( $job['details'] ?? [] ),
		];
		$formats = [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ];

		if ( null !== $bytes_before ) {
			$data['bytes_before'] = $bytes_before;
			$formats[]            = '%d';
		}
		if ( null !== $bytes_after ) {
			$data['bytes_after'] = $bytes_after;
			$formats[]           = '%d';
		}

		$wpdb->insert( $table, $data, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Query job history with optional search, ordering, and pagination.
	 *
	 * @param array $args {
	 *   @type string $search    Search term for image_name / job_type / status.
	 *   @type string $orderby   Column to order by. Whitelisted.
	 *   @type string $order     ASC or DESC.
	 *   @type int    $per_page  Rows per page.
	 *   @type int    $paged     Current page (1-based).
	 * }
	 * @return array { jobs: array, total: int }
	 */
	public static function get_jobs( array $args = [] ): array {
		global $wpdb;

		$table = self::table_name();

		$defaults = [
			'search'   => '',
			'orderby'  => 'id',
			'order'    => 'DESC',
			'per_page' => 20,
			'paged'    => 1,
		];
		$args = wp_parse_args( $args, $defaults );

		$allowed_orderby = [ 'id', 'image_name', 'job_type', 'status', 'submitted_at', 'completed_at', 'bytes_before', 'bytes_after' ];
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$per_page        = max( 1, (int) $args['per_page'] );
		$offset          = ( max( 1, (int) $args['paged'] ) - 1 ) * $per_page;

		if ( ! empty( $args['search'] ) ) {
			$like  = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where = $wpdb->prepare(
				'WHERE image_name LIKE %s OR job_type LIKE %s OR status LIKE %s',
				$like, $like, $like
			);
		} else {
			$where = '';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );

		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);
		// phpcs:enable

		return [ 'jobs' => $jobs ?: [], 'total' => $total ];
	}

	/**
	 * Fetch a single job row by ID for re-queue operations.
	 *
	 * @param int $job_id DB row ID.
	 * @return object|null
	 */
	public static function get_job( int $job_id ): ?object {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $job_id ) );
	}

	/**
	 * Return aggregate statistics: total bytes saved, job counts by type and status.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_stats(): array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			"SELECT
				COUNT(*)                                          AS total_jobs,
				SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed,
				SUM(CASE WHEN status='failed'    THEN 1 ELSE 0 END) AS failed,
				SUM(CASE WHEN job_type='compression' AND status='completed' AND bytes_before > 0 AND bytes_after > 0 AND bytes_after < bytes_before
				         THEN (bytes_before - bytes_after) ELSE 0 END) AS bytes_saved,
				SUM(CASE WHEN job_type='compression' AND status='completed' AND bytes_before > 0 AND bytes_after > 0 AND bytes_after < bytes_before
				         THEN bytes_before ELSE 0 END)             AS bytes_original,
				COUNT(DISTINCT attachment_id)                     AS unique_attachments
			FROM {$table}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return $row ? (array) $row : [
			'total_jobs'         => 0,
			'completed'          => 0,
			'failed'             => 0,
			'bytes_saved'        => 0,
			'bytes_original'     => 0,
			'unique_attachments' => 0,
		];
	}

	/**
	 * Truncate the entire jobs history table.
	 */
	public static function clear_history(): void {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}

// Run on every admin_init to self-heal if the table drifts (e.g. after manual DB restore).
add_action( 'admin_init', [ 'MM_DB', 'create_or_update_table' ] );
