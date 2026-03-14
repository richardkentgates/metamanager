<?php
/**
 * Integration tests for MM_Frontend.
 *
 * Covers: Schema.org JSON-LD structure (ImageObject, VideoObject, AudioObject),
 * Open Graph meta tags, license link output, and GPS/location embedding.
 *
 * Each test creates real WP attachment posts via the factory, sets post meta,
 * navigates to the attachment URL via go_to(), then buffers the output of
 * MM_Frontend::output_head_tags() and asserts against the actual HTML/JSON.
 *
 * @package Metamanager\Tests\Integration
 */

class Test_MM_Frontend extends WP_UnitTestCase {

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Create an image attachment and navigate to its page.
	 *
	 * @param  array $meta  Post meta to set.
	 * @return int  Attachment ID.
	 */
	private function make_image_attachment( array $meta = [] ): int {
		$id = $this->factory->attachment->create( [
			'post_mime_type' => 'image/jpeg',
			'post_title'     => 'Test Image',
			'post_excerpt'   => 'A test caption.',
			'post_content'   => 'A longer description.',
		] );

		// Give it a fake file URL so wp_get_attachment_url() returns something.
		update_post_meta( $id, '_wp_attached_file', 'test-image.jpg' );
		// Fake full-size src for wp_get_attachment_image_src().
		update_post_meta( $id, '_wp_attachment_metadata', [
			'width'  => 1920,
			'height' => 1080,
			'file'   => 'test-image.jpg',
			'sizes'  => [],
		] );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		return $id;
	}

	/**
	 * Create a video attachment.
	 *
	 * @param  array $meta Post meta.
	 * @return int
	 */
	private function make_video_attachment( array $meta = [] ): int {
		$id = $this->factory->attachment->create( [
			'post_mime_type' => 'video/mp4',
			'post_title'     => 'Test Video',
			'post_excerpt'   => 'A video description.',
		] );
		update_post_meta( $id, '_wp_attached_file', 'test-video.mp4' );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		return $id;
	}

	/**
	 * Create an audio attachment.
	 */
	private function make_audio_attachment( array $meta = [] ): int {
		$id = $this->factory->attachment->create( [
			'post_mime_type' => 'audio/mpeg',
			'post_title'     => 'Test Audio',
		] );
		update_post_meta( $id, '_wp_attached_file', 'test-audio.mp3' );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		return $id;
	}

	/**
	 * Navigate to the attachment page and capture output_head_tags().
	 *
	 * @param  int $id Attachment ID.
	 * @return string  Buffered HTML output.
	 */
	private function get_head_output( int $id ): string {
		go_to( get_attachment_link( $id ) );
		// Ensure we are on an attachment page from WP's perspective.
		global $wp_query;
		$wp_query->is_attachment = true;
		$wp_query->is_singular   = true;

		global $post;
		$post = get_post( $id );
		setup_postdata( $post );

		ob_start();
		MM_Frontend::output_head_tags();
		$output = ob_get_clean();

		wp_reset_postdata();
		return $output;
	}

	/**
	 * Extract and decode the first JSON-LD block from head output.
	 *
	 * @param  string $html
	 * @return array|null
	 */
	private function extract_json_ld( string $html ): ?array {
		if ( ! preg_match( '/<script type="application\/ld\+json">\s*(\{.*?\})\s*<\/script>/s', $html, $m ) ) {
			return null;
		}
		$decoded = json_decode( $m[1], true );
		return is_array( $decoded ) ? $decoded : null;
	}

	// -----------------------------------------------------------------------
	// ImageObject JSON-LD
	// -----------------------------------------------------------------------

	public function test_image_schema_type_is_image_object(): void {
		$id     = $this->make_image_attachment();
		$output = $this->get_head_output( $id );
		$schema = $this->extract_json_ld( $output );

		$this->assertNotNull( $schema, 'JSON-LD block should be present for an image attachment.' );
		$this->assertSame( 'https://schema.org', $schema['@context'] );
		$this->assertSame( 'ImageObject', $schema['@type'] );
	}

	public function test_image_schema_includes_title(): void {
		$id     = $this->make_image_attachment();
		$output = $this->get_head_output( $id );
		$schema = $this->extract_json_ld( $output );

		$this->assertSame( 'Test Image', $schema['name'] );
	}

	public function test_image_schema_includes_creator(): void {
		$id = $this->make_image_attachment( [ MM_Metadata::META_CREATOR => 'Jane Doe' ] );
		$schema = $this->extract_json_ld( $this->get_head_output( $id ) );

		$this->assertArrayHasKey( 'creator', $schema );
		$this->assertSame( 'Person', $schema['creator']['@type'] );
		$this->assertSame( 'Jane Doe', $schema['creator']['name'] );
	}

	public function test_image_schema_includes_copyright_notice(): void {
		$id = $this->make_image_attachment( [ MM_Metadata::META_COPYRIGHT => '© 2026 Jane Doe' ] );
		$schema = $this->extract_json_ld( $this->get_head_output( $id ) );

		$this->assertSame( '© 2026 Jane Doe', $schema['copyrightNotice'] );
	}

	public function test_image_schema_includes_keywords_as_array(): void {
		$id = $this->make_image_attachment( [ MM_Metadata::META_KEYWORDS => 'landscape;sunrise;nature' ] );
		$schema = $this->extract_json_ld( $this->get_head_output( $id ) );

		$this->assertSame( [ 'landscape', 'sunrise', 'nature' ], $schema['keywords'] );
	}

