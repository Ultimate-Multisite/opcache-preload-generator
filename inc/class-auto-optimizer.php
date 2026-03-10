<?php
/**
 * Auto Optimizer class.
 *
 * Handles automated optimization of preload configuration.
 *
 * @package OPcache_Preload_Generator
 */

namespace OPcache_Preload_Generator;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

/**
 * Manages automated preload optimization process.
 */
class Auto_Optimizer {

	/**
	 * Option name for optimization state.
	 *
	 * @var string
	 */
	const STATE_OPTION = 'opcache_preload_optimizer_state';

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Preload tester instance.
	 *
	 * @var Preload_Tester
	 */
	private Preload_Tester $tester;

	/**
	 * Constructor.
	 *
	 * @param Plugin         $plugin Plugin instance.
	 * @param Preload_Tester $tester Preload tester instance.
	 */
	public function __construct(Plugin $plugin, Preload_Tester $tester) {

		$this->plugin = $plugin;
		$this->tester = $tester;
	}

	/**
	 * Get the current optimization state.
	 *
	 * @return array
	 */
	public function get_state(): array {

		$default = [
			'status'           => 'idle', // idle, running, paused, completed, error.
			'phase'            => '', // baseline, optimizing, complete.
			'test_token'       => '',
			'baseline_time'    => 0,
			'current_time'     => 0,
			'best_time'        => 0,
			'files_tested'     => 0,
			'files_added'      => 0,
			'files_failed'     => 0,
			'failed_files'     => [],
			'candidate_files'  => [],
			'total_candidates' => 0, // Total candidates at start (for progress calculation).
			'current_file'     => '',
			'time_saved_ms'    => 0,
			'time_saved_pct'   => 0,
			'last_error'       => '',
			'started_at'       => 0,
			'updated_at'       => 0,
			'test_results'     => [],
		];

		$state = get_option(self::STATE_OPTION, $default);

		return wp_parse_args($state, $default);
	}

	/**
	 * Save the optimization state.
	 *
	 * @param array $state State to save.
	 * @return bool
	 */
	public function save_state(array $state): bool {

		$state['updated_at'] = time();

		return update_option(self::STATE_OPTION, $state, false);
	}

	/**
	 * Reset the optimization state.
	 *
	 * @return bool
	 */
	public function reset_state(): bool {

		return delete_option(self::STATE_OPTION);
	}

	/**
	 * Start the optimization process.
	 *
	 * @param int $max_files Maximum number of files to test.
	 * @return array Result with state.
	 */
	public function start(int $max_files = 100): array {

		// Get safe candidate files from OPcache.
		$candidates = $this->get_candidate_files($max_files);

		if (empty($candidates)) {
			return [
				'success' => false,
				'error'   => __('No candidate files found. Make sure OPcache has cached some files.', 'opcache-preload-generator'),
			];
		}

		// Generate test token.
		$token = $this->tester->generate_test_token();

		// Generate test file.
		$settings = $this->plugin->get_settings();
		$result   = $this->tester->generate_test_file($settings['output_path']);

		if (true !== $result) {
			return [
				'success' => false,
				'error'   => $result,
			];
		}

		// Clear existing preload files for baseline test.
		$existing_files = $this->plugin->get_preload_files();

		// Store total candidates count for progress calculation.
		$total_candidates = count($candidates);

		// Initialize state.
		$state = [
			'status'           => 'running',
			'phase'            => 'baseline',
			'test_token'       => $token,
			'baseline_time'    => 0,
			'current_time'     => 0,
			'best_time'        => 0,
			'files_tested'     => 0,
			'files_added'      => 0,
			'files_failed'     => 0,
			'failed_files'     => [],
			'candidate_files'  => $candidates,
			'total_candidates' => $total_candidates,
			'current_file'     => '',
			'time_saved_ms'    => 0,
			'time_saved_pct'   => 0,
			'last_error'       => '',
			'started_at'       => time(),
			'updated_at'       => time(),
			'test_results'     => [],
			'original_files'   => $existing_files,
		];

		$this->save_state($state);

		// Create the token file for the test script to read.
		// This is created once at start and persists until optimization ends.
		$token_file = ABSPATH . '.opcache_test_token';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents($token_file, $token);

		// Also store in transient as backup.
		set_transient('opcache_test_token', $token, HOUR_IN_SECONDS);

		return [
			'success'          => true,
			'state'            => $state,
			'candidates_count' => $total_candidates,
		];
	}

