<?php
/**
 * Tests for the Preload_Generator class.
 *
 * @package OPcache_Preload_Generator
 */

namespace OPcache_Preload_Generator\Tests;

use OPcache_Preload_Generator\File_Safety_Analyzer;
use OPcache_Preload_Generator\Preload_Generator;
use WP_UnitTestCase;

/**
 * Preload_Generator test case.
 */
class Preload_Generator_Test extends WP_UnitTestCase {

	/**
	 * Generator instance.
	 *
	 * @var Preload_Generator
	 */
	private Preload_Generator $generator;

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
		$this->generator = new Preload_Generator(new File_Safety_Analyzer());
		$this->temp_dir  = sys_get_temp_dir() . '/opcache-preload-test-' . uniqid();
		mkdir($this->temp_dir);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {

		// Clean up temp files.
		$files = glob($this->temp_dir . '/*');
		foreach ($files as $file) {
			if (is_file($file)) {
				unlink($file);
			}
		}
		rmdir($this->temp_dir);
		parent::tear_down();
	}

	/**
	 * Test generating preload content with require_once.
	 */
	public function test_generate_with_require_once(): void {

		$files   = ['/path/to/file1.php', '/path/to/file2.php'];
		$content = $this->generator->generate($files, ['use_require' => true]);

		$this->assertStringContainsString('<?php', $content);
		$this->assertStringContainsString('OPcache Preload File', $content);
		$this->assertStringContainsString('require_once', $content);
		$this->assertStringNotContainsString('opcache_compile_file', $content);
		$this->assertStringContainsString('/path/to/file1.php', $content);
		$this->assertStringContainsString('/path/to/file2.php', $content);
	}

	/**
	 * Test generating preload content with opcache_compile_file.
	 */
	public function test_generate_with_compile_file(): void {

		$files   = ['/path/to/file1.php'];
		$content = $this->generator->generate($files, ['use_require' => false]);

		$this->assertStringContainsString('opcache_compile_file', $content);
		$this->assertStringNotContainsString('require_once', $content);
	}

	/**
	 * Test generated content includes PHP version check.
	 */
	public function test_generate_includes_version_check(): void {

		$content = $this->generator->generate(['/path/to/file.php']);

		$this->assertStringContainsString('PHP_VERSION_ID < 70400', $content);
	}

	/**
	 * Test generated content includes file existence check.
	 */
	public function test_generate_includes_file_check(): void {

		$content = $this->generator->generate(
			['/path/to/file.php'],
			['validate_files' => true]
		);

		$this->assertStringContainsString('file_exists', $content);
	}

	/**
	 * Test generating without file validation.
	 */
	public function test_generate_without_file_validation(): void {

		$content = $this->generator->generate(
			['/path/to/file.php'],
			['validate_files' => false]
		);

		$this->assertStringNotContainsString('file_exists', $content);
	}

	/**
	 * Test writing preload file to disk.
	 */
	public function test_write_file(): void {

		$files       = ['/path/to/file.php'];
		$output_path = $this->temp_dir . '/preload.php';

		$result = $this->generator->write_file($files, $output_path);

		$this->assertTrue($result);
		$this->assertFileExists($output_path);

		$content = file_get_contents($output_path);
		$this->assertStringContainsString('<?php', $content);
		$this->assertStringContainsString('/path/to/file.php', $content);
	}

	/**
	 * Test writing to non-existent directory fails.
	 */
	public function test_write_file_nonexistent_dir(): void {

		$result = $this->generator->write_file(
			['/path/to/file.php'],
			'/nonexistent/dir/preload.php'
		);

		$this->assertIsString($result);
		$this->assertStringContainsString('does not exist', $result);
	}

