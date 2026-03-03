<?php
/**
 * Metamanager WP-CLI Commands
 *
 * Provides CLI access to Metamanager operations:
 *
 *   wp metamanager compress <id|all>  — queue lossless compression
 *   wp metamanager import  <id|all>   — import embedded metadata into WP fields
 *   wp metamanager queue   status     — show live queue statistics
 *   wp metamanager scan               — batch-import metadata for un-synced library
 *   wp metamanager stats              — show compression savings statistics
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage Metamanager image processing jobs.
 */
class MM_CLI extends \WP_CLI_Command {

	// -----------------------------------------------------------------------
	// compress
	// -----------------------------------------------------------------------

	/**
	 * Queue lossless compression for one or all image attachments.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Attachment ID to compress.  Omit (or pass "all") to compress everything.
	 *
	 * [--force]
	 * : Re-queue even if the image has already been compressed.
	 *
	 * ## EXAMPLES
	 *
	 *     wp metamanager compress 42
	 *     wp metamanager compress all
	 *     wp metamanager compress all --force
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function compress( array $args, array $assoc_args ): void {
		$force = isset( $assoc_args['force'] );
		$target = $args[0] ?? 'all';

		if ( 'all' === strtolower( $target ) ) {
			$ids = get_posts( [
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'numberposts'    => -1,
				'fields'         => 'ids',
			] );
			if ( empty( $ids ) ) {
				\WP_CLI::success( 'No images found.' );
				return;
			}
		} else {
			$ids = [ (int) $target ];
			if ( ! wp_attachment_is_image( $ids[0] ) ) {
				\WP_CLI::error( "Attachment {$ids[0]} is not an image." );
				return;
			}
		}

		$count   = 0;
		$skipped = 0;
		$progress = \WP_CLI\Utils\make_progress_bar( 'Queuing compression', count( $ids ) );

		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$meta = wp_get_attachment_metadata( $id ) ?: [];
			$file = get_attached_file( $id );

			if ( ! $file || ! file_exists( $file ) ) {
				++$skipped;
				$progress->tick();
				continue;
			}

			if ( ! $force ) {
				if ( $file && MM_Status::is_compressed( $id, 'full' ) ) {
					++$skipped;
					$progress->tick();
					continue;
				}
			}

			MM_Job_Queue::enqueue_all_sizes( $id, $meta, 'compression', [ 'trigger' => 'cli' ] );
			++$count;
			$progress->tick();
		}

		$progress->finish();
		\WP_CLI::success( "Queued compression for {$count} image(s). Skipped: {$skipped}." );
	}

	// -----------------------------------------------------------------------
	// import
	// -----------------------------------------------------------------------

	/**
	 * Import embedded metadata (EXIF/IPTC/XMP) into WordPress fields.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Attachment ID to process.  Omit (or pass "all") to process everything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp metamanager import 42
	 *     wp metamanager import all
	 *
	 * @param array $args Positional arguments.
	 */
	public function import( array $args ): void {
		$target = $args[0] ?? 'all';

		if ( 'all' === strtolower( $target ) ) {
			$ids = get_posts( [
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'numberposts'    => -1,
				'fields'         => 'ids',
			] );
			if ( empty( $ids ) ) {
				\WP_CLI::success( 'No images found.' );
				return;
			}
		} else {
			$ids = [ (int) $target ];
			if ( ! wp_attachment_is_image( $ids[0] ) ) {
				\WP_CLI::error( "Attachment {$ids[0]} is not an image." );
				return;
			}
		}

		$count    = 0;
		$progress = \WP_CLI\Utils\make_progress_bar( 'Importing metadata', count( $ids ) );

		foreach ( $ids as $id ) {
			MM_Metadata::import_from_file( (int) $id );
			++$count;
			$progress->tick();
		}

		$progress->finish();
		\WP_CLI::success( "Imported metadata for {$count} image(s)." );
	}

	// -----------------------------------------------------------------------
	// queue
	// -----------------------------------------------------------------------

