<?php
/**
 * PHPUnit bootstrap file for OPcache Preload Generator.
 *
 * @package OPcache_Preload_Generator
 */

$_tests_dir = getenv('WP_TESTS_DIR');

// Load PHPUnit Polyfills.
require_once dirname(__DIR__) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

if (! $_tests_dir) {
	$_tests_dir = rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests-lib';
}

// Check for alternate location.
if (! file_exists("{$_tests_dir}/includes/functions.php")) {
	$_tests_dir = dirname(dirname(dirname(__DIR__))) . '/wp-test-lib';
}

// Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file.
$_phpunit_polyfills_path = getenv('WP_TESTS_PHPUNIT_POLYFILLS_PATH');
if (false !== $_phpunit_polyfills_path) {
	define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path);
}

if (! file_exists("{$_tests_dir}/includes/functions.php")) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit(1);
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {

	$plugin_dir = dirname(__DIR__);

	// Define plugin constants.
	if (! defined('OPCACHE_PRELOAD_VERSION')) {
		define('OPCACHE_PRELOAD_VERSION', '1.0.0');
	}
	if (! defined('OPCACHE_PRELOAD_PLUGIN_FILE')) {
		define('OPCACHE_PRELOAD_PLUGIN_FILE', $plugin_dir . '/opcache-preload-generator.php');
	}
	if (! defined('OPCACHE_PRELOAD_DIR')) {
		define('OPCACHE_PRELOAD_DIR', $plugin_dir . '/');
	}
	if (! defined('OPCACHE_PRELOAD_URL')) {
		define('OPCACHE_PRELOAD_URL', '');
	}

	// Load the classes directly.
	require_once $plugin_dir . '/inc/class-opcache-analyzer.php';
	require_once $plugin_dir . '/inc/class-file-safety-analyzer.php';
	require_once $plugin_dir . '/inc/class-preload-generator.php';
}

tests_add_filter('muplugins_loaded', '_manually_load_plugin');

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";
