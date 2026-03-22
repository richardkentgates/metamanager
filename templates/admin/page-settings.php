<?php
/**
 * Admin page shell wrapper.
 *
 * Injected variables (from MM_Metadata_Admin::render()):
 *   @var MM_Metadata_Admin $this
 *   @var MM_Site_Settings   $settings
 *   @var array              $pages
 *   @var string             $current_page   current sub-page slug
 *   @var string|null        $opt_group      option group for settings_fields()
 *   @var bool               $has_inner_form template handles its own <form>
 */
defined( 'ABSPATH' ) || exit;

/** @var MM_Metadata_Admin $this */
$page_cfg = $pages[ $current_page ] ?? $pages['metamanager-business'];
$template = $page_cfg['template'];
$label    = $page_cfg['label'];
?>
<div class="wrap mm-meta-wrap">

<h1 class="mm-meta-page-title">
<?php echo esc_html( $label ); ?> <span class="mm-meta-dash">&#8212;</span> Metamanager
<span class="mm-meta-version">v<?php echo esc_html( MM_META_VERSION ); ?></span>
</h1>

<?php settings_errors(); ?>

<?php if ( $has_inner_form ) : ?>

<?php include MM_META_DIR . 'templates/admin/page-' . $template . '.php'; ?>

<?php else : ?>

<form method="post" action="options.php" class="mm-meta-settings-form">
<?php settings_fields( $opt_group ); ?>
<?php include MM_META_DIR . 'templates/admin/page-' . $template . '.php'; ?>
<?php submit_button( 'Save Settings' ); ?>
</form>

<?php endif; ?>

</div>
