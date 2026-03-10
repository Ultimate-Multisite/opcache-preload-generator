<?php
/**
 * Docket Cache Integration class.
 *
 * Provides integration with Docket Cache for OPcache preloading.
 *
 * @package OPcache_Preload_Generator
 */

namespace OPcache_Preload_Generator;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

/**
 * Handles Docket Cache integration for preload optimization.
 */
class Docket_Cache_Integration {

	/**
	 * Cache groups that are safe to preload (global, not site-specific).
	 *
	 * @var array<string>
	 */
	private const GLOBAL_CACHE_GROUPS = [
		'site-options',
		'networks',
		'sites',
		'blog-details',
		'blog-lookup',
		'blog-id-cache',
		'global-posts',
		'users',
		'useremail',
		'userlogins',
		'userslugs',
		'user_meta',
	];

	/**
	 * Cache groups that should be preloaded per-site.
	 *
	 * @var array<string>
	 */
	private const SITE_CACHE_GROUPS = [
		'options',
		'posts',
		'post_meta',
		'terms',
		'term_meta',
	];

	/**
	 * Check if Docket Cache is active.
	 *
	 * @return bool
	 */
	public function is_active(): bool {

		// Check if object-cache.php exists and is Docket Cache.
		$object_cache = WP_CONTENT_DIR . '/object-cache.php';

		if (! file_exists($object_cache)) {
			return false;
		}

		// Check if it's Docket Cache by looking for the signature.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents($object_cache, false, null, 0, 500);

		return false !== strpos($content, 'Docket Cache');
	}

	/**
	 * Get the Docket Cache data directory.
	 *
	 * @return string|null
	 */
	public function get_cache_path(): ?string {

		// Check common locations.
		$paths = [
			WP_CONTENT_DIR . '/cache/docket-cache',
			WP_CONTENT_DIR . '/docket-cache',
		];

		foreach ($paths as $path) {
			if (is_dir($path)) {
				return $path;
			}
		}

		// Check if defined.
		if (defined('DOCKET_CACHE_PATH')) {
			return DOCKET_CACHE_PATH;
		}

		return null;
	}

	/**
	 * Get all network IDs in a multisite installation.
	 *
	 * @return array<int>
	 */
	public function get_network_ids(): array {

		if (! is_multisite()) {
			return [1];
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$networks = $wpdb->get_col("SELECT id FROM {$wpdb->site} ORDER BY id");

		return array_map('intval', $networks);
	}

	/**
	 * Get all site IDs for a network.
	 *
	 * @param int $network_id Network ID.
	 * @return array<int>
	 */
	public function get_site_ids(int $network_id = 1): array {

		if (! is_multisite()) {
			return [1];
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sites = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT blog_id FROM {$wpdb->blogs} WHERE site_id = %d AND deleted = 0 AND archived = 0 ORDER BY blog_id",
				$network_id
			)
		);

		return array_map('intval', $sites);
	}

