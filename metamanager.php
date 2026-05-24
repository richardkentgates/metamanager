<?php
/**
 * Plugin Name:  Metamanager
 * Plugin URI:   https://github.com/richardkentgates/metamanager
 * Description:  Lossless image compression and standards-compliant metadata embedding (EXIF, IPTC, XMP) via OS-level daemons. Expands the WordPress Media Library with native metadata editing, bulk operations, and a real-time job dashboard.
 * Version:      2.1.7
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Author:       Richard Kent Gates
 * Author URI:   https://github.com/richardkentgates
 * License:      GPL-2.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  metamanager
 * Domain Path:  /languages
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Plugin constants
// ---------------------------------------------------------------------------

define( 'MM_VERSION',     '2.1.7' );
define( 'MM_PLUGIN_FILE', __FILE__ );
define( 'MM_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'MM_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// Metadata subsystem constants (used by includes/metadata/** classes).
define( 'MM_META_VERSION',      MM_VERSION );
define( 'MM_META_FILE',         __FILE__ );
define( 'MM_META_DIR',          MM_PLUGIN_DIR );
define( 'MM_META_URL',          MM_PLUGIN_URL );
define( 'MM_META_BASENAME',     plugin_basename( __FILE__ ) );
define( 'MM_META_OPT_SETTINGS', 'mm_meta_settings' );
define( 'MM_META_OPT_BUSINESS', 'mm_meta_business' );
define( 'MM_META_KEY',          '_mm_meta' );

/**
 * Job queue directories.
 *
 * MM_JOB_ROOT is the only path the daemons need to know. metamanager-install.sh patches
 * the shell scripts with the exact value of WP_CONTENT_DIR at deploy time,
 * so PHP and the OS always agree on the location — no hardcoded paths anywhere.
 */
define( 'MM_JOB_ROOT',     WP_CONTENT_DIR . '/metamanager-jobs' );
define( 'MM_JOB_COMPRESS', MM_JOB_ROOT . '/compress/' );
define( 'MM_JOB_META',     MM_JOB_ROOT . '/meta/' );
define( 'MM_JOB_DONE',     MM_JOB_ROOT . '/completed/' );
define( 'MM_JOB_FAILED',   MM_JOB_ROOT . '/failed/' );

/** Database table name (without prefix). */
define( 'MM_JOB_TABLE', 'metamanager_jobs' );

/**
 * PID files written by the daemons on startup.
 * Stored in MM_JOB_ROOT so they are visible to PHP-FPM even when its
 * systemd service has PrivateTmp=yes (which isolates /tmp per-service).
 */
define( 'MM_PID_COMPRESS', MM_JOB_ROOT . '/compress-daemon.pid' );
define( 'MM_PID_META',     MM_JOB_ROOT . '/meta-daemon.pid' );

// ---------------------------------------------------------------------------
// Autoload classes
// ---------------------------------------------------------------------------

require_once MM_PLUGIN_DIR . 'includes/class-mm-db.php';
require_once MM_PLUGIN_DIR . 'includes/class-mm-job-queue.php';
require_once MM_PLUGIN_DIR . 'includes/class-mm-metadata.php';
require_once MM_PLUGIN_DIR . 'includes/class-mm-status.php';
require_once MM_PLUGIN_DIR . 'includes/class-mm-settings.php';
require_once MM_PLUGIN_DIR . 'includes/class-mm-sitemap.php';
require_once MM_PLUGIN_DIR . 'includes/class-mm-upload-notify.php';
require_once MM_PLUGIN_DIR . 'includes/class-mm-admin.php';
require_once MM_PLUGIN_DIR . 'includes/class-mm-updater.php';
require_once MM_PLUGIN_DIR . 'includes/class-mm-cli.php';

