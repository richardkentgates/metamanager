<?php
/**
 * Plugin Name:  Metamanager
 * Plugin URI:   https://github.com/richardkentgates/metamanager
 * Description:  [BETA — untested in production] Lossless image compression and standards-compliant metadata embedding (EXIF, IPTC, XMP) via OS-level daemons. Expands the WordPress Media Library with native metadata editing, bulk operations, and a real-time job dashboard.
 * Version:      1.5.4
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author:       Richard Kent Gates
 * Author URI:   https://github.com/richardkentgates
 * License:      GPLv3 or later
 * License URI:  https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:  metamanager
 * Domain Path:  /languages
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Plugin constants
// ---------------------------------------------------------------------------

define( 'MM_VERSION',     '1.5.4' );
define( 'MM_PLUGIN_FILE', __FILE__ );
define( 'MM_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'MM_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

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
 * Using /tmp lets www-data read them without elevated privileges,
 * avoiding the need for shell_exec( 'systemctl ...' ).
 */
define( 'MM_PID_COMPRESS', '/tmp/metamanager-compress-daemon.pid' );
define( 'MM_PID_META',     '/tmp/metamanager-meta-daemon.pid' );

// ---------------------------------------------------------------------------
// Autoload classes
// ---------------------------------------------------------------------------

require_once MM_PLUGIN_DIR . 'includes/class-mm-db.php';
require_once MM_PLUGIN_DIR . 'includes/class-mm-job-queue.php';
require_once MM_PLUGIN_DIR . 'includes/class-mm-metadata.php';
require_once MM_PLUGIN_DIR . 'includes/class-mm-status.php';
require_once MM_PLUGIN_DIR . 'includes/class-mm-settings.php';
require_once MM_PLUGIN_DIR . 'includes/class-mm-upload-notify.php';
require_once MM_PLUGIN_DIR . 'includes/class-mm-admin.php';
require_once MM_PLUGIN_DIR . 'includes/class-mm-updater.php';
require_once MM_PLUGIN_DIR . 'includes/class-mm-frontend.php';
require_once MM_PLUGIN_DIR . 'includes/class-mm-cli.php';

// Front-end schema / Open Graph output (not needed in admin context).
if ( ! is_admin() ) {
	MM_Frontend::init();
}

// ---------------------------------------------------------------------------
// Activation / deactivation
// ---------------------------------------------------------------------------

register_activation_hook( MM_PLUGIN_FILE, 'mm_activate' );
register_deactivation_hook( MM_PLUGIN_FILE, 'mm_deactivate' );

/**
 * Single-site activation routine: create DB table, job directories, schedule cron.
 */
function mm_activate_single_site(): void {
	MM_DB::create_or_update_table();
	MM_Job_Queue::ensure_dirs();

	if ( ! wp_next_scheduled( 'mm_import_completed_jobs' ) ) {
		wp_schedule_event( time(), 'mm_every_minute', 'mm_import_completed_jobs' );
	}
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

			if ( is_array( $job ) ) {
				$job['status'] = $status;
				MM_DB::log_job( $job );

				// Mark the attachment size as compressed so the Media Library
				// column and bulk-compress skip logic reflect the real state.
				if ( 'completed' === $status
					&& 'compression' === ( $job['job_type'] ?? '' )
					&& ! empty( $job['attachment_id'] )
				) {
					MM_Status::mark_compressed(
						(int) $job['attachment_id'],
						(string) ( $job['size'] ?? 'full' )
					);
				}

				if ( 'failed' === $status ) {
					$failed_jobs[] = $job;
				}
			}
			// wp_delete_file() silences errors internally; safe if the file was
			// already removed by a concurrent request.
			wp_delete_file( $filepath );
		}
	}

	// Send one combined failure notification email if any jobs failed this run.
	if ( ! empty( $failed_jobs ) && MM_Settings::get_notify_enabled() ) {
		$to      = MM_Settings::get_notify_email();
		$subject = sprintf(
			/* translators: %d: failed job count */
			__( '[Metamanager] %d job(s) failed on %s', 'metamanager' ),
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
		$type    = in_array( $_GET['mm_notice_type'] ?? '', [ 'updated', 'error' ], true )
			? sanitize_key( $_GET['mm_notice_type'] )
			: 'updated';
		$message = sanitize_text_field( urldecode( $_GET['mm_notice'] ) );
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	} );
}
