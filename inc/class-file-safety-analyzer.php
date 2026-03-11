<?php
/**
 * File Safety Analyzer class.
 *
 * @package OPcache_Preload_Generator
 */

namespace OPcache_Preload_Generator;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

/**
 * Analyzes PHP files for preload safety issues.
 *
 * This analyzer is intentionally permissive because:
 * 1. Files are tested during optimization and errors are caught
 * 2. Files that fail with require_once fall back to opcache_compile_file()
 * 3. opcache_compile_file() compiles without executing, so side effects don't matter
 *
 * We only block files that would cause issues even with opcache_compile_file().
 */
class File_Safety_Analyzer {

	/**
	 * List of known problematic WordPress core files.
	 *
	 * These files have issues that can't be worked around:
	 * - Bootstrap files that must run in a specific order
	 * - Files with syntax that confuses the preloader
	 *
	 * @var array<string>
	 */
	private array $blacklisted_files = [
		// Core bootstrap files - must run in specific order during WordPress init.
		'wp-settings.php',
		'wp-config.php',
		'wp-load.php',
	];

	/**
	 * Analyze a file for preload safety.
	 *
	 * @param string $path Full path to the file.
	 * @return array{safe: bool, warnings: array<string>, errors: array<string>, dependencies: array<string>}
	 */
	public function analyze_file(string $path): array {

		$result = [
			'safe'         => true,
			'warnings'     => [],
			'errors'       => [],
			'dependencies' => [],
		];

		// Check if file exists.
		if (! file_exists($path)) {
			$result['safe']     = false;
			$result['errors'][] = __('File does not exist.', 'opcache-preload-generator');

			return $result;
		}

		// Check if file is readable.
		if (! is_readable($path)) {
			$result['safe']     = false;
			$result['errors'][] = __('File is not readable.', 'opcache-preload-generator');

			return $result;
		}

		// Check blacklist.
		$blacklist_result = $this->check_blacklist($path);
		if ($blacklist_result) {
			$result['safe']     = false;
			$result['errors'][] = $blacklist_result;

			return $result;
		}

		// Read file content for dependency extraction.
		$content = file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if (false === $content) {
			$result['safe']     = false;
			$result['errors'][] = __('Could not read file content.', 'opcache-preload-generator');

			return $result;
		}

		// Check for PHP opening tag.
		if (false === strpos($content, '<?php') && false === strpos($content, '<?=')) {
			$result['safe']     = false;
			$result['errors'][] = __('File does not appear to be a PHP file.', 'opcache-preload-generator');

			return $result;
		}

		// Extract dependencies for ordering (extends, implements, use).
		$this->check_dependencies($content, $result);

		return $result;
	}

	/**
	 * Check if file is blacklisted.
	 *
	 * @param string $path File path.
	 * @return string|null Error message if blacklisted, null otherwise.
	 */
	private function check_blacklist(string $path): ?string {

		$filename = basename($path);

		if (in_array($filename, $this->blacklisted_files, true)) {
			return sprintf(
				/* translators: %s: filename */
				__('File "%s" is blacklisted as it is known to cause issues with preloading.', 'opcache-preload-generator'),
				$filename
			);
		}

		return null;
	}

	/**
	 * Check for class dependencies (extends, implements, use).
	 *
	 * @param string                                                                                         $content File content.
	 * @param array{safe: bool, warnings: array<string>, errors: array<string>, dependencies: array<string>} $result  Result array (modified by reference).
	 * @return void
	 */
	private function check_dependencies(string $content, array &$result): void {

		// Extract extends clauses.
		if (preg_match_all('/class\s+\w+\s+extends\s+([\w\\\\]+)/', $content, $matches)) {
			foreach ($matches[1] as $parent) {
				if ('stdClass' !== $parent) {
					$result['dependencies'][] = $parent;
				}
			}
		}

		// Extract implements clauses.
		if (preg_match_all('/class\s+\w+(?:\s+extends\s+[\w\\\\]+)?\s+implements\s+([\w\\\\,\s]+)/', $content, $matches)) {
			foreach ($matches[1] as $interfaces) {
				$interface_list = preg_split('/\s*,\s*/', $interfaces);
				foreach ($interface_list as $interface) {
					$interface = trim($interface);
					if (! empty($interface)) {
						$result['dependencies'][] = $interface;
					}
				}
			}
		}

		// Extract use statements (for traits).
		if (preg_match_all('/\buse\s+([\w\\\\]+)\s*;/', $content, $matches)) {
			foreach ($matches[1] as $trait) {
				// Skip namespace use statements (those at the top of file).
				if (strpos($trait, '\\') !== false || ! preg_match('/^[A-Z]/', $trait)) {
					$result['dependencies'][] = $trait;
				}
			}
		}

		$result['dependencies'] = array_unique($result['dependencies']);
	}

	/**
	 * Quick check if a file is safe for preloading.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	public function is_safe(string $path): bool {

		$result = $this->analyze_file($path);

		return $result['safe'] && empty($result['errors']);
	}

	/**
	 * Get the list of blacklisted files.
	 *
	 * @return array<string>
	 */
	public function get_blacklisted_files(): array {

		return $this->blacklisted_files;
	}

	/**
	 * Add a file to the blacklist.
	 *
	 * @param string $filename Filename to blacklist.
	 * @return void
	 */
	public function add_to_blacklist(string $filename): void {

		if (! in_array($filename, $this->blacklisted_files, true)) {
			$this->blacklisted_files[] = $filename;
		}
	}

	/**
	 * Analyze multiple files.
	 *
	 * @param array<string> $paths Array of file paths.
	 * @return array<string, array{safe: bool, warnings: array<string>, errors: array<string>, dependencies: array<string>}>
	 */
	public function analyze_files(array $paths): array {

		$results = [];

		foreach ($paths as $path) {
			$results[ $path ] = $this->analyze_file($path);
		}

		return $results;
	}

	/**
	 * Filter files to only safe ones.
	 *
	 * @param array<string> $paths       Array of file paths.
	 * @param bool          $allow_warnings Whether to allow files with warnings.
	 * @return array<string>
	 */
	public function filter_safe_files(array $paths, bool $allow_warnings = true): array {

		$safe_files = [];

		foreach ($paths as $path) {
			$result = $this->analyze_file($path);

			if ($result['safe'] && empty($result['errors'])) {
				if ($allow_warnings || empty($result['warnings'])) {
					$safe_files[] = $path;
				}
			}
		}

		return $safe_files;
	}
}
