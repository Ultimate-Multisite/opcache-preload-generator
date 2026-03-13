<?php
/**
 * AJAX Handler class.
 *
 * @package OPcache_Preload_Generator
 */

namespace OPcache_Preload_Generator;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

/**
 * Handles AJAX requests for the plugin.
 */
class Ajax_Handler {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct(Plugin $plugin) {

		$this->plugin = $plugin;

		add_action('wp_ajax_opcache_preload_generate', [$this, 'generate_preload']);
		add_action('wp_ajax_opcache_preload_delete', [$this, 'delete_preload']);
		add_action('wp_ajax_opcache_preload_save_settings', [$this, 'save_settings']);
		add_action('wp_ajax_opcache_preload_preview', [$this, 'preview_preload']);
		add_action('wp_ajax_opcache_preload_candidates_preview', [$this, 'candidates_preview']);
	}

	/**
	 * Verify AJAX request.
	 *
	 * @return bool
	 */
	private function verify_request(): bool {

		$capability = is_multisite() ? 'manage_network_options' : 'manage_options';

		if (! current_user_can($capability)) {
			wp_send_json_error(['message' => __('Permission denied.', 'opcache-preload-generator')]);

			return false;
		}

		if (! check_ajax_referer('opcache_preload_nonce', 'nonce', false)) {
			wp_send_json_error(['message' => __('Security check failed.', 'opcache-preload-generator')]);

			return false;
		}

		return true;
	}

	/**
	 * Get files for preloading from OPcache statistics.
	 *
	 * Automatically selects top files based on hit count.
	 *
	 * @return array<array{path: string, method: string}>
	 */
	private function get_files_for_preload(): array {

		$settings = $this->plugin->get_settings();
		$analyzer = $this->plugin->opcache_analyzer;

		// Get top files from OPcache.
		$scripts = $analyzer->get_scripts_by_hits(200); // Get top 200 files.

		// Filter to WordPress files only.
		$scripts = $analyzer->filter_wordpress_scripts($scripts);

		// Exclude patterns.
		$scripts = $analyzer->exclude_patterns($scripts, $settings['exclude_patterns']);

		$files = [];

		foreach ($scripts as $script) {
			$path = $script['full_path'] ?? '';

			if (empty($path) || ! file_exists($path)) {
				continue;
			}

			// Analyze file for safety.
			$analysis = $this->plugin->safety_analyzer->analyze_file($path);

			// Only include safe files.
			if ($analysis['safe'] && empty($analysis['errors'])) {
				$files[] = [
					'path'   => $path,
					'method' => 'opcache_compile_file',
				];
			}
		}

		return $files;
	}

	/**
	 * Generate the preload file.
	 *
	 * @return void
	 */
	public function generate_preload(): void {

		if (! $this->verify_request()) {
			return;
		}

		$settings = $this->plugin->get_settings();

		// Get files automatically from OPcache.
		$files_config = $this->get_files_for_preload();

		if (empty($files_config)) {
			wp_send_json_error(['message' => __('No suitable files found in OPcache. Please browse your site to populate the cache, then try again.', 'opcache-preload-generator')]);

			return;
		}

		// Generate file.
		$output_path = $settings['output_path'];
		$options     = [
			'validate_files' => true,
			'output_path'    => $output_path,
			'abspath'        => ABSPATH,
		];

		$result = $this->plugin->preload_generator->write_file($files_config, $output_path, $options);

		if (true !== $result) {
			wp_send_json_error(['message' => $result]);

			return;
		}

		$info = $this->plugin->preload_generator->get_preload_file_info($output_path);

		wp_send_json_success(
			[
				'message'    => __('Preload file generated successfully.', 'opcache-preload-generator'),
				'path'       => $output_path,
				'info'       => $info,
				'php_config' => $this->plugin->preload_generator->get_php_ini_config($output_path),
			]
		);
	}

	/**
	 * Delete the preload file.
	 *
	 * @return void
	 */
	public function delete_preload(): void {

		if (! $this->verify_request()) {
			return;
		}

		$settings = $this->plugin->get_settings();
		$path     = $settings['output_path'];

		$result = $this->plugin->preload_generator->delete_preload_file($path);

		if (true !== $result) {
			wp_send_json_error(['message' => $result]);

			return;
		}

		wp_send_json_success(
			[
				'message' => __('Preload file deleted successfully.', 'opcache-preload-generator'),
			]
		);
	}

