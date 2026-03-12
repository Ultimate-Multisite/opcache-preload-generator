<?php
/**
 * WP-CLI Command class.
 *
 * Provides a WP-CLI command to run the auto-optimizer from the command line.
 * Since WP-CLI runs in the CLI SAPI (which has a separate OPcache from FPM),
 * this command uses HTTP requests to the site's REST API to:
 * - Fetch OPcache stats from the web server
 * - Run preload tests in the web context
 *
 * @package OPcache_Preload_Generator
 */

namespace OPcache_Preload_Generator;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

/**
 * Manage OPcache preload file generation and optimization.
 *
 * ## EXAMPLES
 *
 *     # Auto-detect and optimize preload files
 *     wp opcache-preload optimize
 *
 *     # Test a specific file as a preload candidate
 *     wp opcache-preload test-file /path/to/file.php
 *
 *     # Show current preload status
 *     wp opcache-preload status
 *
 *     # List candidate files from OPcache
 *     wp opcache-preload candidates
 *
 *     # Generate the preload.php file from current file list
 *     wp opcache-preload generate
 *
 *     # Reset optimization state
 *     wp opcache-preload reset
 */
class CLI_Command extends \WP_CLI_Command {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 */
	public function __construct() {

		$this->plugin = Plugin::get_instance();
	}

