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

		// Note: rest_api_init for register_rest_routes is registered unconditionally
		// in metamanager.php so REST requests (which are not is_admin() context)
		// also have the routes available.

		// AJAX: re-queue a failed job.
		add_action( 'wp_ajax_mm_requeue_job', [ __CLASS__, 'ajax_requeue_job' ] );

		// AJAX: scan existing library (import metadata for all un-synced images).
		add_action( 'wp_ajax_mm_scan_library', [ __CLASS__, 'ajax_scan_library' ] );

		// AJAX: re-compress a single attachment from its edit screen.
		add_action( 'wp_ajax_mm_recompress', [ __CLASS__, 'ajax_recompress' ] );

		// AJAX: save a single row on the bulk metadata edit page.
		add_action( 'wp_ajax_mm_save_bulk_meta_row', [ __CLASS__, 'ajax_save_bulk_meta_row' ] );

		// Bulk spinner (lightweight UX touch on the Media Library).
		add_action( 'admin_footer-upload.php', [ __CLASS__, 'bulk_spinner_markup' ] );

		// Contextual help tabs (appear in the top-right "Help" tab on WP screens).
		add_action( 'current_screen', [ __CLASS__, 'add_help_tabs' ] );

		// Job queue status notices (duplicate compression suppressed, metadata queued in sequence).
		add_action( 'admin_notices', [ __CLASS__, 'queue_notices' ] );
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
					'<p>' . esc_html__( 'Both sections refresh automatically every 5 seconds. You do not need to reload the page.', 'metamanager' ) . '</p>' .
					'<p>' . esc_html__( 'Access to this dashboard and all write operations (bulk actions, re-queue, library scan) requires the Editor role or higher (edit_others_posts capability). Per-attachment actions such as recompressing or saving metadata on a single file respect normal WordPress ownership — Authors can act on their own uploads.', 'metamanager' ) . '</p>',
			] );

			$screen->add_help_tab( [
				'id'      => 'mm_help_queue',
				'title'   => __( 'Job Queue', 'metamanager' ),
				'content' =>
					'<h2>' . esc_html__( 'Job Queue', 'metamanager' ) . '</h2>' .
					'<p>' . esc_html__( 'Jobs enter the queue when you upload a media file, save metadata fields on an attachment edit screen, or trigger a bulk action from the Media Library.', 'metamanager' ) . '</p>' .
					'<p>' . esc_html__( 'Each job is written as a small JSON file to one of two directories inside wp-content/metamanager-jobs/: compress/ for image compression jobs, and meta/ for metadata embedding jobs.', 'metamanager' ) . '</p>' .
					'<p>' . esc_html__( 'The OS daemons watch these directories with inotifywait and process jobs immediately — no polling delay. Jobs disappear from this view as soon as a daemon processes them.', 'metamanager' ) . '</p>',
			] );

			$screen->add_help_tab( [
				'id'      => 'mm_help_history',
				'title'   => __( 'Job History', 'metamanager' ),
				'content' =>
					'<h2>' . esc_html__( 'Job History', 'metamanager' ) . '</h2>' .
					'<p>' . esc_html__( 'After a daemon finishes a job it writes a result JSON to the completed/ or failed/ directory. WP-Cron reads these files every 60 seconds and records them in the database.', 'metamanager' ) . '</p>' .
					'<p>' . esc_html__( 'Completed jobs show the file size, dimensions (where applicable), and timestamps. Failed jobs show a Re-queue button — click it to re-submit the original job file without any manual steps.', 'metamanager' ) . '</p>' .
					'<p>' . esc_html__( 'Use the search box to filter by file name, job type, or status. Clear History removes all records from the database but does not affect any media files.', 'metamanager' ) . '</p>',
			] );

			$screen->add_help_tab( [
				'id'      => 'mm_help_daemons',
				'title'   => __( 'Daemons', 'metamanager' ),
				'content' =>
					'<h2>' . esc_html__( 'OS Daemons', 'metamanager' ) . '</h2>' .
					'<p>' . esc_html__( 'Two systemd services handle all media file processing:', 'metamanager' ) . '</p>' .
					'<ul>' .
					'<li><strong>metamanager-compress-daemon</strong> — ' . esc_html__( 'lossless JPEG compression via jpegtran; lossless PNG compression via optipng; lossless WebP recompression via cwebp; video container remux via ffmpeg. Files are only replaced if the result is smaller.', 'metamanager' ) . '</li>' .
					'<li><strong>metamanager-meta-daemon</strong> — ' . esc_html__( 'writes metadata via ExifTool in a single pass, using each file\'s native tag system: EXIF/IPTC/XMP for images; ID3 for MP3; QuickTime atoms for MP4/MOV/M4A; Vorbis comments for OGG/FLAC; XMP-only for AVI/WAV/WMV/WMA/PDF; read-only for MKV/WebM/OGV.', 'metamanager' ) . '</li>' .
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
					'<p>' . esc_html__( 'Metamanager maps WordPress fields to each file\'s native tag system. Images write EXIF, IPTC, and XMP simultaneously; MP3 uses ID3; MP4/MOV/M4A use QuickTime atoms; OGG/FLAC use Vorbis comments; AVI/WAV/WMV/WMA and PDF use XMP-only. All field names below apply across all supported types.', 'metamanager' ) . '</p>' .
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
					'<p style="margin-top:.75em;">' . esc_html__( 'Creator, Copyright, and Owner carry rights and attribution meaning. They are intentionally unavailable as bulk actions and must be set per file.', 'metamanager' ) . '</p>',
			] );

			$screen->add_help_tab( [
				'id'      => 'mm_help_updates',
				'title'   => __( 'Updates', 'metamanager' ),
				'content' =>
					'<h2>' . esc_html__( 'Keeping Metamanager Up to Date', 'metamanager' ) . '</h2>' .
					'<p>' . esc_html__( 'Metamanager integrates with the WordPress update system. New GitHub releases appear automatically in Dashboard → Updates within 12 hours.', 'metamanager' ) . '</p>' .
					'<p>' . esc_html__( 'To check immediately, go to Plugins → Installed Plugins and click the “Check for Updates” link next to Metamanager.', 'metamanager' ) . '</p>' .
					'<p>' . esc_html__( 'To update only the plugin files from the server without restarting daemons:', 'metamanager' ) . '</p>' .
					'<code>sudo bash metamanager-install.sh --update</code>',
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
					'<p>' . esc_html__( 'The Compression column shows the lossless compression status of each eligible media file. It polls the server every 10 seconds and updates without a page reload.', 'metamanager' ) . '</p>' .
					'<p>' . esc_html__( 'Status meanings:', 'metamanager' ) . '</p>' .
					'<ul>' .
					'<li><strong style="color:#13bb2c;">' . esc_html__( 'Compressed', 'metamanager' ) . '</strong> — ' . esc_html__( 'The file has been losslessly optimised.', 'metamanager' ) . '</li>' .
					'<li><strong style="color:#e6b800;">' . esc_html__( 'Pending', 'metamanager' ) . '</strong> — ' . esc_html__( 'A compression job is queued and waiting for the daemon.', 'metamanager' ) . '</li>' .
					'<li><strong style="color:#e54c3c;">' . esc_html__( 'Failed', 'metamanager' ) . '</strong> — ' . esc_html__( 'The last compression attempt failed. Go to Media → Metamanager to re-queue.', 'metamanager' ) . '</li>' .
					'<li><strong style="color:#888;">' . esc_html__( 'Not compressed', 'metamanager' ) . '</strong> — ' . esc_html__( 'No compression job has run yet. Use Compress Lossless from the Bulk Actions menu.', 'metamanager' ) . '</li>' .
					'</ul>' .
					'<p><a href="' . esc_url( admin_url( 'upload.php?page=metamanager-jobs' ) ) . '">' . esc_html__( 'View Job Dashboard →', 'metamanager' ) . '</a></p>',
			] );
		}

		// Settings page help.
		if ( 'media_page_metamanager-settings' === $screen->id ) {
			$screen->add_help_tab( [
				'id'      => 'mm_help_settings_api',
				'title'   => __( 'REST API Access', 'metamanager' ),
				'content' =>
					'<h2>' . esc_html__( 'REST API Access Control', 'metamanager' ) . '</h2>' .
					'<p>' . esc_html__( 'These settings control external access to the Metamanager REST endpoints. Logged-in WordPress users are never affected — the Media Library status column and job dashboard always work regardless of what is configured here.', 'metamanager' ) . '</p>' .
					'<ul>' .
					'<li><strong>' . esc_html__( 'Disable REST API', 'metamanager' ) . '</strong> — ' . esc_html__( 'Blocks all unauthenticated requests to /wp-json/metamanager/v1/*. Logged-in users are not blocked.', 'metamanager' ) . '</li>' .
					'<li><strong>' . esc_html__( 'Allowed IP Addresses', 'metamanager' ) . '</strong> — ' . esc_html__( 'Restricts unauthenticated access to requests originating from the listed IPv4 or IPv6 addresses. Leave blank to allow requests from any IP. Logged-in users are not subject to this restriction.', 'metamanager' ) . '</li>' .
					'</ul>' .
					'<p>' . esc_html__( 'Normal WordPress authentication (X-WP-Nonce header or cookie) and capability checks still apply to all requests.', 'metamanager' ) . '</p>',
			] );

			$screen->add_help_tab( [
				'id'      => 'mm_help_settings_notify',
				'title'   => __( 'Upload Receipts', 'metamanager' ),
				'content' =>
					'<h2>' . esc_html__( 'Upload Receipt Emails', 'metamanager' ) . '</h2>' .
					'<p>' . esc_html__( 'When "Enable upload receipt emails" is checked, Metamanager sends a digest email after a batch of uploads. Emails are grouped into 60-second windows — no matter how many files are uploaded in that window, only one email is sent per uploader (plus one to the admin address).', 'metamanager' ) . '</p>' .
					'<ul>' .
					'<li><strong>' . esc_html__( 'Extra CC address', 'metamanager' ) . '</strong> — ' . esc_html__( 'An additional email address to CC on every upload receipt. Leave blank to send only to the uploader and the site admin.', 'metamanager' ) . '</li>' .
					'</ul>' .
					'<p>' . esc_html__( 'If an email fails to send, an admin notice banner appears at the top of the dashboard with a one-click retry button. Dismiss it to discard the failed batch without retrying.', 'metamanager' ) . '</p>',
			] );

			$screen->add_help_tab( [
				'id'      => 'mm_help_settings_data',
				'title'   => __( 'Data & Uninstall', 'metamanager' ),
				'content' =>
					'<h2>' . esc_html__( 'Data & Uninstall', 'metamanager' ) . '</h2>' .
					'<p>' . esc_html__( 'By default, Metamanager leaves all data in place when the plugin is deleted — options, post meta, and the job log table are kept so nothing is lost on an accidental uninstall.', 'metamanager' ) . '</p>' .
					'<p>' . esc_html__( 'Enable "Remove all data on uninstall" only when you are certain you want a clean removal. When this setting is on, deleting the plugin from the Plugins screen will permanently remove all plugin options, all custom post meta, the metamanager_jobs database table, and the job queue directory.', 'metamanager' ) . '</p>' .
					'<p>' . esc_html__( 'The daemon services must be stopped and removed manually from the server.', 'metamanager' ) . '</p>',
			] );

			$screen->set_help_sidebar(
				'<p><strong>' . esc_html__( 'Metamanager', 'metamanager' ) . ' ' . MM_VERSION . '</strong></p>' .
				'<p><a href="https://metamanager.richardkentgates.com" target="_blank" rel="noopener">' . esc_html__( 'Documentation Website', 'metamanager' ) . ' ↗</a></p>' .
				'<p><a href="https://github.com/richardkentgates/metamanager" target="_blank" rel="noopener">' . esc_html__( 'GitHub Repository', 'metamanager' ) . ' ↗</a></p>' .
				'<p><a href="https://github.com/richardkentgates/metamanager/issues" target="_blank" rel="noopener">' . esc_html__( 'Report an Issue', 'metamanager' ) . ' ↗</a></p>'
			);
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
			'edit_others_posts',
			'metamanager-jobs',
			[ __CLASS__, 'render_jobs_page' ]
		);
		add_submenu_page(
			'upload.php',
			esc_html__( 'Bulk Edit Metadata', 'metamanager' ),
			esc_html__( 'Bulk Edit Metadata', 'metamanager' ),
			'edit_others_posts',
			'metamanager-bulk-meta',
			[ __CLASS__, 'render_bulk_meta_page' ]
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
		echo ' &nbsp;cwebp '    . $icon( $status['cwebp'],     'cwebp found',      'cwebp missing — WebP lossless compression disabled' );
		echo ' &nbsp;ffmpeg '   . $icon( $status['ffmpeg'],    'ffmpeg found',     'ffmpeg missing — video remux disabled' );
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
		$columns['mm_meta_sync']   = esc_html__( 'Meta Sync', 'metamanager' );
		return $columns;
	}

	/**
	 * @param string $column_name Column slug.
	 * @param int    $attachment_id Attachment ID.
	 */
	public static function render_media_column( string $column_name, int $attachment_id ): void {
		if ( 'mm_compression' === $column_name ) {
			$status = MM_Status::compression_status( $attachment_id );
			echo '<span class="mm-compress-status" '
				. 'id="mm-status-' . esc_attr( (string) $attachment_id ) . '" '
				. 'data-id="' . esc_attr( (string) $attachment_id ) . '" '
				. 'style="color:' . esc_attr( $status['color'] ) . ';font-weight:bold;">'
				. esc_html( $status['label'] )
				. '</span>';
			return;
		}

		if ( 'mm_meta_sync' === $column_name ) {
			$mime = (string) get_post_mime_type( $attachment_id );
			if ( ! wp_attachment_is_image( $attachment_id ) && ! MM_Metadata::is_av_mime( $mime ) && ! MM_Metadata::is_pdf_mime( $mime ) ) {
				return;
			}
			$synced = (string) get_post_meta( $attachment_id, MM_Metadata::META_SYNCED, true );
			if ( '1' === $synced ) {
				echo '<span class="dashicons dashicons-yes-alt" style="color:#00a32a;" title="'
					. esc_attr__( 'Metadata imported from file', 'metamanager' ) . '"></span>';
			} else {
				echo '<span class="dashicons dashicons-warning" style="color:#dba617;" title="'
					. esc_attr__( 'Not yet synced — use Library Scan (Media → Metamanager)', 'metamanager' ) . '"></span>';
			}
		}
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
		if ( 'attachment' !== $post->post_type ) {
			return;
		}

		$mime     = (string) get_post_mime_type( $post->ID );
		$is_image = wp_attachment_is_image( $post->ID );
		$is_video = MM_Metadata::is_video_mime( $mime );
		$is_audio = MM_Metadata::is_audio_mime( $mime );
		$is_pdf   = MM_Metadata::is_pdf_mime( $mime );

		if ( ! $is_image && ! $is_video && ! $is_audio && ! $is_pdf ) {
			return;
		}

		$file = get_attached_file( $post->ID );
		if ( ! $file || ! file_exists( $file ) ) {
			return;
		}

		$capability = MM_Metadata::write_capability( $mime );
		$metadata   = MM_Metadata::read_embedded( $file );

		echo '<div class="postbox mm-meta-pane"><div class="postbox-header">'
			. '<h2 class="hndle">' . esc_html__( 'Embedded File Metadata', 'metamanager' ) . '</h2>'
			. '</div><div class="inside">';

		// Optimise / re-optimise action row.
		if ( $is_image ) {
			$compress_status = MM_Status::compression_status( $post->ID );
			echo '<div style="margin-bottom:12px;display:flex;align-items:center;gap:16px;">';
			echo '<span>' . esc_html__( 'Compression:', 'metamanager' ) . ' '
				. '<strong style="color:' . esc_attr( $compress_status['color'] ) . ';">' . esc_html( $compress_status['label'] ) . '</strong></span>';
			echo '<button type="button" class="button mm-recompress-btn" data-id="' . esc_attr( (string) $post->ID ) . '">'
				. esc_html__( 'Re-compress This Image', 'metamanager' ) . '</button>';
			echo '<span class="mm-recompress-result" style="font-size:13px;"></span>';
			echo '</div>';
		} elseif ( $is_video ) {
			$can_remux = MM_Status::ffmpeg_available() && 'read_only' !== $capability;
			echo '<div style="margin-bottom:12px;display:flex;align-items:center;gap:16px;">';
			echo '<button type="button" class="button mm-recompress-btn" data-id="' . esc_attr( (string) $post->ID ) . '"'
				. ( $can_remux ? '' : ' disabled' ) . '>'
				. ( $can_remux
					? esc_html__( 'Re-remux This Video', 'metamanager' )
					: esc_html__( 'Remux unavailable', 'metamanager' ) ) . '</button>';
			if ( ! MM_Status::ffmpeg_available() ) {
				echo '<span style="color:#888;font-size:13px;">' . esc_html__( '(ffmpeg not installed)', 'metamanager' ) . '</span>';
			}
			echo '<span class="mm-recompress-result" style="font-size:13px;"></span>';
			echo '</div>';
		} elseif ( $is_audio ) {
			echo '<div style="margin-bottom:12px;">';
			echo '<span style="color:#888;font-size:13px;">'
				. esc_html__( 'Audio files are not compressed — metadata import and write-back only.', 'metamanager' ) . '</span>';
			echo '</div>';
		}

		// Write capability notice.
		if ( 'read_only' === $capability ) {
			echo '<div style="background:#fff3cd;border:1px solid #ffc107;padding:8px 12px;border-radius:3px;margin-bottom:12px;">'
				. '<strong>' . esc_html__( 'Read-only format', 'metamanager' ) . '</strong> &mdash; '
				. esc_html__( 'Metamanager can import metadata from this file but cannot write back to it.', 'metamanager' )
				. '</div>';
		} elseif ( 'xmp_only' === $capability ) {
			echo '<div style="background:#e8f4fd;border:1px solid #2196f3;padding:8px 12px;border-radius:3px;margin-bottom:12px;">'
				. '<strong>' . esc_html__( 'Limited write support', 'metamanager' ) . '</strong> &mdash; '
				. esc_html__( 'Metamanager writes XMP tags only for this format.', 'metamanager' )
				. '</div>';
		} elseif ( 'vorbis_only' === $capability ) {
			echo '<div style="background:#e8f4fd;border:1px solid #2196f3;padding:8px 12px;border-radius:3px;margin-bottom:12px;">'
				. '<strong>' . esc_html__( 'Limited write support', 'metamanager' ) . '</strong> &mdash; '
				. esc_html__( 'Metamanager writes Vorbis comment tags only for this format.', 'metamanager' )
				. '</div>';
		}

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

		// Inline script for the re-compress button on this edit screen.
		echo "<script>
		jQuery(function(\$){
			\$('.mm-recompress-btn').on('click', function(){
				var btn    = \$(this);
				var result = btn.siblings('.mm-recompress-result');
				btn.prop('disabled', true).text('" . esc_js( __( 'Queuing…', 'metamanager' ) ) . "');
				\$.post(ajaxurl, {
					action: 'mm_recompress',
					nonce:  '" . esc_js( wp_create_nonce( 'mm_recompress' ) ) . "',
					id:     btn.data('id')
				}, function(resp){
					btn.prop('disabled', false).text('" . esc_js( __( 'Re-compress This Image', 'metamanager' ) ) . "');
					if (resp.success) {
						var msg = '" . esc_js( __( 'Queued — daemon will process shortly.', 'metamanager' ) ) . "';
						if (resp.data && resp.data.notices && resp.data.notices.length) {
							msg += '<br><em>' + resp.data.notices.join('<br>') + '</em>';
							result.html(msg).css('color','#996800');
						} else {
							result.css('color','#00a32a').text(msg);
						}
					} else {
						result.css('color','#d63638').text(resp.data || '" . esc_js( __( 'Error.', 'metamanager' ) ) . "');
					}
				}, 'json');
			});
		});
		</script>";
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
		$actions['mm_bulk_compress']  = esc_html__( 'Compress Lossless (Metamanager)', 'metamanager' );
		$actions['mm_bulk_site_info'] = esc_html__( 'Inject Site Info into Metadata (Metamanager)', 'metamanager' );
		return $actions;
	}

	/**
	 * @param string $redirect_to Redirect URL.
	 * @param string $doaction    Bulk action slug.
	 * @param int[]  $post_ids    Selected attachment IDs.
	 * @return string
	 */
	public static function handle_bulk_actions( string $redirect_to, string $doaction, array $post_ids ): string {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
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

	// -----------------------------------------------------------------------
	// Job queue status notices
	// -----------------------------------------------------------------------

	/**
	 * Read and clear the current user's queue-status transient, then render
	 * any pending notices as standard WordPress admin notices.
	 *
	 * Called via admin_notices. Also used by AJAX handlers to pull messages
	 * into JSON responses before the transient expires.
	 */
	public static function queue_notices(): void {
		$items = self::get_and_clear_queue_notices();
		if ( empty( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			$name  = esc_html( $item['name'] );
			$sizes = implode( ', ', array_unique( $item['sizes'] ) );

			if ( 'skipped' === $item['status'] ) {
				// Compression duplicate — job was not written.
				echo '<div class="notice notice-warning is-dismissible"><p>'
					. sprintf(
						/* translators: 1: file name, 2: size slug(s) */
						esc_html__( 'Metamanager: A compression job for “%1$s” (%2$s) is already in the queue — your request was not duplicated.', 'metamanager' ),
						$name,
						esc_html( $sizes )
					)
					. '</p></div>';
			} else {
				// Metadata queued in sequence behind an existing pending job.
				echo '<div class="notice notice-info is-dismissible"><p>'
					. sprintf(
						/* translators: 1: file name, 2: size slug(s) */
						esc_html__( 'Metamanager: A metadata job for “%1$s” (%2$s) is already in the queue — your update has been added and will run in sequence after it.', 'metamanager' ),
						$name,
						esc_html( $sizes )
					)
					. '</p></div>';
			}
		}
	}

	/**
	 * Read and clear the queue-status transient for the current user.
	 * Returns the notices array (may be empty). Used by both queue_notices()
	 * and AJAX handlers that need to forward messages in JSON responses.
	 *
	 * @return array
	 */
	private static function get_and_clear_queue_notices(): array {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return [];
		}
		$key   = 'mm_queue_notices_' . $user_id;
		$items = get_transient( $key ) ?: [];
		if ( ! empty( $items ) ) {
			delete_transient( $key );
		}
		return is_array( $items ) ? $items : [];
	}

	/**
	 * Convert queue-notice items into plain strings suitable for inclusion in
	 * an AJAX JSON response. The JS handler is responsible for rendering them.
	 *
	 * @param  array $items Items returned by get_and_clear_queue_notices().
	 * @return string[]
	 */
	private static function format_notices_for_ajax( array $items ): array {
		$messages = [];
		foreach ( $items as $item ) {
			$name  = $item['name'];
			$sizes = implode( ', ', array_unique( $item['sizes'] ) );
			if ( 'skipped' === $item['status'] ) {
				$messages[] = sprintf(
					/* translators: 1: file name, 2: size slug(s) */
					__( 'A compression job for "%1$s" (%2$s) is already in the queue — your request was not duplicated.', 'metamanager' ),
					$name,
					$sizes
				);
			} else {
				$messages[] = sprintf(
					/* translators: 1: file name, 2: size slug(s) */
					__( 'A metadata job for "%1$s" (%2$s) is already in the queue — your update has been added and will run in sequence after it.', 'metamanager' ),
					$name,
					$sizes
				);
			}
		}
		return $messages;
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
					/* translators: %d: number of media files */
					esc_html__( 'Metamanager: Compression queued for %d media file(s).', 'metamanager' ),
					$n
				)
				. '</p></div>';
		}
		if ( ! empty( $_REQUEST['mm_bulk_site_info'] ) ) {
			$n = absint( $_REQUEST['mm_bulk_site_info'] );
			echo '<div class="notice notice-success is-dismissible"><p>'
				. sprintf(
					/* translators: %d: number of media files */
					esc_html__( 'Metamanager: Site provenance info (Publisher + Website) injected for %d media file(s).', 'metamanager' ),
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
			$id   = (int) $id;
			$mime = (string) get_post_mime_type( $id );
			$is_image = wp_attachment_is_image( $id );
			$is_video = MM_Metadata::is_video_mime( $mime );

			if ( ! $is_image && ! $is_video ) {
				continue;
			}

			// Videos: queue remux (skip if already compressed).
			if ( $is_video ) {
				$file = get_attached_file( $id );
				if ( $file && file_exists( $file ) && ! MM_Status::is_compressed( $id, 'full' ) ) {
					MM_Job_Queue::write_job( 'compression', $id, $file, 'full', [ 'trigger' => 'bulk' ] );
					++$count;
				}
				continue;
			}

			// Images: existing per-size logic.
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
			$id   = (int) $id;
			$mime = (string) get_post_mime_type( $id );
			if ( ! wp_attachment_is_image( $id ) && ! MM_Metadata::is_av_mime( $mime ) && ! MM_Metadata::is_pdf_mime( $mime ) ) {
				continue;
			}
			if ( wp_attachment_is_image( $id ) ) {
				MM_Job_Queue::enqueue_all_sizes( $id, [], 'metadata', [ 'trigger' => 'bulk_site_info' ] );
			} else {
				$file = get_attached_file( $id );
				if ( $file && file_exists( $file ) && MM_Metadata::can_write_meta( $mime ) ) {
					MM_Job_Queue::write_job( 'metadata', $id, $file, 'full', [ 'trigger' => 'bulk_site_info' ] );
				}
			}
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
		// Shared API gate: enforces the disabled flag and IP allowlist for
		// unauthenticated / external callers only.  Requests from a logged-in
		// WordPress user (e.g. the Media Library column or job dashboard
		// polling with a valid wp_rest nonce) always pass through so that
		// internal admin features are never affected by these settings.
		$api_check = function (): bool {
			if ( is_user_logged_in() ) {
				return true;
			}
			if ( MM_Settings::get_api_disabled() ) {
				return false;
			}
			$allowed = MM_Settings::get_api_allowed_ips();
			if ( ! empty( $allowed ) ) {
				$remote = MM_Settings::get_current_ip();
				if ( ! in_array( $remote, $allowed, true ) ) {
					return false;
				}
			}
			return true;
		};

		// Read-only status checks: any user who can access the Media Library.
		$auth_uploader = fn() => $api_check() && current_user_can( 'upload_files' );
		// Dashboard / write operations: must be able to manage others' media.
		$auth_editor   = fn() => $api_check() && current_user_can( 'edit_others_posts' );

		// --- Compression status (POST: batch, for Media Library column polling) ---
		register_rest_route(
			'metamanager/v1',
			'/compression-status',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'rest_compression_status' ],
				'permission_callback' => $auth_uploader,
				'args' => [
					'ids' => [
						'required'          => true,
						'validate_callback' => fn( $v ) => is_array( $v ),
						'sanitize_callback' => fn( $v ) => array_map( 'absint', (array) $v ),
					],
				],
			]
		);

		// --- Job history (paginated, filterable) ---
		register_rest_route(
			'metamanager/v1',
			'/jobs',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'rest_get_jobs' ],
				'permission_callback' => $auth_editor,
				'args' => [
					'search'   => [ 'type' => 'string',  'default' => '' ],
					'orderby'  => [ 'type' => 'string',  'default' => 'id' ],
					'order'    => [ 'type' => 'string',  'default' => 'DESC' ],
					'per_page' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
					'page'     => [ 'type' => 'integer', 'default' => 1,  'minimum' => 1 ],
				],
			]
		);

		// --- Single job by DB ID ---
		register_rest_route(
			'metamanager/v1',
			'/jobs/(?P<id>[0-9]+)',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'rest_get_job' ],
				'permission_callback' => $auth_editor,
				'args' => [
					'id' => [ 'type' => 'integer', 'required' => true ],
				],
			]
		);

		// --- Attachment compression + sync status ---
		register_rest_route(
			'metamanager/v1',
			'/attachment/(?P<id>[0-9]+)/status',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'rest_attachment_status' ],
				'permission_callback' => $auth_uploader,
				'args' => [
					'id' => [ 'type' => 'integer', 'required' => true ],
				],
			]
		);

		// --- Queue compression for one attachment ---
		register_rest_route(
			'metamanager/v1',
			'/attachment/(?P<id>[0-9]+)/compress',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'rest_compress_attachment' ],
				'permission_callback' => $auth_editor,
				'args' => [
					'id'    => [ 'type' => 'integer', 'required' => true ],
					'force' => [ 'type' => 'boolean', 'default' => false ],
				],
			]
		);

		// --- Aggregate stats ---
		register_rest_route(
			'metamanager/v1',
			'/stats',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'rest_get_stats' ],
				'permission_callback' => $auth_editor,
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

	/**
	 * REST: paginated job history.
	 */
	public static function rest_get_jobs( \WP_REST_Request $request ) {
		$result = MM_DB::get_jobs( [
			'search'   => (string) $request->get_param( 'search' ),
			'orderby'  => (string) $request->get_param( 'orderby' ),
			'order'    => (string) $request->get_param( 'order' ),
			'per_page' => (int) $request->get_param( 'per_page' ),
			'paged'    => (int) $request->get_param( 'page' ),
		] );
		$response = rest_ensure_response( $result['jobs'] );
		$response->header( 'X-WP-Total',      (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $result['total'] / max( 1, (int) $request->get_param( 'per_page' ) ) ) );
		return $response;
	}

	/**
	 * REST: single job by DB ID.
	 */
	public static function rest_get_job( \WP_REST_Request $request ) {
		$job = MM_DB::get_job( (int) $request->get_param( 'id' ) );
		if ( ! $job ) {
			return new \WP_Error( 'not_found', __( 'Job not found.', 'metamanager' ), [ 'status' => 404 ] );
		}
		return rest_ensure_response( $job );
	}

	/**
	 * REST: compression + sync status for a single attachment.
	 */
	public static function rest_attachment_status( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( ! get_post( $id ) ) {
			return new \WP_Error( 'not_found', __( 'Attachment not found.', 'metamanager' ), [ 'status' => 404 ] );
		}
		return rest_ensure_response( [
			'id'          => $id,
			'compression' => MM_Status::compression_status( $id ),
			'meta_synced' => '1' === get_post_meta( $id, MM_Metadata::META_SYNCED, true ),
		] );
	}

	/**
	 * REST: queue lossless compression for one attachment.
	 *
	 * Images are recompressed losslessly (jpegtran/optipng/cwebp).
	 * Video files are remuxed losslessly (ffmpeg -c copy).
	 * Audio files and PDFs have no compression step — returns 422.
	 */
	public static function rest_compress_attachment( \WP_REST_Request $request ) {
		$id    = (int) $request->get_param( 'id' );
		$force = (bool) $request->get_param( 'force' );
		$mime  = (string) get_post_mime_type( $id );

		$is_image = wp_attachment_is_image( $id );
		$is_video = MM_Metadata::is_video_mime( $mime );

		if ( ! $is_image && ! $is_video ) {
			$detail = MM_Metadata::is_audio_mime( $mime ) || MM_Metadata::is_pdf_mime( $mime )
				? __( 'Audio files and PDFs do not have a compression step. Use the metadata import endpoint instead.', 'metamanager' )
				: __( 'Attachment type is not supported for compression.', 'metamanager' );
			return new \WP_Error( 'not_compressible', $detail, [ 'status' => 422 ] );
		}

		if ( $force ) {
			// force=true: clear existing compression markers so the file is treated
			// as uncompressed and the daemon result will always update status.
			delete_post_meta( $id, '_mm_compressed_full' );
			if ( $is_image ) {
				$meta = wp_get_attachment_metadata( $id ) ?: [];
				if ( ! empty( $meta['sizes'] ) ) {
					foreach ( array_keys( $meta['sizes'] ) as $size ) {
						delete_post_meta( $id, '_mm_compressed_' . $size );
					}
				}
			}
		}

		if ( $is_video ) {
			$file = get_attached_file( $id );
			MM_Job_Queue::write_job( 'compression', $id, $file, 'full', [ 'trigger' => 'rest_api' ] );
		} else {
			MM_Job_Queue::enqueue_all_sizes( $id, [], 'compression', [ 'trigger' => 'rest_api' ] );
		}

		return rest_ensure_response( [
			'id'      => $id,
			'queued'  => true,
			'message' => $is_video
				? __( 'Video remux job queued.', 'metamanager' )
				: __( 'Compression jobs queued.', 'metamanager' ),
		] );
	}

	/**
	 * REST: aggregate stats.
	 */
	public static function rest_get_stats( \WP_REST_Request $request ) {
		return rest_ensure_response( MM_DB::get_stats() );
	}

	// -----------------------------------------------------------------------
	// Admin page — job dashboard
	// -----------------------------------------------------------------------

	public static function render_jobs_page(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
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
						<p><?php esc_html_e( 'Jobs appear here when a media file is uploaded, metadata fields are saved, or a bulk action is triggered. Each job is a small JSON file the OS daemon picks up via inotifywait. Jobs vanish as soon as the daemon processes them.', 'metamanager' ); ?></p>
						<h4><?php esc_html_e( 'Job History', 'metamanager' ); ?></h4>
						<p><?php esc_html_e( 'Once a daemon finishes a job it writes a result file to completed/ or failed/. WP-Cron reads those files every 60 seconds and records them here. Click the image name to open the edit screen. Use Re-queue on any failed job to resubmit it without manual steps.', 'metamanager' ); ?></p>
						<h4><?php esc_html_e( 'Bulk Actions (Media Library)', 'metamanager' ); ?></h4>
						<ul style="margin:.3em 0 0 1.5em;">
							<li><strong><?php esc_html_e( 'Compress Lossless', 'metamanager' ); ?></strong> — <?php esc_html_e( 'queues lossless compression for all uncompressed files in the selection. Images: JPEG via jpegtran, PNG via optipng, WebP via cwebp. Video: container remux via ffmpeg. Files are only replaced if the result is smaller.', 'metamanager' ); ?></li>
							<li><strong><?php esc_html_e( 'Inject Site Info into Metadata', 'metamanager' ); ?></strong> — <?php esc_html_e( 'writes Publisher (your site name) and Website (your site URL) into IPTC and XMP. This is neutral provenance — it never sets Creator, Copyright, or Owner.', 'metamanager' ); ?></li>
						</ul>
						<h4><?php esc_html_e( 'Status Banner', 'metamanager' ); ?></h4>
						<p><?php esc_html_e( 'The banner at the top shows tool availability and daemon health. A green icon means the tool is installed and reachable. A red icon means it is missing or the daemon is not running. Daemon status is read from a PID file — no elevated privileges are needed.', 'metamanager' ); ?></p>
						<p><?php esc_html_e( 'Full documentation is available in the Help tab (top right) and at', 'metamanager' ); ?> <a href="https://metamanager.richardkentgates.com" target="_blank" rel="noopener">metamanager.richardkentgates.com</a>.</p>
					</div>
				</details>
			</div>

			<!-- Library Sync tool -->
			<div class="postbox mm-section" id="mm-sync-box">
				<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Library Sync', 'metamanager' ); ?></h2></div>
				<div class="inside" style="display:flex;flex-direction:column;gap:10px;">
					<div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
						<p style="margin:0;">
							<?php esc_html_e( 'Scans every media file in the library that has never been processed by Metamanager and imports any embedded metadata into WordPress fields. Safe to run at any time — existing user-set values are never overwritten.', 'metamanager' ); ?>
						</p>
						<button id="mm-scan-library-btn" class="button button-primary" style="white-space:nowrap;">
							<?php esc_html_e( 'Scan Existing Library', 'metamanager' ); ?>
						</button>
						<span id="mm-scan-result" style="font-size:13px;"></span>
					</div>
					<div style="background:#e5e5e5;border-radius:3px;height:8px;display:none;" id="mm-scan-progress-wrap">
						<div id="mm-scan-progress" style="background:#00a32a;height:100%;border-radius:3px;width:0%;transition:width .3s;"></div>
					</div>
				</div>
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

			$(document).on('click', '#mm-scan-library-btn', function(e){
				e.preventDefault();
				var btn        = $(this);
				var result     = $('#mm-scan-result');
				var progressEl = $('#mm-scan-progress');
				btn.prop('disabled', true).text('<?php echo esc_js( __( 'Scanning…', 'metamanager' ) ); ?>');
				result.text('');
				$('#mm-scan-progress-wrap').show();
				progressEl.css('width', '0%');

				var totalScanned = 0;
				var nonce = '<?php echo esc_js( wp_create_nonce( 'mm_scan_library' ) ); ?>';

				function runBatch(offset, total) {
					$.post(ajaxurl, {
						action:     'mm_scan_library',
						nonce:      nonce,
						offset:     offset,
						batch_size: 50,
						total:      total || 0,
					}, function(resp){
						if (!resp.success) {
							btn.prop('disabled', false).text('<?php echo esc_js( __( 'Scan Existing Library', 'metamanager' ) ); ?>');
							result.css('color','#d63638').text('<?php echo esc_js( __( 'Scan failed.', 'metamanager' ) ); ?>');
							$('#mm-scan-progress-wrap').hide();
							return;
						}
						totalScanned += resp.data.count;
						var pct = resp.data.total > 0 ? Math.min(100, Math.round(resp.data.offset / resp.data.total * 100)) : 100;
						progressEl.css('width', pct + '%');
						result.css('color','#50575e').text(
							'<?php echo esc_js( __( 'Scanned', 'metamanager' ) ); ?> ' + totalScanned +
							' / ' + resp.data.total + ' <?php echo esc_js( __( 'media file(s)…', 'metamanager' ) ); ?>'
						);
						if (!resp.data.done) {
							runBatch(resp.data.offset, resp.data.total);
						} else {
							btn.prop('disabled', false).text('<?php echo esc_js( __( 'Scan Existing Library', 'metamanager' ) ); ?>');
							result.css('color','#00a32a').text(
								'<?php echo esc_js( __( 'Done — scanned', 'metamanager' ) ); ?> ' +
								totalScanned + ' <?php echo esc_js( __( 'media file(s).', 'metamanager' ) ); ?>'
							);
							$('#mm-scan-progress-wrap').hide();
						}
					}, 'json');
				}
				runBatch(0, 0);
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
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( '-1' );
		}
		self::render_jobs_content();
		wp_die();
	}

	public static function ajax_requeue_job(): void {
		check_ajax_referer( 'mm_requeue_job', 'nonce' );
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}
		$job_id = absint( $_POST['job_id'] ?? 0 );
		if ( MM_Job_Queue::requeue( $job_id ) ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( 'Job not found or file missing.' );
		}
	}

	/**
	 * AJAX: Scan un-synced library attachments in batches and enqueue daemon jobs.
	 *
	 * For each un-synced attachment:
	 *   1. Bootstraps WP post_meta from any metadata already embedded in the file.
	 *   2. Queues metadata-embedding and compression jobs for the daemon — mirrors
	 *      the on_upload() path so every attachment goes through the full pipeline.
	 *
	 * Supports incremental calls: pass `offset` to pick up where the last
	 * batch finished. JS calls this in a loop until `done` is true.
	 *
	 * Reads $_POST['offset'] (int, default 0) and $_POST['batch_size'] (int, default 50).
	 */
	public static function ajax_scan_library(): void {
		check_ajax_referer( 'mm_scan_library', 'nonce' );
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$offset     = max( 0, (int) ( $_POST['offset']     ?? 0 ) );
		$batch_size = max( 1, min( 200, (int) ( $_POST['batch_size'] ?? 50 ) ) );

		// Include images plus all supported video and audio MIME types.
		$all_mime_types = array_merge(
			[ 'image' ],
			MM_Metadata::VIDEO_MIME_TYPES,
			MM_Metadata::AUDIO_MIME_TYPES,
			MM_Metadata::PDF_MIME_TYPES
		);

		$base_query = [
			'post_type'      => 'attachment',
			'post_mime_type' => $all_mime_types,
			'post_status'    => 'inherit',
			'fields'         => 'ids',
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery
				[
					'key'     => MM_Metadata::META_SYNCED,
					'compare' => 'NOT EXISTS',
				],
			],
		];

		// Total un-synced count: accept the client's cached value on all calls
		// after the first to avoid a full library scan on every batch.
		// On the first call (posted total is 0 or absent) run the count query.
		$posted_total = max( 0, (int) ( $_POST['total'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $posted_total > 0 && $offset > 0 ) {
			$total = $posted_total;
		} else {
			$total_ids = get_posts( array_merge( $base_query, [ 'numberposts' => -1 ] ) );
			$total     = count( $total_ids );
		}

		// Fetch this batch.
		$batch = get_posts( array_merge( $base_query, [
			'numberposts' => $batch_size,
			'offset'      => $offset,
		] ) );

		$count = 0;
		foreach ( $batch as $id ) {
			$id   = (int) $id;
			$mime = (string) get_post_mime_type( $id );

			// Bootstrap WP post_meta from any metadata already embedded in the file.
			MM_Metadata::import_from_file( $id );

			// Queue daemon jobs — mirrors the on_upload() path for unsynced attachments.
			if ( wp_attachment_is_image( $id ) ) {
				MM_Job_Queue::enqueue_all_sizes( $id, [], 'both', [ 'trigger' => 'scan' ] );
			} elseif ( MM_Metadata::is_video_mime( $mime ) ) {
				$file = get_attached_file( $id );
				if ( $file && file_exists( $file ) ) {
					MM_Job_Queue::write_job( 'metadata', $id, $file, 'full', [ 'trigger' => 'scan' ] );
					MM_Job_Queue::write_job( 'compression', $id, $file, 'full', [ 'trigger' => 'scan' ] );
				}
			} elseif ( MM_Metadata::is_audio_mime( $mime ) || MM_Metadata::is_pdf_mime( $mime ) ) {
				if ( MM_Metadata::can_write_meta( $mime ) ) {
					$file = get_attached_file( $id );
					if ( $file && file_exists( $file ) ) {
						MM_Job_Queue::write_job( 'metadata', $id, $file, 'full', [ 'trigger' => 'scan' ] );
					}
				}
			}

			++$count;
		}

		$new_offset = $offset + $count;
		$done       = empty( $batch ) || $new_offset >= $total;

		wp_send_json_success( [
			'count'  => $count,
			'offset' => $new_offset,
			'total'  => $total,
			'done'   => $done,
		] );
	}

	/**
	 * AJAX: Re-compress a single image from its edit screen.
	 */
	public static function ajax_recompress(): void {
		check_ajax_referer( 'mm_recompress', 'nonce' );

		$id   = absint( $_POST['id'] ?? 0 );
		$mime = $id ? (string) get_post_mime_type( $id ) : '';

		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		if ( ! wp_attachment_is_image( $id ) && ! MM_Metadata::is_video_mime( $mime ) ) {
			wp_send_json_error( __( 'Invalid or unsupported attachment.', 'metamanager' ) );
		}

		// Clear existing compression flags so every size gets re-processed.
		delete_post_meta( $id, '_mm_compressed_full' );
		$meta = wp_get_attachment_metadata( $id ) ?: [];
		if ( ! empty( $meta['sizes'] ) ) {
			foreach ( array_keys( $meta['sizes'] ) as $size ) {
				delete_post_meta( $id, '_mm_compressed_' . $size );
			}
		}

		if ( MM_Metadata::is_video_mime( $mime ) ) {
			$file = get_attached_file( $id );
			if ( ! $file || ! file_exists( $file ) ) {
				wp_send_json_error( __( 'File not found.', 'metamanager' ) );
			}
			MM_Job_Queue::write_job( 'compression', $id, $file, 'full', [ 'trigger' => 'manual' ] );
		} else {
			MM_Job_Queue::enqueue_all_sizes( $id, $meta, 'compression', [ 'trigger' => 'manual' ] );
		}

		$notices = self::get_and_clear_queue_notices();
		wp_send_json_success( [ 'notices' => self::format_notices_for_ajax( $notices ) ] );
	}

	/**
	 * AJAX: Save a single row's metadata fields from the bulk edit page.
	 */
	public static function ajax_save_bulk_meta_row(): void {
		check_ajax_referer( 'mm_bulk_meta_save', 'nonce' );

		$id     = absint( $_POST['id'] ?? 0 );
		$fields = $_POST['fields'] ?? [];

		if ( ! $id || ! get_post( $id ) ) {
			wp_send_json_error( __( 'Attachment not found.', 'metamanager' ) );
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		// Allowed bulk-editable fields (no rights/attribution fields).
		$allowed = [
			MM_Metadata::META_HEADLINE,
			MM_Metadata::META_CREDIT,
			MM_Metadata::META_KEYWORDS,
			MM_Metadata::META_DATE,
			MM_Metadata::META_CITY,
			MM_Metadata::META_STATE,
			MM_Metadata::META_COUNTRY,
		];

		foreach ( $allowed as $key ) {
			if ( isset( $fields[ $key ] ) ) {
				update_post_meta( $id, $key, sanitize_text_field( (string) $fields[ $key ] ) );
			}
		}

		// Queue a metadata embedding job for the changed image.
		MM_Job_Queue::enqueue_all_sizes( $id, [], 'metadata', [ 'trigger' => 'bulk_meta_edit' ] );

		$notices = self::get_and_clear_queue_notices();
		wp_send_json_success( [ 'notices' => self::format_notices_for_ajax( $notices ) ] );
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
				. '<th>' . esc_html__( 'File', 'metamanager' ) . '</th>'
				. '<th>' . esc_html__( 'Type', 'metamanager' ) . '</th>'
				. '<th>' . esc_html__( 'Size', 'metamanager' ) . '</th>'
				. '<th>' . esc_html__( 'Dimensions', 'metamanager' ) . '</th>'
				. '<th>' . esc_html__( 'Savings', 'metamanager' ) . '</th>'
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

				// Compression savings column.
				$savings_html = '—';
				$bytes_before = (int) ( $job->bytes_before ?? 0 );
				$bytes_after  = (int) ( $job->bytes_after  ?? 0 );
				if ( $bytes_before > 0 && $bytes_after > 0 && 'compression' === $job->job_type ) {
					if ( $bytes_after < $bytes_before ) {
						$saved = $bytes_before - $bytes_after;
						$pct   = round( $saved / $bytes_before * 100, 1 );
						$savings_html = '<span style="color:#00a32a;">−' . esc_html( size_format( $saved ) ) . ' (' . esc_html( (string) $pct ) . '%)</span>';
					} else {
						$savings_html = '<span style="color:#888;">' . esc_html__( 'already optimal', 'metamanager' ) . '</span>';
					}
				}

				echo '<tr>'
					. '<td>' . esc_html( (string) $job->id ) . '</td>'
					. '<td>' . $att_link . '</td>' // Escaped above.
					. '<td>' . esc_html( ucfirst( $job->job_type ) ) . '</td>'
					. '<td>' . esc_html( $job->size ) . '</td>'
					. '<td>' . esc_html( $job->dimensions ) . '</td>'
					. '<td>' . $savings_html . '</td>' // Escaped above.
					. '<td><span class="' . esc_attr( $status_class ) . '">' . esc_html( ucfirst( $job->status ) ) . '</span></td>'
					. '<td>' . esc_html( $job->submitted_at ) . '</td>'
					. '<td>' . esc_html( $job->completed_at ?? '' ) . '</td>'
					. '<td>' . $requeue_btn . '</td>' // Re-queue button for failed jobs; empty otherwise.
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

	// -----------------------------------------------------------------------
	// Bulk Metadata Edit page
	// -----------------------------------------------------------------------

	/**
	 * Render the dedicated Bulk Edit Metadata page.
	 *
	 * Shows a paginated table of images with inline-editable fields for the
	 * "safe" bulk-edit fields.  Creator/Copyright/Owner are intentionally
	 * excluded — those carry authorship and rights meaning and must be set
	 * per-image only.
	 */
	public static function render_bulk_meta_page(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'metamanager' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification
		$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$search   = sanitize_text_field( $_GET['s'] ?? '' );
		// phpcs:enable
		$per_page = 25;

		$query_args = [
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'numberposts'    => $per_page,
			'offset'         => ( $paged - 1 ) * $per_page,
			'fields'         => 'ids',
		];
		if ( $search ) {
			$query_args['s'] = $search;
		}
		$ids = get_posts( $query_args );

		// Total count.
		$count_args          = $query_args;
		$count_args['fields']      = 'ids';
		$count_args['numberposts'] = -1;
		unset( $count_args['offset'] );
		$all_ids     = get_posts( $count_args );
		$total       = count( $all_ids );
		$total_pages = (int) ceil( $total / $per_page );

		$nonce = wp_create_nonce( 'mm_bulk_meta_save' );

		$safe_fields = [
			MM_Metadata::META_HEADLINE => __( 'Headline', 'metamanager' ),
			MM_Metadata::META_CREDIT   => __( 'Credit', 'metamanager' ),
			MM_Metadata::META_KEYWORDS => __( 'Keywords', 'metamanager' ),
			MM_Metadata::META_DATE     => __( 'Date Created', 'metamanager' ),
			MM_Metadata::META_CITY     => __( 'City', 'metamanager' ),
			MM_Metadata::META_STATE    => __( 'State', 'metamanager' ),
			MM_Metadata::META_COUNTRY  => __( 'Country', 'metamanager' ),
		];
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Bulk Edit Metadata', 'metamanager' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'upload.php?page=metamanager-jobs' ) ); ?>" class="page-title-action">
				<?php esc_html_e( '← Job Dashboard', 'metamanager' ); ?>
			</a>
			<hr class="wp-header-end">

			<p class="description">
				<?php esc_html_e(
					'Edit shared metadata fields for multiple images at once. ' .
					'Save individual rows with the row button, or use Save All to write all visible changes at once. ' .
					'Creator, Copyright, and Owner are intentionally excluded — they must be set per image.',
					'metamanager'
				); ?>
			</p>

			<!-- Search form -->
			<form method="get" action="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>" style="margin:1em 0;">
				<input type="hidden" name="page" value="metamanager-bulk-meta">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search images…', 'metamanager' ); ?>" class="regular-text">
				<button class="button"><?php esc_html_e( 'Search', 'metamanager' ); ?></button>
				<?php if ( $search ) : ?>
					<a href="<?php echo esc_url( admin_url( 'upload.php?page=metamanager-bulk-meta' ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'metamanager' ); ?></a>
				<?php endif; ?>
			</form>

			<?php if ( empty( $ids ) ) : ?>
				<p><?php esc_html_e( 'No images found.', 'metamanager' ); ?></p>
			<?php else : ?>
				<div id="mm-bulk-meta-status" style="min-height:1.5em;font-size:13px;margin-bottom:.5em;"></div>

				<table class="widefat striped mm-bulk-meta-table" style="font-size:13px;">
					<thead>
						<tr>
							<th style="width:80px;"><?php esc_html_e( 'Image', 'metamanager' ); ?></th>
							<th><?php esc_html_e( 'Title', 'metamanager' ); ?></th>
							<?php foreach ( $safe_fields as $key => $label ) : ?>
								<th><?php echo esc_html( $label ); ?></th>
							<?php endforeach; ?>
							<th><?php esc_html_e( 'Action', 'metamanager' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $ids as $id ) :
						$id      = (int) $id;
						$src     = wp_get_attachment_image_url( $id, 'thumbnail' ) ?: '';
						$title   = get_the_title( $id );
						$edit_url = get_edit_post_link( $id );
						?>
						<tr data-id="<?php echo esc_attr( (string) $id ); ?>">
							<td>
								<?php if ( $src ) : ?>
									<img src="<?php echo esc_url( $src ); ?>" style="width:60px;height:60px;object-fit:cover;border-radius:3px;">
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $edit_url ) : ?>
									<a href="<?php echo esc_url( $edit_url ); ?>" target="_blank"><?php echo esc_html( $title ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $title ); ?>
								<?php endif; ?>
								<br><small style="color:#888;">#<?php echo esc_html( (string) $id ); ?></small>
							</td>
							<?php foreach ( $safe_fields as $key => $label ) :
								$val = (string) get_post_meta( $id, $key, true );
								?>
								<td>
									<input type="text"
										   class="mm-bm-field"
										   data-key="<?php echo esc_attr( $key ); ?>"
										   value="<?php echo esc_attr( $val ); ?>"
										   placeholder="<?php echo esc_attr( $label ); ?>"
										   style="width:100%;min-width:80px;">
								</td>
							<?php endforeach; ?>
							<td>
								<button class="button button-small mm-bm-save-row" data-id="<?php echo esc_attr( (string) $id ); ?>">
									<?php esc_html_e( 'Save', 'metamanager' ); ?>
								</button>
								<span class="mm-bm-row-status" style="font-size:11px;margin-left:4px;"></span>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<div style="margin-top:10px;display:flex;align-items:center;gap:12px;">
					<button id="mm-bm-save-all" class="button button-primary">
						<?php esc_html_e( 'Save All on This Page', 'metamanager' ); ?>
					</button>
					<span id="mm-bm-save-all-status" style="font-size:13px;"></span>
				</div>

				<!-- Pagination -->
				<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom" style="margin-top:1em;">
					<div class="tablenav-pages">
						<span class="displaying-num"><?php
							printf(
								/* translators: %d: total */
								esc_html__( '%d images', 'metamanager' ),
								$total
							);
						?></span>
						<span class="pagination-links">
							<?php for ( $i = 1; $i <= $total_pages; $i++ ) :
								$url = add_query_arg( [ 'paged' => $i, 's' => $search, 'page' => 'metamanager-bulk-meta' ], admin_url( 'upload.php' ) );
								?>
								<?php if ( $i === $paged ) : ?>
									<span class="tablenav-pages-navspan button disabled" aria-current="page"><?php echo esc_html( (string) $i ); ?></span>
								<?php else : ?>
									<a class="button" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( (string) $i ); ?></a>
								<?php endif; ?>
							<?php endfor; ?>
						</span>
					</div>
				</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<script>
		jQuery(function($){
			var nonce = '<?php echo esc_js( $nonce ); ?>';

			function saveRow(row, btn, statusEl) {
				var id     = row.data('id');
				var fields = {};
				row.find('.mm-bm-field').each(function(){
					fields[$(this).data('key')] = $(this).val();
				});
				btn.prop('disabled', true);
				$.post(ajaxurl, {
					action: 'mm_save_bulk_meta_row',
					nonce:  nonce,
					id:     id,
					fields: fields,
				}, function(resp){
					btn.prop('disabled', false);
					if (resp.success) {
						statusEl.css('color','#00a32a').text('✔');
						setTimeout(function(){ statusEl.text(''); }, 3000);
						if (resp.data && resp.data.notices && resp.data.notices.length) {
							var $status = $('#mm-bulk-meta-status');
							var html = resp.data.notices.map(function(n){
								return '<div class="notice notice-info inline" style="margin:.25em 0;padding:.4em .75em;"><p>' + n + '</p></div>';
							}).join('');
							$status.html(html);
							setTimeout(function(){ $status.html(''); }, 8000);
						}
					} else {
						statusEl.css('color','#d63638').text('✘ ' + (resp.data || '<?php echo esc_js( __( 'Error', 'metamanager' ) ); ?>'));
					}
				}, 'json');
			}

			$(document).on('click', '.mm-bm-save-row', function(){
				var btn  = $(this);
				var row  = btn.closest('tr');
				saveRow(row, btn, btn.siblings('.mm-bm-row-status'));
			});

			$('#mm-bm-save-all').on('click', function(){
				var btn    = $(this);
				var status = $('#mm-bm-save-all-status');
				var rows   = $('.mm-bulk-meta-table tbody tr');
				var total  = rows.length;
				var done   = 0;
				btn.prop('disabled', true);
				status.css('color','#50575e').text('0 / ' + total);

				rows.each(function(){
					var row = $(this);
					var rowBtn = row.find('.mm-bm-save-row');
					saveRow(row, rowBtn, row.find('.mm-bm-row-status'));
					// Track completion via row count (simple approach).
					done++;
					if (done === total) {
						status.css('color','#00a32a').text('<?php echo esc_js( __( 'All saved.', 'metamanager' ) ); ?>');
						btn.prop('disabled', false);
						setTimeout(function(){ status.text(''); }, 4000);
					}
				});
			});
		});
		</script>
		<?php
	}
}