// ---------------------------------------------------------------------------
// Metadata subsystem — page-level head output, structured data, social tags,
// per-post/term/user metadata panels, sitemaps, robots.txt, and more.
// ---------------------------------------------------------------------------
require_once MM_META_DIR . 'includes/metadata/class-mm-mod-base.php';
require_once MM_META_DIR . 'includes/metadata/class-mm-site-settings.php';
require_once MM_META_DIR . 'includes/metadata/class-mm-page-context.php';
require_once MM_META_DIR . 'includes/metadata/class-mm-head-emitter.php';
require_once MM_META_DIR . 'includes/metadata/class-mm-schema-types.php';
require_once MM_META_DIR . 'includes/metadata/class-mm-biz-card-css.php';
require_once MM_META_DIR . 'includes/metadata/class-mm-importer.php';
require_once MM_META_DIR . 'includes/metadata/class-mm-metadata-cli.php';
require_once MM_META_DIR . 'includes/metadata/modules/class-mm-mod-head-meta.php';
require_once MM_META_DIR . 'includes/metadata/modules/class-mm-mod-social.php';
require_once MM_META_DIR . 'includes/metadata/modules/class-mm-mod-schema.php';
require_once MM_META_DIR . 'includes/metadata/modules/class-mm-mod-sitemap.php';
require_once MM_META_DIR . 'includes/metadata/modules/class-mm-mod-robots.php';
require_once MM_META_DIR . 'includes/metadata/modules/class-mm-mod-author.php';
require_once MM_META_DIR . 'includes/metadata/modules/class-mm-mod-hygiene.php';
require_once MM_META_DIR . 'includes/metadata/modules/class-mm-mod-links.php';
require_once MM_META_DIR . 'includes/metadata/modules/class-mm-mod-local.php';
require_once MM_META_DIR . 'includes/metadata/modules/class-mm-mod-html-sitemap.php';
require_once MM_META_DIR . 'includes/metadata/modules/class-mm-mod-business-contact.php';
require_once MM_META_DIR . 'includes/metadata/modules/class-mm-mod-rss.php';
require_once MM_META_DIR . 'includes/metadata/admin/class-mm-metadata-help.php';
require_once MM_META_DIR . 'includes/metadata/admin/class-mm-metadata-admin.php';
require_once MM_META_DIR . 'includes/metadata/admin/class-mm-post-meta-panel.php';
require_once MM_META_DIR . 'includes/metadata/admin/class-mm-term-meta-panel.php';
require_once MM_META_DIR . 'includes/metadata/admin/class-mm-user-meta-panel.php';
require_once MM_META_DIR . 'includes/metadata/class-mm-metadata-loader.php';

// Boot the metadata subsystem after all plugins are loaded so hooks fire in
// the correct order.
add_action( 'plugins_loaded', function (): void {
	( new MM_Metadata_Loader() )->run();
} );

// Media sitemaps: rewrite rules and template_redirect for /sitemap-media.xml etc.
MM_Sitemap::init();

// Auto-clean job history when an attachment is deleted from the Media Library.
// Fires in both admin and REST API contexts, so it belongs here unconditionally.
add_action( 'delete_attachment', [ 'MM_DB', 'delete_jobs_for_attachment' ] );

// ---------------------------------------------------------------------------
// Activation / deactivation
// ---------------------------------------------------------------------------

register_activation_hook( MM_PLUGIN_FILE, 'mm_activate' );
register_deactivation_hook( MM_PLUGIN_FILE, 'mm_deactivate' );

/**
 * Single-site activation routine: create DB table, job directories, schedule cron,
 * and flush rewrite rules so sitemap URLs resolve immediately.
 */
function mm_activate_single_site(): void {
	MM_DB::create_or_update_table();
	MM_Job_Queue::ensure_dirs();

	if ( ! wp_next_scheduled( 'mm_import_completed_jobs' ) ) {
		wp_schedule_event( time(), 'mm_every_minute', 'mm_import_completed_jobs' );
	}

	// Migrate option keys from old gcm-seo-core names to mm_meta_* keys.
	// Safe to run on every activation — only copies when old key exists and new
	// key does not, then removes the old key so it is a one-time migration.
	$migrations = [
		'gcm_seo_settings'     => MM_META_OPT_SETTINGS,
		'gcm_seo_business'     => MM_META_OPT_BUSINESS,
		'gcm_seo_contact_style' => 'mm_meta_contact_style',
	];
	foreach ( $migrations as $old_key => $new_key ) {
		$old_value = get_option( $old_key, null );
		if ( $old_value !== null && get_option( $new_key, null ) === null ) {
			update_option( $new_key, $old_value, false );
			delete_option( $old_key );
		}
	}

	MM_Sitemap::add_rewrite_rules();
	flush_rewrite_rules();
}

