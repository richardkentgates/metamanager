<?php
/**
 * MM_Mod_Robots — dynamic robots.txt via WordPress's built-in filter.
 *
 * WordPress intercepts /robots.txt requests via do_robots() which fires
 * the robots_txt filter.  We replace the content entirely when enabled.
 */

defined( 'ABSPATH' ) || exit;

class MM_Mod_Robots extends MM_Mod_Base {

	/** Nothing to add to the HTML head. */
	public function populate( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {}

	public function register_hooks(): void {
		if ( $this->settings->get( 'robots.enabled', true ) ) {
			add_filter( 'robots_txt', [ $this, 'generate' ], 99, 2 );
		}
	}

	public function generate( string $output, bool $public ): string {
		$s       = $this->settings;
		$lines   = [];

		$lines[] = 'User-agent: *';

		foreach ( (array) $s->get( 'robots.disallow', [] ) as $path ) {
			$path = trim( $path );
			if ( $path ) {
				$lines[] = 'Disallow: ' . sanitize_text_field( $path );
			}
		}

		foreach ( (array) $s->get( 'robots.allow', [] ) as $path ) {
			$path = trim( $path );
			if ( $path ) {
				$lines[] = 'Allow: ' . sanitize_text_field( $path );
			}
		}

		$delay = $s->get( 'robots.crawl_delay', '' );
		if ( $delay !== '' && is_numeric( $delay ) ) {
			$lines[] = 'Crawl-delay: ' . (int) $delay;
		}

		// Custom directives (sanitised, one per line).
		$custom = trim( $s->get( 'robots.custom', '' ) );
		if ( $custom ) {
			foreach ( explode( "\n", $custom ) as $line ) {
				$line = sanitize_text_field( trim( $line ) );
				if ( $line ) {
					$lines[] = $line;
				}
			}
		}

		$lines[] = '';

		// Append Sitemap directive(s) for each active sitemap.
		if ( $s->get( 'sitemap.enabled', true ) ) {
			$lines[] = 'Sitemap: ' . esc_url( home_url( '/sitemap.xml' ) );
		}

		return implode( "\n", $lines );
	}
}