	public function test_image_schema_includes_geocoordinates_when_gps_set(): void {
		$id = $this->make_image_attachment( [
			MM_Metadata::META_GPS_LAT => '40.014984',
			MM_Metadata::META_GPS_LON => '-105.270546',
			MM_Metadata::META_GPS_ALT => '1655.0',
		] );
		$schema = $this->extract_json_ld( $this->get_head_output( $id ) );

		$this->assertArrayHasKey( 'locationCreated', $schema );
		$geo = $schema['locationCreated']['geo'];
		$this->assertSame( 'GeoCoordinates', $geo['@type'] );
		$this->assertEqualsWithDelta( 40.014984, $geo['latitude'],  0.000001 );
		$this->assertEqualsWithDelta( -105.270546, $geo['longitude'], 0.000001 );
		$this->assertEqualsWithDelta( 1655.0, $geo['elevation'],  0.1 );
	}

	public function test_image_schema_omits_geocoordinates_when_no_gps(): void {
		$id     = $this->make_image_attachment();
		$schema = $this->extract_json_ld( $this->get_head_output( $id ) );

		$this->assertArrayNotHasKey( 'locationCreated', $schema );
	}

	public function test_image_schema_includes_iptc_location_without_gps(): void {
		$id = $this->make_image_attachment( [
			MM_Metadata::META_CITY    => 'Boulder',
			MM_Metadata::META_STATE   => 'CO',
			MM_Metadata::META_COUNTRY => 'USA',
		] );
		$schema = $this->extract_json_ld( $this->get_head_output( $id ) );

		$this->assertArrayHasKey( 'locationCreated', $schema );
		$this->assertSame( 'Boulder, CO, USA', $schema['locationCreated']['name'] );
	}

	// -----------------------------------------------------------------------
	// VideoObject JSON-LD
	// -----------------------------------------------------------------------

	public function test_video_schema_type_is_video_object(): void {
		$id     = $this->make_video_attachment();
		$schema = $this->extract_json_ld( $this->get_head_output( $id ) );

		$this->assertNotNull( $schema );
		$this->assertSame( 'VideoObject', $schema['@type'] );
	}

	public function test_video_schema_has_upload_date(): void {
		$id     = $this->make_video_attachment();
		$schema = $this->extract_json_ld( $this->get_head_output( $id ) );

		$this->assertArrayHasKey( 'uploadDate', $schema );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $schema['uploadDate'] );
	}

	// -----------------------------------------------------------------------
	// AudioObject JSON-LD
	// -----------------------------------------------------------------------

	public function test_audio_schema_type_is_audio_object(): void {
		$id     = $this->make_audio_attachment();
		$schema = $this->extract_json_ld( $this->get_head_output( $id ) );

		$this->assertNotNull( $schema );
		$this->assertSame( 'AudioObject', $schema['@type'] );
	}

	// -----------------------------------------------------------------------
	// Open Graph tags
	// -----------------------------------------------------------------------

	public function test_image_og_tags_are_present(): void {
		$id     = $this->make_image_attachment();
		$output = $this->get_head_output( $id );

		$this->assertStringContainsString( 'og:image', $output );
		$this->assertStringContainsString( 'og:type', $output );
	}

	public function test_video_og_tag_is_present(): void {
		$id     = $this->make_video_attachment();
		$output = $this->get_head_output( $id );

		$this->assertStringContainsString( 'og:video', $output );
	}

	public function test_audio_og_tag_is_present(): void {
		$id     = $this->make_audio_attachment();
		$output = $this->get_head_output( $id );

		$this->assertStringContainsString( 'og:audio', $output );
	}

	// -----------------------------------------------------------------------
	// License link / copyright meta
	// -----------------------------------------------------------------------

	public function test_license_link_emitted_for_url_copyright(): void {
		$id = $this->make_image_attachment( [
			MM_Metadata::META_COPYRIGHT => 'https://creativecommons.org/licenses/by/4.0/',
		] );
		$output = $this->get_head_output( $id );

		$this->assertStringContainsString( 'rel="license"', $output );
		$this->assertStringContainsString( 'https://creativecommons.org/licenses/by/4.0/', $output );
	}

	public function test_copyright_meta_emitted_for_plain_text_copyright(): void {
		$id = $this->make_image_attachment( [
			MM_Metadata::META_COPYRIGHT => '© 2026 Jane Doe',
		] );
		$output = $this->get_head_output( $id );

		$this->assertStringContainsString( 'name="copyright"', $output );
		$this->assertStringContainsString( '© 2026 Jane Doe', $output );
	}

	public function test_no_license_output_when_no_copyright(): void {
		$id     = $this->make_image_attachment();
		$output = $this->get_head_output( $id );

		$this->assertStringNotContainsString( 'rel="license"',   $output );
		$this->assertStringNotContainsString( 'name="copyright"', $output );
	}

	// -----------------------------------------------------------------------
	// Edge cases
	// -----------------------------------------------------------------------

	public function test_no_output_for_non_media_attachment(): void {
		// A plain text attachment should produce no head output.
		$id = $this->factory->attachment->create( [
			'post_mime_type' => 'text/plain',
		] );
		$output = $this->get_head_output( $id );

		$this->assertStringNotContainsString( 'application/ld+json', $output );
		$this->assertStringNotContainsString( 'og:image', $output );
	}
}