/**
 * Activation hook. Handles both single-site and network-wide (multisite) activation.
 *
 * When a plugin is network-activated from the Network Admin, WordPress fires the
 * activation hook once (not per-site), so we iterate all blogs manually.
 *
 * @param bool $network_wide True when activated network-wide in a multisite install.
 */
function mm_activate( bool $network_wide = false ): void {
	if ( $network_wide && is_multisite() ) {
		$sites = get_sites( [ 'number' => 0 ] );
		foreach ( $sites as $site ) {
			switch_to_blog( (int) $site->blog_id );
			mm_activate_single_site();
			restore_current_blog();
		}
	} else {
		mm_activate_single_site();
	}
}

/**
 * Deactivation hook. Clears the scheduled cron event on all sites when
 * deactivated network-wide.
 *
 * @param bool $network_wide True when deactivated network-wide.
 */
function mm_deactivate( bool $network_wide = false ): void {
	if ( $network_wide && is_multisite() ) {
		$sites = get_sites( [ 'number' => 0 ] );
		foreach ( $sites as $site ) {
			switch_to_blog( (int) $site->blog_id );
			wp_clear_scheduled_hook( 'mm_import_completed_jobs' );
			wp_clear_scheduled_hook( 'mm_send_upload_receipt' );
			restore_current_blog();
		}
	} else {
		wp_clear_scheduled_hook( 'mm_import_completed_jobs' );
		wp_clear_scheduled_hook( 'mm_send_upload_receipt' );
	}
}

// ---------------------------------------------------------------------------
// Multisite: set up new sites when this plugin is network-activated
// ---------------------------------------------------------------------------

add_action( 'wp_initialize_site', 'mm_on_new_site' ); // WP 5.1+
add_action( 'wpmu_new_blog',      'mm_on_new_blog'  ); // WP < 5.1 (deprecated but harmless)

/**
 * Create the DB table and schedule cron for a brand-new site (WP 5.1+).
 *
 * @param WP_Site $site The newly created site object.
 */
function mm_on_new_site( WP_Site $site ): void {
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! is_plugin_active_for_network( plugin_basename( MM_PLUGIN_FILE ) ) ) {
		return;
	}
	switch_to_blog( (int) $site->blog_id );
	mm_activate_single_site();
	restore_current_blog();
}

/**
 * Create the DB table and schedule cron for a brand-new blog (WP < 5.1 compat).
 *
 * @param int $blog_id The new blog ID.
 */
function mm_on_new_blog( int $blog_id ): void {
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! is_plugin_active_for_network( plugin_basename( MM_PLUGIN_FILE ) ) ) {
		return;
	}
	switch_to_blog( $blog_id );
	mm_activate_single_site();
	restore_current_blog();
}

// ---------------------------------------------------------------------------
// Custom cron interval
// ---------------------------------------------------------------------------

add_filter( 'cron_schedules', function ( array $schedules ): array {
	if ( ! isset( $schedules['mm_every_minute'] ) ) {
		$schedules['mm_every_minute'] = [
			'interval' => 60,
			'display'  => esc_html__( 'Every Minute (Metamanager)', 'metamanager' ),
		];
	}
	return $schedules;
} );

// ---------------------------------------------------------------------------
// Cron handler: import daemon-completed jobs into DB
//
// Philosophy: PHP never touches the image files. The daemons do all heavy
// lifting (compression, metadata embedding). When a daemon finishes a job it
// drops a small result JSON into MM_JOB_DONE or MM_JOB_FAILED. This cron
// runs every minute, reads those files, records them in the DB for the
// history dashboard, then deletes the result files.
// ---------------------------------------------------------------------------

