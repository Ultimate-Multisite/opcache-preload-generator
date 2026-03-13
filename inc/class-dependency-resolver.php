<?php
/**
 * Dependency Resolver class.
 *
 * Resolves class dependencies and sorts files for proper loading order.
 * Uses PHP-Parser for accurate AST-based dependency analysis.
 *
 * @package OPcache_Preload_Generator
 */

namespace OPcache_Preload_Generator;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

/**
 * Resolves class dependencies for preload ordering using PHP-Parser.
 */
class Dependency_Resolver {

	/**
	 * PHP-Parser instance.
	 *
	 * @var \PhpParser\Parser\Php7|null
	 */
	private $parser;

	/**
	 * Map of class names to file paths.
	 *
	 * @var array<string, string>
	 */
	private array $class_to_file = [];

	/**
	 * Map of file paths to class definitions.
	 *
	 * @var array<string, array<string>>
	 */
	private array $file_to_classes = [];

	/**
	 * Map of file paths to dependencies (extends/implements/uses).
	 *
	 * @var array<string, array<string>>
	 */
	private array $file_dependencies = [];

	/**
	 * Map of file paths to parsed data.
	 *
	 * @var array<string, array>
	 */
	private array $file_data = [];

	/**
	 * Core PHP classes that don't need to be resolved.
	 *
	 * @var array<string>
	 */
	private array $core_classes = [
		'stdClass',
		'Exception',
		'Error',
		'Throwable',
		'Iterator',
		'IteratorAggregate',
		'ArrayAccess',
		'Countable',
		'Serializable',
		'Traversable',
		'JsonSerializable',
		'Stringable',
		'Closure',
		'Generator',
		'DateTime',
		'DateTimeImmutable',
		'DateTimeInterface',
		'DateInterval',
		'DateTimeZone',
		'PDO',
		'PDOStatement',
		'PDOException',
		'ReflectionClass',
		'ReflectionMethod',
		'ReflectionProperty',
		'ReflectionException',
		'ArrayObject',
		'SplFileInfo',
		'SplFileObject',
		'SplObjectStorage',
		'WeakReference',
		'WeakMap',
	];

	/**
	 * Get or create the PHP-Parser instance.
	 *
	 * Supports both PHP-Parser v4 (create method) and v5 (createForNewestSupportedVersion).
	 *
	 * @return \PhpParser\Parser
	 */
	private function get_parser() {

		if (null === $this->parser) {
			// Use fully qualified name to avoid conflicts with other versions.
			$factory = new \PhpParser\ParserFactory();

			// Check if we're using v4 or v5 of PHP-Parser.
			if (method_exists($factory, 'create')) {
				// PHP-Parser v4.x.
				$this->parser = $factory->create(\PhpParser\ParserFactory::PREFER_PHP7);
			} else {
				// PHP-Parser v5.x.
				$this->parser = $factory->createForNewestSupportedVersion();
			}
		}

		return $this->parser;
	}

