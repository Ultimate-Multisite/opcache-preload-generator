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
	 * Dependency resolver instance.
	 *
	 * @var Dependency_Resolver|null
	 */
	public ?Dependency_Resolver $dependency_resolver = null;

	/**
	 * REST API instance.
	 *
	 * @var Rest_API|null
	 */
	public ?Rest_API $rest_api = null;

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
		require_once OPCACHE_PRELOAD_DIR . 'inc/class-dependency-resolver.php';
		require_once OPCACHE_PRELOAD_DIR . 'inc/class-preload-generator.php';
		require_once OPCACHE_PRELOAD_DIR . 'inc/class-rest-api.php';

		require_once OPCACHE_PRELOAD_DIR . 'inc/class-admin-page.php';
		require_once OPCACHE_PRELOAD_DIR . 'inc/class-ajax-handler.php';

		if (defined('WP_CLI') && WP_CLI) {
			require_once OPCACHE_PRELOAD_DIR . 'inc/class-cli-command.php';
		}
	}

	/**
	 * Initialize plugin components.
	 *
	 * @return void
	 */
	private function init_components(): void {

		$this->opcache_analyzer    = new OPcache_Analyzer();
		$this->safety_analyzer     = new File_Safety_Analyzer();
		$this->dependency_resolver = new Dependency_Resolver();
		$this->preload_generator   = new Preload_Generator($this->safety_analyzer, $this->dependency_resolver);
		$this->rest_api            = new Rest_API($this);

		if (is_admin()) {
			$this->admin_page   = new Admin_Page($this);
			$this->ajax_handler = new Ajax_Handler($this);
		}

		if (defined('WP_CLI') && WP_CLI) {
			\WP_CLI::add_command('opcache-preload', __NAMESPACE__ . '\\CLI_Command');
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
	 * Get plugin settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {

		$defaults = [
			// Output path for the generated preload.php file.
			'output_path'      => ABSPATH . 'preload.php',
			// Patterns to exclude from preloading.
			'exclude_patterns' => [
				// Test files.
				'*/tests/*',
				'*/vendor/*test*',
				'*/phpunit/*',
				'*/docket-cache/cache.php',
				'*/docket-cache/load.php',
				'*/docket-cache/compat.php',
			],
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
