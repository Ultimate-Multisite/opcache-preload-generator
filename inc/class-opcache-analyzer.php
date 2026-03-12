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
	 * Get high-hit scripts using automatic cutoff detection.
	 *
	 * This method finds files that are loaded on almost every request by using
	 * a reference WordPress core file as the threshold. Any file with hits
	 * close to the reference file's hits is considered a high-hit file.
	 *
	 * The algorithm:
	 * 1. Filter to WordPress files only (files within ABSPATH)
	 * 2. Find a reference file (e.g., class-wp-hook.php) that's loaded on every request
	 * 3. Use a percentage of its hit count as the threshold (default 50%)
	 * 4. Return all files with hits >= threshold
	 *
	 * @param float $threshold_ratio Ratio of reference file hits to use as threshold (default 0.5 = 50%).
	 * @param int   $max_files       Maximum number of files to return (default 500).
	 * @return array{scripts: array<int, array<string, mixed>>, cutoff_hits: int, total_scripts: int, reason: string, reference_file: string, reference_hits: int}
	 */
	public function get_high_hit_scripts(
		float $threshold_ratio = 0.7,
		int $max_files = 5000
	): array {

		$all_scripts = $this->get_scripts_by_hits(0);

		// Filter to WordPress files only.
		$all_scripts = $this->filter_wordpress_scripts($all_scripts);

		if (empty($all_scripts)) {
			return [
				'scripts'        => [],
				'cutoff_hits'    => 0,
				'total_scripts'  => 0,
				'reason'         => 'no_scripts',
				'reference_file' => '',
				'reference_hits' => 0,
			];
		}

		$total_scripts = count($all_scripts);

		// Find a reference file - a WordPress core file that's loaded on every request.
		// We use path suffixes (not bare basenames) to avoid matching plugin/theme
		// files with the same name (e.g., plugin.php, functions.php, load.php).
		// l10n.php is the primary reference because its hit count is representative
		// of "loaded on every request" without being an outlier like class-wp-hook.php.
		$reference_files = [
			'wp-includes/l10n.php',               // Localization - loaded on every request.
			'wp-includes/option.php',             // Options API.
			'wp-includes/formatting.php',         // Formatting functions.
			'wp-includes/class-wp.php',           // Main WP class.
			'wp-includes/class-wp-query.php',     // Query class.
			'wp-includes/load.php',               // Core loader.
			'wp-includes/plugin.php',             // Plugin API.
			'wp-includes/functions.php',          // Core functions.
		];

		$reference_hits = 0;
		$reference_file = '';

		// Find the first reference file (in priority order) that exists in the cache.
		// Outer loop is the reference list so l10n.php is checked before fallbacks.
		foreach ($reference_files as $ref_suffix) {
			foreach ($all_scripts as $script) {
				$path = $script['full_path'] ?? '';
				if (substr($path, -strlen($ref_suffix)) === $ref_suffix) {
					$reference_hits = $script['hits'] ?? 0;
					$reference_file = $ref_suffix;
					break 2;
				}
			}
		}

		// If no reference file found, fall back to using the median hit count.
		if (0 === $reference_hits) {
			$hits_array = array_column($all_scripts, 'hits');
			sort($hits_array);
			$median_index   = (int) floor(count($hits_array) / 2);
			$reference_hits = $hits_array[ $median_index ] ?? 0;
			$reference_file = 'median';
		}

		if (0 === $reference_hits) {
			return [
				'scripts'        => [],
				'cutoff_hits'    => 0,
				'total_scripts'  => $total_scripts,
				'reason'         => 'no_hits',
				'reference_file' => $reference_file,
				'reference_hits' => 0,
			];
		}

		// Calculate threshold as a percentage of reference hits.
		$cutoff_hits = (int) ($reference_hits * $threshold_ratio);

		// Select all files with hits >= threshold.
		$selected = [];
		foreach ($all_scripts as $script) {
			$hits = $script['hits'] ?? 0;

			if ($hits >= $cutoff_hits) {
				$selected[] = $script;

				if (count($selected) >= $max_files) {
					break;
				}
			}
		}

		return [
			'scripts'        => $selected,
			'cutoff_hits'    => $cutoff_hits,
			'total_scripts'  => $total_scripts,
			'reason'         => 'reference_threshold',
			'reference_file' => $reference_file,
			'reference_hits' => $reference_hits,
		];
	}

	/**
	 * Analyze hit distribution and provide statistics.
	 *
	 * Useful for understanding the hit distribution and tuning auto-detection.
	 *
	 * @return array{max_hits: int, min_hits: int, avg_hits: float, median_hits: int, std_dev: float, total_scripts: int, percentiles: array<int, int>}
	 */
	public function get_hit_distribution_stats(): array {

		$scripts = $this->get_scripts_by_hits(0);

		if (empty($scripts)) {
			return [
				'max_hits'      => 0,
				'min_hits'      => 0,
				'avg_hits'      => 0.0,
				'median_hits'   => 0,
				'std_dev'       => 0.0,
				'total_scripts' => 0,
				'percentiles'   => [],
			];
		}

		$hits  = array_column($scripts, 'hits');
		$count = count($hits);

		// Basic stats.
		$max = max($hits);
		$min = min($hits);
		$avg = array_sum($hits) / $count;

		// Median.
		sort($hits);
		$middle = (int) floor($count / 2);
		$median = (0 === $count % 2)
			? (int) (($hits[ $middle - 1 ] + $hits[ $middle ]) / 2)
			: $hits[ $middle ];

		// Standard deviation.
		$variance = 0;
		foreach ($hits as $hit) {
			$variance += pow($hit - $avg, 2);
		}
		$std_dev = sqrt($variance / $count);

		// Percentiles (10th, 25th, 50th, 75th, 90th, 95th, 99th).
		$percentiles = [];
		foreach ([10, 25, 50, 75, 90, 95, 99] as $p) {
			$index             = (int) floor(($p / 100) * ($count - 1));
			$percentiles[ $p ] = $hits[ $count - 1 - $index ]; // Reverse because sorted ascending.
		}

		return [
			'max_hits'      => $max,
			'min_hits'      => $min,
			'avg_hits'      => round($avg, 2),
			'median_hits'   => $median,
			'std_dev'       => round($std_dev, 2),
			'total_scripts' => $count,
			'percentiles'   => $percentiles,
		];
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