add_action( 'mm_import_completed_jobs', 'mm_import_completed_jobs' );

/**
 * Scan completed/failed result directories and persist to DB.
 * Sends a failure notification email if notifications are enabled.
 */
function mm_import_completed_jobs(): void {
	$result_dirs = [
		MM_JOB_DONE   => 'completed',
		MM_JOB_FAILED => 'failed',
	];

	$failed_jobs = [];

	// Initialise WP_Filesystem for local file reads (direct method; no credentials needed).
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;

	foreach ( $result_dirs as $dir => $status ) {
		// Only read fully-written .json files — skip .tmp (daemon mid-write),
		// .unparseable (prior failure), and .processing (daemon-locked).
		$files = glob( $dir . '*.json' );
		if ( ! $files ) {
			continue;
		}
		foreach ( $files as $filepath ) {
			if ( $wp_filesystem ) {
				$raw = $wp_filesystem->get_contents( $filepath );
			} else {
				// Fallback for uncommon server configurations where WP_Filesystem
				// cannot initialise without credentials (e.g. FTP-only servers).
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$raw = file_get_contents( $filepath );
			}
			$job = $raw ? json_decode( $raw, true ) : null;

			if ( ! is_array( $job ) ) {
				// Unparseable — likely a partial write or corrupted file.
				// Rename so the admin can inspect, rather than silently deleting.
				$backup = $filepath . '.unparseable';
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rename
				rename( $filepath, $backup );
				error_log( sprintf(
					'[Metamanager] Unparseable result file renamed for inspection: %s',
					$backup
				) );
				continue;
			}

			$job['status'] = $status;

			// Only delete the result file and act on the outcome once the DB
			// record is confirmed written. If the insert fails the file is
			// left in place so the next cron run can retry it.
			if ( ! MM_DB::log_job( $job ) ) {
				error_log( sprintf(
					'[Metamanager] DB insert failed for result file: %s — will retry on next cron run.',
					$filepath
				) );
				continue;
			}

			// Import result: apply embedded tags to WP post meta, then queue
			// the metadata write-back job so the daemon embeds the values.
			if ( 'import' === ( $job['job_type'] ?? '' ) && 'completed' === $status ) {
				$att_id  = (int) ( $job['attachment_id'] ?? 0 );
				$tags    = is_array( $job['embedded_tags'] ?? null ) ? $job['embedded_tags'] : [];
				$trigger = (string) ( $job['trigger'] ?? '' );
				if ( $att_id > 0 ) {
					if ( 'verify' === $trigger ) {
						// Post-write-back verification: compare embedded tags against
						// WP meta and record any discrepancies for display in the admin.
						MM_Metadata::apply_verify_result( $att_id, $tags );
					} else {
						MM_Metadata::apply_import_result( $att_id, $tags );

						// Now that WP fields are populated, queue the write-back job.
						$file = get_attached_file( $att_id );
						$mime = (string) get_post_mime_type( $att_id );
						if ( $file && file_exists( $file ) && MM_Metadata::can_write_meta( $mime ) ) {
							MM_Job_Queue::write_job( 'metadata', $att_id, $file, 'full', [ 'trigger' => 'import' ] );
						}

						// For images, also queue metadata write-back for all registered sizes.
						if ( wp_attachment_is_image( $att_id ) ) {
							$meta = wp_get_attachment_metadata( $att_id );
							if ( is_array( $meta ) ) {
								MM_Job_Queue::enqueue_all_sizes( $att_id, $meta, 'metadata', [ 'trigger' => 'import' ] );
							}
						}
					}
				}
			}

			// Metadata write-back completed: queue a verification read-back so
			// the daemon re-reads the file and confirms all values stuck.
			// Only queue for the 'full' size to avoid a verification storm on images.
			if ( 'metadata' === ( $job['job_type'] ?? '' )
				&& 'full' === ( $job['size'] ?? '' )
				&& 'completed' === $status ) {
				$att_id = (int) ( $job['attachment_id'] ?? 0 );
				$file   = $att_id > 0 ? get_attached_file( $att_id ) : '';
				if ( $att_id > 0 && $file && file_exists( $file ) && MM_Status::exiftool_available() ) {
					MM_Job_Queue::write_job( 'import', $att_id, (string) $file, 'full', [ 'trigger' => 'verify' ] );
				}
			}

			if ( 'failed' === $status ) {
				$failed_jobs[] = $job;
			}

			// Clean up only after DB write is confirmed (the continue above
			// left the file in place for a retry).  wp_delete_file() silences
			// errors internally; safe if the file was already removed by a
			// concurrent request.
			wp_delete_file( $filepath );
		}
	}

	// Send one combined failure notification email if any jobs failed this run.
	if ( ! empty( $failed_jobs ) && MM_Settings::get_notify_enabled() ) {
		$to      = MM_Settings::get_notify_email();
		$subject = sprintf(
			/* translators: 1: number of failed jobs, 2: site name */
			__( '[Metamanager] %1$d job(s) failed on %2$s', 'metamanager' ),
			count( $failed_jobs ),
			wp_specialchars_decode( get_option( 'blogname', '' ), ENT_QUOTES )
		);
		$lines = [ 'The following Metamanager jobs failed:', '' ];
		foreach ( $failed_jobs as $job ) {
			$detail  = is_array( $job['details'] ?? null ) ? ( $job['details']['message'] ?? '' ) : '';
			$lines[] = '• ' . ( $job['image_name'] ?? '(unknown)' )
				. ' [' . ( $job['size'] ?? '' ) . ']'
				. ( $detail ? ' — ' . $detail : '' );
		}
		$lines[] = '';
		$lines[] = 'View the Job Dashboard: ' . admin_url( 'upload.php?page=metamanager-jobs' );

		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}
}

