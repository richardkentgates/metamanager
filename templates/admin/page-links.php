<?php
/**
 * Admin tab — Broken Links.
 * @var MM_Site_Settings $settings
 */
defined( 'ABSPATH' ) || exit;
$opt = MM_META_OPT_SETTINGS;
$s   = $settings;
global $wpdb;
$table      = MM_Mod_Links::table_name();
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$stats = $wpdb->get_row( "SELECT
	COUNT(*) AS total,
	SUM(is_broken = 1 AND is_ignored = 0) AS broken,
	SUM(is_ignored = 1) AS ignored,
	SUM(is_broken = 0 AND http_code > 0) AS ok
	FROM {$table}", ARRAY_A );
$next_run = wp_next_scheduled('mm_meta_check_links');
?>
<div class="mm-meta-panel" id="page-links">

	<h2>Broken Link Checker</h2>

	<div class="gcm-links-stats">
		<div class="gcm-stat gcm-stat--broken">
			<span class="gcm-stat-number"><?php echo (int)($stats['broken']??0); ?></span>
			<span class="gcm-stat-label">Broken</span>
		</div>
		<div class="gcm-stat gcm-stat--ok">
			<span class="gcm-stat-number"><?php echo (int)($stats['ok']??0); ?></span>
			<span class="gcm-stat-label">OK</span>
		</div>
		<div class="gcm-stat gcm-stat--ignored">
			<span class="gcm-stat-number"><?php echo (int)($stats['ignored']??0); ?></span>
			<span class="gcm-stat-label">Ignored</span>
		</div>
		<div class="gcm-stat gcm-stat--total">
			<span class="gcm-stat-number"><?php echo (int)($stats['total']??0); ?></span>
			<span class="gcm-stat-label">Total</span>
		</div>
	</div>

	<?php if ( $next_run ) : ?>
		<p class="description">Next scheduled scan: <?php echo esc_html( human_time_diff( $next_run ) ); ?> from now (<?php echo esc_html( get_date_from_gmt( gmdate('Y-m-d H:i:s', $next_run), 'H:i T' ) ); ?>)</p>
	<?php endif; ?>

	<div class="gcm-links-scan-wrap" style="margin:16px 0;padding:12px 16px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;">
		<button type="button" class="button button-primary" id="gcm-scan-all-posts"><?php esc_html_e( 'Scan All Posts for Links', 'metamanager' ); ?></button>
		<span id="gcm-scan-progress" style="display:none;margin-left:12px;">
			<span id="gcm-scan-count">0</span> / <span id="gcm-scan-total">0</span> <?php esc_html_e( 'posts processed&hellip;', 'metamanager' ); ?>
		</span>
		<span id="gcm-scan-done" style="display:none;margin-left:12px;color:#008a00;">&#10003; <?php esc_html_e( 'Scan complete — reload the page to see updated stats.', 'metamanager' ); ?></span>
		<p class="description" style="margin-top:8px;"><?php esc_html_e( 'Scans all published posts and extracts their links into the checker. Posts already indexed (saved after the plugin was installed) are skipped automatically. Also available via WP-CLI: wp metamanager backfill-links', 'metamanager' ); ?></p>
	</div>

	<h3>Settings</h3>
	<form method="post" action="options.php">
		<?php settings_fields('mm_meta_links_group'); ?>
		<table class="form-table gcm-form-table">
			<tr>
				<th>Enable Checker</th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr($opt); ?>[links][enabled]" value="1"
							<?php checked( $s->get('links.enabled', true) ); ?>>
						Actively check links in published posts
					</label>
				</td>
			</tr>
			<tr>
				<th>Check Frequency</th>
				<td>
					<select name="<?php echo esc_attr($opt); ?>[links][cron_frequency]">
						<?php foreach (['mm_meta_every6h'=>'Every 6 hours','twicedaily'=>'Twice daily','daily'=>'Daily'] as $v=>$l) : ?>
							<option value="<?php echo esc_attr($v); ?>" <?php selected($s->get('links.cron_frequency','twicedaily'),$v); ?>><?php echo esc_html($l); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th>Batch Size</th>
				<td>
					<input type="number" name="<?php echo esc_attr($opt); ?>[links][batch_size]"
						value="<?php echo (int) $s->get('links.batch_size', 50); ?>"
						min="10" max="500" class="small-text">
					<p class="description">Links checked per cron run.</p>
				</td>
			</tr>
			<tr>
				<th>Timeout</th>
				<td>
					<input type="number" name="<?php echo esc_attr($opt); ?>[links][timeout]"
						value="<?php echo (int) $s->get('links.timeout', 10); ?>"
						min="3" max="30" class="small-text">
					seconds
				</td>
			</tr>
			<tr>
				<th>Check External Links</th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr($opt); ?>[links][check_external]" value="1"
							<?php checked( $s->get('links.check_external', true) ); ?>>
						Also check external (off-site) links
					</label>
				</td>
			</tr>
			<tr>
				<th>Email Alerts</th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr($opt); ?>[links][email_alerts]" value="1"
							<?php checked( $s->get('links.email_alerts', false) ); ?>>
						Send email when broken links are found
					</label>
					<br>
					<input type="email" name="<?php echo esc_attr($opt); ?>[links][email_address]"
						value="<?php echo esc_attr( $s->get('links.email_address', get_option('admin_email')) ); ?>"
						class="regular-text" placeholder="admin@example.com">
				</td>
			</tr>
		</table>
		<?php submit_button( 'Save Link Settings' ); ?>
	</form>

	<hr>

	<h3>Broken Links</h3>

	<div class="gcm-links-filter-bar">
		<button type="button" class="button gcm-links-filter-btn gcm-links-filter-btn--active" data-filter="broken">Broken</button>
		<button type="button" class="button gcm-links-filter-btn" data-filter="ok">OK</button>
		<button type="button" class="button gcm-links-filter-btn" data-filter="ignored">Ignored</button>
		<button type="button" class="button gcm-links-filter-btn" data-filter="">All</button>
		<div class="gcm-links-actions" style="float:right">
			<button type="button" class="button" id="gcm-recheck-all">Re-check All in View</button>
		</div>
	</div>

	<div id="gcm-links-table-wrap">
		<p class="gcm-links-loading">Loading…</p>
	</div>

	<script>
	window.gcmLinksNonce = '<?php echo esc_js( wp_create_nonce('mm_meta_links_nonce') ); ?>';
	window.gcmLinksAjax = '<?php echo esc_js( admin_url('admin-ajax.php') ); ?>';
	</script>

</div>
