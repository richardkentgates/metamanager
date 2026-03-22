<?php
/**
 * MM_User_Meta_Panel — per-author SEO controls on the user profile page.
 *
 * Fields: Author archive title, description override, noindex toggle,
 * social profile links (Twitter, LinkedIn, Instagram, BlueSky, website),
 * Person schema override.
 *
 * Stores data under user-meta key MM_META_KEY.
 */

defined( 'ABSPATH' ) || exit;

class MM_User_Meta_Panel {

	/** @var MM_Site_Settings */
	private MM_Site_Settings $settings;

	/** Social profile fields. */
	const SOCIAL_FIELDS = [
		'twitter'   => 'Twitter / X (@handle)',
		'linkedin'  => 'LinkedIn Profile URL',
		'instagram' => 'Instagram URL',
		'bluesky'   => 'BlueSky Handle',
		'website'   => 'Personal Website URL',
	];

	public function __construct( MM_Site_Settings $settings ) {
		$this->settings = $settings;
	}

	public function register_hooks(): void {
		if ( ! $this->settings->get( 'authors.enabled', true ) ) {
			return;
		}
		add_action( 'show_user_profile',        [ $this, 'render_fields' ] );
		add_action( 'edit_user_profile',         [ $this, 'render_fields' ] );
		add_action( 'personal_options_update',   [ $this, 'save_meta' ] );
		add_action( 'edit_user_profile_update',  [ $this, 'save_meta' ] );
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	public function render_fields( \WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		$meta = $this->settings->get_user_meta( $user->ID );
		wp_nonce_field( 'mm_meta_user_meta_save', 'mm_meta_user_nonce' );
		include MM_META_DIR . 'templates/metabox/user-metadata.php';
	}

	// -------------------------------------------------------------------------
	// Save
	// -------------------------------------------------------------------------

	public function save_meta( int $user_id ): void {
		if ( ! isset( $_POST['mm_meta_user_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['mm_meta_user_nonce'] ), 'mm_meta_user_meta_save' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$clean = [];

		$title = sanitize_text_field( wp_unslash( $_POST['mm_meta_title'] ?? '' ) );
		$desc  = sanitize_textarea_field( wp_unslash( $_POST['mm_meta_description'] ?? '' ) );
		$noindex = $this->sanitize_tristate( wp_unslash( $_POST['mm_meta_noindex'] ?? '' ) );

		if ( $title )           { $clean['title']       = $title; }
		if ( $desc )            { $clean['description']  = $desc; }
		if ( null !== $noindex ) { $clean['noindex']     = $noindex; }

		// Social profiles.
		foreach ( array_keys( self::SOCIAL_FIELDS ) as $field ) {
			$val = sanitize_text_field( wp_unslash( $_POST[ 'mm_meta_social_' . $field ] ?? '' ) );
			if ( $val ) {
				if ( in_array( $field, [ 'linkedin', 'instagram', 'website' ], true ) ) {
					$val = esc_url_raw( $val );
				}
				$clean[ 'social_' . $field ] = $val;
			}
		}
		// phpcs:enable

		if ( empty( $clean ) ) {
			delete_user_meta( $user_id, MM_META_KEY );
		} else {
			update_user_meta( $user_id, MM_META_KEY, wp_json_encode( $clean ) );
		}
	}

	private function sanitize_tristate( $value ): ?bool {
		if ( $value === '' || $value === null ) {
			return null;
		}
		return (bool) (int) $value;
	}
}
