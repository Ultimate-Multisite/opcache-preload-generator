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
 * Preloading executes files at PHP startup, before WordPress bootstraps.
 * This means WordPress constants, functions, and many classes don't exist yet.
 * Files that reference these at load time (not just inside function bodies)
 * will cause fatal errors that prevent PHP from starting.
 *
 * The analyzer checks for:
 * 1. Blacklisted filenames known to cause issues
 * 2. Blacklisted path patterns (e.g., WP-CLI vendor files, test frameworks)
 * 3. Files that reference WordPress constants at the top level
 * 4. Files that call functions or instantiate classes at the top level
 * 5. Class dependency extraction for ordering (extends, implements, use)
 */
class File_Safety_Analyzer {

	/**
	 * List of known problematic WordPress core files.
	 *
	 * These files have issues that can't be worked around:
	 * - Bootstrap files that must run in a specific order
	 * - Files with conditional function definitions that conflict with WordPress
	 * - Files that depend on constants defined during bootstrap
	 *
	 * @var array<string>
	 */
	private array $blacklisted_files = [
		// Core bootstrap files - must run in specific order during WordPress init.
		'wp-settings.php',
		'wp-config.php',
		'wp-load.php',

		// Pluggable functions - depends on constants (SECURE_AUTH_COOKIE, etc.)
		// defined during WordPress bootstrap. Functions are conditionally defined
		// and conflict with WordPress's own loading of this file.
		'pluggable.php',

		// Other core files with bootstrap dependencies.
		'pluggable-deprecated.php',
		'ms-settings.php',
		'ms-load.php',
		'default-constants.php',
		'default-filters.php',

		// Multisite sunrise file - loaded very early by ms-settings.php,
		// defines functions at top level that conflict on re-include.
		'sunrise.php',
	];

	/**
	 * Path patterns that indicate files unsafe for preloading.
	 *
	 * These patterns match directory structures known to contain files
	 * with runtime dependencies that don't exist at preload time.
	 *
	 * @var array<string, string>
	 */
	private array $blacklisted_path_patterns = [
		// WordPress core bootstrap files with generic names.
		// These are matched by path to avoid blocking plugin files with the
		// same basename (e.g., docket-cache/includes/load.php is fine).
		'wp-load'           => '/wp-includes/load.php',
		'wp-vars'           => '/wp-includes/vars.php',

		// WP-CLI files depend on WP_CLI\Runner and other WP-CLI classes
		// that are only available when running via the wp command.
		'wp-cli'            => '/vendor/wp-cli/',

		// Test framework files should never be preloaded.
		'phpunit'           => '/vendor/phpunit/',
		'codeception'       => '/vendor/codeception/',
		'behat'             => '/vendor/behat/',

		// Development tools.
		'php-cs-fixer'      => '/vendor/friendsofphp/php-cs-fixer/',
		'phpcs'             => '/vendor/squizlabs/php_codesniffer/',
		'phpstan'           => '/vendor/phpstan/',
		'psalm'             => '/vendor/vimeo/psalm/',

		// Composer autoloader bootstrap (has side effects and runtime dependencies).
		// Note: We match specific Composer files rather than the entire directory
		// because plugin vendor/composer/ dirs may contain safe data files
		// (e.g., jetpack_autoload_classmap.php which is just an array).
		'composer-autoload' => '/vendor/composer/autoload_real.php',
		'composer-loader'   => '/vendor/composer/ClassLoader.php',

		// WordPress test suites.
		'wp-tests'          => '/wordpress-tests-lib/',
		'wp-phpunit'        => '/wp-phpunit/',

		// CLI-only files in plugins/themes.
		'cli-commands'      => '/cli/',

		// Object cache drop-in plugins. These replace wp-content/object-cache.php
		// and their files depend on WordPress runtime state (database connection,
		// constants, global $wp_object_cache). They are loaded by WordPress's own
		// bootstrap before any request code runs, so preloading them is both
		// unnecessary and dangerous — their code calls WordPress functions that
		// don't exist at preload time.
		'docket-cache'      => '/plugins/docket-cache/',
		'redis-cache'       => '/plugins/redis-cache/',
		'wp-redis'          => '/plugins/wp-redis/',
		'memcached'         => '/plugins/memcached/',
		'object-cache-pro'  => '/plugins/object-cache-pro/',
		'litespeed-cache'   => '/plugins/litespeed-cache/',
	];

