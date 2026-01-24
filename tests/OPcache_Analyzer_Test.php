<?php
/**
 * Tests for the OPcache_Analyzer class.
 *
 * @package OPcache_Preload_Generator
 */

namespace OPcache_Preload_Generator\Tests;

use OPcache_Preload_Generator\OPcache_Analyzer;
use WP_UnitTestCase;

/**
 * OPcache_Analyzer test case.
 */
class OPcache_Analyzer_Test extends WP_UnitTestCase {

	/**
	 * Analyzer instance.
	 *
	 * @var OPcache_Analyzer
	 */
	private OPcache_Analyzer $analyzer;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {

		parent::set_up();
		$this->analyzer = new OPcache_Analyzer();
	}

	/**
	 * Test is_available method.
	 */
	public function test_is_available(): void {

		$result = $this->analyzer->is_available();

		// Result depends on whether OPcache is enabled in test environment.
		$this->assertIsBool($result);

		if (function_exists('opcache_get_status')) {
			$status = opcache_get_status(false);
			$this->assertEquals($status !== false, $result);
		} else {
			$this->assertFalse($result);
		}
	}

	/**
	 * Test get_status method returns array or false.
	 */
	public function test_get_status(): void {

		$status = $this->analyzer->get_status();

		if ($this->analyzer->is_available()) {
			$this->assertIsArray($status);
		} else {
			$this->assertFalse($status);
		}
	}

	/**
	 * Test get_configuration method.
	 */
	public function test_get_configuration(): void {

		$config = $this->analyzer->get_configuration();

		if (function_exists('opcache_get_configuration')) {
			$this->assertTrue($config === false || is_array($config));
		} else {
			$this->assertFalse($config);
		}
	}

	/**
	 * Test get_scripts method returns array.
	 */
	public function test_get_scripts(): void {

		$scripts = $this->analyzer->get_scripts();

		$this->assertIsArray($scripts);
	}

	/**
	 * Test get_scripts_by_hits returns sorted array.
	 */
	public function test_get_scripts_by_hits(): void {

		$scripts = $this->analyzer->get_scripts_by_hits();

		$this->assertIsArray($scripts);

		// If there are scripts, verify they're sorted by hits (descending).
		if (count($scripts) > 1) {
			for ($i = 0; $i < count($scripts) - 1; $i++) {
				$this->assertGreaterThanOrEqual(
					$scripts[ $i + 1 ]['hits'] ?? 0,
					$scripts[ $i ]['hits'] ?? 0,
					'Scripts should be sorted by hits in descending order'
				);
			}
		}
	}

	/**
	 * Test get_scripts_by_hits with limit.
	 */
	public function test_get_scripts_by_hits_with_limit(): void {

		$all_scripts     = $this->analyzer->get_scripts_by_hits();
		$limited_scripts = $this->analyzer->get_scripts_by_hits(5);

		if (count($all_scripts) > 5) {
			$this->assertCount(5, $limited_scripts);
		} else {
			$this->assertCount(count($all_scripts), $limited_scripts);
		}
	}

	/**
	 * Test get_scripts_by_memory returns sorted array.
	 */
	public function test_get_scripts_by_memory(): void {

		$scripts = $this->analyzer->get_scripts_by_memory();

		$this->assertIsArray($scripts);

		// If there are scripts, verify they're sorted by memory (descending).
		if (count($scripts) > 1) {
			for ($i = 0; $i < count($scripts) - 1; $i++) {
				$this->assertGreaterThanOrEqual(
					$scripts[ $i + 1 ]['memory_consumption'] ?? 0,
					$scripts[ $i ]['memory_consumption'] ?? 0,
					'Scripts should be sorted by memory consumption in descending order'
				);
			}
		}
	}

