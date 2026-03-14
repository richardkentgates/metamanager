<?php
/**
 * Uninstall Metamanager
 *
 * Runs when the plugin is deleted from "Plugins → Delete".
 * On single-site installs, data is removed only when the admin has opted in
 * via the "Remove all data on uninstall" setting.
 * On multisite networks, each blog's option is checked individually so only
 * sites that have opted in are cleaned up.
 *
 * @package Metamanager
 */

// WordPress sets this constant before running uninstall.php.  Any direct
// execution attempt is silently blocked.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

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
		'mm_api_disabled',
		'mm_api_allowed_ips',
		'mm_upload_notify_enabled',
		'mm_upload_notify_extra_email',
		'mm_failed_upload_notices',
		'mm_sitemap_media',
		'mm_sitemap_images',
		'mm_sitemap_video',
		'mm_sitemap_video_youtube',
		'mm_sitemap_video_vimeo',
		'mm_sitemap_video_selfhosted',
	];
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Transients used by the plugin.
	delete_transient( 'mm_github_latest_release' );
	delete_transient( 'mm_upload_batch' );

	// Wildcard transients: mm_oembed_{md5} (written by MM_Sitemap::get_cached_oembed()).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( '_transient_mm_oembed_' ) . '%'
		)
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( '_transient_timeout_mm_oembed_' ) . '%'
		)
	);

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
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

// ---------------------------------------------------------------------------
// Multisite: check option per-site; only clean up consenting sites.
// Single-site: check option once and proceed or bail.
// ---------------------------------------------------------------------------

$job_root    = WP_CONTENT_DIR . '/metamanager-jobs';
$any_deleted = false;

if ( is_multisite() ) {
	$sites = get_sites( [ 'number' => 0 ] );
	foreach ( $sites as $site ) {
		switch_to_blog( (int) $site->blog_id );
		if ( get_option( 'mm_delete_data_on_uninstall' ) ) {
			_mm_uninstall_site();
			$any_deleted = true;
		}
		restore_current_blog();
	}
} else {
	// Single-site: bail early if the admin chose to keep data.
	if ( ! get_option( 'mm_delete_data_on_uninstall' ) ) {
		return;
	}
	_mm_uninstall_site();
	$any_deleted = true;
}

if ( ! $any_deleted ) {
	return;
}

// ---------------------------------------------------------------------------
// Remove the shared job queue directory tree (outside wp-content is unlikely;
// WP_CONTENT_DIR is always available when uninstall.php runs).
// ---------------------------------------------------------------------------

/**
 * Recursively delete a directory and all its contents.
 * Uses wp_delete_file() for individual files so WordPress hooks are honoured.
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
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem has no recursive rmdir equivalent
			rmdir( $item->getRealPath() );
		} else {
			wp_delete_file( $item->getRealPath() );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem has no recursive rmdir equivalent
	rmdir( $dir );
}

_mm_rmdir_recursive( $job_root );

// ---------------------------------------------------------------------------
// Clear the cron event (may still be registered if plugin was not deactivated
// before deletion).
// ---------------------------------------------------------------------------

wp_clear_scheduled_hook( 'mm_import_completed_jobs' );
wp_clear_scheduled_hook( 'mm_send_upload_receipt' );
