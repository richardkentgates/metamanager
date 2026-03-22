<?php
/**
 * Admin page — Head Hygiene.
 * Controls removal of WordPress-generated <head> clutter.
 *
 * @var MM_Site_Settings $settings
 */
defined( 'ABSPATH' ) || exit;
$opt = MM_META_OPT_SETTINGS;
$s   = $settings;
$h   = $s->get( 'hygiene', [] );

$toggles = [
	'remove_generator'       => [
		'label' => 'WordPress Generator Tag',
		'desc'  => 'Removes <code>&lt;meta name="generator" content="WordPress X.X.X"&gt;</code>. Recommended — reduces version fingerprinting.',
	],
	'remove_oembed_links'    => [
		'label' => 'oEmbed Discovery Links',
		'desc'  => 'Removes <code>&lt;link rel="alternate" type="application/json+oembed"&gt;</code> tags added by WordPress.',
	],
	'remove_shortlink'       => [
		'label' => 'Shortlink Tag',
		'desc'  => 'Removes <code>&lt;link rel="shortlink"&gt;</code>.',
	],
	'remove_wlw_manifest'    => [
		'label' => 'Windows Live Writer Manifest',
		'desc'  => 'Removes <code>&lt;link rel="wlwmanifest"&gt;</code>. Safe to remove unless you use WLW.',
	],
	'remove_rsd_link'        => [
		'label' => 'RSD (Really Simple Discovery) Link',
		'desc'  => 'Removes <code>&lt;link rel="EditURI"&gt;</code>.',
	],
	'remove_pingback_header' => [
		'label' => 'X-Pingback HTTP Header',
		'desc'  => 'Removes the <code>X-Pingback</code> server header.',
	],
	'remove_x_powered_by'   => [
		'label' => 'X-Powered-By HTTP Header',
		'desc'  => 'Removes <code>X-Powered-By: PHP</code> from server responses.',
	],
	'remove_wp_dns_prefetch' => [
		'label' => 'WordPress DNS Prefetch <link>',
		'desc'  => 'Removes the WordPress-added <code>dns-prefetch</code> hint for s.w.org.',
	],
];
?>
<div class="mm-meta-panel" id="page-hygiene">

	<h2>Head Hygiene</h2>
	<p class="description">
		Remove unnecessary tags from your site's <code>&lt;head&gt;</code> and HTTP headers. Each option is safe to enable for most sites.
	</p>

	<table class="form-table gcm-form-table">
		<?php foreach ( $toggles as $key => $cfg ) : ?>
		<tr>
			<th><?php echo wp_kses_post( $cfg['label'] ); ?></th>
			<td>
				<label>
					<input type="checkbox"
						name="<?php echo esc_attr( $opt ); ?>[hygiene][<?php echo esc_attr( $key ); ?>]"
						value="1"
						<?php checked( ! empty( $h[ $key ] ) ); ?>>
					Remove
				</label>
				<p class="description"><?php echo wp_kses_post( $cfg['desc'] ); ?></p>
			</td>
		</tr>
		<?php endforeach; ?>
	</table>

</div>
