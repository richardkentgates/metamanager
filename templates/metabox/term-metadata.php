<?php
/**
 * Term SEO fields — edit form (table rows).
 *
 * Variables:
 *   @var WP_Term          $term
 *   @var string           $taxonomy
 *   @var array            $meta      Current _mm_meta values
 *   @var MM_Site_Settings $settings
 */
defined( 'ABSPATH' ) || exit;
$title          = $meta['title']           ?? '';
$description    = $meta['description']     ?? '';
$noindex        = $meta['noindex']         ?? null;
$nofollow       = $meta['nofollow']        ?? null;
$og_title       = $meta['og_title']        ?? '';
$og_desc        = $meta['og_description'] ?? '';
$og_image_id    = (int) ( $meta['og_image_id']  ?? 0 );
$og_image_url   = $meta['og_image_url']   ?? '';
$breadcrumb_label = $meta['breadcrumb_label'] ?? '';
$exclude_sitemap  = ! empty( $meta['exclude_sitemap'] );
?>
<tr class="form-field term-mm-meta-wrap">
	<th scope="row" colspan="2">
		<h3 style="margin:12px 0 4px;padding:0">Metamanager</h3>
	</th>
</tr>

<tr class="form-field">
	<th scope="row"><label for="mm_meta_title">SEO Title</label></th>
	<td>
		<input type="text" id="mm_meta_title" name="mm_meta_title"
			   value="<?php echo esc_attr($title); ?>" class="large-text"
			   placeholder="Leave blank to auto-generate">
		<p class="description">Template vars: <code>%%term_title%% %%sep%% %%sitetitle%%</code></p>
	</td>
</tr>

<tr class="form-field">
	<th scope="row"><label for="mm_meta_description">Meta Description</label></th>
	<td>
		<textarea id="mm_meta_description" name="mm_meta_description"
				  rows="3" class="large-text gcm-desc-textarea"
				  placeholder="Leave blank to use term description"><?php echo esc_textarea($description); ?></textarea>
		<div class="gcm-char-bar">
			<span class="gcm-char-count" id="gcm-term-desc-count">0</span>
			<span>/160</span>
		</div>
	</td>
</tr>

<tr class="form-field">
	<th scope="row">Robots</th>
	<td>
		<?php foreach ( [ 'noindex' => 'Noindex', 'nofollow' => 'Nofollow' ] as $field => $label ) :
			$current = $$field;
		?>
		<fieldset style="margin-bottom:6px">
			<legend style="font-weight:600"><?php echo esc_html($label); ?></legend>
			<label><input type="radio" name="mm_meta_<?php echo esc_attr($field); ?>" value="" <?php checked($current,null); ?>> Default</label>
			<label style="margin-left:10px"><input type="radio" name="mm_meta_<?php echo esc_attr($field); ?>" value="1" <?php checked($current,true); ?>> On</label>
			<label style="margin-left:10px"><input type="radio" name="mm_meta_<?php echo esc_attr($field); ?>" value="0" <?php checked($current,false); ?>> Off</label>
		</fieldset>
		<?php endforeach; ?>
	</td>
</tr>

<tr class="form-field">
	<th scope="row"><label for="mm_meta_og_title">OG Title</label></th>
	<td>
		<input type="text" id="mm_meta_og_title" name="mm_meta_og_title"
			   value="<?php echo esc_attr($og_title); ?>" class="large-text">
	</td>
</tr>

<tr class="form-field">
	<th scope="row"><label for="mm_meta_og_description">OG Description</label></th>
	<td>
		<textarea id="mm_meta_og_description" name="mm_meta_og_description"
				  rows="2" class="large-text"><?php echo esc_textarea($og_desc); ?></textarea>
	</td>
</tr>

<tr class="form-field">
	<th scope="row">OG Image</th>
	<td>
		<div class="gcm-media-picker" data-target="mm_meta_og_image_id" data-preview="gcm_term_og_img_preview">
			<input type="hidden" id="mm_meta_og_image_id" name="mm_meta_og_image_id" value="<?php echo (int)$og_image_id; ?>">
			<input type="url" name="mm_meta_og_image_url" value="<?php echo esc_attr($og_image_url); ?>"
				   class="large-text" placeholder="Image URL">
			<button type="button" class="button gcm-btn-pick-media">Choose Image</button>
			<img id="gcm_term_og_img_preview" src="<?php echo esc_url($og_image_url); ?>"
				 class="gcm-image-preview"
				 style="max-height:80px;display:<?php echo $og_image_url?'block':'none'; ?>;margin-top:6px">
		</div>
	</td>
</tr>

<tr class="form-field">
	<th scope="row"><label for="mm_meta_breadcrumb_label">Breadcrumb Label</label></th>
	<td>
		<input type="text" id="mm_meta_breadcrumb_label" name="mm_meta_breadcrumb_label"
			   value="<?php echo esc_attr($breadcrumb_label); ?>" class="large-text"
			   placeholder="Leave blank to use term name">
	</td>
</tr>

<tr class="form-field">
	<th scope="row">Sitemaps</th>
	<td>
		<label>
			<input type="checkbox" name="mm_meta_exclude_sitemap" value="1" <?php checked($exclude_sitemap); ?>>
			Exclude this term from XML and HTML sitemaps
		</label>
	</td>
</tr>
