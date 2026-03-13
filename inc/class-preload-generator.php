<?php
/**
 * Preload Generator class.
 *
 * @package OPcache_Preload_Generator
 */

namespace OPcache_Preload_Generator;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

/**
 * Generates preload.php files for OPcache.
 */
class Preload_Generator {

	/**
	 * File safety analyzer instance.
	 *
	 * @var File_Safety_Analyzer
	 */
	private File_Safety_Analyzer $safety_analyzer;

	/**
	 * Dependency resolver instance.
	 *
	 * @var Dependency_Resolver
	 */
	private Dependency_Resolver $dependency_resolver;

	/**
	 * Files skipped during last generation due to unresolved dependencies.
	 *
	 * @var array<array{path: string, dependencies: array<string>}>
	 */
	private array $skipped_files = [];

	/**
	 * Constructor.
	 *
	 * @param File_Safety_Analyzer $safety_analyzer     File safety analyzer instance.
	 * @param Dependency_Resolver  $dependency_resolver Dependency resolver instance.
	 */
	public function __construct(File_Safety_Analyzer $safety_analyzer, Dependency_Resolver $dependency_resolver) {

		$this->safety_analyzer     = $safety_analyzer;
		$this->dependency_resolver = $dependency_resolver;
	}

	/**
	 * Generate the preload.php file content.
	 *
	 * @param array<string|array<string, mixed>> $files   Array of file paths or file config arrays.
	 * @param array<string, mixed>               $options Generation options.
	 * @return string Generated file content.
	 */
	public function generate(array $files, array $options = []): string {

		$defaults = [
			'use_require'          => true,
			'include_header'       => true,
			'validate_files'       => true,
			'output_path'          => '',
			'abspath'              => ABSPATH,
			'resolve_dependencies' => true,
		];

		$options = wp_parse_args($options, $defaults);

		// Sort files by dependencies and handle unresolvable ones.
		if ($options['resolve_dependencies']) {
			$files = $this->sort_files_by_dependencies($files, $options);
		}

		$content = '';

		if ($options['include_header']) {
			$content .= $this->generate_header($files, $options);
		}

		$content .= $this->generate_safety_checks($options);
		$content .= $this->generate_file_includes($files, $options);

		return $content;
	}

	/**
	 * Sort files by dependency order and skip unresolvable files.
	 *
	 * All files use opcache_compile_file (compile-only, no execution) to avoid
	 * dependency ordering issues that require_once would introduce.
	 *
	 * @param array<string|array<string, mixed>> $files   Array of file paths or file config arrays.
	 * @param array<string, mixed>               $options Generation options.
	 * @return array<array<string, mixed>> Sorted files with method set.
	 */
	private function sort_files_by_dependencies(array $files, array $options): array {

		// Normalize files to config array format and extract paths.
		$normalized = [];
		$paths      = [];

		foreach ($files as $file) {
			if (is_array($file)) {
				$path = $file['path'] ?? '';
			} else {
				$path = $file;
			}

			if (empty($path) || ! file_exists($path)) {
				continue;
			}

			$paths[]      = $path;
			$normalized[] = [
				'path'   => $path,
				'method' => 'opcache_compile_file',
			];
		}

		if (empty($paths)) {
			return [];
		}

		// Build class map for all files.
		$this->dependency_resolver->clear_cache();
		$this->dependency_resolver->build_class_map($paths);

		// Sort by dependencies.
		$sorted = $this->dependency_resolver->sort_by_dependencies($paths);

		// Build result with proper method assignment.
		$result       = [];
		$sorted_paths = $sorted['sorted'];
		$unresolvable = $sorted['unresolvable'];

		// Create a lookup for original method settings.
		$method_lookup = [];
		foreach ($normalized as $file) {
			$method_lookup[ $file['path'] ] = $file['method'];
		}

		// Reset skipped files tracking.
		$this->skipped_files = [];

		// Determine method per file. require_once is used when BOTH conditions hold:
		// 1. File has only declarations (no side effects) — checked by tokenizer.
		// 2. All dependencies are resolved — extends/implements/use-trait targets are
		// either PHP/WP built-ins or files earlier in the sorted preload list.
		// This makes the class/interface/function globally available from PHP start,
		// with zero per-request loading cost.
		//
		// opcache_compile_file is used for everything else. It compiles bytecode into
		// the opcode cache (faster loading) but doesn't define symbols globally —
		// the file still needs to be required at runtime. It handles missing parent
		// classes gracefully (non-fatal "Can't preload unlinked class" warning).
		$resolved_paths = [];

		foreach ($sorted_paths as $path) {
			$unresolved = $this->dependency_resolver->get_unresolved_dependencies($path, $sorted_paths);
			$deps = $this->dependency_resolver->get_file_dependencies($path);

			if (! empty($unresolved)) {
				$this->skipped_files[] = [
					'path'         => $path,
					'reason'       => 'unresolved_dependencies',
					'dependencies' => $unresolved,
				];
			}

			// Use require_once only when safe: no side effects AND all deps resolved.
			if (empty($unresolved)) {
				$method = 'require_once';
				// Check the file itself and all its dependencies for side effects.
				$files_to_check = [$path];
				foreach ($deps as $dep_class) {
					$dep_file = $this->dependency_resolver->get_class_file($dep_class);
					if ($dep_file) {
						$files_to_check[] = $dep_file;
					}
				}
				foreach ($files_to_check as $check_path) {
					if ($this->safety_analyzer->get_recommended_method($check_path) !== 'require_once') {
						$method = 'opcache_compile_file';
						break;
					}
				}
			} else {
				$method = 'opcache_compile_file';
			}

			$result[]         = [
				'path'   => $path,
				'method' => $method,
			];
			$resolved_paths[] = $path;
		}

		// Include unresolvable files too (circular dependencies).
		// opcache_compile_file handles these gracefully.
		foreach ($unresolvable as $path) {
			$this->skipped_files[] = [
				'path'         => $path,
				'reason'       => 'circular_dependency',
				'dependencies' => [],
			];

			$result[] = [
				'path'   => $path,
				'method' => 'opcache_compile_file',
			];
		}

		return $result;
	}

