<?php
/**
 * MM_Mod_Business_Contact — Business contact card (widget, block, shortcode).
 *
 * Renders a stylable business contact card sourced entirely from the business
 * profile stored by this plugin. Provides:
 *   - WordPress Widget  (classic widget areas)
 *   - Gutenberg Block   (metamanager/business-contact)
 *   - Shortcode         [gcm_business_contact]
 *   - Secure server-side download endpoints for vCard (.vcf), JSON, and CSV
 *   - Inline schema.org JSON-LD scoped to each rendered card instance
 *
 * Style and visibility settings are sitewide, managed at SEO → Contact Card.
 */

defined( 'ABSPATH' ) || exit;

class MM_Mod_Business_Contact extends MM_Mod_Base {

	/** WordPress option key for sitewide contact-card style settings. */
	const OPT_STYLE = 'mm_meta_contact_style';

	/** Settings API option group for the Contact Card admin page. */
	const OPT_GROUP = 'mm_meta_contact_group';

	/** Nothing to write into the <head> document graph. */
	public function populate( array &$data, MM_Page_Context $context, MM_Site_Settings $settings ): void {}

	public function register_hooks(): void {
		add_action( 'init',               [ $this, 'add_rewrite_rules' ] );
		add_action( 'init',               [ $this, 'register_block' ] );
		add_filter( 'query_vars',         [ $this, 'add_query_var' ] );
		add_action( 'template_redirect',  [ $this, 'maybe_serve_download' ] );
		add_action( 'widgets_init',       [ $this, 'register_widget' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
		add_shortcode( 'gcm_business_contact', [ $this, 'render_shortcode' ] );
	}

	// -------------------------------------------------------------------------
	// Download endpoints
	// -------------------------------------------------------------------------

	public function add_rewrite_rules(): void {
		add_rewrite_rule(
			'^gcm-biz-export/(vcard|json|csv)/?$',
			'index.php?gcm_biz_export=$matches[1]',
			'top'
		);
	}

	public function add_query_var( array $vars ): array {
		$vars[] = 'gcm_biz_export';
		return $vars;
	}

	public function maybe_serve_download(): void {
		$format = get_query_var( 'gcm_biz_export' );
		if ( ! $format ) {
			return;
		}

		if ( ! in_array( $format, [ 'vcard', 'json', 'csv' ], true ) ) {
			wp_die( esc_html__( 'Invalid export format.', 'metamanager' ), '', [ 'response' => 400 ] );
		}

		$biz = $this->settings->all_business();
		if ( empty( $biz['name'] ) ) {
			wp_die( esc_html__( 'No business profile configured.', 'metamanager' ), '', [ 'response' => 404 ] );
		}

		nocache_headers();

		switch ( $format ) {
			case 'vcard':
				$this->serve_vcard( $biz );
				break;
			case 'json':
				$this->serve_json( $biz );
				break;
			case 'csv':
				$this->serve_csv( $biz );
				break;
		}
		exit;
	}

	private function serve_vcard( array $biz ): void {
		$name    = sanitize_text_field( $biz['name']     ?? '' );
		$phone   = sanitize_text_field( $biz['phone']    ?? '' );
		$email   = sanitize_email( $biz['email']         ?? '' );
		$url     = trailingslashit( home_url() );
		$addr    = $biz['address'] ?? [];
		$lat     = isset( $biz['lat'] ) && is_numeric( $biz['lat'] )  ? (float) $biz['lat']  : null;
		$lng     = isset( $biz['lng'] ) && is_numeric( $biz['lng'] )  ? (float) $biz['lng']  : null;
		$logo    = esc_url_raw( $biz['logo_url'] ?? '' );
		$slug    = sanitize_file_name( strtolower( str_replace( ' ', '-', $name ) ) ) ?: 'business';

		$lines = [
			'BEGIN:VCARD',
			'VERSION:3.0',
			'FN:' . $name,
			'ORG:' . $name,
			'KIND:org',
		];

		if ( $phone ) { $lines[] = 'TEL;TYPE=WORK,VOICE:' . $phone; }
		if ( $email ) { $lines[] = 'EMAIL;TYPE=WORK:' . $email; }
		if ( $url   ) { $lines[] = 'URL:' . $url; }

		if ( ! empty( $addr['street'] ) ) {
			$lines[] = 'ADR;TYPE=WORK:;;'
				. sanitize_text_field( $addr['street']  ?? '' ) . ';'
				. sanitize_text_field( $addr['city']    ?? '' ) . ';'
				. sanitize_text_field( $addr['state']   ?? '' ) . ';'
				. sanitize_text_field( $addr['zip']     ?? '' ) . ';'
				. sanitize_text_field( $addr['country'] ?? 'US' );
		}

		if ( $lat !== null && $lng !== null ) {
			$lines[] = 'GEO:' . $lat . ';' . $lng;
		}

		if ( $logo ) { $lines[] = 'PHOTO;VALUE=URI:' . $logo; }

		$lines[] = 'END:VCARD';

		header( 'Content-Type: text/vcard; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $slug . '.vcf"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo implode( "\r\n", $lines ) . "\r\n";
	}

	private function serve_json( array $biz ): void {
		$name = sanitize_text_field( $biz['name'] ?? '' );
		$addr = $biz['address'] ?? [];
		$slug = sanitize_file_name( strtolower( str_replace( ' ', '-', $name ) ) ) ?: 'business';

		$lat = isset( $biz['lat'] ) && is_numeric( $biz['lat'] ) ? (float) $biz['lat'] : null;
		$lng = isset( $biz['lng'] ) && is_numeric( $biz['lng'] ) ? (float) $biz['lng'] : null;

		$data = [
			'name'    => $name,
			'phone'   => sanitize_text_field( $biz['phone'] ?? '' ),
			'email'   => sanitize_email( $biz['email'] ?? '' ),
			'url'     => trailingslashit( home_url() ),
			'address' => array_filter( [
				'street'  => sanitize_text_field( $addr['street']  ?? '' ),
				'city'    => sanitize_text_field( $addr['city']    ?? '' ),
				'state'   => sanitize_text_field( $addr['state']   ?? '' ),
				'zip'     => sanitize_text_field( $addr['zip']     ?? '' ),
				'country' => sanitize_text_field( $addr['country'] ?? '' ),
			] ),
		];
		if ( null !== $lat ) { $data['lat'] = $lat; }
		if ( null !== $lng ) { $data['lng'] = $lng; }

		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $slug . '.json"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	private function serve_csv( array $biz ): void {
		$name = sanitize_text_field( $biz['name'] ?? '' );
		$addr = $biz['address'] ?? [];
		$slug = sanitize_file_name( strtolower( str_replace( ' ', '-', $name ) ) ) ?: 'business';

		$headers = [ 'Name', 'Phone', 'Email', 'Website', 'Street', 'City', 'State', 'ZIP', 'Country', 'Latitude', 'Longitude' ];
		$row     = [
			$name,
			sanitize_text_field( $biz['phone'] ?? '' ),
			sanitize_email( $biz['email'] ?? '' ),
			trailingslashit( home_url() ),
			sanitize_text_field( $addr['street']  ?? '' ),
			sanitize_text_field( $addr['city']    ?? '' ),
			sanitize_text_field( $addr['state']   ?? '' ),
			sanitize_text_field( $addr['zip']     ?? '' ),
			sanitize_text_field( $addr['country'] ?? '' ),
			isset( $biz['lat'] ) && is_numeric( $biz['lat'] ) ? (float) $biz['lat'] : '',
			isset( $biz['lng'] ) && is_numeric( $biz['lng'] ) ? (float) $biz['lng'] : '',
		];

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $slug . '.csv"' );

		ob_start();
		$fp = fopen( 'php://output', 'wb' );
		fputcsv( $fp, $headers );
		fputcsv( $fp, $row );
		fclose( $fp );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Widget
	// -------------------------------------------------------------------------

	public function register_widget(): void {
		register_widget( 'MM_Business_Contact_Widget' );
	}

	// -------------------------------------------------------------------------
	// Gutenberg block
	// -------------------------------------------------------------------------

	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'mm-meta-block-business-contact',
			MM_META_URL . 'assets/js/block-business-contact.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n' ],
			MM_META_VERSION,
			true
		);
		wp_localize_script( 'mm-meta-block-business-contact', 'gcmBizBlock', [
			'settingsUrl' => admin_url( 'admin.php?page=mm-meta-contact' ),
		] );

		register_block_type( 'metamanager/business-contact', [
			'editor_script'   => 'mm-meta-block-business-contact',
			'render_callback' => [ $this, 'render_card' ],
			'attributes'      => [],
		] );
	}

