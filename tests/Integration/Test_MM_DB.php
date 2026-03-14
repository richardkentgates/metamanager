<?php
/**
 * Integration tests for MM_DB.
 *
 * Covers: table lifecycle, log_job() upsert behaviour, get_jobs() filtering
 * and pagination, get_stats(), and delete_jobs_for_attachment().
 *
 * Every test runs inside the WP test suite's transaction wrapper — inserts
 * are rolled back automatically after each test, so tests are fully isolated.
 * The table itself is created once before the class and dropped after.
 *
 * @package Metamanager\Tests\Integration
 */

class Test_MM_DB extends WP_UnitTestCase {

	// -----------------------------------------------------------------------
	// Schema lifecycle
	// -----------------------------------------------------------------------

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		MM_DB::create_or_update_table();
	}

	public static function tear_down_after_class(): void {
		MM_DB::drop_table();
		parent::tear_down_after_class();
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Build a minimal valid job data array.
	 *
	 * @param  array $overrides Field overrides.
	 * @return array
	 */
	private function make_job( array $overrides = [] ): array {
		return array_merge(
			[
				'attachment_id' => 1,
				'image_name'    => 'test-image.jpg',
				'job_type'      => 'compression',
				'file_path'     => '/srv/www/uploads/test-image.jpg',
				'size'          => 'full',
				'dimensions'    => '1920x1080',
				'bytes_before'  => 200000,
				'bytes_after'   => 160000,
				'status'        => 'completed',
				'submitted_at'  => current_time( 'mysql' ),
				'completed_at'  => current_time( 'mysql' ),
			],
			$overrides
		);
	}

	// -----------------------------------------------------------------------
	// log_job() — insert
	// -----------------------------------------------------------------------

	public function test_log_job_returns_true_on_success(): void {
		$result = MM_DB::log_job( $this->make_job() );
		$this->assertTrue( $result );
	}

	public function test_log_job_creates_a_row(): void {
		MM_DB::log_job( $this->make_job() );
		$data = MM_DB::get_jobs();
		$this->assertCount( 1, $data['jobs'] );
		$this->assertSame( 1, $data['total'] );
	}

	// -----------------------------------------------------------------------
	// log_job() — upsert behaviour
	// -----------------------------------------------------------------------

	public function test_log_job_upserts_same_attachment_job_type_size(): void {
		// Insert once, then insert again with the same (attachment_id, job_type, size).
		MM_DB::log_job( $this->make_job( [ 'bytes_before' => 200000, 'bytes_after' => 160000 ] ) );
		MM_DB::log_job( $this->make_job( [ 'bytes_before' => 180000, 'bytes_after' => 140000 ] ) );

		$data = MM_DB::get_jobs();
		// Only one row should remain.
		$this->assertCount( 1, $data['jobs'] );
		$this->assertSame( 1, $data['total'] );
		// The row should reflect the second insert.
		$this->assertSame( '140000', $data['jobs'][0]->bytes_after );
	}

	public function test_log_job_allows_different_sizes_for_same_attachment(): void {
		MM_DB::log_job( $this->make_job( [ 'size' => 'full' ] ) );
		MM_DB::log_job( $this->make_job( [ 'size' => 'thumbnail' ] ) );

		$data = MM_DB::get_jobs();
		$this->assertCount( 2, $data['jobs'] );
		$this->assertSame( 2, $data['total'] );
	}

	public function test_log_job_allows_different_types_for_same_attachment(): void {
		MM_DB::log_job( $this->make_job( [ 'job_type' => 'compression' ] ) );
		MM_DB::log_job( $this->make_job( [ 'job_type' => 'metadata' ] ) );

		$data = MM_DB::get_jobs();
		$this->assertCount( 2, $data['jobs'] );
	}

	public function test_log_job_orphan_rows_are_appended_not_upserted(): void {
		// attachment_id = 0 (orphan) should never be de-duped.
		MM_DB::log_job( $this->make_job( [ 'attachment_id' => 0 ] ) );
		MM_DB::log_job( $this->make_job( [ 'attachment_id' => 0 ] ) );

		$data = MM_DB::get_jobs();
		$this->assertCount( 2, $data['jobs'] );
	}

	// -----------------------------------------------------------------------
	// get_jobs() — filtering & pagination
	// -----------------------------------------------------------------------

	public function test_get_jobs_returns_all_by_default(): void {
		MM_DB::log_job( $this->make_job( [ 'attachment_id' => 1, 'size' => 'full' ] ) );
		MM_DB::log_job( $this->make_job( [ 'attachment_id' => 2, 'size' => 'full' ] ) );
		MM_DB::log_job( $this->make_job( [ 'attachment_id' => 3, 'size' => 'full' ] ) );

		$data = MM_DB::get_jobs();
		$this->assertCount( 3, $data['jobs'] );
		$this->assertSame( 3, $data['total'] );
	}

	public function test_get_jobs_paginates_correctly(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			MM_DB::log_job( $this->make_job( [ 'attachment_id' => $i, 'size' => 'full' ] ) );
		}

		$page1 = MM_DB::get_jobs( [ 'per_page' => 2, 'paged' => 1 ] );
		$page2 = MM_DB::get_jobs( [ 'per_page' => 2, 'paged' => 2 ] );
		$page3 = MM_DB::get_jobs( [ 'per_page' => 2, 'paged' => 3 ] );

		$this->assertCount( 2, $page1['jobs'] );
		$this->assertCount( 2, $page2['jobs'] );
		$this->assertCount( 1, $page3['jobs'] );
		$this->assertSame( 5, $page1['total'] );
	}

	public function test_get_jobs_search_filters_by_image_name(): void {
		MM_DB::log_job( $this->make_job( [ 'attachment_id' => 1, 'size' => 'full', 'image_name' => 'holiday-photo.jpg' ] ) );
		MM_DB::log_job( $this->make_job( [ 'attachment_id' => 2, 'size' => 'full', 'image_name' => 'office-headshot.jpg' ] ) );

		$data = MM_DB::get_jobs( [ 'search' => 'holiday' ] );
		$this->assertCount( 1, $data['jobs'] );
		$this->assertSame( 'holiday-photo.jpg', $data['jobs'][0]->image_name );
	}

	public function test_get_jobs_search_filters_by_job_type(): void {
		MM_DB::log_job( $this->make_job( [ 'attachment_id' => 1, 'size' => 'full', 'job_type' => 'compression' ] ) );
		MM_DB::log_job( $this->make_job( [ 'attachment_id' => 2, 'size' => 'full', 'job_type' => 'metadata' ] ) );

		$data = MM_DB::get_jobs( [ 'search' => 'metadata' ] );
		$this->assertCount( 1, $data['jobs'] );
		$this->assertSame( 'metadata', $data['jobs'][0]->job_type );
	}

	public function test_get_jobs_sort_order_asc(): void {
		MM_DB::log_job( $this->make_job( [ 'attachment_id' => 10, 'size' => 'full' ] ) );
		MM_DB::log_job( $this->make_job( [ 'attachment_id' => 20, 'size' => 'full' ] ) );

		$data = MM_DB::get_jobs( [ 'orderby' => 'id', 'order' => 'ASC' ] );
		$ids  = array_column( $data['jobs'], 'id' );
		$this->assertSame( $ids, array_values( $ids ) ); // already sorted ascending
		$this->assertLessThan( (int) $ids[1], (int) $ids[0] );
	}

	public function test_get_jobs_rejects_invalid_orderby(): void {
		// An invalid orderby column should default to 'id' (no SQL injection possible).
		MM_DB::log_job( $this->make_job() );
		$data = MM_DB::get_jobs( [ 'orderby' => 'DROP TABLE' ] );
		$this->assertCount( 1, $data['jobs'] ); // query ran without error
	}

	// -----------------------------------------------------------------------
	// get_job()
	// -----------------------------------------------------------------------

	public function test_get_job_returns_correct_row(): void {
		global $wpdb;
		MM_DB::log_job( $this->make_job() );
		$table = $wpdb->prefix . MM_JOB_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id  = (int) $wpdb->get_var( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1" );

		$row = MM_DB::get_job( $id );
		$this->assertNotNull( $row );
		$this->assertSame( $id, (int) $row->id );
		$this->assertSame( 'test-image.jpg', $row->image_name );
	}

	public function test_get_job_returns_null_for_unknown_id(): void {
		$this->assertNull( MM_DB::get_job( 999999 ) );
	}

	// -----------------------------------------------------------------------
	// get_stats()
	// -----------------------------------------------------------------------

	public function test_get_stats_returns_correct_counts(): void {
		MM_DB::log_job( $this->make_job( [ 'attachment_id' => 1, 'size' => 'full',  'status' => 'completed', 'bytes_before' => 100000, 'bytes_after' => 80000 ] ) );
		MM_DB::log_job( $this->make_job( [ 'attachment_id' => 2, 'size' => 'full',  'status' => 'completed', 'bytes_before' => 50000,  'bytes_after' => 40000 ] ) );
		MM_DB::log_job( $this->make_job( [ 'attachment_id' => 3, 'size' => 'full',  'status' => 'failed',    'bytes_before' => 0,      'bytes_after' => 0 ] ) );

		$stats = MM_DB::get_stats();

		$this->assertSame( 3, (int) $stats['total_jobs'] );
		$this->assertSame( 2, (int) $stats['completed'] );
		$this->assertSame( 1, (int) $stats['failed'] );
		$this->assertSame( 3, (int) $stats['unique_attachments'] );
		$this->assertSame( 30000, (int) $stats['bytes_saved'] );  // (100000-80000) + (50000-40000)
		$this->assertSame( 150000, (int) $stats['bytes_original'] );
	}

	public function test_get_stats_returns_zeros_when_empty(): void {
		$stats = MM_DB::get_stats();
		$this->assertSame( 0, (int) $stats['total_jobs'] );
		$this->assertSame( 0, (int) $stats['bytes_saved'] );
	}

	// -----------------------------------------------------------------------
	// delete_jobs_for_attachment()
	// -----------------------------------------------------------------------

	public function test_delete_jobs_for_attachment_removes_all_its_rows(): void {
		MM_DB::log_job( $this->make_job( [ 'attachment_id' => 42, 'size' => 'full' ] ) );
		MM_DB::log_job( $this->make_job( [ 'attachment_id' => 42, 'size' => 'thumbnail' ] ) );
		MM_DB::log_job( $this->make_job( [ 'attachment_id' => 99, 'size' => 'full' ] ) );

		MM_DB::delete_jobs_for_attachment( 42 );

		$all = MM_DB::get_jobs();
		$this->assertCount( 1, $all['jobs'] );
		$this->assertSame( '99', $all['jobs'][0]->attachment_id );
	}

	public function test_delete_jobs_for_attachment_is_called_on_delete_attachment_hook(): void {
		// Register the hook the same way metamanager.php does at runtime.
		add_action( 'delete_attachment', [ 'MM_DB', 'delete_jobs_for_attachment' ] );

		$attachment_id = $this->factory->attachment->create();
		MM_DB::log_job( $this->make_job( [ 'attachment_id' => $attachment_id, 'size' => 'full' ] ) );

		// Deleting the attachment should trigger the hook and clean the history.
		wp_delete_attachment( $attachment_id, true );

		$data = MM_DB::get_jobs( [ 'search' => '' ] );
		$ids  = array_column( $data['jobs'], 'attachment_id' );
		$this->assertNotContains( (string) $attachment_id, $ids );

		remove_action( 'delete_attachment', [ 'MM_DB', 'delete_jobs_for_attachment' ] );
	}
}
