<?php
/**
 * Admin tab — Titles & Meta.
 * @var MM_Site_Settings $settings
 */
defined( 'ABSPATH' ) || exit;
$opt = MM_META_OPT_SETTINGS;
$s   = $settings;

$sep       = $s->get('titles.separator', '|');
$home_t    = $s->get('titles.home_title',       '%%sitetitle%% %%sep%% %%tagline%%');
$home_d    = $s->get('titles.home_description',  '');
$date_ni   = $s->get('titles.date_archive_noindex', true);
$search_ni = $s->get('titles.search_noindex', true);
$page_app  = $s->get('titles.paginate_append', true);

$pt_settings   = $s->get('titles.post_types', []);
$tax_settings  = $s->get('titles.taxonomies', []);

// All publicly visible post types.
$post_types = get_post_types(['public'=>true], 'objects');
unset($post_types['attachment']);

// All public taxonomies.
$taxonomies = get_taxonomies(['public'=>true], 'objects');
unset($taxonomies['post_format']);

$template_vars_help = '%%sitetitle%% %%tagline%% %%sep%% %%post_title%% %%term_title%% %%term_description%% %%author_name%% %%page%% %%current_year%% %%post_type_label%%';
?>
<div class="mm-meta-panel" id="page-titles">

	<h2>Global Defaults</h2>
	<table class="form-table gcm-form-table">
		<tr>
			<th><label for="gcm_sep">Title Separator</label></th>
			<td>
				<select id="gcm_sep" name="<?php echo esc_attr($opt); ?>[titles][separator]">
					<?php foreach ( ['|','–','—','·','•','›','»','/','~'] as $sep_opt ) : ?>
						<option value="<?php echo esc_attr($sep_opt); ?>" <?php selected($sep,$sep_opt); ?>><?php echo esc_html($sep_opt); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th><label for="gcm_home_title">Homepage Title</label></th>
			<td>
				<input type="text" id="gcm_home_title" name="<?php echo esc_attr($opt); ?>[titles][home_title]"
					value="<?php echo esc_attr($home_t); ?>" class="large-text">
				<p class="description">Template vars: <code><?php echo esc_html($template_vars_help); ?></code></p>
			</td>
		</tr>
		<tr>
			<th><label for="gcm_home_desc">Homepage Meta Description</label></th>
			<td>
				<textarea id="gcm_home_desc" name="<?php echo esc_attr($opt); ?>[titles][home_description]"
					rows="2" class="large-text gcm-desc-field"><?php echo esc_textarea($home_d); ?></textarea>
				<p class="description gcm-char-counter">0 / 160</p>
			</td>
		</tr>
		<tr>
			<th>Pagination</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[titles][paginate_append]" value="1" <?php checked($page_app); ?>>
					Append page number to title on paginated archives (Page 2 of …)
				</label>
			</td>
		</tr>
		<tr>
			<th>Date Archives</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[titles][date_archive_noindex]" value="1" <?php checked($date_ni); ?>>
					noindex date archives by default
				</label>
			</td>
		</tr>
		<tr>
			<th>Search Results</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[titles][search_noindex]" value="1" <?php checked($search_ni); ?>>
					noindex search result pages
				</label>
			</td>
		</tr>
	</table>

	<h2>Post Types</h2>
	<?php foreach ( $post_types as $pt_slug => $pt_obj ) :
		$pt_cfg = $pt_settings[$pt_slug] ?? [];
		$is_hier = $pt_obj->hierarchical;
	?>
	<div class="gcm-post-type-block">
		<h3 class="gcm-pt-heading"><?php echo esc_html($pt_obj->labels->name); ?> (<code><?php echo esc_html($pt_slug); ?></code>)</h3>
		<table class="form-table gcm-form-table gcm-form-table--nested">
			<tr>
				<th>Single Title Template</th>
				<td>
					<input type="text"
						name="<?php echo esc_attr($opt); ?>[titles][post_types][<?php echo esc_attr($pt_slug); ?>][single_title]"
						value="<?php echo esc_attr($pt_cfg['single_title'] ?? '%%post_title%% %%sep%% %%sitetitle%%'); ?>"
						class="large-text">
				</td>
			</tr>
			<?php if ( $pt_obj->has_archive || ! $is_hier ) : ?>
			<tr>
				<th>Archive Title Template</th>
				<td>
					<input type="text"
						name="<?php echo esc_attr($opt); ?>[titles][post_types][<?php echo esc_attr($pt_slug); ?>][archive_title]"
						value="<?php echo esc_attr($pt_cfg['archive_title'] ?? '%%post_type_label%% %%sep%% %%sitetitle%%'); ?>"
						class="large-text">
				</td>
			</tr>
			<?php endif; ?>
			<tr>
				<th>Description Source</th>
				<td>
					<select name="<?php echo esc_attr($opt); ?>[titles][post_types][<?php echo esc_attr($pt_slug); ?>][description_source]">
						<?php foreach (['excerpt'=>'Excerpt first, then content','content'=>'Content (trimmed)','none'=>'None (manual only)'] as $ds_val => $ds_label) : ?>
							<option value="<?php echo esc_attr($ds_val); ?>" <?php selected($pt_cfg['description_source']??'excerpt',$ds_val); ?>><?php echo esc_html($ds_label); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th>Robots Defaults</th>
				<td>
					<label>
						<input type="checkbox"
							name="<?php echo esc_attr($opt); ?>[titles][post_types][<?php echo esc_attr($pt_slug); ?>][noindex]"
							value="1" <?php checked($pt_cfg['noindex']??false); ?>>
						noindex all <em><?php echo esc_html($pt_obj->labels->name); ?></em> by default
					</label>
					<?php if ( $pt_obj->has_archive ) : ?>
					<br>
					<label>
						<input type="checkbox"
							name="<?php echo esc_attr($opt); ?>[titles][post_types][<?php echo esc_attr($pt_slug); ?>][noindex_archive]"
							value="1" <?php checked($pt_cfg['noindex_archive']??false); ?>>
						noindex post type archive (<?php echo esc_html($pt_slug); ?> archive page)
					</label>
					<?php endif; ?>
				</td>
			</tr>
		</table>
	</div>
	<?php endforeach; ?>

	<h2>Taxonomies</h2>
	<?php foreach ( $taxonomies as $tax_slug => $tax_obj ) :
		$tax_cfg = $tax_settings[$tax_slug] ?? [];
	?>
	<div class="gcm-taxonomy-block">
		<h3 class="gcm-pt-heading"><?php echo esc_html($tax_obj->labels->name); ?> (<code><?php echo esc_html($tax_slug); ?></code>)</h3>
		<table class="form-table gcm-form-table gcm-form-table--nested">
			<tr>
				<th>Archive Title Template</th>
				<td>
					<input type="text"
						name="<?php echo esc_attr($opt); ?>[titles][taxonomies][<?php echo esc_attr($tax_slug); ?>][archive_title]"
						value="<?php echo esc_attr($tax_cfg['archive_title'] ?? '%%term_title%% %%sep%% %%sitetitle%%'); ?>"
						class="large-text">
				</td>
			</tr>
			<tr>
				<th>Robots Default</th>
				<td>
					<label>
						<input type="checkbox"
							name="<?php echo esc_attr($opt); ?>[titles][taxonomies][<?php echo esc_attr($tax_slug); ?>][noindex]"
							value="1" <?php checked($tax_cfg['noindex']??false); ?>>
						noindex all <em><?php echo esc_html($tax_obj->labels->name); ?></em> archives by default
					</label>
				</td>
			</tr>
		</table>
	</div>
	<?php endforeach; ?>

</div>