	// -------------------------------------------------------------------------
	// Shortcode
	// -------------------------------------------------------------------------

	public function render_shortcode( $atts ): string {
		return $this->render_card( [] );
	}

	// -------------------------------------------------------------------------
	// Frontend assets
	// -------------------------------------------------------------------------

	public function enqueue_frontend_assets(): void {
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style(
			'mm-meta-biz-contact',
			MM_META_URL . 'assets/css/biz-contact.css',
			[ 'dashicons' ],
			MM_META_VERSION
		);
		// Attach the generated sitewide card CSS as a single inline block so it
		// is emitted once in <head> regardless of how many cards are on the page.
		$css = MM_Biz_Card_CSS::generate( self::get_style_settings() );
		if ( $css ) {
			wp_add_inline_style( 'mm-meta-biz-contact', $css );
		}
	}

	// -------------------------------------------------------------------------
	// Card rendering — shared by widget, block, and shortcode
	// -------------------------------------------------------------------------

	/**
	 * Build and return the complete contact card HTML with inline CSS and schema.
	 *
	 * @param array $attributes Block attributes (unused — all settings are sitewide).
	 * @return string
	 */
	public function render_card( array $attributes = [] ): string {
		$biz   = $this->settings->all_business();
		$style = self::get_style_settings();

		if ( empty( $biz['name'] ) ) {
			return '';
		}

		$name    = $biz['name'];
		$phone   = $biz['phone'] ?? '';
		$email   = sanitize_email( $biz['email'] ?? '' );
		$addr    = $biz['address'] ?? [];
		$type    = sanitize_text_field( $biz['type'] ?? 'LocalBusiness' );
		$schema  = wp_json_encode( $this->build_schema( $biz, $style ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '{}';

		$has_address = ! empty( $addr['street'] );
		$show_phone  = ! empty( $style['show_phone'] ) && $phone;
		$show_sms    = ! empty( $style['show_sms'] )   && $phone;
		$show_email  = ! empty( $style['show_email'] ) && $email;
		$show_vcard  = ! empty( $style['show_vcard'] );
		$show_json   = ! empty( $style['show_json'] );
		$show_csv    = ! empty( $style['show_csv'] );

		ob_start();
		?>
		<div class="gcm-biz-card" itemscope itemtype="https://schema.org/<?php echo esc_attr( $type ); ?>">

			<?php if ( ! empty( $biz['logo_url'] ) ) : ?>
			<div class="gcm-biz-card__logo">
				<img src="<?php echo esc_url( $biz['logo_url'] ); ?>"
				     alt="<?php echo esc_attr( $name ); ?>"
				     itemprop="logo">
			</div>
			<?php endif; ?>

			<div class="gcm-biz-card__name" itemprop="name"><?php echo esc_html( $name ); ?></div>

			<?php if ( $has_address ) : ?>
			<div class="gcm-biz-card__address"
			     itemprop="address"
			     itemscope itemtype="https://schema.org/PostalAddress">
				<?php if ( ! empty( $addr['street'] ) ) : ?>
					<span itemprop="streetAddress"><?php echo esc_html( $addr['street'] ); ?></span><br>
				<?php endif; ?>
				<?php
				$city  = $addr['city']  ?? '';
				$state = $addr['state'] ?? '';
				$zip   = $addr['zip']   ?? '';
				if ( $city || $state || $zip ) :
				?>
					<span itemprop="addressLocality"><?php echo esc_html( $city ); ?></span><?php echo ( $city && $state ) ? ', ' : ''; ?><span itemprop="addressRegion"><?php echo esc_html( $state ); ?></span><?php echo $zip ? ' ' . esc_html( $zip ) : ''; ?>
				<?php endif; ?>
				<?php if ( ! empty( $addr['country'] ) ) : ?>
					<br><span itemprop="addressCountry"><?php echo esc_html( $addr['country'] ); ?></span>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<div class="gcm-biz-card__actions">

				<?php if ( $show_phone ) : ?>
				<a href="tel:<?php echo esc_attr( $phone ); ?>"
				   class="gcm-biz-card__btn gcm-biz-card__btn--call"
				   itemprop="telephone">
					<span class="dashicons dashicons-phone" aria-hidden="true"></span>
					<span><?php echo esc_html( $phone ); ?></span>
				</a>
				<?php endif; ?>

				<?php if ( $show_sms ) : ?>
				<a href="sms:<?php echo esc_attr( $phone ); ?>"
				   class="gcm-biz-card__btn gcm-biz-card__btn--sms">
					<span class="dashicons dashicons-smartphone" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Send SMS', 'metamanager' ); ?></span>
				</a>
				<?php endif; ?>

				<?php if ( $show_email ) : ?>
				<a href="mailto:<?php echo esc_attr( $email ); ?>"
				   class="gcm-biz-card__btn gcm-biz-card__btn--email"
				   itemprop="email">
					<span class="dashicons dashicons-email" aria-hidden="true"></span>
					<span><?php echo esc_html( $email ); ?></span>
				</a>
				<?php endif; ?>

				<?php if ( $show_vcard ) : ?>
				<a href="<?php echo esc_url( home_url( '/gcm-biz-export/vcard/' ) ); ?>"
				   class="gcm-biz-card__btn gcm-biz-card__btn--vcard"
				   download>
					<span class="dashicons dashicons-id-alt" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Save Contact', 'metamanager' ); ?></span>
				</a>
				<?php endif; ?>

				<?php if ( $show_json ) : ?>
				<a href="<?php echo esc_url( home_url( '/gcm-biz-export/json/' ) ); ?>"
				   class="gcm-biz-card__btn gcm-biz-card__btn--json"
				   download>
					<span class="dashicons dashicons-download" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Download JSON', 'metamanager' ); ?></span>
				</a>
				<?php endif; ?>

				<?php if ( $show_csv ) : ?>
				<a href="<?php echo esc_url( home_url( '/gcm-biz-export/csv/' ) ); ?>"
				   class="gcm-biz-card__btn gcm-biz-card__btn--csv"
				   download>
					<span class="dashicons dashicons-media-spreadsheet" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Download CSV', 'metamanager' ); ?></span>
				</a>
				<?php endif; ?>

			</div>
		</div>
		<script type="application/ld+json"><?php echo $schema; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Schema builder
	// -------------------------------------------------------------------------

	private function build_schema( array $biz, array $style ): array {
		$type   = sanitize_text_field( $biz['type'] ?? 'LocalBusiness' );
		$schema = [
			'@context' => 'https://schema.org',
			'@type'    => $type,
			'name'     => sanitize_text_field( $biz['name'] ),
			'url'      => trailingslashit( home_url() ),
		];

		if ( ! empty( $biz['logo_url'] ) ) {
			$schema['logo'] = [
				'@type' => 'ImageObject',
				'url'   => esc_url_raw( $biz['logo_url'] ),
			];
		}

		if ( ! empty( $style['show_phone'] ) && ! empty( $biz['phone'] ) ) {
			$schema['telephone'] = sanitize_text_field( $biz['phone'] );
		}
		if ( ! empty( $style['show_email'] ) && ! empty( $biz['email'] ) ) {
			$schema['email'] = sanitize_email( $biz['email'] );
		}

		$addr = $biz['address'] ?? [];
		if ( ! empty( $addr['street'] ) ) {
			$schema['address'] = array_filter( [
				'@type'           => 'PostalAddress',
				'streetAddress'   => sanitize_text_field( $addr['street']  ?? '' ),
				'addressLocality' => sanitize_text_field( $addr['city']    ?? '' ),
				'addressRegion'   => sanitize_text_field( $addr['state']   ?? '' ),
				'postalCode'      => sanitize_text_field( $addr['zip']     ?? '' ),
				'addressCountry'  => sanitize_text_field( $addr['country'] ?? 'US' ),
			] );
		}

		$geo_lat = isset( $biz['lat'] ) && is_numeric( $biz['lat'] ) ? (float) $biz['lat'] : null;
		$geo_lng = isset( $biz['lng'] ) && is_numeric( $biz['lng'] ) ? (float) $biz['lng'] : null;
		if ( null !== $geo_lat && null !== $geo_lng ) {
			$schema['geo'] = [
				'@type'     => 'GeoCoordinates',
				'latitude'  => $geo_lat,
				'longitude' => $geo_lng,
			];
		}

		// ContactPoint nodes for rendered actions.
		$contact_points = [];
		if ( ! empty( $style['show_phone'] ) && ! empty( $biz['phone'] ) ) {
			$contact_points[] = [
				'@type'       => 'ContactPoint',
				'telephone'   => sanitize_text_field( $biz['phone'] ),
				'contactType' => 'customer service',
			];
		}
		if ( ! empty( $style['show_email'] ) && ! empty( $biz['email'] ) ) {
			$contact_points[] = [
				'@type'       => 'ContactPoint',
				'email'       => sanitize_email( $biz['email'] ),
				'contactType' => 'customer service',
			];
		}
		if ( $contact_points ) {
			$schema['contactPoint'] = ( 1 === count( $contact_points ) ) ? $contact_points[0] : $contact_points;
		}

		return $schema;
	}

	// -------------------------------------------------------------------------
	// Style settings helpers
	// -------------------------------------------------------------------------

	/** Return merged (defaults + saved) sitewide style settings. */
	public static function get_style_settings(): array {
		$saved = get_option( self::OPT_STYLE, [] );
		return array_merge( self::style_defaults(), is_array( $saved ) ? $saved : [] );
	}

	/** Full set of style defaults. */
	public static function style_defaults(): array {
		return [
			// Visibility toggles
			'show_phone' => true,
			'show_sms'   => true,
			'show_email' => true,
			'show_vcard' => true,
			'show_json'  => false,
			'show_csv'   => false,
			// Card appearance
			'card_bg'           => '#ffffff',
			'card_text'         => '#333333',
			'card_border'       => '#e2e2e2',
			'card_border_width' => '1px',
			'card_radius'       => '8px',
			'card_padding'      => '24px',
			'card_max_width'    => '420px',
			'card_shadow'       => '0 2px 8px rgba(0,0,0,0.08)',
			// Button appearance
			'btn_bg'        => '#0073aa',
			'btn_text'      => '#ffffff',
			'btn_radius'    => '4px',
			'btn_padding'   => '10px 16px',
			'btn_font_size' => '14px',
			// Typography
			'name_font_size' => '20px',
			'body_font_size' => '14px',
		];
	}
}

// =============================================================================
// Widget — thin wrapper that delegates to the module's render_card().
// =============================================================================

class MM_Business_Contact_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'mm_meta_business_contact',
			__( 'GCM Business Contact Card', 'metamanager' ),
			[ 'description' => __( 'Displays the business contact card. Styling is controlled via SEO → Contact Card.', 'metamanager' ) ]
		);
	}

	public function widget( $args, $instance ): void {
		$settings = MM_Site_Settings::get_instance();
		$module   = new MM_Mod_Business_Contact( $settings );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			     . esc_html( apply_filters( 'widget_title', $instance['title'] ) )
			     . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo $module->render_card( [] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function form( $instance ): void {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title (optional):', 'metamanager' ); ?></label>
			<input class="widefat"
			       id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
			       name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
			       type="text"
			       value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p class="description">
			<?php
			printf(
				/* translators: %s: link to Contact Card settings page */
				esc_html__( 'Styling and action visibility are configured on the %s page.', 'metamanager' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=mm-meta-contact' ) ) . '">'
				. esc_html__( 'Contact Card settings', 'metamanager' )
				. '</a>'
			);
			?>
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ): array {
		return [ 'title' => sanitize_text_field( $new_instance['title'] ?? '' ) ];
	}
}