	/**
	 * Analyze cache files to find the most frequently accessed keys.
	 *
	 * @param int $limit Maximum number of keys to return per group.
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public function analyze_cache_usage(int $limit = 20): array {

		$cache_path = $this->get_cache_path();

		if (! $cache_path || ! is_dir($cache_path)) {
			return [];
		}

		$results = [
			'global' => [],
			'sites'  => [],
		];

		// Scan cache files.
		$files = glob($cache_path . '/*.php');

		if (empty($files)) {
			return $results;
		}

		$cache_data = [];

		foreach ($files as $file) {
			$data = $this->parse_cache_file($file);

			if (! $data) {
				continue;
			}

			$group      = $data['group'] ?? 'default';
			$key        = $data['key'] ?? '';
			$network_id = $data['network_id'] ?? 1;
			$site_id    = $data['site_id'] ?? 1;

			// Skip expired entries.
			if (isset($data['timeout']) && $data['timeout'] > 0 && $data['timeout'] < time()) {
				continue;
			}

			// Categorize by global vs site-specific.
			if (in_array($group, self::GLOBAL_CACHE_GROUPS, true)) {
				if (! isset($cache_data['global'][ $group ])) {
					$cache_data['global'][ $group ] = [];
				}
				$cache_data['global'][ $group ][ $key ] = [
					'file'       => $file,
					'network_id' => $network_id,
					'site_id'    => $site_id,
					'size'       => filesize($file),
					'mtime'      => filemtime($file),
				];
			} else {
				$site_key = "{$network_id}:{$site_id}";
				if (! isset($cache_data['sites'][ $site_key ][ $group ])) {
					$cache_data['sites'][ $site_key ][ $group ] = [];
				}
				$cache_data['sites'][ $site_key ][ $group ][ $key ] = [
					'file'       => $file,
					'network_id' => $network_id,
					'site_id'    => $site_id,
					'size'       => filesize($file),
					'mtime'      => filemtime($file),
				];
			}
		}

		// Sort and limit results.
		foreach ($cache_data['global'] as $group => $keys) {
			// Sort by modification time (most recent first).
			uasort(
				$keys,
				function ($a, $b) {
					return $b['mtime'] - $a['mtime'];
				}
			);
			$results['global'][ $group ] = array_slice($keys, 0, $limit, true);
		}

		foreach ($cache_data['sites'] as $site_key => $groups) {
			foreach ($groups as $group => $keys) {
				uasort(
					$keys,
					function ($a, $b) {
						return $b['mtime'] - $a['mtime'];
					}
				);
				$results['sites'][ $site_key ][ $group ] = array_slice($keys, 0, $limit, true);
			}
		}

		return $results;
	}

	/**
	 * Parse a Docket Cache file.
	 *
	 * @param string $file Path to cache file.
	 * @return array<string, mixed>|null
	 */
	private function parse_cache_file(string $file): ?array {

		if (! file_exists($file) || ! is_readable($file)) {
			return null;
		}

		// Docket Cache files return an array.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$data = @include $file;

		if (! is_array($data)) {
			return null;
		}

		return $data;
	}

	/**
	 * Get the most important cache keys to preload.
	 *
	 * These are keys that are accessed on almost every page load.
	 *
	 * @return array<string, array<string>>
	 */
	public function get_essential_cache_keys(): array {

		return [
			// Global options that are loaded on every request.
			'options'      => [
				'siteurl',
				'home',
				'blogname',
				'blogdescription',
				'users_can_register',
				'admin_email',
				'start_of_week',
				'use_balanceTags',
				'use_smilies',
				'require_name_email',
				'comments_notify',
				'posts_per_rss',
				'rss_use_excerpt',
				'mailserver_url',
				'mailserver_login',
				'mailserver_pass',
				'mailserver_port',
				'default_category',
				'default_comment_status',
				'default_ping_status',
				'default_pingback_flag',
				'posts_per_page',
				'date_format',
				'time_format',
				'links_updated_date_format',
				'comment_moderation',
				'moderation_notify',
				'permalink_structure',
				'rewrite_rules',
				'hack_file',
				'blog_charset',
				'moderation_keys',
				'active_plugins',
				'category_base',
				'ping_sites',
				'comment_max_links',
				'gmt_offset',
				'default_email_category',
				'recently_edited',
				'template',
				'stylesheet',
				'comment_registration',
				'html_type',
				'use_trackback',
				'default_role',
				'db_version',
				'uploads_use_yearmonth_folders',
				'upload_path',
				'blog_public',
				'default_link_category',
				'show_on_front',
				'tag_base',
				'show_avatars',
				'avatar_rating',
				'upload_url_path',
				'thumbnail_size_w',
				'thumbnail_size_h',
				'thumbnail_crop',
				'medium_size_w',
				'medium_size_h',
				'avatar_default',
				'large_size_w',
				'large_size_h',
				'image_default_link_type',
				'image_default_size',
				'image_default_align',
				'close_comments_for_old_posts',
				'close_comments_days_old',
				'thread_comments',
				'thread_comments_depth',
				'page_comments',
				'comments_per_page',
				'default_comments_page',
				'comment_order',
				'sticky_posts',
				'widget_categories',
				'widget_text',
				'widget_rss',
				'uninstall_plugins',
				'timezone_string',
				'page_for_posts',
				'page_on_front',
				'default_post_format',
				'link_manager_enabled',
				'finished_splitting_shared_terms',
				'site_icon',
				'medium_large_size_w',
				'medium_large_size_h',
				'wp_page_for_privacy_policy',
				'show_comments_cookies_opt_in',
				'admin_email_lifespan',
				'disallowed_keys',
				'comment_previously_approved',
				'auto_plugin_theme_update_emails',
				'auto_update_core_dev',
				'auto_update_core_minor',
				'auto_update_core_major',
				'wp_force_deactivated_plugins',
				'wp_attachment_pages_enabled',
				'WPLANG',
				'new_admin_email',
			],
			// Site meta for multisite.
			'site-options' => [
				'site_name',
				'admin_email',
				'admin_user_id',
				'registration',
				'upload_filetypes',
				'blog_upload_space',
				'fileupload_maxk',
				'site_admins',
				'allowedthemes',
				'illegal_names',
				'wpmu_upgrade_site',
				'welcome_email',
				'first_post',
				'siteurl',
				'add_new_users',
				'upload_space_check_disabled',
				'subdomain_install',
				'ms_files_rewriting',
				'user_count',
				'blog_count',
				'active_sitewide_plugins',
				'menu_items',
				'initial_db_version',
				'WPLANG',
			],
		];
	}

