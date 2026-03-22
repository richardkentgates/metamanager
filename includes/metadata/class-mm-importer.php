<?php
/**
 * MM_Importer — migrate SEOPress data to mm-meta-core.
 *
 * Reads all four SEOPress option keys and per-post/term/user meta,
 * maps them to their mm-meta-core equivalents.
 *
 * Usage:
 *   $importer = new MM_Importer( $settings );
 *   $report   = $importer->dry_run();  // preview diff, no writes
 *   $report   = $importer->run( true ); // commit changes
 */

defined( 'ABSPATH' ) || exit;

class MM_Importer {

	/** @var MM_Site_Settings */
	private MM_Site_Settings $settings;

	public function __construct( MM_Site_Settings $settings ) {
		$this->settings = $settings;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	public function dry_run(): array {
		return $this->run( false );
	}

	/**
	 * @param bool $write  When true, actually commit the data.
	 * @return array  Summary report with counts and diff entries.
	 */
	public function run( bool $write = false ): array {
		$report = [
			'write'   => $write,
			'actions' => [],
		];

		// 1. Global settings migration.
		$this->import_global_settings( $write, $report );

		// 2. Per-post meta migration.
		$this->import_post_meta( $write, $report );

		// 3. Per-term meta migration.
		$this->import_term_meta( $write, $report );

		// 4. Per-user meta migration.
		$this->import_user_meta( $write, $report );

		$report['total'] = count( $report['actions'] );
		return $report;
	}

	// -------------------------------------------------------------------------
	// Global settings
	// -------------------------------------------------------------------------

	private function import_global_settings( bool $write, array &$report ): void {
		// SEOPress stores settings across multiple option keys.
		$sp_settings  = get_option( 'seopress_titles_option_name', [] );
		$sp_social    = get_option( 'seopress_social_option_name', [] );
		$sp_google    = get_option( 'seopress_google_analytics_option_name', [] );
		$sp_advanced  = get_option( 'seopress_advanced_option_name', [] );

		$gcm_settings = MM_Site_Settings::settings_defaults();
		$gcm_business = MM_Site_Settings::business_defaults();

		// --- Titles separator ---
		if ( ! empty( $sp_settings['seopress_titles_sep'] ) ) {
			$gcm_settings['titles']['separator'] = sanitize_text_field( $sp_settings['seopress_titles_sep'] );
			$report['actions'][] = [ 'type' => 'settings', 'key' => 'titles.separator', 'value' => $gcm_settings['titles']['separator'] ];
		}

		// --- Social accounts ---
		$sp_social_map = [
			'seopress_social_accounts_facebook'  => 'facebook',
			'seopress_social_accounts_twitter'   => 'twitter',
			'seopress_social_accounts_instagram' => 'instagram',
			'seopress_social_accounts_linkedin'  => 'linkedin',
			'seopress_social_accounts_youtube'   => 'youtube',
			'seopress_social_accounts_pinterest' => 'pinterest',
		];
		foreach ( $sp_social_map as $sp_key => $gcm_key ) {
			if ( ! empty( $sp_social[ $sp_key ] ) ) {
				$gcm_settings['social']['accounts'][ $gcm_key ] = sanitize_text_field( $sp_social[ $sp_key ] );
				$report['actions'][] = [ 'type' => 'settings', 'key' => "social.accounts.{$gcm_key}", 'value' => $gcm_settings['social']['accounts'][ $gcm_key ] ];
			}
		}

		// --- OG default image ---
		if ( ! empty( $sp_social['seopress_social_og_default_image'] ) ) {
			$gcm_settings['social']['og_default_image'] = esc_url_raw( $sp_social['seopress_social_og_default_image'] );
			$report['actions'][] = [ 'type' => 'settings', 'key' => 'social.og_default_image', 'value' => $gcm_settings['social']['og_default_image'] ];
		}

		// --- Twitter site handle ---
		if ( ! empty( $sp_social['seopress_social_twitter_card_twitter_account'] ) ) {
			$gcm_settings['social']['twitter_site'] = sanitize_text_field( $sp_social['seopress_social_twitter_card_twitter_account'] );
			$report['actions'][] = [ 'type' => 'settings', 'key' => 'social.twitter_site', 'value' => $gcm_settings['social']['twitter_site'] ];
		}

		// --- sitemap enabled ---
		if ( isset( $sp_advanced['seopress_advanced_security_xml_sitemap_disabled'] ) &&
			'1' === $sp_advanced['seopress_advanced_security_xml_sitemap_disabled'] ) {
			$gcm_settings['sitemap']['enabled'] = false;
			$report['actions'][] = [ 'type' => 'settings', 'key' => 'sitemap.enabled', 'value' => false ];
		}

		// --- Hygiene options ---
		if ( ! empty( $sp_advanced['seopress_advanced_advanced_wp_generator'] ) ) {
			$gcm_settings['hygiene']['remove_generator'] = true;
			$report['actions'][] = [ 'type' => 'settings', 'key' => 'hygiene.remove_generator', 'value' => true ];
		}

		if ( $write ) {
			update_option( MM_META_OPT_SETTINGS, $gcm_settings );
			update_option( MM_META_OPT_BUSINESS, $gcm_business );
		}
	}

	// -------------------------------------------------------------------------
	// Per-post meta
	// -------------------------------------------------------------------------

	private function import_post_meta( bool $write, array &$report ): void {
		global $wpdb;

		// SEOPress uses individual postmeta keys, not a JSON blob.
		$sp_keys = [
			'_seopress_titles_title'       => 'title',
			'_seopress_titles_desc'        => 'description',
			'_seopress_titles_canonical'   => 'canonical',
			'_seopress_robots_index'       => 'noindex',
			'_seopress_robots_follow'      => 'nofollow',
			'_seopress_robots_archive'     => 'noarchive',
			'_seopress_robots_snippet'     => 'nosnippet',
			'_seopress_robots_imageindex'  => 'noimageindex',
			'_seopress_social_fb_title'    => 'og_title',
			'_seopress_social_fb_desc'     => 'og_description',
			'_seopress_social_fb_img'      => 'og_image_url',
		];

		foreach ( $sp_keys as $sp_key => $gcm_key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
				$sp_key
			), ARRAY_A );

			foreach ( $rows as $row ) {
				$post_id = (int) $row['post_id'];
				$raw     = $row['meta_value'];
				$value   = $this->map_post_meta_value( $gcm_key, $raw );

				if ( null === $value ) {
					continue;
				}

				$existing = $this->settings->get_post_meta( $post_id );
				$existing[ $gcm_key ] = $value;

				$report['actions'][] = [
					'type'    => 'post_meta',
					'post_id' => $post_id,
					'key'     => $gcm_key,
					'value'   => $value,
				];

				if ( $write ) {
					$json = wp_json_encode( $existing );
					update_post_meta( $post_id, MM_META_KEY, $json );
				}
			}
		}
	}

	// -------------------------------------------------------------------------
	// Per-term meta
	// -------------------------------------------------------------------------

	private function import_term_meta( bool $write, array &$report ): void {
		$sp_term_keys = [
			'_seopress_titles_title' => 'title',
			'_seopress_titles_desc'  => 'description',
			'_seopress_robots_index' => 'noindex',
		];

		foreach ( $sp_term_keys as $sp_key => $gcm_key ) {
			$terms = get_terms( [
				'taxonomy'   => get_taxonomies( [ 'public' => true ] ),
				'hide_empty' => false,
				'meta_key'   => $sp_key, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare' => '!=',
				'meta_value'   => '',
			] );

			if ( is_wp_error( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$raw   = get_term_meta( $term->term_id, $sp_key, true );
				$value = $this->map_post_meta_value( $gcm_key, $raw );

				if ( null === $value ) {
					continue;
				}

				$existing             = $this->settings->get_term_meta( $term->term_id );
				$existing[ $gcm_key ] = $value;

				$report['actions'][] = [
					'type'    => 'term_meta',
					'term_id' => $term->term_id,
					'key'     => $gcm_key,
					'value'   => $value,
				];

				if ( $write ) {
					update_term_meta( $term->term_id, MM_META_KEY, wp_json_encode( $existing ) );
				}
			}
		}
	}

	// -------------------------------------------------------------------------
	// Per-user meta
	// -------------------------------------------------------------------------

	private function import_user_meta( bool $write, array &$report ): void {
		$users = get_users( [ 'fields' => 'ID' ] );
		foreach ( $users as $user_id ) {
			$sp_title = get_user_meta( $user_id, '_seopress_titles_title', true );
			$sp_desc  = get_user_meta( $user_id, '_seopress_titles_desc',  true );

			$existing = $this->settings->get_user_meta( $user_id );
			$changed  = false;

			if ( $sp_title ) {
				$existing['title'] = sanitize_text_field( $sp_title );
				$changed = true;
				$report['actions'][] = [ 'type' => 'user_meta', 'user_id' => $user_id, 'key' => 'title', 'value' => $existing['title'] ];
			}
			if ( $sp_desc ) {
				$existing['description'] = sanitize_textarea_field( $sp_desc );
				$changed = true;
				$report['actions'][] = [ 'type' => 'user_meta', 'user_id' => $user_id, 'key' => 'description', 'value' => $existing['description'] ];
			}

			if ( $write && $changed ) {
				update_user_meta( $user_id, MM_META_KEY, wp_json_encode( $existing ) );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Value mapping
	// -------------------------------------------------------------------------

	private function map_post_meta_value( string $gcm_key, $raw ) {
		switch ( $gcm_key ) {
			case 'title':
				return sanitize_text_field( $this->translate_template_vars( (string) $raw ) );

			case 'description':
			case 'og_title':
			case 'og_description':
				return sanitize_textarea_field( $this->translate_template_vars( (string) $raw ) );

			case 'canonical':
				$url = esc_url_raw( (string) $raw );
				return $url ?: null;

			case 'og_image_url':
				return esc_url_raw( (string) $raw ) ?: null;

			// SEOPress stores 'yes' for noindex, empty for follow.
			case 'noindex':
				return ( 'yes' === $raw ) ? true : null;

			case 'nofollow':
				return ( 'yes' === $raw ) ? true : null;

			case 'noarchive':
				return ( 'yes' === $raw ) ? true : null;

			case 'nosnippet':
				return ( 'yes' === $raw ) ? true : null;

			case 'noimageindex':
				return ( 'yes' === $raw ) ? true : null;

			default:
				return null;
		}
	}

	/**
	 * Translate SEOPress %%variables%% to GCM equivalents.
	 *
	 * SEOPress and GCM share most variable names so this is mostly a pass-through,
	 * but a few differ.
	 */
	private function translate_template_vars( string $str ): string {
		$map = [
			'%%post_title%%'           => '%%post_title%%',
			'%%page_title%%'           => '%%post_title%%',
			'%%sitetitle%%'            => '%%sitetitle%%',
			'%%sep%%'                  => '%%sep%%',
			'%%tagline%%'              => '%%tagline%%',
			'%%category_title%%'       => '%%term_title%%',
			'%%tag_title%%'            => '%%term_title%%',
			'%%term_title%%'           => '%%term_title%%',
			'%%term_description%%'     => '%%term_description%%',
			'%%author%%'               => '%%author_name%%',
			'%%post_excerpt%%'         => '%%post_excerpt%%',
			// Strip SEOPress-only vars that have no GCM equivalent.
			'%%post_content%%'         => '',
			'%%post_modified_date%%'   => '',
		];
		return str_replace( array_keys( $map ), array_values( $map ), $str );
	}
}
