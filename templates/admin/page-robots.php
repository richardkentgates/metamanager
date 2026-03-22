<?php
/**
 * Admin tab — Robots.txt.
 * @var MM_Site_Settings $settings
 */
defined( 'ABSPATH' ) || exit;
$opt      = MM_META_OPT_SETTINGS;
$s        = $settings;
$disallow = $s->get('robots.disallow', ['/wp-admin/', '/wp-login.php']);
$allow    = $s->get('robots.allow',    ['/wp-admin/admin-ajax.php']);
?>
<div class="mm-meta-panel" id="page-robots">

	<h2>Dynamic Robots.txt</h2>
	<p class="description">
		Metamanager overwrites the WordPress-generated robots.txt via the <code>robots_txt</code> filter.
		A physical <code>robots.txt</code> file in your web root will take precedence — remove it if it exists.
	</p>

	<table class="form-table gcm-form-table">
		<tr>
			<th>Manage robots.txt</th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr($opt); ?>[robots][enabled]" value="1"
						<?php checked( $s->get('robots.enabled', true) ); ?>>
					Enable Metamanager robots.txt output
				</label>
			</td>
		</tr>
		<tr>
			<th>Crawl-Delay</th>
			<td>
				<input type="number" name="<?php echo esc_attr($opt); ?>[robots][crawl_delay]"
					value="<?php echo esc_attr( (string) $s->get('robots.crawl_delay','') ); ?>"
					min="0" max="60" class="small-text" placeholder="Seconds (optional)">
				<p class="description">Leave blank to omit. Respected by some crawlers (not Google).</p>
			</td>
		</tr>
	</table>

	<h2>Disallow Rules</h2>
	<p class="description">One path per row. Applies to <code>User-agent: *</code>.</p>
	<div class="gcm-repeater" id="gcm-disallow-repeater">
		<ul class="gcm-simple-list" id="gcm-disallow-list" data-name-base="<?php echo esc_attr($opt); ?>[robots][disallow]">
			<?php foreach ( (array) $disallow as $rule ) : ?>
				<li class="gcm-simple-list-item">
					<input type="text" name="<?php echo esc_attr($opt); ?>[robots][disallow][]"
						value="<?php echo esc_attr($rule); ?>" class="regular-text" placeholder="/path/">
					<button type="button" class="button-link gcm-repeater-remove">✕</button>
				</li>
			<?php endforeach; ?>
		</ul>
		<button type="button" class="button gcm-repeater-add" data-target="gcm-disallow-list" data-template="disallow">+ Add Disallow Rule</button>
	</div>

	<h2>Allow Rules</h2>
	<p class="description">Explicitly allow paths inside a broader Disallow scope.</p>
	<div class="gcm-repeater" id="gcm-allow-repeater">
		<ul class="gcm-simple-list" id="gcm-allow-list" data-name-base="<?php echo esc_attr($opt); ?>[robots][allow]">
			<?php foreach ( (array) $allow as $rule ) : ?>
				<li class="gcm-simple-list-item">
					<input type="text" name="<?php echo esc_attr($opt); ?>[robots][allow][]"
						value="<?php echo esc_attr($rule); ?>" class="regular-text" placeholder="/path/">
					<button type="button" class="button-link gcm-repeater-remove">✕</button>
				</li>
			<?php endforeach; ?>
		</ul>
		<button type="button" class="button gcm-repeater-add" data-target="gcm-allow-list" data-template="allow">+ Add Allow Rule</button>
	</div>

	<h2>Custom Directives</h2>
	<p class="description">Appended verbatim after the generated rules. Useful for specific user-agents.</p>
	<textarea name="<?php echo esc_attr($opt); ?>[robots][custom]"
		rows="6" class="large-text code"
		placeholder="User-agent: Googlebot-Image&#10;Disallow: /wp-content/uploads/private/"><?php echo esc_textarea( $s->get('robots.custom','') ); ?></textarea>

	<h2>Preview</h2>
	<p>
		<a href="<?php echo esc_url( home_url('/robots.txt') ); ?>" target="_blank" class="button">
			View robots.txt ↗
		</a>
	</p>

</div>