	/**
	 * Test getting preload file info.
	 */
	public function test_get_preload_file_info(): void {

		$output_path = $this->temp_dir . '/preload.php';
		$this->generator->write_file(['/path/to/file.php'], $output_path);

		$info = $this->generator->get_preload_file_info($output_path);

		$this->assertIsArray($info);
		$this->assertEquals($output_path, $info['path']);
		$this->assertArrayHasKey('size', $info);
		$this->assertArrayHasKey('modified', $info);
		$this->assertArrayHasKey('actual_count', $info);
		$this->assertEquals(1, $info['actual_count']);
	}

	/**
	 * Test getting info for non-existent file returns null.
	 */
	public function test_get_preload_file_info_nonexistent(): void {

		$info = $this->generator->get_preload_file_info('/nonexistent/preload.php');

		$this->assertNull($info);
	}

	/**
	 * Test deleting preload file.
	 */
	public function test_delete_preload_file(): void {

		$output_path = $this->temp_dir . '/preload.php';
		$this->generator->write_file(['/path/to/file.php'], $output_path);

		$this->assertFileExists($output_path);

		$result = $this->generator->delete_preload_file($output_path);

		$this->assertTrue($result);
		$this->assertFileDoesNotExist($output_path);
	}

	/**
	 * Test deleting non-existent file returns error.
	 */
	public function test_delete_nonexistent_file(): void {

		$result = $this->generator->delete_preload_file('/nonexistent/preload.php');

		$this->assertIsString($result);
		$this->assertStringContainsString('does not exist', $result);
	}

	/**
	 * Test preview method.
	 */
	public function test_preview(): void {

		$files   = ['/path/to/file.php'];
		$preview = $this->generator->preview($files);

		$this->assertIsString($preview);
		$this->assertStringContainsString('<?php', $preview);
		$this->assertStringContainsString('/path/to/file.php', $preview);
	}

	/**
	 * Test validate_files method.
	 */
	public function test_validate_files(): void {

		// Create a real temp file.
		$real_file = $this->temp_dir . '/real.php';
		file_put_contents($real_file, '<?php class Test {}');

		$files = [
			$real_file,
			'/nonexistent/file.php',
			$this->temp_dir . '/not-php.txt',
		];

		// Create the .txt file.
		file_put_contents($this->temp_dir . '/not-php.txt', 'Not PHP');

		$result = $this->generator->validate_files($files);

		$this->assertContains($real_file, $result['valid']);
		$this->assertArrayHasKey('/nonexistent/file.php', $result['invalid']);
		$this->assertArrayHasKey($this->temp_dir . '/not-php.txt', $result['invalid']);
	}

	/**
	 * Test get_suggested_output_path.
	 */
	public function test_get_suggested_output_path(): void {

		$path = $this->generator->get_suggested_output_path();

		$this->assertStringEndsWith('preload.php', $path);
		$this->assertStringContainsString(ABSPATH, $path);
	}

	/**
	 * Test get_php_ini_config.
	 */
	public function test_get_php_ini_config(): void {

		$config = $this->generator->get_php_ini_config('/path/to/preload.php');

		$this->assertStringContainsString('opcache.preload=/path/to/preload.php', $config);
		$this->assertStringContainsString('opcache.preload_user=', $config);
	}

	/**
	 * Test generating with no header.
	 */
	public function test_generate_without_header(): void {

		$content = $this->generator->generate(
			['/path/to/file.php'],
			['include_header' => false]
		);

		$this->assertStringNotContainsString('OPcache Preload File', $content);
		$this->assertStringNotContainsString('Generated:', $content);
	}

	/**
	 * Test special characters in file paths are escaped.
	 */
	public function test_escapes_special_characters(): void {

		$files   = ["/path/with'quote/file.php"];
		$content = $this->generator->generate($files);

		// The single quote should be escaped.
		$this->assertStringContainsString("\\'", $content);
	}

	/**
	 * Test file count in header matches actual files.
	 */
	public function test_file_count_in_header(): void {

		$files   = ['/file1.php', '/file2.php', '/file3.php'];
		$content = $this->generator->generate($files);

		$this->assertStringContainsString('Files included: 3', $content);
	}
}
