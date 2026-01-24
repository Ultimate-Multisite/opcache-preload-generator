<?php
/**
 * Main plugin class.
 *
 * @package OPcache_Preload_Generator
 */

namespace OPcache_Preload_Generator;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

/**
 * Main plugin bootstrap class.
 */
class Plugin {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	public string $version = '1.0.0';

	/**
	 * Single instance of the class.
	 *
	 * @var Plugin|null
	 */
	protected static ?Plugin $instance = null;

	/**
	 * OPcache analyzer instance.
	 *
	 * @var OPcache_Analyzer|null
	 */
	public ?OPcache_Analyzer $opcache_analyzer = null;

	/**
	 * File safety analyzer instance.
	 *
	 * @var File_Safety_Analyzer|null
	 */
	public ?File_Safety_Analyzer $safety_analyzer = null;

	/**
	 * Preload generator instance.
	 *
	 * @var Preload_Generator|null
	 */
	public ?Preload_Generator $preload_generator = null;

	/**
	 * Admin page instance.
	 *
	 * @var Admin_Page|null
	 */
	public ?Admin_Page $admin_page = null;

	/**
	 * AJAX handler instance.
	 *
	 * @var Ajax_Handler|null
	 */
	public ?Ajax_Handler $ajax_handler = null;

	/**
	 * Preload tester instance.
	 *
	 * @var Preload_Tester|null
	 */
	public ?Preload_Tester $preload_tester = null;

	/**
	 * Auto optimizer instance.
	 *
	 * @var Auto_Optimizer|null
	 */
	public ?Auto_Optimizer $auto_optimizer = null;

