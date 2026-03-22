<?php
/**
 * Integration tests for MM_Mod_Rss — RSS feed cleanup module.
 *
 * Covers:
 *   - filter_generator()     : strips generator tag for all feed types, preserves for HTML
 *   - clean_rss_output()     : strips wfw:commentRss, slash:comments, xmlns:wfw, xmlns:slash
 *   - register_hooks()       : correct hooks are added/skipped depending on settings
 *
 * Each test works with a fresh MM_Site_Settings instance (no stored option) so
 * defaults are clean.  Hooks added during register_hooks() tests are removed in
 * tear_down() to avoid cross-test contamination.
 *
 * @package Metamanager\Tests\Integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * @covers MM_Mod_Rss
 */
class Test_MM_Mod_Rss extends WP_UnitTestCase {

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Build an MM_Mod_Rss instance pre-configured with the given setting overrides.
	 *
	 * @param  array $overrides  Nested settings array merged over defaults, e.g.
	 *                           [ 'feed' => [ 'cleanup_enabled' => false ] ].
	 * @return MM_Mod_Rss
	 */
	private function make_module( array $overrides = [] ): MM_Mod_Rss {
		MM_Site_Settings::reset_instance();
		delete_option( MM_META_OPT_SETTINGS );

		if ( $overrides ) {
			update_option( MM_META_OPT_SETTINGS, $overrides );
			MM_Site_Settings::reset_instance();
		}

		return new MM_Mod_Rss( MM_Site_Settings::get_instance() );
	}

	public function tear_down(): void {
		// Clean up any hooks that tests may have added.
		remove_all_filters( 'the_generator' );
		remove_all_actions( 'template_redirect' );
		remove_all_filters( 'the_content_feed' );
		remove_all_filters( 'wp_title_rss' );
		remove_all_actions( 'rss2_head' );

		MM_Site_Settings::reset_instance();
		delete_option( MM_META_OPT_SETTINGS );
		parent::tear_down();
	}

	// -----------------------------------------------------------------------
	// filter_generator()
	// -----------------------------------------------------------------------

	/** Returns empty string for the 'rss2' feed type. */
	public function test_filter_generator_strips_rss2(): void {
		$mod = $this->make_module();
		$this->assertSame( '', $mod->filter_generator( '<generator>https://wordpress.org/?v=6.9</generator>', 'rss2' ) );
	}

	/** Returns empty string for every recognised feed type. */
	public function test_filter_generator_strips_all_feed_types(): void {
		$mod   = $this->make_module();
		$input = '<generator>https://wordpress.org/?v=6.9</generator>';

		foreach ( [ 'rss2', 'rss', 'atom', 'rdf', 'comment', 'export', 'opml' ] as $type ) {
			$this->assertSame(
				'',
				$mod->filter_generator( $input, $type ),
				"filter_generator() should return '' for type '$type'"
			);
		}
	}

	/** Returns the original string unchanged for non-feed types (e.g. HTML). */
	public function test_filter_generator_preserves_html_type(): void {
		$mod      = $this->make_module();
		$original = '<meta name="generator" content="WordPress 6.9">';
		$this->assertSame( $original, $mod->filter_generator( $original, 'html' ) );
	}

	/** Returns the original string unchanged for an arbitrary unknown type. */
	public function test_filter_generator_preserves_unknown_type(): void {
		$mod      = $this->make_module();
		$original = 'anything';
		$this->assertSame( $original, $mod->filter_generator( $original, 'unknown_type' ) );
	}

	// -----------------------------------------------------------------------
	// clean_rss_output()
	// -----------------------------------------------------------------------

	/** Strips the wfw:commentRss element and its content. */
	public function test_clean_rss_output_strips_wfw_comment_rss(): void {
		$mod    = $this->make_module();
		$input  = "<item><title>Test</title>\n\t\t\t\t<wfw:commentRss>https://example.com/p/1/feed/</wfw:commentRss></item>";
		$output = $mod->clean_rss_output( $input );

		$this->assertStringNotContainsString( 'wfw:commentRss', $output );
		$this->assertStringContainsString( '<title>Test</title>', $output );
	}

