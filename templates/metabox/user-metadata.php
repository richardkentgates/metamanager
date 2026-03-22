<?php
/**
 * User SEO profile fields.
 *
 * Variables:
 *   @var WP_User          $user
 *   @var array            $meta   Current _mm_meta values
 *   @var MM_Site_Settings $settings
 */
defined( 'ABSPATH' ) || exit;
$title   = $meta['title']       ?? '';
$desc    = $meta['description'] ?? '';
$noindex = $meta['noindex']     ?? null;

$social_fields = MM_User_Meta_Panel::SOCIAL_FIELDS;
?>
<h2>Metamanager — Author Profile</h2>

<table class="form-table">
	<tr>
		<th><label for="mm_meta_title">SEO Title Override</label></th>
		<td>
			<input type="text" id="mm_meta_title" name="mm_meta_title"
				   value="<?php echo esc_attr($title); ?>" class="regular-text"
				   placeholder="Leave blank for the global author archive template">
			<p class="description">Template vars: <code>%%author_name%% %%sep%% %%sitetitle%%</code></p>
		</td>
	</tr>
	<tr>
		<th><label for="mm_meta_description">Meta Description</label></th>
		<td>
			<textarea id="mm_meta_description" name="mm_meta_description"
					  rows="3" class="regular-text gcm-desc-textarea"
					  placeholder="Leave blank to use author bio"><?php echo esc_textarea($desc); ?></textarea>
			<div class="gcm-char-bar">
				<span class="gcm-char-count" id="gcm-user-desc-count">0</span> / 160
			</div>
		</td>
	</tr>
	<tr>
		<th>noindex</th>
		<td>
			<fieldset>
				<label><input type="radio" name="mm_meta_noindex" value="" <?php checked($noindex,null); ?>> Default (inherit site setting)</label><br>
				<label><input type="radio" name="mm_meta_noindex" value="1" <?php checked($noindex,true); ?>> Force noindex (hide author archive from search)</label><br>
				<label><input type="radio" name="mm_meta_noindex" value="0" <?php checked($noindex,false); ?>> Force index (override even if site default is noindex)</label>
			</fieldset>
		</td>
	</tr>

	<tr>
		<th colspan="2"><h3 style="padding-bottom:0">Social Profiles</h3>
			<p class="description">Used in <code>Person</code> schema <code>sameAs</code> array and Twitter card <code>twitter:creator</code>.</p>
		</th>
	</tr>

	<?php foreach ( $social_fields as $field => $label ) :
		$val = $meta[ 'social_' . $field ] ?? '';
	?>
	<tr>
		<th><label for="mm_meta_social_<?php echo esc_attr($field); ?>"><?php echo esc_html($label); ?></label></th>
		<td>
			<input type="<?php echo in_array($field,['linkedin','instagram','website'],true)?'url':'text'; ?>"
				   id="mm_meta_social_<?php echo esc_attr($field); ?>"
				   name="mm_meta_social_<?php echo esc_attr($field); ?>"
				   value="<?php echo esc_attr($val); ?>"
				   class="regular-text"
				   placeholder="<?php echo 'twitter'===$field?'@handle':'https://…'; ?>">
		</td>
	</tr>
	<?php endforeach; ?>
</table>
