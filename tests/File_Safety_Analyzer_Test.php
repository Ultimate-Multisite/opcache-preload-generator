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

		// Clean up temp files.
		$files = glob($this->temp_dir . '/*');
		foreach ($files as $file) {
			unlink($file);
		}
		rmdir($this->temp_dir);
		parent::tear_down();
	}

	/**
	 * Create a temporary PHP file.
	 *
	 * @param string $content File content.
	 * @return string File path.
	 */
	private function create_temp_file(string $content): string {

		$path = $this->temp_dir . '/test-' . uniqid() . '.php';
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
	 * Test detecting echo at top level.
	 */
	public function test_detect_echo_side_effect(): void {

		$content = <<<'PHP'
<?php
echo "Hello World";

class MyClass {}
PHP;

		$path   = $this->create_temp_file($content);
		$result = $this->analyzer->analyze_file($path);

		$this->assertNotEmpty($result['warnings']);
		$this->assertTrue(
			$this->contains_substring($result['warnings'], 'output statements')
		);
	}

	/**
	 * Test detecting define without defined check.
	 */
	public function test_detect_unsafe_define(): void {

		$content = <<<'PHP'
<?php
define('MY_CONSTANT', 'value');

class MyClass {}
PHP;

		$path   = $this->create_temp_file($content);
		$result = $this->analyzer->analyze_file($path);

		$this->assertNotEmpty($result['warnings']);
		$this->assertTrue(
			$this->contains_substring($result['warnings'], 'define()')
		);
	}

	/**
	 * Test safe define with defined check.
	 */
	public function test_safe_define_with_check(): void {

		$content = <<<'PHP'
<?php
if (!defined('MY_CONSTANT')) {
	define('MY_CONSTANT', 'value');
}

class MyClass {}
PHP;

		$path   = $this->create_temp_file($content);
		$result = $this->analyzer->analyze_file($path);

		// Should not have define warning.
		$this->assertFalse(
			$this->contains_substring($result['warnings'], 'without defined()')
		);
	}

	/**
	 * Test detecting __DIR__ in class properties.
	 */
	public function test_detect_dir_in_properties(): void {

		$content = <<<'PHP'
<?php
class MyClass {
	private string $path = __DIR__ . '/file.php';
}
PHP;

		$path   = $this->create_temp_file($content);
		$result = $this->analyzer->analyze_file($path);

		$this->assertNotEmpty($result['warnings']);
		$this->assertTrue(
			$this->contains_substring($result['warnings'], '__DIR__')
		);
	}

	/**
	 * Test detecting conditional class definitions.
	 */
	public function test_detect_conditional_class(): void {

		$content = <<<'PHP'
<?php
if (!class_exists('MyClass')) {
	class MyClass {}
}
PHP;

		$path   = $this->create_temp_file($content);
		$result = $this->analyzer->analyze_file($path);

		$this->assertNotEmpty($result['warnings']);
		$this->assertTrue(
			$this->contains_substring($result['warnings'], 'class_exists')
		);
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
	 * Test detecting WordPress hooks at top level.
	 */
	public function test_detect_wordpress_hooks(): void {

		$content = <<<'PHP'
<?php
add_action('init', 'my_function');

class MyClass {}
PHP;

		$path   = $this->create_temp_file($content);
		$result = $this->analyzer->analyze_file($path);

		$this->assertNotEmpty($result['warnings']);
		$this->assertTrue(
			$this->contains_substring($result['warnings'], 'WordPress hooks')
		);
	}

	/**
	 * Test blacklisted files.
	 */
	public function test_blacklisted_file(): void {

		$content = <<<'PHP'
<?php
// general-template.php
class MyClass {}
PHP;

		// Create file with blacklisted name.
		$path = $this->temp_dir . '/general-template.php';
		file_put_contents($path, $content);

		$result = $this->analyzer->analyze_file($path);

		$this->assertFalse($result['safe']);
		$this->assertNotEmpty($result['errors']);
		$this->assertTrue(
			$this->contains_substring($result['errors'], 'blacklisted')
		);

		unlink($path);
	}

	/**
	 * Test is_safe helper method.
	 */
	public function test_is_safe_method(): void {

		$safe_content = <<<'PHP'
<?php
class SafeClass {}
PHP;

		$unsafe_content = <<<'PHP'
<?php
echo "Hello";
PHP;

		$safe_path   = $this->create_temp_file($safe_content);
		$unsafe_path = $this->create_temp_file($unsafe_content);

		$this->assertTrue($this->analyzer->is_safe($safe_path));
		// is_safe returns true even with warnings (only false for errors).
		// Let's test with a non-PHP file.
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

		$safe_content   = '<?php class SafeClass {}';
		$unsafe_content = '<?php echo "test";';

		$safe_path   = $this->create_temp_file($safe_content);
		$unsafe_path = $this->create_temp_file($unsafe_content);

		// With warnings allowed (default).
		$safe_files = $this->analyzer->filter_safe_files([$safe_path, $unsafe_path]);
		$this->assertCount(2, $safe_files); // Both are "safe" (no errors).

		// Test with non-existent file (should be filtered out).
		$safe_files = $this->analyzer->filter_safe_files([$safe_path, '/nonexistent/file.php']);
		$this->assertCount(1, $safe_files);
		$this->assertContains($safe_path, $safe_files);
	}

	/**
	 * Test detecting require/include at top level.
	 */
	public function test_detect_require_include(): void {

		$content = <<<'PHP'
<?php
require_once 'other-file.php';

class MyClass {}
PHP;

		$path   = $this->create_temp_file($content);
		$result = $this->analyzer->analyze_file($path);

		$this->assertNotEmpty($result['warnings']);
		$this->assertTrue(
			$this->contains_substring($result['warnings'], 'require/include')
		);
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

		unlink($path);
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
