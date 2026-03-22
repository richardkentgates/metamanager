<?php
/**
 * Admin tab — Social & OG.
 * @var MM_Site_Settings $settings
 */
defined( 'ABSPATH' ) || exit;
$opt = MM_META_OPT_SETTINGS;
$s   = $settings;
?>
<div class="mm-meta-panel" id="page-social">

	<h2>Open Graph</h2>
	<table class="form-table gcm-form-table">
		<tr>
			<th>Enable Open Graph</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[social][og_enabled]" value="1"
						<?php checked( $s->get('social.og_enabled', true) ); ?>>
					Output Open Graph meta tags in <code>&lt;head&gt;</code>
				</label>
			</td>
		</tr>
		<tr>
			<th><label for="gcm_og_locale">OG Locale</label></th>
			<td>
				<input type="text" id="gcm_og_locale"
					name="<?php echo esc_attr($opt); ?>[social][og_locale]"
					value="<?php echo esc_attr( $s->get('social.og_locale','en_US') ); ?>"
					class="regular-text" placeholder="en_US">
			</td>
		</tr>
		<tr>
			<th>Default OG Image</th>
			<td>
				<div class="gcm-media-picker" data-target="gcm_og_img_id" data-preview="gcm_og_img_preview">
					<input type="hidden" id="gcm_og_img_id"
						name="<?php echo esc_attr($opt); ?>[social][og_default_image_id]"
						value="<?php echo (int) $s->get('social.og_default_image_id', 0); ?>">
					<input type="text" id="gcm_og_img_url"
						name="<?php echo esc_attr($opt); ?>[social][og_default_image]"
						value="<?php echo esc_attr( $s->get('social.og_default_image','') ); ?>"
						class="large-text" placeholder="Fallback image URL when no featured image exists">
					<button type="button" class="button gcm-btn-pick-media">Choose Image</button>
					<img id="gcm_og_img_preview"
						src="<?php echo esc_url( $s->get('social.og_default_image','') ); ?>"
						class="gcm-image-preview"
						style="max-height:80px;display:<?php echo $s->get('social.og_default_image','') ? 'block' : 'none'; ?>;margin-top:8px">
				</div>
				<p class="description">Recommended: 1200×630 px, under 8 MB.</p>
			</td>
		</tr>
		<tr>
			<th><label for="gcm_fb_app_id">Facebook App ID</label></th>
			<td>
				<input type="text" id="gcm_fb_app_id"
					name="<?php echo esc_attr($opt); ?>[social][fb_app_id]"
					value="<?php echo esc_attr( $s->get('social.fb_app_id','') ); ?>"
					class="regular-text">
			</td>
		</tr>
	</table>

	<h2>Twitter / X Cards</h2>
	<table class="form-table gcm-form-table">
		<tr>
			<th>Enable Twitter Cards</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[social][twitter_enabled]" value="1"
						<?php checked( $s->get('social.twitter_enabled', true) ); ?>>
					Output <code>twitter:*</code> meta tags
				</label>
			</td>
		</tr>
		<tr>
			<th><label for="gcm_tw_site">Site @handle</label></th>
			<td>
				<input type="text" id="gcm_tw_site"
					name="<?php echo esc_attr($opt); ?>[social][twitter_site]"
					value="<?php echo esc_attr( $s->get('social.twitter_site','') ); ?>"
					class="regular-text" placeholder="@yourbrand">
			</td>
		</tr>
		<tr>
			<th>Default Card Type</th>
			<td>
				<select name="<?php echo esc_attr($opt); ?>[social][twitter_card_type]">
					<?php foreach (['summary_large_image'=>'Summary Large Image (recommended)','summary'=>'Summary'] as $v=>$l) : ?>
						<option value="<?php echo esc_attr($v); ?>" <?php selected($s->get('social.twitter_card_type','summary_large_image'), $v); ?>><?php echo esc_html($l); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
	</table>

	<h2>Platform Verification</h2>
	<table class="form-table gcm-form-table">
		<tr>
			<th><label for="gcm_pinterest_verify">Pinterest Verify</label></th>
			<td>
				<input type="text" id="gcm_pinterest_verify"
					name="<?php echo esc_attr($opt); ?>[social][pinterest_verify]"
					value="<?php echo esc_attr( $s->get('social.pinterest_verify','') ); ?>"
					class="regular-text" placeholder="Verification code only (not full meta tag)">
			</td>
		</tr>
	</table>

</div>
