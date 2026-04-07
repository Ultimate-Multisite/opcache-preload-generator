# AGENTS.md - OPcache Preload Generator

WordPress plugin that generates OPcache preload files based on runtime statistics with safety analysis.

## Build & Development Commands

```bash
# Install dependencies
composer install              # Full install with dev dependencies
composer install -o --no-dev  # Production build (optimized autoloader)
npm install                   # JS/CSS build tools (uglify-js, clean-css-cli)

# Run all tests (requires WordPress test environment)
vendor/bin/phpunit
npm test                      # Alias for vendor/bin/phpunit

# Run a single test file
vendor/bin/phpunit tests/File_Safety_Analyzer_Test.php

# Run a single test method
vendor/bin/phpunit --filter test_analyze_safe_class_file

# Run tests with verbose output
vendor/bin/phpunit --testdox

# Code style checking (WordPress Coding Standards)
vendor/bin/phpcs

# Auto-fix code style issues
vendor/bin/phpcbf

# Static analysis (PHPStan with WordPress extensions)
vendor/bin/phpstan analyse
```

### Test Environment Setup

Tests require the WordPress test library. Set `WP_TESTS_DIR` environment variable or install to:
- `$TMPDIR/wordpress-tests-lib` (default)
- `../../wp-test-lib` (relative fallback)

Tests run in multisite mode (`WP_TESTS_MULTISITE=1`).

## Project Structure

```
├── opcache-preload-generator.php  # Plugin entry point, defines constants
├── inc/                           # PSR-4 autoloaded classes (OPcache_Preload_Generator\)
│   ├── class-plugin.php           # Main bootstrap, singleton pattern
│   ├── class-opcache-analyzer.php # OPcache statistics analysis
│   ├── class-file-safety-analyzer.php  # Preload safety checks
│   ├── class-preload-generator.php     # Generates preload.php files
│   ├── class-dependency-resolver.php   # Class dependency ordering
│   ├── class-admin-page.php            # WordPress admin UI
│   ├── class-ajax-handler.php          # AJAX endpoints
│   ├── class-cli-command.php           # WP-CLI commands
│   ├── class-file-list-table.php       # WP_List_Table implementation
│   └── class-rest-api.php             # REST API endpoints
├── tests/                         # PHPUnit tests (suffix: *Test.php)
├── views/                         # PHP template files
└── assets/                        # CSS/JS assets
```

## Code Style Guidelines

### PHP Standards

This project follows **WordPress Coding Standards** with customizations defined in `.phpcs.xml.dist`.

#### Naming Conventions

- **Classes**: `Upper_Snake_Case` (e.g., `File_Safety_Analyzer`)
- **Methods/Functions**: `snake_case` (e.g., `analyze_file()`)
- **Variables**: `$snake_case` (e.g., `$file_path`)
- **Constants**: `UPPER_SNAKE_CASE` (e.g., `OPCACHE_PRELOAD_VERSION`)
- **Hooks**: Prefix with `opcache_preload_` (e.g., `opcache_preload_before_generate`)
- **Global functions**: Prefix with `opcache_preload_` (enforced by PHPCS)

#### File Naming

- Class files: `class-{class-name}.php` (e.g., `class-file-safety-analyzer.php`)
- Test files: `{ClassName}_Test.php` (e.g., `File_Safety_Analyzer_Test.php`)

#### Namespace

All classes use `OPcache_Preload_Generator` namespace. PSR-4 autoloading maps to `inc/`.

```php
namespace OPcache_Preload_Generator;
```

### Formatting Rules

```php
// Short array syntax allowed (Universal.Arrays.DisallowShortArraySyntax excluded)
$array = ['key' => 'value'];

// Short ternary allowed (Universal.Operators.DisallowShortTernary excluded)
$value = $input ?: 'default';

// No spaces inside parentheses (customized from WP standard)
if ($condition) {
    function_call($arg1, $arg2);
}

// Spaces around operators
$result = $a + $b;

// Typed properties (PHP 7.4+)
private string $path;
private ?Plugin $instance = null;

// Return type declarations
public function analyze_file(string $path): array {
```

