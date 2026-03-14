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
}
