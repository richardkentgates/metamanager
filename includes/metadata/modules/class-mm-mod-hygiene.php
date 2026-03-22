<?php
/**
 * MM_Mod_Hygiene — WordPress head cleanup.
 *
 * Removes meta/link tags that add noise, expose WP version, or are
 * not useful for content-marketing sites.
 */

defined( 'ABSPATH' ) || exit;

class MM_Mod_Hygiene extends MM_Mod_Base {

	/**
	 * Hygiene runs very early (module added first in Loader).
	 * It registers removal hooks here rather than writing to $data.
	 */
	public function populate( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {
		// All hygiene hooks are registered once on init via register_hooks(),
		// called from the Loader before wp_head fires — so this method is a no-op.
	}

	/**
	 * Register all cleanup hooks.
	 * Called by Loader immediately (not deferred to wp_head).
	 */
	public function register_hooks(): void {
		$h = $this->settings;

		if ( $h->get( 'hygiene.remove_generator', true ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
		}

		if ( $h->get( 'hygiene.remove_oembed_links', true ) ) {
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		}

		if ( $h->get( 'hygiene.remove_shortlink', true ) ) {
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		}

		if ( $h->get( 'hygiene.remove_wlw_manifest', true ) ) {
			remove_action( 'wp_head', 'wlwmanifest_link' );
		}

		if ( $h->get( 'hygiene.remove_rsd_link', true ) ) {
			remove_action( 'wp_head', 'rsd_link' );
		}

		if ( $h->get( 'hygiene.remove_pingback_header', true ) ) {
			add_filter( 'wp_headers', [ $this, 'remove_pingback_header' ] );
			// Also remove from HTML head.
			remove_action( 'wp_head', 'wp_really_simple_discovery' );
		}

		if ( $h->get( 'hygiene.remove_x_powered_by', true ) ) {
			add_filter( 'wp_headers', [ $this, 'remove_x_powered_by' ] );
			// PHP header removal (best-effort — may be set by server config).
			if ( function_exists( 'header_remove' ) ) {
				@header_remove( 'X-Powered-By' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		if ( $h->get( 'hygiene.remove_wp_dns_prefetch', true ) ) {
			remove_action( 'wp_head', 'wp_resource_hints', 2 );
		}
	}

	public function remove_pingback_header( array $headers ): array {
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	public function remove_x_powered_by( array $headers ): array {
		unset( $headers['X-Powered-By'] );
		return $headers;
	}
}
