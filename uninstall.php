<?php
/**
 * Uninstall script for OPcache Preload Generator.
 *
 * @package OPcache_Preload_Generator
 */

// Exit if not called by WordPress uninstall.
if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

// Delete plugin options.
delete_option('opcache_preload_files');
delete_option('opcache_preload_settings');

// Note: We do NOT delete the generated preload.php file as it may still be
// referenced in php.ini and deleting it could cause PHP startup errors.
// Users should manually remove it after updating their php.ini configuration.