	/**
	 * Main instance.
	 *
	 * @return Plugin
	 */
	public static function get_instance(): Plugin {

		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {

		add_action('plugins_loaded', [$this, 'init']);
	}

	/**
	 * Initialize the plugin.
	 *
	 * @return void
	 */
	public function init(): void {

		// Load plugin files.
		$this->load_dependencies();

		// Initialize components.
		$this->init_components();

		// Initialize hooks.
		$this->init_hooks();
	}

	/**
	 * Load required dependencies.
	 *
	 * @return void
	 */
	private function load_dependencies(): void {

		require_once OPCACHE_PRELOAD_DIR . 'inc/class-opcache-analyzer.php';
		require_once OPCACHE_PRELOAD_DIR . 'inc/class-file-safety-analyzer.php';
		require_once OPCACHE_PRELOAD_DIR . 'inc/class-preload-generator.php';
		require_once OPCACHE_PRELOAD_DIR . 'inc/class-preload-tester.php';
		require_once OPCACHE_PRELOAD_DIR . 'inc/class-auto-optimizer.php';
		require_once OPCACHE_PRELOAD_DIR . 'inc/class-admin-page.php';
		require_once OPCACHE_PRELOAD_DIR . 'inc/class-ajax-handler.php';

		if (is_admin()) {
			require_once OPCACHE_PRELOAD_DIR . 'inc/class-file-list-table.php';
		}
	}

	/**
	 * Initialize plugin components.
	 *
	 * @return void
	 */
	private function init_components(): void {

		$this->opcache_analyzer  = new OPcache_Analyzer();
		$this->safety_analyzer   = new File_Safety_Analyzer();
		$this->preload_generator = new Preload_Generator($this->safety_analyzer);
		$this->preload_tester    = new Preload_Tester();
		$this->auto_optimizer    = new Auto_Optimizer($this, $this->preload_tester);

		if (is_admin()) {
			$this->admin_page   = new Admin_Page($this);
			$this->ajax_handler = new Ajax_Handler($this);
		}
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	private function init_hooks(): void {

		add_action('init', [$this, 'load_textdomain']);
	}

	/**
	 * Load plugin textdomain.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {

		load_plugin_textdomain(
			'opcache-preload-generator',
			false,
			dirname(plugin_basename(OPCACHE_PRELOAD_PLUGIN_FILE)) . '/lang/'
		);
	}

	/**
	 * Get the saved preload files list.
	 *
	 * Returns array of file paths for backward compatibility.
	 * Use get_preload_files_config() for full configuration.
	 *
	 * @return array<string>
	 */
	public function get_preload_files(): array {

		$files = get_option('opcache_preload_files', []);

		// Handle both old (string array) and new (config array) formats.
		$paths = [];
		foreach ($files as $file) {
			if (is_array($file)) {
				$paths[] = $file['path'] ?? '';
			} else {
				$paths[] = $file;
			}
		}

		return array_filter($paths);
	}

	/**
	 * Get the saved preload files with full configuration.
	 *
	 * @return array<array{path: string, method: string}>
	 */
	public function get_preload_files_config(): array {

		$files    = get_option('opcache_preload_files', []);
		$settings = $this->get_settings();
		$default  = $settings['use_require'] ? 'require_once' : 'opcache_compile_file';

		// Normalize to config array format.
		$config = [];
		foreach ($files as $file) {
			if (is_array($file)) {
				$config[] = [
					'path'   => $file['path'] ?? '',
					'method' => $file['method'] ?? $default,
				];
			} else {
				$config[] = [
					'path'   => $file,
					'method' => $default,
				];
			}
		}

		return array_filter($config, fn($f) => ! empty($f['path']));
	}

	/**
	 * Save the preload files list.
	 *
	 * @param array<string|array<string, mixed>> $files List of file paths or config arrays.
	 * @return bool
	 */
	public function save_preload_files(array $files): bool {

		// Normalize input - deduplicate by path.
		$normalized = [];
		$seen_paths = [];

		foreach ($files as $file) {
			if (is_array($file)) {
				$path   = $file['path'] ?? '';
				$method = $file['method'] ?? 'require_once';
			} else {
				$path   = $file;
				$method = 'require_once';
			}

			if (empty($path) || in_array($path, $seen_paths, true)) {
				continue;
			}

			$seen_paths[] = $path;
			$normalized[] = [
				'path'   => $path,
				'method' => $method,
			];
		}

		return update_option('opcache_preload_files', $normalized);
	}

	/**
	 * Update the method for a specific file.
	 *
	 * @param string $path   File path.
	 * @param string $method Method ('require_once' or 'opcache_compile_file').
	 * @return bool
	 */
	public function update_file_method(string $path, string $method): bool {

		$files = get_option('opcache_preload_files', []);

		// Validate method.
		if (! in_array($method, ['require_once', 'opcache_compile_file'], true)) {
			return false;
		}

		// Find and update the file.
		foreach ($files as $key => $file) {
			$file_path = is_array($file) ? ($file['path'] ?? '') : $file;

			if ($file_path === $path) {
				$files[ $key ] = [
					'path'   => $path,
					'method' => $method,
				];

				return update_option('opcache_preload_files', $files);
			}
		}

		return false;
	}

	/**
	 * Get the method for a specific file.
	 *
	 * @param string $path File path.
	 * @return string Method name or empty string if not found.
	 */
	public function get_file_method(string $path): string {

		$files    = get_option('opcache_preload_files', []);
		$settings = $this->get_settings();
		$default  = $settings['use_require'] ? 'require_once' : 'opcache_compile_file';

		foreach ($files as $file) {
			$file_path = is_array($file) ? ($file['path'] ?? '') : $file;

			if ($file_path === $path) {
				return is_array($file) ? ($file['method'] ?? $default) : $default;
			}
		}

		return '';
	}

	/**
	 * Get plugin settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {

		$defaults = [
			'use_require'      => true,
			'output_path'      => ABSPATH . 'preload.php',
			'auto_suggest_top' => 50,
			'exclude_patterns' => ['*/tests/*', '*/vendor/*test*', '*/phpunit/*'],
		];

		return wp_parse_args(get_option('opcache_preload_settings', []), $defaults);
	}

	/**
	 * Save plugin settings.
	 *
	 * @param array<string, mixed> $settings Settings array.
	 * @return bool
	 */
	public function save_settings(array $settings): bool {

		return update_option('opcache_preload_settings', $settings);
	}

	/**
	 * Check if OPcache is available and enabled.
	 *
	 * @return bool
	 */
	public function is_opcache_available(): bool {

		return $this->opcache_analyzer->is_available();
	}
}
