<?php
/**
 * REST API class.
 *
 * Provides endpoints for OPcache data and preload testing.
 * WP-CLI runs in the CLI SAPI which has a separate OPcache from FPM/Apache,
 * so the CLI must call these HTTP endpoints to get the web server's OPcache stats
 * and to test preload files in the web context.
 *
 * @package OPcache_Preload_Generator
 */

namespace OPcache_Preload_Generator;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

/**
 * REST API endpoints for OPcache preload operations.
 */
class Rest_API {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'opcache-preload/v1';

	/**
	 * Transient key for CLI-to-REST auth tokens.
	 *
	 * @var string
	 */
	const AUTH_TRANSIENT_KEY = 'opcache_preload_cli_token';

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

		add_action('rest_api_init', [$this, 'register_routes']);
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {

		register_rest_route(
			self::NAMESPACE,
			'/opcache-status',
			[
				'methods'             => 'GET',
				'callback'            => [$this, 'get_opcache_status'],
				'permission_callback' => [$this, 'check_permissions'],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/candidates',
			[
				'methods'             => 'GET',
				'callback'            => [$this, 'get_candidates'],
				'permission_callback' => [$this, 'check_permissions'],
				'args'                => [
					'mode'        => [
						'type'    => 'string',
						'default' => 'auto',
						'enum'    => ['auto', 'all'],
					],
					'max'         => [
						'type'    => 'integer',
						'default' => 5000,
					],
					'threshold'   => [
						'type'    => 'number',
						'default' => 0.7,
					],
					'skip_safety' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/test',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'run_test'],
				'permission_callback' => [$this, 'check_permissions'],
			]
		);
	}

	/**
	 * Check if the current user has permission to access these endpoints.
	 *
	 * Supports two auth methods:
	 * 1. Standard WordPress auth (cookie+nonce, application passwords) for browser/API use.
	 * 2. Shared-secret token for WP-CLI cross-process auth. The CLI stores a random
	 *    token as a site transient and sends it via X-OPcache-Preload-Token header.
	 *    This works because CLI and web server share the same database.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool|\WP_Error
	 */
	public function check_permissions(\WP_REST_Request $request) {

		// Method 1: Check for CLI auth token (shared-secret via transient).
		$token = $request->get_header('X-OPcache-Preload-Token');

		if (! empty($token)) {
			$stored_token = get_site_transient(self::AUTH_TRANSIENT_KEY);

			if (! empty($stored_token) && hash_equals($stored_token, $token)) {
				return true;
			}

			return new \WP_Error(
				'rest_forbidden',
				__('Invalid or expired CLI auth token.', 'opcache-preload-generator'),
				['status' => 403]
			);
		}

		// Method 2: Standard WordPress capability check (cookie+nonce, app passwords).
		$capability = is_multisite() ? 'manage_network_options' : 'manage_options';

		if (! current_user_can($capability)) {
			return new \WP_Error(
				'rest_forbidden',
				__('You do not have permission to access OPcache data.', 'opcache-preload-generator'),
				['status' => 403]
			);
		}

		return true;
	}

	/**
	 * Get OPcache status from the web server's SAPI.
	 *
	 * This is the key endpoint — WP-CLI can't access FPM's OPcache directly,
	 * so it calls this endpoint to get the web server's cached scripts.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_opcache_status(\WP_REST_Request $request): \WP_REST_Response {

		$analyzer = $this->plugin->opcache_analyzer;

		if (! $analyzer->is_available()) {
			return new \WP_REST_Response(
				[
					'success' => false,
					'error'   => 'OPcache is not available.',
				],
				500
			);
		}

		$status = $analyzer->get_status(true);
		$config = $analyzer->get_configuration();

		return new \WP_REST_Response(
			[
				'success'    => true,
				'status'     => $status,
				'config'     => $config,
				'memory'     => $analyzer->get_memory_stats(),
				'cache'      => $analyzer->get_cache_stats(),
				'preloading' => [
					'enabled' => $analyzer->is_preloading_enabled(),
					'file'    => $analyzer->get_preload_file(),
					'user'    => $analyzer->get_preload_user(),
					'scripts' => $analyzer->get_preloaded_scripts(),
				],
			]
		);
	}

	/**
	 * Get candidate files for preloading.
	 *
	 * Returns high-hit scripts from the web server's OPcache, filtered
	 * through the safety analyzer. When skip_safety is true, all filtered
	 * scripts are returned without safety analysis — useful for the optimizer
	 * which does its own runtime testing.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_candidates(\WP_REST_Request $request): \WP_REST_Response {

		$analyzer    = $this->plugin->opcache_analyzer;
		$mode        = $request->get_param('mode');
		$max         = min((int) $request->get_param('max'), 10000);
		$threshold   = (float) $request->get_param('threshold');
		$skip_safety = (bool) $request->get_param('skip_safety');

		// Clamp threshold to a sane range (1% to 100%).
		$threshold = max(0.01, min(1.0, $threshold));

		if (! $analyzer->is_available()) {
			return new \WP_REST_Response(
				[
					'success' => false,
					'error'   => 'OPcache is not available.',
				],
				500
			);
		}

		if ('auto' === $mode) {
			$high_hit_result = $analyzer->get_high_hit_scripts($threshold, $max);
			$scripts         = $high_hit_result['scripts'];
			$meta            = [
				'cutoff_hits'    => $high_hit_result['cutoff_hits'],
				'reference_file' => $high_hit_result['reference_file'],
				'reference_hits' => $high_hit_result['reference_hits'],
				'reason'         => $high_hit_result['reason'],
			];
		} else {
			// Get ALL scripts sorted by hits — filtering happens below.
			// The max limit is applied after filtering so that cache files
			// (Docket Cache, object cache, etc.) don't consume limit slots.
			$scripts = $analyzer->get_scripts_by_hits(0);
			$meta    = [];
		}

		// Filter to WordPress files (within ABSPATH).
		$scripts = $analyzer->filter_wordpress_scripts($scripts);

		// Apply exclude patterns (test files, etc.).
		$settings = $this->plugin->get_settings();
		$scripts  = $analyzer->exclude_patterns($scripts, $settings['exclude_patterns']);

		// Apply max limit after filtering.
		if ($max > 0 && count($scripts) > $max) {
			$scripts = array_slice($scripts, 0, $max);
		}

		// When skip_safety is true, skip the expensive content analysis but
		// still apply the filename/path blacklist. Blacklisted files (sunrise.php,
		// default-filters.php, WP-CLI vendor files, etc.) are fundamentally
		// incompatible with preloading — they cause "Cannot redeclare" errors
		// or depend on runtime state that doesn't exist at preload time.
		if ($skip_safety) {
			$candidates = [];
			$skipped    = [];

			foreach ($scripts as $script) {
				$path = $script['full_path'];

				if ($this->plugin->safety_analyzer->is_blacklisted($path)) {
					$skipped[] = [
						'file'   => $path,
						'errors' => ['Blacklisted file or path pattern'],
					];
					continue;
				}

				$candidates[] = [
					'file'     => $path,
					'hits'     => $script['hits'] ?? 0,
					'memory'   => $script['memory_consumption'] ?? 0,
					'warnings' => [],
				];
			}

			return new \WP_REST_Response(
				[
					'success'    => true,
					'candidates' => $candidates,
					'skipped'    => $skipped,
					'total'      => count($candidates),
					'meta'       => $meta,
				]
			);
		}

		// Run safety analysis (for listing/display purposes).
		$candidates = [];
		$skipped    = [];

		foreach ($scripts as $script) {
			$path     = $script['full_path'];
			$analysis = $this->plugin->safety_analyzer->analyze_file($path);

			if ($analysis['safe'] && empty($analysis['errors'])) {
				$candidates[] = [
					'file'     => $path,
					'hits'     => $script['hits'] ?? 0,
					'memory'   => $script['memory_consumption'] ?? 0,
					'warnings' => $analysis['warnings'],
				];
			} else {
				$skipped[] = [
					'file'   => $path,
					'errors' => $analysis['errors'],
				];
			}
		}

		return new \WP_REST_Response(
			[
				'success'    => true,
				'candidates' => $candidates,
				'skipped'    => $skipped,
				'total'      => count($candidates),
				'meta'       => $meta,
			]
		);
	}

	/**
	 * Run a preload test via HTTP.
	 *
	 * This endpoint is called by WP-CLI after regenerating the preload file.
	 * It loads WordPress with the preload file active and checks for errors.
	 * The actual test is done by the Preload_Tester which makes a separate
	 * HTTP request to the test file.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function run_test(\WP_REST_Request $request): \WP_REST_Response {

		$state = $this->plugin->auto_optimizer->get_state();
		$token = $state['test_token'] ?? '';

		if (empty($token)) {
			return new \WP_REST_Response(
				[
					'success' => false,
					'error'   => 'No test token found. Start optimization first.',
				],
				400
			);
		}

		$result = $this->plugin->preload_tester->run_test($token);

		return new \WP_REST_Response($result);
	}
}