	/** Strips the slash:comments element and its content. */
	public function test_clean_rss_output_strips_slash_comments(): void {
		$mod    = $this->make_module();
		$input  = "<item><title>Test</title>\n\t\t\t\t<slash:comments>3</slash:comments></item>";
		$output = $mod->clean_rss_output( $input );

		$this->assertStringNotContainsString( 'slash:comments', $output );
		$this->assertStringContainsString( '<title>Test</title>', $output );
	}

	/** Strips the xmlns:wfw namespace declaration but leaves other namespaces intact. */
	public function test_clean_rss_output_strips_xmlns_wfw(): void {
		$mod   = $this->make_module();
		$input = '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wfw="http://wellformedweb.org/CommentAPI/" xmlns:dc="http://purl.org/dc/elements/1.1/">';

		$output = $mod->clean_rss_output( $input );

		$this->assertStringNotContainsString( 'xmlns:wfw', $output );
		$this->assertStringContainsString( 'xmlns:content', $output );
		$this->assertStringContainsString( 'xmlns:dc', $output );
	}

	/** Strips the xmlns:slash namespace declaration. */
	public function test_clean_rss_output_strips_xmlns_slash(): void {
		$mod    = $this->make_module();
		$input  = '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:slash="http://purl.org/rss/1.0/modules/slash/">';
		$output = $mod->clean_rss_output( $input );

		$this->assertStringNotContainsString( 'xmlns:slash', $output );
		$this->assertStringContainsString( 'xmlns:content', $output );
	}

	/** Leaves content that contains neither wfw nor slash completely unchanged. */
	public function test_clean_rss_output_preserves_unrelated_content(): void {
		$mod   = $this->make_module();
		$input = '<item><title>Hello World</title><link>https://example.com/hello</link><description>A post.</description></item>';

		$this->assertSame( $input, $mod->clean_rss_output( $input ) );
	}

	// -----------------------------------------------------------------------
	// register_hooks() — master switch
	// -----------------------------------------------------------------------

	/** No hooks are registered when cleanup_enabled is false. */
	public function test_register_hooks_skips_all_when_disabled(): void {
		$mod = $this->make_module( [ 'feed' => [ 'cleanup_enabled' => false ] ] );
		$mod->register_hooks();

		$this->assertFalse( (bool) has_filter( 'the_generator', [ $mod, 'filter_generator' ] ) );
		$this->assertFalse( (bool) has_action( 'template_redirect', [ $mod, 'start_rss_buffer' ] ) );
	}

	// -----------------------------------------------------------------------
	// register_hooks() — generator filter
	// -----------------------------------------------------------------------

	/** the_generator filter is added when remove_generator is true. */
	public function test_register_hooks_adds_generator_filter(): void {
		$mod = $this->make_module( [
			'feed' => [ 'cleanup_enabled' => true, 'remove_generator' => true ],
		] );
		$mod->register_hooks();

		$this->assertNotFalse( has_filter( 'the_generator', [ $mod, 'filter_generator' ] ) );
	}

	/** the_generator filter is NOT added when remove_generator is false. */
	public function test_register_hooks_skips_generator_filter_when_off(): void {
		$mod = $this->make_module( [
			'feed' => [ 'cleanup_enabled' => true, 'remove_generator' => false ],
		] );
		$mod->register_hooks();

		$this->assertFalse( (bool) has_filter( 'the_generator', [ $mod, 'filter_generator' ] ) );
	}

	// -----------------------------------------------------------------------
	// register_hooks() — comments elements buffer
	// -----------------------------------------------------------------------

	/** template_redirect action is added when remove_comments_elements is true. */
	public function test_register_hooks_adds_template_redirect_for_buffer(): void {
		$mod = $this->make_module( [
			'feed' => [ 'cleanup_enabled' => true, 'remove_comments_elements' => true ],
		] );
		$mod->register_hooks();

		$this->assertNotFalse( has_action( 'template_redirect', [ $mod, 'start_rss_buffer' ] ) );
	}

	/** template_redirect action is NOT added when remove_comments_elements is false. */
	public function test_register_hooks_skips_buffer_when_off(): void {
		$mod = $this->make_module( [
			'feed' => [ 'cleanup_enabled' => true, 'remove_comments_elements' => false ],
		] );
		$mod->register_hooks();

		$this->assertFalse( (bool) has_action( 'template_redirect', [ $mod, 'start_rss_buffer' ] ) );
	}

