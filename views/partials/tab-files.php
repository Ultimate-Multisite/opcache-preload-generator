<?php
/**
 * Manage Files tab template.
 *
 * @package OPcache_Preload_Generator
 * @var OPcache_Preload_Generator\Admin_Page $this
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

$list_table = new OPcache_Preload_Generator\File_List_Table($this->plugin);
$list_table->prepare_items();
?>

<div class="opcache-files-tab">
	<h2><?php esc_html_e('Manage Preload Files', 'opcache-preload-generator'); ?></h2>
	<p class="description">
		<?php esc_html_e('These files will be included in the generated preload.php file. You can add files from the "Analyze Files" tab.', 'opcache-preload-generator'); ?>
	</p>

	<!-- Add File Manually -->
	<div class="opcache-add-file-form">
		<h3><?php esc_html_e('Add File Manually', 'opcache-preload-generator'); ?></h3>
		<p class="description">
			<?php esc_html_e('Enter the full path to a PHP file to add it to the preload list.', 'opcache-preload-generator'); ?>
		</p>
		<div class="opcache-form-row">
			<input type="text" id="opcache-manual-file-path" class="regular-text"
					placeholder="<?php echo esc_attr(ABSPATH); ?>wp-includes/class-wp-query.php">
			<button type="button" id="opcache-add-manual-file" class="button">
				<?php esc_html_e('Add File', 'opcache-preload-generator'); ?>
			</button>
		</div>
		<div id="opcache-manual-add-result" class="opcache-result-message" style="display: none;"></div>
	</div>

	<!-- File List Table -->
	<form method="post" id="opcache-files-form">
		<?php
		$list_table->display();
		?>
	</form>

	<?php if (count($this->plugin->get_preload_files()) > 0) : ?>
		<div class="opcache-files-actions">
			<p>
				<a href="<?php echo esc_url($this->get_admin_url(['tab' => 'generate'])); ?>" class="button button-primary">
					<?php esc_html_e('Generate Preload File', 'opcache-preload-generator'); ?>
				</a>
			</p>
		</div>
	<?php endif; ?>
</div>
