<?php
/**
 * MM_Post_Meta_Panel — per-post SEO control panel.
 *
 * Fields:
 *   SEO Title (override)          Meta Description (0-160 counter + live preview)
 *   Canonical URL override        noindex / nofollow / noarchive / nosnippet / noimageindex
 *   OG Title / Description        OG Image (media picker)
 *   Schema type override          Breadcrumb label
 *   Exclude from sitemap          Priority sitemap (0.0–1.0)
 */

defined( 'ABSPATH' ) || exit;

class MM_Post_Meta_Panel {

	/** @var MM_Site_Settings */
	private MM_Site_Settings $settings;

	/** Post types that should NOT have the SEO metabox. */
	private array $excluded_types = [ 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset' ];

	public function __construct( MM_Site_Settings $settings ) {
		$this->settings = $settings;
	}

	public function register_hooks(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post',      [ $this, 'save_meta' ], 5, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	// -------------------------------------------------------------------------
	// Meta box registration
	// -------------------------------------------------------------------------

	public function add_meta_boxes(): void {
		$post_types = get_post_types( [ 'public' => true ], 'names' );
		foreach ( $post_types as $pt ) {
			if ( in_array( $pt, $this->excluded_types, true ) ) {
				continue;
			}
			add_meta_box(
				'mm_meta_post_meta',
				'<span class="mm-meta-metabox-title">Metamanager</span>',
				[ $this, 'render_metabox' ],
				$pt,
				'normal',
				'high'
			);
		}
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	public function render_metabox( \WP_Post $post ): void {
		$meta     = $this->settings->get_post_meta( $post->ID );
		$settings = $this->settings;
		wp_nonce_field( 'mm_meta_post_meta_save', 'mm_meta_post_nonce' );
			include MM_META_DIR . 'templates/metabox/post-metadata.php';
	}

	// -------------------------------------------------------------------------
	// Save
	// -------------------------------------------------------------------------

	public function save_meta( int $post_id, \WP_Post $post ): void {
		// Skip revisions, auto-saves, and AJAX non-metabox saves.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['mm_meta_post_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['mm_meta_post_nonce'] ), 'mm_meta_post_meta_save' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$existing = $this->settings->get_post_meta( $post_id );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$new = [
			'title'          => sanitize_text_field( wp_unslash( $_POST['mm_meta_title'] ?? '' ) ),
			'description'    => sanitize_textarea_field( wp_unslash( $_POST['mm_meta_description'] ?? '' ) ),
			'canonical'      => esc_url_raw( wp_unslash( $_POST['mm_meta_canonical'] ?? '' ) ),
			'noindex'        => $this->sanitize_tristate( wp_unslash( $_POST['mm_meta_noindex'] ?? '' ) ),
			'nofollow'       => $this->sanitize_tristate( wp_unslash( $_POST['mm_meta_nofollow'] ?? '' ) ),
			'noarchive'      => $this->sanitize_tristate( wp_unslash( $_POST['mm_meta_noarchive'] ?? '' ) ),
			'nosnippet'      => $this->sanitize_tristate( wp_unslash( $_POST['mm_meta_nosnippet'] ?? '' ) ),
			'noimageindex'   => $this->sanitize_tristate( wp_unslash( $_POST['mm_meta_noimageindex'] ?? '' ) ),
			'og_title'       => sanitize_text_field( wp_unslash( $_POST['mm_meta_og_title'] ?? '' ) ),
			'og_description' => sanitize_textarea_field( wp_unslash( $_POST['mm_meta_og_description'] ?? '' ) ),
			'og_image_id'    => (int) ( $_POST['mm_meta_og_image_id'] ?? 0 ),
			'og_image_url'   => esc_url_raw( wp_unslash( $_POST['mm_meta_og_image_url'] ?? '' ) ),
			'schema_type'    => sanitize_key( wp_unslash( $_POST['mm_meta_schema_type'] ?? '' ) ),
			'breadcrumb_label' => sanitize_text_field( wp_unslash( $_POST['mm_meta_breadcrumb_label'] ?? '' ) ),
			'exclude_sitemap'  => ! empty( $_POST['mm_meta_exclude_sitemap'] ),
		];

		// Schema field overrides.
		$raw_schema_fields = wp_unslash( $_POST['mm_meta_schema_fields'] ?? [] );
		if ( is_array( $raw_schema_fields ) ) {
			$clean_schema_fields = [];
			foreach ( $raw_schema_fields as $sf_key => $sf_val ) {
				$sf_key = sanitize_key( $sf_key );
				$sf_val = sanitize_text_field( wp_unslash( $sf_val ) );
				if ( $sf_key !== '' && $sf_val !== '' ) {
					$clean_schema_fields[ $sf_key ] = $sf_val;
				}
			}
			if ( ! empty( $clean_schema_fields ) ) {
				$new['schema_fields'] = $clean_schema_fields;
			}
		}
		// phpcs:enable

		// Strip empty fields to save space; keep null tristates.
		$clean = [];
		foreach ( $new as $k => $v ) {
			if ( $v === '' || $v === 0 || $v === false ) {
				// Only store if it differs from the default (unset = use global default).
				continue;
			}
			$clean[ $k ] = $v;
		}

		// Always persist tristate values even when null (to handle explicit default).
		foreach ( [ 'noindex', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex' ] as $f ) {
			if ( $new[ $f ] !== null ) {
				$clean[ $f ] = $new[ $f ];
			} elseif ( isset( $existing[ $f ] ) ) {
				// User cleared to "default" — remove stored override.
				unset( $clean[ $f ] );
			}
		}

		if ( empty( $clean ) ) {
			delete_post_meta( $post_id, MM_META_KEY );
		} else {
			update_post_meta( $post_id, MM_META_KEY, wp_json_encode( $clean ) );
		}
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style(
			'mm-meta-admin',
			MM_META_URL . 'assets/css/admin.css',
			[],
			MM_META_VERSION
		);
		wp_enqueue_script(
			'mm-meta-admin-metabox',
			MM_META_URL . 'assets/js/admin-metabox.js',
			[ 'jquery' ],
			MM_META_VERSION,
			true
		);
		wp_enqueue_script(
			'mm-meta-admin-media',
			MM_META_URL . 'assets/js/admin-media.js',
			[ 'jquery' ],
			MM_META_VERSION,
			true
		);
		wp_localize_script( 'mm-meta-admin-metabox', 'mmMetabox', [
			'sep'       => esc_js( $this->settings->get( 'titles.separator', '|' ) ),
			'sitetitle' => esc_js( get_bloginfo( 'name' ) ),
		] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Sanitize a tristate field: '' → null, '1' → true, '0' → false.
	 */
	private function sanitize_tristate( $value ): ?bool {
		if ( $value === '' || $value === null ) {
			return null;
		}
		return (bool) (int) $value;
	}
}
