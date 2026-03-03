<?php
/**
 * Metamanager Settings Page
 *
 * Registers and renders the plugin settings under Media → Settings.
 *
 * Options:
 *   mm_compress_level  — PNG/WebP optimisation level (1–7, default 2).
 *                        JPEG compression is always maximum lossless quality.
 *   mm_notify_enabled  — Whether to send an email on job failure.
 *   mm_notify_email    — Recipient address; falls back to admin email if empty.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MM_Settings
 */
class MM_Settings {

	const OPTION_COMPRESS_LEVEL = 'mm_compress_level';
	const OPTION_NOTIFY_ENABLED = 'mm_notify_enabled';
	const OPTION_NOTIFY_EMAIL   = 'mm_notify_email';

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
}