	/**
	 * Save plugin settings.
	 *
	 * @return void
	 */
	public function save_settings(): void {

		if (! $this->verify_request()) {
			return;
		}

		$settings = $this->plugin->get_settings();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request()
		if (isset($_POST['output_path'])) {
			$path = sanitize_text_field(wp_unslash($_POST['output_path']));

			// Validate path is writable.
			$dir = dirname($path);
			if (is_dir($dir) && wp_is_writable($dir)) {
				$settings['output_path'] = $path;
			} else {
				wp_send_json_error(['message' => __('Output directory is not writable.', 'opcache-preload-generator')]);

				return;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->plugin->save_settings($settings);

		wp_send_json_success(
			[
				'message'  => __('Settings saved.', 'opcache-preload-generator'),
				'settings' => $settings,
			]
		);
	}

	/**
	 * Preview the preload file content.
	 *
	 * @return void
	 */
	public function preview_preload(): void {

		if (! $this->verify_request()) {
			return;
		}

		$settings = $this->plugin->get_settings();

		// Get files automatically from OPcache.
		$files_config = $this->get_files_for_preload();

		if (empty($files_config)) {
			wp_send_json_error(['message' => __('No suitable files found in OPcache.', 'opcache-preload-generator')]);

			return;
		}

		$options = [
			'validate_files' => true,
			'output_path'    => $settings['output_path'],
			'abspath'        => ABSPATH,
		];

		$content = $this->plugin->preload_generator->preview($files_config, $options);

		wp_send_json_success(
			[
				'content'    => $content,
				'file_count' => count($files_config),
			]
		);
	}

	/**
	 * Get candidate files preview based on threshold.
	 *
	 * @return void
	 */
	public function candidates_preview(): void {

		if (! $this->verify_request()) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request()
		$threshold = isset($_POST['threshold']) ? (float) $_POST['threshold'] : 0.7;
		$threshold = max(0.01, min(1.0, $threshold));

		$analyzer = $this->plugin->opcache_analyzer;
		$settings = $this->plugin->get_settings();

		// Get scripts from OPcache.
		$scripts = $analyzer->get_scripts_by_hits(500);

		// Filter to WordPress files only.
		$scripts = $analyzer->filter_wordpress_scripts($scripts);

		// Exclude patterns.
		$scripts = $analyzer->exclude_patterns($scripts, $settings['exclude_patterns']);

		if (empty($scripts)) {
			wp_send_json_success(
				[
					'total'         => 0,
					'candidates'    => [],
					'cutoff_hits'   => 0,
					'reference'     => null,
				]
			);

			return;
		}

		// Find a reference file - a WordPress core file that's loaded on every request.
		$reference_files = [
			'wp-includes/l10n.php',
			'wp-includes/option.php',
			'wp-includes/formatting.php',
			'wp-includes/class-wp.php',
			'wp-includes/class-wp-query.php',
			'wp-includes/load.php',
			'wp-includes/plugin.php',
			'wp-includes/functions.php',
		];

		$reference_hits = 0;
		$reference_file = '';

		foreach ($reference_files as $ref_suffix) {
			foreach ($scripts as $script) {
				$path = $script['full_path'] ?? '';
				if (substr($path, -strlen($ref_suffix)) === $ref_suffix) {
					$reference_hits = $script['hits'] ?? 0;
					$reference_file = $ref_suffix;
					break 2;
				}
			}
		}

		// Fallback to median if no reference file found.
		if (0 === $reference_hits) {
			$hits_array = array_column($scripts, 'hits');
			sort($hits_array);
			$median_index   = (int) floor(count($hits_array) / 2);
			$reference_hits = $hits_array[ $median_index ] ?? 0;
			$reference_file = 'median';
		}

		// Calculate cutoff based on reference file hits * threshold.
		$cutoff_hits = (int) round($reference_hits * $threshold);

		// Filter candidates above threshold.
		// Note: Skip safety analysis for preview to show all potential candidates.
		// Safety analysis is done during actual file generation.
		$candidates = [];
		foreach ($scripts as $script) {
			if (($script['hits'] ?? 0) >= $cutoff_hits) {
				$path = $script['full_path'] ?? '';

				if (empty($path) || ! file_exists($path)) {
					continue;
				}

				// Skip safety analysis for preview - just show the file.
				$candidates[] = [
					'path'      => $this->get_relative_path($path),
					'full_path' => $path,
					'hits'      => $script['hits'] ?? 0,
					'memory'    => $script['memory_consumption'] ?? 0,
				];
			}
		}

		// Get files near the cutoff (last 5 candidates around the threshold).
		$near_cutoff = array_slice($candidates, -5, 5, true);

		wp_send_json_success(
			[
				'total'       => count($candidates),
				'candidates'  => $near_cutoff,
				'cutoff_hits' => $cutoff_hits,
				'reference'   => [
					'file' => $reference_file,
					'hits' => $reference_hits,
				],
			]
		);
	}

	/**
	 * Get the relative path from ABSPATH.
	 *
	 * @param string $path Full file path.
	 * @return string
	 */
	private function get_relative_path(string $path): string {

		$abspath = realpath(ABSPATH);

		if (! $abspath) {
			return $path;
		}

		$realpath = realpath($path);

		if (! $realpath) {
			return $path;
		}

		if (0 === strpos($realpath, $abspath)) {
			return substr($realpath, strlen($abspath) + 1);
		}

		return $path;
	}
}