	/**
	 * Get files that were skipped during the last generation.
	 *
	 * @return array<array{path: string, reason: string, dependencies: array<string>}>
	 */
	public function get_skipped_files(): array {

		return $this->skipped_files;
	}

	/**
	 * Generate the file header with metadata.
	 *
	 * @param array<string>        $files   Array of file paths.
	 * @param array<string, mixed> $options Generation options.
	 * @return string
	 */
	private function generate_header(array $files, array $options): string {

		$file_count  = count($files);
		$timestamp   = gmdate('Y-m-d H:i:s');
		$output_path = $options['output_path'] ?? '';
		$abspath     = $options['abspath'] ?? ABSPATH;

		// Count methods for header metadata.
		$require_count = 0;
		$compile_count = 0;
		foreach ($files as $file) {
			$method = is_array($file) ? ($file['method'] ?? 'opcache_compile_file') : 'opcache_compile_file';
			if ('require_once' === $method) {
				++$require_count;
			} else {
				++$compile_count;
			}
		}
		$method_summary = "{$require_count} require_once, {$compile_count} opcache_compile_file";

		$header = <<<PHP
<?php
/**
 * OPcache Preload File
 *
 * Generated by OPcache Preload Generator for WordPress
 * Generated: {$timestamp} UTC
 * Expected Path: {$output_path}
 * ABSPATH: {$abspath}
 *
 * Files included: {$file_count}
 * Methods: {$method_summary}
 *
 * INSTRUCTIONS:
 * 1. Copy this file to your WordPress root directory (or a location of your choice)
 * 2. Add the following to your php.ini:
 *    opcache.preload=/path/to/this/preload.php
 *    opcache.preload_user=www-data  (or your web server user)
 * 3. Restart PHP-FPM or your web server
 *
 * IMPORTANT NOTES:
 * - Preloading happens once when PHP starts, not on every request
 * - Changes to preloaded files require a PHP-FPM restart to take effect
 * - If any file causes an error during preload, PHP will fail to start
 * - Always test in a staging environment first
 * - Do NOT move this file after generation - regenerate instead
 *
 * @generated by OPcache Preload Generator
 */


PHP;

		return $header;
	}

	/**
	 * Generate PHP version check and safety checks.
	 *
	 * @param array<string, mixed> $options Generation options.
	 * @return string
	 */
	private function generate_safety_checks(array $options): string {

		$output_path      = addslashes($options['output_path'] ?? '');
		$abspath          = addslashes($options['abspath'] ?? ABSPATH);
		$abspath_no_slash = rtrim($abspath, '/\\');

		return <<<PHP
// Require PHP 7.4 or higher for preloading support.
if (PHP_VERSION_ID < 70400) {
	return;
}

// Verify this file has not been moved from its expected location.
// If moved, paths may be incorrect. Regenerate the preload file instead.
\$expected_path = '{$output_path}';
if (__FILE__ !== \$expected_path && realpath(__FILE__) !== realpath(\$expected_path)) {
	error_log('OPcache Preload: File has been moved from expected location. Expected: ' . \$expected_path . ', Actual: ' . __FILE__);
	return;
}

// Define ABSPATH if not already defined.
// This allows WordPress files with "defined('ABSPATH') || exit" to load properly.
if (!defined('ABSPATH')) {
	define('ABSPATH', '{$abspath}');
}

PHP;
	}