	/**
	 * Parse a PHP file to extract class information using PHP-Parser.
	 *
	 * @param string $path File path.
	 * @return array{namespace: string, classes: array, dependencies: array}
	 */
	public function parse_file(string $path): array {

		// Return cached result if available.
		if (isset($this->file_data[ $path ])) {
			return $this->file_data[ $path ];
		}

		$result = [
			'namespace'    => '',
			'use_imports'  => [],
			'classes'      => [],
			'interfaces'   => [],
			'traits'       => [],
			'dependencies' => [],
		];

		if (! file_exists($path) || ! is_readable($path)) {
			return $result;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents($path);

		if (false === $content) {
			return $result;
		}

		try {
			$ast = $this->get_parser()->parse($content);

			if (null === $ast) {
				return $result;
			}

			// Use NameResolver visitor to resolve fully qualified names.
			$traverser = new NodeTraverser();
			$traverser->addVisitor(new NameResolver());
			$ast = $traverser->traverse($ast);

			// Extract information using visitor.
			$visitor = new DependencyVisitor();
			$traverser = new NodeTraverser();
			$traverser->addVisitor($visitor);
			$traverser->traverse($ast);

			$result = $visitor->get_result();
		} catch (\Exception $e) {
			// If parsing fails, return empty result.
			// This can happen with PHP files that have syntax errors or use unsupported features.
		}

		// Cache result.
		$this->file_data[ $path ] = $result;

		return $result;
	}

	/**
	 * Resolve a class name to its fully qualified name.
	 *
	 * @param string               $name              Class name.
	 * @param string               $current_namespace Current namespace.
	 * @param array<string,string> $use_imports       Use statement imports.
	 * @return string Fully qualified class name.
	 */
	private function resolve_class_name(string $name, string $current_namespace, array $use_imports): string {

		// Already fully qualified.
		if (0 === strpos($name, '\\')) {
			return ltrim($name, '\\');
		}

		// Check for alias in use imports.
		$parts      = explode('\\', $name);
		$first_part = $parts[0];

		if (isset($use_imports[ $first_part ])) {
			if (1 === count($parts)) {
				return $use_imports[ $first_part ];
			}
			array_shift($parts);
			return $use_imports[ $first_part ] . '\\' . implode('\\', $parts);
		}

		// Relative to current namespace.
		if (! empty($current_namespace)) {
			return $current_namespace . '\\' . $name;
		}

		return $name;
	}

	/**
	 * Check if a class is a core PHP or WordPress class.
	 *
	 * @param string $class_name Class name.
	 * @return bool
	 */
	private function is_core_class(string $class_name): bool {

		$class_name = ltrim($class_name, '\\');

		// Core PHP classes.
		if (in_array($class_name, $this->core_classes, true)) {
			return true;
		}

		return false;
	}

	/**
	 * Build a class map from an array of file paths.
	 *
	 * @param array<string> $files File paths.
	 * @return void
	 */
	public function build_class_map(array $files): void {

		foreach ($files as $file) {
			$data = $this->parse_file($file);

			// Map classes to files.
			foreach ($data['classes'] as $class) {
				$this->class_to_file[ $class ]    = $file;
				$this->file_to_classes[ $file ][] = $class;
			}

			// Map interfaces to files.
			foreach ($data['interfaces'] as $interface) {
				$this->class_to_file[ $interface ] = $file;
				$this->file_to_classes[ $file ][]  = $interface;
			}

			// Map traits to files.
			foreach ($data['traits'] as $trait) {
				$this->class_to_file[ $trait ]    = $file;
				$this->file_to_classes[ $file ][] = $trait;
			}

			// Store dependencies.
			$this->file_dependencies[ $file ] = $data['dependencies'];
		}
	}

	/**
	 * Get the file that defines a class.
	 *
	 * @param string $class_name Class name.
	 * @return string|null File path or null if not found.
	 */
	public function get_class_file(string $class_name): ?string {

		return $this->class_to_file[ $class_name ] ?? null;
	}

	/**
	 * Get file dependencies.
	 *
	 * @param string $file File path.
	 * @return array<string> Array of dependency class names.
	 */
	public function get_file_dependencies(string $file): array {

		if (! isset($this->file_dependencies[ $file ])) {
			$data                             = $this->parse_file($file);
			$this->file_dependencies[ $file ] = $data['dependencies'];
		}

		return $this->file_dependencies[ $file ];
	}

	/**
	 * Get unresolved dependencies for a file.
	 *
	 * Returns dependencies that aren't in the provided file list and aren't core classes.
	 *
	 * @param string        $file  File path.
	 * @param array<string> $files Array of available files.
	 * @return array<string> Unresolved dependency class names.
	 */
	public function get_unresolved_dependencies(string $file, array $files): array {

		$dependencies = $this->get_file_dependencies($file);
		$unresolved   = [];

		foreach ($dependencies as $dep) {
			// Skip if it's a core class.
			if ($this->is_core_class($dep)) {
				continue;
			}

			// Check if the dependency is in our file list.
			$dep_file = $this->get_class_file($dep);

			if (null === $dep_file || ! in_array($dep_file, $files, true)) {
				$unresolved[] = $dep;
			}
		}

		return $unresolved;
	}

	/**
	 * Sort files by dependency order.
	 *
	 * Files with no dependencies come first, then files whose dependencies are satisfied.
	 *
	 * @param array<string> $files File paths to sort.
	 * @return array{sorted: array<string>, unresolvable: array<string>}
	 */
	public function sort_by_dependencies(array $files): array {

		// Build class map.
		$this->build_class_map($files);

		$sorted         = [];
		$unresolvable   = [];
		$remaining      = array_flip($files);
		$max_iterations = count($files) * 2; // Prevent infinite loops.
		$iterations     = 0;

		while (! empty($remaining) && $iterations < $max_iterations) {
			++$iterations;
			$progress = false;

			foreach (array_keys($remaining) as $file) {
				$deps    = $this->get_file_dependencies($file);
				$can_add = true;

				foreach ($deps as $dep) {
					// Skip core classes.
					if ($this->is_core_class($dep)) {
						continue;
					}

					// Find the file that provides this dependency.
					$dep_file = $this->get_class_file($dep);

					// If dependency is in our list but not yet sorted, we can't add this file yet.
					if (null !== $dep_file && isset($remaining[ $dep_file ])) {
						$can_add = false;
						break;
					}
				}

				if ($can_add) {
					$sorted[] = $file;
					unset($remaining[ $file ]);
					$progress = true;
				}
			}

			// If no progress was made, we have circular dependencies or unresolvable deps.
			if (! $progress && ! empty($remaining)) {
				break;
			}
		}

		// Any remaining files have unresolvable dependencies.
		$unresolvable = array_keys($remaining);

		return [
			'sorted'       => $sorted,
			'unresolvable' => $unresolvable,
		];
	}

	/**
	 * Get files that need to be added as dependencies.
	 *
	 * @param string        $file  File to analyze.
	 * @param array<string> $existing_files Files already in the preload list.
	 * @return array<string> Additional files needed.
	 */
	public function get_required_dependencies(string $file, array $existing_files = []): array {

		$required = [];
		$checked  = [];
		$to_check = [$file];

		while (! empty($to_check)) {
			$current = array_shift($to_check);

			if (isset($checked[ $current ])) {
				continue;
			}
			$checked[ $current ] = true;

			$deps = $this->get_file_dependencies($current);

			foreach ($deps as $dep) {
				if ($this->is_core_class($dep)) {
					continue;
				}

				$dep_file = $this->get_class_file($dep);

				if (null !== $dep_file && ! in_array($dep_file, $existing_files, true) && ! in_array($dep_file, $required, true)) {
					$required[] = $dep_file;
					$to_check[] = $dep_file;
				}
			}
		}

		return $required;
	}

	/**
	 * Check if a file has all its dependencies satisfied.
	 *
	 * @param string        $file            File to check.
	 * @param array<string> $available_files Files available for dependency resolution.
	 * @return bool
	 */
	public function has_satisfied_dependencies(string $file, array $available_files): bool {

		$deps = $this->get_file_dependencies($file);

		foreach ($deps as $dep) {
			if ($this->is_core_class($dep)) {
				continue;
			}

			$dep_file = $this->get_class_file($dep);

			// Dependency must be provided by one of the available files.
			if (null === $dep_file || ! in_array($dep_file, $available_files, true)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Clear cached data.
	 *
	 * @return void
	 */
	public function clear_cache(): void {

		$this->class_to_file     = [];
		$this->file_to_classes   = [];
		$this->file_dependencies = [];
		$this->file_data         = [];
	}
}

/**
 * AST Visitor for extracting dependencies.
 */
class DependencyVisitor implements NodeVisitor {

	/**
	 * Parser result.
	 *
	 * @var array
	 */
	private array $result = [
		'namespace'    => '',
		'use_imports'  => [],
		'classes'      => [],
		'interfaces'   => [],
		'traits'       => [],
		'dependencies' => [],
	];

	/**
	 * Current namespace.
	 *
	 * @var string
	 */
	private string $current_namespace = '';

	/**
	 * Called once before traversal.
	 *
	 * @param array $nodes AST nodes.
	 * @return void
	 */
	public function beforeTraverse(array $nodes): void {

		$this->result = [
			'namespace'    => '',
			'use_imports'  => [],
			'classes'      => [],
			'interfaces'   => [],
			'traits'       => [],
			'dependencies' => [],
		];
		$this->current_namespace = '';
	}

	/**
	 * Called when entering a node.
	 *
	 * @param Node $node Node.
	 * @return int|null
	 */
	public function enterNode(Node $node): ?int {

		if ($node instanceof Node\Stmt\Namespace_) {
			$this->current_namespace = $node->name ? $node->name->toString() : '';
			$this->result['namespace'] = $this->current_namespace;
		}

		if ($node instanceof Node\Stmt\Use_) {
			foreach ($node->uses as $use) {
				$name = $use->name->toString();
				$alias = $use->getAlias()->toString();
				$this->result['use_imports'][ $alias ] = $name;
			}
		}

		if ($node instanceof Node\Stmt\Class_) {
			if ($node->namespacedName) {
				$class_name = $node->namespacedName->toString();
				$this->result['classes'][] = $class_name;

				// Check extends.
				if ($node->extends) {
					$this->add_dependency($node->extends->toString());
				}

				// Check implements.
				foreach ($node->implements as $interface) {
					$this->add_dependency($interface->toString());
				}

				// Check trait uses.
				foreach ($node->stmts as $stmt) {
					if ($stmt instanceof Node\Stmt\TraitUse) {
						foreach ($stmt->traits as $trait) {
							$this->add_dependency($trait->toString());
						}
					}
				}
			}
		}

		if ($node instanceof Node\Stmt\Interface_) {
			if ($node->namespacedName) {
				$interface_name = $node->namespacedName->toString();
				$this->result['interfaces'][] = $interface_name;

				// Check extends (interfaces can extend other interfaces).
				foreach ($node->extends as $parent) {
					$this->add_dependency($parent->toString());
				}
			}
		}

		if ($node instanceof Node\Stmt\Trait_) {
			if ($node->namespacedName) {
				$trait_name = $node->namespacedName->toString();
				$this->result['traits'][] = $trait_name;
			}
		}

		return null;
	}

	/**
	 * Add a dependency.
	 *
	 * @param string $name Class name.
	 * @return void
	 */
	private function add_dependency(string $name): void {

		$name = ltrim($name, '\\');

		// Skip core classes.
		$core_classes = [
			'stdClass', 'Exception', 'Error', 'Throwable', 'Iterator', 'IteratorAggregate',
			'ArrayAccess', 'Countable', 'Serializable', 'Traversable', 'JsonSerializable',
			'Stringable', 'Closure', 'Generator', 'DateTime', 'DateTimeImmutable',
			'DateTimeInterface', 'DateInterval', 'DateTimeZone', 'PDO', 'PDOStatement',
			'PDOException', 'ReflectionClass', 'ReflectionMethod', 'ReflectionProperty',
			'ReflectionException', 'ArrayObject', 'SplFileInfo', 'SplFileObject',
			'SplObjectStorage', 'WeakReference', 'WeakMap',
		];

		if (in_array($name, $core_classes, true)) {
			return;
		}

		$this->result['dependencies'][] = $name;
	}

	/**
	 * Called when leaving a node.
	 *
	 * @param Node $node Node.
	 * @return null
	 */
	public function leaveNode(Node $node): ?int {

		return null;
	}

	/**
	 * Called once after traversal.
	 *
	 * @param array $nodes AST nodes.
	 * @return void
	 */
	public function afterTraverse(array $nodes): void {

		// Remove duplicates from dependencies.
		$this->result['dependencies'] = array_unique($this->result['dependencies']);
	}

	/**
	 * Get the parsing result.
	 *
	 * @return array
	 */
	public function get_result(): array {

		return $this->result;
	}
}
