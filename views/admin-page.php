<?php
/**
 * Admin page template - Context-aware single-page layout.
 *
 * @package OPcache_Preload_Generator
 * @var OPcache_Preload_Generator\Admin_Page $this
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

$status        = $this->get_opcache_status();
$settings      = $this->plugin->get_settings();
$preload_info  = null;

if (file_exists($settings['output_path'])) {
	$preload_info = $this->plugin->preload_generator->get_preload_file_info($settings['output_path']);
}

// Determine UI state.
$has_preload_file   = $preload_info !== null;
$is_preload_active  = $status['preloading_enabled'] ?? false;
$state              = $is_preload_active ? 'active' : ($has_preload_file ? 'generated' : 'new');

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

	<!-- Advanced Users Only Warning -->
	<div class="notice notice-warning">
		<p>
			<strong><?php esc_html_e('Advanced Users Only', 'opcache-preload-generator'); ?></strong> &mdash;
			<?php esc_html_e('This plugin requires server-level access to modify php.ini and restart PHP. It will NOT work on shared hosting.', 'opcache-preload-generator'); ?>
		</p>
		<p>
			<?php esc_html_e('Requirements: Dedicated server or VPS, SSH/root access, ability to restart PHP-FPM/web server.', 'opcache-preload-generator'); ?>
		</p>
	</div>

	<?php if ($status['available']) : ?>

		<!-- Step Progress Indicator (Vertical) -->
		<div class="opcache-steps-section">
			<div class="opcache-steps-vertical">
				<div class="opcache-step <?php echo $has_preload_file ? 'completed' : 'active'; ?>">
					<div class="opcache-step-header">
						<div class="opcache-step-number">1</div>
						<div class="opcache-step-title"><?php esc_html_e('Generate Preload File', 'opcache-preload-generator'); ?></div>
					</div>
					<div class="opcache-step-body">
						<p><?php esc_html_e('Select files and create your preload.php', 'opcache-preload-generator'); ?></p>
					</div>
				</div>
				<div class="opcache-step <?php echo $is_preload_active ? 'completed' : ($has_preload_file ? 'active' : ''); ?>">
					<div class="opcache-step-header">
						<div class="opcache-step-number">2</div>
						<div class="opcache-step-title"><?php esc_html_e('Configure PHP', 'opcache-preload-generator'); ?></div>
					</div>
					<div class="opcache-step-body">
						<p><?php esc_html_e('Add configuration to php.ini', 'opcache-preload-generator'); ?></p>
					</div>
				</div>
				<div class="opcache-step <?php echo $is_preload_active ? 'active' : ''; ?>">
					<div class="opcache-step-header">
						<div class="opcache-step-number">3</div>
						<div class="opcache-step-title"><?php esc_html_e('Preload Active', 'opcache-preload-generator'); ?></div>
					</div>
					<div class="opcache-step-body">
						<p><?php esc_html_e('Files are preloaded on PHP startup', 'opcache-preload-generator'); ?></p>
					</div>
				</div>
			</div>
		</div>

		<?php if ($state === 'new') : ?>
			<!-- NEW STATE: Generate Preload File (Primary) -->
			<div class="opcache-section opcache-action-primary">
				<h2><?php esc_html_e('Generate Your Preload File', 'opcache-preload-generator'); ?></h2>
				<p class="description" style="color: rgba(255,255,255,0.9);">
					<?php esc_html_e('Files are automatically selected from OPcache based on hit count.', 'opcache-preload-generator'); ?>
				</p>
			</div>

			<!-- Simple Generate Button Section -->
			<div class="opcache-section" style="text-align: center; padding: 30px;">
				<p class="description" style="margin-bottom: 20px; font-size: 14px;">
					<?php esc_html_e('Files are automatically selected based on OPcache hit statistics.', 'opcache-preload-generator'); ?>
				</p>
				<p>
					<button type="button" id="opcache-generate-btn" class="button button-primary button-hero" style="font-size: 16px; padding: 10px 30px;">
						<?php esc_html_e('Generate Preload File', 'opcache-preload-generator'); ?>
					</button>
				</p>
				<div id="opcache-generate-result" class="opcache-result-message" style="display: none;"></div>
			</div>

			<!-- Advanced Options Toggle -->
			<div class="opcache-section opcache-advanced-toggle">
				<button type="button" id="opcache-advanced-toggle-btn" class="button button-secondary">
					<span class="dashicons dashicons-admin-generic"></span>
					<?php esc_html_e('Advanced Options', 'opcache-preload-generator'); ?>
					<span class="dashicons dashicons-arrow-down-alt2" id="opcache-advanced-arrow"></span>
				</button>
			</div>

			<!-- Advanced Options Container -->
			<div id="opcache-advanced-container" style="display: none;">
				<!-- Output Path -->
				<div class="opcache-section">
					<h3><?php esc_html_e('Output Path', 'opcache-preload-generator'); ?></h3>
					<input type="text" id="opcache-output-path" class="regular-text" value="<?php echo esc_attr($settings['output_path']); ?>">
					<p class="description">
						<?php esc_html_e('Where to save the preload.php file. This path will be used in php.ini.', 'opcache-preload-generator'); ?>
					</p>
				</div>

				<!-- Threshold Slider Section -->
				<div class="opcache-section opcache-threshold-section">
					<h3><?php esc_html_e('Hit Threshold', 'opcache-preload-generator'); ?></h3>
					<p class="description">
						<?php esc_html_e('Files with hits above this percentage of the reference file will be included. Lower threshold = more files included.', 'opcache-preload-generator'); ?>
					</p>

					<div class="opcache-slider-container">
						<div class="opcache-slider-labels">
							<span><?php esc_html_e('More Files (1%)', 'opcache-preload-generator'); ?></span>
							<span><?php esc_html_e('Balanced (50%)', 'opcache-preload-generator'); ?></span>
							<span><?php esc_html_e('Fewer Files (99%)', 'opcache-preload-generator'); ?></span>
						</div>
						<div class="opcache-slider-wrapper">
							<input type="range" id="opcache-threshold-slider" min="1" max="99" value="70" step="1">
						</div>
						<div class="opcache-threshold-value">
							<?php
							printf(
								/* translators: %s: threshold percentage */
								esc_html__('Current: %s%% of reference file hits', 'opcache-preload-generator'),
								'<span id="opcache-threshold-display">70</span>'
							);
							?>
						</div>
					</div>
				</div>

				<!-- Candidate Preview Section -->
				<div class="opcache-section opcache-candidates-section">
					<h3><?php esc_html_e('Candidate Files Preview', 'opcache-preload-generator'); ?></h3>

					<div class="opcache-candidate-stats">
						<div class="opcache-candidate-count">
							<span id="opcache-candidate-count">-</span>
							<span class="opcache-candidate-count-label"><?php esc_html_e('files will be preloaded', 'opcache-preload-generator'); ?></span>
						</div>
						<div class="opcache-cutoff-info" id="opcache-cutoff-info">
							<?php esc_html_e('Loading...', 'opcache-preload-generator'); ?>
						</div>
					</div>

					<div id="opcache-candidates-table-container">
						<h4><?php esc_html_e('Files near the cutoff threshold:', 'opcache-preload-generator'); ?></h4>
						<table class="opcache-candidates-table">
							<thead>
								<tr>
									<th><?php esc_html_e('File', 'opcache-preload-generator'); ?></th>
									<th><?php esc_html_e('Hits', 'opcache-preload-generator'); ?></th>
									<th><?php esc_html_e('Memory', 'opcache-preload-generator'); ?></th>
								</tr>
							</thead>
							<tbody id="opcache-candidates-tbody">
								<tr>
									<td colspan="3" class="opcache-candidates-loading">
										<?php esc_html_e('Loading candidate files...', 'opcache-preload-generator'); ?>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</div>

		<?php elseif ($state === 'generated') : ?>
			<!-- GENERATED STATE: PHP Configuration is Primary -->
			<div class="opcache-section opcache-phpini-prominent">
				<h2><?php esc_html_e('Next Step: Configure PHP', 'opcache-preload-generator'); ?></h2>
				<p class="description" style="font-size: 14px;">
					<?php esc_html_e('Your preload file has been generated. Add these lines to your php.ini file to activate preloading:', 'opcache-preload-generator'); ?>
				</p>
				<div class="opcache-code-block" style="margin: 20px 0;">
					<pre id="opcache-phpini-config"><?php echo esc_html($this->plugin->preload_generator->get_php_ini_config($settings['output_path'])); ?></pre>
					<button type="button" class="button opcache-copy-btn" data-target="opcache-phpini-config">
						<?php esc_html_e('Copy to Clipboard', 'opcache-preload-generator'); ?>
					</button>
				</div>
				<p class="description">
					<?php esc_html_e('After updating php.ini, restart PHP-FPM or your web server for changes to take effect.', 'opcache-preload-generator'); ?>
				</p>
			</div>

			<!-- Existing File Info -->
			<div class="opcache-section opcache-existing-file">
				<h2><?php esc_html_e('Existing Preload File', 'opcache-preload-generator'); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e('Path', 'opcache-preload-generator'); ?></th>
						<td><code><?php echo esc_html($preload_info['path']); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Files included', 'opcache-preload-generator'); ?></th>
						<td><?php echo esc_html($preload_info['actual_count']); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Size', 'opcache-preload-generator'); ?></th>
						<td><?php echo esc_html($this->format_bytes($preload_info['size'])); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Last generated', 'opcache-preload-generator'); ?></th>
						<td><?php echo esc_html($this->format_timestamp($preload_info['modified'])); ?></td>
					</tr>
				</table>
			</div>

			<!-- Regenerate Section -->
			<div class="opcache-section">
				<h2><?php esc_html_e('Regenerate File', 'opcache-preload-generator'); ?></h2>
				<p>
					<button type="button" id="opcache-generate-btn" class="button button-primary">
						<?php esc_html_e('Regenerate Preload File', 'opcache-preload-generator'); ?>
					</button>
					<button type="button" id="opcache-delete-btn" class="button button-link-delete">
						<?php esc_html_e('Delete File', 'opcache-preload-generator'); ?>
					</button>
				</p>
				<div id="opcache-generate-result" class="opcache-result-message" style="display: none;"></div>
			</div>

		<?php elseif ($state === 'active') : ?>
			<!-- ACTIVE STATE: Regenerate is Primary -->
			<div class="opcache-section opcache-regenerate-section">
				<h2><?php esc_html_e('Preload is Active', 'opcache-preload-generator'); ?></h2>
				<p class="description">
					<?php esc_html_e('Your preload file is configured and running. Regenerate periodically to keep it updated with current OPcache statistics.', 'opcache-preload-generator'); ?>
				</p>
				<p style="margin-top: 20px;">
					<button type="button" id="opcache-generate-btn" class="button button-primary button-hero">
						<?php esc_html_e('Regenerate Preload File', 'opcache-preload-generator'); ?>
					</button>
				</p>
				<div id="opcache-generate-result" class="opcache-result-message" style="display: none;"></div>
			</div>

			<!-- Existing File Info -->
			<div class="opcache-section opcache-existing-file">
				<h2><?php esc_html_e('Current Preload File', 'opcache-preload-generator'); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e('Path', 'opcache-preload-generator'); ?></th>
						<td><code><?php echo esc_html($preload_info['path']); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Files included', 'opcache-preload-generator'); ?></th>
						<td><?php echo esc_html($preload_info['actual_count']); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Size', 'opcache-preload-generator'); ?></th>
						<td><?php echo esc_html($this->format_bytes($preload_info['size'])); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Last generated', 'opcache-preload-generator'); ?></th>
						<td><?php echo esc_html($this->format_timestamp($preload_info['modified'])); ?></td>
					</tr>
				</table>
			</div>

			<!-- PHP Configuration Reference -->
			<div class="opcache-section">
				<h2><?php esc_html_e('PHP Configuration', 'opcache-preload-generator'); ?></h2>
				<p class="description">
					<?php esc_html_e('Your current php.ini configuration:', 'opcache-preload-generator'); ?>
				</p>
				<div class="opcache-code-block">
					<pre id="opcache-phpini-config"><?php echo esc_html($this->plugin->preload_generator->get_php_ini_config($settings['output_path'])); ?></pre>
					<button type="button" class="button opcache-copy-btn" data-target="opcache-phpini-config">
						<?php esc_html_e('Copy to Clipboard', 'opcache-preload-generator'); ?>
					</button>
				</div>
				<p>
					<button type="button" id="opcache-delete-btn" class="button button-link-delete">
						<?php esc_html_e('Delete Preload File', 'opcache-preload-generator'); ?>
					</button>
				</p>
			</div>

		<?php endif; ?>

		<!-- Preview Section (available in all states) -->
		<div class="opcache-section">
			<h2><?php esc_html_e('Preview Generated File', 'opcache-preload-generator'); ?></h2>
			<p>
				<button type="button" id="opcache-preview-btn" class="button">
					<?php esc_html_e('Show File Contents', 'opcache-preload-generator'); ?>
				</button>
			</p>
			<div id="opcache-preview-container" style="display: none;">
				<textarea id="opcache-preview-content" class="large-text code" rows="15" readonly></textarea>
			</div>
		</div>

		<!-- Help Link -->
		<div class="opcache-section opcache-help-link">
			<p>
				<span class="dashicons dashicons-editor-help"></span>
				<a href="https://www.php.net/manual/en/opcache.preloading.php" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e('Learn more about OPcache preloading', 'opcache-preload-generator'); ?>
				</a>
			</p>
		</div>

	<?php else : ?>

		<div class="opcache-card opcache-card-error">
			<h2><?php esc_html_e('OPcache Not Available', 'opcache-preload-generator'); ?></h2>
			<p><?php esc_html_e('OPcache is not available on this server. Please ensure the OPcache extension is installed and enabled.', 'opcache-preload-generator'); ?></p>
		</div>

	<?php endif; ?>
</div>
