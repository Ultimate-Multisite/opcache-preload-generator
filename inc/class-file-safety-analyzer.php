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
 */
class File_Safety_Analyzer {

	/**
	 * List of known problematic WordPress core files.
	 *
	 * @var array<string>
	 */
	private array $blacklisted_files = [
		'general-template.php',
		'link-template.php',
		'l10n.php',
		'class-simplepie.php',
		'class-snoopy.php',
		'class-json.php',
		'version.php',
		'load.php',
		'default-constants.php',
		'plugin.php',
		'option.php',
		'wp-db.php',
		'class-wp-locale.php',
	];

	/**
	 * List of functions that indicate side effects when called at top level.
	 *
	 * @var array<string>
	 */
	private array $side_effect_functions = [
		'echo',
		'print',
		'print_r',
		'var_dump',
		'var_export',
		'exit',
		'die',
		'header',
		'setcookie',
		'session_start',
		'ob_start',
		'ob_flush',
		'ob_end_flush',
		'error_reporting',
		'set_error_handler',
		'set_exception_handler',
		'register_shutdown_function',
		'wp_die',
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

		// Read file content.
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

		// Run all safety checks.
		$this->check_side_effects($content, $result);
		$this->check_define_calls($content, $result);
		$this->check_path_constants_in_properties($content, $result);
		$this->check_conditional_definitions($content, $result);
		$this->check_dependencies($content, $result);
		$this->check_global_code($content, $result);
		$this->check_require_include($content, $result);

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
	 * Check for side effects (code that executes on include).
	 *
	 * @param string                                                                                         $content File content.
	 * @param array{safe: bool, warnings: array<string>, errors: array<string>, dependencies: array<string>} $result  Result array (modified by reference).
	 * @return void
	 */
	private function check_side_effects(string $content, array &$result): void {

		// Look for echo/print outside functions/classes.
		$patterns = [
			'/^\s*echo\s+/m',
			'/^\s*print\s+/m',
			'/^\s*print_r\s*\(/m',
			'/^\s*var_dump\s*\(/m',
			'/^\s*header\s*\(/m',
		];

		// Simple heuristic: check if these appear at the start of a line (not indented inside function/class).
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $content)) {
				$result['warnings'][] = __('File may contain output statements (echo/print) at the top level.', 'opcache-preload-generator');
				break;
			}
		}

		// Check for exit/die at top level.
		if (preg_match('/^\s*(exit|die)\s*[;(]/m', $content)) {
			$result['warnings'][] = __('File contains exit/die statements that may execute during preload.', 'opcache-preload-generator');
		}
	}

	/**
	 * Check for define() calls that may conflict.
	 *
	 * @param string                                                                                         $content File content.
	 * @param array{safe: bool, warnings: array<string>, errors: array<string>, dependencies: array<string>} $result  Result array (modified by reference).
	 * @return void
	 */
	private function check_define_calls(string $content, array &$result): void {

		// Check for define calls outside functions.
		if (preg_match('/^\s*define\s*\(/m', $content)) {
			// Check if it's wrapped in if(!defined()).
			if (! preg_match('/if\s*\(\s*!\s*defined\s*\(/', $content)) {
				$result['warnings'][] = __('File contains define() calls without defined() checks, which may cause conflicts.', 'opcache-preload-generator');
			}
		}
	}

	/**
	 * Check for __DIR__/__FILE__ in class properties.
	 *
	 * @param string                                                                                         $content File content.
	 * @param array{safe: bool, warnings: array<string>, errors: array<string>, dependencies: array<string>} $result  Result array (modified by reference).
	 * @return void
	 */
	private function check_path_constants_in_properties(string $content, array &$result): void {

		// Pattern to detect class property assignments with __DIR__ or __FILE__.
		// Handles typed properties (PHP 7.4+) like: private string $path = __DIR__;
		$pattern = '/(?:private|protected|public|var)\s+(?:static\s+)?(?:[\?\w\\\\]+\s+)?\$\w+\s*=\s*[^;]*(__DIR__|__FILE__)/';

		if (preg_match($pattern, $content)) {
			$result['warnings'][] = __('File uses __DIR__ or __FILE__ in class property declarations. These will resolve to the preload file location instead of the original file location.', 'opcache-preload-generator');
		}
	}

	/**
	 * Check for conditional class/function definitions.
	 *
	 * @param string                                                                                         $content File content.
	 * @param array{safe: bool, warnings: array<string>, errors: array<string>, dependencies: array<string>} $result  Result array (modified by reference).
	 * @return void
	 */
	private function check_conditional_definitions(string $content, array &$result): void {

		// Check for if(!class_exists()) patterns.
		if (preg_match('/if\s*\(\s*!\s*class_exists\s*\(/', $content)) {
			$result['warnings'][] = __('File contains conditional class definitions (class_exists checks). These may not work as expected with preloading.', 'opcache-preload-generator');
		}

		// Check for if(!function_exists()) patterns.
		if (preg_match('/if\s*\(\s*!\s*function_exists\s*\(/', $content)) {
			$result['warnings'][] = __('File contains conditional function definitions (function_exists checks). These may not work as expected with preloading.', 'opcache-preload-generator');
		}

		// Check for if(!interface_exists()) patterns.
		if (preg_match('/if\s*\(\s*!\s*interface_exists\s*\(/', $content)) {
			$result['warnings'][] = __('File contains conditional interface definitions (interface_exists checks).', 'opcache-preload-generator');
		}
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
	 * Check for global code that executes on include.
	 *
	 * @param string                                                                                         $content File content.
	 * @param array{safe: bool, warnings: array<string>, errors: array<string>, dependencies: array<string>} $result  Result array (modified by reference).
	 * @return void
	 */
	private function check_global_code(string $content, array &$result): void {

		// Check for immediate function calls at top level.
		// This is a heuristic - we look for function calls not inside class/function definitions.
		$dangerous_patterns = [
			'/^\s*\$GLOBALS\s*\[/m',
			'/^\s*\$_SERVER\s*\[/m',
			'/^\s*\$_GET\s*\[/m',
			'/^\s*\$_POST\s*\[/m',
			'/^\s*\$_REQUEST\s*\[/m',
			'/^\s*\$_COOKIE\s*\[/m',
			'/^\s*add_action\s*\(/m',
			'/^\s*add_filter\s*\(/m',
			'/^\s*register_activation_hook\s*\(/m',
			'/^\s*register_deactivation_hook\s*\(/m',
		];

		foreach ($dangerous_patterns as $pattern) {
			if (preg_match($pattern, $content)) {
				$result['warnings'][] = __('File contains WordPress hooks or global variable access at the top level. This code will execute during preload when WordPress may not be fully loaded.', 'opcache-preload-generator');
				break;
			}
		}
	}

	/**
	 * Check for require/include statements.
	 *
	 * @param string                                                                                         $content File content.
	 * @param array{safe: bool, warnings: array<string>, errors: array<string>, dependencies: array<string>} $result  Result array (modified by reference).
	 * @return void
	 */
	private function check_require_include(string $content, array &$result): void {

		// Check for require/include at top level.
		$patterns = [
			'/^\s*require\s+/m',
			'/^\s*require_once\s+/m',
			'/^\s*include\s+/m',
			'/^\s*include_once\s+/m',
		];

		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $content)) {
				$result['warnings'][] = __('File contains require/include statements at the top level. These files will also be loaded during preload.', 'opcache-preload-generator');
				break;
			}
		}
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
