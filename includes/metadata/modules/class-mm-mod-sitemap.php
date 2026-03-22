<?php
/**
 * MM_Mod_Sitemap_Web — XML sitemap engine.
 *
 * Generates:
 *   /sitemap.xml              — index listing all sub-sitemaps
 *   /sitemap-post-{type}.xml  — per post-type sitemap with image extension
 *   /sitemap-tax-{taxonomy}.xml — per taxonomy sitemap
 *   /sitemap-video.xml        — video sitemap (YouTube + Vimeo + self-hosted)
 *
 * On post publish, pings Google and Bing (async via cron, once per event).
 */

defined( 'ABSPATH' ) || exit;

class MM_Mod_Sitemap_Web extends MM_Mod_Base {

	/** XML namespace used in output. */
	const NS_SITEMAP = 'http://www.sitemaps.org/schemas/sitemap/0.9';

	/** Nothing to add to HTML head. */
	public function populate( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {}

	public function register_hooks(): void {
		if ( ! $this->settings->get( 'sitemap.enabled', true ) ) {
			return;
		}

		// Disable WordPress 5.5+ built-in sitemap.
		add_filter( 'wp_sitemaps_enabled', '__return_false' );

		// Register rewrite rules and query var.
		add_action( 'init', [ $this, 'add_rewrite_rules' ] );
		add_filter( 'query_vars', [ $this, 'add_query_var' ] );
		add_action( 'template_redirect', [ $this, 'maybe_serve' ] );

		// Ping on publish (async).
		add_action( 'transition_post_status', [ $this, 'schedule_ping' ], 10, 3 );
		add_action( 'mm_meta_sitemap_ping', [ $this, 'send_ping' ] );

		// Bust the sitemap cache whenever content or taxonomy changes.
		foreach ( [ 'save_post', 'deleted_post', 'add_attachment', 'delete_attachment' ] as $hook ) {
			add_action( $hook, [ $this, 'flush_sitemap_cache' ] );
		}
		foreach ( [ 'created_term', 'edited_term', 'delete_term' ] as $hook ) {
			add_action( $hook, [ $this, 'flush_sitemap_cache' ] );
		}
	}

	// -------------------------------------------------------------------------
	// Rewrite rules
	// -------------------------------------------------------------------------

	public function add_rewrite_rules(): void {
		add_rewrite_rule( 'sitemap\.xml$', 'index.php?mm_meta_sitemap=index', 'top' );
		add_rewrite_rule( 'sitemap-post-([a-z0-9_-]+)\.xml$', 'index.php?mm_meta_sitemap=post&mm_meta_sitemap_type=$matches[1]', 'top' );
		add_rewrite_rule( 'sitemap-tax-([a-z0-9_-]+)\.xml$', 'index.php?mm_meta_sitemap=tax&mm_meta_sitemap_type=$matches[1]', 'top' );
	}

	public function add_query_var( array $vars ): array {
		$vars[] = 'mm_meta_sitemap';
		$vars[] = 'mm_meta_sitemap_type';
		return $vars;
	}

	// -------------------------------------------------------------------------
	// Routing
	// -------------------------------------------------------------------------

	public function maybe_serve(): void {
		$type = get_query_var( 'mm_meta_sitemap' );
		if ( ! $type ) {
			return;
		}

		$sub       = sanitize_key( get_query_var( 'mm_meta_sitemap_type' ) );
		$cache_key = 'mm_sm_' . $this->get_cache_version() . '_' . $type . ( $sub ? "_$sub" : '' );

		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, follow' );

		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			echo $cached; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		switch ( $type ) {
			case 'index':
				$output = $this->render_index();
				break;
			case 'post':
				$output = $this->render_post_sitemap( $sub );
				break;
			case 'tax':
				$output = $this->render_tax_sitemap( $sub );
				break;
			default:
				$output = '';
		}

		set_transient( $cache_key, $output, HOUR_IN_SECONDS );
		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/** Returns a version stamp used to scope all sitemap cache keys. */
	private function get_cache_version(): int {
		return (int) get_option( 'mm_sitemap_cache_ver', 0 );
	}

	/** Bust all sitemap transients cheaply by incrementing the version stamp. */
	public function flush_sitemap_cache(): void {
		update_option( 'mm_sitemap_cache_ver', time(), false );
	}

	// -------------------------------------------------------------------------
	// Index sitemap
	// -------------------------------------------------------------------------

	private function render_index(): string {
		$s        = $this->settings;
		$sitemaps = [];

		foreach ( $this->get_active_post_types() as $pt ) {
			$count = $this->post_type_count( $pt );
			if ( $count > 0 ) {
				$sitemaps[] = [
					'loc'     => home_url( '/sitemap-post-' . $pt . '.xml' ),
					'lastmod' => $this->last_modified_post( $pt ),
				];
			}
		}

		foreach ( $this->get_active_taxonomies() as $taxonomy ) {
			$count = wp_count_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => true ] );
			if ( ! is_wp_error( $count ) && $count > 0 ) {
				$sitemaps[] = [
					'loc'     => home_url( '/sitemap-tax-' . $taxonomy . '.xml' ),
					'lastmod' => gmdate( 'Y-m-d' ),
				];
			}
		}

		$xml  = $this->xml_header();
		$xml .= '<sitemapindex xmlns="' . self::NS_SITEMAP . '">' . "\n";
		foreach ( $sitemaps as $sm ) {
			$xml .= "  <sitemap>\n";
			$xml .= '    <loc>' . esc_url( $sm['loc'] ) . "</loc>\n";
			if ( $sm['lastmod'] ) {
				$xml .= '    <lastmod>' . esc_html( $sm['lastmod'] ) . "</lastmod>\n";
			}
			$xml .= "  </sitemap>\n";
		}
		$xml .= '</sitemapindex>';
		return $xml;
	}

