<?php
/**
 * Metamanager Settings Page
 *
 * Registers and renders the plugin settings under Media → Settings.
 *
 * Options:
 *   mm_compress_level           — PNG/WebP optimisation level (1–7, default 2).
 *                                 JPEG compression is always maximum lossless quality.
 *   mm_notify_enabled           — Whether to send an email on job failure.
 *   mm_notify_email             — Recipient address; falls back to admin email if empty.
 *   mm_delete_data_on_uninstall — When true, all plugin data is wiped on plugin deletion.
 *   mm_api_disabled             — When true, all Metamanager REST API routes return 403.
 *   mm_api_allowed_ips          — Newline/comma-separated IP allowlist for the REST API.
 *                                 Empty = no restriction.
 *   mm_upload_notify_enabled    — Whether to send receipt emails when images are uploaded.
 *   mm_upload_notify_extra_email — Additional CC address(es) for upload receipts, comma-separated.
 *
 * Sitemap options (managed by MM_Sitemap::register_settings()):
 *   mm_sitemap_media            — Serve /sitemap-media.xml (bool, default true).
 *   mm_sitemap_images           — Include <image:image> nodes in media sitemap (bool, default true).
 *   mm_sitemap_video            — Serve /sitemap-video.xml (bool, default true).
 *   mm_sitemap_video_youtube    — Extract YouTube embeds for video sitemap (bool, default true).
 *   mm_sitemap_video_vimeo      — Extract Vimeo embeds for video sitemap (bool, default true).
 *   mm_sitemap_video_selfhosted — Extract self-hosted <video> tags for video sitemap (bool, default true).
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MM_Settings
 */
class MM_Settings {

	const OPTION_COMPRESS_LEVEL         = 'mm_compress_level';
	const OPTION_NOTIFY_ENABLED         = 'mm_notify_enabled';
	const OPTION_NOTIFY_EMAIL           = 'mm_notify_email';
	const OPTION_DELETE_DATA            = 'mm_delete_data_on_uninstall';
	const OPTION_API_DISABLED           = 'mm_api_disabled';
	const OPTION_API_ALLOWED_IPS        = 'mm_api_allowed_ips';
	const OPTION_UPLOAD_NOTIFY_ENABLED  = 'mm_upload_notify_enabled';
	const OPTION_UPLOAD_NOTIFY_EXTRA    = 'mm_upload_notify_extra_email';

	// -----------------------------------------------------------------------
	// Boot
	// -----------------------------------------------------------------------

