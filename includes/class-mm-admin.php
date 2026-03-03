<?php
/**
 * Metamanager Admin Class
 *
 * All WordPress admin integration:
 * - System status banner
 * - Media Library compression column
 * - Embedded metadata pane on single image view
 * - Bulk actions (compress, inject site provenance info, re-queue failed)
 * - Admin submenu: live job queue + searchable/paginated history
 * - REST endpoint and JS enqueue for real-time column updates
 * - AJAX handler for live dashboard refresh
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MM_Admin
 */
class MM_Admin {

	/**
	 * Register all admin hooks.
	 * Called from metamanager.php only if is_admin() is true.
	 */
	public static function init(): void {
		// Menu.
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );

		// Status banner on Media Library and our own pages.
		add_action( 'admin_notices', [ __CLASS__, 'status_banner' ] );

		// Media Library column.
		add_filter( 'manage_upload_columns', [ __CLASS__, 'add_media_column' ] );
		add_action( 'manage_media_custom_column', [ __CLASS__, 'render_media_column' ], 10, 2 );

		// Per-image metadata pane (single image edit screen).
		add_action( 'edit_form_after_title', [ __CLASS__, 'render_attachment_meta_pane' ] );

		// Bulk actions.
		add_filter( 'bulk_actions-upload', [ __CLASS__, 'register_bulk_actions' ] );
		add_filter( 'handle_bulk_actions-upload', [ __CLASS__, 'handle_bulk_actions' ], 10, 3 );
		add_action( 'admin_notices', [ __CLASS__, 'bulk_action_notices' ] );

