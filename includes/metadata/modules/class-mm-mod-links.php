<?php
/**
 * MM_Mod_Links — asynchronous broken-link checker.
 *
 * Stores every href/src found in saved posts in {prefix}mm_meta_links.
 * A WP-Cron job issues HEAD requests in batches to detect broken links
 * and records the HTTP status code.
 *
 * Admin AJAX endpoints expose table data and per-link re-check.
 */

defined( 'ABSPATH' ) || exit;

class MM_Mod_Links extends MM_Mod_Base {

	/** Nothing to add to HTML head. */
	public function populate( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {}

	public function register_hooks(): void {
		if ( ! $this->settings->get( 'links.enabled', true ) ) {
			return;
		}

		// Register custom cron schedule.
		add_filter( 'cron_schedules', [ $this, 'add_cron_schedule' ] );

		// Schedule the recurring check if not yet set.
		add_action( 'wp', [ $this, 'maybe_schedule_cron' ] );
		add_action( 'mm_meta_check_links', [ $this, 'run_batch_check' ] );

		// Extract links on post save.
		add_action( 'save_post', [ $this, 'extract_from_post' ], 10, 2 );

		// On post delete, purge link records.
		add_action( 'before_delete_post', [ $this, 'purge_for_post' ] );

		// Admin AJAX.
		add_action( 'wp_ajax_mm_meta_links_fetch',    [ $this, 'ajax_fetch_links' ] );
		add_action( 'wp_ajax_mm_meta_recheck_link',   [ $this, 'ajax_recheck_link' ] );
		add_action( 'wp_ajax_mm_meta_ignore_link',    [ $this, 'ajax_ignore_link' ] );
		add_action( 'wp_ajax_mm_meta_scan_all_posts', [ $this, 'ajax_scan_all_posts' ] );
	}

	// -------------------------------------------------------------------------
	// DB table
	// -------------------------------------------------------------------------

	public static function create_table(): void {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			url         TEXT            NOT NULL,
			url_hash    CHAR(32)        NOT NULL,
			post_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			anchor_text VARCHAR(500)    NOT NULL DEFAULT '',
			link_type   ENUM('internal','external') NOT NULL DEFAULT 'external',
			http_code   SMALLINT UNSIGNED          NOT NULL DEFAULT 0,
			is_broken   TINYINT(1)                 NOT NULL DEFAULT 0,
			is_ignored  TINYINT(1)                 NOT NULL DEFAULT 0,
			last_checked DATETIME                  NULL,
			created     DATETIME                   NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY url_post (url_hash, post_id),
			KEY is_broken (is_broken),
			KEY last_checked (last_checked)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'mm_meta_links';
	}

	// -------------------------------------------------------------------------
	// Link extraction
	// -------------------------------------------------------------------------

	public function extract_from_post( int $post_id, \WP_Post $post ): void {
		// Only public post types; skip auto-saves and revisions.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		$monitored_types = array_keys( $this->settings->get( 'sitemap.post_types', [ 'post' => true, 'page' => true ] ) );
		if ( ! in_array( $post->post_type, $monitored_types, true ) ) {
			return;
		}

		$content = $post->post_content;
		$links   = $this->parse_links( $content, $post_id );

		global $wpdb;
		$table = self::table_name();

		foreach ( $links as $link ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare(
				"INSERT INTO {$table} (url, url_hash, post_id, anchor_text, link_type)
				 VALUES (%s, %s, %d, %s, %s)
				 ON DUPLICATE KEY UPDATE anchor_text = VALUES(anchor_text)",
				$link['url'],
				md5( $link['url'] ),
				$post_id,
				$link['anchor'],
				$link['type']
			) );
		}
	}

	private function parse_links( string $html, int $post_id ): array {
		$links = [];
		$home  = home_url();

		// Extract <a href="...">anchor</a>.
		if ( preg_match_all( '/<a[^>]+href=["\']([^"\'#][^"\']*)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$url    = esc_url_raw( $m[1] );
				$anchor = wp_strip_all_tags( $m[2] );
				if ( ! $url || strpos( $url, 'mailto:' ) === 0 || strpos( $url, 'javascript:' ) === 0 ) {
					continue;
				}
				// Make relative URLs absolute.
				if ( strpos( $url, 'http' ) !== 0 ) {
					$url = trailingslashit( $home ) . ltrim( $url, '/' );
				}
				$links[] = [
					'url'    => $url,
					'anchor' => substr( $anchor, 0, 500 ),
					'type'   => ( strpos( $url, $home ) === 0 ) ? 'internal' : 'external',
				];
			}
		}

		return $links;
	}