	// -----------------------------------------------------------------------
	// register_hooks() — use_excerpt
	// -----------------------------------------------------------------------

	/** the_content_feed filter is added when use_excerpt is true. */
	public function test_register_hooks_adds_content_feed_filter_when_excerpt_on(): void {
		$mod = $this->make_module( [
			'feed' => [ 'cleanup_enabled' => true, 'use_excerpt' => true ],
		] );
		$mod->register_hooks();

		$this->assertNotFalse( has_filter( 'the_content_feed' ) );
	}

	/** the_content_feed filter is NOT added when use_excerpt is false. */
	public function test_register_hooks_skips_content_feed_filter_when_excerpt_off(): void {
		$mod = $this->make_module( [
			'feed' => [ 'cleanup_enabled' => true, 'use_excerpt' => false ],
		] );
		$mod->register_hooks();

		$this->assertFalse( (bool) has_filter( 'the_content_feed' ) );
	}

	// -----------------------------------------------------------------------
	// register_hooks() — feed title override
	// -----------------------------------------------------------------------

	/** wp_title_rss filter is added when a non-empty feed_title is set. */
	public function test_register_hooks_adds_title_filter_when_set(): void {
		$mod = $this->make_module( [
			'feed' => [ 'cleanup_enabled' => true, 'feed_title' => 'My Custom Feed' ],
		] );
		$mod->register_hooks();

		$this->assertNotFalse( has_filter( 'wp_title_rss' ) );
	}

	/** wp_title_rss filter is NOT added when feed_title is empty. */
	public function test_register_hooks_skips_title_filter_when_empty(): void {
		$mod = $this->make_module( [
			'feed' => [ 'cleanup_enabled' => true, 'feed_title' => '' ],
		] );
		$mod->register_hooks();

		$this->assertFalse( (bool) has_filter( 'wp_title_rss' ) );
	}

	/** wp_title_rss filter returns the configured title. */
	public function test_title_filter_returns_configured_value(): void {
		$mod = $this->make_module( [
			'feed' => [ 'cleanup_enabled' => true, 'feed_title' => 'Custom Feed Title' ],
		] );
		$mod->register_hooks();

		$result = apply_filters( 'wp_title_rss', 'Original Title' );
		$this->assertSame( 'Custom Feed Title', $result );
	}

	// -----------------------------------------------------------------------
	// register_hooks() — copyright
	// -----------------------------------------------------------------------

	/** rss2_head action is added when a non-empty feed_copyright is set. */
	public function test_register_hooks_adds_rss2_head_action_when_set(): void {
		$mod = $this->make_module( [
			'feed' => [ 'cleanup_enabled' => true, 'feed_copyright' => '2026 Example Inc.' ],
		] );
		$mod->register_hooks();

		$this->assertNotFalse( has_action( 'rss2_head' ) );
	}

	/** rss2_head action outputs a <copyright> element. */
	public function test_rss2_head_outputs_copyright_element(): void {
		$mod = $this->make_module( [
			'feed' => [ 'cleanup_enabled' => true, 'feed_copyright' => '2026 Example Inc.' ],
		] );
		$mod->register_hooks();

		ob_start();
		do_action( 'rss2_head' );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<copyright>', $output );
		$this->assertStringContainsString( '2026 Example Inc.', $output );
	}

	/** Copyright output is properly escaped. */
	public function test_rss2_head_escapes_copyright_value(): void {
		$mod = $this->make_module( [
			'feed' => [ 'cleanup_enabled' => true, 'feed_copyright' => '<b>Bad</b> & "Worse"' ],
		] );
		$mod->register_hooks();

		ob_start();
		do_action( 'rss2_head' );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '<b>', $output );
		$this->assertStringContainsString( '&lt;b&gt;', $output );
	}

	/** rss2_head action is NOT added when feed_copyright is empty. */
	public function test_register_hooks_skips_copyright_when_empty(): void {
		$mod = $this->make_module( [
			'feed' => [ 'cleanup_enabled' => true, 'feed_copyright' => '' ],
		] );
		$mod->register_hooks();

		$this->assertFalse( (bool) has_action( 'rss2_head' ) );
	}
}