### PHPDoc Requirements

```php
/**
 * Short description.
 *
 * @param string $path Full path to the file.
 * @return array{safe: bool, warnings: array<string>, errors: array<string>}
 */
```

- File headers: `@package OPcache_Preload_Generator`
- Use typed arrays: `array<string>`, `array<string, mixed>`
- Nullable types: `?ClassName` or `ClassName|null`

### Security Patterns

```php
// Always check ABSPATH at file start
if (! defined('ABSPATH')) {
    exit;
}

// Escape output in views
esc_html($text);
esc_attr($attribute);
esc_url($url);

// Sanitize input
sanitize_text_field($input);
absint($number);

// Nonce verification for forms/AJAX
wp_verify_nonce($nonce, 'opcache_preload_action');
check_ajax_referer('opcache_preload_nonce');

// Capability checks
if (! current_user_can('manage_options')) {
    wp_die();
}
```

### WordPress Integration

```php
// Text domain for i18n
__('Message', 'opcache-preload-generator');
_e('Message', 'opcache-preload-generator');

// Options API
get_option('opcache_preload_files', []);
update_option('opcache_preload_settings', $settings);

// Hooks
add_action('plugins_loaded', [$this, 'init']);
add_filter('opcache_preload_files', [$this, 'filter_files']);
```

### Error Handling

```php
// Return error messages instead of throwing (WordPress pattern)
public function write_file(array $files, string $output_path) {
    if (! is_dir($dir)) {
        return sprintf(
            __('Directory does not exist: %s', 'opcache-preload-generator'),
            $dir
        );
    }
    // ... success
    return true;
}

// Check return values
$result = $this->write_file($files, $path);
if (true !== $result) {
    // $result contains error message
}
```

### Test Patterns

```php
class File_Safety_Analyzer_Test extends WP_UnitTestCase {
    private File_Safety_Analyzer $analyzer;
    
    public function set_up(): void {
        parent::set_up();
        $this->analyzer = new File_Safety_Analyzer();
    }
    
    public function tear_down(): void {
        // Cleanup
        parent::tear_down();
    }
    
    public function test_method_name(): void {
        // Arrange, Act, Assert
        $result = $this->analyzer->analyze_file($path);
        $this->assertTrue($result['safe']);
    }
}
```

## Key Constants

```php
OPCACHE_PRELOAD_VERSION    // Plugin version
OPCACHE_PRELOAD_PLUGIN_FILE // Main plugin file path
OPCACHE_PRELOAD_DIR        // Plugin directory path (with trailing slash)
OPCACHE_PRELOAD_URL        // Plugin URL
```

## Dependencies

- **PHP**: >=7.4.1
- **WordPress**: >=5.3
- **Extensions**: ext-json
- **Dev**: phpunit ^9.6, phpstan, woocommerce/woocommerce-sniffs (includes WordPress standards)

## Local Development Environment

The shared WordPress dev install for testing this plugin is at `../wordpress` (relative to this repo root).

- **URL**: http://wordpress.local:8080
- **Admin**: http://wordpress.local:8080/wp-admin — `admin` / `admin`
- **WordPress version**: 7.0-RC2
- **This plugin**: symlinked into `../wordpress/wp-content/plugins/$(basename $PWD)`
- **Reset to clean state**: `cd ../wordpress && ./reset.sh`

WP-CLI is configured via `wp-cli.yml` in this repo root — run `wp` commands directly from here without specifying `--path`.

```bash
wp plugin activate $(basename $PWD)   # activate this plugin
wp plugin deactivate $(basename $PWD) # deactivate
wp db reset --yes && cd ../wordpress && ./reset.sh  # full reset
```
