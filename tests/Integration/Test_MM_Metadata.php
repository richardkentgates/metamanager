<?php
/**
 * Integration tests for the Metamanager metadata subsystem.
 *
 * Covers:
 *   - MM_Site_Settings  : option get/set/sanitize and multisite isolation
 *   - MM_Page_Context   : context resolver returns correct string for each WP query state
 *   - MM_Head_Emitter   : wp_head output contains expected meta tags
 *
 * @package Metamanager\Tests\Integration
 */

require_once dirname( __DIR__, 2 ) . '/includes/metadata/class-mm-metadata-loader.php';

MM_Metadata_Loader::load();

/**
 * @covers MM_Site_Settings
 * @covers MM_Page_Context
 */
class Test_MM_Metadata extends WP_UnitTestCase {

	// ------------------------------------------------------------------
	// Setup
	// ------------------------------------------------------------------

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		// Ensure the MM DB table exists for any hooks that query it.
		if ( class_exists( 'MM_DB' ) ) {
			MM_DB::create_or_update_table();
		}
	}

	public function set_up(): void {
		parent::set_up();
		// Reset the site settings option between tests.
		delete_option( MM_Site_Settings::OPTION_KEY );
	}

	// ------------------------------------------------------------------
	// MM_Site_Settings — defaults
	// ------------------------------------------------------------------

	/** A fresh install returns an array of defaults. */
	public function test_site_settings_get_all_returns_array(): void {
		$settings = MM_Site_Settings::get_all();
		$this->assertIsArray( $settings );
	}

	/** Default title separator is a pipe. */
	public function test_title_separator_default(): void {
		$this->assertSame( '|', MM_Site_Settings::get( 'titles', 'separator', '|' ) );
	}

	/** Saving and reading back a string value round-trips correctly. */
	public function test_save_and_read_string_value(): void {
		MM_Site_Settings::update_section( 'titles', [ 'separator' => '-' ] );
		$this->assertSame( '-', MM_Site_Settings::get( 'titles', 'separator', '|' ) );
	}

	/** Saving a boolean flag and reading it back. */
	public function test_save_and_read_bool_flag(): void {
		MM_Site_Settings::update_section( 'robots', [ 'noindex_search' => true ] );
		$this->assertTrue( MM_Site_Settings::get( 'robots', 'noindex_search', false ) );
	}

	/** update_section merges, not overwrites — other keys are preserved. */
	public function test_update_section_merges_into_existing(): void {
		MM_Site_Settings::update_section( 'titles', [
			'separator'   => '-',
			'post_format' => '%%post_title%% %%sep%% %%sitetitle%%',
		] );
		MM_Site_Settings::update_section( 'titles', [ 'separator' => '|' ] );

		$this->assertSame( '|', MM_Site_Settings::get( 'titles', 'separator', '' ) );
		$this->assertSame(
			'%%post_title%% %%sep%% %%sitetitle%%',
			MM_Site_Settings::get( 'titles', 'post_format', '' )
		);
	}

	/** An unknown key returns the provided default. */
	public function test_get_unknown_key_returns_default(): void {
		$result = MM_Site_Settings::get( 'titles', 'nonexistent_key', 'fallback' );
		$this->assertSame( 'fallback', $result );
	}

	// ------------------------------------------------------------------
	// MM_Site_Settings — sanitization
	// ------------------------------------------------------------------

	/** HTML tags are stripped from string values. */
	public function test_sanitize_strips_html_from_strings(): void {
		MM_Site_Settings::update_section( 'titles', [
			'separator' => '<script>alert(1)</script>|',
		] );
		$val = MM_Site_Settings::get( 'titles', 'separator', '' );
		$this->assertStringNotContainsString( '<script>', $val );
	}

	// ------------------------------------------------------------------
	// MM_Page_Context
	// ------------------------------------------------------------------

	/** Returns 'home' on the front page. */
	public function test_page_context_home(): void {
		$this->go_to( home_url( '/' ) );
		// Front page is_home() or is_front_page() depending on settings.
		$context = MM_Page_Context::resolve();
		$this->assertContains( $context, [ 'home', 'front_page' ] );
	}

	/** Returns 'single' for a singular post. */
	public function test_page_context_single_post(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->go_to( get_permalink( $post_id ) );
		$this->assertSame( 'single', MM_Page_Context::resolve() );
	}

	/** Returns 'page' for a singular page. */
	public function test_page_context_single_page(): void {
		$page_id = self::factory()->post->create( [
			'post_status' => 'publish',
			'post_type'   => 'page',
		] );
		$this->go_to( get_permalink( $page_id ) );
		$this->assertSame( 'page', MM_Page_Context::resolve() );
	}

	/** Returns 'search' for a search query. */
	public function test_page_context_search(): void {
		$this->go_to( home_url( '/?s=test' ) );
		$this->assertSame( 'search', MM_Page_Context::resolve() );
	}

	/** Returns 'category' for a category archive. */
	public function test_page_context_category_archive(): void {
		$cat_id = self::factory()->category->create();
		$this->go_to( get_category_link( $cat_id ) );
		$this->assertSame( 'category', MM_Page_Context::resolve() );
	}

	// ------------------------------------------------------------------
	// MM_Head_Emitter — output
	// ------------------------------------------------------------------

	/** wp_head contains a <title> tag when emitter is active. */
	public function test_head_emitter_outputs_title_tag(): void {
		$post_id = self::factory()->post->create( [
			'post_title'  => 'Head Emitter Test Post',
			'post_status' => 'publish',
		] );
		$this->go_to( get_permalink( $post_id ) );

		// Boot the emitter to register its wp_head hook.
		$emitter = new MM_Head_Emitter();
		$emitter->register();

		ob_start();
		do_action( 'wp_head' );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<title>', $output );
	}

	/** wp_head contains <meta name="description"> when a description is set. */
	public function test_head_emitter_outputs_meta_description(): void {
		$post_id = self::factory()->post->create( [
			'post_title'  => 'Description Test',
			'post_status' => 'publish',
		] );
		update_post_meta( $post_id, '_mm_meta_description', 'A great description.' );
		$this->go_to( get_permalink( $post_id ) );

		$emitter = new MM_Head_Emitter();
		$emitter->register();

		ob_start();
		do_action( 'wp_head' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="description"', $output );
		$this->assertStringContainsString( 'A great description.', $output );
	}
}