	/**
	 * WordPress constants that are defined during bootstrap.
	 *
	 * Files that reference these constants at the top level (outside function/method bodies)
	 * will fail during preloading because the constants don't exist yet.
	 *
	 * @var array<string>
	 */
	private array $bootstrap_constants = [
		'SECURE_AUTH_COOKIE',
		'LOGGED_IN_COOKIE',
		'AUTH_COOKIE',
		'PASS_COOKIE',
		'COOKIEHASH',
		'COOKIE_DOMAIN',
		'SITECOOKIEPATH',
		'COOKIEPATH',
		'ADMIN_COOKIE_PATH',
		'PLUGINS_COOKIE_PATH',
		'TEMPLATEPATH',
		'STYLESHEETPATH',
		'WP_CONTENT_DIR',
		'WP_PLUGIN_DIR',
		'WPMU_PLUGIN_DIR',
		'WPINC',
		'WP_LANG_DIR',
		'AUTOSAVE_INTERVAL',
		'WP_POST_REVISIONS',
		'WP_CRON_LOCK_TIMEOUT',
		'FORCE_SSL_ADMIN',
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

		// Check blacklist by filename.
		$blacklist_result = $this->check_blacklist($path);
		if ($blacklist_result) {
			$result['safe']     = false;
			$result['errors'][] = $blacklist_result;

			return $result;
		}

		// Check blacklist by path pattern.
		$path_result = $this->check_path_patterns($path);
		if ($path_result) {
			$result['safe']     = false;
			$result['errors'][] = $path_result;

			return $result;
		}

		// Read file content for analysis.
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

		// Check for top-level bootstrap constant usage.
		$this->check_bootstrap_constants($content, $path, $result);

		// Check for top-level function calls and class instantiation.
		$this->check_top_level_execution($content, $path, $result);

		// Extract dependencies for ordering (extends, implements, use).
		$this->check_dependencies($content, $result);

		return $result;
	}

	/**
	 * Check if a file is blacklisted by filename or path pattern.
	 *
	 * This is a fast check that only looks at the file path, not its content.
	 * Use this to filter out fundamentally incompatible files even when
	 * full safety analysis is skipped (e.g., during optimizer runtime testing).
	 *
	 * @param string $path Full path to the file.
	 * @return bool True if the file is blacklisted.
	 */
	public function is_blacklisted(string $path): bool {

		return null !== $this->check_blacklist($path) || null !== $this->check_path_patterns($path);
	}

	/**
	 * Check if file is blacklisted by filename.
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
	 * Check if file path matches any blacklisted patterns.
	 *
	 * @param string $path File path.
	 * @return string|null Error message if matched, null otherwise.
	 */
	private function check_path_patterns(string $path): ?string {

		// Normalize path separators for consistent matching.
		$normalized = str_replace('\\', '/', $path);

		foreach ($this->blacklisted_path_patterns as $name => $pattern) {
			if (false !== strpos($normalized, $pattern)) {
				return sprintf(
					/* translators: 1: pattern name, 2: matched pattern */
					__('File matches blacklisted path pattern "%1$s" (%2$s). These files have runtime dependencies that are unavailable during preloading.', 'opcache-preload-generator'),
					$name,
					$pattern
				);
			}
		}

		return null;
	}

