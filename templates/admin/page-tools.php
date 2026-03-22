<?php
/**
 * Admin tab — Tools.
 * @var MM_Site_Settings $settings
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="mm-meta-panel" id="page-tools">

	<h2>Diagnostics &amp; Maintenance</h2>

	<div class="gcm-tool-card">
		<h3>Flush Rewrite Rules</h3>
		<p class="description">Run this if sitemap URLs return 404.</p>
		<button type="button" class="button gcm-tool-btn" data-action="flush_rewrite">Flush Rules</button>
		<div class="gcm-tool-result" id="gcm-flush-result"></div>
	</div>

	<div class="gcm-tool-card">
		<h3>Ping Search Engines</h3>
		<p class="description">Immediately send sitemap URL to Google and Bing.</p>
		<button type="button" class="button gcm-tool-btn" data-action="ping_sitemap">Send Ping Now</button>
		<div class="gcm-tool-result" id="gcm-ping-result"></div>
	</div>

	<div class="gcm-tool-card">
		<h3>Purge Link Table</h3>
		<p class="description">Clears all rows in the broken-link tracking table. Links will be re-queued on the next post save or cron run.</p>
		<button type="button" class="button gcm-tool-btn" data-action="purge_links">Purge Links Table</button>
		<div class="gcm-tool-result" id="gcm-purge-result"></div>
	</div>

	<hr>

	<h2>Reset Settings</h2>
	<div class="gcm-tool-card gcm-tool-card--danger">
		<p class="description"><strong>Danger zone.</strong> Resets ALL plugin settings and business profile to factory defaults. Per-post/term/user meta is not affected.</p>
		<button type="button" class="button gcm-tool-btn" data-action="reset_settings" id="gcm-reset-settings" data-confirm="Reset all settings to defaults? This cannot be undone.">
			Reset to Defaults
		</button>
		<div class="gcm-tool-result" id="gcm-reset-result"></div>
	</div>

	<hr>

	<h2>System Info</h2>
	<table class="form-table gcm-form-table">
		<tr><th>Plugin Version</th><td><?php echo esc_html(MM_META_VERSION); ?></td></tr>
		<tr><th>WordPress Version</th><td><?php echo esc_html(get_bloginfo('version')); ?></td></tr>
		<tr><th>PHP Version</th><td><?php echo esc_html(PHP_VERSION); ?></td></tr>
		<tr><th>Home URL</th><td><code><?php echo esc_html(home_url()); ?></code></td></tr>
		<tr>
			<th>Active Theme</th>
			<?php $theme = wp_get_theme(); ?>
			<td><?php echo esc_html($theme->get('Name')); ?> <?php echo esc_html($theme->get('Version')); ?></td>
		</tr>
		<tr>
			<th>Title Tag Support</th>
			<td><?php echo current_theme_supports('title-tag') ? '<span style="color:green">✓ yes</span>' : '<span style="color:orange">✗ no (Metamanager outputs &lt;title&gt; directly)</span>'; ?></td>
		</tr>
		<tr>
			<th>Sitemap URL</th>
			<td><a href="<?php echo esc_url(home_url('/sitemap.xml')); ?>" target="_blank"><?php echo esc_html(home_url('/sitemap.xml')); ?></a></td>
		</tr>
		<tr>
			<th>Robots.txt URL</th>
			<td><a href="<?php echo esc_url(home_url('/robots.txt')); ?>" target="_blank"><?php echo esc_html(home_url('/robots.txt')); ?></a></td>
		</tr>
	</table>

	<script>
	(function($) {
		var nonce = '<?php echo esc_js( wp_create_nonce('mm_meta_tools_nonce') ); ?>';
		var ajax  = '<?php echo esc_js( admin_url('admin-ajax.php') ); ?>';

		$('.gcm-tool-btn').on('click', function() {
			var $btn     = $(this);
			var action   = $btn.data('action');
			var origText = $btn.text();
			var confirm_msg = $btn.data('confirm');
			if (confirm_msg && !window.confirm(confirm_msg)) { return; }

			$btn.prop('disabled', true).text('Working…');

			$.post(ajax, {
				action:       'mm_meta_tools_action',
				tools_action: action,
				_nonce:       nonce
			}, function(res) {
				$btn.prop('disabled', false).text(origText);
				var $result = $btn.next('.gcm-tool-result').show();
				if (res.success) {
					var msg = typeof res.data === 'string' ? res.data : 'Done.';
					if ( action === 'import_dry_run' || action === 'import_write' ) {
						if (res.data && res.data.actions) {
							msg = '<strong>' + res.data.total + ' record(s)</strong><br>';
							var rows = res.data.actions.slice(0,50);
							msg += '<table class="wp-list-table widefat striped" style="margin-top:8px"><thead><tr><th>Type</th><th>ID</th><th>Key</th></tr></thead><tbody>';
							rows.forEach(function(r) {
								msg += '<tr><td>' + r.type + '</td><td>' + (r.post_id||r.term_id||r.user_id||'—') + '</td><td>' + r.key + '</td></tr>';
							});
							msg += '</tbody></table>';
						}
					}
					$result.html('<div class="notice notice-success inline" style="margin:8px 0"><p>' + msg + '</p></div>');
				} else {
					$result.html('<div class="notice notice-error inline" style="margin:8px 0"><p>' + (res.data||'Error') + '</p></div>');
				}
			}).fail(function() {
				$btn.prop('disabled', false).text(origText);
				$btn.next('.gcm-tool-result').html('<div class="notice notice-error inline"><p>Request failed.</p></div>').show();
			});
		});
	})(jQuery);
	</script>

</div>