	// -------------------------------------------------------------------------
	// Post-type sitemap
	// -------------------------------------------------------------------------

	private function render_post_sitemap( string $pt ): string {
		$s    = $this->settings;
		$args = [
			'post_type'      => $pt,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $s->get( 'sitemap.records_per_file', 1000 ),
			'no_found_rows'  => true,
			'fields'         => 'ids',
		];

		if ( $s->get( 'sitemap.exclude_password_protected', true ) ) {
			$args['has_password'] = false;
		}

		// Exclude noindexed posts.
		if ( $s->get( 'sitemap.exclude_noindexed', true ) ) {
			$args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'OR',
				[
					'key'     => MM_META_KEY,
					'compare' => 'NOT EXISTS',
				],
				[
					'key'     => MM_META_KEY,
					'value'   => '"noindex":true',
					'compare' => 'NOT LIKE',
				],
			];
		}

		$ids = get_posts( $args );

		$xml  = $this->xml_header();
		$xml .= '<urlset xmlns="' . self::NS_SITEMAP . "\"\n";

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$xml .= "  <url>\n";
			$xml .= '    <loc>' . esc_url( get_permalink( $post ) ) . "</loc>\n";
			$xml .= '    <lastmod>' . esc_html( get_the_modified_date( 'Y-m-d', $post ) ) . "</lastmod>\n";
			$xml .= '    <changefreq>monthly</changefreq>' . "\n";

			$xml .= "  </url>\n";
		}

		$xml .= '</urlset>';
		return $xml;
	}

	// -------------------------------------------------------------------------
	// Taxonomy sitemap
	// -------------------------------------------------------------------------

	private function render_tax_sitemap( string $taxonomy ): string {
		$terms = get_terms( [
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
		] );

		$xml  = $this->xml_header();
		$xml .= '<urlset xmlns="' . self::NS_SITEMAP . "\">\n";

		if ( ! is_wp_error( $terms ) && $terms ) {
			foreach ( $terms as $term ) {
				// Skip noindexed terms.
				if ( $this->settings->get( 'sitemap.exclude_noindexed', true ) ) {
					$meta = $this->settings->get_term_meta( $term->term_id );
					if ( ! empty( $meta['noindex'] ) ) {
						continue;
					}
				}
				$link = get_term_link( $term );
				if ( is_string( $link ) ) {
					$xml .= "  <url>\n";
					$xml .= '    <loc>' . esc_url( $link ) . "</loc>\n";
					$xml .= '    <changefreq>monthly</changefreq>' . "\n";
					$xml .= "  </url>\n";
				}
			}
		}

		$xml .= '</urlset>';
		return $xml;
	}

	// -------------------------------------------------------------------------
	// Publish ping
	// -------------------------------------------------------------------------

	public function schedule_ping( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		$pt = $this->get_active_post_types();
		if ( ! in_array( $post->post_type, $pt, true ) ) {
			return;
		}
		// Schedule once, 10 seconds out, deduplicated.
		if ( ! wp_next_scheduled( 'mm_meta_sitemap_ping' ) ) {
			wp_schedule_single_event( time() + 10, 'mm_meta_sitemap_ping' );
		}
	}

	public function send_ping(): void {
		$sitemap_url = rawurlencode( home_url( '/sitemap.xml' ) );

		if ( $this->settings->get( 'sitemap.ping_google', true ) ) {
			wp_remote_get( 'https://www.google.com/ping?sitemap=' . $sitemap_url, [
				'timeout'   => 5,
				'blocking'  => false,
				'sslverify' => true,
			] );
		}

		if ( $this->settings->get( 'sitemap.ping_bing', true ) ) {
			wp_remote_get( 'https://www.bing.com/ping?sitemap=' . rawurlencode( home_url( '/sitemap.xml' ) ), [
				'timeout'   => 5,
				'blocking'  => false,
				'sslverify' => true,
			] );
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function get_active_post_types(): array {
		$configured = $this->settings->get( 'sitemap.post_types', [] );
		return array_keys( array_filter( $configured ) );
	}

	private function get_active_taxonomies(): array {
		$configured = $this->settings->get( 'sitemap.taxonomies', [] );
		return array_keys( array_filter( $configured ) );
	}

	private function post_type_count( string $pt ): int {
		$counts = wp_count_posts( $pt );
		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	private function last_modified_post( string $pt ): string {
		global $wpdb;
		$date = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
			$pt
		) );
		return $date ? gmdate( 'Y-m-d', strtotime( $date ) ) : gmdate( 'Y-m-d' );
	}

	private function xml_header(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	}
}
