<?php
/**
 * Admin page — Contact Card Style.
 *
 * Sitewide appearance and visibility settings for the business contact card
 * widget, block, and shortcode.
 *
 * @var MM_Site_Settings $settings
 */
defined( 'ABSPATH' ) || exit;

$s   = MM_Mod_Business_Contact::get_style_settings();
$opt = MM_Mod_Business_Contact::OPT_STYLE;
?>

<div class="mm-meta-panel" id="page-contact">

	<h2><?php esc_html_e( 'Contact Card', 'metamanager' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Controls the appearance and visible actions for every Business Contact Card widget, block, and shortcode on this site.', 'metamanager' ); ?>
		<?php esc_html_e( 'Add the card via the "GCM Business Contact Card" widget, the "Business Contact Card" Gutenberg block, or the [gcm_business_contact] shortcode.', 'metamanager' ); ?>
	</p>

	<?php /* ------------------------------------------------------------------ */ ?>
	<?php /* Section: Actions */ ?>
	<?php /* ------------------------------------------------------------------ */ ?>

	<h3><?php esc_html_e( 'Visible Actions', 'metamanager' ); ?></h3>
	<p class="description"><?php esc_html_e( 'Choose which action buttons appear on the card. Actions that have no corresponding data in your Business Profile are always hidden regardless of these settings.', 'metamanager' ); ?></p>

	<table class="form-table gcm-form-table">
		<tr>
			<th><?php esc_html_e( 'Click to Call', 'metamanager' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[show_phone]" value="1" <?php checked( $s['show_phone'] ); ?>>
					<?php esc_html_e( 'Show phone / click-to-call button', 'metamanager' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'SMS', 'metamanager' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[show_sms]" value="1" <?php checked( $s['show_sms'] ); ?>>
					<?php esc_html_e( 'Show SMS / text button', 'metamanager' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Email', 'metamanager' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[show_email]" value="1" <?php checked( $s['show_email'] ); ?>>
					<?php esc_html_e( 'Show email button', 'metamanager' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Save Contact (vCard)', 'metamanager' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[show_vcard]" value="1" <?php checked( $s['show_vcard'] ); ?>>
					<?php esc_html_e( 'Show vCard (.vcf) download button', 'metamanager' ); ?>
				</label>
				<p class="description">
					<?php
					printf(
						/* translators: %s: download URL */
						esc_html__( 'Direct URL: %s', 'metamanager' ),
						'<code>' . esc_html( home_url( '/gcm-biz-export/vcard/' ) ) . '</code>'
					);
					?>
				</p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Download JSON', 'metamanager' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[show_json]" value="1" <?php checked( $s['show_json'] ); ?>>
					<?php esc_html_e( 'Show JSON contact download button', 'metamanager' ); ?>
				</label>
				<p class="description">
					<?php
					printf(
						/* translators: %s: download URL */
						esc_html__( 'Direct URL: %s', 'metamanager' ),
						'<code>' . esc_html( home_url( '/gcm-biz-export/json/' ) ) . '</code>'
					);
					?>
				</p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Download CSV', 'metamanager' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[show_csv]" value="1" <?php checked( $s['show_csv'] ); ?>>
					<?php esc_html_e( 'Show CSV contact download button', 'metamanager' ); ?>
				</label>
				<p class="description">
					<?php
					printf(
						/* translators: %s: download URL */
						esc_html__( 'Direct URL: %s', 'metamanager' ),
						'<code>' . esc_html( home_url( '/gcm-biz-export/csv/' ) ) . '</code>'
					);
					?>
				</p>
			</td>
		</tr>
	</table>

	<?php /* ------------------------------------------------------------------ */ ?>
	<?php /* Section: Card Appearance */ ?>
	<?php /* ------------------------------------------------------------------ */ ?>

	<h3><?php esc_html_e( 'Card Appearance', 'metamanager' ); ?></h3>

	<table class="form-table gcm-form-table">
		<tr>
			<th><label for="gcm_card_bg"><?php esc_html_e( 'Background Color', 'metamanager' ); ?></label></th>
			<td>
				<div class="gcm-color-field">
					<input type="color" id="gcm_card_bg_picker" value="<?php echo esc_attr( $s['card_bg'] ); ?>"
					       oninput="document.getElementById('gcm_card_bg').value=this.value">
					<input type="text" id="gcm_card_bg" name="<?php echo esc_attr( $opt ); ?>[card_bg]"
					       value="<?php echo esc_attr( $s['card_bg'] ); ?>" class="small-text"
					       oninput="document.getElementById('gcm_card_bg_picker').value=this.value">
				</div>
			</td>
		</tr>
		<tr>
			<th><label for="gcm_card_text"><?php esc_html_e( 'Text Color', 'metamanager' ); ?></label></th>
			<td>
				<div class="gcm-color-field">
					<input type="color" id="gcm_card_text_picker" value="<?php echo esc_attr( $s['card_text'] ); ?>"
					       oninput="document.getElementById('gcm_card_text').value=this.value">
					<input type="text" id="gcm_card_text" name="<?php echo esc_attr( $opt ); ?>[card_text]"
					       value="<?php echo esc_attr( $s['card_text'] ); ?>" class="small-text"
					       oninput="document.getElementById('gcm_card_text_picker').value=this.value">
				</div>
			</td>
		</tr>
		<tr>
			<th><label for="gcm_card_border"><?php esc_html_e( 'Border Color', 'metamanager' ); ?></label></th>
			<td>
				<div class="gcm-color-field">
					<input type="color" id="gcm_card_border_picker" value="<?php echo esc_attr( $s['card_border'] ); ?>"
					       oninput="document.getElementById('gcm_card_border').value=this.value">
					<input type="text" id="gcm_card_border" name="<?php echo esc_attr( $opt ); ?>[card_border]"
					       value="<?php echo esc_attr( $s['card_border'] ); ?>" class="small-text"
					       oninput="document.getElementById('gcm_card_border_picker').value=this.value">
				</div>
			</td>
		</tr>
		<tr>
			<th><label for="gcm_card_border_width"><?php esc_html_e( 'Border Width', 'metamanager' ); ?></label></th>
			<td>
				<input type="text" id="gcm_card_border_width"
				       name="<?php echo esc_attr( $opt ); ?>[card_border_width]"
				       value="<?php echo esc_attr( $s['card_border_width'] ); ?>"
				       class="small-text" placeholder="1px">
				<p class="description"><?php esc_html_e( 'CSS length, e.g. 1px or 2px. Use 0 for no border.', 'metamanager' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="gcm_card_radius"><?php esc_html_e( 'Corner Radius', 'metamanager' ); ?></label></th>
			<td>
				<input type="text" id="gcm_card_radius"
				       name="<?php echo esc_attr( $opt ); ?>[card_radius]"
				       value="<?php echo esc_attr( $s['card_radius'] ); ?>"
				       class="small-text" placeholder="8px">
				<p class="description"><?php esc_html_e( 'CSS length, e.g. 8px. Use 0 for sharp corners.', 'metamanager' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="gcm_card_padding"><?php esc_html_e( 'Padding', 'metamanager' ); ?></label></th>
			<td>
				<input type="text" id="gcm_card_padding"
				       name="<?php echo esc_attr( $opt ); ?>[card_padding]"
				       value="<?php echo esc_attr( $s['card_padding'] ); ?>"
				       class="small-text" placeholder="24px">
				<p class="description"><?php esc_html_e( 'CSS length, e.g. 24px or 16px 24px.', 'metamanager' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="gcm_card_max_width"><?php esc_html_e( 'Max Width', 'metamanager' ); ?></label></th>
			<td>
				<input type="text" id="gcm_card_max_width"
				       name="<?php echo esc_attr( $opt ); ?>[card_max_width]"
				       value="<?php echo esc_attr( $s['card_max_width'] ); ?>"
				       class="small-text" placeholder="420px">
				<p class="description"><?php esc_html_e( 'Maximum card width, e.g. 420px or 100%. On screens narrower than 480 px the card fills full width automatically.', 'metamanager' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="gcm_card_shadow"><?php esc_html_e( 'Box Shadow', 'metamanager' ); ?></label></th>
			<td>
				<input type="text" id="gcm_card_shadow"
				       name="<?php echo esc_attr( $opt ); ?>[card_shadow]"
				       value="<?php echo esc_attr( $s['card_shadow'] ); ?>"
				       class="regular-text" placeholder="0 2px 8px rgba(0,0,0,0.08)">
				<p class="description"><?php esc_html_e( 'CSS box-shadow value. Use "none" to remove shadow.', 'metamanager' ); ?></p>
			</td>
		</tr>
	</table>

	<?php /* ------------------------------------------------------------------ */ ?>
	<?php /* Section: Button Appearance */ ?>
	<?php /* ------------------------------------------------------------------ */ ?>

	<h3><?php esc_html_e( 'Button Appearance', 'metamanager' ); ?></h3>

	<table class="form-table gcm-form-table">
		<tr>
			<th><label for="gcm_btn_bg"><?php esc_html_e( 'Button Background', 'metamanager' ); ?></label></th>
			<td>
				<div class="gcm-color-field">
					<input type="color" id="gcm_btn_bg_picker" value="<?php echo esc_attr( $s['btn_bg'] ); ?>"
					       oninput="document.getElementById('gcm_btn_bg').value=this.value">
					<input type="text" id="gcm_btn_bg" name="<?php echo esc_attr( $opt ); ?>[btn_bg]"
					       value="<?php echo esc_attr( $s['btn_bg'] ); ?>" class="small-text"
					       oninput="document.getElementById('gcm_btn_bg_picker').value=this.value">
				</div>
			</td>
		</tr>
		<tr>
			<th><label for="gcm_btn_text"><?php esc_html_e( 'Button Text Color', 'metamanager' ); ?></label></th>
			<td>
				<div class="gcm-color-field">
					<input type="color" id="gcm_btn_text_picker" value="<?php echo esc_attr( $s['btn_text'] ); ?>"
					       oninput="document.getElementById('gcm_btn_text').value=this.value">
					<input type="text" id="gcm_btn_text" name="<?php echo esc_attr( $opt ); ?>[btn_text]"
					       value="<?php echo esc_attr( $s['btn_text'] ); ?>" class="small-text"
					       oninput="document.getElementById('gcm_btn_text_picker').value=this.value">
				</div>
			</td>
		</tr>
		<tr>
			<th><label for="gcm_btn_radius"><?php esc_html_e( 'Button Corner Radius', 'metamanager' ); ?></label></th>
			<td>
				<input type="text" id="gcm_btn_radius"
				       name="<?php echo esc_attr( $opt ); ?>[btn_radius]"
				       value="<?php echo esc_attr( $s['btn_radius'] ); ?>"
				       class="small-text" placeholder="4px">
			</td>
		</tr>
		<tr>
			<th><label for="gcm_btn_padding"><?php esc_html_e( 'Button Padding', 'metamanager' ); ?></label></th>
			<td>
				<input type="text" id="gcm_btn_padding"
				       name="<?php echo esc_attr( $opt ); ?>[btn_padding]"
				       value="<?php echo esc_attr( $s['btn_padding'] ); ?>"
				       class="small-text" placeholder="10px 16px">
				<p class="description"><?php esc_html_e( 'CSS padding shorthand, e.g. 10px 16px.', 'metamanager' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="gcm_btn_font_size"><?php esc_html_e( 'Button Font Size', 'metamanager' ); ?></label></th>
			<td>
				<input type="text" id="gcm_btn_font_size"
				       name="<?php echo esc_attr( $opt ); ?>[btn_font_size]"
				       value="<?php echo esc_attr( $s['btn_font_size'] ); ?>"
				       class="small-text" placeholder="14px">
			</td>
		</tr>
	</table>

	<?php /* ------------------------------------------------------------------ */ ?>
	<?php /* Section: Typography */ ?>
	<?php /* ------------------------------------------------------------------ */ ?>

	<h3><?php esc_html_e( 'Typography', 'metamanager' ); ?></h3>

	<table class="form-table gcm-form-table">
		<tr>
			<th><label for="gcm_name_font_size"><?php esc_html_e( 'Business Name Size', 'metamanager' ); ?></label></th>
			<td>
				<input type="text" id="gcm_name_font_size"
				       name="<?php echo esc_attr( $opt ); ?>[name_font_size]"
				       value="<?php echo esc_attr( $s['name_font_size'] ); ?>"
				       class="small-text" placeholder="20px">
			</td>
		</tr>
		<tr>
			<th><label for="gcm_body_font_size"><?php esc_html_e( 'Body / Address Size', 'metamanager' ); ?></label></th>
			<td>
				<input type="text" id="gcm_body_font_size"
				       name="<?php echo esc_attr( $opt ); ?>[body_font_size]"
				       value="<?php echo esc_attr( $s['body_font_size'] ); ?>"
				       class="small-text" placeholder="14px">
			</td>
		</tr>
	</table>

</div>

<style>
.gcm-color-field { display: flex; align-items: center; gap: 8px; }
.gcm-color-field input[type="color"] { width: 40px; height: 32px; padding: 2px; cursor: pointer; border: 1px solid #8c8f94; border-radius: 3px; background: none; }
</style>