	/**
	 * Check for WordPress bootstrap constants used at the top level.
	 *
	 * Constants like SECURE_AUTH_COOKIE are defined during WordPress bootstrap
	 * (in wp-includes/default-constants.php). Files that reference these at the
	 * top level (outside function/method bodies) will cause fatal errors during
	 * preloading because the constants don't exist yet.
	 *
	 * We only flag constants used outside of function/method/class bodies,
	 * since constants inside function bodies are only evaluated when called.
	 *
	 * @param string                                                                                         $content File content.
	 * @param string                                                                                         $path    File path.
	 * @param array{safe: bool, warnings: array<string>, errors: array<string>, dependencies: array<string>} $result  Result array (modified by reference).
	 * @return void
	 */
	private function check_bootstrap_constants(string $content, string $path, array &$result): void {

		// Strip comments to avoid false positives.
		$stripped = $this->strip_comments($content);

		// Check for top-level constant usage.
		// We look for constants outside of function/method/class bodies.
		$top_level = $this->extract_top_level_code($stripped);

		if (empty($top_level)) {
			return;
		}

		foreach ($this->bootstrap_constants as $constant) {
			// Check for direct constant usage (not inside defined() check).
			if (false !== strpos($top_level, $constant)) {
				// Make sure it's not just a defined() check, which is safe.
				if (! preg_match('/defined\s*\(\s*[\'"]' . preg_quote($constant, '/') . '[\'"]\s*\)/', $top_level)) {
					$result['warnings'][] = sprintf(
						/* translators: %s: constant name */
						__('File references bootstrap constant "%s" which may not be defined during preloading.', 'opcache-preload-generator'),
						$constant
					);
				}
			}
		}
	}

	/**
	 * Check for top-level code execution that may fail during preloading.
	 *
	 * Files that execute code at the top level (outside function/method bodies)
	 * may reference classes, functions, or globals that don't exist at preload time.
	 * This is a heuristic check that flags common dangerous patterns.
	 *
	 * @param string                                                                                         $content File content.
	 * @param string                                                                                         $path    File path.
	 * @param array{safe: bool, warnings: array<string>, errors: array<string>, dependencies: array<string>} $result  Result array (modified by reference).
	 * @return void
	 */
	private function check_top_level_execution(string $content, string $path, array &$result): void {

		// Strip comments.
		$stripped = $this->strip_comments($content);

		$top_level = $this->extract_top_level_code($stripped);

		if (empty($top_level)) {
			return;
		}

		// Check for top-level function calls to WordPress functions.
		// These are safe inside function/method bodies but fatal at the top level during preload.
		$wp_function_patterns = [
			'add_action',
			'add_filter',
			'do_action',
			'apply_filters',
			'wp_die',
			'get_option',
			'update_option',
			'current_user_can',
			'is_admin',
			'is_multisite',
			'wp_enqueue_script',
			'wp_enqueue_style',
			'register_activation_hook',
			'register_deactivation_hook',
			'register_uninstall_hook',
			'load_plugin_textdomain',
		];

		foreach ($wp_function_patterns as $func) {
			// Match function call at top level (not inside a function definition).
			if (preg_match('/\b' . preg_quote($func, '/') . '\s*\(/', $top_level)) {
				$result['warnings'][] = sprintf(
					/* translators: %s: function name */
					__('File calls WordPress function "%s" at the top level, which may not exist during preloading.', 'opcache-preload-generator'),
					$func
				);
			}
		}
	}

	/**
	 * Extract top-level code from PHP content.
	 *
	 * Returns code that is outside of class, function, and method bodies.
	 * This is a heuristic approach using brace counting — it won't handle
	 * every edge case (heredocs with braces, etc.) but is sufficient for
	 * detecting common patterns.
	 *
	 * @param string $content PHP content (comments already stripped).
	 * @return string Top-level code.
	 */
	private function extract_top_level_code(string $content): string {

		$lines       = explode("\n", $content);
		$depth       = 0;
		$top_level   = '';
		$in_string   = false;
		$string_char = '';

		foreach ($lines as $line) {
			$trimmed = trim($line);

			// Skip empty lines and PHP tags.
			if (empty($trimmed) || '<?php' === $trimmed || '?>' === $trimmed) {
				continue;
			}

			// Skip namespace and use statements (these are safe).
			if (preg_match('/^(namespace|use)\s+/', $trimmed)) {
				continue;
			}

			// Skip defined('ABSPATH') || exit patterns (these are safe guards).
			if (preg_match('/defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)/', $trimmed)) {
				continue;
			}

			// Track brace depth to identify top-level code.
			$line_len = strlen($line);
			for ($i = 0; $i < $line_len; $i++) {
				$char = $line[ $i ];

				// Handle string literals to avoid counting braces inside strings.
				if (! $in_string && ("'" === $char || '"' === $char)) {
					$in_string   = true;
					$string_char = $char;
					continue;
				}

				if ($in_string && $char === $string_char) {
					// Check for escaped quote.
					$backslashes = 0;
					$j           = $i - 1;
					while ($j >= 0 && '\\' === $line[ $j ]) {
						++$backslashes;
						--$j;
					}
					if (0 === $backslashes % 2) {
						$in_string = false;
					}
					continue;
				}

				if (! $in_string) {
					if ('{' === $char) {
						++$depth;
					} elseif ('}' === $char) {
						--$depth;
					}
				}
			}

			// Collect lines at depth 0 (top-level code).
			if (0 === $depth) {
				$top_level .= $line . "\n";
			}
		}

		return $top_level;
	}

