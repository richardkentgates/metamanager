<?php
/**
 * Integration tests for MM_JobQueue.
 *
 * Covers: job file creation, duplicate detection for compression jobs,
 * metadata job queuing order, and queue cleanup on attachment delete.
 *
 * Tests use a temporary job queue directory (isolated via MM_JOB_* constants
 * defined in tests/bootstrap.php) and clean up files after each test.
 *
 * @package Metamanager\Tests\Integration
 */

class Test_MM_JobQueue extends WP_UnitTestCase {

	/** Absolute paths of temporary media files created during a test. */
	private array $tmp_files = [];

	// -----------------------------------------------------------------------
	// Setup / teardown
	// -----------------------------------------------------------------------

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		// Ensure all queue directories exist for the test run.
		foreach ( [ MM_JOB_COMPRESS, MM_JOB_META, MM_JOB_DONE, MM_JOB_FAILED ] as $dir ) {
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0755, true );
			}
		}
	}

	public function tear_down(): void {
		// Remove all job files written during the test.
		foreach ( [ MM_JOB_COMPRESS, MM_JOB_META, MM_JOB_DONE, MM_JOB_FAILED ] as $dir ) {
			foreach ( glob( $dir . '*.json' ) ?: [] as $file ) {
				wp_delete_file( $file );
			}
		}
		// Remove temporary media files created by create_tmp_attachment().
		foreach ( $this->tmp_files as $file ) {
			wp_delete_file( $file );
		}
		$this->tmp_files = [];
		parent::tear_down();
	}

	public static function tear_down_after_class(): void {
		// Remove the test job queue root directory tree.
		if ( is_dir( MM_JOB_ROOT ) ) {
			$items = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( MM_JOB_ROOT, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $items as $item ) {
				$item->isDir() ? rmdir( $item->getRealPath() ) : wp_delete_file( $item->getRealPath() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			}
			rmdir( MM_JOB_ROOT ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		parent::tear_down_after_class();
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Count job files in a queue directory.
	 *
	 * @param  string $dir Queue directory path.
	 * @return int
	 */
	private function count_jobs( string $dir ): int {
		return count( glob( $dir . '*.json' ) ?: [] );
	}

	/**
	 * Create a real media file in the WP uploads dir and register it as a
	 * WordPress attachment.  Using a real file means file_exists() passes
	 * inside enqueue_all_sizes() and on_upload().
	 *
	 * @param  string $mime MIME type for the attachment (default image/jpeg).
	 * @return array{ 0: int, 1: string } [attachment_id, absolute file path]
	 */
	private function create_tmp_attachment( string $mime = 'image/jpeg' ): array {
		$ext = match ( $mime ) {
			'image/jpeg', 'image/jpg' => 'jpg',
			'image/png'               => 'png',
			'image/gif'               => 'gif',
			'video/mp4'               => 'mp4',
			'audio/mpeg'              => 'mp3',
			default                   => explode( '/', $mime )[1] ?? 'bin',
		};

		// Write to the system temp dir — always writable by the test-runner user.
		// get_attached_file() returns absolute paths (starting with '/') as-is
		// without prepending the WP uploads dir, so file_exists() passes inside
		// enqueue_all_sizes() and on_upload().
		$file_path = sys_get_temp_dir() . '/mm_test_' . uniqid() . '.' . $ext;

		// Write a minimal placeholder file so file_exists() passes.
		file_put_contents( $file_path, str_repeat( "\x00", 64 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$attachment_id = $this->factory->attachment->create( [ 'post_mime_type' => $mime ] );

		// Store the absolute path so get_attached_file() resolves it directly.
		update_post_meta( $attachment_id, '_wp_attached_file', $file_path );

		$this->tmp_files[] = $file_path;

		return [ $attachment_id, $file_path ];
	}

	// -----------------------------------------------------------------------
	// Queue creation
	// -----------------------------------------------------------------------

	public function test_write_compression_job_creates_file(): void {
		$attachment_id = $this->factory->attachment->create( [ 'post_mime_type' => 'image/jpeg' ] );

		MM_Job_Queue::write_job( 'compression', $attachment_id, '/tmp/photo.jpg', 'full' );

		$this->assertSame( 1, $this->count_jobs( MM_JOB_COMPRESS ) );
	}

	public function test_write_metadata_job_creates_file(): void {
		$attachment_id = $this->factory->attachment->create( [ 'post_mime_type' => 'image/jpeg' ] );

		MM_Job_Queue::write_job( 'metadata', $attachment_id, '/tmp/photo.jpg', 'full' );

		$this->assertSame( 1, $this->count_jobs( MM_JOB_META ) );
	}

	// -----------------------------------------------------------------------
	// Duplicate detection — compression
	// -----------------------------------------------------------------------

	public function test_duplicate_compression_job_is_skipped(): void {
		$attachment_id = $this->factory->attachment->create( [ 'post_mime_type' => 'image/jpeg' ] );

		$result1 = MM_Job_Queue::write_job( 'compression', $attachment_id, '/tmp/photo.jpg', 'full' );
		$result2 = MM_Job_Queue::write_job( 'compression', $attachment_id, '/tmp/photo.jpg', 'full' );

		$this->assertSame( 'written', $result1 );
		$this->assertSame( 'skipped', $result2 );
		// Still only one file in the queue.
		$this->assertSame( 1, $this->count_jobs( MM_JOB_COMPRESS ) );
	}

	public function test_different_sizes_are_not_duplicates(): void {
		$attachment_id = $this->factory->attachment->create( [ 'post_mime_type' => 'image/jpeg' ] );

		$result_full  = MM_Job_Queue::write_job( 'compression', $attachment_id, '/tmp/photo.jpg', 'full' );
		$result_thumb = MM_Job_Queue::write_job( 'compression', $attachment_id, '/tmp/photo-thumb.jpg', 'thumbnail' );

		$this->assertSame( 'written', $result_full );
		$this->assertSame( 'written', $result_thumb );
		$this->assertSame( 2, $this->count_jobs( MM_JOB_COMPRESS ) );
	}

	// -----------------------------------------------------------------------
	// Cleanup on attachment delete
	// -----------------------------------------------------------------------

	public function test_on_delete_attachment_removes_pending_jobs(): void {
		$attachment_id = $this->factory->attachment->create( [ 'post_mime_type' => 'image/jpeg' ] );

		MM_Job_Queue::write_job( 'compression', $attachment_id, '/tmp/photo.jpg', 'full' );
		MM_Job_Queue::write_job( 'metadata',    $attachment_id, '/tmp/photo.jpg', 'full' );

		$this->assertSame( 1, $this->count_jobs( MM_JOB_COMPRESS ) );
		$this->assertSame( 1, $this->count_jobs( MM_JOB_META ) );

		MM_Job_Queue::on_delete_attachment( $attachment_id );

		$this->assertSame( 0, $this->count_jobs( MM_JOB_COMPRESS ) );
		$this->assertSame( 0, $this->count_jobs( MM_JOB_META ) );
	}

	// -----------------------------------------------------------------------
	// Upload hook — on_upload(): fresh upload vs. thumbnail regeneration
	//
	// Fresh upload:   reads embedded metadata into WP, then queues BOTH
	//                 compression and metadata-embedding jobs.
	// Regeneration:   skips metadata import (preserves user edits), queues
	//                 only compression for the new/changed sizes.
	// -----------------------------------------------------------------------

	public function test_on_upload_fresh_image_queues_both_job_types(): void {
		[ $attachment_id ] = $this->create_tmp_attachment( 'image/jpeg' );

		// META_SYNCED is NOT set → treated as a fresh upload.
		MM_Job_Queue::on_upload( [], $attachment_id );

		$this->assertSame( 1, $this->count_jobs( MM_JOB_COMPRESS ),
			'Fresh image upload must queue a compression job.' );
		$this->assertSame( 1, $this->count_jobs( MM_JOB_META ),
			'Fresh image upload must queue a metadata-embedding job.' );
	}

	public function test_on_upload_regeneration_queues_only_compression(): void {
		[ $attachment_id ] = $this->create_tmp_attachment( 'image/jpeg' );

		// Mark as already synced to simulate thumbnail regeneration.
		update_post_meta( $attachment_id, MM_Metadata::META_SYNCED, 1 );

		MM_Job_Queue::on_upload( [], $attachment_id );

		$this->assertSame( 1, $this->count_jobs( MM_JOB_COMPRESS ),
			'Thumbnail regeneration must re-queue compression.' );
		$this->assertSame( 0, $this->count_jobs( MM_JOB_META ),
			'Thumbnail regeneration must NOT queue metadata (preserves user edits).' );
	}

	// -----------------------------------------------------------------------
	// Two-way sync: editing metadata in WP queues a metadata-embedding job
	//
	// When a user edits title/description/creator/copyright/etc. in the
	// Media Library, on_fields_save() must queue a metadata-embedding job so
	// the daemon writes the new values back into the image file.
	// -----------------------------------------------------------------------

	public function test_on_fields_save_queues_metadata_job_not_compression(): void {
		[ $attachment_id ] = $this->create_tmp_attachment( 'image/jpeg' );

		MM_Metadata::on_fields_save( [ 'ID' => $attachment_id ], [] );

		$this->assertGreaterThan( 0, $this->count_jobs( MM_JOB_META ),
			'Editing attachment fields must queue a metadata-embedding job.' );
		$this->assertSame( 0, $this->count_jobs( MM_JOB_COMPRESS ),
			'Editing attachment fields must NOT queue a compression job.' );
	}

	// -----------------------------------------------------------------------
	// Scan path: enqueue_all_sizes with type='both' produces both job types
	//
	// ajax_scan_library() now calls enqueue_all_sizes('both') for each
	// unsynced image — this test verifies that contract holds so scans and
	// uploads follow the same full pipeline.
	// -----------------------------------------------------------------------

	public function test_scan_enqueues_both_job_types_for_image(): void {
		[ $attachment_id ] = $this->create_tmp_attachment( 'image/jpeg' );

		MM_Job_Queue::enqueue_all_sizes( $attachment_id, [], 'both', [ 'trigger' => 'scan' ] );

		$this->assertSame( 1, $this->count_jobs( MM_JOB_COMPRESS ),
			'Library scan must queue a compression job for an image.' );
		$this->assertSame( 1, $this->count_jobs( MM_JOB_META ),
			'Library scan must queue a metadata-embedding job for an image.' );
	}
}
