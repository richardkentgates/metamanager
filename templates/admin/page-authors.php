<?php
/**
 * Admin tab — Authors.
 * @var MM_Site_Settings $settings
 */
defined( 'ABSPATH' ) || exit;
$opt = MM_META_OPT_SETTINGS;
$s   = $settings;
?>
<div class="mm-meta-panel" id="page-authors">

	<h2>Author Archive SEO</h2>
	<table class="form-table gcm-form-table">
		<tr>
			<th>Enable Author SEO</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[authors][enabled]" value="1"
						<?php checked( $s->get('authors.enabled', true) ); ?>>
					Enable author archive optimisation and Person schema
				</label>
			</td>
		</tr>
		<tr>
			<th><label for="gcm_author_title">Author Archive Title</label></th>
			<td>
				<input type="text" id="gcm_author_title"
					name="<?php echo esc_attr($opt); ?>[authors][title_template]"
					value="<?php echo esc_attr( $s->get('authors.title_template','Articles by %%author_name%% %%sep%% %%sitetitle%%') ); ?>"
					class="large-text">
			</td>
		</tr>
		<tr>
			<th><label for="gcm_author_desc">Author Description Template</label></th>
			<td>
				<input type="text" id="gcm_author_desc"
					name="<?php echo esc_attr($opt); ?>[authors][description_template]"
					value="<?php echo esc_attr( $s->get('authors.description_template','%%author_bio%%') ); ?>"
					class="large-text">
				<p class="description">Default: <code>%%author_bio%%</code>. Overridable per-user in the profile editor.</p>
			</td>
		</tr>
		<tr>
			<th>Default noindex</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[authors][noindex_default]" value="1"
						<?php checked( $s->get('authors.noindex_default', false) ); ?>>
					noindex all author archives by default (overridable per-user)
				</label>
			</td>
		</tr>
	</table>

	<h2>Person Schema</h2>
	<table class="form-table gcm-form-table">
		<tr>
			<th>Emit Person nodes</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[authors][person_schema]" value="1"
						<?php checked( $s->get('authors.person_schema', true) ); ?>>
					Output <code>Person</code> JSON-LD nodes on author archive pages
				</label>
			</td>
		</tr>
		<tr>
			<th>Social Fields on Profile</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[authors][profile_social_fields]" value="1"
						<?php checked( $s->get('authors.profile_social_fields', true) ); ?>>
					Show social profile link fields on the WordPress user edit screen
				</label>
				<p class="description">Twitter, LinkedIn, Instagram, BlueSky, and personal website. These populate <code>sameAs</code> in Person schema.</p>
			</td>
		</tr>
	</table>

</div>
