<?php
/**
 * Admin tab — Sitemaps.
 * @var MM_Site_Settings $settings
 */
defined( 'ABSPATH' ) || exit;
$opt = MM_META_OPT_SETTINGS;
$s   = $settings;

$pt_enabled  = $s->get('sitemap.post_types', ['post'=>true,'page'=>true]);
$tax_enabled = $s->get('sitemap.taxonomies',  ['category'=>true]);

$public_pts  = get_post_types(['public'=>true],'objects');
unset($public_pts['attachment']);
$public_taxs = get_taxonomies(['public'=>true],'objects');
unset($public_taxs['post_format']);
?>
<div class="mm-meta-panel" id="page-sitemaps">

	<h2>XML Sitemaps</h2>
	<table class="form-table gcm-form-table">
		<tr>
			<th>Enable Sitemaps</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[sitemap][enabled]" value="1"
						<?php checked( $s->get('sitemap.enabled', true) ); ?>>
					Generate XML sitemaps at <a href="<?php echo esc_url(home_url('/sitemap.xml')); ?>" target="_blank">/sitemap.xml</a>
				</label>
			</td>
		</tr>
		<tr>
			<th>Records per File</th>
			<td>
				<input type="number" name="<?php echo esc_attr($opt); ?>[sitemap][records_per_file]"
					value="<?php echo (int) $s->get('sitemap.records_per_file', 1000); ?>"
					min="100" max="50000" class="small-text">
			</td>
		</tr>
		<tr>
			<th>Exclude</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[sitemap][exclude_password_protected]" value="1"
						<?php checked( $s->get('sitemap.exclude_password_protected', true) ); ?>>
					Exclude password-protected posts
				</label><br>
				<label>
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[sitemap][exclude_noindexed]" value="1"
						<?php checked( $s->get('sitemap.exclude_noindexed', true) ); ?>>
					Exclude noindexed posts/terms
				</label>
			</td>
		</tr>
	</table>

	<h2>Post Types in Sitemap</h2>
	<table class="form-table gcm-form-table">
		<tr>
			<th>Include</th>
			<td>
				<?php foreach ( $public_pts as $pt_slug => $pt_obj ) : ?>
					<label style="display:block;margin-bottom:4px">
						<input type="checkbox"
							name="<?php echo esc_attr($opt); ?>[sitemap][post_types][<?php echo esc_attr($pt_slug); ?>]"
							value="1"
							<?php checked( ! empty($pt_enabled[$pt_slug]) ); ?>>
						<?php echo esc_html($pt_obj->labels->name); ?> (<code><?php echo esc_html($pt_slug); ?></code>)
					</label>
				<?php endforeach; ?>
			</td>
		</tr>
	</table>

	<h2>Taxonomies in Sitemap</h2>
	<table class="form-table gcm-form-table">
		<tr>
			<th>Include</th>
			<td>
				<?php foreach ( $public_taxs as $tax_slug => $tax_obj ) : ?>
					<label style="display:block;margin-bottom:4px">
						<input type="checkbox"
							name="<?php echo esc_attr($opt); ?>[sitemap][taxonomies][<?php echo esc_attr($tax_slug); ?>]"
							value="1"
							<?php checked( ! empty($tax_enabled[$tax_slug]) ); ?>>
						<?php echo esc_html($tax_obj->labels->name); ?> (<code><?php echo esc_html($tax_slug); ?></code>)
					</label>
				<?php endforeach; ?>
			</td>
		</tr>
	</table>

	<h2>Image Sitemap</h2>
	<table class="form-table gcm-form-table">
		<tr>
			<th>Include Images</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[sitemap][images]" value="1"
						<?php checked( $s->get('sitemap.images', true) ); ?>>
					Add <code>image:image</code> nodes (featured image + content images)
				</label>
			</td>
		</tr>
	</table>

	<h2>Video Sitemap</h2>
	<table class="form-table gcm-form-table">
		<tr>
			<th>Enable Video Sitemap</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[sitemap][video]" value="1"
						<?php checked( $s->get('sitemap.video', true) ); ?>>
					Generate <a href="<?php echo esc_url(home_url('/sitemap-video.xml')); ?>" target="_blank">/sitemap-video.xml</a>
				</label>
			</td>
		</tr>
		<tr>
			<th>Video Sources</th>
			<td>
				<label style="display:block;margin-bottom:4px">
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[sitemap][video_youtube]" value="1"
						<?php checked( $s->get('sitemap.video_youtube', true) ); ?>>
					YouTube embeds
				</label>
				<label style="display:block;margin-bottom:4px">
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[sitemap][video_vimeo]" value="1"
						<?php checked( $s->get('sitemap.video_vimeo', true) ); ?>>
					Vimeo embeds
				</label>
				<label style="display:block">
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[sitemap][video_selfhosted]" value="1"
						<?php checked( $s->get('sitemap.video_selfhosted', true) ); ?>>
					Self-hosted <code>&lt;video&gt;</code> files (.mp4 / .webm)
				</label>
			</td>
		</tr>
	</table>

	<h2>Search Engine Pings</h2>
	<table class="form-table gcm-form-table">
		<tr>
			<th>Auto-ping on Publish</th>
			<td>
				<label style="display:block;margin-bottom:4px">
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[sitemap][ping_google]" value="1"
						<?php checked( $s->get('sitemap.ping_google', true) ); ?>>
					Ping Google when a post is published
				</label>
				<label style="display:block">
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[sitemap][ping_bing]" value="1"
						<?php checked( $s->get('sitemap.ping_bing', true) ); ?>>
					Ping Bing when a post is published
				</label>
			</td>
		</tr>
	</table>

	<h2>HTML Sitemap</h2>
	<?php
	$html_s    = $s->get( 'sitemap.html_sitemap', [] );
	$html_pts  = (array) ( $html_s['post_types'] ?? [ 'page', 'post' ] );
	$html_taxs = (array) ( $html_s['taxonomies'] ?? [] );
	?>
	<table class="form-table gcm-form-table">
		<tr>
			<th>Enable Shortcode</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[sitemap][html_sitemap][enabled]" value="1"
						<?php checked( $s->get('sitemap.html_sitemap.enabled', true) ); ?>>
					Register the <code>[mm_sitemap]</code> shortcode
				</label>
			</td>
		</tr>
		<tr>
			<th>Post Types</th>
			<td>
				<?php foreach ( $public_pts as $pt_slug => $pt_obj ) : ?>
					<label style="display:block;margin-bottom:4px">
						<input type="checkbox"
							name="<?php echo esc_attr($opt); ?>[sitemap][html_sitemap][post_types][]"
							value="<?php echo esc_attr($pt_slug); ?>"
							<?php checked( in_array( $pt_slug, $html_pts, true ) ); ?>>
						<?php echo esc_html($pt_obj->labels->name); ?> (<code><?php echo esc_html($pt_slug); ?></code>)
					</label>
				<?php endforeach; ?>
			</td>
		</tr>
		<tr>
			<th>Taxonomies</th>
			<td>
				<?php foreach ( $public_taxs as $tax_slug => $tax_obj ) : ?>
					<label style="display:block;margin-bottom:4px">
						<input type="checkbox"
							name="<?php echo esc_attr($opt); ?>[sitemap][html_sitemap][taxonomies][]"
							value="<?php echo esc_attr($tax_slug); ?>"
							<?php checked( in_array( $tax_slug, $html_taxs, true ) ); ?>>
						<?php echo esc_html($tax_obj->labels->name); ?> (<code><?php echo esc_html($tax_slug); ?></code>)
					</label>
				<?php endforeach; ?>
			</td>
		</tr>
		<tr>
			<th>Columns</th>
			<td>
				<input type="number" name="<?php echo esc_attr($opt); ?>[sitemap][html_sitemap][columns]"
					value="<?php echo (int) ( $html_s['columns'] ?? 1 ); ?>"
					min="1" max="4" class="small-text">
				<p class="description">Number of columns in the sitemap layout (1–4).</p>
			</td>
		</tr>
		<tr>
			<th>Order By</th>
			<td>
				<select name="<?php echo esc_attr($opt); ?>[sitemap][html_sitemap][order_by]">
					<?php foreach ( [ 'menu_order' => 'Menu Order', 'title' => 'Title', 'date' => 'Date Published' ] as $v => $l ) : ?>
						<option value="<?php echo esc_attr($v); ?>" <?php selected( $html_s['order_by'] ?? 'menu_order', $v ); ?>><?php echo esc_html($l); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
	</table>

</div>
