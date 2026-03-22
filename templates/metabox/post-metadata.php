<?php
/**
 * Post SEO metabox template.
 *
 * Variables available:
 *   @var WP_Post         $post
 *   @var array           $meta     Current _mm_meta values (decoded JSON)
 *   @var MM_Site_Settings $settings
 */
defined( 'ABSPATH' ) || exit;

$title            = $meta['title']           ?? '';
$description      = $meta['description']     ?? '';
$canonical        = $meta['canonical']       ?? '';
$noindex          = $meta['noindex']         ?? null;  // true|false|null
$nofollow         = $meta['nofollow']        ?? null;
$noarchive        = $meta['noarchive']       ?? null;
$nosnippet        = $meta['nosnippet']       ?? null;
$noimageindex     = $meta['noimageindex']    ?? null;
$og_title         = $meta['og_title']        ?? '';
$og_description   = $meta['og_description'] ?? '';
$og_image_id      = (int) ( $meta['og_image_id']  ?? 0 );
$og_image_url     = $meta['og_image_url']   ?? '';
$schema_type      = $meta['schema_type']    ?? '';
$breadcrumb_label = $meta['breadcrumb_label'] ?? '';
$exclude_sitemap  = ! empty( $meta['exclude_sitemap'] );

$pt_slug          = $post->post_type;
$default_noindex  = (bool) $settings->get( "titles.post_types.{$pt_slug}.noindex", false );

// Schema types for the override selector.
$schema_types = MM_Schema_Types::get_schema_types( true );

// Field definitions for expandable panels (types that need extra structured data).
$schema_field_defs  = MM_Schema_Types::get_fields_by_type();
$stored_schema_fields = $meta['schema_fields'] ?? [];

