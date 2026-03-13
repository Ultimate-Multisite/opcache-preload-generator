<?php
/**
 * Admin Page class.
 *
 * @package OPcache_Preload_Generator
 */

namespace OPcache_Preload_Generator;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

/**
 * Handles the admin settings page.
 */
class Admin_Page {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct(Plugin $plugin) {

		$this->plugin = $plugin;

		// Use network admin menu for multisite, regular admin menu otherwise.
		if (is_multisite()) {
			add_action('network_admin_menu', [$this, 'add_menu_page']);
		} else {
			add_action('admin_menu', [$this, 'add_menu_page']);
		}

		add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
	}

	/**
	 * Add the menu page.
	 *
	 * @return void
	 */
	public function add_menu_page(): void {

		if (is_multisite()) {
			// Add to Network Settings menu.
			$this->hook_suffix = add_submenu_page(
				'settings.php',
				__('OPcache Preload', 'opcache-preload-generator'),
				__('OPcache Preload', 'opcache-preload-generator'),
				'manage_network_options',
				'opcache-preload-generator',
				[$this, 'render_page']
			);
		} else {
			// Add to regular Settings menu.
			$this->hook_suffix = add_options_page(
				__('OPcache Preload', 'opcache-preload-generator'),
				__('OPcache Preload', 'opcache-preload-generator'),
				'manage_options',
				'opcache-preload-generator',
				[$this, 'render_page']
			);
		}
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current page hook.
	 * @return void
	 */
	public function enqueue_assets(string $hook): void {

		if ($hook !== $this->hook_suffix) {
			return;
		}

		wp_enqueue_style(
			'opcache-preload-admin',
			OPCACHE_PRELOAD_URL . 'assets/css/admin.css',
			[],
			OPCACHE_PRELOAD_VERSION
		);

		wp_enqueue_script(
			'opcache-preload-admin',
			OPCACHE_PRELOAD_URL . 'assets/js/admin.js',
			['jquery'],
			OPCACHE_PRELOAD_VERSION,
			true
		);

		wp_localize_script(
			'opcache-preload-admin',
			'opcachePreload',
			[
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce'   => wp_create_nonce('opcache_preload_nonce'),
				'i18n'    => [
					'confirm_delete'   => __('Are you sure you want to delete the preload file? You should update your php.ini configuration first.', 'opcache-preload-generator'),
					'generating'       => __('Generating...', 'opcache-preload-generator'),
					'success'          => __('Success!', 'opcache-preload-generator'),
					'error'            => __('Error:', 'opcache-preload-generator'),
					'copied'           => __('Copied to clipboard!', 'opcache-preload-generator'),
					'copy_failed'      => __('Failed to copy. Please select and copy manually.', 'opcache-preload-generator'),
					'show_preview'     => __('Show Generated File Preview', 'opcache-preload-generator'),
					'hide_preview'     => __('Hide Preview', 'opcache-preload-generator'),
					'loading'          => __('Loading...', 'opcache-preload-generator'),
					'cutoff_info'      => __('Cutoff: %s hits (reference: %s with %s hits)', 'opcache-preload-generator'),
					'no_candidates'    => __('No candidate files found at this threshold. Try a lower value.', 'opcache-preload-generator'),
					'no_candidates_near' => __('No files near the cutoff threshold.', 'opcache-preload-generator'),
				],
			]
		);
	}

	/**
	 * Render the admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {

		$capability = is_multisite() ? 'manage_network_options' : 'manage_options';

		if (! current_user_can($capability)) {
			wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'opcache-preload-generator'));
		}

		include OPCACHE_PRELOAD_DIR . 'views/admin-page.php';
	}

	/**
	 * Get OPcache status for display.
	 *
	 * @return array<string, mixed>
	 */
	public function get_opcache_status(): array {

		$analyzer = $this->plugin->opcache_analyzer;

		return [
			'available'          => $analyzer->is_available(),
			'memory'             => $analyzer->get_memory_stats(),
			'cache'              => $analyzer->get_cache_stats(),
			'preloading_enabled' => $analyzer->is_preloading_enabled(),
			'preload_file'       => $analyzer->get_preload_file(),
			'preload_user'       => $analyzer->get_preload_user(),
		];
	}

	/**
	 * Get suggested files for preloading.
	 *
	 * @param int $limit Number of files to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_suggested_files(int $limit = 50): array {

		$analyzer = $this->plugin->opcache_analyzer;
		$settings = $this->plugin->get_settings();

		// Get scripts by hit count.
		$scripts = $analyzer->get_scripts_by_hits($limit * 2); // Get extra to account for filtering.

		// Filter to WordPress files only.
		$scripts = $analyzer->filter_wordpress_scripts($scripts);

		// Exclude patterns.
		$scripts = $analyzer->exclude_patterns($scripts, $settings['exclude_patterns']);

		// Limit results.
		return array_slice($scripts, 0, $limit);
	}

	/**
	 * Format bytes for display.
	 *
	 * @param int $bytes Number of bytes.
	 * @return string
	 */
	public function format_bytes(int $bytes): string {

		if (0 === $bytes) {
			return '0 B';
		}

		$units = ['B', 'KB', 'MB', 'GB'];
		$pow   = floor(log($bytes, 1024));
		$pow   = min($pow, count($units) - 1);

		return round($bytes / (1024 ** $pow), 2) . ' ' . $units[ $pow ];
	}

	/**
	 * Format a timestamp for display.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	public function format_timestamp(int $timestamp): string {

		if (0 === $timestamp) {
			return __('Never', 'opcache-preload-generator');
		}

		return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
	}

	/**
	 * Get the relative path from ABSPATH.
	 *
	 * @param string $path Full file path.
	 * @return string
	 */
	public function get_relative_path(string $path): string {

		$abspath = realpath(ABSPATH);

		if (! $abspath) {
			return $path;
		}

		$realpath = realpath($path);

		if (! $realpath) {
			return $path;
		}

		if (strpos($realpath, $abspath) === 0) {
			return substr($realpath, strlen($abspath) + 1);
		}

		return $path;
	}

	/**
	 * Get analysis result badge HTML.
	 *
	 * @param array{safe: bool, warnings: array<string>, errors: array<string>, dependencies: array<string>} $result Analysis result.
	 * @return string
	 */
	public function get_analysis_badge(array $result): string {

		if (! empty($result['errors'])) {
			return '<span class="opcache-badge opcache-badge-error">' . esc_html__('Error', 'opcache-preload-generator') . '</span>';
		}

		if (! empty($result['warnings'])) {
			return '<span class="opcache-badge opcache-badge-warning">' . esc_html__('Warning', 'opcache-preload-generator') . '</span>';
		}

		return '<span class="opcache-badge opcache-badge-safe">' . esc_html__('Safe', 'opcache-preload-generator') . '</span>';
	}

	/**
	 * Get the admin page URL.
	 *
	 * @param array<string, string> $args Optional query args to add.
	 * @return string
	 */
	public function get_admin_url(array $args = []): string {

		$args['page'] = 'opcache-preload-generator';

		if (is_multisite()) {
			return add_query_arg($args, network_admin_url('settings.php'));
		}

		return add_query_arg($args, admin_url('options-general.php'));
	}
}