	/**
	 * Generate the file includes section.
	 *
	 * Files are grouped by preload method:
	 *
	 * - require_once: Pure declaration files (class/interface/trait/enum) with all
	 *   dependencies resolved. Executes the file, making symbols globally available
	 *   from PHP start — zero per-request loading cost. Dependency-sorted so parent
	 *   classes/interfaces are loaded before children.
	 *
	 * - opcache_compile_file: Everything else. Compiles bytecode into the opcode
	 *   cache without executing. Handles missing parent classes gracefully (non-fatal
	 *   warning). The file still needs to be required at runtime, but loads faster
	 *   from the opcode cache.
	 *
	 * @param array<string|array<string, mixed>> $files   Array of file paths or file config arrays.
	 * @param array<string, mixed>               $options Generation options.
	 * @return string
	 */
	private function generate_file_includes(array $files, array $options): string {

		$content = '';

		// Group files by method for readable output.
		$require_files = [];
		$compile_files = [];

		foreach ($files as $file) {
			if (is_array($file)) {
				$path   = $file['path'] ?? '';
				$method = $file['method'] ?? 'opcache_compile_file';
			} else {
				$path   = $file;
				$method = 'opcache_compile_file';
			}

			if (empty($path)) {
				continue;
			}

			if ('require_once' === $method) {
				$require_files[] = $path;
			} else {
				$compile_files[] = $path;
			}
		}

		// Emit require_once files first (dependency-sorted, symbols globally available).
		if (! empty($require_files)) {
			$content .= "// Preload via require_once — pure declarations, all dependencies resolved.\n";
			$content .= "// These classes/interfaces/functions are globally available from PHP start.\n";

			foreach ($require_files as $path) {
				$escaped_path = addslashes($path);

				if ($options['validate_files']) {
					$content .= "if (file_exists('{$escaped_path}')) {\n";
					$content .= "\trequire_once '{$escaped_path}';\n";
					$content .= "}\n";
				} else {
					$content .= "require_once '{$escaped_path}';\n";
				}
			}
		}

		// Emit opcache_compile_file files (bytecode cached, loaded faster at runtime).
		if (! empty($compile_files)) {
			if (! empty($require_files)) {
				$content .= "\n";
			}
			$content .= "// Preload via opcache_compile_file — bytecode cached, loaded faster at runtime.\n";

			foreach ($compile_files as $path) {
				$escaped_path = addslashes($path);

				if ($options['validate_files']) {
					$content .= "if (file_exists('{$escaped_path}')) {\n";
					$content .= "\topcache_compile_file('{$escaped_path}');\n";
					$content .= "}\n";
				} else {
					$content .= "opcache_compile_file('{$escaped_path}');\n";
				}
			}
		}

		return $content;
	}

	/**
	 * Get the first class, interface, or trait defined in a file.
	 *
	 * Uses the dependency resolver's parser to extract definitions.
	 * Returns the fully-qualified class name for use in class_exists() guards,
	 * or null if the file doesn't define any classes.
	 *
	 * @param string $path Full path to the PHP file.
	 * @return string|null First defined class name, or null.
	 */
	private function get_first_defined_class(string $path): ?string {

		$data = $this->dependency_resolver->parse_file($path);

		// Check classes first, then interfaces, then traits.
		if (! empty($data['classes'])) {
			return $data['classes'][0];
		}

		if (! empty($data['interfaces'])) {
			return $data['interfaces'][0];
		}

		if (! empty($data['traits'])) {
			return $data['traits'][0];
		}

		return null;
	}

	/**
	 * Write the preload file to disk.
	 *
	 * @param array<string>        $files   Array of file paths to preload.
	 * @param string               $output_path Path to write the file.
	 * @param array<string, mixed> $options Generation options.
	 * @return bool|string True on success, error message on failure.
	 */
	public function write_file(array $files, string $output_path, array $options = []) {

		// Validate output path.
		$dir = dirname($output_path);

		if (! is_dir($dir)) {
			return sprintf(
				/* translators: %s: directory path */
				__('Directory does not exist: %s', 'opcache-preload-generator'),
				$dir
			);
		}

		if (! wp_is_writable($dir)) {
			return sprintf(
				/* translators: %s: directory path */
				__('Directory is not writable: %s', 'opcache-preload-generator'),
				$dir
			);
		}

		// Generate content.
		$content = $this->generate($files, $options);

		// Write file.
		$result = file_put_contents($output_path, $content); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		if (false === $result) {
			return __('Failed to write preload file.', 'opcache-preload-generator');
		}

		return true;
	}

