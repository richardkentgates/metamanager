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
	 * Prevents the one-time dedup migration from running more than once per request.
	 */
	private static bool $dedup_done = false;

	/**
	 * Full (prefixed) table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . MM_JOB_TABLE;
	}

	/**
	 * Drop the jobs table. Called during uninstall when data deletion is enabled.
	 */
	public static function drop_table(): void {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
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

		self::maybe_deduplicate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT(20) UNSIGNED NOT NULL,
			image_name VARCHAR(255) NOT NULL DEFAULT '',
			job_type VARCHAR(32) NOT NULL DEFAULT '',
			job_trigger VARCHAR(64) NOT NULL DEFAULT '',
			file_path TEXT NOT NULL,
			size VARCHAR(64) NOT NULL DEFAULT '',
			dimensions VARCHAR(32) NOT NULL DEFAULT '',
			bytes_before BIGINT(20) UNSIGNED DEFAULT NULL,
			bytes_after BIGINT(20) UNSIGNED DEFAULT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'pending',
			submitted_at DATETIME NOT NULL,
			completed_at DATETIME DEFAULT NULL,
			details LONGTEXT DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_job (attachment_id, job_type, size),
			KEY idx_attachment (attachment_id),
			KEY idx_job_type (job_type),
			KEY idx_status (status)
		) {$charset_collate};";

		// dbDelta is sensitive to leading whitespace; normalize before calling it.
		$sql = implode( "\n", array_map( 'ltrim', explode( "\n", $sql ) ) );

		dbDelta( $sql );
	}

	/**
	 * One-time migration: removes duplicate rows (same attachment_id + job_type + size)
	 * before dbDelta adds the UNIQUE KEY that enforces the one-row-per-triple invariant.
	 * Runs at most once per request and skips immediately once the key exists.
	 */
	public static function maybe_deduplicate(): void {
		if ( self::$dedup_done ) {
			return;
		}
		self::$dedup_done = true;

		global $wpdb;
		$table = self::table_name();

		// Skip on a fresh install — the table doesn't exist yet and dbDelta will
		// create it with the UNIQUE KEY already in place.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return;
		}

		// Once the UNIQUE KEY is present there is nothing left to clean up.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $wpdb->get_var( "SHOW INDEX FROM {$table} WHERE Key_name = 'uniq_job'" ) ) {
			return;
		}

		// Keep only the latest row per (attachment_id, job_type, size).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"DELETE t1 FROM {$table} t1
			 INNER JOIN {$table} t2
			    ON  t1.attachment_id = t2.attachment_id
			    AND t1.job_type      = t2.job_type
			    AND t1.size          = t2.size
			    AND t1.id            < t2.id"
		);
	}

	/**
	 * Insert a completed or failed job result into the DB.
	 *
	 * Expected keys in $job:
	 *   attachment_id, image_name, job_type, file_path, size, dimensions,
	 *   status, submitted_at, completed_at, details (optional array).
	 *
	 * @param array $job Associative array of job data.
	 * @return bool True on successful insert, false on failure.
	 */
	public static function log_job( array $job ): bool {
		global $wpdb;

		$table = self::table_name();

		$bytes_before = isset( $job['bytes_before'] ) && (int) $job['bytes_before'] > 0 ? (int) $job['bytes_before'] : null;
		$bytes_after  = isset( $job['bytes_after'] )  && (int) $job['bytes_after']  > 0 ? (int) $job['bytes_after']  : null;

		$attachment_id = absint( $job['attachment_id'] ?? 0 );
		$job_type      = sanitize_key( $job['job_type'] ?? 'unknown' );
		$size          = sanitize_key( $job['size'] ?? '' );

		$data    = [
			'attachment_id' => $attachment_id,
			'image_name'    => sanitize_text_field( $job['image_name'] ?? '' ),
			'job_type'      => $job_type,
			'job_trigger'   => sanitize_key( $job['trigger'] ?? '' ),
			'file_path'     => sanitize_text_field( $job['file_path'] ?? '' ),
			'size'          => $size,
			'dimensions'    => sanitize_text_field( $job['dimensions'] ?? '' ),
			'status'        => sanitize_key( $job['status'] ?? 'completed' ),
			'submitted_at'  => sanitize_text_field( $job['submitted_at'] ?? current_time( 'mysql' ) ),
			'completed_at'  => sanitize_text_field( $job['completed_at'] ?? current_time( 'mysql' ) ),
			'details'       => wp_json_encode( $job['details'] ?? [] ),
		];
		$formats = [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ];

		if ( null !== $bytes_before ) {
			$data['bytes_before'] = $bytes_before;
			$formats[]            = '%d';
		}
		if ( null !== $bytes_after ) {
			$data['bytes_after'] = $bytes_after;
			$formats[]          = '%d';
		}

		// REPLACE INTO atomically enforces the UNIQUE KEY (attachment_id, job_type, size),
		// removing any stale row for the same triple before writing the new state.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->replace( $table, $data, $formats );
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
	 * @return array{
	 *   jobs: list<object{id: int, attachment_id: int, image_name: string, job_type: string, job_trigger: string, file_path: string, size: string, dimensions: string, bytes_before: int, bytes_after: int, status: string, submitted_at: string, completed_at: string|null, details: string|null}>,
	 *   total: int
	 * }
	 */
	public static function get_jobs( array $args = [] ): array {
		global $wpdb;

		$table = self::table_name();

		$defaults = [
			'search'   => '',
			'status'   => '',
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

		$conditions = [];
		if ( ! empty( $args['search'] ) ) {
			$like         = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$conditions[] = $wpdb->prepare(
				'(image_name LIKE %s OR job_type LIKE %s)',
				$like, $like
			);
		}
		$allowed_statuses = [ 'pending', 'completed', 'failed' ];
		if ( ! empty( $args['status'] ) && in_array( $args['status'], $allowed_statuses, true ) ) {
			$conditions[] = $wpdb->prepare( 'status = %s', $args['status'] );
		} elseif ( ! empty( $args['status'] ) && 'not_pending' === $args['status'] ) {
			$conditions[] = "status != 'pending'";
		}
		$where = $conditions ? 'WHERE ' . implode( ' AND ', $conditions ) : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
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
	 * Delete all job history rows for a specific attachment.
	 * Hooked to 'delete_attachment' so the history self-cleans when media is
	 * removed from the library.
	 *
	 * @param int $attachment_id WordPress attachment post ID.
	 */
	public static function delete_jobs_for_attachment( int $attachment_id ): void {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, [ 'attachment_id' => $attachment_id ], [ '%d' ] );
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
	 * Check whether a completed compression job exists for this attachment + size.
	 * Single source of truth for the library compression column.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $size          Size slug (e.g. 'full', 'thumbnail').
	 * @return bool
	 */
	public static function has_completed_compression( int $attachment_id, string $size ): bool {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE attachment_id = %d AND job_type = 'compression' AND size = %s AND status = 'completed'",
			$attachment_id,
			$size
		) );
	}

	/**
	 * Check whether any completed metadata embedding job exists for this attachment.
	 * Single source of truth for the library metadata column.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function has_any_completed_metadata( int $attachment_id ): bool {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE attachment_id = %d AND job_type = 'metadata' AND status = 'completed'",
			$attachment_id
		) );
	}

	/**
	 * Write a 'pending' row when a job is queued.
	 * Same upsert as log_job, so each (attachment_id, job_type, size) always has
	 * exactly one row and its status reflects current state: pending → completed/failed.
	 *
	 * @param array $job Job data (same keys that write_job builds).
	 */
	public static function log_pending_job( array $job ): void {
		global $wpdb;
		$table = self::table_name();

		$attachment_id = absint( $job['attachment_id'] ?? 0 );
		$job_type      = sanitize_key( $job['job_type'] ?? 'unknown' );
		$size          = sanitize_key( $job['size'] ?? '' );

		// completed_at is omitted so it defaults to NULL in the schema.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->replace(
			$table,
			[
				'attachment_id' => $attachment_id,
				'image_name'    => sanitize_text_field( $job['image_name'] ?? '' ),
				'job_type'      => $job_type,
				'job_trigger'   => sanitize_key( $job['trigger'] ?? '' ),
				'file_path'     => sanitize_text_field( $job['file_path'] ?? '' ),
				'size'          => $size,
				'dimensions'    => sanitize_text_field( $job['dimensions'] ?? '' ),
				'status'        => 'pending',
				'submitted_at'  => sanitize_text_field( $job['submitted_at'] ?? current_time( 'mysql' ) ),
				'details'       => wp_json_encode( [] ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Return true if any completed job exists for this attachment.
	 * Used to distinguish a fresh upload from a thumbnail regeneration.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function has_any_completed_job( int $attachment_id ): bool {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE attachment_id = %d AND status = 'completed'",
			$attachment_id
		) );
	}

	/**
	 * Return attachment IDs that have at least one completed job of the given type.
	 * Used by library scan to find unprocessed attachments without post_meta queries.
	 *
	 * @param string $job_type 'compression' or 'metadata'.
	 * @return int[]
	 */
	public static function get_ids_with_completed_job( string $job_type ): array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT attachment_id FROM {$table} WHERE job_type = %s AND status = 'completed'",
			$job_type
		) );
		return $ids ? array_map( 'intval', $ids ) : [];
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
