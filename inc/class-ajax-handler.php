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

		add_action('wp_ajax_opcache_preload_analyze_file', [$this, 'analyze_file']);
		add_action('wp_ajax_opcache_preload_analyze_suggested', [$this, 'analyze_suggested']);
		add_action('wp_ajax_opcache_preload_add_file', [$this, 'add_file']);
		add_action('wp_ajax_opcache_preload_remove_file', [$this, 'remove_file']);
		add_action('wp_ajax_opcache_preload_update_file_method', [$this, 'update_file_method']);
		add_action('wp_ajax_opcache_preload_generate', [$this, 'generate_preload']);
		add_action('wp_ajax_opcache_preload_delete', [$this, 'delete_preload']);
		add_action('wp_ajax_opcache_preload_save_settings', [$this, 'save_settings']);
		add_action('wp_ajax_opcache_preload_preview', [$this, 'preview_preload']);

		// Auto-optimization AJAX handlers.
		add_action('wp_ajax_opcache_preload_start_optimize', [$this, 'start_optimize']);
		add_action('wp_ajax_opcache_preload_run_baseline', [$this, 'run_baseline']);
		add_action('wp_ajax_opcache_preload_process_next', [$this, 'process_next']);
		add_action('wp_ajax_opcache_preload_stop_optimize', [$this, 'stop_optimize']);
		add_action('wp_ajax_opcache_preload_get_optimize_state', [$this, 'get_optimize_state']);
		add_action('wp_ajax_opcache_preload_reset_optimize', [$this, 'reset_optimize']);
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
	 * Analyze a single file.
	 *
	 * @return void
	 */
	public function analyze_file(): void {

		if (! $this->verify_request()) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request()
		$file = isset($_POST['file']) ? sanitize_text_field(wp_unslash($_POST['file'])) : '';

		if (empty($file)) {
			wp_send_json_error(['message' => __('No file specified.', 'opcache-preload-generator')]);

			return;
		}

		// Validate file path - must be within ABSPATH.
		$realpath = realpath($file);
		$abspath  = realpath(ABSPATH);

		if (! $realpath || ! $abspath || 0 !== strpos($realpath, $abspath)) {
			wp_send_json_error(['message' => __('Invalid file path.', 'opcache-preload-generator')]);

			return;
		}

		$result             = $this->plugin->safety_analyzer->analyze_file($realpath);
		$result['file']     = $realpath;
		$result['relative'] = $this->get_relative_path($realpath);
		$result['in_list']  = in_array($realpath, $this->plugin->get_preload_files(), true);

		wp_send_json_success($result);
	}

	/**
	 * Analyze suggested files from OPcache.
	 *
	 * @return void
	 */
	public function analyze_suggested(): void {

		if (! $this->verify_request()) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request()
		$limit = isset($_POST['limit']) ? absint($_POST['limit']) : 50;
		$limit = min($limit, 10000); // Cap at 10000.

		$analyzer = $this->plugin->opcache_analyzer;
		$settings = $this->plugin->get_settings();

		// Debug: Check OPcache status.
		$status = $analyzer->get_status(true);
		$debug  = [
			'opcache_available'  => $analyzer->is_available(),
			'status_returned'    => false !== $status,
			'scripts_key_exists' => is_array($status) && isset($status['scripts']),
			'scripts_count'      => is_array($status) && isset($status['scripts']) ? count($status['scripts']) : 0,
		];

		// Check restrict_api setting.
		$config = $analyzer->get_configuration();
		if ($config && isset($config['directives']['opcache.restrict_api'])) {
			$debug['restrict_api'] = $config['directives']['opcache.restrict_api'];
		}

		// Debug: Check ABSPATH and sample script paths.
		$debug['abspath']          = ABSPATH;
		$debug['abspath_realpath'] = realpath(ABSPATH);
		$abspath_real              = realpath(ABSPATH);
		if (is_array($status) && isset($status['scripts']) && ! empty($status['scripts'])) {
			$matching_count  = 0;
			$matching_sample = [];
			$other_sample    = [];

			foreach (array_keys($status['scripts']) as $path) {
				$realpath = realpath($path);
				if ($realpath && $abspath_real && 0 === strpos($realpath, $abspath_real)) {
					++$matching_count;
					if (count($matching_sample) < 5) {
						$matching_sample[] = $path;
					}
				} elseif (count($other_sample) < 3) {
					$other_sample[] = $path;
				}
			}

			$debug['matching_scripts_count'] = $matching_count;
			$debug['matching_sample_paths']  = $matching_sample;
			$debug['non_matching_sample']    = $other_sample;
		}

		// Get scripts by hit count.
		$scripts = $analyzer->get_scripts_by_hits($limit * 2);

		// Filter to WordPress files only.
		$scripts = $analyzer->filter_wordpress_scripts($scripts);

		// Exclude patterns.
		$scripts = $analyzer->exclude_patterns($scripts, $settings['exclude_patterns']);

		// Limit results.
		$scripts = array_slice($scripts, 0, $limit);

		$results       = [];
		$current_files = $this->plugin->get_preload_files();

		foreach ($scripts as $script) {
			$path = $script['full_path'];

			$analysis             = $this->plugin->safety_analyzer->analyze_file($path);
			$analysis['file']     = $path;
			$analysis['relative'] = $this->get_relative_path($path);
			$analysis['hits']     = $script['hits'] ?? 0;
			$analysis['memory']   = $script['memory_consumption'] ?? 0;
			$analysis['in_list']  = in_array($path, $current_files, true);

			$results[] = $analysis;
		}

		wp_send_json_success(
			[
				'files' => $results,
				'total' => count($results),
				'debug' => $debug,
			]
		);
	}

	/**
	 * Add a file to the preload list.
	 *
	 * @return void
	 */
	public function add_file(): void {

		if (! $this->verify_request()) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request()
		$file = isset($_POST['file']) ? sanitize_text_field(wp_unslash($_POST['file'])) : '';

		if (empty($file)) {
			wp_send_json_error(['message' => __('No file specified.', 'opcache-preload-generator')]);

			return;
		}

		// Validate file path.
		$realpath = realpath($file);
		$abspath  = realpath(ABSPATH);

		if (! $realpath || ! $abspath || 0 !== strpos($realpath, $abspath)) {
			wp_send_json_error(['message' => __('Invalid file path.', 'opcache-preload-generator')]);

			return;
		}

		// Check file extension.
		$ext = pathinfo($realpath, PATHINFO_EXTENSION);
		if ('php' !== strtolower($ext)) {
			wp_send_json_error(['message' => __('Only PHP files can be preloaded.', 'opcache-preload-generator')]);

			return;
		}

		// Add to list.
		$files = $this->plugin->get_preload_files();

		if (in_array($realpath, $files, true)) {
			wp_send_json_error(['message' => __('File is already in the preload list.', 'opcache-preload-generator')]);

			return;
		}

		$files[] = $realpath;
		$this->plugin->save_preload_files($files);

		wp_send_json_success(
			[
				'message' => __('File added to preload list.', 'opcache-preload-generator'),
				'file'    => $realpath,
				'count'   => count($files),
			]
		);
	}

	/**
	 * Remove a file from the preload list.
	 *
	 * @return void
	 */
	public function remove_file(): void {

		if (! $this->verify_request()) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request()
		$file = isset($_POST['file']) ? sanitize_text_field(wp_unslash($_POST['file'])) : '';

		if (empty($file)) {
			wp_send_json_error(['message' => __('No file specified.', 'opcache-preload-generator')]);

			return;
		}

		$files = $this->plugin->get_preload_files();
		$key   = array_search($file, $files, true);

		if (false === $key) {
			wp_send_json_error(['message' => __('File is not in the preload list.', 'opcache-preload-generator')]);

			return;
		}

		unset($files[ $key ]);
		$this->plugin->save_preload_files($files);

		wp_send_json_success(
			[
				'message' => __('File removed from preload list.', 'opcache-preload-generator'),
				'file'    => $file,
				'count'   => count($files),
			]
		);
	}

	/**
	 * Update the method for a file.
	 *
	 * @return void
	 */
	public function update_file_method(): void {

		if (! $this->verify_request()) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request()
		$file   = isset($_POST['file']) ? sanitize_text_field(wp_unslash($_POST['file'])) : '';
		$method = isset($_POST['method']) ? sanitize_text_field(wp_unslash($_POST['method'])) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if (empty($file)) {
			wp_send_json_error(['message' => __('No file specified.', 'opcache-preload-generator')]);

			return;
		}

		if (! in_array($method, ['require_once', 'opcache_compile_file'], true)) {
			wp_send_json_error(['message' => __('Invalid method specified.', 'opcache-preload-generator')]);

			return;
		}

		$result = $this->plugin->update_file_method($file, $method);

		if (! $result) {
			wp_send_json_error(['message' => __('File not found in preload list.', 'opcache-preload-generator')]);

			return;
		}

		wp_send_json_success(
			[
				'message' => __('File method updated.', 'opcache-preload-generator'),
				'file'    => $file,
				'method'  => $method,
			]
		);
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

		$files_config = $this->plugin->get_preload_files_config();
		$files        = $this->plugin->get_preload_files();
		$settings     = $this->plugin->get_settings();

		if (empty($files)) {
			wp_send_json_error(['message' => __('No files in preload list.', 'opcache-preload-generator')]);

			return;
		}

		// Validate files.
		$validation = $this->plugin->preload_generator->validate_files($files);

		if (! empty($validation['invalid'])) {
			$invalid_count = count($validation['invalid']);
			wp_send_json_error(
				[
					'message' => sprintf(
						/* translators: %d: number of invalid files */
						_n(
							'%d file is missing or invalid. Please remove it from the list first.',
							'%d files are missing or invalid. Please remove them from the list first.',
							$invalid_count,
							'opcache-preload-generator'
						),
						$invalid_count
					),
					'invalid' => $validation['invalid'],
				]
			);

			return;
		}

		// Generate file with safety options.
		$output_path = $settings['output_path'];
		$options     = [
			'use_require'    => $settings['use_require'],
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
		if (isset($_POST['use_require'])) {
			$settings['use_require'] = (bool) $_POST['use_require'];
		}

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

		if (isset($_POST['auto_suggest_top'])) {
			$settings['auto_suggest_top'] = min(10000, max(10, absint($_POST['auto_suggest_top'])));
		}

		if (isset($_POST['exclude_patterns'])) {
			$patterns                     = sanitize_textarea_field(wp_unslash($_POST['exclude_patterns']));
			$settings['exclude_patterns'] = array_filter(array_map('trim', explode("\n", $patterns)));
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

		$files_config = $this->plugin->get_preload_files_config();
		$settings     = $this->plugin->get_settings();

		if (empty($files_config)) {
			wp_send_json_error(['message' => __('No files in preload list.', 'opcache-preload-generator')]);

			return;
		}

		$options = [
			'use_require'    => $settings['use_require'],
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
	 * Start the auto-optimization process.
	 *
	 * @return void
	 */
	public function start_optimize(): void {

		if (! $this->verify_request()) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request()
		$max_files = isset($_POST['max_files']) ? absint($_POST['max_files']) : 100;
		$max_files = min($max_files, 500); // Cap at 500 for safety.

		$result = $this->plugin->auto_optimizer->start($max_files);

		if ($result['success']) {
			wp_send_json_success($result);
		} else {
			wp_send_json_error($result);
		}
	}

	/**
	 * Run the baseline test.
	 *
	 * @return void
	 */
	public function run_baseline(): void {

		if (! $this->verify_request()) {
			return;
		}

		$result = $this->plugin->auto_optimizer->run_baseline_test();

		if ($result['success']) {
			wp_send_json_success($result);
		} else {
			wp_send_json_error($result);
		}
	}

	/**
	 * Process the next file in the optimization queue.
	 *
	 * @return void
	 */
	public function process_next(): void {

		if (! $this->verify_request()) {
			return;
		}

		$result = $this->plugin->auto_optimizer->process_next_file();

		if ($result['success']) {
			wp_send_json_success($result);
		} else {
			wp_send_json_error($result);
		}
	}

	/**
	 * Stop the optimization process.
	 *
	 * @return void
	 */
	public function stop_optimize(): void {

		if (! $this->verify_request()) {
			return;
		}

		$result = $this->plugin->auto_optimizer->stop();

		if ($result['success']) {
			wp_send_json_success($result);
		} else {
			wp_send_json_error($result);
		}
	}

	/**
	 * Get the current optimization state.
	 *
	 * @return void
	 */
	public function get_optimize_state(): void {

		if (! $this->verify_request()) {
			return;
		}

		$state = $this->plugin->auto_optimizer->get_state();

		wp_send_json_success(['state' => $state]);
	}

	/**
	 * Reset the optimization state.
	 *
	 * @return void
	 */
	public function reset_optimize(): void {

		if (! $this->verify_request()) {
			return;
		}

		// Clean up test file.
		$this->plugin->preload_tester->delete_test_file();

		// Reset state.
		$this->plugin->auto_optimizer->reset_state();

		wp_send_json_success(['message' => __('Optimization state reset.', 'opcache-preload-generator')]);
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
