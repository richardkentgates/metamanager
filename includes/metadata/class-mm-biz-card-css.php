<?php
/**
 * MM_Biz_Card_CSS — generates scoped CSS for the business contact card.
 *
 * All values are sanitized before being written into the CSS string to prevent
 * injection. Only characters valid in CSS values are permitted per type.
 */

defined( 'ABSPATH' ) || exit;

class MM_Biz_Card_CSS {

	/**
	 * Generate a complete CSS string from the sitewide style settings.
	 *
	 * @param array $s Style settings (from MM_Mod_Business_Contact::style_defaults()).
	 * @return string Safe CSS string ready for inline <style> output.
	 */
	public static function generate( array $s ): string {
		if ( empty( $s ) ) {
			return '';
		}

		$card_bg           = self::color( $s['card_bg']           ?? '#ffffff' );
		$card_text         = self::color( $s['card_text']         ?? '#333333' );
		$card_border       = self::color( $s['card_border']       ?? '#e2e2e2' );
		$card_border_width = self::length( $s['card_border_width'] ?? '1px' );
		$card_radius       = self::shorthand( $s['card_radius']    ?? '8px' );
		$card_padding      = self::shorthand( $s['card_padding']   ?? '24px' );
		$card_max_width    = self::length( $s['card_max_width']    ?? '420px' );
		$card_shadow       = self::shadow( $s['card_shadow']       ?? '0 2px 8px rgba(0,0,0,0.08)' );
		$btn_bg            = self::color( $s['btn_bg']             ?? '#0073aa' );
		$btn_text          = self::color( $s['btn_text']           ?? '#ffffff' );
		$btn_radius        = self::shorthand( $s['btn_radius']     ?? '4px' );
		$btn_padding       = self::shorthand( $s['btn_padding']    ?? '10px 16px' );
		$btn_font_size     = self::length( $s['btn_font_size']     ?? '14px' );
		$name_font_size    = self::length( $s['name_font_size']    ?? '20px' );
		$body_font_size    = self::length( $s['body_font_size']    ?? '14px' );

		$css  = ".gcm-biz-card{box-sizing:border-box;display:block;";
		$css .= "max-width:{$card_max_width};padding:{$card_padding};";
		$css .= "background:{$card_bg};color:{$card_text};";
		$css .= "border:{$card_border_width} solid {$card_border};";
		$css .= "border-radius:{$card_radius};box-shadow:{$card_shadow};";
		$css .= "font-size:{$body_font_size};}\n";

		$css .= ".gcm-biz-card__logo{margin-bottom:12px;}\n";
		$css .= ".gcm-biz-card__logo img{max-height:60px;width:auto;display:block;}\n";

		$css .= ".gcm-biz-card__name{font-size:{$name_font_size};font-weight:700;margin:0 0 8px;}\n";

		$css .= ".gcm-biz-card__address{margin:0 0 16px;font-size:{$body_font_size};line-height:1.55;}\n";

		$css .= ".gcm-biz-card__actions{display:flex;flex-direction:column;gap:8px;margin-top:16px;}\n";

		$css .= ".gcm-biz-card__btn{display:inline-flex;align-items:center;gap:8px;text-decoration:none;";
		$css .= "background:{$btn_bg};color:{$btn_text};padding:{$btn_padding};";
		$css .= "border-radius:{$btn_radius};font-size:{$btn_font_size};font-weight:500;";
		$css .= "cursor:pointer;border:none;line-height:1.4;}\n";

		$css .= ".gcm-biz-card__btn:hover,.gcm-biz-card__btn:focus{opacity:.88;text-decoration:none;color:{$btn_text};}\n";
		$css .= ".gcm-biz-card__btn:focus{outline:2px solid {$btn_bg};outline-offset:2px;}\n";

		$css .= ".gcm-biz-card__btn .dashicons{font-size:{$btn_font_size};width:auto;height:auto;flex-shrink:0;vertical-align:middle;}\n";

		$css .= "@media(max-width:480px){.gcm-biz-card{max-width:100%!important;}}\n";

		return $css;
	}

	// -------------------------------------------------------------------------
	// Sanitizers — only allow characters valid for each CSS value type.
	// -------------------------------------------------------------------------

	/** Hex, rgb/rgba/hsl/hsla, or named color. */
	private static function color( string $v ): string {
		$v = trim( $v );
		// Allow: a-z A-Z 0-9 # % ( ) , . space
		$sanitized = preg_replace( '/[^a-zA-Z0-9#%(),.\s]/', '', $v );
		return $sanitized ?: '#000000';
	}

	/** Single CSS length: digits, dot, units (px em rem % vh vw). */
	private static function length( string $v ): string {
		$v = trim( $v );
		$sanitized = preg_replace( '/[^a-zA-Z0-9.%]/', '', $v );
		return $sanitized ?: '0';
	}

	/** Shorthand length (up to 4 space-separated lengths). */
	private static function shorthand( string $v ): string {
		$v = trim( $v );
		$sanitized = preg_replace( '/[^a-zA-Z0-9.% ]/', '', $v );
		return $sanitized ?: '0';
	}

	/** Box-shadow value: lengths, colors, keywords. */
	private static function shadow( string $v ): string {
		$v = trim( $v );
		$sanitized = preg_replace( '/[^a-zA-Z0-9#%(),.\s\-]/', '', $v );
		return $sanitized ?: 'none';
	}
}
