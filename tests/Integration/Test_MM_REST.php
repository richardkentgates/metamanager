<?php
/**
 * Integration tests for Metamanager REST API endpoints.
 *
 * Loads class-mm-admin.php and fires rest_api_init to register all routes,
 * then exercises each endpoint using WP_REST_Request + rest_do_request().
 *
 * Endpoints covered:
 *   GET  /metamanager/v1/stats
 *   GET  /metamanager/v1/jobs
 *   GET  /metamanager/v1/jobs/{id}
 *   GET  /metamanager/v1/attachment/{id}/status
 *   POST /metamanager/v1/attachment/{id}/compress
 *   POST /metamanager/v1/attachment/{id}/embed
 *   POST /metamanager/v1/compression-status
 *
 * Permission tests confirm that unauthenticated requests are rejected and that
 * the uploader/editor capability split is enforced.
 *
 * @package Metamanager\Tests\Integration
 */

// Load the admin class so its REST routes become available.
require_once dirname( __DIR__, 2 ) . '/includes/class-mm-admin.php';

/**
 * @covers MM_Admin::register_rest_routes
 */
class Test_MM_REST extends WP_Test_REST_TestCase {

	/** Editor-level user ID (edit_others_posts). */
	private int $editor_id;
	/** Author/uploader user ID (upload_files). */
	private int $uploader_id;
	/** Temp files to clean up. */
	private array $tmp_files = [];

