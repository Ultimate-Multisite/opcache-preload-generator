<?php
/**
 * Plugin Name: OPcache Preload Generator
 * Description: Generate OPcache preload files based on runtime statistics with safety analysis.
 * Plugin URI: https://multisiteultimate.com
 * Text Domain: opcache-preload-generator
 * Version: 1.0.0
 * Author: David Stone - Multisite Ultimate
 * Author URI: https://multisiteultimate.com
 * Copyright: David Stone, Multisite Ultimate
 * Requires at least: 5.3
 * Tested up to: 6.6
 * Requires PHP: 7.4
 *
 * @package OPcache_Preload_Generator
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

// Define addon constants.
define('OPCACHE_PRELOAD_VERSION', '1.0.10');
define('OPCACHE_PRELOAD_PLUGIN_FILE', __FILE__);
define('OPCACHE_PRELOAD_DIR', plugin_dir_path(__FILE__));
define('OPCACHE_PRELOAD_URL', plugin_dir_url(__FILE__));

// Load Composer autoloader.
if (file_exists(OPCACHE_PRELOAD_DIR . 'vendor/autoload.php')) {
	require_once OPCACHE_PRELOAD_DIR . 'vendor/autoload.php';
}

// Load the main plugin class.
require_once OPCACHE_PRELOAD_DIR . 'inc/class-plugin.php';

// Initialize the plugin.
OPcache_Preload_Generator\Plugin::get_instance();