	/**
	 * Run the baseline test.
	 *
	 * @return array Result.
	 */
	public function run_baseline_test(): array {

		$state = $this->get_state();

		if ('running' !== $state['status'] || 'baseline' !== $state['phase']) {
			return [
				'success' => false,
				'error'   => __('Invalid state for baseline test.', 'opcache-preload-generator'),
			];
		}

		// Clear preload files for baseline.
		$this->plugin->save_preload_files([]);

		// Regenerate empty preload file.
		$settings = $this->plugin->get_settings();
		$this->plugin->preload_generator->write_file(
			[],
			$settings['output_path'],
			[
				'use_require'    => $settings['use_require'],
				'validate_files' => false,
				'output_path'    => $settings['output_path'],
				'abspath'        => ABSPATH,
			]
		);

		// Run multiple tests and average.
		$times      = [];
		$test_count = 3;

		for ($i = 0; $i < $test_count; $i++) {
			// Small delay between tests.
			if ($i > 0) {
				usleep(100000); // 100ms.
			}

			$result = $this->run_test_with_token($state['test_token']);

			if (! $result['success']) {
				$state['status']     = 'error';
				$state['last_error'] = $result['error'] ?? 'Baseline test failed';
				$this->save_state($state);

				return [
					'success' => false,
					'error'   => $state['last_error'],
					'result'  => $result,
				];
			}

			$times[] = $result['time_ms'];
		}

		// Calculate average baseline time.
		$baseline_time = array_sum($times) / count($times);

		// Update state.
		$state['baseline_time']  = round($baseline_time, 2);
		$state['current_time']   = $state['baseline_time'];
		$state['best_time']      = $state['baseline_time'];
		$state['phase']          = 'optimizing';
		$state['test_results'][] = [
			'phase'     => 'baseline',
			'time_ms'   => $state['baseline_time'],
			'files'     => 0,
			'timestamp' => time(),
		];

		$this->save_state($state);

		return [
			'success'       => true,
			'baseline_time' => $state['baseline_time'],
			'state'         => $state,
		];
	}

	/**
	 * Process the next file in the optimization queue.
	 *
	 * @return array Result.
	 */
	public function process_next_file(): array {

		$state = $this->get_state();

		if ('running' !== $state['status'] || 'optimizing' !== $state['phase']) {
			return [
				'success' => false,
				'error'   => __('Invalid state for optimization.', 'opcache-preload-generator'),
			];
		}

		// Check if we have more candidates.
		if (empty($state['candidate_files'])) {
			return $this->complete_optimization();
		}

		// Get next candidate.
		$candidate             = array_shift($state['candidate_files']);
		$state['current_file'] = $candidate['file'];
		++$state['files_tested'];

		$this->save_state($state);

		// Add file to preload list.
		$current_files   = $this->plugin->get_preload_files();
		$current_files[] = $candidate['file'];
		$this->plugin->save_preload_files($current_files);

		// Regenerate preload file.
		$settings     = $this->plugin->get_settings();
		$files_config = $this->plugin->get_preload_files_config();

		$this->plugin->preload_generator->write_file(
			$files_config,
			$settings['output_path'],
			[
				'use_require'    => $settings['use_require'],
				'validate_files' => true,
				'output_path'    => $settings['output_path'],
				'abspath'        => ABSPATH,
			]
		);

		// Run test.
		$result = $this->run_test_with_token($state['test_token']);

		if (! $result['success']) {
			// File caused an error - remove it and mark as failed.
			$current_files = array_diff($current_files, [$candidate['file']]);
			$this->plugin->save_preload_files($current_files);

			// Regenerate without the failed file.
			$files_config = $this->plugin->get_preload_files_config();
			$this->plugin->preload_generator->write_file(
				$files_config,
				$settings['output_path'],
				[
					'use_require'    => $settings['use_require'],
					'validate_files' => true,
					'output_path'    => $settings['output_path'],
					'abspath'        => ABSPATH,
				]
			);

			$state = $this->get_state();
			++$state['files_failed'];
			$state['failed_files'][] = [
				'file'  => $candidate['file'],
				'error' => $result['error'] ?? 'Unknown error',
				'code'  => $result['code'] ?? 'unknown',
			];
			$state['current_file']   = '';

			$this->save_state($state);

			return [
				'success'     => true,
				'file_status' => 'failed',
				'file'        => $candidate['file'],
				'error'       => $result['error'],
				'state'       => $state,
			];
		}

		// File worked - update times.
		$state = $this->get_state();
		++$state['files_added'];
		$state['current_time'] = $result['time_ms'];
		$state['current_file'] = '';

		if ($result['time_ms'] < $state['best_time']) {
			$state['best_time'] = $result['time_ms'];
		}

		// Calculate time saved.
		$state['time_saved_ms']  = round($state['baseline_time'] - $state['best_time'], 2);
		$state['time_saved_pct'] = $state['baseline_time'] > 0
			? round(($state['time_saved_ms'] / $state['baseline_time']) * 100, 1)
			: 0;

		$state['test_results'][] = [
			'phase'     => 'optimize',
			'file'      => $candidate['file'],
			'time_ms'   => $result['time_ms'],
			'files'     => $state['files_added'],
			'timestamp' => time(),
		];

		$this->save_state($state);

		return [
			'success'     => true,
			'file_status' => 'added',
			'file'        => $candidate['file'],
			'time_ms'     => $result['time_ms'],
			'state'       => $state,
		];
	}