	// ------------------------------------------------------------------
	// Setup / teardown
	// ------------------------------------------------------------------

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		MM_DB::create_or_update_table();
		// The rest_api_init hook for register_rest_routes lives in metamanager.php
		// which is not loaded in tests — register it here explicitly.
		add_action( 'rest_api_init', [ 'MM_Admin', 'register_rest_routes' ] );
		do_action( 'rest_api_init' );
	}

	public function set_up(): void {
		parent::set_up();
		$this->editor_id   = $this->factory->user->create( [ 'role' => 'editor' ] );
		$this->uploader_id = $this->factory->user->create( [ 'role' => 'author' ] );
	}

	public function tear_down(): void {
		foreach ( $this->tmp_files as $path ) {
			wp_delete_file( $path );
		}
		$this->tmp_files = [];
		parent::tear_down();
	}

	public static function tear_down_after_class(): void {
		MM_DB::drop_table();
		parent::tear_down_after_class();
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	private function rest_request( string $method, string $route, array $body = [], ?int $user_id = null ): WP_REST_Response {
		if ( $user_id ) {
			wp_set_current_user( $user_id );
		}
		$request = new WP_REST_Request( $method, $route );
		if ( ! empty( $body ) ) {
			$request->set_body_params( $body );
		}
		$response = rest_do_request( $request );
		// Unwrap WP_Error → WP_REST_Response if needed.
		if ( is_wp_error( $response ) ) {
			$response = new WP_REST_Response( [ 'message' => $response->get_error_message() ], 500 );
		}
		return rest_ensure_response( $response );
	}

	private function create_tmp_attachment( string $mime = 'image/jpeg' ): array {
		$ext = match ( $mime ) {
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'video/mp4'  => 'mp4',
			'audio/mpeg' => 'mp3',
			'application/pdf' => 'pdf',
			default      => explode( '/', $mime )[1] ?? 'bin',
		};
		$file_path = sys_get_temp_dir() . '/mm_rest_test_' . uniqid() . '.' . $ext;
		file_put_contents( $file_path, str_repeat( "\x00", 64 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$id = $this->factory->attachment->create( [ 'post_mime_type' => $mime ] );
		update_post_meta( $id, '_wp_attached_file', $file_path );
		$this->tmp_files[] = $file_path;
		return [ $id, $file_path ];
	}

	// ------------------------------------------------------------------
	// GET /stats
	// ------------------------------------------------------------------

	public function test_stats_requires_editor(): void {
		$response = $this->rest_request( 'GET', '/metamanager/v1/stats' );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_stats_uploader_forbidden(): void {
		$response = $this->rest_request( 'GET', '/metamanager/v1/stats', [], $this->uploader_id );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_stats_editor_ok(): void {
		$response = $this->rest_request( 'GET', '/metamanager/v1/stats', [], $this->editor_id );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'total_jobs', $data );
		$this->assertArrayHasKey( 'completed', $data );
		$this->assertArrayHasKey( 'failed', $data );
	}

	// ------------------------------------------------------------------
	// GET /jobs
	// ------------------------------------------------------------------

	public function test_jobs_requires_editor(): void {
		$response = $this->rest_request( 'GET', '/metamanager/v1/jobs' );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_jobs_editor_returns_array(): void {
		$response = $this->rest_request( 'GET', '/metamanager/v1/jobs', [], $this->editor_id );
		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $response->get_data() );
	}

	public function test_jobs_pagination_headers_present(): void {
		$response = $this->rest_request( 'GET', '/metamanager/v1/jobs', [], $this->editor_id );
		$headers  = $response->get_headers();
		$this->assertArrayHasKey( 'X-WP-Total', $headers );
		$this->assertArrayHasKey( 'X-WP-TotalPages', $headers );
	}

	// ------------------------------------------------------------------
	// GET /jobs/{id}
	// ------------------------------------------------------------------

	public function test_get_single_job_404_for_missing(): void {
		$response = $this->rest_request( 'GET', '/metamanager/v1/jobs/999999', [], $this->editor_id );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_get_single_job_returns_job(): void {
		// Insert a job row directly.
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . MM_JOB_TABLE,
			[
				'attachment_id' => 1,
				'size'          => 'full',
				'job_type'      => 'compression',
				'status'        => 'completed',
				'file_path'     => '/tmp/fake.jpg',
				'image_name'    => 'fake.jpg',
				'job_trigger'   => 'upload',
			]
		);
		$job_id   = (int) $wpdb->insert_id;
		$response = $this->rest_request( 'GET', "/metamanager/v1/jobs/{$job_id}", [], $this->editor_id );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( $job_id, (int) $data->id );
	}

	// ------------------------------------------------------------------
	// GET /attachment/{id}/status
	// ------------------------------------------------------------------

	public function test_attachment_status_requires_uploader(): void {
		$response = $this->rest_request( 'GET', '/metamanager/v1/attachment/1/status' );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_attachment_status_404_for_missing(): void {
		$response = $this->rest_request( 'GET', '/metamanager/v1/attachment/999999/status', [], $this->uploader_id );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_attachment_status_returns_shape(): void {
		[ $id ] = $this->create_tmp_attachment( 'image/jpeg' );
		$response = $this->rest_request( 'GET', "/metamanager/v1/attachment/{$id}/status", [], $this->uploader_id );
		$this->assertSame( 200, $response->get_status() );
		$data = (array) $response->get_data();
		$this->assertArrayHasKey( 'id', $data );
		$this->assertArrayHasKey( 'compression', $data );
		$this->assertArrayHasKey( 'meta_synced', $data );
	}

	// ------------------------------------------------------------------
	// POST /attachment/{id}/compress
	// ------------------------------------------------------------------

	public function test_compress_endpoint_requires_editor(): void {
		[ $id ] = $this->create_tmp_attachment( 'image/jpeg' );
		$response = $this->rest_request( 'POST', "/metamanager/v1/attachment/{$id}/compress", [], $this->uploader_id );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_compress_endpoint_queues_image(): void {
		[ $id ] = $this->create_tmp_attachment( 'image/jpeg' );
		$response = $this->rest_request( 'POST', "/metamanager/v1/attachment/{$id}/compress", [], $this->editor_id );
		$this->assertSame( 200, $response->get_status() );
		$data = (array) $response->get_data();
		$this->assertTrue( $data['queued'] );
	}

	public function test_compress_endpoint_422_for_audio(): void {
		[ $id ] = $this->create_tmp_attachment( 'audio/mpeg' );
		$response = $this->rest_request( 'POST', "/metamanager/v1/attachment/{$id}/compress", [], $this->editor_id );
		$this->assertSame( 422, $response->get_status() );
	}

	public function test_compress_endpoint_422_for_pdf(): void {
		[ $id ] = $this->create_tmp_attachment( 'application/pdf' );
		$response = $this->rest_request( 'POST', "/metamanager/v1/attachment/{$id}/compress", [], $this->editor_id );
		$this->assertSame( 422, $response->get_status() );
	}

	// ------------------------------------------------------------------
	// POST /attachment/{id}/embed
	// ------------------------------------------------------------------

	public function test_embed_endpoint_requires_editor(): void {
		[ $id ] = $this->create_tmp_attachment( 'image/jpeg' );
		$response = $this->rest_request( 'POST', "/metamanager/v1/attachment/{$id}/embed", [], $this->uploader_id );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_embed_endpoint_queues_image(): void {
		[ $id ] = $this->create_tmp_attachment( 'image/jpeg' );
		$response = $this->rest_request( 'POST', "/metamanager/v1/attachment/{$id}/embed", [], $this->editor_id );
		$this->assertSame( 200, $response->get_status() );
		$data = (array) $response->get_data();
		$this->assertTrue( $data['queued'] );
		$this->assertSame( $id, $data['id'] );
	}

	public function test_embed_endpoint_queues_audio(): void {
		[ $id ] = $this->create_tmp_attachment( 'audio/mpeg' );
		$response = $this->rest_request( 'POST', "/metamanager/v1/attachment/{$id}/embed", [], $this->editor_id );
		$this->assertSame( 200, $response->get_status() );
		$data = (array) $response->get_data();
		$this->assertTrue( $data['queued'] );
	}

	public function test_embed_endpoint_404_for_missing_attachment(): void {
		$response = $this->rest_request( 'POST', '/metamanager/v1/attachment/999999/embed', [], $this->editor_id );
		$this->assertSame( 404, $response->get_status() );
	}

	// ------------------------------------------------------------------
	// POST /compression-status
	// ------------------------------------------------------------------

	public function test_compression_status_requires_uploader(): void {
		$response = $this->rest_request( 'POST', '/metamanager/v1/compression-status', [ 'ids' => [ 1 ] ] );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_compression_status_returns_map(): void {
		[ $id ] = $this->create_tmp_attachment( 'image/jpeg' );
		$response = $this->rest_request(
			'POST',
			'/metamanager/v1/compression-status',
			[ 'ids' => [ $id ] ],
			$this->uploader_id
		);
		$this->assertSame( 200, $response->get_status() );
		$data = (array) $response->get_data();
		$this->assertArrayHasKey( (string) $id, $data );
	}

	// ------------------------------------------------------------------
	// job_trigger field in job history
	// ------------------------------------------------------------------

	public function test_job_trigger_field_present_in_jobs_response(): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . MM_JOB_TABLE,
			[
				'attachment_id' => 1,
				'size'          => 'full',
				'job_type'      => 'metadata',
				'status'        => 'completed',
				'file_path'     => '/tmp/fake.jpg',
				'image_name'    => 'fake.jpg',
				'job_trigger'   => 'rest_api',
			]
		);
		$response = $this->rest_request( 'GET', '/metamanager/v1/jobs', [], $this->editor_id );
		$jobs = $response->get_data();
		$this->assertNotEmpty( $jobs );
		$first = (array) $jobs[0];
		$this->assertArrayHasKey( 'job_trigger', $first );
		$this->assertSame( 'rest_api', $first['job_trigger'] );
	}
}