	public static function init(): void {
		add_action( 'admin_menu',  [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_init',  [ __CLASS__, 'register_settings' ] );
	}

	// -----------------------------------------------------------------------
	// Menu
	// -----------------------------------------------------------------------

	public static function add_menu(): void {
		add_submenu_page(
			'upload.php',
			esc_html__( 'Metamanager Settings', 'metamanager' ),
			esc_html__( 'MM Settings', 'metamanager' ),
			'manage_options',
			'metamanager-settings',
			[ __CLASS__, 'render_page' ]
		);
	}

	// -----------------------------------------------------------------------
	// Register settings
	// -----------------------------------------------------------------------

	public static function register_settings(): void {
		register_setting(
			'mm_settings_group',
			self::OPTION_COMPRESS_LEVEL,
			[
				'type'              => 'integer',
				'sanitize_callback' => fn( $v ) => max( 1, min( 7, (int) $v ) ),
				'default'           => 2,
			]
		);

		register_setting(
			'mm_settings_group',
			self::OPTION_NOTIFY_ENABLED,
			[
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			]
		);

		register_setting(
			'mm_settings_group',
			self::OPTION_NOTIFY_EMAIL,
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
				'default'           => '',
			]
		);

		register_setting(
			'mm_settings_group',
			self::OPTION_DELETE_DATA,
			[
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			]
		);

		// ---- Sections & fields ----

		add_settings_section(
			'mm_section_compression',
			esc_html__( 'Compression', 'metamanager' ),
			fn() => esc_html_e( 'Controls how aggressively PNG and WebP files are optimised. JPEG lossless compression is always at maximum quality and is unaffected by this setting.', 'metamanager' ),
			'metamanager-settings'
		);

		add_settings_field(
			'mm_compress_level',
			esc_html__( 'Optimisation level', 'metamanager' ),
			[ __CLASS__, 'field_compress_level' ],
			'metamanager-settings',
			'mm_section_compression'
		);

		add_settings_section(
			'mm_section_notifications',
			esc_html__( 'Failure Notifications', 'metamanager' ),
			fn() => esc_html_e( 'Send an email when a compression or metadata job fails. Failures are also always logged in the Job Dashboard.', 'metamanager' ),
			'metamanager-settings'
		);

		add_settings_field(
			'mm_notify_enabled',
			esc_html__( 'Enable notifications', 'metamanager' ),
			[ __CLASS__, 'field_notify_enabled' ],
			'metamanager-settings',
			'mm_section_notifications'
		);

		add_settings_field(
			'mm_notify_email',
			esc_html__( 'Notification email', 'metamanager' ),
			[ __CLASS__, 'field_notify_email' ],
			'metamanager-settings',
			'mm_section_notifications'
		);

		// --- REST API ---

		register_setting(
			'mm_settings_group',
			self::OPTION_API_DISABLED,
			[
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			]
		);

		register_setting(
			'mm_settings_group',
			self::OPTION_API_ALLOWED_IPS,
			[
				'type'              => 'string',
				'sanitize_callback' => fn( $v ) => sanitize_textarea_field( (string) $v ),
				'default'           => '',
			]
		);

		// --- Upload receipts ---

		register_setting(
			'mm_settings_group',
			self::OPTION_UPLOAD_NOTIFY_ENABLED,
			[
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			]
		);

		register_setting(
			'mm_settings_group',
			self::OPTION_UPLOAD_NOTIFY_EXTRA,
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			]
		);

		// --- Uninstall ---

		add_settings_section(
			'mm_section_api',
			esc_html__( 'REST API', 'metamanager' ),
			fn() => esc_html_e( 'Control external access to the Metamanager REST API endpoints (/wp-json/metamanager/v1/). The API is used by the Media Library column polling and the job dashboard. Disabling or restricting it will break those features.', 'metamanager' ),
			'metamanager-settings'
		);

		add_settings_field(
			'mm_api_disabled',
			esc_html__( 'Disable REST API', 'metamanager' ),
			[ __CLASS__, 'field_api_disabled' ],
			'metamanager-settings',
			'mm_section_api'
		);

		add_settings_field(
			'mm_api_allowed_ips',
			esc_html__( 'IP allowlist', 'metamanager' ),
			[ __CLASS__, 'field_api_allowed_ips' ],
			'metamanager-settings',
			'mm_section_api'
		);

		add_settings_section(
			'mm_section_upload_notify',
			esc_html__( 'Upload Receipts', 'metamanager' ),
			fn() => esc_html_e( 'Send an email receipt whenever images are uploaded to the Media Library. Multiple files uploaded in quick succession are batched into a single email.', 'metamanager' ),
			'metamanager-settings'
		);

		add_settings_field(
			'mm_upload_notify_enabled',
			esc_html__( 'Enable upload receipts', 'metamanager' ),
			[ __CLASS__, 'field_upload_notify_enabled' ],
			'metamanager-settings',
			'mm_section_upload_notify'
		);

		add_settings_field(
			'mm_upload_notify_extra_email',
			esc_html__( 'Additional recipients', 'metamanager' ),
			[ __CLASS__, 'field_upload_notify_extra' ],
			'metamanager-settings',
			'mm_section_upload_notify'
		);

		add_settings_section(
			'mm_section_uninstall',
			esc_html__( 'Data & Uninstall', 'metamanager' ),
			fn() => esc_html_e( 'Controls what happens to plugin data when Metamanager is deleted from WordPress.', 'metamanager' ),
			'metamanager-settings'
		);

		add_settings_field(
			'mm_delete_data_on_uninstall',
			esc_html__( 'Remove all data on uninstall', 'metamanager' ),
			[ __CLASS__, 'field_delete_data' ],
			'metamanager-settings',
			'mm_section_uninstall'
		);
	}

	// -----------------------------------------------------------------------
	// Field renderers
	// -----------------------------------------------------------------------