	/**
	 * Test filter_wordpress_scripts method.
	 */
	public function test_filter_wordpress_scripts(): void {

		$scripts = [
			['full_path' => ABSPATH . 'wp-includes/class-wp.php'],
			['full_path' => '/usr/share/php/external.php'],
			['full_path' => ABSPATH . 'wp-content/plugins/test.php'],
		];

		$filtered = $this->analyzer->filter_wordpress_scripts($scripts);

		// Only WordPress files should remain.
		foreach ($filtered as $script) {
			$realpath = realpath($script['full_path']);
			if ($realpath) {
				$this->assertStringStartsWith(
					realpath(ABSPATH),
					$realpath,
					'Filtered scripts should be within ABSPATH'
				);
			}
		}
	}

	/**
	 * Test exclude_patterns method.
	 */
	public function test_exclude_patterns(): void {

		$scripts = [
			['full_path' => '/var/www/html/wp-content/plugins/test/test.php'],
			['full_path' => '/var/www/html/wp-content/plugins/test/tests/unit-test.php'],
			['full_path' => '/var/www/html/vendor/package/src/Class.php'],
			['full_path' => '/var/www/html/vendor/package/tests/Test.php'],
		];

		$patterns = ['*/tests/*', '*/vendor/*test*'];
		$filtered = $this->analyzer->exclude_patterns($scripts, $patterns);

		$this->assertCount(2, $filtered);

		$paths = array_column($filtered, 'full_path');
		$this->assertContains('/var/www/html/wp-content/plugins/test/test.php', $paths);
		$this->assertContains('/var/www/html/vendor/package/src/Class.php', $paths);
	}

	/**
	 * Test get_memory_stats returns expected structure.
	 */
	public function test_get_memory_stats(): void {

		$stats = $this->analyzer->get_memory_stats();

		$this->assertIsArray($stats);
		$this->assertArrayHasKey('used', $stats);
		$this->assertArrayHasKey('free', $stats);
		$this->assertArrayHasKey('wasted', $stats);
		$this->assertArrayHasKey('total', $stats);
		$this->assertArrayHasKey('used_pct', $stats);
		$this->assertArrayHasKey('wasted_pct', $stats);
	}

	/**
	 * Test get_cache_stats returns expected structure.
	 */
	public function test_get_cache_stats(): void {

		$stats = $this->analyzer->get_cache_stats();

		$this->assertIsArray($stats);
		$this->assertArrayHasKey('num_cached_scripts', $stats);
		$this->assertArrayHasKey('hits', $stats);
		$this->assertArrayHasKey('misses', $stats);
		$this->assertArrayHasKey('hit_rate', $stats);
	}

	/**
	 * Test is_preloading_enabled method.
	 */
	public function test_is_preloading_enabled(): void {

		$result = $this->analyzer->is_preloading_enabled();

		$this->assertIsBool($result);
	}

	/**
	 * Test get_preload_file method.
	 */
	public function test_get_preload_file(): void {

		$result = $this->analyzer->get_preload_file();

		$this->assertIsString($result);
	}

	/**
	 * Test get_preload_user method.
	 */
	public function test_get_preload_user(): void {

		$result = $this->analyzer->get_preload_user();

		$this->assertIsString($result);
	}

	/**
	 * Test get_preloaded_scripts method.
	 */
	public function test_get_preloaded_scripts(): void {

		$result = $this->analyzer->get_preloaded_scripts();

		$this->assertIsArray($result);
	}

	/**
	 * Test empty patterns doesn't filter anything.
	 */
	public function test_exclude_patterns_empty(): void {

		$scripts = [
			['full_path' => '/path/to/file1.php'],
			['full_path' => '/path/to/file2.php'],
		];

		$filtered = $this->analyzer->exclude_patterns($scripts, []);

		$this->assertCount(2, $filtered);
	}

	/**
	 * Test scripts have full_path key after processing.
	 */
	public function test_scripts_have_full_path(): void {

		$scripts = $this->analyzer->get_scripts_by_hits();

		foreach ($scripts as $script) {
			$this->assertArrayHasKey('full_path', $script);
		}
	}
}
