<?php
/**
 * Generate tab template.
 *
 * @package OPcache_Preload_Generator
 * @var OPcache_Preload_Generator\Admin_Page $this
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

$settings      = $this->plugin->get_settings();
$preload_files = $this->plugin->get_preload_files();
$preload_info  = null;

if (file_exists($settings['output_path'])) {
	$preload_info = $this->plugin->preload_generator->get_preload_file_info($settings['output_path']);
}
?>

<div class="opcache-generate-tab">
	<h2><?php esc_html_e('Generate Preload File', 'opcache-preload-generator'); ?></h2>

	<?php if (count($preload_files) === 0) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php esc_html_e('No files in your preload list. Add some files first from the "Analyze Files" tab.', 'opcache-preload-generator'); ?>
				<a href="<?php echo esc_url($this->get_admin_url(['tab' => 'analyze'])); ?>">
					<?php esc_html_e('Go to Analyze Files', 'opcache-preload-generator'); ?>
				</a>
			</p>
		</div>
	<?php else : ?>

		<!-- Current Status -->
		<?php if ($preload_info) : ?>
			<div class="opcache-existing-file">
				<h3><?php esc_html_e('Existing Preload File', 'opcache-preload-generator'); ?></h3>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e('Path', 'opcache-preload-generator'); ?></th>
						<td><code><?php echo esc_html($preload_info['path']); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Generated', 'opcache-preload-generator'); ?></th>
						<td><?php echo esc_html($preload_info['generated'] ?? __('Unknown', 'opcache-preload-generator')); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Files included', 'opcache-preload-generator'); ?></th>
						<td><?php echo esc_html($preload_info['actual_count']); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Size', 'opcache-preload-generator'); ?></th>
						<td><?php echo esc_html($this->format_bytes($preload_info['size'])); ?></td>
					</tr>
				</table>
			</div>
		<?php endif; ?>

		<!-- Settings -->
		<div class="opcache-generate-settings">
			<h3><?php esc_html_e('Generation Settings', 'opcache-preload-generator'); ?></h3>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="opcache-output-path"><?php esc_html_e('Output Path', 'opcache-preload-generator'); ?></label>
					</th>
					<td>
						<input type="text" id="opcache-output-path" class="regular-text"
								value="<?php echo esc_attr($settings['output_path']); ?>">
						<p class="description">
							<?php esc_html_e('Where to save the preload.php file. This path will be used in php.ini.', 'opcache-preload-generator'); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e('Include Method', 'opcache-preload-generator'); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="radio" name="opcache-use-require" value="1"
										<?php checked($settings['use_require'], true); ?>>
								<code>require_once</code>
								<span class="description"><?php esc_html_e('(Recommended) - Automatically resolves autoloader dependencies', 'opcache-preload-generator'); ?></span>
							</label>
							<br>
							<label>
								<input type="radio" name="opcache-use-require" value="0"
										<?php checked($settings['use_require'], false); ?>>
								<code>opcache_compile_file</code>
								<span class="description"><?php esc_html_e('- Compiles without executing, but requires manual dependency ordering', 'opcache-preload-generator'); ?></span>
							</label>
						</fieldset>
					</td>
				</tr>
			</table>
		</div>

		<!-- Preview -->
		<div class="opcache-preview-section">
			<h3><?php esc_html_e('Preview', 'opcache-preload-generator'); ?></h3>
			<p>
				<button type="button" id="opcache-preview-btn" class="button">
					<?php esc_html_e('Preview Generated File', 'opcache-preload-generator'); ?>
				</button>
			</p>
			<div id="opcache-preview-container" style="display: none;">
				<textarea id="opcache-preview-content" class="large-text code" rows="15" readonly></textarea>
			</div>
		</div>

		<!-- Generate Actions -->
		<div class="opcache-generate-actions">
			<h3><?php esc_html_e('Generate', 'opcache-preload-generator'); ?></h3>
			<p class="description">
				<?php
				$file_count = count($preload_files);
				printf(
					esc_html(
						/* translators: %d: number of files */
						_n(
							'This will generate a preload file with %d file.',
							'This will generate a preload file with %d files.',
							$file_count,
							'opcache-preload-generator'
						)
					),
					(int) $file_count
				);
				?>
			</p>
			<p>
				<button type="button" id="opcache-generate-btn" class="button button-primary button-hero">
					<?php esc_html_e('Generate Preload File', 'opcache-preload-generator'); ?>
				</button>

				<?php if ($preload_info) : ?>
					<button type="button" id="opcache-delete-btn" class="button button-link-delete">
						<?php esc_html_e('Delete Existing File', 'opcache-preload-generator'); ?>
					</button>
				<?php endif; ?>
			</p>
			<div id="opcache-generate-result" class="opcache-result-message" style="display: none;"></div>
		</div>

		<!-- PHP.ini Configuration -->
		<div class="opcache-phpini-section" id="opcache-phpini-section" <?php echo $preload_info ? '' : 'style="display: none;"'; ?>>
			<h3><?php esc_html_e('PHP Configuration', 'opcache-preload-generator'); ?></h3>
			<p class="description">
				<?php esc_html_e('Add these lines to your php.ini file to enable preloading:', 'opcache-preload-generator'); ?>
			</p>
			<div class="opcache-code-block">
				<pre id="opcache-phpini-config"><?php echo esc_html($this->plugin->preload_generator->get_php_ini_config($settings['output_path'])); ?></pre>
				<button type="button" class="button opcache-copy-btn" data-target="opcache-phpini-config">
					<?php esc_html_e('Copy to Clipboard', 'opcache-preload-generator'); ?>
				</button>
			</div>
			<p class="description">
				<?php esc_html_e('After updating php.ini, restart PHP-FPM or your web server for changes to take effect.', 'opcache-preload-generator'); ?>
			</p>
		</div>

	<?php endif; ?>
</div>
