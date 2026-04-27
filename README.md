# OPcache Preload Generator

[![Download Plugin Now](https://img.shields.io/github/v/release/Ultimate-Multisite/opcache-preload-generator?style=for-the-badge&label=Download+Plugin+Now&color=0073aa)](https://github.com/Ultimate-Multisite/opcache-preload-generator/releases/latest/download/opcache-preload-generator.zip) &nbsp; Upload the zip to WordPress like any other plugin

> ⚠️ **Advanced Users Only** - This plugin is intended for system administrators and developers. It requires server-level access to modify `php.ini` and restart PHP processes.
>
> 🚫 **Not for Shared Hosting** - OPcache preloading requires dedicated server access. This will **NOT work** on shared hosting environments where you don't have control over the PHP configuration.

A WordPress plugin that generates OPcache preload files based on runtime statistics with safety analysis.

## Description

OPcache Preload Generator analyzes your WordPress site's OPcache usage patterns and creates optimized preload files for PHP's OPcache preloading feature (PHP 7.4+).

The plugin automatically identifies which PHP files are most frequently accessed and would benefit most from preloading, while filtering out files that could cause issues.

**You should use this plugin if:**
- You manage your own server (VPS, dedicated, or cloud instance)
- You have SSH/root access to modify `php.ini`
- You can restart PHP-FPM or your web server
- You understand the implications of PHP preloading

**Do NOT use this plugin if:**
- You're on shared hosting (SiteGround, Bluehost, GoDaddy, etc.)
- You cannot access or modify `php.ini`
- You cannot restart your web server
- You're not comfortable with server administration

## Features

- **Automatic File Detection** - Uses OPcache statistics to identify hot files
- **Smart Reference Algorithm** - Uses WordPress core files as reference points for threshold calculation
- **Safety Analysis** - Filters files with side effects, dependency issues, or conflicts
- **Interactive Threshold Slider** - Visual control over how many files to include
- **Context-Aware UI** - Different workflows for new users, file generated, and active states
- **File Preview** - See which files will be included before generating

## Requirements

- PHP 7.4 or higher
- WordPress 5.3 or higher
- OPcache extension enabled
- Server access to configure php.ini

## Installation

1. Upload the plugin files to `/wp-content/plugins/opcache-preload-generator/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **Settings → OPcache Preload** (Network Admin for multisite)

## Usage

### For New Users (No Preload File)

1. Click **"Advanced Options"** to expand settings
2. Adjust the **Hit Threshold** slider:
   - Lower % = more files, higher cutoff
   - Higher % = fewer files, lower cutoff
3. Review the **Candidate Files Preview**
4. Click **"Generate Preload File"**

### After Generating

1. Copy the PHP configuration shown
2. Add to your `php.ini`:
   ```ini
   opcache.preload=/path/to/wordpress/preload.php
   opcache.preload_user=www-data
   ```
3. Restart PHP-FPM or your web server

### Regenerating

Use **"Regenerate Preload File"** to update the preload file with current OPcache statistics. Recommended periodically or after major updates.

## How It Works

1. **Reference File Detection**: Finds a WordPress core file (l10n.php, option.php, etc.) that's loaded on every request
2. **Threshold Calculation**: `reference_hits × threshold_percentage = cutoff_hits`
3. **File Selection**: Includes all files with `hits >= cutoff_hits`
4. **Safety Filtering**: Excludes files with side effects or dependency issues
5. **Preload Generation**: Creates a `preload.php` file with `opcache_compile_file()` calls

## Project Structure

```
opcache-preload-generator/
├── opcache-preload-generator.php    # Plugin entry point
├── inc/
│   ├── class-plugin.php           # Main plugin class
│   ├── class-opcache-analyzer.php # OPcache statistics analysis
│   ├── class-preload-generator.php # Preload file generation
│   ├── class-file-safety-analyzer.php  # Safety checks
│   ├── class-admin-page.php       # Admin UI
│   ├── class-ajax-handler.php     # AJAX endpoints
│   └── ...
├── views/                         # Admin templates
├── assets/                        # CSS and JS
└── tests/                         # PHPUnit tests
```

## Development

### Setup

```bash
composer install
npm install
```

### Running Tests

```bash
vendor/bin/phpunit
```

### Code Standards

```bash
vendor/bin/phpcs
vendor/bin/phpcbf  # Auto-fix
```

## Safety Analysis

The plugin checks files for:

- **Side Effects** - Code that executes on include (echo, direct calls)
- **Dependency Issues** - Classes extending unavailable classes
- **Define Conflicts** - Multiple define() calls
- **Path Constants** - __DIR__/__FILE__ usage in class properties
- **Blacklisted Files** - Known problematic WordPress core files

## FAQ

**Why don't I see more files when lowering the threshold?**

If all your cached files have high hit counts, lowering the threshold won't show more files. The cutoff is relative to the reference file's hit count.

**Why does the reference file hit count keep increasing?**

The reference file's hit count grows as the OPcache warms up. This is normal and expected.

**Is preloading right for my site?**

Best for production sites with stable code. Changes to preloaded files require a PHP-FPM restart.

## License

GPLv3 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-3.0.html).

## Credits

Created by [David Stone](https://multisiteultimate.com) - Multisite Ultimate
