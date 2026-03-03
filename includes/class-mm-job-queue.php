<?php
/**
 * Metamanager Job Queue
 *
 * Single point of responsibility for writing, reading, and cleaning up job
 * files. PHP's only role in image processing is placing the job instruction
 * on the queue. The OS daemons do all image work.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MM_Job_Queue
 */
class MM_Job_Queue {

	// -----------------------------------------------------------------------
	// Filesystem helper
	// -----------------------------------------------------------------------

	/**
	 * Initialise and return the global WP_Filesystem object (direct method).
	 * Safe to call in any execution context — cron, admin, front-end uploads.
	 *
	 * @return WP_Filesystem_Base|null
	 */
	private static function get_filesystem(): ?WP_Filesystem_Base {
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		return $wp_filesystem instanceof WP_Filesystem_Base ? $wp_filesystem : null;
	}

	// -----------------------------------------------------------------------
	// Directory management
	// -----------------------------------------------------------------------

	/**
	 * Create all job queue directories if they do not exist.
	 * Called on plugin activation and defensively before writing.
	 */
	public static function ensure_dirs(): void {
		foreach ( [
			MM_JOB_COMPRESS,
			MM_JOB_META,
			MM_JOB_DONE,
			MM_JOB_FAILED,
		] as $dir ) {
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}

			// Drop an .htaccess in each queue dir to prevent direct HTTP access.
			$htaccess = trailingslashit( $dir ) . '.htaccess';
			if ( ! file_exists( $htaccess ) ) {
				$fs = self::get_filesystem();
				if ( $fs ) {
					$fs->put_contents( $htaccess, "Deny from all\n", FS_CHMOD_FILE );
				}
			}
		}
	}

	// -----------------------------------------------------------------------
	// Writing a single job file
	// -----------------------------------------------------------------------

	/**
	 * Write one job JSON file for a single image file.
	 *
	 * @param string $type          'compression' or 'metadata'.
	 * @param int    $attachment_id WordPress attachment ID.
	 * @param string $file_path     Absolute path to the image file.
	 * @param string $size          WP size slug (e.g. 'full', 'thumbnail').
	 * @param array  $extra         Optional additional fields merged into the job.
	 */
	public static function write_job(
		string $type,
		int $attachment_id,
		string $file_path,
		string $size = 'full',
		array $extra = []
	): void {
		self::ensure_dirs();

		$post = get_post( $attachment_id );

		// Dimensions from file — only valid for images; skip for video/audio.
		$dimensions = '';
		if ( file_exists( $file_path ) ) {
			$info = @getimagesize( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $info && isset( $info[0], $info[1] ) ) {
				$dimensions = $info[0] . 'x' . $info[1];
			}
		}

		$job = array_merge( [
			'attachment_id'  => $attachment_id,
			'image_name'     => $post ? $post->post_title : '',
			'job_type'       => $type,
			'file_path'      => $file_path,
			'size'           => $size,
			'dimensions'     => $dimensions,
			'submitted_at'   => current_time( 'mysql' ),
			'optimize_level' => (int) get_option( 'mm_compress_level', 2 ),
			'metadata'       => MM_Metadata::get_fields_for_job( $attachment_id ),
		], $extra );

		$dir      = ( 'compression' === $type ) ? MM_JOB_COMPRESS : MM_JOB_META;
		$uid      = wp_generate_password( 6, false, false );
		$filename = $dir . $attachment_id . '-' . $size . '-' . time() . '-' . $uid . '.json';

		$fs = self::get_filesystem();
		if ( $fs ) {
			$fs->put_contents( $filename, (string) wp_json_encode( $job ), FS_CHMOD_FILE );
		}
	}

	// -----------------------------------------------------------------------
	// Enqueue for all image sizes
	// -----------------------------------------------------------------------

	/**
	 * Enqueue one or both job types for the original image and all registered sizes.
	 *
	 * @param int    $attachment_id WordPress attachment ID.
	 * @param array  $meta          Attachment metadata (from wp_get_attachment_metadata).
	 * @param string $types         'both' | 'compression' | 'metadata'
	 * @param array  $extra         Extra fields passed to each job.
	 */
	public static function enqueue_all_sizes(
		int $attachment_id,
		array $meta = [],
		string $types = 'both',
		array $extra = []
	): void {
		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			return;
		}

		if ( ! $meta ) {
			$meta = wp_get_attachment_metadata( $attachment_id ) ?: [];
		}

		$queue_types = match ( $types ) {
			'compression' => [ 'compression' ],
			'metadata'    => [ 'metadata' ],
			default       => [ 'compression', 'metadata' ],
		};

		// Full / original image.
		foreach ( $queue_types as $type ) {
			self::write_job( $type, $attachment_id, $file, 'full', $extra );
		}

		// All generated sizes.
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$dir = trailingslashit( pathinfo( $file, PATHINFO_DIRNAME ) );
			foreach ( $meta['sizes'] as $size => $info ) {
				if ( empty( $info['file'] ) ) {
					continue;
				}
				$img_path = $dir . $info['file'];
				if ( ! file_exists( $img_path ) ) {
					continue;
				}
				foreach ( $queue_types as $type ) {
					self::write_job( $type, $attachment_id, $img_path, $size, $extra );
				}
			}
		}
	}

	// -----------------------------------------------------------------------
	// Hook handlers (called from metamanager.php — no duplicate registrations)
	// -----------------------------------------------------------------------

	/**
	 * wp_generate_attachment_metadata hook: enqueue both job types on upload.
	 *
	 * On a fresh upload (mm_meta_synced not yet set) both metadata import and
	 * compression are queued. On thumbnail regeneration (mm_meta_synced already
	 * set) only compression is re-queued for sizes that have changed; metadata
	 * import is skipped to avoid overwriting user edits.
	 *
	 * @param array $metadata      WordPress-generated metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array Unmodified metadata (we are a passthrough filter).
	 */
	public static function on_upload( array $metadata, int $attachment_id ): array {
		$mime = (string) get_post_mime_type( $attachment_id );
		$is_image = wp_attachment_is_image( $attachment_id );
		$is_video = MM_Metadata::is_video_mime( $mime );
		$is_audio = MM_Metadata::is_audio_mime( $mime );
		$is_pdf   = MM_Metadata::is_pdf_mime( $mime );

		if ( ! $is_image && ! $is_video && ! $is_audio && ! $is_pdf ) {
			return $metadata;
		}

		$is_regeneration = '1' === get_post_meta( $attachment_id, MM_Metadata::META_SYNCED, true );

		if ( $is_image ) {
			// ---- Images: existing regen-aware logic ----
			if ( $is_regeneration ) {
				delete_post_meta( $attachment_id, '_mm_compressed_full' );
				if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
					foreach ( array_keys( $metadata['sizes'] ) as $size ) {
						delete_post_meta( $attachment_id, '_mm_compressed_' . $size );
					}
				}
				self::enqueue_all_sizes( $attachment_id, $metadata, 'compression', [ 'trigger' => 'thumbnail_regen' ] );
			} else {
				MM_Metadata::import_from_file( $attachment_id );
				self::enqueue_all_sizes( $attachment_id, $metadata, 'both', [ 'trigger' => 'upload' ] );
			}
			return $metadata;
		}

		// ---- Video / Audio / PDF: single-file handling ----
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return $metadata;
		}

		if ( ! $is_regeneration ) {
			MM_Metadata::import_from_file( $attachment_id );
		}

		// Queue metadata write-back if the format supports it.
		if ( MM_Metadata::can_write_meta( $mime ) ) {
			self::write_job( 'metadata', $attachment_id, $file, 'full', [ 'trigger' => 'upload' ] );
		}

		// Queue video remux (container repack, lossless) — audio and PDF have no remux.
		if ( $is_video ) {
			self::write_job( 'compression', $attachment_id, $file, 'full', [ 'trigger' => 'upload', 'is_remux' => true ] );
		}

		return $metadata;
	}

	/**
	 * delete_attachment hook: remove queued job files and compression meta.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function on_delete_attachment( int $attachment_id ): void {
		// Remove any unprocessed job files for this attachment.
		foreach ( [ MM_JOB_COMPRESS, MM_JOB_META ] as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			foreach ( glob( $dir . $attachment_id . '-*.json' ) ?: [] as $file ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors
				@unlink( $file );
			}
		}

		// Remove compression status post meta.
		$meta = wp_get_attachment_metadata( $attachment_id );
		delete_post_meta( $attachment_id, '_mm_compressed_full' );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( array_keys( $meta['sizes'] ) as $size ) {
				delete_post_meta( $attachment_id, '_mm_compressed_' . $size );
			}
		}

		// Remove custom metadata fields.
		foreach ( [ 'mm_creator', 'mm_copyright', 'mm_owner' ] as $key ) {
			delete_post_meta( $attachment_id, $key );
		}
	}

	// -----------------------------------------------------------------------
	// Reading queued jobs (for dashboard display)
	// -----------------------------------------------------------------------

	/**
	 * Return all pending jobs from compress and meta directories.
	 *
	 * @return array[ 'compression' => [...], 'metadata' => [...] ]
	 */
	public static function get_pending_jobs(): array {
		$result = [ 'compression' => [], 'metadata' => [] ];

		foreach ( [
			'compression' => MM_JOB_COMPRESS,
			'metadata'    => MM_JOB_META,
		] as $type => $dir ) {
			foreach ( glob( $dir . '*.json' ) ?: [] as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$job = json_decode( file_get_contents( $file ), true );
				if ( ! is_array( $job ) ) {
					continue;
				}
				$job['_queued_at']  = (int) filemtime( $file );
				$job['_queue_file'] = basename( $file );
				$job['job_type']    = $type;
				$result[ $type ][]  = $job;
			}
		}

		return $result;
	}

	// -----------------------------------------------------------------------
	// Re-queue from history
	// -----------------------------------------------------------------------

	/**
	 * Re-enqueue a job from the history table (e.g. to retry a failed job).
	 *
	 * @param int $job_id DB row ID.
	 * @return bool True if the job file was written.
	 */
	public static function requeue( int $job_id ): bool {
		$row = MM_DB::get_job( $job_id );
		if ( ! $row ) {
			return false;
		}

		if ( ! file_exists( $row->file_path ) ) {
			return false;
		}

		self::write_job(
			$row->job_type,
			(int) $row->attachment_id,
			$row->file_path,
			$row->size,
			[ 'trigger' => 'requeue', 're queue_source_id' => $job_id ]
		);

		return true;
	}
}