	/**
	 * Run the auto-optimizer.
	 *
	 * Fetches OPcache stats from the web server, identifies high-hit files,
	 * and tests each one by adding it to the preload file and verifying the
	 * site still works. Files that cause errors are automatically removed.
	 *
	 * With --max=auto (default), uses a reference WordPress core file to
	 * determine a hit threshold. Only files with hits above the threshold
	 * are tested. The --threshold option controls how aggressive this is:
	 * 0.7 (default) = files with 70% of the reference file's hits,
	 * 0.1 = files with 10% (more files), 0.01 = nearly everything.
	 *
	 * ## OPTIONS
	 *
	 * [--max=<number>]
	 * : Maximum number of candidate files to test. Use 'auto' for automatic
	 *   detection based on hit distribution.
	 * ---
	 * default: auto
	 * ---
	 *
	 * [--threshold=<ratio>]
	 * : Hit threshold ratio for auto mode (0.01-1.0). Files with hits above
	 *   this percentage of the reference file's hits are included. Lower values
	 *   include more files. Ignored when --max is a number.
	 * ---
	 * default: 0.7
	 * ---
	 *
	 * [--delay=<ms>]
	 * : Delay in milliseconds between testing each file.
	 * ---
	 * default: 500
	 * ---
	 *
	 * [--baseline-runs=<number>]
	 * : Number of baseline test runs to average.
	 * ---
	 * default: 3
	 * ---
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Auto-detect with default 70% threshold (~20 files)
	 *     wp opcache-preload optimize
	 *
	 *     # Lower threshold to include more files (~500 files)
	 *     wp opcache-preload optimize --threshold=0.1
	 *
	 *     # Very low threshold for comprehensive optimization
	 *     wp opcache-preload optimize --threshold=0.01
	 *
	 *     # Fixed number of top files by hits
	 *     wp opcache-preload optimize --max=200
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function optimize(array $args, array $assoc_args): void {

		$max_files     = $assoc_args['max'] ?? 'auto';
		$threshold     = (float) ($assoc_args['threshold'] ?? 0.7);
		$delay_ms      = (int) ($assoc_args['delay'] ?? 500);
		$baseline_runs = (int) ($assoc_args['baseline-runs'] ?? 3);

		\WP_CLI::log('OPcache Preload Optimizer');
		\WP_CLI::log('========================');
		\WP_CLI::log('');

		// Step 1: Fetch candidates from web server.
		\WP_CLI::log('Fetching OPcache stats from web server...');

		$candidates_response = $this->api_get(
			'candidates',
			[
				'mode'        => 'auto' === $max_files ? 'auto' : 'all',
				'max'         => is_numeric($max_files) ? (int) $max_files : 5000,
				'threshold'   => $threshold,
				'skip_safety' => 1, // Skip static analysis — the optimizer does runtime testing.
			]
		);

		if (! $candidates_response['success']) {
			$error = $candidates_response['error'] ?? 'Unknown error';
			if (! empty($candidates_response['body'])) {
				$body_preview = substr($candidates_response['body'], 0, 200);
				\WP_CLI::debug('Response body: ' . $body_preview);
			}
			\WP_CLI::error('Failed to fetch candidates: ' . $error . "\nMake sure the site is accessible via HTTP. Try: curl -sk " . rest_url(Rest_API::NAMESPACE . '/candidates'));
		}

		$candidates = $candidates_response['candidates'];
		$skipped    = $candidates_response['skipped'] ?? [];
		$total      = count($candidates);

		if (0 === $total) {
			\WP_CLI::error('No candidate files found. Make sure OPcache has cached some files and the site has been visited.');
		}

		\WP_CLI::log(sprintf('Found %d candidate files.', $total));

		if (! empty($candidates_response['meta'])) {
			$meta = $candidates_response['meta'];
			if (! empty($meta['reference_file'])) {
				\WP_CLI::log(
					sprintf(
						'Reference file: %s (%d hits), cutoff: %d hits',
						$meta['reference_file'],
						$meta['reference_hits'] ?? 0,
						$meta['cutoff_hits'] ?? 0
					)
				);
			}
		}

		if (! empty($skipped)) {
			\WP_CLI::log(sprintf('Skipped %d files (blacklisted or unsafe).', count($skipped)));
		}

		\WP_CLI::log('');

		if (! isset($assoc_args['yes'])) {
			\WP_CLI::confirm(sprintf('Test %d files for preloading?', $total));
		}

		$files_config = $candidates;
		$files_config = array_map(fn($e) => $e['file'], $files_config);
		$this->plugin->save_preload_files($files_config);
		$files_config = $this->plugin->get_preload_files_config();
		$settings     = $this->plugin->get_settings();

		if (empty($files_config)) {
			\WP_CLI::error('No files in preload list. Run "wp opcache-preload optimize" first.');
		}

		$result = $this->regenerate_preload_file($files_config);

		if (true !== $result) {
			\WP_CLI::error('Failed to generate preload file: ' . $result);
		}

		$skipped = $this->plugin->preload_generator->get_skipped_files();

		\WP_CLI::success(
			sprintf(
				'Preload file generated: %s (%d files)',
				$settings['output_path'],
				count($files_config) - count($skipped)
			)
		);

		if (! empty($skipped)) {
			\WP_CLI::log(sprintf('%d files skipped due to dependency issues.', count($skipped)));
		}
		RETURN  ;

		// Step 2: Set up test infrastructure.
		\WP_CLI::log('Setting up test infrastructure...');

		$settings = $this->plugin->get_settings();
		$tester   = $this->plugin->preload_tester;

		// Generate test token and file.
		$token  = $tester->generate_test_token();
		$result = $tester->generate_test_file($settings['output_path']);

		if (true !== $result) {
			\WP_CLI::error('Failed to generate test file: ' . $result);
		}

		// Write token file.
		$token_file = ABSPATH . '.opcache_test_token';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents($token_file, $token);

		// Save state so the REST endpoint can find the token.
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
			'total_candidates' => $total,
			'current_file'     => '',
			'time_saved_ms'    => 0,
			'time_saved_pct'   => 0,
			'last_error'       => '',
			'started_at'       => time(),
			'updated_at'       => time(),
			'test_results'     => [],
			'original_files'   => $this->plugin->get_preload_files(),
		];
		$this->plugin->auto_optimizer->save_state($state);

		// Step 3: Run baseline test.
		\WP_CLI::log('Running baseline test...');

		// Clear preload files for baseline.
		$this->plugin->save_preload_files([]);
		$this->regenerate_preload_file([]);

		$baseline_times = [];
		for ($i = 0; $i < $baseline_runs; $i++) {
			if ($i > 0) {
				usleep($delay_ms * 1000);
			}

			$test_result = $tester->run_test($token);

			if (! $test_result['success']) {
				$this->cleanup($tester, $token_file);
				\WP_CLI::error('Baseline test failed: ' . ($test_result['error'] ?? 'Unknown error'));
			}

			$baseline_times[] = $test_result['time_ms'];
			\WP_CLI::log(sprintf('  Run %d: %.2f ms', $i + 1, $test_result['time_ms']));
		}

		$baseline_time = round(array_sum($baseline_times) / count($baseline_times), 2);
		\WP_CLI::log(sprintf('Baseline: %.2f ms (average of %d runs)', $baseline_time, $baseline_runs));
		\WP_CLI::log('');

		// Step 4: Test each candidate file.
		\WP_CLI::log('Testing candidate files...');
		\WP_CLI::log('');

		$files_added  = 0;
		$files_failed = 0;
		$best_time    = $baseline_time;
		$failed_files = [];
		$progress     = \WP_CLI\Utils\make_progress_bar('Optimizing', $total);

		foreach ($candidates as $index => $candidate) {
			$file_path = $candidate['file'];
			$relative  = $this->get_relative_path($file_path);

			// Add file to preload list. The generator determines the preload
			// method per-file (require_once vs opcache_compile_file) based on
			// dependency resolution and static analysis.
			$files_config   = $this->plugin->get_preload_files_config();
			$files_config[] = [
				'path' => $file_path,
			];
			$this->plugin->save_preload_files($files_config);
			$this->regenerate_preload_file($files_config);

			// Small delay to let things settle.
			usleep($delay_ms * 1000);

			// Test that the site still works with this file in the preload list.
			$test_result = $tester->run_test($token);

			if ($test_result['success']) {
				++$files_added;

				if ($test_result['time_ms'] < $best_time) {
					$best_time = $test_result['time_ms'];
				}

				\WP_CLI::log(
					sprintf(
						'  + %s (%.2f ms)',
						$relative,
						$test_result['time_ms']
					)
				);
			} else {
				// File caused a test failure — remove it.
				$this->remove_file_from_config($file_path);
				$this->regenerate_preload_file($this->plugin->get_preload_files_config());

				++$files_failed;
				$error_msg      = $test_result['error'] ?? 'Unknown error';
				$failed_files[] = [
					'file'  => $file_path,
					'error' => $error_msg,
				];

				\WP_CLI::log(
					sprintf(
						'  x %s FAILED: %s',
						$relative,
						substr($error_msg, 0, 120)
					)
				);
			}

			$progress->tick();
		}

		$progress->finish();

		// Step 5: Final regeneration and cleanup.
		\WP_CLI::log('');
		\WP_CLI::log('Generating final preload file...');

		$files_config = $this->plugin->get_preload_files_config();
		$this->regenerate_preload_file($files_config);

		$this->cleanup($tester, $token_file);
		$this->delete_auth_token();

		// Update state to completed.
		$time_saved_ms  = round($baseline_time - $best_time, 2);
		$time_saved_pct = $baseline_time > 0 ? round(($time_saved_ms / $baseline_time) * 100, 1) : 0;

		$state['status']         = 'completed';
		$state['phase']          = 'complete';
		$state['files_added']    = $files_added;
		$state['files_failed']   = $files_failed;
		$state['failed_files']   = $failed_files;
		$state['baseline_time']  = $baseline_time;
		$state['best_time']      = $best_time;
		$state['time_saved_ms']  = $time_saved_ms;
		$state['time_saved_pct'] = $time_saved_pct;
		$this->plugin->auto_optimizer->save_state($state);

		// Summary.
		\WP_CLI::log('');
		\WP_CLI::success('Optimization complete!');
		\WP_CLI::log('');
		\WP_CLI::log(sprintf('  Files added:   %d', $files_added));
		\WP_CLI::log(sprintf('  Files failed:  %d', $files_failed));
		\WP_CLI::log(sprintf('  Baseline:      %.2f ms', $baseline_time));
		\WP_CLI::log(sprintf('  Best time:     %.2f ms', $best_time));
		\WP_CLI::log(sprintf('  Time saved:    %.2f ms (%.1f%%)', $time_saved_ms, $time_saved_pct));
		\WP_CLI::log(sprintf('  Preload file:  %s', $settings['output_path']));
		\WP_CLI::log('');
		\WP_CLI::log('To activate preloading, add to your php.ini:');
		\WP_CLI::log($this->plugin->preload_generator->get_php_ini_config($settings['output_path']));
	}

	/**
	 * Test a specific file as a preload candidate.
	 *
	 * Adds the file to the preload list, regenerates preload.php, and runs
	 * a test to see if the site still works. If it fails, the file is removed.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the PHP file to test.
	 *
	 * [--method=<method>]
	 * : Include method to use.
	 * ---
	 * default: opcache_compile_file
	 * options:
	 *   - opcache_compile_file
	 *   - require_once
	 * ---
	 *
	 * [--keep]
	 * : Keep the file in the preload list even if the test fails.
	 *
	 * ## EXAMPLES
	 *
	 *     wp opcache-preload test-file /path/to/file.php
	 *     wp opcache-preload test-file /path/to/file.php --method=opcache_compile_file
	 *
	 * @subcommand test-file
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function test_file(array $args, array $assoc_args): void {

		$file_path = $args[0];
		$method    = $assoc_args['method'] ?? 'opcache_compile_file';
		$keep      = isset($assoc_args['keep']);

		// Validate file.
		if (! file_exists($file_path)) {
			// Try as relative to ABSPATH.
			$abs_path = ABSPATH . ltrim($file_path, '/');
			if (file_exists($abs_path)) {
				$file_path = $abs_path;
			} else {
				\WP_CLI::error("File not found: $file_path");
			}
		}

		$file_path = realpath($file_path);

		// Safety analysis.
		$analysis = $this->plugin->safety_analyzer->analyze_file($file_path);

		if (! $analysis['safe']) {
			\WP_CLI::warning('Safety analysis flagged this file:');
			foreach ($analysis['errors'] as $error) {
				\WP_CLI::log("  Error: $error");
			}
			\WP_CLI::log('');
			\WP_CLI::confirm('Continue testing anyway?');
		}

		if (! empty($analysis['warnings'])) {
			\WP_CLI::log('Warnings:');
			foreach ($analysis['warnings'] as $warning) {
				\WP_CLI::log("  - $warning");
			}
			\WP_CLI::log('');
		}

		// Set up test infrastructure.
		$settings = $this->plugin->get_settings();
		$tester   = $this->plugin->preload_tester;
		$token    = $tester->generate_test_token();

		$result = $tester->generate_test_file($settings['output_path']);
		if (true !== $result) {
			\WP_CLI::error('Failed to generate test file: ' . $result);
		}

		$token_file = ABSPATH . '.opcache_test_token';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents($token_file, $token);

		// Save minimal state for the test endpoint.
		$state               = $this->plugin->auto_optimizer->get_state();
		$state['test_token'] = $token;
		$this->plugin->auto_optimizer->save_state($state);

		// Add file and test.
		$files_config = $this->plugin->get_preload_files_config();
		$was_in_list  = false;

		foreach ($files_config as $existing) {
			if ($existing['path'] === $file_path) {
				$was_in_list = true;
				break;
			}
		}

		if (! $was_in_list) {
			$files_config[] = [
				'path'   => $file_path,
				'method' => $method,
			];
			$this->plugin->save_preload_files($files_config);
		}

		$this->regenerate_preload_file($files_config);

		\WP_CLI::log(sprintf('Testing %s with %s...', $this->get_relative_path($file_path), $method));

		usleep(500000); // 500ms settle time.
		$test_result = $tester->run_test($token);

		// Cleanup test infrastructure.
		$this->cleanup($tester, $token_file);

		if ($test_result['success']) {
			\WP_CLI::success(
				sprintf(
					'File works! Time: %.2f ms',
					$test_result['time_ms']
				)
			);

			// Regenerate without test file.
			$this->regenerate_preload_file($this->plugin->get_preload_files_config());
		} else {
			$error = $test_result['error'] ?? 'Unknown error';
			\WP_CLI::warning("Test failed: $error");

			if (! $keep && ! $was_in_list) {
				$this->remove_file_from_config($file_path);
				$this->regenerate_preload_file($this->plugin->get_preload_files_config());
				\WP_CLI::log('File removed from preload list.');
			}
		}
	}

	/**
	 * Show current preload status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp opcache-preload status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function status(array $args, array $assoc_args): void {

		$settings     = $this->plugin->get_settings();
		$files        = $this->plugin->get_preload_files();
		$files_config = $this->plugin->get_preload_files_config();
		$state        = $this->plugin->auto_optimizer->get_state();

		\WP_CLI::log('OPcache Preload Status');
		\WP_CLI::log('======================');
		\WP_CLI::log('');
		\WP_CLI::log(sprintf('Output path:     %s', $settings['output_path']));
		\WP_CLI::log(sprintf('File exists:     %s', file_exists($settings['output_path']) ? 'Yes' : 'No'));
		\WP_CLI::log(sprintf('Files in list:   %d', count($files)));
		\WP_CLI::log(sprintf('Method:          auto (require_once for pure declarations, opcache_compile_file for rest)'));
		\WP_CLI::log('');

		// Optimizer state.
		if ('idle' !== $state['status']) {
			\WP_CLI::log('Optimizer State');
			\WP_CLI::log('---------------');
			\WP_CLI::log(sprintf('Status:          %s', $state['status']));
			\WP_CLI::log(sprintf('Phase:           %s', $state['phase']));
			\WP_CLI::log(sprintf('Files tested:    %d', $state['files_tested']));
			\WP_CLI::log(sprintf('Files added:     %d', $state['files_added']));
			\WP_CLI::log(sprintf('Files failed:    %d', $state['files_failed']));
			\WP_CLI::log(sprintf('Baseline:        %.2f ms', $state['baseline_time']));
			\WP_CLI::log(sprintf('Best time:       %.2f ms', $state['best_time']));
			\WP_CLI::log(sprintf('Time saved:      %.2f ms (%.1f%%)', $state['time_saved_ms'], $state['time_saved_pct']));
			\WP_CLI::log('');
		}

		// Try to get OPcache info from web server.
		$opcache_response = $this->api_get('opcache-status');

		if ($opcache_response['success']) {
			$preloading = $opcache_response['preloading'] ?? [];
			\WP_CLI::log('OPcache (Web Server)');
			\WP_CLI::log('--------------------');
			\WP_CLI::log(sprintf('Preloading enabled: %s', ($preloading['enabled'] ?? false) ? 'Yes' : 'No'));

			if (! empty($preloading['file'])) {
				\WP_CLI::log(sprintf('Preload file:       %s', $preloading['file']));
			}

			if (! empty($preloading['user'])) {
				\WP_CLI::log(sprintf('Preload user:       %s', $preloading['user']));
			}

			$memory = $opcache_response['memory'] ?? [];
			if (! empty($memory)) {
				\WP_CLI::log(sprintf('Memory used:        %.1f%%', $memory['used_pct'] ?? 0));
			}

			$cache = $opcache_response['cache'] ?? [];
			if (! empty($cache)) {
				\WP_CLI::log(sprintf('Cached scripts:     %d', $cache['num_cached_scripts'] ?? 0));
				\WP_CLI::log(sprintf('Hit rate:           %.1f%%', $cache['hit_rate'] ?? 0));
			}
		} else {
			\WP_CLI::warning('Could not fetch OPcache status from web server. REST API may not be accessible.');
		}
	}

	/**
	 * List candidate files from OPcache.
	 *
	 * ## OPTIONS
	 *
	 * [--mode=<mode>]
	 * : Detection mode. 'auto' uses hit threshold, 'all' returns top N by hits.
	 * ---
	 * default: auto
	 * options:
	 *   - auto
	 *   - all
	 * ---
	 *
	 * [--max=<number>]
	 * : Maximum number of candidates.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--threshold=<ratio>]
	 * : Hit threshold ratio for auto mode (0.01-1.0). Lower = more files.
	 * ---
	 * default: 0.7
	 * ---
	 *
	 * [--skip-safety]
	 * : Skip static safety analysis (faster for large result sets).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp opcache-preload candidates
	 *     wp opcache-preload candidates --threshold=0.1
	 *     wp opcache-preload candidates --mode=all --max=500 --format=json
	 *     wp opcache-preload candidates --mode=all --max=10000 --skip-safety
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function candidates(array $args, array $assoc_args): void {

		$mode        = $assoc_args['mode'] ?? 'auto';
		$max         = (int) ($assoc_args['max'] ?? 100);
		$threshold   = (float) ($assoc_args['threshold'] ?? 0.7);
		$format      = $assoc_args['format'] ?? 'table';
		$skip_safety = isset($assoc_args['skip-safety']);

		$response = $this->api_get(
			'candidates',
			[
				'mode'        => $mode,
				'max'         => $max,
				'threshold'   => $threshold,
				'skip_safety' => $skip_safety ? 1 : 0,
			]
		);

		if (! $response['success']) {
			$error = $response['error'] ?? 'Unknown error';
			\WP_CLI::error('Failed to fetch candidates: ' . $error . "\nMake sure the site is accessible via HTTP.");
		}

		$candidates = $response['candidates'];

		if (empty($candidates)) {
			\WP_CLI::warning('No candidate files found.');
			return;
		}

		// Format for display.
		$items = [];
		foreach ($candidates as $candidate) {
			$items[] = [
				'file'     => $this->get_relative_path($candidate['file']),
				'hits'     => $candidate['hits'],
				'memory'   => $this->format_bytes($candidate['memory']),
				'warnings' => count($candidate['warnings'] ?? []),
			];
		}

		\WP_CLI\Utils\format_items($format, $items, ['file', 'hits', 'memory', 'warnings']);

		if (! empty($response['meta'])) {
			$meta = $response['meta'];
			\WP_CLI::log('');
			\WP_CLI::log(sprintf('Total candidates: %d', count($candidates)));
			if (! empty($meta['reference_file'])) {
				\WP_CLI::log(sprintf('Reference: %s (%d hits), cutoff: %d hits', $meta['reference_file'], $meta['reference_hits'] ?? 0, $meta['cutoff_hits'] ?? 0));
			}
		}
	}

	/**
	 * Generate the preload.php file from the current file list.
	 *
	 * ## EXAMPLES
	 *
	 *     wp opcache-preload generate
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function generate(array $args, array $assoc_args): void {

		$files_config = $this->plugin->get_preload_files_config();
		$settings     = $this->plugin->get_settings();

		if (empty($files_config)) {
			\WP_CLI::error('No files in preload list. Run "wp opcache-preload optimize" first.');
		}

		$result = $this->regenerate_preload_file($files_config);

		if (true !== $result) {
			\WP_CLI::error('Failed to generate preload file: ' . $result);
		}

		$skipped = $this->plugin->preload_generator->get_skipped_files();

		\WP_CLI::success(
			sprintf(
				'Preload file generated: %s (%d files)',
				$settings['output_path'],
				count($files_config) - count($skipped)
			)
		);

		if (! empty($skipped)) {
			\WP_CLI::log(sprintf('%d files skipped due to dependency issues.', count($skipped)));
		}
	}

	/**
	 * Reset the optimization state.
	 *
	 * ## EXAMPLES
	 *
	 *     wp opcache-preload reset
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function reset(array $args, array $assoc_args): void {

		$this->plugin->auto_optimizer->reset_state();

		// Clean up test file and token.
		$this->plugin->preload_tester->delete_test_file();

		$token_file = ABSPATH . '.opcache_test_token';
		if (file_exists($token_file)) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink($token_file);
		}

		\WP_CLI::success('Optimization state reset.');
	}

	/**
	 * Make a GET request to the plugin's REST API.
	 *
	 * Uses wp_remote_get to hit the site's own REST API, which runs in the
	 * web server's SAPI and has access to the real OPcache.
	 *
	 * @param string               $endpoint REST endpoint path (after namespace).
	 * @param array<string, mixed> $params   Query parameters.
	 * @return array Response data.
	 */
	private function api_get(string $endpoint, array $params = []): array {

		$url = rest_url(Rest_API::NAMESPACE . '/' . $endpoint);

		if (! empty($params)) {
			$url = add_query_arg($params, $url);
		}

		// Use a shared-secret token stored as a transient for cross-process auth.
		// Cookie+nonce auth doesn't work from WP-CLI because the nonce is tied
		// to the CLI process session and is invalid in the web server process.
		$response = wp_remote_get(
			$url,
			[
				'timeout'   => 120,
				'sslverify' => false,
				'headers'   => $this->get_auth_headers(),
			]
		);

		if (is_wp_error($response)) {
			return [
				'success' => false,
				'error'   => $response->get_error_message(),
			];
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if (null === $data) {
			return [
				'success' => false,
				'error'   => 'Invalid JSON response',
				'body'    => substr($body, 0, 500),
			];
		}

		return $data;
	}

	/**
	 * Get authentication headers for REST API requests.
	 *
	 * Uses a shared-secret token stored as a WordPress transient. Both WP-CLI
	 * and the web server share the same database, so the token created here
	 * can be verified by the REST API endpoint in the web process.
	 *
	 * @return array<string, string>
	 */
	private function get_auth_headers(): array {

		$token = $this->get_or_create_auth_token();

		return [
			'X-OPcache-Preload-Token' => $token,
		];
	}

	/**
	 * Get or create a short-lived auth token for CLI-to-REST communication.
	 *
	 * The token is stored as a transient with a 5-minute TTL. If a valid token
	 * already exists, it is reused (so multiple API calls in one optimize run
	 * share the same token).
	 *
	 * @return string The auth token.
	 */
	private function get_or_create_auth_token(): string {

		$transient_key = Rest_API::AUTH_TRANSIENT_KEY;
		$existing      = get_site_transient($transient_key);

		if (! empty($existing)) {
			return $existing;
		}

		// Generate a cryptographically secure random token.
		$token = wp_generate_password(64, false);

		// Store with 5-minute TTL. Uses site transient for multisite compat.
		set_site_transient($transient_key, $token, 5 * MINUTE_IN_SECONDS);

		return $token;
	}

	/**
	 * Clean up the auth token after the CLI session is done.
	 *
	 * @return void
	 */
	private function delete_auth_token(): void {

		delete_site_transient(Rest_API::AUTH_TRANSIENT_KEY);
	}

	/**
	 * Regenerate the preload.php file.
	 *
	 * @param array<array<string, mixed>> $files_config File configuration array.
	 * @return bool|string True on success, error message on failure.
	 */
	private function regenerate_preload_file(array $files_config) {

		$settings = $this->plugin->get_settings();

		return $this->plugin->preload_generator->write_file(
			$files_config,
			$settings['output_path'],
			[
				'use_require'    => $settings['use_require'],
				'validate_files' => true,
				'output_path'    => $settings['output_path'],
				'abspath'        => ABSPATH,
			]
		);
	}

	/**
	 * Remove a file from the preload config.
	 *
	 * @param string $file_path File path to remove.
	 * @return void
	 */
	private function remove_file_from_config(string $file_path): void {

		$files_config = $this->plugin->get_preload_files_config();

		$files_config = array_filter(
			$files_config,
			function ($file) use ($file_path) {
				return $file['path'] !== $file_path;
			}
		);

		$this->plugin->save_preload_files(array_values($files_config));
	}

	/**
	 * Clean up test infrastructure.
	 *
	 * @param Preload_Tester $tester     Tester instance.
	 * @param string         $token_file Token file path.
	 * @return void
	 */
	private function cleanup(Preload_Tester $tester, string $token_file): void {

		$tester->delete_test_file();

		if (file_exists($token_file)) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink($token_file);
		}

		delete_transient('opcache_test_token');
	}

	/**
	 * Get relative path from ABSPATH.
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

	/**
	 * Format bytes to human-readable string.
	 *
	 * @param int $bytes Byte count.
	 * @return string
	 */
	private function format_bytes(int $bytes): string {

		if ($bytes >= 1048576) {
			return round($bytes / 1048576, 1) . ' MB';
		}

		if ($bytes >= 1024) {
			return round($bytes / 1024, 1) . ' KB';
		}

		return $bytes . ' B';
	}
}