	/**
	 * Get information about an existing preload file.
	 *
	 * @param string $path Path to the preload file.
	 * @return array<string, mixed>|null
	 */
	public function get_preload_file_info(string $path): ?array {

		if (! file_exists($path)) {
			return null;
		}

		$content = file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if (false === $content) {
			return null;
		}

		// Extract metadata from header.
		$generated = null;
		if (preg_match('/Generated:\s*(.+)\s*UTC/', $content, $matches)) {
			$generated = $matches[1];
		}

		$file_count = null;
		if (preg_match('/Files included:\s*(\d+)/', $content, $matches)) {
			$file_count = (int) $matches[1];
		}

		$method = null;
		if (preg_match('/Method:\s*(\w+)/', $content, $matches)) {
			$method = $matches[1];
		}

		// Count actual file includes.
		$actual_count = 0;
		if (preg_match_all('/(?:require_once|opcache_compile_file)\s*\(/', $content, $matches)) {
			$actual_count = count($matches[0]);
		}

		return [
			'path'         => $path,
			'size'         => filesize($path),
			'modified'     => filemtime($path),
			'generated'    => $generated,
			'file_count'   => $file_count,
			'actual_count' => $actual_count,
			'method'       => $method,
			'readable'     => is_readable($path),
			'writable'     => wp_is_writable($path),
		];
	}

	/**
	 * Delete an existing preload file.
	 *
	 * @param string $path Path to the preload file.
	 * @return bool|string True on success, error message on failure.
	 */
	public function delete_preload_file(string $path) {

		if (! file_exists($path)) {
			return __('Preload file does not exist.', 'opcache-preload-generator');
		}

		if (! wp_is_writable($path)) {
			return __('Preload file is not writable.', 'opcache-preload-generator');
		}

		$result = wp_delete_file($path);

		// wp_delete_file doesn't return a value, check if file still exists.
		if (file_exists($path)) {
			return __('Failed to delete preload file.', 'opcache-preload-generator');
		}

		return true;
	}

	/**
	 * Preview the generated content without writing to disk.
	 *
	 * @param array<string>        $files   Array of file paths to preload.
	 * @param array<string, mixed> $options Generation options.
	 * @return string
	 */
	public function preview(array $files, array $options = []): string {

		return $this->generate($files, $options);
	}

	/**
	 * Validate files before generation.
	 *
	 * @param array<string> $files Array of file paths.
	 * @return array{valid: array<string>, invalid: array<string, string>}
	 */
	public function validate_files(array $files): array {

		$valid   = [];
		$invalid = [];

		foreach ($files as $file) {
			if (! file_exists($file)) {
				$invalid[ $file ] = __('File does not exist', 'opcache-preload-generator');
				continue;
			}

			if (! is_readable($file)) {
				$invalid[ $file ] = __('File is not readable', 'opcache-preload-generator');
				continue;
			}

			// Check for PHP extension.
			$ext = pathinfo($file, PATHINFO_EXTENSION);
			if (strtolower($ext) !== 'php') {
				$invalid[ $file ] = __('Not a PHP file', 'opcache-preload-generator');
				continue;
			}

			$valid[] = $file;
		}

		return [
			'valid'   => $valid,
			'invalid' => $invalid,
		];
	}

	/**
	 * Get suggested output path.
	 *
	 * @return string
	 */
	public function get_suggested_output_path(): string {

		return ABSPATH . 'preload.php';
	}

	/**
	 * Generate php.ini configuration snippet.
	 *
	 * @param string $preload_path Path to the preload file.
	 * @return string
	 */
	public function get_php_ini_config(string $preload_path): string {

		$user = $this->get_suggested_user();

		return <<<INI
; Add these lines to your php.ini file
opcache.preload={$preload_path}
opcache.preload_user={$user}
INI;
	}

	/**
	 * Get suggested preload user.
	 *
	 * @return string
	 */
	public function get_suggested_user(): string {

		// Try to detect web server user.
		if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
			$user_info = posix_getpwuid(posix_geteuid());
			if ($user_info && isset($user_info['name'])) {
				return $user_info['name'];
			}
		}

		// Common defaults.
		if (PHP_OS_FAMILY === 'Darwin') {
			return '_www';
		}

		return 'www-data';
	}
}
