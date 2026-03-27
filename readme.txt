=== OPcache Preload Generator ===
Contributors: superdav42
Tags: opcache, preload, performance, optimization, php
Requires at least: 5.3
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Generate OPcache preload files based on runtime statistics with safety analysis.

== Description ==

OPcache Preload Generator helps you create optimized preload files for PHP's OPcache preloading feature (PHP 7.4+). The plugin analyzes your site's OPcache statistics to identify which files are most frequently accessed and would benefit most from preloading.

= Features =

* **OPcache Statistics Analysis** - View which files are cached and their hit counts
* **Smart File Suggestions** - Automatically suggests the most beneficial files for preloading
* **Safety Analysis** - Detects files with side effects or dependency issues that could cause problems
* **Preload File Generation** - Creates a ready-to-use preload.php file
* **Educational Content** - Learn how preloading works and best practices

= Requirements =

* PHP 7.4 or higher
* OPcache extension enabled
* Server access to configure php.ini

= How It Works =

1. The plugin monitors which PHP files are loaded and cached by OPcache
2. It ranks files by their hit count (how often they're used)
3. Files are analyzed for safety issues before being added to the preload list
4. A preload.php file is generated that can be configured in php.ini

= Safety Analysis =

The plugin checks each file for potential issues:

* **Blacklisted files** - Known problematic WordPress core files
* **Side effects** - Code that executes on include (echo, direct function calls)
* **Define conflicts** - Files with define() calls that may conflict
* **Path constants** - Use of __DIR__/__FILE__ in class properties
* **Conditional definitions** - class_exists() checks that won't work with preloading
* **Missing dependencies** - Classes that extend/implement unavailable classes

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/opcache-preload-generator/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > OPcache Preload to configure

== Configuration ==

After generating your preload.php file, add this to your php.ini:

`opcache.preload=/path/to/wordpress/preload.php`
`opcache.preload_user=www-data`

Replace `/path/to/wordpress/` with your actual WordPress root path and `www-data` with your web server user.

== Frequently Asked Questions ==

= What is OPcache preloading? =

OPcache preloading is a PHP 7.4+ feature that loads specified PHP files into memory when PHP starts, making them instantly available for all requests without needing to compile them.

= Is preloading right for my site? =

Preloading works best on sites with stable code that doesn't change frequently. It's ideal for production environments. Changes to preloaded files require a PHP-FPM restart to take effect.

= Why are some files marked as unsafe? =

Some files have code that executes immediately when included, or have dependencies that may not be available during preload. These files can cause errors if preloaded.

= Can I preload plugin files? =

Yes, but be cautious. Plugin files may have dependencies on WordPress core functions that aren't available during preload. The safety analyzer helps identify these issues.

== Changelog ==

= 1.1.0 =
* New: Automatic optimization — the plugin now analyzes your site's OPcache usage and suggests the best files to preload, no manual configuration needed
* New: Dependency resolution — preloaded files are now ordered correctly so classes that depend on other classes load in the right sequence, preventing errors
* New: Threshold slider in the admin UI — easily adjust how aggressively the optimizer selects files for preloading
* Improved: Completely redesigned admin interface — cleaner layout, easier to understand at a glance
* Improved: Parent directory now shown in optimization logs so you can tell apart files with the same name from different plugins
* Fixed: Files with WordPress security checks (ABSPATH) are now correctly identified and handled during preloading
* Fixed: Safety analyzer now properly catches WordPress core constant definitions that could cause conflicts
* Fixed: Resolved race conditions in the auto-optimizer that could generate incomplete preload files
* Fixed: Property type declarations in PHP 7.4+ are now correctly analyzed for dependencies

= 1.0.0 =
* Initial release
* OPcache status analysis
* File safety analyzer
* Preload file generator
* Admin interface with file management

== Upgrade Notice ==

= 1.1.0 =
Major update with automatic optimization, dependency resolution, and a redesigned admin interface. Recommended for all users.

= 1.0.0 =
Initial release of OPcache Preload Generator.