// ---------------------------------------------------------------------------
// Core hook registrations (single, authoritative source — no duplicates)
// ---------------------------------------------------------------------------

// Register custom attachment post meta — type safety, sanitisation, REST API.
add_action( 'init', [ 'MM_Metadata', 'register_meta' ] );

// After WordPress generates image sizes on upload, enqueue both job types.
add_filter( 'wp_generate_attachment_metadata', [ 'MM_Job_Queue', 'on_upload' ], 20, 2 );

// Upload receipt notifications (runs on front-end and admin; cron fires outside is_admin()).
MM_Upload_Notify::init();

// When attachment fields are saved in admin, enqueue metadata jobs only.
add_filter( 'attachment_fields_to_save', [ 'MM_Metadata', 'on_fields_save' ], 10, 2 );

// Extend media edit screen with our custom metadata fields.
add_filter( 'attachment_fields_to_edit', [ 'MM_Metadata', 'register_fields' ], 10, 2 );

// Clean up when an attachment is deleted.
add_action( 'delete_attachment', [ 'MM_Job_Queue', 'on_delete_attachment' ] );

// REST routes must register for all request types (not just admin pages).
// REST requests are not is_admin() context, so registering here is required.
add_action( 'rest_api_init', [ 'MM_Admin', 'register_rest_routes' ] );

// ---------------------------------------------------------------------------
// Boot admin
// ---------------------------------------------------------------------------

if ( is_admin() ) {
	MM_Admin::init();
	MM_Settings::init();
	MM_Updater::init();

	// Display the one-shot notice produced by the manual "Check for Updates" redirect.
	add_action( 'admin_notices', function (): void {
		if ( empty( $_GET['mm_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only GET param set by MM_Updater
		$type    = in_array( wp_unslash( $_GET['mm_notice_type'] ?? '' ), [ 'updated', 'error' ], true )
			? sanitize_key( wp_unslash( $_GET['mm_notice_type'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: 'updated';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below via sanitize_text_field
			$message = sanitize_text_field( urldecode( wp_unslash( $_GET['mm_notice'] ) ) );
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	} );
}
