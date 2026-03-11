<?php
/**
 * Auto-Optimize tab template.
 *
 * @package OPcache_Preload_Generator
 * @var OPcache_Preload_Generator\Admin_Page $this
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

$state = $this->plugin->auto_optimizer->get_state();
?>

<div class="opcache-optimize-tab">
	<h2><?php esc_html_e('Auto-Optimize Preload Configuration', 'opcache-preload-generator'); ?></h2>
	<p class="description">
		<?php esc_html_e('Automatically find the optimal preload configuration by testing files one at a time. This process measures the baseline homepage load time, then adds files incrementally while monitoring for errors and performance improvements.', 'opcache-preload-generator'); ?>
	</p>

	<?php if (! $this->plugin->is_opcache_available()) : ?>
		<div class="notice notice-error inline">
			<p><?php esc_html_e('OPcache is not available. Please enable OPcache to use auto-optimization.', 'opcache-preload-generator'); ?></p>
		</div>
	<?php else : ?>

		<!-- Status Card -->
		<div class="opcache-optimize-status" id="opcache-optimize-status">
			<div class="opcache-cards">
				<div class="opcache-card">
					<h3><?php esc_html_e('Status', 'opcache-preload-generator'); ?></h3>
					<div class="opcache-stat-large" id="optimize-status-text">
						<?php
						$status_labels = [
							'idle'      => __('Ready', 'opcache-preload-generator'),
							'running'   => __('Running', 'opcache-preload-generator'),
							'paused'    => __('Paused', 'opcache-preload-generator'),
							'completed' => __('Completed', 'opcache-preload-generator'),
							'error'     => __('Error', 'opcache-preload-generator'),
						];
						echo esc_html($status_labels[ $state['status'] ] ?? $state['status']);
						?>
					</div>
					<p class="opcache-stat-label" id="optimize-phase-text">
						<?php
						if ('running' === $state['status']) {
							$phase_labels = [
								'baseline'   => __('Running baseline test...', 'opcache-preload-generator'),
								'optimizing' => __('Testing files...', 'opcache-preload-generator'),
								'complete'   => __('Optimization complete', 'opcache-preload-generator'),
							];
							echo esc_html($phase_labels[ $state['phase'] ] ?? '');
						}
						?>
					</p>
				</div>

				<div class="opcache-card">
					<h3><?php esc_html_e('Baseline Time', 'opcache-preload-generator'); ?></h3>
					<div class="opcache-stat-large" id="optimize-baseline-time">
						<?php echo esc_html($state['baseline_time'] ? $state['baseline_time'] . ' ms' : '—'); ?>
					</div>
					<p class="opcache-stat-label"><?php esc_html_e('Homepage load without preload', 'opcache-preload-generator'); ?></p>
				</div>

				<div class="opcache-card">
					<h3><?php esc_html_e('Current Best Time', 'opcache-preload-generator'); ?></h3>
					<div class="opcache-stat-large opcache-stat-success" id="optimize-best-time">
						<?php echo esc_html($state['best_time'] ? $state['best_time'] . ' ms' : '—'); ?>
					</div>
					<p class="opcache-stat-label"><?php esc_html_e('Best time achieved so far', 'opcache-preload-generator'); ?></p>
				</div>

				<div class="opcache-card <?php echo $state['time_saved_ms'] > 0 ? 'opcache-card-success' : ''; ?>">
					<h3><?php esc_html_e('Time Saved', 'opcache-preload-generator'); ?></h3>
					<div class="opcache-stat-large" id="optimize-time-saved">
						<?php
						if ($state['time_saved_ms'] > 0) {
							echo esc_html($state['time_saved_ms'] . ' ms (' . $state['time_saved_pct'] . '%)');
						} else {
							echo '—';
						}
						?>
					</div>
					<p class="opcache-stat-label"><?php esc_html_e('Reduction in load time', 'opcache-preload-generator'); ?></p>
				</div>
			</div>

			<!-- Progress -->
			<div class="opcache-optimize-progress" id="optimize-progress" <?php echo 'running' !== $state['status'] ? 'style="display:none;"' : ''; ?>>
				<h3><?php esc_html_e('Progress', 'opcache-preload-generator'); ?></h3>
				<div class="opcache-progress-stats">
					<span id="optimize-files-tested">
						<?php
						printf(
							/* translators: %d: number of files */
							esc_html__('%d files tested', 'opcache-preload-generator'),
							(int) $state['files_tested']
						);
						?>
					</span>
					<span class="opcache-badge opcache-badge-safe" id="optimize-files-added">
						<?php echo esc_html($state['files_added']); ?> <?php esc_html_e('added', 'opcache-preload-generator'); ?>
					</span>
					<span class="opcache-badge opcache-badge-error" id="optimize-files-failed">
						<?php echo esc_html($state['files_failed']); ?> <?php esc_html_e('failed', 'opcache-preload-generator'); ?>
					</span>
				</div>
				<div class="opcache-progress-bar">
					<div class="opcache-progress-fill" id="optimize-progress-bar" style="width: 0%"></div>
				</div>
				<p class="opcache-current-file" id="optimize-current-file">
					<?php if ($state['current_file']) : ?>
						<?php esc_html_e('Testing:', 'opcache-preload-generator'); ?>
						<code><?php echo esc_html($state['current_file']); ?></code>
					<?php endif; ?>
				</p>
			</div>
		</div>

		<!-- Controls -->
		<div class="opcache-optimize-controls">
			<div class="opcache-form-row">
				<label for="optimize-max-files">
					<?php esc_html_e('Files to test:', 'opcache-preload-generator'); ?>
				</label>
				<select id="optimize-max-files">
					<option value="auto" selected><?php esc_html_e('Auto (detect high-hit files)', 'opcache-preload-generator'); ?></option>
					<option value="25">25</option>
					<option value="50">50</option>
					<option value="100">100</option>
					<option value="200">200</option>
					<option value="500">500</option>
				</select>
			</div>

			<div class="opcache-button-group">
				<button type="button" id="opcache-start-optimize" class="button button-primary" <?php echo 'running' === $state['status'] ? 'disabled' : ''; ?>>
					<?php esc_html_e('Start Optimization', 'opcache-preload-generator'); ?>
				</button>
				<button type="button" id="opcache-stop-optimize" class="button" <?php echo 'running' !== $state['status'] ? 'disabled' : ''; ?>>
					<?php esc_html_e('Stop', 'opcache-preload-generator'); ?>
				</button>
				<button type="button" id="opcache-reset-optimize" class="button button-link-delete" <?php echo 'idle' === $state['status'] ? 'disabled' : ''; ?>>
					<?php esc_html_e('Reset', 'opcache-preload-generator'); ?>
				</button>
			</div>
		</div>

		<!-- Results Log -->
		<div class="opcache-optimize-log" id="optimize-log" <?php echo empty($state['test_results']) ? 'style="display:none;"' : ''; ?>>
			<h3><?php esc_html_e('Optimization Log', 'opcache-preload-generator'); ?></h3>
			<div class="opcache-log-entries" id="optimize-log-entries">
				<?php foreach ($state['test_results'] as $result) : ?>
					<div class="opcache-log-entry opcache-log-<?php echo esc_attr($result['phase']); ?>">
						<span class="opcache-log-time"><?php echo esc_html(gmdate('H:i:s', $result['timestamp'])); ?></span>
						<?php if ('baseline' === $result['phase']) : ?>
							<span class="opcache-log-message">
								<?php
								printf(
									/* translators: %s: time in milliseconds */
									esc_html__('Baseline: %s ms', 'opcache-preload-generator'),
									esc_html($result['time_ms'])
								);
								?>
							</span>
						<?php elseif ('optimize' === $result['phase']) : ?>
							<span class="opcache-log-message">
								<code><?php echo esc_html(basename($result['file'] ?? '')); ?></code>
								→ <?php echo esc_html($result['time_ms']); ?> ms
								(<?php echo esc_html($result['files']); ?> <?php esc_html_e('files', 'opcache-preload-generator'); ?>)
							</span>
						<?php elseif ('complete' === $result['phase']) : ?>
							<span class="opcache-log-message opcache-log-success">
								<?php
								printf(
									/* translators: 1: final time, 2: number of files */
									esc_html__('Complete: %1$s ms with %2$d files', 'opcache-preload-generator'),
									esc_html($result['time_ms']),
									esc_html($result['files'])
								);
								?>
							</span>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<!-- Failed Files -->
		<?php if (! empty($state['failed_files'])) : ?>
			<div class="opcache-optimize-failed">
				<h3><?php esc_html_e('Failed Files', 'opcache-preload-generator'); ?></h3>
				<p class="description"><?php esc_html_e('These files caused errors and were excluded from preloading:', 'opcache-preload-generator'); ?></p>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e('File', 'opcache-preload-generator'); ?></th>
							<th><?php esc_html_e('Error', 'opcache-preload-generator'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($state['failed_files'] as $failed) : ?>
							<tr>
								<td><code><?php echo esc_html($failed['file']); ?></code></td>
								<td><?php echo esc_html($failed['error']); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

	<?php endif; ?>
</div>
