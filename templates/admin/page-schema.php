<?php
/**
 * Admin tab — Schema.
 * @var MM_Site_Settings $settings
 */
defined( 'ABSPATH' ) || exit;
$opt  = MM_META_OPT_SETTINGS;
$s    = $settings;
$post_types = get_post_types(['public'=>true],'objects');
unset($post_types['attachment']);
$pt_types = $s->get('schema.post_type_types', []);

$schema_types = MM_Schema_Types::get_schema_types();
?>
<div class="mm-meta-panel" id="page-schema">

	<h2>Site-Wide Entity</h2>
	<table class="form-table gcm-form-table">
		<tr>
			<th>Knowledge Entity</th>
			<td>
				<select name="<?php echo esc_attr($opt); ?>[schema][knowledge_entity]">
					<option value="Organization" <?php selected($s->get('schema.knowledge_entity','LocalBusiness'), 'Organization'); ?>>Organization</option>
					<option value="LocalBusiness" <?php selected($s->get('schema.knowledge_entity','LocalBusiness'), 'LocalBusiness'); ?>>LocalBusiness (use Business tab for subtype)</option>
				</select>
				<p class="description">Used as the publisher and graph root. If you have a physical location, choose LocalBusiness.</p>
			</td>
		</tr>
		<tr>
			<th>SearchAction Sitelinks</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[schema][website_searchaction]" value="1"
						<?php checked( $s->get('schema.website_searchaction', true) ); ?>>
					Add <code>SearchAction</code> to <code>WebSite</code> node (enables sitelinks searchbox)
				</label>
			</td>
		</tr>
		<tr>
			<th>Breadcrumbs</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[schema][breadcrumbs]" value="1"
						<?php checked( $s->get('schema.breadcrumbs', true) ); ?>>
					Emit <code>BreadcrumbList</code> schema on all non-homepage pages
				</label>
			</td>
		</tr>
		<tr>
			<th>Author Person nodes</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[schema][author_persons]" value="1"
						<?php checked( $s->get('schema.author_persons', true) ); ?>>
					Include author <code>Person</code> nodes referenced from <code>BlogPosting</code>/<code>Article</code>
				</label>
			</td>
		</tr>
		<tr>
			<th>Archive ItemList</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[schema][archive_itemlist]" value="1"
						<?php checked( $s->get('schema.archive_itemlist', true) ); ?>>
					Add <code>ItemList</code> schema on taxonomy and post-type archive pages
				</label>
			</td>
		</tr>
	</table>

	<h2>Default Schema Type per Post Type</h2>
	<p class="description">These are overridable per-post via the SEO metabox.</p>
	<table class="form-table gcm-form-table">
		<?php foreach ( $post_types as $pt_slug => $pt_obj ) : ?>
		<tr>
			<th><label for="gcm_schema_type_<?php echo esc_attr($pt_slug); ?>"><?php echo esc_html($pt_obj->labels->name); ?></label></th>
			<td>
				<select id="gcm_schema_type_<?php echo esc_attr($pt_slug); ?>" name="<?php echo esc_attr($opt); ?>[schema][post_type_types][<?php echo esc_attr($pt_slug); ?>]">
					<?php foreach ( $schema_types as $st_val => $st_label ) : ?>
						<option value="<?php echo esc_attr($st_val); ?>" <?php selected($pt_types[$pt_slug]??'WebPage', $st_val); ?>><?php echo esc_html($st_label); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<?php endforeach; ?>
	</table>

	<h2>Custom JSON-LD</h2>
	<p class="description">Append raw JSON-LD to every page. Must be valid schema.org markup. Use sparingly — per-post schema is handled via post type settings above.</p>
	<textarea name="<?php echo esc_attr($opt); ?>[schema][custom_json_ld]"
		rows="6" class="large-text code"><?php echo esc_textarea( $s->get('schema.custom_json_ld','') ); ?></textarea>

</div>
