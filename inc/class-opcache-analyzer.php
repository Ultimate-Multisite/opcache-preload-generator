<?php
/**
 * OPcache Analyzer class.
 *
 * @package OPcache_Preload_Generator
 */

namespace OPcache_Preload_Generator;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

/**
 * Analyzes OPcache status and statistics.
 */
class OPcache_Analyzer {

	/**
	 * Check if OPcache is available and enabled.
	 *
	 * @return bool
	 */
	public function is_available(): bool {

		return function_exists('opcache_get_status') && opcache_get_status(false) !== false;
	}

	/**
	 * Get OPcache status.
	 *
	 * @param bool $include_scripts Whether to include script information.
	 * @return array<string, mixed>|false
	 */
	public function get_status(bool $include_scripts = true) {

		if (! $this->is_available()) {
			return false;
		}

		return opcache_get_status($include_scripts);
	}

	/**
	 * Get OPcache configuration.
	 *
	 * @return array<string, mixed>|false
	 */
	public function get_configuration() {

		if (! function_exists('opcache_get_configuration')) {
			return false;
		}

		return opcache_get_configuration();
	}

	/**
	 * Get all cached scripts.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_scripts(): array {

		$status = $this->get_status(true);

		if (! $status || ! isset($status['scripts'])) {
			return [];
		}

		return $status['scripts'];
	}

	/**
	 * Get cached scripts sorted by hit count.
	 *
	 * @param int $limit Maximum number of scripts to return (0 = unlimited).
	 * @return array<int, array<string, mixed>>
	 */
	public function get_scripts_by_hits(int $limit = 0): array {

		$scripts = $this->get_scripts();

		if (empty($scripts)) {
			return [];
		}

		// Convert to indexed array with full path as key in array.
		$indexed = [];
		foreach ($scripts as $path => $info) {
			$info['full_path'] = $path;
			$indexed[]         = $info;
		}

		// Sort by hits descending.
		usort(
			$indexed,
			function ($a, $b) {
				return ($b['hits'] ?? 0) <=> ($a['hits'] ?? 0);
			}
		);

		if ($limit > 0) {
			return array_slice($indexed, 0, $limit);
		}

		return $indexed;
	}

	/**
	 * Get cached scripts sorted by memory consumption.
	 *
	 * @param int $limit Maximum number of scripts to return (0 = unlimited).
	 * @return array<int, array<string, mixed>>
	 */
	public function get_scripts_by_memory(int $limit = 0): array {

		$scripts = $this->get_scripts();

		if (empty($scripts)) {
			return [];
		}

		// Convert to indexed array with full path as key in array.
		$indexed = [];
		foreach ($scripts as $path => $info) {
			$info['full_path'] = $path;
			$indexed[]         = $info;
		}

		// Sort by memory_consumption descending.
		usort(
			$indexed,
			function ($a, $b) {
				return ($b['memory_consumption'] ?? 0) <=> ($a['memory_consumption'] ?? 0);
			}
		);

		if ($limit > 0) {
			return array_slice($indexed, 0, $limit);
		}

		return $indexed;
	}

	/**
	 * Filter scripts to only include WordPress-related files.
	 *
	 * @param array<int, array<string, mixed>> $scripts Scripts array.
	 * @return array<int, array<string, mixed>>
	 */
	public function filter_wordpress_scripts(array $scripts): array {

		$abspath = realpath(ABSPATH);

		return array_filter(
			$scripts,
			function ($script) use ($abspath) {
				$path = $script['full_path'] ?? '';

				if (empty($path)) {
					return false;
				}

				$realpath = realpath($path);

				if (! $realpath) {
					return false;
				}

				return strpos($realpath, $abspath) === 0;
			}
		);
	}