	public static function field_compress_level(): void {
		$value = self::get_compress_level();
		echo '<select id="mm_compress_level" name="' . esc_attr( self::OPTION_COMPRESS_LEVEL ) . '">';
		for ( $i = 1; $i <= 7; $i++ ) {
			$label = match ( $i ) {
				1       => esc_html__( '1 — Minimal (fastest)', 'metamanager' ),
				2       => esc_html__( '2 — Default (recommended)', 'metamanager' ),
				3, 4, 5 => esc_html( (string) $i ),
				6       => esc_html__( '6 — High', 'metamanager' ),
				7       => esc_html__( '7 — Maximum (slowest)', 'metamanager' ),
				default => esc_html( (string) $i ),
			};
			printf(
				'<option value="%d"%s>%s</option>',
				$i,
				selected( $value, $i, false ),
				$label // Already escaped above.
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Applied to optipng (PNG) and cwebp (WebP). Higher levels produce smaller files but take longer.', 'metamanager' ) . '</p>';
	}

	public static function field_notify_enabled(): void {
		$checked = (bool) get_option( self::OPTION_NOTIFY_ENABLED, false );
		printf(
			'<input type="checkbox" id="mm_notify_enabled" name="%s" value="1"%s>',
			esc_attr( self::OPTION_NOTIFY_ENABLED ),
			checked( $checked, true, false )
		);
		echo ' <label for="mm_notify_enabled">' . esc_html__( 'Send an email when a job fails', 'metamanager' ) . '</label>';
	}

	public static function field_notify_email(): void {
		$value = self::get_notify_email();
		printf(
			'<input type="email" id="mm_notify_email" name="%s" value="%s" class="regular-text" placeholder="%s">',
			esc_attr( self::OPTION_NOTIFY_EMAIL ),
			esc_attr( $value ),
			esc_attr( get_option( 'admin_email', '' ) )
		);
		echo '<p class="description">' . esc_html__( 'Leave blank to use the WordPress admin email address.', 'metamanager' ) . '</p>';
	}

	public static function field_api_disabled(): void {
		$checked = self::get_api_disabled();
		printf(
			'<input type="checkbox" id="mm_api_disabled" name="%s" value="1"%s>',
			esc_attr( self::OPTION_API_DISABLED ),
			checked( $checked, true, false )
		);
		echo ' <label for="mm_api_disabled">' . esc_html__( 'Disable all Metamanager REST API routes', 'metamanager' ) . '</label>';
		echo '<p class="description" style="color:#d63638;"><strong>' . esc_html__( 'Warning:', 'metamanager' ) . '</strong> ' . esc_html__( 'Disabling the API breaks the live compression status column and the job dashboard. Only enable this if you are intentionally blocking external API access.', 'metamanager' ) . '</p>';
	}

	public static function field_api_allowed_ips(): void {
		$value = (string) get_option( self::OPTION_API_ALLOWED_IPS, '' );
		printf(
			'<textarea id="mm_api_allowed_ips" name="%s" rows="4" class="large-text">%s</textarea>',
			esc_attr( self::OPTION_API_ALLOWED_IPS ),
			esc_textarea( $value )
		);
		echo '<p class="description">' . esc_html__( 'Enter one IP address per line, or separate with commas. Only requests from these IPs will be allowed to access Metamanager REST endpoints. Leave blank to allow requests from any IP. IPv4 and IPv6 are both supported.', 'metamanager' ) . '</p>';
		$ip = self::get_current_ip();
		if ( $ip ) {
			echo '<p class="description"><em>' . sprintf(
				/* translators: %s: detected IP address */
				esc_html__( 'Your current IP address appears to be: %s', 'metamanager' ),
				'<code>' . esc_html( $ip ) . '</code>'
			) . '</em></p>';
		}
	}

	public static function field_upload_notify_enabled(): void {
		$checked = self::get_upload_notify_enabled();
		printf(
			'<input type="checkbox" id="mm_upload_notify_enabled" name="%s" value="1"%s>',
			esc_attr( self::OPTION_UPLOAD_NOTIFY_ENABLED ),
			checked( $checked, true, false )
		);
		echo ' <label for="mm_upload_notify_enabled">' . esc_html__( 'Send an email receipt when images are uploaded', 'metamanager' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Emails are sent to the site admin and to the uploading user. Multiple files uploaded within 60 seconds are grouped into a single email.', 'metamanager' ) . '</p>';
	}

	public static function field_upload_notify_extra(): void {
		$value = (string) get_option( self::OPTION_UPLOAD_NOTIFY_EXTRA, '' );
		printf(
			'<input type="text" id="mm_upload_notify_extra_email" name="%s" value="%s" class="regular-text" placeholder="editor@example.com, manager@example.com">',
			esc_attr( self::OPTION_UPLOAD_NOTIFY_EXTRA ),
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'Optional. Comma-separated list of addresses that will receive a copy of every upload receipt in addition to the admin and uploader.', 'metamanager' ) . '</p>';
	}

	public static function field_delete_data(): void {
		$checked = self::get_delete_data();
		printf(
			'<input type="checkbox" id="mm_delete_data_on_uninstall" name="%s" value="1"%s>',
			esc_attr( self::OPTION_DELETE_DATA ),
			checked( $checked, true, false )
		);
		echo ' <label for="mm_delete_data_on_uninstall">' . esc_html__( 'Delete all plugin data when the plugin is deleted', 'metamanager' ) . '</label>';
		echo '<p class="description" style="color:#d63638;"><strong>' . esc_html__( 'Warning:', 'metamanager' ) . '</strong> ' . esc_html__( 'When enabled and the plugin is deleted, all compression logs, attachment metadata, settings, and job queue directories will be permanently removed. This action cannot be undone.', 'metamanager' ) . '</p>';
	}

	// -----------------------------------------------------------------------
	// Page renderer
	// -----------------------------------------------------------------------

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage settings.', 'metamanager' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Metamanager Settings', 'metamanager' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'mm_settings_group' );
				do_settings_sections( 'metamanager-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Getters (used throughout the plugin)
	// -----------------------------------------------------------------------

	/**
	 * Get the configured optimisation level (1–7).
	 */
	public static function get_compress_level(): int {
		return max( 1, min( 7, (int) get_option( self::OPTION_COMPRESS_LEVEL, 2 ) ) );
	}

	/**
	 * Whether failure notifications are enabled.
	 */
	public static function get_notify_enabled(): bool {
		return (bool) get_option( self::OPTION_NOTIFY_ENABLED, false );
	}

	/**
	 * Notification email address. Falls back to admin email if unset.
	 */
	public static function get_notify_email(): string {
		$email = sanitize_email( (string) get_option( self::OPTION_NOTIFY_EMAIL, '' ) );
		return $email ?: (string) get_option( 'admin_email', '' );
	}

	/**
	 * Whether all plugin data should be removed on uninstall.
	 */
	public static function get_delete_data(): bool {
		return (bool) get_option( self::OPTION_DELETE_DATA, false );
	}

	/**
	 * Whether the Metamanager REST API is completely disabled.
	 */
	public static function get_api_disabled(): bool {
		return (bool) get_option( self::OPTION_API_DISABLED, false );
	}

	/**
	 * Returns the IP allowlist as an array of trimmed strings.
	 * An empty array means no restriction.
	 *
	 * @return string[]
	 */
	public static function get_api_allowed_ips(): array {
		$raw = (string) get_option( self::OPTION_API_ALLOWED_IPS, '' );
		if ( '' === trim( $raw ) ) {
			return [];
		}
		// Split on newlines or commas, trim each entry.
		$items = preg_split( '/[\r\n,]+/', $raw );
		$items = array_map( 'trim', $items ?: [] );
		$items = array_filter( $items );
		return array_values( $items );
	}

	/**
	 * Whether upload receipt emails are enabled.
	 */
	public static function get_upload_notify_enabled(): bool {
		return (bool) get_option( self::OPTION_UPLOAD_NOTIFY_ENABLED, false );
	}

	/**
	 * Extra CC addresses for upload receipts as an array of trimmed email strings.
	 *
	 * @return string[]
	 */
	public static function get_upload_notify_extra_emails(): array {
		$raw = (string) get_option( self::OPTION_UPLOAD_NOTIFY_EXTRA, '' );
		if ( '' === trim( $raw ) ) {
			return [];
		}
		$items = explode( ',', $raw );
		$items = array_map( 'trim', $items );
		$items = array_filter( $items, fn( $e ) => is_email( $e ) );
		return array_values( $items );
	}

	/**
	 * Best-effort detection of the current request's IP address.
	 * Uses REMOTE_ADDR only — not X-Forwarded-For, which is spoofable.
	 *
	 * @return string Empty string if not determinable.
	 */
	public static function get_current_ip(): string {
		return sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
	}
}