	/**
	 * Strip PHP comments from content.
	 *
	 * Removes single-line (//) and multi-line comments to avoid false positives
	 * when scanning for patterns.
	 *
	 * @param string $content PHP content.
	 * @return string Content without comments.
	 */
	private function strip_comments(string $content): string {

		// Use token_get_all for accurate comment removal.
		$tokens   = token_get_all($content);
		$stripped = '';

		foreach ($tokens as $token) {
			if (is_array($token)) {
				// Skip comment tokens.
				if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
					// Preserve newlines from multi-line comments to keep line numbers consistent.
					$stripped .= str_repeat("\n", substr_count($token[1], "\n"));
					continue;
				}
				$stripped .= $token[1];
			} else {
				$stripped .= $token;
			}
		}

		return $stripped;
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

		// Extract return type declarations.
		// Handles: ClassName, ?ClassName, A|B, A&B, ?A|B (nullable union).
		if (preg_match_all('/\bfunction\s+\w+\s*\([^)]*\)\s*:\s*\??([\w\\\\|&]+)/', $content, $matches)) {
			foreach ($matches[1] as $return_type) {
				$this->add_type_string_dependencies($return_type, $result);
			}
		}

		// Extract parameter type declarations.
		// Handles: ClassName $bar, ?ClassName $bar, A|B $bar.
		if (preg_match_all('/(?:^|[(,])\s*\??([\w\\\\|&]+)\s+\$\w+/m', $content, $matches)) {
			foreach ($matches[1] as $param_type) {
				$this->add_type_string_dependencies($param_type, $result);
			}
		}

		// Extract property type declarations.
		// Handles: public ClassName $prop, private ?ClassName $prop, protected A|B $prop.
		if (preg_match_all('/\b(?:public|protected|private)\s+(?:readonly\s+)?\??([\w\\\\|&]+)\s+\$\w+/', $content, $matches)) {
			foreach ($matches[1] as $prop_type) {
				$this->add_type_string_dependencies($prop_type, $result);
			}
		}

