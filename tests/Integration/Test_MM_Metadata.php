<?php
/**
 * Integration tests for the Metamanager metadata subsystem.
 *
 * Covers:
 *   - MM_Site_Settings  : option get/defaults/round-trip
 *   - MM_Page_Context   : context resolver returns correct string for each WP query state
 *   - MM_Head_Emitter   : wp_head output contains expected meta tags
 *
 * All metadata classes are loaded by tests/bootstrap.php — no require_once needed here.
 *
 * @package Metamanager\Tests\Integration
 */

defined( 'ABSPATH' ) || exit;

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
		if ( class_exists( 'MM_DB' ) ) {
			MM_DB::create_or_update_table();
		}
	}

	public function set_up(): void {
		parent::set_up();
		// Clear both stored options and flush the in-memory singleton so each test
		// starts from a clean state with only the class defaults.
		delete_option( MM_META_OPT_SETTINGS );
		delete_option( MM_META_OPT_BUSINESS );
		MM_Site_Settings::reset_instance();
	}

	public function tear_down(): void {
		MM_Site_Settings::reset_instance();
		parent::tear_down();
	}

	// ------------------------------------------------------------------
	// MM_Site_Settings — defaults
	// ------------------------------------------------------------------

	/** A fresh install returns an array of defaults. */
	public function test_site_settings_all_returns_array(): void {
		$settings = MM_Site_Settings::get_instance()->all();
		$this->assertIsArray( $settings );
	}

	/** Default title separator is a pipe. */
	public function test_title_separator_default(): void {
		$sep = MM_Site_Settings::get_instance()->get( 'titles.separator', '__missing__' );
		$this->assertSame( '|', $sep );
	}

	/** Saving and reading back a string value round-trips correctly. */
	public function test_save_and_read_string_value(): void {
		update_option( MM_META_OPT_SETTINGS, [ 'titles' => [ 'separator' => '-' ] ] );
		MM_Site_Settings::reset_instance();
		$this->assertSame( '-', MM_Site_Settings::get_instance()->get( 'titles.separator', '|' ) );
	}

	/** Saving a boolean flag and reading it back. */
	public function test_save_and_read_bool_flag(): void {
		update_option( MM_META_OPT_SETTINGS, [ 'titles' => [ 'search_noindex' => true ] ] );
		MM_Site_Settings::reset_instance();
		$this->assertTrue( MM_Site_Settings::get_instance()->get( 'titles.search_noindex', false ) );
	}

	/** Saving multiple keys — both persist after a second reset. */
	public function test_partial_save_preserves_sibling_keys(): void {
		update_option( MM_META_OPT_SETTINGS, [
			'titles' => [ 'separator' => '-', 'home_title' => 'Custom Site' ],
		] );
		MM_Site_Settings::reset_instance();
		$this->assertSame( '-', MM_Site_Settings::get_instance()->get( 'titles.separator', '|' ) );
		$this->assertSame( 'Custom Site', MM_Site_Settings::get_instance()->get( 'titles.home_title', '' ) );
	}

	/** An unknown dot-path key returns the provided default. */
	public function test_get_unknown_key_returns_default(): void {
		$result = MM_Site_Settings::get_instance()->get( 'titles.nonexistent_key', 'fallback' );
		$this->assertSame( 'fallback', $result );
	}

	// ------------------------------------------------------------------
	// MM_Site_Settings — verified defaults from audit
	// ------------------------------------------------------------------

	/** HTML sitemap config is nested inside the sitemap section. */
	public function test_html_sitemap_default_enabled(): void {
		$enabled = MM_Site_Settings::get_instance()->get( 'sitemap.html_sitemap.enabled', false );
		$this->assertTrue( $enabled );
	}

	/** Post types for HTML sitemap default to page and post. */
	public function test_html_sitemap_default_post_types(): void {
		$pts = MM_Site_Settings::get_instance()->get( 'sitemap.html_sitemap.post_types', [] );
		$this->assertContains( 'page', $pts );
		$this->assertContains( 'post', $pts );
	}

	/** Default cron frequency is twicedaily, not the old mm_meta_6h. */
	public function test_links_cron_frequency_default_is_twicedaily(): void {
		$freq = MM_Site_Settings::get_instance()->get( 'links.cron_frequency', '' );
		$this->assertSame( 'twicedaily', $freq );
	}

	/** search_title default contains the search_query token. */
	public function test_titles_search_title_default_has_token(): void {
		$tpl = MM_Site_Settings::get_instance()->get( 'titles.search_title', '' );
		$this->assertStringContainsString( '%%search_query%%', $tpl );
	}

	/** 404_title default is not empty. */
	public function test_titles_404_title_default_is_set(): void {
		$tpl = MM_Site_Settings::get_instance()->get( 'titles.404_title', '' );
		$this->assertNotEmpty( $tpl );
	}

	/** fb_link_ownership_id has been removed from social defaults. */
	public function test_social_has_no_fb_link_ownership_id(): void {
		$social = MM_Site_Settings::get_instance()->all()['social'];
		$this->assertArrayNotHasKey( 'fb_link_ownership_id', $social );
	}

	/** tools section has been removed from settings defaults. */
	public function test_settings_has_no_tools_section(): void {
		$all = MM_Site_Settings::get_instance()->all();
		$this->assertArrayNotHasKey( 'tools', $all );
	}

	/** payment_accepted exists in business defaults. */
	public function test_business_defaults_has_payment_accepted(): void {
		$biz = MM_Site_Settings::get_instance()->all_business();
		$this->assertArrayHasKey( 'payment_accepted', $biz );
		$this->assertIsArray( $biz['payment_accepted'] );
	}

	/** business defaults no longer include locations key. */
	public function test_business_defaults_has_no_locations(): void {
		$biz = MM_Site_Settings::get_instance()->all_business();
		$this->assertArrayNotHasKey( 'locations', $biz );
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

	/**
	 * Build a minimal head emitter wired with MM_Mod_Head_Meta only.
	 *
	 * @return array{emitter: MM_Head_Emitter, context: MM_Page_Context, settings: MM_Site_Settings}
	 */
	private function make_emitter(): array {
		$settings = MM_Site_Settings::get_instance();
		$context  = new MM_Page_Context();
		$emitter  = new MM_Head_Emitter( $context, $settings );
		$emitter->add_module( new MM_Mod_Head_Meta( $settings ) );
		return compact( 'emitter', 'context', 'settings' );
	}

	/** wp_head contains a <title> tag when emitter is active. */
	public function test_head_emitter_outputs_title_tag(): void {
		$post_id = self::factory()->post->create( [
			'post_title'  => 'Head Emitter Test Post',
			'post_status' => 'publish',
		] );
		$this->go_to( get_permalink( $post_id ) );

		$e = $this->make_emitter();
		add_action( 'wp_head', [ $e['emitter'], 'render' ], 99 );

		ob_start();
		do_action( 'wp_head' );
		$output = ob_get_clean();

		remove_action( 'wp_head', [ $e['emitter'], 'render' ], 99 );

		$this->assertStringContainsString( '<title>', $output );
	}

	/** wp_head contains <meta name="description"> when a per-post description is stored. */
	public function test_head_emitter_outputs_meta_description(): void {
		$post_id = self::factory()->post->create( [
			'post_title'  => 'Description Test',
			'post_status' => 'publish',
		] );
		// _mm_meta stores a JSON object; 'description' key is the per-post override.
		update_post_meta( $post_id, MM_META_KEY, wp_json_encode( [ 'description' => 'A great description.' ] ) );
		$this->go_to( get_permalink( $post_id ) );

		$e = $this->make_emitter();
		add_action( 'wp_head', [ $e['emitter'], 'render' ], 99 );

		ob_start();
		do_action( 'wp_head' );
		$output = ob_get_clean();

		remove_action( 'wp_head', [ $e['emitter'], 'render' ], 99 );

		$this->assertStringContainsString( 'name="description"', $output );
		$this->assertStringContainsString( 'A great description.', $output );
	}
}
