<?php
/**
 * Integration tests for MM_Settings.
 *
 * Covers: option defaults, option getters, the sanitize/validate callbacks
 * registered with register_settings(), IP allowlist parsing, and the
 * REMOTE_ADDR helper.
 *
 * WordPress options are stored inside the WP test suite's transaction wrapper
 * and rolled back after each test, so tests are fully isolated.
 *
 * @package Metamanager\Tests\Integration
 */

class Test_MM_Settings extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// Trigger settings registration so sanitize_callbacks are available.
		MM_Settings::register_settings();
	}

	// -----------------------------------------------------------------------
	// get_compress_level()
	// -----------------------------------------------------------------------

	public function test_compress_level_default_is_2(): void {
		$this->assertSame( 2, MM_Settings::get_compress_level() );
	}

	public function test_compress_level_returns_stored_value(): void {
		update_option( MM_Settings::OPTION_COMPRESS_LEVEL, 5 );
		$this->assertSame( 5, MM_Settings::get_compress_level() );
	}

	public function test_compress_level_sanitize_clamps_below_minimum(): void {
		// The sanitize callback is (int) clamped to 1–7.
		$sanitized = apply_filters( 'sanitize_option_mm_compress_level', 0 );
		$this->assertSame( 1, (int) $sanitized );
	}

	public function test_compress_level_sanitize_clamps_above_maximum(): void {
		$sanitized = apply_filters( 'sanitize_option_mm_compress_level', 99 );
		$this->assertSame( 7, (int) $sanitized );
	}

	public function test_compress_level_sanitize_accepts_valid_value(): void {
		$sanitized = apply_filters( 'sanitize_option_mm_compress_level', 4 );
		$this->assertSame( 4, (int) $sanitized );
	}

	// -----------------------------------------------------------------------
	// get_notify_email()
	// -----------------------------------------------------------------------

	public function test_notify_email_falls_back_to_admin_email(): void {
		delete_option( MM_Settings::OPTION_NOTIFY_EMAIL );
		$this->assertSame( get_option( 'admin_email' ), MM_Settings::get_notify_email() );
	}

	public function test_notify_email_returns_stored_email(): void {
		update_option( MM_Settings::OPTION_NOTIFY_EMAIL, 'test@example.com' );
		$this->assertSame( 'test@example.com', MM_Settings::get_notify_email() );
	}

	public function test_notify_email_sanitize_rejects_non_email(): void {
		// sanitize_email should strip invalid values.
		$sanitized = apply_filters( 'sanitize_option_mm_notify_email', 'not-an-email' );
		$this->assertEmpty( $sanitized );
	}

	// -----------------------------------------------------------------------
	// get_api_allowed_ips() — allowlist parsing
	// -----------------------------------------------------------------------

	public function test_allowed_ips_empty_returns_empty_array(): void {
		update_option( MM_Settings::OPTION_API_ALLOWED_IPS, '' );
		$this->assertSame( [], MM_Settings::get_api_allowed_ips() );
	}

	public function test_allowed_ips_single_ip_returns_one_entry(): void {
		update_option( MM_Settings::OPTION_API_ALLOWED_IPS, '10.0.0.1' );
		$ips = MM_Settings::get_api_allowed_ips();
		$this->assertContains( '10.0.0.1', $ips );
		$this->assertNotContains( '10.0.0.2', $ips );
	}

	public function test_allowed_ips_comma_separated_list(): void {
		update_option( MM_Settings::OPTION_API_ALLOWED_IPS, '10.0.0.1, 192.168.1.5, ::1' );
		$ips = MM_Settings::get_api_allowed_ips();
		$this->assertContains( '192.168.1.5', $ips );
		$this->assertContains( '::1', $ips );
		$this->assertNotContains( '8.8.8.8', $ips );
	}

	public function test_allowed_ips_newline_separated_list(): void {
		update_option( MM_Settings::OPTION_API_ALLOWED_IPS, "10.0.0.1\n10.0.0.2" );
		$ips = MM_Settings::get_api_allowed_ips();
		$this->assertContains( '10.0.0.1', $ips );
		$this->assertContains( '10.0.0.2', $ips );
		$this->assertNotContains( '10.0.0.3', $ips );
	}

	// -----------------------------------------------------------------------
	// REST API disabled flag
	// -----------------------------------------------------------------------

	public function test_api_disabled_default_is_false(): void {
		delete_option( MM_Settings::OPTION_API_DISABLED );
		$this->assertFalse( (bool) get_option( MM_Settings::OPTION_API_DISABLED, false ) );
	}

	public function test_api_disabled_when_set(): void {
		update_option( MM_Settings::OPTION_API_DISABLED, true );
		$this->assertTrue( (bool) get_option( MM_Settings::OPTION_API_DISABLED ) );
	}

	// -----------------------------------------------------------------------
	// get_current_ip()
	// -----------------------------------------------------------------------

	public function test_get_current_ip_returns_sanitized_string(): void {
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$ip = MM_Settings::get_current_ip();
		$this->assertSame( '127.0.0.1', $ip );
	}

	public function test_get_current_ip_strips_malicious_input(): void {
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1<script>alert(1)</script>';
		$ip = MM_Settings::get_current_ip();
		// sanitize_text_field strips tags; result should not contain '<'.
		$this->assertStringNotContainsString( '<', $ip );
	}

	public function test_get_current_ip_returns_empty_string_when_unset(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		$ip = MM_Settings::get_current_ip();
		$this->assertSame( '', $ip );
	}
}
