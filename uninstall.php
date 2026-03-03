<?php
/**
 * Uninstall Metamanager
 *
 * Runs when the plugin is deleted from "Plugins → Delete".
 * Only removes data when the admin has opted in via the
 * "Remove all data on uninstall" setting.
 *
 * @package Metamanager
 */

// WordPress sets this constant before running uninstall.php.  Any direct
// execution attempt is silently blocked.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// If the user chose to keep data, do nothing.
if ( ! get_option( 'mm_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;

// ---------------------------------------------------------------------------
// Helper: remove all data for the current blog / site context.
// ---------------------------------------------------------------------------

/**
 * Delete options, post meta, transients, and DB table for the current site.
 */
function _mm_uninstall_site(): void {
	global $wpdb;

	// Options registered by the plugin.
	$options = [
		'mm_compress_level',
		'mm_notify_enabled',
		'mm_notify_email',
		'mm_delete_data_on_uninstall',
	];
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Transient used by the GitHub updater.
	delete_transient( 'mm_github_latest_release' );

	// Exact post meta keys.
	$meta_keys = [
		'mm_creator',
		'mm_copyright',
		'mm_owner',
		'mm_headline',
		'mm_credit',
		'mm_keywords',
		'mm_date_created',
		'mm_location_city',
		'mm_location_state',
		'mm_location_country',
		'mm_rating',
		'mm_gps_lat',
		'mm_gps_lon',
		'mm_gps_alt',
		'mm_meta_synced',
		'_mm_compressed_full',
	];
	foreach ( $meta_keys as $key ) {
		delete_post_meta_by_key( $key );
	}

	// Wildcard meta keys: _mm_compressed_{size} (written by class-mm-status.php).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( '_mm_compressed_' ) . '%'
		)
	);

	// Drop the plugin DB table.
	$table = $wpdb->prefix . 'metamanager_jobs';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

// ---------------------------------------------------------------------------
// Multisite: run cleanup for every site; single-site: run once.
// ---------------------------------------------------------------------------

if ( is_multisite() ) {
	$sites = get_sites( [ 'number' => 0 ] );
	foreach ( $sites as $site ) {
		switch_to_blog( (int) $site->blog_id );
		_mm_uninstall_site();
		restore_current_blog();
	}
} else {
	_mm_uninstall_site();
}

// ---------------------------------------------------------------------------
// Remove the shared job queue directory tree (outside wp-content is unlikely;
// WP_CONTENT_DIR is always available when uninstall.php runs).
// ---------------------------------------------------------------------------

/**
 * Recursively delete a directory and all its contents.
 *
 * @param string $dir Absolute path to directory.
 */
function _mm_rmdir_recursive( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		if ( $item->isDir() ) {
			rmdir( $item->getRealPath() );
		} else {
			wp_delete_file( $item->getRealPath() );
		}
	}
	rmdir( $dir );
}

$job_root = WP_CONTENT_DIR . '/metamanager-jobs';
_mm_rmdir_recursive( $job_root );

// ---------------------------------------------------------------------------
// Clear the cron event (may still be registered if plugin was not deactivated
// before deletion).
// ---------------------------------------------------------------------------

wp_clear_scheduled_hook( 'mm_import_completed_jobs' );