		// Scripts and styles.
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );

		// AJAX: live job dashboard.
		add_action( 'wp_ajax_mm_jobs_refresh', [ __CLASS__, 'ajax_jobs_refresh' ] );

		// REST: real-time compression status for Media Library column.
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );

		// AJAX: re-queue a failed job.
		add_action( 'wp_ajax_mm_requeue_job', [ __CLASS__, 'ajax_requeue_job' ] );

		// AJAX: clear job history.
		add_action( 'wp_ajax_mm_clear_history', [ __CLASS__, 'ajax_clear_history' ] );

		// Bulk spinner (lightweight UX touch on the Media Library).
		add_action( 'admin_footer-upload.php', [ __CLASS__, 'bulk_spinner_markup' ] );

		// Contextual help tabs (appear in the top-right "Help" tab on WP screens).
		add_action( 'current_screen', [ __CLASS__, 'add_help_tabs' ] );
	}

	// -----------------------------------------------------------------------
	// Contextual Help Tabs (WordPress Screen API)
	// Appear in the top-right "Help" dropdown on the Metamanager Jobs screen
	// and on the Media Library screen.
	// -----------------------------------------------------------------------

	public static function add_help_tabs( \WP_Screen $screen ): void {
		// Jobs dashboard help.
		if ( 'media_page_metamanager-jobs' === $screen->id ) {
			$screen->add_help_tab( [
				'id'      => 'mm_help_overview',
				'title'   => __( 'Overview', 'metamanager' ),
				'content' =>
					'<h2>' . esc_html__( 'Metamanager Job Dashboard', 'metamanager' ) . '</h2>' .
					'<p>' . esc_html__( 'This page shows everything Metamanager is doing or has done. The top section is the live queue — jobs waiting to be processed by the OS daemons. The bottom section is the history of completed and failed jobs pulled from the database.', 'metamanager' ) . '</p>' .
					'<p>' . esc_html__( 'Both sections refresh automatically every 5 seconds. You do not need to reload the page.', 'metamanager' ) . '</p>',
			] );

			$screen->add_help_tab( [
				'id'      => 'mm_help_queue',
				'title'   => __( 'Job Queue', 'metamanager' ),
				'content' =>
					'<h2>' . esc_html__( 'Job Queue', 'metamanager' ) . '</h2>' .
					'<p>' . esc_html__( 'Jobs enter the queue when you upload an image, save metadata fields on an image edit screen, or trigger a bulk action from the Media Library.', 'metamanager' ) . '</p>' .
					'<p>' . esc_html__( 'Each job is written as a small JSON file to one of two directories inside wp-content/metamanager-jobs/: compress/ for image compression jobs, and meta/ for metadata embedding jobs.', 'metamanager' ) . '</p>' .
					'<p>' . esc_html__( 'The OS daemons watch these directories with inotifywait and process jobs immediately — no polling delay. Jobs disappear from this view as soon as a daemon processes them.', 'metamanager' ) . '</p>',
			] );

			$screen->add_help_tab( [
				'id'      => 'mm_help_history',
				'title'   => __( 'Job History', 'metamanager' ),
				'content' =>
					'<h2>' . esc_html__( 'Job History', 'metamanager' ) . '</h2>' .
					'<p>' . esc_html__( 'After a daemon finishes a job it writes a result JSON to the completed/ or failed/ directory. WP-Cron reads these files every 60 seconds and records them in the database.', 'metamanager' ) . '</p>' .
					'<p>' . esc_html__( 'Completed jobs show the file size, image dimensions, and timestamps. Failed jobs show a Re-queue button — click it to re-submit the original job file without any manual steps.', 'metamanager' ) . '</p>' .
					'<p>' . esc_html__( 'Use the search box to filter by image name, job type, or status. Clear History removes all records from the database but does not affect any image files.', 'metamanager' ) . '</p>',
			] );

			$screen->add_help_tab( [
				'id'      => 'mm_help_daemons',
				'title'   => __( 'Daemons', 'metamanager' ),
				'content' =>
					'<h2>' . esc_html__( 'OS Daemons', 'metamanager' ) . '</h2>' .
					'<p>' . esc_html__( 'Two systemd services handle all image processing:', 'metamanager' ) . '</p>' .
					'<ul>' .
					'<li><strong>metamanager-compress-daemon</strong> — ' . esc_html__( 'lossless JPEG compression via jpegtran; lossless PNG compression via optipng. Files are only replaced if the result is smaller.', 'metamanager' ) . '</li>' .
					'<li><strong>metamanager-meta-daemon</strong> — ' . esc_html__( 'writes EXIF, IPTC, and XMP metadata simultaneously via ExifTool in a single pass.', 'metamanager' ) . '</li>' .
					'</ul>' .
					'<p>' . esc_html__( 'Daemon status is shown in the banner at the top of this page and the Media Library. Status is read from a PID file in /tmp/ — no systemctl privileges are needed.', 'metamanager' ) . '</p>' .
					'<p>' . esc_html__( 'To restart a daemon from the server:', 'metamanager' ) . '</p>' .
					'<code>sudo systemctl restart metamanager-compress-daemon</code><br>' .
					'<code>sudo systemctl restart metamanager-meta-daemon</code>',
			] );

			$screen->add_help_tab( [
				'id'      => 'mm_help_metadata',
				'title'   => __( 'Metadata Fields', 'metamanager' ),
				'content' =>
					'<h2>' . esc_html__( 'Metadata Fields', 'metamanager' ) . '</h2>' .
					'<p>' . esc_html__( 'Metamanager maps WordPress fields to EXIF, IPTC, and XMP tags and writes all three simultaneously:', 'metamanager' ) . '</p>' .
					'<table style="border-collapse:collapse;width:100%;font-size:13px;">' .
					'<tr style="border-bottom:1px solid #ddd;"><th style="text-align:left;padding:4px 8px;">Field</th><th style="text-align:left;padding:4px 8px;">Source</th><th style="text-align:left;padding:4px 8px;">Bulk?</th></tr>' .
					'<tr><td style="padding:4px 8px;">Title</td><td style="padding:4px 8px;">WP Post Title</td><td style="padding:4px 8px;">No</td></tr>' .
					'<tr><td style="padding:4px 8px;">Description</td><td style="padding:4px 8px;">WP Post Content</td><td style="padding:4px 8px;">No</td></tr>' .
					'<tr><td style="padding:4px 8px;">Caption</td><td style="padding:4px 8px;">WP Excerpt</td><td style="padding:4px 8px;">No</td></tr>' .
					'<tr><td style="padding:4px 8px;">Alt Text</td><td style="padding:4px 8px;">WP Alt Field</td><td style="padding:4px 8px;">No</td></tr>' .
					'<tr><td style="padding:4px 8px;"><strong>Creator</strong></td><td style="padding:4px 8px;">Per-image field</td><td style="padding:4px 8px;"><strong style="color:#e54c3c;">Never</strong></td></tr>' .
					'<tr><td style="padding:4px 8px;"><strong>Copyright</strong></td><td style="padding:4px 8px;">Per-image field</td><td style="padding:4px 8px;"><strong style="color:#e54c3c;">Never</strong></td></tr>' .
					'<tr><td style="padding:4px 8px;"><strong>Owner</strong></td><td style="padding:4px 8px;">Per-image field</td><td style="padding:4px 8px;"><strong style="color:#e54c3c;">Never</strong></td></tr>' .
					'<tr><td style="padding:4px 8px;">Publisher</td><td style="padding:4px 8px;">Site name (auto)</td><td style="padding:4px 8px;">Yes — Inject Site Info</td></tr>' .
					'<tr><td style="padding:4px 8px;">Website</td><td style="padding:4px 8px;">Site URL (auto)</td><td style="padding:4px 8px;">Yes — Inject Site Info</td></tr>' .				'<tr><td colspan="3" style="padding:6px 8px 2px;font-weight:700;border-top:1px solid #ddd;">Editorial</td></tr>' .
				'<tr><td style="padding:4px 8px;">Headline</td><td style="padding:4px 8px;">Per-image field</td><td style="padding:4px 8px;">No</td></tr>' .
				'<tr><td style="padding:4px 8px;">Credit</td><td style="padding:4px 8px;">Per-image field</td><td style="padding:4px 8px;">No</td></tr>' .
				'<tr><td colspan="3" style="padding:6px 8px 2px;font-weight:700;border-top:1px solid #ddd;">Classification</td></tr>' .
				'<tr><td style="padding:4px 8px;">Keywords</td><td style="padding:4px 8px;">Per-image field (semicolon-separated)</td><td style="padding:4px 8px;">No</td></tr>' .
				'<tr><td style="padding:4px 8px;">Date Created</td><td style="padding:4px 8px;">Per-image field (YYYY-MM-DD)</td><td style="padding:4px 8px;">No</td></tr>' .
				'<tr><td style="padding:4px 8px;">Rating</td><td style="padding:4px 8px;">Per-image field (0–5 stars)</td><td style="padding:4px 8px;">No</td></tr>' .
				'<tr><td colspan="3" style="padding:6px 8px 2px;font-weight:700;border-top:1px solid #ddd;">Location (IPTC Photo Metadata Standard)</td></tr>' .
				'<tr><td style="padding:4px 8px;">City</td><td style="padding:4px 8px;">Per-image field</td><td style="padding:4px 8px;">No</td></tr>' .
				'<tr><td style="padding:4px 8px;">State / Province</td><td style="padding:4px 8px;">Per-image field</td><td style="padding:4px 8px;">No</td></tr>' .
				'<tr><td style="padding:4px 8px;">Country</td><td style="padding:4px 8px;">Per-image field</td><td style="padding:4px 8px;">No</td></tr>' .					'</table>' .
					'<p style="margin-top:.75em;">' . esc_html__( 'Creator, Copyright, and Owner carry rights and attribution meaning. They are intentionally unavailable as bulk actions and must be set per image.', 'metamanager' ) . '</p>',
			] );

			$screen->add_help_tab( [
				'id'      => 'mm_help_updates',
				'title'   => __( 'Updates', 'metamanager' ),
				'content' =>
					'<h2>' . esc_html__( 'Keeping Metamanager Up to Date', 'metamanager' ) . '</h2>' .
					'<p>' . esc_html__( 'Metamanager integrates with the WordPress update system. New GitHub releases appear automatically in Dashboard → Updates within 12 hours.', 'metamanager' ) . '</p>' .
					'<p>' . esc_html__( 'To check immediately, go to Plugins → Installed Plugins and click the “Check for Updates” link next to Metamanager.', 'metamanager' ) . '</p>' .
					'<p>' . esc_html__( 'To update only the plugin files from the server without restarting daemons:', 'metamanager' ) . '</p>' .
					'<code>sudo bash install.sh --update</code>',
			] );

			$screen->set_help_sidebar(
				'<p><strong>' . esc_html__( 'Metamanager', 'metamanager' ) . ' ' . MM_VERSION . '</strong></p>' .
				'<p><a href="https://metamanager.richardkentgates.com" target="_blank" rel="noopener">' . esc_html__( 'Documentation Website', 'metamanager' ) . ' ↗</a></p>' .
				'<p><a href="https://github.com/richardkentgates/metamanager" target="_blank" rel="noopener">' . esc_html__( 'GitHub Repository', 'metamanager' ) . ' ↗</a></p>' .
				'<p><a href="https://github.com/richardkentgates/metamanager/issues" target="_blank" rel="noopener">' . esc_html__( 'Report an Issue', 'metamanager' ) . ' ↗</a></p>' .
				'<p><a href="https://github.com/richardkentgates/metamanager/blob/main/CHANGELOG.md" target="_blank" rel="noopener">' . esc_html__( 'Changelog', 'metamanager' ) . ' ↗</a></p>'
			);
		}

		// Media Library help sidebar addendum.
		if ( 'upload' === $screen->id ) {
			$screen->add_help_tab( [
				'id'      => 'mm_help_media_column',
				'title'   => __( 'Compression Column', 'metamanager' ),
				'content' =>
					'<h2>' . esc_html__( 'Metamanager Compression Column', 'metamanager' ) . '</h2>' .
					'<p>' . esc_html__( 'The Compression column shows the lossless compression status of each image. It polls the server every 10 seconds and updates without a page reload.', 'metamanager' ) . '</p>' .
					'<p>' . esc_html__( 'Status meanings:', 'metamanager' ) . '</p>' .
					'<ul>' .
					'<li><strong style="color:#13bb2c;">' . esc_html__( 'Compressed', 'metamanager' ) . '</strong> — ' . esc_html__( 'All image sizes have been losslessly optimised.', 'metamanager' ) . '</li>' .
					'<li><strong style="color:#e6b800;">' . esc_html__( 'Pending', 'metamanager' ) . '</strong> — ' . esc_html__( 'A compression job is queued and waiting for the daemon.', 'metamanager' ) . '</li>' .
					'<li><strong style="color:#e54c3c;">' . esc_html__( 'Failed', 'metamanager' ) . '</strong> — ' . esc_html__( 'The last compression attempt failed. Go to Media → Metamanager to re-queue.', 'metamanager' ) . '</li>' .
					'<li><strong style="color:#888;">' . esc_html__( 'Not compressed', 'metamanager' ) . '</strong> — ' . esc_html__( 'No compression job has run yet. Use Compress Lossless from the Bulk Actions menu.', 'metamanager' ) . '</li>' .
					'</ul>' .
					'<p><a href="' . esc_url( admin_url( 'upload.php?page=metamanager-jobs' ) ) . '">' . esc_html__( 'View Job Dashboard →', 'metamanager' ) . '</a></p>',
			] );
		}
	}

	// -----------------------------------------------------------------------
	// Menu
	// -----------------------------------------------------------------------

	public static function add_menu(): void {
		add_submenu_page(
			'upload.php',
			esc_html__( 'Metamanager Jobs', 'metamanager' ),
			esc_html__( 'Metamanager', 'metamanager' ),
			'upload_files',
			'metamanager-jobs',
			[ __CLASS__, 'render_jobs_page' ]
		);
	}

	// -----------------------------------------------------------------------
	// System status banner
	// -----------------------------------------------------------------------

	public static function status_banner(): void {
		global $pagenow;
		$is_mm_page   = isset( $_GET['page'] ) && 'metamanager-jobs' === sanitize_key( $_GET['page'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$is_media_lib = ( 'upload.php' === $pagenow );

		if ( ! $is_mm_page && ! $is_media_lib ) {
			return;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$status = MM_Status::system_status();

		$icon = fn( bool $ok, string $ok_title, string $fail_title ) =>
			$ok
				? '<span class="dashicons dashicons-yes-alt" style="color:#00a32a;vertical-align:middle;" title="' . esc_attr( $ok_title ) . '"></span>'
				: '<span class="dashicons dashicons-dismiss" style="color:#d63638;vertical-align:middle;" title="' . esc_attr( $fail_title ) . '"></span>';

		echo '<div class="notice notice-info mm-banner" style="display:flex;align-items:center;gap:4px;flex-wrap:wrap;padding:10px 16px;">';
		echo '<strong>' . esc_html__( 'Metamanager', 'metamanager' ) . ':</strong> &nbsp;';
		echo 'ExifTool '  . $icon( $status['exiftool'],        'ExifTool found',   'ExifTool missing — metadata embedding disabled' );
		echo ' &nbsp;jpegtran ' . $icon( $status['jpegtran'],  'jpegtran found',   'jpegtran missing — JPEG lossless compression disabled' );
		echo ' &nbsp;optipng '  . $icon( $status['optipng'],   'optipng found',    'optipng missing — PNG lossless compression disabled' );
		echo ' &nbsp;Compress daemon ' . $icon( $status['compress_daemon'], 'Compression daemon running', 'Compression daemon not running' );
		echo ' &nbsp;Metadata daemon ' . $icon( $status['meta_daemon'],     'Metadata daemon running',    'Metadata daemon not running' );
		echo '</div>';
	}

	// -----------------------------------------------------------------------
	// Media Library compression column
	// -----------------------------------------------------------------------

	/**
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function add_media_column( array $columns ): array {
		$columns['mm_compression'] = esc_html__( 'Compression', 'metamanager' );
		return $columns;
	}

	/**
	 * @param string $column_name Column slug.
	 * @param int    $attachment_id Attachment ID.
	 */
	public static function render_media_column( string $column_name, int $attachment_id ): void {
		if ( 'mm_compression' !== $column_name ) {
			return;
		}
		$status = MM_Status::compression_status( $attachment_id );
		echo '<span class="mm-compress-status" '
			. 'id="mm-status-' . esc_attr( (string) $attachment_id ) . '" '
			. 'data-id="' . esc_attr( (string) $attachment_id ) . '" '
			. 'style="color:' . esc_attr( $status['color'] ) . ';font-weight:bold;">'
			. esc_html( $status['label'] )
			. '</span>';
	}

	// -----------------------------------------------------------------------
	// Embedded metadata pane — single image edit screen
	// -----------------------------------------------------------------------

	/**
	 * Render a read-only table of all embedded metadata for the current image,
	 * pulled live from ExifTool. Shown just below the title on the edit screen.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public static function render_attachment_meta_pane( \WP_Post $post ): void {
		if ( 'attachment' !== $post->post_type || ! wp_attachment_is_image( $post->ID ) ) {
			return;
		}

		$file = get_attached_file( $post->ID );
		if ( ! $file || ! file_exists( $file ) ) {
			return;
		}

		$metadata = MM_Metadata::read_embedded( $file );

		echo '<div class="postbox mm-meta-pane"><div class="postbox-header">'
			. '<h2 class="hndle">' . esc_html__( 'Embedded File Metadata', 'metamanager' ) . '</h2>'
			. '</div><div class="inside">';

		if ( ! MM_Status::exiftool_available() ) {
			echo '<p style="color:#d63638;">' . esc_html__( 'ExifTool is not installed. Metadata display unavailable.', 'metamanager' ) . '</p>';
		} elseif ( empty( $metadata ) ) {
			echo '<p style="color:#50575e;">' . esc_html__( 'No embedded metadata found in this file.', 'metamanager' ) . '</p>';
		} else {
			echo '<div style="max-height:420px;overflow:auto;">';
			echo '<table class="widefat striped" style="font-size:13px;">';
			echo '<thead><tr>'
				. '<th style="width:35%;">' . esc_html__( 'Tag', 'metamanager' ) . '</th>'
				. '<th>' . esc_html__( 'Value', 'metamanager' ) . '</th>'
				. '</tr></thead><tbody>';
			foreach ( $metadata as $tag => $value ) {
				if ( is_array( $value ) ) {
					$value = wp_json_encode( $value );
				}
				echo '<tr>'
					. '<td><code>' . esc_html( (string) $tag ) . '</code></td>'
					. '<td style="white-space:pre-wrap;">' . esc_html( (string) $value ) . '</td>'
					. '</tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</div></div>'; // .inside .postbox
	}

	// -----------------------------------------------------------------------
	// Bulk actions
	//
	// IMPORTANT: Only two bulk actions touch metadata:
	//   1. "Inject Site Info" — sets Publisher (site name) + Website (site URL) only.
	//      This is neutral provenance, not an ownership or copyright claim.
	//   2. "Compress (Lossless)" — queues compression jobs.
	//
	// We intentionally do NOT provide bulk set-copyright or bulk set-creator
	// because doing so would falsely claim authorship or rights over images that
	// may belong to clients, photographers, or third parties.
	// -----------------------------------------------------------------------

	/**
	 * @param array $actions Existing bulk actions.
	 * @return array
	 */
	public static function register_bulk_actions( array $actions ): array {
		$actions['mm_bulk_compress']   = esc_html__( 'Compress Lossless (Metamanager)', 'metamanager' );
		$actions['mm_bulk_site_info']  = esc_html__( 'Inject Site Info into Metadata (Metamanager)', 'metamanager' );
		return $actions;
	}

	/**
	 * @param string $redirect_to Redirect URL.
	 * @param string $doaction    Bulk action slug.
	 * @param int[]  $post_ids    Selected attachment IDs.
	 * @return string
	 */
	public static function handle_bulk_actions( string $redirect_to, string $doaction, array $post_ids ): string {
		if ( ! current_user_can( 'upload_files' ) ) {
			return $redirect_to;
		}

		switch ( $doaction ) {
			case 'mm_bulk_compress':
				$count = self::do_bulk_compress( $post_ids );
				return add_query_arg( 'mm_bulk_compress', $count, $redirect_to );

			case 'mm_bulk_site_info':
				$count = self::do_bulk_inject_site_info( $post_ids );
				return add_query_arg( 'mm_bulk_site_info', $count, $redirect_to );
		}

		return $redirect_to;
	}

	/**
	 * Display notices after bulk actions redirect back.
	 */
	public static function bulk_action_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! empty( $_REQUEST['mm_bulk_compress'] ) ) {
			$n = absint( $_REQUEST['mm_bulk_compress'] );
			echo '<div class="notice notice-success is-dismissible"><p>'
				. sprintf(
					/* translators: %d: number of images */
					esc_html__( 'Metamanager: Compression queued for %d image(s).', 'metamanager' ),
					$n
				)
				. '</p></div>';
		}
		if ( ! empty( $_REQUEST['mm_bulk_site_info'] ) ) {
			$n = absint( $_REQUEST['mm_bulk_site_info'] );
			echo '<div class="notice notice-success is-dismissible"><p>'
				. sprintf(
					/* translators: %d: number of images */
					esc_html__( 'Metamanager: Site provenance info (Publisher + Website) injected for %d image(s).', 'metamanager' ),
					$n
				)
				. '</p></div>';
		}
		// phpcs:enable
	}

	// -----------------------------------------------------------------------
	// Bulk helpers
	// -----------------------------------------------------------------------

	/**
	 * Enqueue lossless compression jobs for all selected images.
	 * Only enqueues if the size has not already been compressed.
	 *
	 * @param int[] $post_ids Attachment IDs.
	 * @return int Number of attachments with at least one job enqueued.
	 */
	private static function do_bulk_compress( array $post_ids ): int {
		$count = 0;
		foreach ( $post_ids as $id ) {
			$id = (int) $id;
			if ( ! wp_attachment_is_image( $id ) ) {
				continue;
			}
			$meta    = wp_get_attachment_metadata( $id ) ?: [];
			$file    = get_attached_file( $id );
			$queued  = false;

			if ( $file && file_exists( $file ) && ! MM_Status::is_compressed( $id, 'full' ) ) {
				MM_Job_Queue::write_job( 'compression', $id, $file, 'full', [ 'trigger' => 'bulk' ] );
				$queued = true;
			}

			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				$dir = trailingslashit( pathinfo( $file, PATHINFO_DIRNAME ) );
				foreach ( $meta['sizes'] as $size => $info ) {
					if ( empty( $info['file'] ) ) {
						continue;
					}
					$img_path = $dir . $info['file'];
					if ( file_exists( $img_path ) && ! MM_Status::is_compressed( $id, $size ) ) {
						MM_Job_Queue::write_job( 'compression', $id, $img_path, $size, [ 'trigger' => 'bulk' ] );
						$queued = true;
					}
				}
			}

			if ( $queued ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Inject site provenance into image metadata for selected images.
	 *
	 * Sets Publisher = site name and Website = site URL.
	 * Does NOT touch Creator, Copyright, or Owner.
	 *
	 * @param int[] $post_ids Attachment IDs.
	 * @return int Number of attachments processed.
	 */
	private static function do_bulk_inject_site_info( array $post_ids ): int {
		$count = 0;
		foreach ( $post_ids as $id ) {
			$id = (int) $id;
			if ( ! wp_attachment_is_image( $id ) ) {
				continue;
			}
			// No post meta to update — Publisher/Website always come from get_bloginfo()
			// and home_url() in MM_Metadata::get_fields_for_job(). We just queue the jobs.
			MM_Job_Queue::enqueue_all_sizes( $id, [], 'metadata', [ 'trigger' => 'bulk_site_info' ] );
			++$count;
		}
		return $count;
	}

	// -----------------------------------------------------------------------
	// Scripts & styles
	// -----------------------------------------------------------------------

	/**
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( 'upload.php' !== $hook ) {
			return;
		}
		wp_enqueue_script(
			'mm-status',
			MM_PLUGIN_URL . 'assets/js/mm-status.js',
			[ 'jquery' ],
			MM_VERSION,
			true
		);
		wp_localize_script(
			'mm-status',
			'MMStatus',
			[
				'restUrl' => rest_url( 'metamanager/v1/compression-status' ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			]
		);
	}

	// -----------------------------------------------------------------------
	// REST endpoint: real-time compression status for Media Library column
	// -----------------------------------------------------------------------

	public static function register_rest_routes(): void {
		register_rest_route(
			'metamanager/v1',
			'/compression-status',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'rest_compression_status' ],
				'permission_callback' => function () {
					return current_user_can( 'upload_files' );
				},
				'args' => [
					'ids' => [
						'required'          => true,
						'validate_callback' => fn( $v ) => is_array( $v ),
						'sanitize_callback' => fn( $v ) => array_map( 'absint', (array) $v ),
					],
				],
			]
		);
	}

	/**
	 * REST callback: return compression status for each requested attachment ID.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_compression_status( \WP_REST_Request $request ) {
		$ids    = (array) $request->get_param( 'ids' );
		$result = [];
		foreach ( $ids as $id ) {
			$result[ $id ] = MM_Status::compression_status( (int) $id );
		}
		return rest_ensure_response( $result );
	}

	// -----------------------------------------------------------------------
	// Admin page — job dashboard
	// -----------------------------------------------------------------------

	public static function render_jobs_page(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'metamanager' ) );
		}

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Metamanager — Job Dashboard', 'metamanager' ); ?></h1>
			<hr class="wp-header-end">

			<div class="notice notice-info inline mm-help-box" style="padding:12px 16px;margin:1em 0 1.5em;">
				<details>
					<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( 'About this page (click to expand)', 'metamanager' ); ?></summary>
					<div style="margin-top:.8em;line-height:1.7;">
						<p><?php esc_html_e( 'This dashboard shows all Metamanager activity in real time. It refreshes every 5 seconds automatically — no page reload needed.', 'metamanager' ); ?></p>
						<h4><?php esc_html_e( 'Job Queue', 'metamanager' ); ?></h4>
						<p><?php esc_html_e( 'Jobs appear here when an image is uploaded, metadata fields are saved, or a bulk action is triggered. Each job is a small JSON file the OS daemon picks up via inotifywait. Jobs vanish as soon as the daemon processes them.', 'metamanager' ); ?></p>
						<h4><?php esc_html_e( 'Job History', 'metamanager' ); ?></h4>
						<p><?php esc_html_e( 'Once a daemon finishes a job it writes a result file to completed/ or failed/. WP-Cron reads those files every 60 seconds and records them here. Click the image name to open the edit screen. Use Re-queue on any failed job to resubmit it without manual steps.', 'metamanager' ); ?></p>
						<h4><?php esc_html_e( 'Bulk Actions (Media Library)', 'metamanager' ); ?></h4>
						<ul style="margin:.3em 0 0 1.5em;">
							<li><strong><?php esc_html_e( 'Compress Lossless', 'metamanager' ); ?></strong> — <?php esc_html_e( 'queues lossless compression for all uncompressed sizes of selected images. JPEG via jpegtran, PNG via optipng. Files are only replaced if the result is smaller.', 'metamanager' ); ?></li>
							<li><strong><?php esc_html_e( 'Inject Site Info into Metadata', 'metamanager' ); ?></strong> — <?php esc_html_e( 'writes Publisher (your site name) and Website (your site URL) into IPTC and XMP. This is neutral provenance — it never sets Creator, Copyright, or Owner.', 'metamanager' ); ?></li>
						</ul>
						<h4><?php esc_html_e( 'Status Banner', 'metamanager' ); ?></h4>
						<p><?php esc_html_e( 'The banner at the top shows tool availability and daemon health. A green icon means the tool is installed and reachable. A red icon means it is missing or the daemon is not running. Daemon status is read from a PID file — no elevated privileges are needed.', 'metamanager' ); ?></p>
						<p><?php esc_html_e( 'Full documentation is available in the Help tab (top right) and at', 'metamanager' ); ?> <a href="https://metamanager.richardkentgates.com" target="_blank" rel="noopener">metamanager.richardkentgates.com</a>.</p>
					</div>
				</details>
			</div>

			<div id="mm-jobs-dashboard">
				<?php self::render_jobs_content(); ?>
			</div>
		</div>

		<style>
		/* Metamanager admin — leverages WP native classes wherever possible */
		.mm-section               { margin-bottom: 1.5em; }
		.mm-section .hndle        { cursor: default; padding: 8px 12px; }
		.mm-section .hndle span   { font-size: .8em; font-weight: 400; color: #50575e; margin-left: .5em; }
		.mm-section .inside       { padding: 0 12px 12px; }
		.mm-section table         { width: 100%; font-size: 13px; border-collapse: collapse; }
		.mm-section th            { background: #f6f7f7; color: #1d2327; padding: 9px 8px; text-align: left; border-bottom: 1px solid #c3c4c7; }
		.mm-section td            { padding: 8px; border-top: 1px solid #f0f0f1; }
		.mm-section tr:hover td   { background: #f6f7f7; }
		.mm-section h4            { color: #1d2327; margin: 1em 0 .3em; }
		.mm-tag-completed         { color: #00a32a; font-weight: 600; }
		.mm-tag-failed            { color: #d63638; font-weight: 600; }
		.mm-tag-pending           { color: #dba617; font-weight: 600; }
		.mm-meta-pane             { margin: 1.5em 0 2em; max-width: 900px; }
		.mm-banner .dashicons     { font-size: 18px; width: 18px; height: 18px; }
		</style>

		<script>
		jQuery(function($){
			function refreshDashboard() {
				$.post(ajaxurl, {
					action: 'mm_jobs_refresh',
					nonce: '<?php echo esc_js( wp_create_nonce( 'mm_jobs_refresh' ) ); ?>',
					s:     $('input[name="s"]').val() || '',
					paged: $('input.mm-paged').val() || 1
				}, function(html){
					if (html) $('#mm-jobs-dashboard').html(html);
				});
			}
			var refreshTimer = setInterval(refreshDashboard, 5000);

			$(document).on('submit', '.mm-search-form', function(e){
				e.preventDefault();
				clearInterval(refreshTimer);
				refreshDashboard();
				refreshTimer = setInterval(refreshDashboard, 5000);
			});

			$(document).on('click', '.mm-page-link', function(e){
				e.preventDefault();
				$('input.mm-paged').val($(this).data('paged'));
				refreshDashboard();
			});

			$(document).on('click', '.mm-requeue-btn', function(e){
				e.preventDefault();
				var btn = $(this);
				$.post(ajaxurl, {
					action: 'mm_requeue_job',
					nonce:  '<?php echo esc_js( wp_create_nonce( 'mm_requeue_job' ) ); ?>',
					job_id: btn.data('job-id')
				}, function(resp){
					if (resp.success) {
						btn.replaceWith('<span style="color:#13bb2c;">&#10004; Re-queued</span>');
					} else {
						btn.replaceWith('<span style="color:#e54c3c;">Failed: ' + resp.data + '</span>');
					}
				}, 'json');
			});

			$(document).on('click', '#mm-clear-history-btn', function(e){
				e.preventDefault();
				if (!confirm('<?php echo esc_js( __( 'Clear entire job history? This cannot be undone.', 'metamanager' ) ); ?>')) return;
				$.post(ajaxurl, {
					action: 'mm_clear_history',
					nonce:  '<?php echo esc_js( wp_create_nonce( 'mm_clear_history' ) ); ?>'
				}, function(){ refreshDashboard(); }, 'json');
			});
		});
		</script>
		<?php
	}

	// -----------------------------------------------------------------------
	// AJAX handlers
	// -----------------------------------------------------------------------

	public static function ajax_jobs_refresh(): void {
		check_ajax_referer( 'mm_jobs_refresh', 'nonce' );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( '-1' );
		}
		self::render_jobs_content();
		wp_die();
	}

	public static function ajax_requeue_job(): void {
		check_ajax_referer( 'mm_requeue_job', 'nonce' );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}
		$job_id = absint( $_POST['job_id'] ?? 0 );
		if ( MM_Job_Queue::requeue( $job_id ) ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( 'Job not found or file missing.' );
		}
	}

	public static function ajax_clear_history(): void {
		check_ajax_referer( 'mm_clear_history', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}
		MM_DB::clear_history();
		wp_send_json_success();
	}

	// -----------------------------------------------------------------------
	// Dashboard content (shared by page render + AJAX refresh)
	// -----------------------------------------------------------------------

	public static function render_jobs_content(): void {
		echo '<input type="hidden" class="mm-paged" value="' . esc_attr( (string) max( 1, (int) ( $_REQUEST['paged'] ?? 1 ) ) ) . '">'; // phpcs:ignore WordPress.Security.NonceVerification
		self::render_queue_section();
		self::render_history_section();
	}

	/**
	 * Render the live queue section (reads from filesystem).
	 */
	private static function render_queue_section(): void {
		$all_jobs = MM_Job_Queue::get_pending_jobs();
		$total    = count( $all_jobs['compression'] ) + count( $all_jobs['metadata'] );

		echo '<div class="postbox mm-section">';
		echo '<div class="postbox-header"><h2 class="hndle">'
			. esc_html__( 'Job Queue', 'metamanager' )
			. ' <span>' . esc_html__( '(live)', 'metamanager' ) . '</span>'
			. ' <span>' . sprintf(
				/* translators: %d: job count */
				esc_html__( '%d waiting', 'metamanager' ),
				$total
			) . '</span>'
			. '</h2></div><div class="inside">';

		foreach ( [ 'compression' => __( 'Compression Jobs', 'metamanager' ), 'metadata' => __( 'Metadata Jobs', 'metamanager' ) ] as $type => $label ) {
			$jobs = $all_jobs[ $type ];
			echo '<h4>' . esc_html( $label ) . ': <strong>' . count( $jobs ) . '</strong></h4>';
			if ( empty( $jobs ) ) {
				echo '<p style="color:#50575e;font-size:12px;">' . esc_html__( 'Queue is empty.', 'metamanager' ) . '</p>';
				continue;
			}
			echo '<table><thead><tr>'
				. '<th>' . esc_html__( 'File', 'metamanager' ) . '</th>'
				. '<th>' . esc_html__( 'Size', 'metamanager' ) . '</th>'
				. '<th>' . esc_html__( 'Dimensions', 'metamanager' ) . '</th>'
				. '<th>' . esc_html__( 'Trigger', 'metamanager' ) . '</th>'
				. '<th>' . esc_html__( 'Queued', 'metamanager' ) . '</th>'
				. '</tr></thead><tbody>';
			foreach ( $jobs as $job ) {
				$age = human_time_diff( (int) ( $job['_queued_at'] ?? 0 ), time() );
				echo '<tr>'
					. '<td><code>' . esc_html( basename( $job['file_path'] ?? '' ) ) . '</code></td>'
					. '<td>' . esc_html( $job['size'] ?? '' ) . '</td>'
					. '<td>' . esc_html( $job['dimensions'] ?? '' ) . '</td>'
					. '<td>' . esc_html( $job['trigger'] ?? '' ) . '</td>'
				. '<td><span style="color:#50575e;font-size:11px;">' . esc_html( $age ) . ' ago</span></td>'
				. '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div></div>'; // .inside .postbox
	}

	/**
	 * Render the history section (reads from database, paginated).
	 */
	private static function render_history_section(): void {
		// phpcs:disable WordPress.Security.NonceVerification
		$search   = sanitize_text_field( $_REQUEST['s'] ?? '' );
		$paged    = max( 1, (int) ( $_REQUEST['paged'] ?? 1 ) );
		// phpcs:enable

		$result     = MM_DB::get_jobs( [ 'search' => $search, 'paged' => $paged ] );
		$jobs       = $result['jobs'];
		$total      = $result['total'];
		$per_page   = 20;
		$total_pages = (int) ceil( $total / $per_page );

		echo '<div class="postbox mm-section">';
		echo '<div class="postbox-header">'
			. '<h2 class="hndle">' . esc_html__( 'Job History', 'metamanager' )
			. ' <span>' . esc_html__( '(live)', 'metamanager' ) . '</span></h2>'
			. '<div class="handle-actions"><button id="mm-clear-history-btn" class="button button-secondary button-small">'
			. esc_html__( 'Clear History', 'metamanager' ) . '</button></div>'
			. '</div><div class="inside">';

		// Search form.
		echo '<form class="mm-search-form" style="margin-bottom:1em;display:flex;gap:8px;">'
			. wp_nonce_field( 'mm_jobs_refresh', '_wpnonce', true, false )
			. '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search jobs…', 'metamanager' ) . '" class="regular-text">'
			. '<button class="button">' . esc_html__( 'Search', 'metamanager' ) . '</button>'
			. '</form>';

		if ( empty( $jobs ) ) {
			echo '<p style="color:#50575e;">' . esc_html__( 'No jobs recorded yet.', 'metamanager' ) . '</p>';
		} else {
			echo '<table><thead><tr>'
				. '<th>#</th>'
				. '<th>' . esc_html__( 'Image', 'metamanager' ) . '</th>'
				. '<th>' . esc_html__( 'Type', 'metamanager' ) . '</th>'
				. '<th>' . esc_html__( 'Size', 'metamanager' ) . '</th>'
				. '<th>' . esc_html__( 'Dimensions', 'metamanager' ) . '</th>'
				. '<th>' . esc_html__( 'Status', 'metamanager' ) . '</th>'
				. '<th>' . esc_html__( 'Submitted', 'metamanager' ) . '</th>'
				. '<th>' . esc_html__( 'Completed', 'metamanager' ) . '</th>'
				. '<th>' . esc_html__( 'Actions', 'metamanager' ) . '</th>'
				. '</tr></thead><tbody>';

			foreach ( $jobs as $job ) {
				$status_class = match ( $job->status ) {
					'completed' => 'mm-tag-completed',
					'failed'    => 'mm-tag-failed',
					default     => 'mm-tag-pending',
				};
				$att_link = '';
				if ( ! empty( $job->attachment_id ) ) {
					$edit_url = get_edit_post_link( (int) $job->attachment_id );
					$att_link = $edit_url
						? '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $job->image_name ) . '</a>'
						: esc_html( $job->image_name );
				}
				$requeue_btn = ( 'failed' === $job->status )
					? '<button class="button button-small mm-requeue-btn" data-job-id="' . esc_attr( (string) $job->id ) . '">'
						. esc_html__( 'Re-queue', 'metamanager' ) . '</button>'
					: '';

				echo '<tr>'
					. '<td>' . esc_html( (string) $job->id ) . '</td>'
					. '<td>' . $att_link . '</td>' // Escaped above.
					. '<td>' . esc_html( ucfirst( $job->job_type ) ) . '</td>'
					. '<td>' . esc_html( $job->size ) . '</td>'
					. '<td>' . esc_html( $job->dimensions ) . '</td>'
					. '<td><span class="' . esc_attr( $status_class ) . '">' . esc_html( ucfirst( $job->status ) ) . '</span></td>'
					. '<td>' . esc_html( $job->submitted_at ) . '</td>'
					. '<td>' . esc_html( $job->completed_at ?? '' ) . '</td>'
					. '<td>' . $requeue_btn . '</td>' // Contains a nonce'd button, escaped above.
					. '</tr>';
			}
			echo '</tbody></table>';

			// Pagination.
			if ( $total_pages > 1 ) {
				echo '<div class="tablenav bottom"><div class="tablenav-pages"><span class="displaying-num">' . sprintf(
					/* translators: %d: total count */
					esc_html__( '%d items', 'metamanager' ), $total
				) . '</span><span class="pagination-links">';
				for ( $i = 1; $i <= $total_pages; $i++ ) {
					if ( $i === $paged ) {
						echo '<span class="tablenav-pages-navspan button disabled" aria-current="page">'
							. esc_html( (string) $i ) . '</span>';
					} else {
						echo '<a class="mm-page-link button" data-paged="' . esc_attr( (string) $i ) . '" href="#">'
							. esc_html( (string) $i ) . '</a>';
					}
				}
				echo '</span></div></div>';
			}
		}

		echo '</div></div>'; // .inside .postbox
	}

	// -----------------------------------------------------------------------
	// Bulk spinner markup
	// -----------------------------------------------------------------------

	public static function bulk_spinner_markup(): void {
		echo '<style>.mm-spinner{display:none;position:fixed;top:50%;left:50%;z-index:9999;transform:translate(-50%,-50%);background:#fff;padding:20px 28px;border-radius:7px;box-shadow:0 2px 14px #0003;font-size:16px;font-weight:600;}.mm-spinner.active{display:block;}</style>';
		echo '<div class="mm-spinner" id="mmBulkSpinner">' . esc_html__( 'Processing…', 'metamanager' ) . '</div>';
		echo '<script>jQuery(function($){
			$(document).on("submit","form#bulk-action-form",function(){
				var action = $("#bulk-action-selector-top").val();
				if(action && action.indexOf("mm_") === 0) $("#mmBulkSpinner").addClass("active");
			});
			$(document).ajaxStop(function(){ $("#mmBulkSpinner").removeClass("active"); });
		});</script>';
	}
}