	/**
	 * Exclude scripts matching patterns.
	 *
	 * @param array<int, array<string, mixed>> $scripts  Scripts array.
	 * @param array<string>                    $patterns Glob patterns to exclude.
	 * @return array<int, array<string, mixed>>
	 */
	public function exclude_patterns(array $scripts, array $patterns): array {

		if (empty($patterns)) {
			return $scripts;
		}

		return array_filter(
			$scripts,
			function ($script) use ($patterns) {
				$path = $script['full_path'] ?? '';

				foreach ($patterns as $pattern) {
					if (fnmatch($pattern, $path)) {
						return false;
					}
				}

				return true;
			}
		);
	}

	/**
	 * Get memory usage statistics.
	 *
	 * @return array<string, mixed>
	 */
	public function get_memory_stats(): array {

		$status = $this->get_status(false);

		if (! $status || ! isset($status['memory_usage'])) {
			return [
				'used'        => 0,
				'free'        => 0,
				'wasted'      => 0,
				'total'       => 0,
				'used_pct'    => 0,
				'wasted_pct'  => 0,
				'current_pct' => 0,
			];
		}

		$memory = $status['memory_usage'];
		$total  = ($memory['used_memory'] ?? 0) + ($memory['free_memory'] ?? 0);

		return [
			'used'        => $memory['used_memory'] ?? 0,
			'free'        => $memory['free_memory'] ?? 0,
			'wasted'      => $memory['wasted_memory'] ?? 0,
			'total'       => $total,
			'used_pct'    => $total > 0 ? round(($memory['used_memory'] / $total) * 100, 1) : 0,
			'wasted_pct'  => $total > 0 ? round(($memory['wasted_memory'] / $total) * 100, 1) : 0,
			'current_pct' => $memory['current_wasted_percentage'] ?? 0,
		];
	}

	/**
	 * Get cache statistics.
	 *
	 * @return array<string, mixed>
	 */
	public function get_cache_stats(): array {

		$status = $this->get_status(false);

		if (! $status || ! isset($status['opcache_statistics'])) {
			return [
				'num_cached_scripts' => 0,
				'num_cached_keys'    => 0,
				'max_cached_keys'    => 0,
				'hits'               => 0,
				'misses'             => 0,
				'hit_rate'           => 0,
				'start_time'         => 0,
				'last_restart_time'  => 0,
			];
		}

		$stats = $status['opcache_statistics'];

		return [
			'num_cached_scripts' => $stats['num_cached_scripts'] ?? 0,
			'num_cached_keys'    => $stats['num_cached_keys'] ?? 0,
			'max_cached_keys'    => $stats['max_cached_keys'] ?? 0,
			'hits'               => $stats['hits'] ?? 0,
			'misses'             => $stats['misses'] ?? 0,
			'hit_rate'           => $stats['opcache_hit_rate'] ?? 0,
			'start_time'         => $stats['start_time'] ?? 0,
			'last_restart_time'  => $stats['last_restart_time'] ?? 0,
		];
	}

	/**
	 * Check if preloading is enabled in configuration.
	 *
	 * @return bool
	 */
	public function is_preloading_enabled(): bool {

		$config = $this->get_configuration();

		if (! $config || ! isset($config['directives'])) {
			return false;
		}

		$preload = $config['directives']['opcache.preload'] ?? '';

		return ! empty($preload);
	}

	/**
	 * Get current preload file path from configuration.
	 *
	 * @return string
	 */
	public function get_preload_file(): string {

		$config = $this->get_configuration();

		if (! $config || ! isset($config['directives'])) {
			return '';
		}

		return $config['directives']['opcache.preload'] ?? '';
	}

	/**
	 * Get preload user from configuration.
	 *
	 * @return string
	 */
	public function get_preload_user(): string {

		$config = $this->get_configuration();

		if (! $config || ! isset($config['directives'])) {
			return '';
		}

		return $config['directives']['opcache.preload_user'] ?? '';
	}

	/**
	 * Get preloaded scripts if preloading is enabled.
	 *
	 * @return array<string>
	 */
	public function get_preloaded_scripts(): array {

		$status = $this->get_status(false);

		if (! $status || ! isset($status['preload_statistics'])) {
			return [];
		}

		return $status['preload_statistics']['scripts'] ?? [];
	}
}
