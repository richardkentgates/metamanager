<?php
/**
 * Admin page — RSS Feed Cleanup.
 * Controls what WordPress includes in its RSS 2.0 feed output.
 *
 * @var MM_Site_Settings $settings
 */
defined( 'ABSPATH' ) || exit;
$opt = MM_META_OPT_SETTINGS;
$s   = $settings;
$f   = $s->get( 'feed', [] );

$toggles = [
	'cleanup_enabled'          => [
		'label' => 'Enable RSS Cleanup',
		'desc'  => 'Master switch. When off, all RSS cleanup is disabled and WordPress outputs its default feed unchanged.',
	],
	'remove_generator'         => [
		'label' => 'Remove Generator Tag',
		'desc'  => 'Removes <code>&lt;generator&gt;https://wordpress.org/?v=X.X.X&lt;/generator&gt;</code> from the feed channel. Recommended — avoids advertising the WordPress version to scrapers.',
	],
	'remove_comments_elements' => [
		'label' => 'Remove Comment-API Elements',
		'desc'  => 'Strips <code>&lt;wfw:commentRss&gt;</code>, <code>&lt;slash:comments&gt;</code>, and their namespace declarations from every feed item. These comment-API relics are unused by modern feed readers.',
	],
	'use_excerpt'              => [
		'label' => 'Use Excerpt Only (no full content)',
		'desc'  => 'Suppress the <code>&lt;content:encoded&gt;</code> element so feed readers receive only the excerpt. Useful if you want to drive readers to the site rather than expose the full post in the feed.',
	],
];
?>
<div class="mm-meta-panel" id="page-feed">

	<h2>RSS Feed Cleanup</h2>
	<p class="description">
		Strip WordPress clutter from your RSS 2.0 feed so it contains only the essential channel and item elements that feed readers actually need.
	</p>

	<table class="form-table gcm-form-table">
		<?php foreach ( $toggles as $key => $cfg ) : ?>
		<tr>
			<th><?php echo wp_kses_post( $cfg['label'] ); ?></th>
			<td>
				<label>
					<input type="checkbox"
						name="<?php echo esc_attr( $opt ); ?>[feed][<?php echo esc_attr( $key ); ?>]"
						value="1"
						<?php checked( ! empty( $f[ $key ] ) ); ?>>
					Enable
				</label>
				<p class="description"><?php echo wp_kses_post( $cfg['desc'] ); ?></p>
			</td>
		</tr>
		<?php endforeach; ?>

		<tr>
			<th><label for="mm-feed-title">Feed Title Override</label></th>
			<td>
				<input type="text" id="mm-feed-title"
					name="<?php echo esc_attr( $opt ); ?>[feed][feed_title]"
					value="<?php echo esc_attr( $f['feed_title'] ?? '' ); ?>"
					class="regular-text">
				<p class="description">
					Replaces the feed channel title that WordPress derives from your site title.
					Leave blank to use the default.
				</p>
			</td>
		</tr>

		<tr>
			<th><label for="mm-feed-copyright">Copyright Notice</label></th>
			<td>
				<input type="text" id="mm-feed-copyright"
					name="<?php echo esc_attr( $opt ); ?>[feed][feed_copyright]"
					value="<?php echo esc_attr( $f['feed_copyright'] ?? '' ); ?>"
					class="regular-text">
				<p class="description">
					Adds a <code>&lt;copyright&gt;</code> element to the feed channel. Example:
					<code>&copy; <?php echo esc_html( (string) gmdate( 'Y' ) ); ?> Your Site Name</code>.
					Leave blank to omit.
				</p>
			</td>
		</tr>
	</table>

</div>