// Current resolved title for live preview.
$site_name  = get_bloginfo( 'name' );
$sep        = $settings->get( 'titles.separator', '|' );
?>
<div class="gcm-metabox" id="mm-meta-metabox">

	<?php /* ── Title ─────────────────────────────────────────────────── */ ?>
	<div class="gcm-field-group">
		<div class="gcm-metabox-preview" id="gcm-serp-preview">
			<div class="gcm-serp-title" id="gcm-serp-title-text">
				<?php echo esc_html( $title ?: ( get_the_title( $post ) . ' ' . $sep . ' ' . $site_name ) ); ?>
			</div>
			<div class="gcm-serp-url"><?php echo esc_html( get_permalink( $post ) ); ?></div>
			<div class="gcm-serp-desc" id="gcm-serp-desc-text">
				<?php echo esc_html( $description ?: wp_trim_words( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ), 25, '…' ) ); ?>
			</div>
		</div>

		<label class="gcm-field-label" for="mm_meta_title">SEO Title</label>
		<input type="text"
			   id="mm_meta_title"
			   name="mm_meta_title"
			   value="<?php echo esc_attr( $title ); ?>"
			   class="large-text"
			   placeholder="Leave blank to use the auto-generated title">
		<p class="description">Template vars: <code>%%post_title%% %%sep%% %%sitetitle%% %%category%%</code></p>
	</div>

	<?php /* ── Description ───────────────────────────────────────────── */ ?>
	<div class="gcm-field-group">
		<label class="gcm-field-label" for="mm_meta_description">Meta Description</label>
		<textarea id="mm_meta_description"
				  name="mm_meta_description"
				  rows="2"
				  class="large-text gcm-desc-textarea"
				  placeholder="Leave blank to auto-generate from excerpt / content"><?php echo esc_textarea( $description ); ?></textarea>
		<div class="gcm-char-bar">
			<span class="gcm-char-count" id="gcm-desc-count">0</span>
			<span class="gcm-char-max">/ 160</span>
			<div class="gcm-char-progress-wrap"><div class="gcm-char-progress" id="gcm-desc-progress"></div></div>
		</div>
	</div>

	<?php /* ── Canonical ─────────────────────────────────────────────── */ ?>
	<div class="gcm-field-group">
		<label class="gcm-field-label" for="mm_meta_canonical">Canonical URL</label>
		<input type="url"
			   id="mm_meta_canonical"
			   name="mm_meta_canonical"
			   value="<?php echo esc_attr( $canonical ); ?>"
			   class="large-text"
			   placeholder="Leave blank to use the auto-resolved canonical">
	</div>

	<?php /* ── Robots directives ────────────────────────────────────── */ ?>
	<div class="gcm-field-group">
		<label class="gcm-field-label">Robots Meta Directives</label>
		<p class="description">
			Leave all unchecked / "Default" to inherit site-level settings.
			The current post-type default is: <strong><?php echo $default_noindex ? 'noindex' : 'index'; ?></strong>.
		</p>

		<table class="gcm-robots-table">
			<thead>
				<tr>
					<th>Directive</th>
					<th>Default</th>
					<th>Force ON</th>
					<th>Force OFF</th>
					<th class="gcm-robots-help">Effect</th>
				</tr>
			</thead>
			<tbody>
				<?php
				$directives = [
					'noindex'      => [ 'Noindex',      'Don\'t add this page to the search index.' ],
					'nofollow'     => [ 'Nofollow',     'Don\'t follow links on this page.' ],
					'noarchive'    => [ 'Noarchive',    'Don\'t show a cached copy of this page.' ],
					'nosnippet'    => [ 'Nosnippet',    'Don\'t show a description snippet in search results.' ],
					'noimageindex' => [ 'Noimageindex', 'Don\'t index images on this page.' ],
				];
				foreach ( $directives as $field => $info ) :
					$current = $$field; // e.g. $noindex, $nofollow …
					?>
					<tr class="gcm-robots-row">
						<td><?php echo esc_html( $info[0] ); ?></td>
						<td>
							<label>
								<input type="radio"
									   name="mm_meta_<?php echo esc_attr($field); ?>"
									   value=""
									   <?php checked( $current, null ); ?>>
								Default
							</label>
						</td>
						<td>
							<label>
								<input type="radio"
									   name="mm_meta_<?php echo esc_attr($field); ?>"
									   value="1"
									   <?php checked( $current, true ); ?>>
								On
							</label>
						</td>
						<td>
							<label>
								<input type="radio"
									   name="mm_meta_<?php echo esc_attr($field); ?>"
									   value="0"
									   <?php checked( $current, false ); ?>>
								Off
							</label>
						</td>
						<td class="gcm-robots-help description"><?php echo esc_html( $info[1] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<?php /* ── Open Graph overrides ─────────────────────────────────── */ ?>
	<div class="gcm-field-group">
		<button type="button" class="gcm-toggle-section" data-target="gcm-og-section">
			▶ Open Graph Overrides
		</button>
		<div class="gcm-collapsible" id="gcm-og-section" style="display:none">
			<label class="gcm-field-label" for="mm_meta_og_title">OG Title</label>
			<input type="text" id="mm_meta_og_title" name="mm_meta_og_title"
				   value="<?php echo esc_attr($og_title); ?>" class="large-text"
				   placeholder="Leave blank to use SEO title (or post title)">

			<label class="gcm-field-label" for="mm_meta_og_description">OG Description</label>
			<textarea id="mm_meta_og_description" name="mm_meta_og_description"
					  rows="2" class="large-text"
					  placeholder="Leave blank to use meta description"><?php echo esc_textarea($og_description); ?></textarea>

			<label class="gcm-field-label">OG Image</label>
			<div class="gcm-media-picker" data-target="mm_meta_og_image_id" data-preview="gcm_og_post_img_preview">
				<input type="hidden" id="mm_meta_og_image_id" name="mm_meta_og_image_id" value="<?php echo (int)$og_image_id; ?>">
				<input type="url"   id="mm_meta_og_image_url" name="mm_meta_og_image_url"
					   value="<?php echo esc_attr($og_image_url); ?>" class="large-text"
					   placeholder="URL or pick from media library">
				<button type="button" class="button gcm-btn-pick-media">Choose Image</button>
				<img id="gcm_og_post_img_preview"
					 src="<?php echo esc_url($og_image_url); ?>"
					 class="gcm-image-preview"
					 style="max-height:80px;display:<?php echo $og_image_url ? 'block' : 'none'; ?>;margin-top:6px">
			</div>
			<p class="description">Overrides the featured image for social sharing. Best: 1200×630 px.</p>
		</div>
	</div>

	<?php /* ── Schema + breadcrumb ──────────────────────────────────── */ ?>
	<div class="gcm-field-group">
		<button type="button" class="gcm-toggle-section" data-target="gcm-schema-section">
			▶ Schema &amp; Breadcrumb
		</button>
		<div class="gcm-collapsible" id="gcm-schema-section" style="display:none">
			<div class="gcm-metabox-row">
				<label class="gcm-field-label" for="mm_meta_schema_type">Schema Type</label>
				<select id="mm_meta_schema_type" name="mm_meta_schema_type">
					<?php foreach ( $schema_types as $st_val => $st_label ) : ?>
						<option value="<?php echo esc_attr($st_val); ?>" <?php selected($schema_type,$st_val); ?>><?php echo esc_html($st_label); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<?php /* ── Per-type field panels ──────────────────────────────────── */ ?>
			<?php foreach ( $schema_field_defs as $panel_type => $panel_fields ) : ?>
				<div class="gcm-schema-fields-panel"
					 data-schema-type="<?php echo esc_attr( $panel_type ); ?>"
					 style="<?php echo ( $schema_type === $panel_type ) ? '' : 'display:none'; ?>">
					<p class="gcm-schema-panel-heading"><?php echo esc_html( $panel_type ); ?> fields</p>
					<?php foreach ( $panel_fields as $field ) :
						$fk  = $field['key'];
						$fv  = $stored_schema_fields[ $fk ] ?? '';
					?>
					<div class="gcm-schema-field-row">
						<label class="gcm-schema-field-label" for="gcm_sf_<?php echo esc_attr( $fk ); ?>">
							<?php echo esc_html( $field['label'] ); ?>
							<?php if ( ! empty( $field['required'] ) ) : ?><span class="gcm-required">*</span><?php endif; ?>
						</label>
						<?php if ( ! empty( $field['auto_label'] ) ) : ?>
							<span class="gcm-schema-auto-note">Auto: <?php echo esc_html( $field['auto_label'] ); ?></span>
						<?php endif; ?>
						<?php if ( 'select' === $field['type'] ) : ?>
							<select id="gcm_sf_<?php echo esc_attr( $fk ); ?>"
								    name="mm_meta_schema_fields[<?php echo esc_attr( $fk ); ?>]">
								<?php foreach ( $field['options'] as $opt_val => $opt_label ) : ?>
									<option value="<?php echo esc_attr( $opt_val ); ?>" <?php selected( $fv, $opt_val ); ?>>
										<?php echo esc_html( $opt_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						<?php else : ?>
							<input type="<?php echo esc_attr( $field['type'] ); ?>"
								   id="gcm_sf_<?php echo esc_attr( $fk ); ?>"
								   name="mm_meta_schema_fields[<?php echo esc_attr( $fk ); ?>]"
								   value="<?php echo esc_attr( $fv ); ?>"
								   placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>"
								   class="<?php echo ( 'number' === $field['type'] ) ? 'small-text' : 'large-text'; ?>">
						<?php endif; ?>
						<?php if ( ! empty( $field['description'] ) ) : ?>
							<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
						<?php endif; ?>
					</div>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>

			<div class="gcm-metabox-row">
				<label class="gcm-field-label" for="mm_meta_breadcrumb_label">Breadcrumb Label</label>
				<input type="text" id="mm_meta_breadcrumb_label" name="mm_meta_breadcrumb_label"
					   value="<?php echo esc_attr($breadcrumb_label); ?>" class="large-text"
					   placeholder="Leave blank to use the post title">
			</div>
		</div>
	</div>

	<?php /* ── Sitemap ──────────────────────────────────────────────── */ ?>
	<div class="gcm-field-group">
		<label>
			<input type="checkbox" name="mm_meta_exclude_sitemap" value="1" <?php checked($exclude_sitemap); ?>>
			Exclude this post from XML sitemap and HTML sitemap
		</label>
	</div>

</div>