	/**
	 * Show live job queue status.
	 *
	 * ## SUBCOMMANDS
	 *
	 *   status   Print pending job counts by type.
	 *
	 * ## EXAMPLES
	 *
	 *     wp metamanager queue status
	 *
	 * @param array $args Positional arguments (first: subcommand).
	 */
	public function queue( array $args ): void {
		$sub = $args[0] ?? 'status';

		if ( 'status' === $sub ) {
			$pending = MM_Job_Queue::get_pending_jobs();
			$comp    = count( $pending['compression'] );
			$meta    = count( $pending['metadata'] );
			$total   = $comp + $meta;

			\WP_CLI\Utils\format_items(
				'table',
				[
					[ 'Type' => 'Compression', 'Pending' => $comp ],
					[ 'Type' => 'Metadata',    'Pending' => $meta ],
					[ 'Type' => 'Total',       'Pending' => $total ],
				],
				[ 'Type', 'Pending' ]
			);

			$compress_running = MM_Status::compress_daemon_running();
			$meta_running     = MM_Status::meta_daemon_running();
			\WP_CLI::line( 'Compress daemon: ' . ( $compress_running ? "\033[0;32mrunning\033[0m" : "\033[0;31mstopped\033[0m" ) );
			\WP_CLI::line( 'Metadata daemon: ' . ( $meta_running     ? "\033[0;32mrunning\033[0m" : "\033[0;31mstopped\033[0m" ) );
		} else {
			\WP_CLI::error( "Unknown subcommand: {$sub}. Valid: status" );
		}
	}

	// -----------------------------------------------------------------------
	// scan
	// -----------------------------------------------------------------------

	/**
	 * Import metadata for every library image not yet processed by Metamanager.
	 *
	 * ## EXAMPLES
	 *
	 *     wp metamanager scan
	 *
	 * @param array $args Positional arguments (unused).
	 */
	public function scan( array $args ): void {
		$ids = get_posts( [
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'numberposts'    => -1,
			'fields'         => 'ids',
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery
				[
					'key'     => MM_Metadata::META_SYNCED,
					'compare' => 'NOT EXISTS',
				],
			],
		] );

		if ( empty( $ids ) ) {
			\WP_CLI::success( 'All images already scanned — nothing to do.' );
			return;
		}

		$count    = 0;
		$progress = \WP_CLI\Utils\make_progress_bar( 'Scanning library', count( $ids ) );

		foreach ( $ids as $id ) {
			MM_Metadata::import_from_file( (int) $id );
			++$count;
			$progress->tick();
		}

		$progress->finish();
		\WP_CLI::success( "Scanned and imported metadata for {$count} image(s)." );
	}

	// -----------------------------------------------------------------------
	// stats
	// -----------------------------------------------------------------------

	/**
	 * Show compression savings statistics from the job history.
	 *
	 * ## EXAMPLES
	 *
	 *     wp metamanager stats
	 *
	 * @param array $args Positional arguments (unused).
	 */
	public function stats( array $args ): void {
		$stats = MM_DB::get_stats();

		$bytes_saved    = (int) ( $stats['bytes_saved']    ?? 0 );
		$bytes_original = (int) ( $stats['bytes_original'] ?? 0 );
		$pct            = $bytes_original > 0
			? round( $bytes_saved / $bytes_original * 100, 1 )
			: 0.0;

		\WP_CLI\Utils\format_items(
			'table',
			[
				[ 'Metric' => 'Total jobs',          'Value' => number_format( (int) $stats['total_jobs'] ) ],
				[ 'Metric' => 'Completed',           'Value' => number_format( (int) $stats['completed'] ) ],
				[ 'Metric' => 'Failed',              'Value' => number_format( (int) $stats['failed'] ) ],
				[ 'Metric' => 'Unique attachments',  'Value' => number_format( (int) $stats['unique_attachments'] ) ],
				[ 'Metric' => 'Bytes saved',         'Value' => size_format( $bytes_saved ) . " ({$pct}%)" ],
			],
			[ 'Metric', 'Value' ]
		);
	}
}

\WP_CLI::add_command( 'metamanager', 'MM_CLI' );
