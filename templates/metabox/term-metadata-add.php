<?php
/**
 * Term SEO fields — add-term form (divs, not table rows).
 *
 * Variables:
 *   @var string           $taxonomy
 *   @var MM_Site_Settings $settings  (not used yet but available)
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="form-field term-mm-meta-wrap">
	<h3 style="margin-bottom:4px">Metamanager</h3>

	<div class="form-field">
		<label for="mm_meta_title">SEO Title</label>
		<input type="text" id="mm_meta_title" name="mm_meta_title" value="" class="large-text"
			   placeholder="Auto-generated from term name if blank">
	</div>

	<div class="form-field">
		<label for="mm_meta_description">Meta Description</label>
		<textarea id="mm_meta_description" name="mm_meta_description" rows="3" class="large-text"
				  placeholder="Defaults to term description"></textarea>
	</div>

	<div class="form-field">
		<label>Noindex</label>
		<label><input type="radio" name="mm_meta_noindex" value=""> Default</label>
		<label style="margin-left:10px"><input type="radio" name="mm_meta_noindex" value="1"> On</label>
		<label style="margin-left:10px"><input type="radio" name="mm_meta_noindex" value="0"> Off</label>
	</div>

	<div class="form-field">
		<label><input type="checkbox" name="mm_meta_exclude_sitemap" value="1"> Exclude from sitemap</label>
	</div>
</div>