		$result['dependencies'] = array_unique($result['dependencies']);
	}

	/**
	 * Parse a type string and add class dependencies.
	 *
	 * Handles union (A|B), intersection (A&B), and simple types.
	 * Filters out primitive types (int, string, bool, etc.).
	 *
	 * @param string               $type_string The type string (e.g., "ClassName", "A|B", "A&B").
	 * @param array<string, mixed> $result      Result array to add dependencies to.
	 * @return void
	 */
	private function add_type_string_dependencies(string $type_string, array &$result): void {

		$primitives = [
			'void', 'int', 'float', 'string', 'bool', 'array', 'object',
			'callable', 'iterable', 'mixed', 'static', 'self', 'parent',
			'null', 'true', 'false', 'never',
		];

		// Split on | (union) and & (intersection) to get individual types.
		$types = preg_split('/[|&]/', $type_string);

		foreach ($types as $type) {
			$type = ltrim(trim($type), '?');

			if (empty($type)) {
				continue;
			}

			if (! in_array(strtolower($type), $primitives, true)) {
				$result['dependencies'][] = $type;
			}
		}
	}

	/**
	 * Determine the recommended preload method for a file.
	 *
	 * Returns 'require_once' for files that only define classes, interfaces,
	 * or traits with no top-level side effects and no internal require/include
	 * statements. These files are safe to execute during preload.
	 *
	 * Returns 'opcache_compile_file' for everything else — files with top-level
	 * code, internal includes, function definitions, or return statements.
	 * opcache_compile_file compiles bytecode without executing, preventing
	 * chain-loading and "Cannot redeclare" errors.
	 *
	 * @param string $path Full path to the file.
	 * @return string 'require_once' or 'opcache_compile_file'.
	 */
	public function get_recommended_method(string $path): string {

		if (! file_exists($path) || ! is_readable($path)) {
			return 'opcache_compile_file';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents($path);

		if (false === $content) {
			return 'opcache_compile_file';
		}

		return $this->has_only_declarations($content) ? 'require_once' : 'opcache_compile_file';
	}

	/**
	 * Check if a PHP file contains only declarations (no executable top-level code).
	 *
	 * Uses PHP's tokenizer to walk the token stream and track brace depth.
	 * At depth 0 (top level), only declaration tokens are allowed:
	 * - namespace, use statements
	 * - class, interface, trait, enum definitions (and their modifiers)
	 * - function definitions
	 * - const declarations
	 * - defined('ABSPATH') || exit guards
	 *
	 * Any other executable code at the top level (function calls, variable
	 * assignments, require/include, return, echo, etc.) means the file has
	 * side effects and must use opcache_compile_file.
	 *
	 * @param string $content PHP file content.
	 * @return bool True if the file contains only declarations.
	 */
	private function has_only_declarations(string $content): bool {

		$tokens = token_get_all($content);
		$count  = count($tokens);
		$depth  = 0;

		// Tokens that are safe at the top level (declarations, not execution).
		// NOTE: T_STRING is NOT in this list — it requires context checking
		// because it matches both declaration names (class Foo) and function
		// calls (add_action()). See the T_STRING handling below.
		$declaration_tokens = [
			T_OPEN_TAG,
			T_CLOSE_TAG,
			T_WHITESPACE,
			T_COMMENT,
			T_DOC_COMMENT,
			T_NAMESPACE,
			T_USE,
			T_CLASS,
			T_INTERFACE,
			T_TRAIT,
			T_ABSTRACT,
			T_FINAL,
			T_READONLY,
			T_FUNCTION,
			T_CONST,
			T_NAME_QUALIFIED,
			T_NAME_FULLY_QUALIFIED,
			T_NS_SEPARATOR,
			T_EXTENDS,
			T_IMPLEMENTS,
			T_AS,            // use ... as ... aliases.
			T_DECLARE,       // declare(strict_types=1).
			T_LNUMBER,       // Integer literals (in declare, const).
			T_DNUMBER,       // Float literals (in const).
		];

		// T_ENUM is only available in PHP 8.1+.
		if (defined('T_ENUM')) {
			$declaration_tokens[] = T_ENUM;
		}

		// T_ATTRIBUTE (#[...]) is only available in PHP 8.0+.
		if (defined('T_ATTRIBUTE')) {
			$declaration_tokens[] = T_ATTRIBUTE;
		}

		// Tokens after which T_STRING is safe (it's a name being declared/referenced,
		// not a function call). A T_STRING not preceded by one of these is a function
		// call or other executable code.
		$name_context_tokens = [
			T_NAMESPACE,
			T_USE,
			T_CLASS,
			T_INTERFACE,
			T_TRAIT,
			T_ABSTRACT,
			T_FINAL,
			T_READONLY,
			T_FUNCTION,
			T_CONST,
			T_EXTENDS,
			T_IMPLEMENTS,
			T_AS,
			T_NS_SEPARATOR,
			T_NAME_QUALIFIED,
			T_NAME_FULLY_QUALIFIED,
			T_DECLARE,
			T_STRING,        // Chained names: namespace Foo\Bar.
		];

		if (defined('T_ENUM')) {
			$name_context_tokens[] = T_ENUM;
		}

		if (defined('T_ATTRIBUTE')) {
			$name_context_tokens[] = T_ATTRIBUTE;
		}

		// Also allow these at top level (part of declarations and guard patterns).
		// Includes ']' for PHP attribute syntax (#[Attr]).
		// Includes '=' for declare(strict_types=1) and const assignments.
		// Includes ':' for backed enum types (enum Foo: string).
		$safe_chars = [';', ',', '(', ')', '{', '}', '!', ']', '=', ':'];

		// Track the last significant token type at depth 0 for context.
		$last_significant_type = null;

		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[ $i ];

			if (is_string($token)) {
				// Single-character token.
				if ('{' === $token) {
					++$depth;
				} elseif ('}' === $token) {
					--$depth;
					if (0 === $depth) {
						// Reset context after closing a class/function body.
						$last_significant_type = null;
					}
				}

				// Inside a class/function body, anything goes.
				if ($depth > 0) {
					continue;
				}

				// At top level, only safe characters are allowed.
				if (! in_array($token, $safe_chars, true)) {
					return false;
				}
				continue;
			}

			// Array token: [type, value, line].
			$type = $token[0];

			if ('{' === $token[1]) {
				++$depth;
			} elseif ('}' === $token[1]) {
				--$depth;
				if (0 === $depth) {
					$last_significant_type = null;
				}
			}

			// Inside a class/function body, anything goes.
			if ($depth > 0) {
				continue;
			}

			// Skip whitespace and comments — don't update context.
			if (T_WHITESPACE === $type || T_COMMENT === $type || T_DOC_COMMENT === $type) {
				continue;
			}

			// T_STRING needs context: it's safe after declaration keywords
			// (class name, function name, const name, namespace part, etc.)
			// but NOT safe as a standalone (that's a function call).
			if (T_STRING === $type) {
				if (null !== $last_significant_type && in_array($last_significant_type, $name_context_tokens, true)) {
					$last_significant_type = T_STRING;
					continue;
				}

				// T_STRING "defined" is part of the ABSPATH guard pattern.
				if ('defined' === $token[1]) {
					$last_significant_type = T_STRING;
					continue;
				}

				// Any other T_STRING at top level without declaration context
				// is a function call (e.g., add_action, do_action).
				return false;
			}

			// At top level, check if this token type is a declaration.
			if (in_array($type, $declaration_tokens, true)) {
				$last_significant_type = $type;
				continue;
			}

			// Allow T_EXIT as part of guard patterns.
			if (T_EXIT === $type) {
				$last_significant_type = $type;
				continue;
			}

			// Allow T_LOGICAL_OR (||) and T_BOOLEAN_OR as part of guard patterns.
			if (T_BOOLEAN_OR === $type || T_LOGICAL_OR === $type) {
				$last_significant_type = $type;
				continue;
			}

			// Allow T_CONSTANT_ENCAPSED_STRING (string literals in defined() calls).
			if (T_CONSTANT_ENCAPSED_STRING === $type) {
				$last_significant_type = $type;
				continue;
			}

			// Allow T_IF and T_RETURN for simple guard patterns like:
			// if (! defined('ABSPATH')) { exit; }
			if (T_IF === $type || T_RETURN === $type) {
				$last_significant_type = $type;
				continue;
			}

			// Anything else at the top level is executable code.
			return false;
		}

		return true;
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
	 * Get the list of blacklisted path patterns.
	 *
	 * @return array<string, string>
	 */
	public function get_blacklisted_path_patterns(): array {

		return $this->blacklisted_path_patterns;
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
	 * Add a path pattern to the blacklist.
	 *
	 * @param string $name    Pattern name for identification.
	 * @param string $pattern Path pattern to match (e.g., '/vendor/wp-cli/').
	 * @return void
	 */
	public function add_path_pattern(string $name, string $pattern): void {

		$this->blacklisted_path_patterns[ $name ] = $pattern;
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
