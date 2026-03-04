<?php
/**
 * Metamanager WP-CLI Commands
 *
 * Provides CLI access to Metamanager operations:
 *
 *   wp metamanager compress <id|all>  — queue lossless compression (images + video remux)
 *   wp metamanager import  <id|all>   — import embedded metadata into WP fields (all supported types)
 *   wp metamanager queue   status     — show live queue statistics
 *   wp metamanager scan               — batch-import metadata for un-synced library (all supported types)
 *   wp metamanager stats              — show compression savings statistics
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage Metamanager media processing jobs.
 */
class MM_CLI extends \WP_CLI_Command {

	// -----------------------------------------------------------------------
	// compress
	// -----------------------------------------------------------------------

	/**
	 * Queue lossless compression for one or all compressible attachments.
	 *
	 * Images (JPEG, PNG, WebP, GIF, TIFF) are recompressed losslessly.
	 * Video files are remuxed losslessly via ffmpeg.
	 * Audio files and PDFs do not have a compression step and are skipped.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Attachment ID to compress.  Omit (or pass "all") to process everything compressible.
	 *
	 * [--force]
	 * : Re-queue even if the file has already been compressed.
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
		$force  = isset( $assoc_args['force'] );
		$target = $args[0] ?? 'all';

		if ( 'all' === strtolower( $target ) ) {
			// Images + video are compressible; audio and PDF have no compression step.
			$compressible_mimes = array_merge(
				[ 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/tiff' ],
				MM_Metadata::VIDEO_MIME_TYPES
			);
			$ids = get_posts( [
				'post_type'      => 'attachment',
				'post_mime_type' => $compressible_mimes,
				'post_status'    => 'inherit',
				'numberposts'    => -1,
				'fields'         => 'ids',
			] );
			if ( empty( $ids ) ) {
				\WP_CLI::success( 'No compressible files found.' );
				return;
			}
		} else {
			$ids  = [ (int) $target ];
			$mime = (string) get_post_mime_type( $ids[0] );
			if ( ! wp_attachment_is_image( $ids[0] ) && ! MM_Metadata::is_video_mime( $mime ) ) {
				$reason = MM_Metadata::is_audio_mime( $mime ) || MM_Metadata::is_pdf_mime( $mime )
					? 'Audio files and PDFs have no compression step. Use `wp metamanager import` for metadata.'
					: 'Attachment is not a compressible file type.';
				\WP_CLI::error( $reason );
				return;
			}
		}

		$count    = 0;
		$skipped  = 0;
		$progress = \WP_CLI\Utils\make_progress_bar( 'Queuing compression', count( $ids ) );

		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$mime = (string) get_post_mime_type( $id );
			$file = get_attached_file( $id );

			if ( ! $file || ! file_exists( $file ) ) {
				++$skipped;
				$progress->tick();
				continue;
			}

			if ( ! $force && MM_Status::is_compressed( $id, 'full' ) ) {
				++$skipped;
				$progress->tick();
				continue;
			}

			if ( MM_Metadata::is_video_mime( $mime ) ) {
				// Video: single lossless remux job.
				MM_Job_Queue::write_job( 'compression', $id, $file, 'full', [ 'trigger' => 'cli' ] );
			} else {
				// Image: queue all registered sizes.
				$meta = wp_get_attachment_metadata( $id ) ?: [];
				MM_Job_Queue::enqueue_all_sizes( $id, $meta, 'compression', [ 'trigger' => 'cli' ] );
			}

			++$count;
			$progress->tick();
		}

		$progress->finish();
		\WP_CLI::success( "Queued compression for {$count} file(s). Skipped: {$skipped}." );
	}

	// -----------------------------------------------------------------------
	// import
	// -----------------------------------------------------------------------

	/**
	 * Import embedded metadata into WordPress fields.
	 *
	 * Reads embedded tags from the file using ExifTool and populates empty
	 * WordPress fields.  Works for images (EXIF/IPTC/XMP), video (QuickTime
	 * atoms, XMP), audio (ID3, Vorbis comments, XMP), and PDF (XMP).
	 * Existing user-set values are never overwritten.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Attachment ID to process.  Omit (or pass "all") to process all supported files.
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
			$supported_mimes = array_merge(
				[ 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/tiff' ],
				MM_Metadata::VIDEO_MIME_TYPES,
				MM_Metadata::AUDIO_MIME_TYPES,
				MM_Metadata::PDF_MIME_TYPES
			);
			$ids = get_posts( [
				'post_type'      => 'attachment',
				'post_mime_type' => $supported_mimes,
				'post_status'    => 'inherit',
				'numberposts'    => -1,
				'fields'         => 'ids',
			] );
			if ( empty( $ids ) ) {
				\WP_CLI::success( 'No supported files found.' );
				return;
			}
		} else {
			$ids  = [ (int) $target ];
			$mime = (string) get_post_mime_type( $ids[0] );
			if (
				! wp_attachment_is_image( $ids[0] ) &&
				! MM_Metadata::is_av_mime( $mime ) &&
				! MM_Metadata::is_pdf_mime( $mime )
			) {
				\WP_CLI::error( "Attachment {$ids[0]} is not a supported file type for metadata import." );
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
		\WP_CLI::success( "Imported metadata for {$count} file(s)." );
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
	 * Import metadata for every library file not yet processed by Metamanager.
	 *
	 * Scans all supported attachments (images, video, audio, PDF) that have
	 * never been synced and imports their embedded metadata into WordPress
	 * fields.  Already-synced files are skipped automatically.
	 *
	 * ## EXAMPLES
	 *
	 *     wp metamanager scan
	 *
	 * @param array $args Positional arguments (unused).
	 */
	public function scan( array $args ): void {
		$supported_mimes = array_merge(
			[ 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/tiff' ],
			MM_Metadata::VIDEO_MIME_TYPES,
			MM_Metadata::AUDIO_MIME_TYPES,
			MM_Metadata::PDF_MIME_TYPES
		);

		$ids = get_posts( [
			'post_type'      => 'attachment',
			'post_mime_type' => $supported_mimes,
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
			\WP_CLI::success( 'All supported files already scanned — nothing to do.' );
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
		\WP_CLI::success( "Scanned and imported metadata for {$count} file(s)." );
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