	/**
	 * Complete the optimization process.
	 *
	 * @return array Result.
	 */
	public function complete_optimization(): array {

		$state = $this->get_state();

		$state['status']       = 'completed';
		$state['phase']        = 'complete';
		$state['current_file'] = '';

		// Final time calculations.
		$state['time_saved_ms']  = round($state['baseline_time'] - $state['best_time'], 2);
		$state['time_saved_pct'] = $state['baseline_time'] > 0
			? round(($state['time_saved_ms'] / $state['baseline_time']) * 100, 1)
			: 0;

		$state['test_results'][] = [
			'phase'     => 'complete',
			'time_ms'   => $state['best_time'],
			'files'     => $state['files_added'],
			'timestamp' => time(),
		];

		$this->save_state($state);

		// Clean up test file and token.
		$this->tester->delete_test_file();
		$this->cleanup_token_file();
		delete_transient('opcache_test_token');

		return [
			'success'        => true,
			'completed'      => true,
			'files_added'    => $state['files_added'],
			'files_failed'   => $state['files_failed'],
			'baseline_time'  => $state['baseline_time'],
			'final_time'     => $state['best_time'],
			'time_saved_ms'  => $state['time_saved_ms'],
			'time_saved_pct' => $state['time_saved_pct'],
			'state'          => $state,
		];
	}

	/**
	 * Stop the optimization process.
	 *
	 * @return array Result.
	 */
	public function stop(): array {

		$state = $this->get_state();

		$state['status'] = 'paused';

		$this->save_state($state);

		// Clean up test file and token.
		$this->tester->delete_test_file();
		$this->cleanup_token_file();
		delete_transient('opcache_test_token');

		return [
			'success' => true,
			'state'   => $state,
		];
	}

	/**
	 * Get candidate files for optimization.
	 *
	 * @param int $max_files Maximum number of files.
	 * @return array
	 */
	private function get_candidate_files(int $max_files): array {

		$analyzer = $this->plugin->opcache_analyzer;
		$settings = $this->plugin->get_settings();

		// Get scripts by hit count.
		$scripts = $analyzer->get_scripts_by_hits($max_files * 2);

		// Filter to WordPress files only.
		$scripts = $analyzer->filter_wordpress_scripts($scripts);

		// Exclude patterns.
		$scripts = $analyzer->exclude_patterns($scripts, $settings['exclude_patterns']);

		// Get current preload files.
		$current_files = $this->plugin->get_preload_files();
		$failed_files  = $this->get_state()['failed_files'] ?? [];
		$failed_paths  = array_column($failed_files, 'file');

		// Filter and analyze for safety.
		$candidates = [];

		foreach ($scripts as $script) {
			$path = $script['full_path'];

			// Skip if already in preload list.
			if (in_array($path, $current_files, true)) {
				continue;
			}

			// Skip if previously failed.
			if (in_array($path, $failed_paths, true)) {
				continue;
			}

			// Analyze for safety.
			$analysis = $this->plugin->safety_analyzer->analyze_file($path);

			// Only include safe files.
			if ($analysis['safe'] && empty($analysis['errors'])) {
				$candidates[] = [
					'file'     => $path,
					'hits'     => $script['hits'] ?? 0,
					'memory'   => $script['memory_consumption'] ?? 0,
					'warnings' => $analysis['warnings'],
				];
			}

			if (count($candidates) >= $max_files) {
				break;
			}
		}

		return $candidates;
	}

	/**
	 * Run test with the stored token.
	 *
	 * The token file is created once at the start of optimization (in start())
	 * and persists until optimization completes or stops. This avoids race
	 * conditions where the HTTP request hasn't read the token yet.
	 *
	 * @param string $token Test token.
	 * @return array
	 */
	private function run_test_with_token(string $token): array {

		return $this->tester->run_test($token);
	}

	/**
	 * Clean up the token file.
	 *
	 * @return void
	 */
	private function cleanup_token_file(): void {

		$token_file = ABSPATH . '.opcache_test_token';

		if (file_exists($token_file)) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink($token_file);
		}
	}
}