	public function purge_for_post( int $post_id ): void {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, [ 'post_id' => $post_id ], [ '%d' ] );
	}

	// -------------------------------------------------------------------------
	// Batch checker (WP-Cron)
	// -------------------------------------------------------------------------

	public function add_cron_schedule( array $schedules ): array {
		$schedules['mm_meta_6h'] = [
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => 'Every 6 Hours',
		];
		return $schedules;
	}

	public function maybe_schedule_cron(): void {
		$freq = $this->settings->get( 'links.cron_frequency', 'twicedaily' );
		if ( ! wp_next_scheduled( 'mm_meta_check_links' ) ) {
			wp_schedule_event( time(), $freq, 'mm_meta_check_links' );
		}
	}

	public function run_batch_check(): void {
		global $wpdb;
		$table      = self::table_name();
		$batch_size = (int) $this->settings->get( 'links.batch_size', 50 );
		$timeout    = (int) $this->settings->get( 'links.timeout', 10 );

		// Pick the $batch_size links least-recently checked (NULL first).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, url FROM {$table}
			 WHERE is_ignored = 0
			 ORDER BY last_checked ASC, id ASC
			 LIMIT %d",
			$batch_size
		), ARRAY_A );

		if ( ! $rows ) {
			return;
		}

		$ignore_domains = array_filter( (array) $this->settings->get( 'links.ignore_domains', [] ) );

		foreach ( $rows as $row ) {
			$url = $row['url'];

			// Skip user-ignored domains.
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( $host && in_array( $host, $ignore_domains, true ) ) {
				continue;
			}

			$code = $this->head_request( $url, $timeout );
			$broken = ( $code === 0 || ( $code >= 400 && $code !== 429 ) ) ? 1 : 0;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$table,
				[
					'http_code'    => $code,
					'is_broken'    => $broken,
					'last_checked' => current_time( 'mysql', true ),
				],
				[ 'id' => (int) $row['id'] ],
				[ '%d', '%d', '%s' ],
				[ '%d' ]
			);
		}

		// Optional email alert.
		if ( $this->settings->get( 'links.email_alerts', false ) ) {
			$this->maybe_send_alert();
		}
	}

	private function head_request( string $url, int $timeout ): int {
		$response = wp_remote_head( $url, [
			'timeout'     => $timeout,
			'redirection' => 3,
			'sslverify'   => false,
			'user-agent'  => 'GCM-SEO-LinkChecker/1.0',
		] );
		if ( is_wp_error( $response ) ) {
			return 0;
		}
		return (int) wp_remote_retrieve_response_code( $response );
	}

	private function maybe_send_alert(): void {
		global $wpdb;
		$table    = self::table_name();
		$interval = HOUR_IN_SECONDS * 24;
		$cache_key = 'mm_meta_link_alert_sent';

		if ( get_transient( $cache_key ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE is_broken = 1 AND is_ignored = 0" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		) );

		if ( $count > 0 ) {
			$email   = sanitize_email( $this->settings->get( 'links.email_address', get_option( 'admin_email' ) ) );
			$subject = sprintf( '[%s] %d broken link(s) found', get_bloginfo( 'name' ), $count );
			$body    = sprintf(
				"Metamanager found %d broken link(s) on %s.\n\nPlease review them in WP Admin › SEO › Broken Links.\n\n%s",
				$count,
				home_url(),
				admin_url( 'admin.php?page=mm-meta-links' )
			);
			wp_mail( $email, $subject, $body ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wpmail_wp_mail
			set_transient( $cache_key, 1, $interval );
		}
	}

	// -------------------------------------------------------------------------
	// Admin AJAX
	// -------------------------------------------------------------------------

	public function ajax_fetch_links(): void {
		check_ajax_referer( 'mm_meta_links_nonce', '_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		global $wpdb;
		$table      = self::table_name();
		$filter     = sanitize_text_field( $_POST['filter'] ?? 'broken' );
		$paged      = max( 1, (int) ( $_POST['paged'] ?? 1 ) );
		$per_page   = 50;
		$offset     = ( $paged - 1 ) * $per_page;

		$where = match ( $filter ) {
			'broken'   => 'WHERE is_broken = 1 AND is_ignored = 0',
			'ignored'  => 'WHERE is_ignored = 1',
			'ok'       => 'WHERE is_broken = 0 AND http_code > 0',
			default    => '',
		};

		// $where is a controlled static fragment from match(), not user input.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT l.*, p.post_title FROM {$table} l
			 LEFT JOIN {$wpdb->posts} p ON p.ID = l.post_id
			 {$where}
			 ORDER BY l.is_broken DESC, l.last_checked ASC
			 LIMIT %d OFFSET %d",
			$per_page,
			$offset
		), ARRAY_A );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}" ) );

		// Augment rows with the post edit URL (not stored in DB).
		if ( is_array( $rows ) ) {
			foreach ( $rows as &$row ) {
				$row['post_edit_url'] = $row['post_id'] ? get_edit_post_link( (int) $row['post_id'], 'raw' ) : '';
			}
			unset( $row );
		}

		wp_send_json_success( [
			'rows'       => $rows,
			'total'      => $total,
			'paged'      => $paged,
			'per_page'   => $per_page,
		] );
	}

	public function ajax_recheck_link(): void {
		check_ajax_referer( 'mm_meta_links_nonce', '_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		global $wpdb;
		$id    = (int) ( $_POST['link_id'] ?? 0 );
		$table = self::table_name();

		if ( ! $id ) {
			wp_send_json_error( 'Invalid ID' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT url FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			wp_send_json_error( 'Not found' );
		}

		$code   = $this->head_request( $row['url'], 10 );
		$broken = ( $code === 0 || ( $code >= 400 && $code !== 429 ) ) ? 1 : 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$table,
			[
				'http_code'    => $code,
				'is_broken'    => $broken,
				'last_checked' => current_time( 'mysql', true ),
			],
			[ 'id' => $id ],
			[ '%d', '%d', '%s' ],
			[ '%d' ]
		);

		wp_send_json_success( [ 'http_code' => $code, 'is_broken' => $broken ] );
	}

	public function ajax_ignore_link(): void {
		check_ajax_referer( 'mm_meta_links_nonce', '_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		global $wpdb;
		$id      = (int) ( $_POST['link_id'] ?? 0 );
		$ignored = (int) ( $_POST['ignored'] ?? 1 );
		$table   = self::table_name();

		if ( ! $id ) {
			wp_send_json_error( 'Invalid ID' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $table, [ 'is_ignored' => $ignored ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );

		wp_send_json_success();
	}

	public function ajax_scan_all_posts(): void {
		check_ajax_referer( 'mm_meta_links_nonce', '_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$offset = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
		wp_send_json_success( $this->backfill_posts( $offset, 20 ) );
	}

	// -------------------------------------------------------------------------
	// Backfill — scan existing posts not yet in the link table
	// -------------------------------------------------------------------------

	/**
	 * Scan a batch of published posts and extract links for any that have not
	 * yet been indexed.  Posts already present in the link table are skipped.
	 *
	 * Uses a stable ORDER BY ID ASC offset so progress is predictable across
	 * multiple AJAX or CLI calls.
	 *
	 * @return array{ total: int, scanned: int, skipped: int, new_offset: int, done: bool }
	 */
	public function backfill_posts( int $offset = 0, int $batch_size = 20 ): array {
		global $wpdb;
		$table           = self::table_name();
		$monitored_types = array_keys( $this->settings->get( 'sitemap.post_types', [ 'post' => true, 'page' => true ] ) );
		$types_ph        = implode( ',', array_fill( 0, count( $monitored_types ), '%s' ) );

		// Total qualifying posts — stable across the whole scan session.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$types_ph})",
				...$monitored_types
			)
		);

		if ( 0 === $total ) {
			return [ 'total' => 0, 'scanned' => 0, 'skipped' => 0, 'new_offset' => 0, 'done' => true ];
		}

		// Fetch next batch in stable ID order.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$post_ids = array_map( 'intval', (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$types_ph}) ORDER BY ID ASC LIMIT %d OFFSET %d",
				...[...$monitored_types, $batch_size, $offset]
			)
		) );

		if ( ! $post_ids ) {
			return [ 'total' => $total, 'scanned' => 0, 'skipped' => 0, 'new_offset' => $offset, 'done' => true ];
		}

		// Batch-check which post IDs already have entries — skip those.
		$id_ph = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$already = array_map( 'intval', (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$table} WHERE post_id IN ({$id_ph})",
				...$post_ids
			)
		) );

		foreach ( $post_ids as $post_id ) {
			if ( in_array( $post_id, $already, true ) ) {
				continue;
			}
			$post = get_post( $post_id );
			if ( $post ) {
				$this->extract_from_post( $post_id, $post );
			}
		}

		$new_offset = $offset + count( $post_ids );

		return [
			'total'      => $total,
			'scanned'    => count( $post_ids ),
			'skipped'    => count( $already ),
			'new_offset' => $new_offset,
			'done'       => $new_offset >= $total,
		];
	}
}
