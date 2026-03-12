<?php
/**
 * Tests for the File_Safety_Analyzer class.
 *
 * @package OPcache_Preload_Generator
 */

namespace OPcache_Preload_Generator\Tests;

use OPcache_Preload_Generator\File_Safety_Analyzer;
use WP_UnitTestCase;

/**
 * File_Safety_Analyzer test case.
 */
class File_Safety_Analyzer_Test extends WP_UnitTestCase {

	/**
	 * Analyzer instance.
	 *
	 * @var File_Safety_Analyzer
	 */
	private File_Safety_Analyzer $analyzer;

	/**
	 * Temp directory for test files.
	 *
	 * @var string
	 */
	private string $temp_dir;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {

		parent::set_up();
		$this->analyzer = new File_Safety_Analyzer();
		$this->temp_dir = sys_get_temp_dir() . '/opcache-preload-test-' . uniqid();
		mkdir($this->temp_dir);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {

		// Clean up temp files recursively.
		$this->remove_directory($this->temp_dir);
		parent::tear_down();
	}

	/**
	 * Recursively remove a directory and its contents.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function remove_directory(string $dir): void {

		if (! is_dir($dir)) {
			return;
		}

		$items = scandir($dir);
		foreach ($items as $item) {
			if ('.' === $item || '..' === $item) {
				continue;
			}
			$path = $dir . '/' . $item;
			if (is_dir($path)) {
				$this->remove_directory($path);
			} else {
				unlink($path);
			}
		}
		rmdir($dir);
	}

	/**
	 * Create a temporary PHP file.
	 *
	 * @param string $content  File content.
	 * @param string $filename Optional filename.
	 * @return string File path.
	 */
	private function create_temp_file(string $content, string $filename = ''): string {

		if (empty($filename)) {
			$filename = 'test-' . uniqid() . '.php';
		}

		$path = $this->temp_dir . '/' . $filename;

		// Create subdirectories if needed.
		$dir = dirname($path);
		if (! is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		file_put_contents($path, $content);

		return $path;
	}

	/**
	 * Test analyzing a non-existent file.
	 */
	public function test_analyze_nonexistent_file(): void {

		$result = $this->analyzer->analyze_file('/nonexistent/file.php');

		$this->assertFalse($result['safe']);
		$this->assertNotEmpty($result['errors']);
		$this->assertStringContainsString('does not exist', $result['errors'][0]);
	}

	/**
	 * Test analyzing a safe class file.
	 */
	public function test_analyze_safe_class_file(): void {

		$content = <<<'PHP'
<?php
namespace Test;

class SafeClass {
	private string $name;

	public function getName(): string {
		return $this->name;
	}
}
PHP;

		$path   = $this->create_temp_file($content);
		$result = $this->analyzer->analyze_file($path);

		$this->assertTrue($result['safe']);
		$this->assertEmpty($result['errors']);
	}

	/**
	 * Test detecting class dependencies.
	 */
	public function test_detect_class_dependencies(): void {

		$content = <<<'PHP'
<?php
class MyClass extends ParentClass implements MyInterface {
	use MyTrait;
}
PHP;

		$path   = $this->create_temp_file($content);
		$result = $this->analyzer->analyze_file($path);

		$this->assertContains('ParentClass', $result['dependencies']);
		$this->assertContains('MyInterface', $result['dependencies']);
	}

	/**
	 * Test blacklisted files by filename.
	 */
	public function test_blacklisted_file_by_name(): void {

		$content = '<?php class MyClass {}';

		// Test wp-settings.php.
		$path = $this->create_temp_file($content, 'wp-settings.php');
		$result = $this->analyzer->analyze_file($path);

		$this->assertFalse($result['safe']);
		$this->assertNotEmpty($result['errors']);
		$this->assertTrue($this->contains_substring($result['errors'], 'blacklisted'));
	}

	/**
	 * Test pluggable.php is blacklisted.
	 */
	public function test_pluggable_php_blacklisted(): void {

		$content = '<?php function wp_parse_auth_cookie() {}';

		$path = $this->create_temp_file($content, 'pluggable.php');
		$result = $this->analyzer->analyze_file($path);

		$this->assertFalse($result['safe']);
		$this->assertNotEmpty($result['errors']);
		$this->assertTrue($this->contains_substring($result['errors'], 'blacklisted'));
	}

	/**
	 * Test WP-CLI vendor path is blacklisted.
	 */
	public function test_wp_cli_path_blacklisted(): void {

		$content = '<?php class WP_CLI {}';

		// Create a file in a vendor/wp-cli/ path.
		$path = $this->create_temp_file($content, 'vendor/wp-cli/wp-cli/php/class-wp-cli.php');
		$result = $this->analyzer->analyze_file($path);

		$this->assertFalse($result['safe']);
		$this->assertNotEmpty($result['errors']);
		$this->assertTrue($this->contains_substring($result['errors'], 'blacklisted path pattern'));
		$this->assertTrue($this->contains_substring($result['errors'], 'wp-cli'));
	}

	/**
	 * Test PHPUnit vendor path is blacklisted.
	 */
	public function test_phpunit_path_blacklisted(): void {

		$content = '<?php class TestCase {}';

		$path = $this->create_temp_file($content, 'vendor/phpunit/phpunit/src/TestCase.php');
		$result = $this->analyzer->analyze_file($path);

		$this->assertFalse($result['safe']);
		$this->assertNotEmpty($result['errors']);
		$this->assertTrue($this->contains_substring($result['errors'], 'blacklisted path pattern'));
	}

	/**
	 * Test composer vendor path is blacklisted.
	 */
	public function test_composer_path_blacklisted(): void {

		$content = '<?php class ClassLoader {}';

		$path = $this->create_temp_file($content, 'vendor/composer/ClassLoader.php');
		$result = $this->analyzer->analyze_file($path);

		$this->assertFalse($result['safe']);
		$this->assertNotEmpty($result['errors']);
		$this->assertTrue($this->contains_substring($result['errors'], 'blacklisted path pattern'));
	}

	/**
	 * Test that normal vendor paths are not blacklisted.
	 */
	public function test_normal_vendor_path_allowed(): void {

		$content = '<?php class MyLibrary {}';

		$path = $this->create_temp_file($content, 'vendor/my-org/my-lib/src/MyLibrary.php');
		$result = $this->analyzer->analyze_file($path);

		$this->assertTrue($result['safe']);
		$this->assertEmpty($result['errors']);
	}

	/**
	 * Test detecting bootstrap constant usage at top level.
	 */
	public function test_detect_bootstrap_constant_at_top_level(): void {

		$content = <<<'PHP'
<?php
$cookie = SECURE_AUTH_COOKIE;
PHP;

		$path   = $this->create_temp_file($content);
		$result = $this->analyzer->analyze_file($path);

		$this->assertNotEmpty($result['warnings']);
		$this->assertTrue(
			$this->contains_substring($result['warnings'], 'SECURE_AUTH_COOKIE')
		);
	}

	/**
	 * Test that bootstrap constants inside function bodies are not flagged.
	 */
	public function test_bootstrap_constant_inside_function_is_safe(): void {

		$content = <<<'PHP'
<?php
class AuthHandler {
	public function get_cookie(): string {
		return SECURE_AUTH_COOKIE;
	}
}
PHP;

		$path   = $this->create_temp_file($content);
		$result = $this->analyzer->analyze_file($path);

		// Constants inside class methods should not trigger warnings.
		$this->assertFalse(
			$this->contains_substring($result['warnings'], 'SECURE_AUTH_COOKIE')
		);
	}

	/**
	 * Test that defined() checks for bootstrap constants are safe.
	 */
	public function test_defined_check_for_bootstrap_constant_is_safe(): void {

		$content = <<<'PHP'
<?php
if (defined('SECURE_AUTH_COOKIE')) {
	$cookie = SECURE_AUTH_COOKIE;
}
PHP;

		$path   = $this->create_temp_file($content);
		$result = $this->analyzer->analyze_file($path);

		// The defined() check itself should not trigger a warning.
		// Note: the constant usage on the next line may still trigger
		// since it's at top level, but the defined() check is safe.
		$this->assertTrue($result['safe']);
	}

	/**
	 * Test detecting WordPress function calls at top level.
	 */
	public function test_detect_wp_function_at_top_level(): void {

		$content = <<<'PHP'
<?php
add_action('init', 'my_function');

class MyClass {}
PHP;

		$path   = $this->create_temp_file($content);
		$result = $this->analyzer->analyze_file($path);

		$this->assertNotEmpty($result['warnings']);
		$this->assertTrue(
			$this->contains_substring($result['warnings'], 'add_action')
		);
	}

	/**
	 * Test that WordPress function calls inside class methods are safe.
	 */
	public function test_wp_function_inside_method_is_safe(): void {

		$content = <<<'PHP'
<?php
namespace MyPlugin;

class Plugin {
	public function init(): void {
		add_action('init', [$this, 'on_init']);
		add_filter('the_content', [$this, 'filter_content']);
	}
}
PHP;

		$path   = $this->create_temp_file($content);
		$result = $this->analyzer->analyze_file($path);

		// WordPress functions inside class methods should not trigger warnings.
		$this->assertFalse(
			$this->contains_substring($result['warnings'], 'add_action')
		);
		$this->assertFalse(
			$this->contains_substring($result['warnings'], 'add_filter')
		);
	}

	/**
	 * Test is_safe helper method.
	 */
	public function test_is_safe_method(): void {

		$safe_content = '<?php class SafeClass {}';

		$safe_path = $this->create_temp_file($safe_content);

		$this->assertTrue($this->analyzer->is_safe($safe_path));
		$this->assertFalse($this->analyzer->is_safe('/nonexistent/file.php'));
	}

	/**
	 * Test analyzing multiple files.
	 */
	public function test_analyze_files(): void {

		$content1 = '<?php class Class1 {}';
		$content2 = '<?php class Class2 {}';

		$path1 = $this->create_temp_file($content1);
		$path2 = $this->create_temp_file($content2);

		$results = $this->analyzer->analyze_files([$path1, $path2]);

		$this->assertCount(2, $results);
		$this->assertArrayHasKey($path1, $results);
		$this->assertArrayHasKey($path2, $results);
	}

	/**
	 * Test filter_safe_files method.
	 */
	public function test_filter_safe_files(): void {

		$safe_content = '<?php class SafeClass {}';

		$safe_path = $this->create_temp_file($safe_content);

		// Test with non-existent file (should be filtered out).
		$safe_files = $this->analyzer->filter_safe_files([$safe_path, '/nonexistent/file.php']);
		$this->assertCount(1, $safe_files);
		$this->assertContains($safe_path, $safe_files);
	}

	/**
	 * Test non-PHP file.
	 */
	public function test_non_php_file(): void {

		$path = $this->temp_dir . '/test.txt';
		file_put_contents($path, 'This is not PHP');

		$result = $this->analyzer->analyze_file($path);

		$this->assertFalse($result['safe']);
		$this->assertTrue(
			$this->contains_substring($result['errors'], 'not appear to be a PHP file')
		);
	}

	/**
	 * Test add_to_blacklist method.
	 */
	public function test_add_to_blacklist(): void {

		$this->analyzer->add_to_blacklist('custom-file.php');

		$blacklist = $this->analyzer->get_blacklisted_files();
		$this->assertContains('custom-file.php', $blacklist);

		// Test that the file is now blocked.
		$content = '<?php class MyClass {}';
		$path    = $this->create_temp_file($content, 'custom-file.php');
		$result  = $this->analyzer->analyze_file($path);

		$this->assertFalse($result['safe']);
	}

	/**
	 * Test add_path_pattern method.
	 */
	public function test_add_path_pattern(): void {

		$this->analyzer->add_path_pattern('custom-vendor', '/vendor/custom-dangerous/');

		$patterns = $this->analyzer->get_blacklisted_path_patterns();
		$this->assertArrayHasKey('custom-vendor', $patterns);

		// Test that files in this path are now blocked.
		$content = '<?php class MyClass {}';
		$path    = $this->create_temp_file($content, 'vendor/custom-dangerous/src/MyClass.php');
		$result  = $this->analyzer->analyze_file($path);

		$this->assertFalse($result['safe']);
		$this->assertTrue($this->contains_substring($result['errors'], 'custom-vendor'));
	}

	/**
	 * Test that ABSPATH guard pattern is not flagged.
	 */
	public function test_abspath_guard_not_flagged(): void {

		$content = <<<'PHP'
<?php
if (! defined('ABSPATH')) {
	exit;
}

class MyPlugin {
	public function init(): void {
		add_action('init', [$this, 'run']);
	}
}
PHP;

		$path   = $this->create_temp_file($content);
		$result = $this->analyzer->analyze_file($path);

		$this->assertTrue($result['safe']);
		$this->assertEmpty($result['errors']);
	}

	/**
	 * Test multiple bootstrap constants detection.
	 */
	public function test_multiple_bootstrap_constants(): void {

		$content = <<<'PHP'
<?php
$auth = AUTH_COOKIE;
$logged = LOGGED_IN_COOKIE;
PHP;

		$path   = $this->create_temp_file($content);
		$result = $this->analyzer->analyze_file($path);

		$this->assertNotEmpty($result['warnings']);
		$this->assertTrue($this->contains_substring($result['warnings'], 'AUTH_COOKIE'));
		$this->assertTrue($this->contains_substring($result['warnings'], 'LOGGED_IN_COOKIE'));
	}

	/**
	 * Test ms-settings.php is blacklisted.
	 */
	public function test_ms_settings_blacklisted(): void {

		$content = '<?php // Multisite settings';
		$path    = $this->create_temp_file($content, 'ms-settings.php');
		$result  = $this->analyzer->analyze_file($path);

		$this->assertFalse($result['safe']);
		$this->assertTrue($this->contains_substring($result['errors'], 'blacklisted'));
	}

	/**
	 * Test default-constants.php is blacklisted.
	 */
	public function test_default_constants_blacklisted(): void {

		$content = '<?php // Default constants';
		$path    = $this->create_temp_file($content, 'default-constants.php');
		$result  = $this->analyzer->analyze_file($path);

		$this->assertFalse($result['safe']);
		$this->assertTrue($this->contains_substring($result['errors'], 'blacklisted'));
	}

	/**
	 * Helper to check if any array element contains a substring.
	 *
	 * @param array  $array     Array to search.
	 * @param string $substring Substring to find.
	 * @return bool
	 */
	private function contains_substring(array $array, string $substring): bool {

		foreach ($array as $item) {
			if (strpos($item, $substring) !== false) {
				return true;
			}
		}

		return false;
	}
}