	/**
	 * Generate preload code for Docket Cache.
	 *
	 * @param array<string, mixed> $options Options for code generation.
	 * @return string
	 */
	public function generate_preload_code(array $options = []): string {

		$defaults = [
			'warmup_global'   => true,
			'warmup_sites'    => true,
			'max_sites'       => 10,
			'max_keys'        => 50,
			'include_options' => true,
		];

		$options = array_merge($defaults, $options);

		$code = <<<'PHP'

/**
 * Docket Cache Warmup
 *
 * Initialize the object cache and preload frequently accessed keys.
 * This runs during OPcache preloading to warm the cache.
 */
if (file_exists(ABSPATH . 'wp-content/object-cache.php')) {
    // Load WordPress core functions needed for object cache.
    if (!function_exists('wp_cache_init')) {
        require_once ABSPATH . 'wp-includes/cache.php';
    }
    
    // Initialize the object cache.
    require_once ABSPATH . 'wp-content/object-cache.php';
    
    if (function_exists('wp_cache_init')) {
        wp_cache_init();
        
        // Preload essential cache keys.
        // These are the most commonly accessed keys across all sites.
        $essential_keys = __ESSENTIAL_KEYS__;
        
        foreach ($essential_keys as $group => $keys) {
            foreach ($keys as $key) {
                wp_cache_get($key, $group);
            }
        }
    }
}

PHP;

		// Get essential keys.
		$essential_keys = $this->get_essential_cache_keys();

		// Limit keys if needed.
		if ($options['max_keys'] > 0) {
			foreach ($essential_keys as $group => $keys) {
				$essential_keys[ $group ] = array_slice($keys, 0, $options['max_keys']);
			}
		}

		// Replace placeholder with actual keys.
		$keys_export = var_export($essential_keys, true);
		$code        = str_replace('__ESSENTIAL_KEYS__', $keys_export, $code);

		return $code;
	}

	/**
	 * Get exclude patterns for cache directories.
	 *
	 * @return array<string>
	 */
	public function get_cache_exclude_patterns(): array {

		return [
			'*/cache/*',
			'*/docket-cache/*',
			'*/docket-cache-data/*',
			'*/wp-content/cache/*',
			'*/object-cache.php',
			'*/.opcache_test_token',
		];
	}
}
