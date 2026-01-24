<?php
/**
 * Admin page template.
 *
 * @package OPcache_Preload_Generator
 * @var OPcache_Preload_Generator\Admin_Page $this
 * @var string $current_tab
 * @var array<string, string> $tabs
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}
?>
<div class="wrap opcache-preload-wrap">
	<h1><?php echo esc_html(get_admin_page_title()); ?></h1>

	<?php if (! $this->plugin->is_opcache_available()) : ?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e('OPcache is not available.', 'opcache-preload-generator'); ?></strong>
				<?php esc_html_e('Please ensure the OPcache extension is installed and enabled in your PHP configuration.', 'opcache-preload-generator'); ?>
			</p>
		</div>
	<?php endif; ?>

	<nav class="nav-tab-wrapper wp-clearfix">
		<?php foreach ($tabs as $tab_id => $tab_name) : ?>
			<a href="<?php echo esc_url($this->get_admin_url(['tab' => $tab_id])); ?>"
				class="nav-tab <?php echo $current_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
				<?php echo esc_html($tab_name); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<div class="opcache-preload-content">
		<?php
		$tab_file = $this->get_tab_content_file($current_tab);
		if (file_exists($tab_file)) {
			include $tab_file;
		}
		?>
	</div>
</div>

<div id="opcache-analysis-modal" class="opcache-modal" style="display: none;">
	<div class="opcache-modal-content">
		<span class="opcache-modal-close">&times;</span>
		<h2><?php esc_html_e('File Analysis', 'opcache-preload-generator'); ?></h2>
		<div class="opcache-modal-body">
			<!-- Content loaded via AJAX -->
		</div>
	</div>
</div>
