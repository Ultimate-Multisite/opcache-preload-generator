<?php
/**
 * Overview tab template.
 *
 * @package OPcache_Preload_Generator
 * @var OPcache_Preload_Generator\Admin_Page $this
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

$status        = $this->get_opcache_status();
$preload_files = $this->plugin->get_preload_files();
$settings      = $this->plugin->get_settings();
$preload_info  = null;

if (file_exists($settings['output_path'])) {
	$preload_info = $this->plugin->preload_generator->get_preload_file_info($settings['output_path']);
}
?>

<div class="opcache-overview">
	<?php if (! $status['available']) : ?>
		<div class="opcache-card opcache-card-error">
			<h2><?php esc_html_e('OPcache Not Available', 'opcache-preload-generator'); ?></h2>
			<p><?php esc_html_e('OPcache is not available on this server. Please ensure the OPcache extension is installed and enabled.', 'opcache-preload-generator'); ?></p>
		</div>
	<?php else : ?>

		<div class="opcache-cards">
			<!-- Memory Usage Card -->
			<div class="opcache-card">
				<h3><?php esc_html_e('Memory Usage', 'opcache-preload-generator'); ?></h3>
				<div class="opcache-stat-large">
					<?php echo esc_html($this->format_bytes($status['memory']['used'])); ?>
					<small>/ <?php echo esc_html($this->format_bytes($status['memory']['total'])); ?></small>
				</div>
				<div class="opcache-progress-bar">
					<div class="opcache-progress-fill" style="width: <?php echo esc_attr($status['memory']['used_pct']); ?>%"></div>
				</div>
				<p class="opcache-stat-label">
					<?php
					printf(
						/* translators: %s: percentage */
						esc_html__('%s%% used', 'opcache-preload-generator'),
						esc_html($status['memory']['used_pct'])
					);
					?>
					<?php if ($status['memory']['wasted_pct'] > 0) : ?>
						&bull;
						<?php
						printf(
							/* translators: %s: percentage */
							esc_html__('%s%% wasted', 'opcache-preload-generator'),
							esc_html($status['memory']['wasted_pct'])
						);
						?>
					<?php endif; ?>
				</p>
			</div>

			<!-- Cache Statistics Card -->
			<div class="opcache-card">
				<h3><?php esc_html_e('Cache Statistics', 'opcache-preload-generator'); ?></h3>
				<div class="opcache-stat-large">
					<?php echo esc_html(number_format($status['cache']['num_cached_scripts'])); ?>
					<small><?php esc_html_e('scripts cached', 'opcache-preload-generator'); ?></small>
				</div>
				<p class="opcache-stat-label">
					<?php
					printf(
						/* translators: %s: hit rate percentage */
						esc_html__('Hit rate: %s%%', 'opcache-preload-generator'),
						esc_html(number_format($status['cache']['hit_rate'], 1))
					);
					?>
				</p>
				<p class="opcache-stat-label">
					<?php
					printf(
						/* translators: 1: hits count, 2: misses count */
						esc_html__('Hits: %1$s | Misses: %2$s', 'opcache-preload-generator'),
						esc_html(number_format($status['cache']['hits'])),
						esc_html(number_format($status['cache']['misses']))
					);
					?>
				</p>
			</div>

			<!-- Preload Status Card -->
			<div class="opcache-card <?php echo $status['preloading_enabled'] ? 'opcache-card-success' : ''; ?>">
				<h3><?php esc_html_e('Preload Status', 'opcache-preload-generator'); ?></h3>
				<?php if ($status['preloading_enabled']) : ?>
					<div class="opcache-stat-large opcache-stat-success">
						<?php esc_html_e('Active', 'opcache-preload-generator'); ?>
					</div>
					<p class="opcache-stat-label">
						<?php esc_html_e('Preload file:', 'opcache-preload-generator'); ?>
						<code><?php echo esc_html($status['preload_file']); ?></code>
					</p>
					<?php if ($status['preload_user']) : ?>
						<p class="opcache-stat-label">
							<?php esc_html_e('Preload user:', 'opcache-preload-generator'); ?>
							<code><?php echo esc_html($status['preload_user']); ?></code>
						</p>
					<?php endif; ?>
				<?php else : ?>
					<div class="opcache-stat-large">
						<?php esc_html_e('Not Configured', 'opcache-preload-generator'); ?>
					</div>
					<p class="opcache-stat-label">
						<?php esc_html_e('Generate a preload file and configure php.ini to enable preloading.', 'opcache-preload-generator'); ?>
					</p>
				<?php endif; ?>
			</div>

			<!-- Your Preload List Card -->
			<div class="opcache-card">
				<h3><?php esc_html_e('Your Preload List', 'opcache-preload-generator'); ?></h3>
				<div class="opcache-stat-large">
					<?php echo esc_html(count($preload_files)); ?>
					<small><?php esc_html_e('files', 'opcache-preload-generator'); ?></small>
				</div>
				<?php if ($preload_info) : ?>
					<p class="opcache-stat-label">
						<?php esc_html_e('Preload file generated', 'opcache-preload-generator'); ?>
						<br>
						<small><?php echo esc_html($this->format_timestamp($preload_info['modified'])); ?></small>
					</p>
				<?php else : ?>
					<p class="opcache-stat-label">
						<?php esc_html_e('No preload file generated yet.', 'opcache-preload-generator'); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>

		<!-- Quick Actions -->
		<div class="opcache-quick-actions">
			<h3><?php esc_html_e('Quick Actions', 'opcache-preload-generator'); ?></h3>
			<p>
				<a href="<?php echo esc_url($this->get_admin_url(['tab' => 'analyze'])); ?>" class="button button-primary">
					<?php esc_html_e('Analyze & Add Files', 'opcache-preload-generator'); ?>
				</a>

				<?php if (count($preload_files) > 0) : ?>
					<a href="<?php echo esc_url($this->get_admin_url(['tab' => 'generate'])); ?>" class="button">
						<?php esc_html_e('Generate Preload File', 'opcache-preload-generator'); ?>
					</a>
				<?php endif; ?>

				<a href="<?php echo esc_url($this->get_admin_url(['tab' => 'help'])); ?>" class="button">
					<?php esc_html_e('Learn About Preloading', 'opcache-preload-generator'); ?>
				</a>
			</p>
		</div>

	<?php endif; ?>
</div>
