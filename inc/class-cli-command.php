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
 * Generate OPcache preload files from runtime statistics.
 *
 * ## EXAMPLES
 *
 *     # Generate preload.php from high-hit OPcache files
 *     wp opcache-preload generate
 *
 *     # Show current status
 *     wp opcache-preload status
 *
 *     # List candidate files from OPcache
 *     wp opcache-preload candidates
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
	 * Generate preload.php from high-hit OPcache files.
	 *
	 * Fetches OPcache stats from the web server, identifies high-hit files,
	 * and generates a preload.php file. Uses the dependency resolver and
	 * safety analyzer to automatically determine the best include method
	 * for each file.
	 *
	 * With --max=auto (default), uses a reference WordPress core file to
	 * determine a hit threshold. Only files with hits above the threshold
	 * are included. The --threshold option controls how aggressive this is:
	 * 0.7 (default) = files with 70% of the reference file's hits,
	 * 0.1 = files with 10% (more files), 0.01 = nearly everything.
	 *
	 * ## OPTIONS
	 *
	 * [--max=<number>]
	 * : Maximum number of files to include. Use 'auto' for automatic
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
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Auto-detect with default 70% threshold (~20 files)
	 *     wp opcache-preload generate
	 *
	 *     # Lower threshold to include more files (~500 files)
	 *     wp opcache-preload generate --threshold=0.1
	 *
	 *     # Very low threshold for comprehensive optimization
	 *     wp opcache-preload generate --threshold=0.01
	 *
	 *     # Fixed number of top files by hits
	 *     wp opcache-preload generate --max=200
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function generate(array $args, array $assoc_args): void {

		$max_files = $assoc_args['max'] ?? 'auto';
		$threshold = (float) ($assoc_args['threshold'] ?? 0.7);

		\WP_CLI::log('OPcache Preload Generator');
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
				'skip_safety' => 1, // Skip static analysis - use dependency resolver instead.
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
			\WP_CLI::confirm(sprintf('Generate preload file with %d files?', $total));
		}

		// Convert candidates to file config.
		$files_config = array_map(fn($e) => ['path' => $e['file'], 'method' => 'opcache_compile_file'], $candidates);
		$settings = $this->plugin->get_settings();

		// Generate the preload file.
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

		\WP_CLI::log('');
		\WP_CLI::log('To activate preloading, add to your php.ini:');
		\WP_CLI::log($this->plugin->preload_generator->get_php_ini_config($settings['output_path']));
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

		$settings = $this->plugin->get_settings();

		\WP_CLI::log('OPcache Preload Status');
		\WP_CLI::log('======================');
		\WP_CLI::log('');
		\WP_CLI::log(sprintf('Output path:     %s', $settings['output_path']));
		\WP_CLI::log(sprintf('File exists:     %s', file_exists($settings['output_path']) ? 'Yes' : 'No'));
		\WP_CLI::log(sprintf('Method:          auto (require_once for pure declarations, opcache_compile_file for rest)'));
		\WP_CLI::log('');

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
	 * The token is stored in a file with a 5-minute TTL. Using a file instead
	 * of transients avoids issues with object caching plugins (e.g., Docket Cache)
	 * that don't share cache between CLI and web contexts.
	 *
	 * @return string The auth token.
	 */
	private function get_or_create_auth_token(): string {

		$token_file = Rest_API::AUTH_TOKEN_FILE;

		// Check for existing valid token.
		if (file_exists($token_file)) {
			$data = @file_get_contents($token_file);
			if (false !== $data) {
				$data = @json_decode($data, true);
				if (is_array($data) && isset($data['token'], $data['expires']) && $data['expires'] > time()) {
					return $data['token'];
				}
			}
		}

		// Generate a cryptographically secure random token.
		$token = wp_generate_password(64, false);

		// Store with 5-minute TTL.
		$data = [
			'token'   => $token,
			'expires' => time() + (5 * MINUTE_IN_SECONDS),
		];

		// Write to file with restricted permissions.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents($token_file, json_encode($data));
		@chmod($token_file, 0600);

		return $token;
	}

	/**
	 * Clean up the auth token after the CLI session is done.
	 *
	 * @return void
	 */
	private function delete_auth_token(): void {

		$token_file = Rest_API::AUTH_TOKEN_FILE;

		if (file_exists($token_file)) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink($token_file);
		}
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
				'validate_files' => true,
				'output_path'    => $settings['output_path'],
				'abspath'        => ABSPATH,
			]
		);
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

	/**
	 * Debug why a file is using opcache_compile_file instead of require_once.
	 *
	 * Analyzes the file and its dependencies to find which dependency has side
	 * effects, causing the file to be excluded from require_once.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the PHP file to analyze.
	 *
	 * ## EXAMPLES
	 *
	 *     # Debug a specific file
	 *     wp opcache-preload debug-file /path/to/file.php
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function debug_file(array $args, array $assoc_args): void {

		$file_path = $args[0] ?? '';

		if (empty($file_path)) {
			\WP_CLI::error('Please provide a file path.');
			return;
		}

		// Resolve relative paths.
		if (!file_exists($file_path)) {
			$abs_path = ABSPATH . ltrim($file_path, '/');
			if (file_exists($abs_path)) {
				$file_path = $abs_path;
			} else {
				\WP_CLI::error("File not found: $file_path");
				return;
			}
		}

		$file_path = realpath($file_path);

		if (!is_readable($file_path)) {
			\WP_CLI::error("File is not readable: $file_path");
			return;
		}

		\WP_CLI::log('');
		\WP_CLI::log('Debug File Analysis');
		\WP_CLI::log('==================');
		\WP_CLI::log('');
		\WP_CLI::log('File: ' . $this->get_relative_path($file_path));
		\WP_CLI::log('Full path: ' . $file_path);
		\WP_CLI::log('');

		$safety_analyzer = $this->plugin->safety_analyzer;
		$dependency_resolver = $this->plugin->dependency_resolver;

		// Check recommended method for this file.
		$method = $safety_analyzer->get_recommended_method($file_path);
		\WP_CLI::log('File safety check:');
		\WP_CLI::log('  Recommended method: ' . $method);

		if ($method !== 'require_once') {
			\WP_CLI::log('');
			\WP_CLI::log('This file has side effects and must use opcache_compile_file');
			\WP_CLI::log('');

			// Parse and show why.
			$content = file_get_contents($file_path);
			\WP_CLI::log('Token analysis (first 20 tokens):');
			$tokens = token_get_all($content);
			for ($i = 0; $i < min(20, count($tokens)); $i++) {
				$token = $tokens[$i];
				if (is_array($token)) {
					$token_name = token_name($token[0]);
					$token_text = str_replace(["\n", "\r"], ['\n', '\r'], substr($token[1], 0, 40));
					\WP_CLI::log(sprintf('  [%2d] %s: "%s"', $i, $token_name, $token_text));
				}
			}
			return;
		}

		// Parse the file to get dependencies.
		$data = $dependency_resolver->parse_file($file_path);
		\WP_CLI::log('');
		\WP_CLI::log('Classes defined in file:');
		if (empty($data['classes'])) {
			\WP_CLI::log('  (none)');
		} else {
			foreach ($data['classes'] as $class) {
				\WP_CLI::log('  - ' . $class);
			}
		}

		\WP_CLI::log('');
		\WP_CLI::log('Direct dependencies (extends/implements/use):');
		if (empty($data['dependencies'])) {
			\WP_CLI::log('  (none)');
		} else {
			foreach ($data['dependencies'] as $dep) {
				\WP_CLI::log('  - ' . $dep);
			}
		}

		// Build class map from OPcache files (or filesystem fallback).
		\WP_CLI::log('');
		\WP_CLI::log('Building class map...');
		$analyzer = $this->plugin->opcache_analyzer;
		$scripts = $analyzer->get_scripts_by_hits(0);
		$scripts = $analyzer->filter_wordpress_scripts($scripts);
		$all_files = array_map(fn($s) => $s['full_path'], $scripts);

		// If OPcache is empty (e.g., in CLI mode), scan the filesystem.
		if (empty($all_files)) {
			\WP_CLI::log('  OPcache empty (CLI mode), scanning filesystem...');
			// Scan WooCommerce and WordPress directories.
			$scan_dirs = [
				ABSPATH . 'wp-content/plugins/woocommerce',
				ABSPATH . 'wp-content/plugins/woocommerce/includes',
				ABSPATH . 'wp-content/plugins/woocommerce/src',
				ABSPATH . 'wp-includes',
			];
			foreach ($scan_dirs as $dir) {
				if (is_dir($dir)) {
					$iterator = new \RecursiveIteratorIterator(
						new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
					);
					foreach ($iterator as $file) {
						if ($file->isFile() && $file->getExtension() === 'php') {
							$all_files[] = $file->getPathname();
						}
					}
				}
			}
			$all_files = array_unique($all_files);
		}

		$dependency_resolver->build_class_map($all_files);
		\WP_CLI::log('  Scanned ' . count($all_files) . ' files');

		// Get file dependencies.
		$deps = $dependency_resolver->get_file_dependencies($file_path);
		\WP_CLI::log('');
		\WP_CLI::log('Resolving dependencies:');
		\WP_CLI::log('');

		$all_clean = true;
		$problematic = [];

		foreach ($deps as $dep_class) {
			$dep_file = $dependency_resolver->get_class_file($dep_class);
			if ($dep_file) {
				$dep_method = $safety_analyzer->get_recommended_method($dep_file);
				if ($dep_method === 'require_once') {
					\WP_CLI::log("OK $dep_class");
					\WP_CLI::log('    File: ' . $this->get_relative_path($dep_file));
				} else {
					$all_clean = false;
					$problematic[] = ['class' => $dep_class, 'file' => $dep_file];
					\WP_CLI::log("PROBLEM $dep_class");
					\WP_CLI::log('    File: ' . $this->get_relative_path($dep_file));
					\WP_CLI::log('    REASON: Has side effects - must use opcache_compile_file');
				}
			} else {
				$all_clean = false;
				$problematic[] = ['class' => $dep_class, 'file' => null];
				\WP_CLI::log("PROBLEM $dep_class");
				\WP_CLI::log('    REASON: Dependency file not found in preload list');
			}
		}

		\WP_CLI::log('');
		if ($all_clean) {
			\WP_CLI::log('OK All dependencies are clean!');
			\WP_CLI::log('This file should use require_once in the generated preload file.');
		} else {
			\WP_CLI::log('PROBLEM: Dependency issues found');
			\WP_CLI::log('This file must use opcache_compile_file due to the above issues.');
			\WP_CLI::log('');

			// Show details of problematic files.
			if (!empty($problematic)) {
				\WP_CLI::log('');
				\WP_CLI::log('Problematic dependency details:');
				\WP_CLI::log('-------------------------------');
				foreach ($problematic as $p) {
					if ($p['file']) {
						\WP_CLI::log('');
						\WP_CLI::log($p['class']);
						\WP_CLI::log('File: ' . $p['file']);
						\WP_CLI::log('');
						$content = file_get_contents($p['file']);
						$lines = explode("\n", $content);
						\WP_CLI::log('First 20 lines:');
						for ($i = 0; $i < min(20, count($lines)); $i++) {
							\WP_CLI::log('  ' . ($i + 1) . ': ' . $lines[$i]);
						}
					} else {
						\WP_CLI::log('');
						\WP_CLI::log($p['class']);
						\WP_CLI::log('File: Not found in class map');
						\WP_CLI::log('This dependency is not available in the preload candidate list.');
					}
				}
			}
		}

		\WP_CLI::log('');
	}
}
