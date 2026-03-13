<?php
/**
 * File List Table class.
 *
 * @package OPcache_Preload_Generator
 */

namespace OPcache_Preload_Generator;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

// Load WP_List_Table if not already loaded.
if (! class_exists('WP_List_Table')) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Custom WP_List_Table for managing preload files.
 */
class File_List_Table extends \WP_List_Table {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct(Plugin $plugin) {

		$this->plugin = $plugin;

		parent::__construct(
			[
				'singular' => 'preload_file',
				'plural'   => 'preload_files',
				'ajax'     => false,
			]
		);
	}

	/**
	 * Get columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {

		return [
			'cb'       => '<input type="checkbox" />',
			'file'     => __('File', 'opcache-preload-generator'),
			'status'   => __('Status', 'opcache-preload-generator'),
			'size'     => __('Size', 'opcache-preload-generator'),
			'modified' => __('Modified', 'opcache-preload-generator'),
			'actions'  => __('Actions', 'opcache-preload-generator'),
		];
	}

	/**
	 * Get sortable columns.
	 *
	 * @return array<string, array<int, string|bool>>
	 */
	public function get_sortable_columns(): array {

		return [
			'file'     => ['file', false],
			'size'     => ['size', false],
			'modified' => ['modified', true],
		];
	}

	/**
	 * Prepare items for display.
	 *
	 * @return void
	 */
	public function prepare_items(): void {

		$columns  = $this->get_columns();
		$hidden   = [];
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = [$columns, $hidden, $sortable];

		$files_config = $this->plugin->get_preload_files_config();
		$data         = [];

		foreach ($files_config as $file_config) {
			$file     = $file_config['path'];
			$method   = $file_config['method'];
			$exists   = file_exists($file);
			$filesize = $exists ? filesize($file) : 0;
			$modified = $exists ? filemtime($file) : 0;

			$data[] = [
				'file'     => $file,
				'method'   => $method,
				'exists'   => $exists,
				'size'     => $filesize,
				'modified' => $modified,
			];
		}

		// Handle sorting.
		$orderby = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'file'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order   = isset($_GET['order']) ? sanitize_key($_GET['order']) : 'asc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		usort(
			$data,
			function ($a, $b) use ($orderby, $order) {
				$result = 0;

				switch ($orderby) {
					case 'size':
						$result = $a['size'] <=> $b['size'];
						break;
					case 'modified':
						$result = $a['modified'] <=> $b['modified'];
						break;
					case 'file':
					default:
						$result = strcmp($a['file'], $b['file']);
						break;
				}

				return 'asc' === $order ? $result : -$result;
			}
		);

		// Pagination.
		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$total_items  = count($data);

		$this->set_pagination_args(
			[
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil($total_items / $per_page),
			]
		);

		$this->items = array_slice($data, ($current_page - 1) * $per_page, $per_page);
	}

	/**
	 * Render the checkbox column.
	 *
	 * @param array<string, mixed> $item Item data.
	 * @return string
	 */
	public function column_cb($item): string {

		return sprintf(
			'<input type="checkbox" name="files[]" value="%s" />',
			esc_attr($item['file'])
		);
	}

	/**
	 * Render the file column.
	 *
	 * @param array<string, mixed> $item Item data.
	 * @return string
	 */
	public function column_file($item): string {

		$relative = $this->get_relative_path($item['file']);

		$output = '<strong>' . esc_html($relative) . '</strong>';

		if (! $item['exists']) {
			$output .= ' <span class="opcache-badge opcache-badge-error">' . esc_html__('Missing', 'opcache-preload-generator') . '</span>';
		}

		$output .= '<br><small class="opcache-full-path" title="' . esc_attr($item['file']) . '">' . esc_html($item['file']) . '</small>';

		return $output;
	}

	/**
	 * Render the status column.
	 *
	 * @param array<string, mixed> $item Item data.
	 * @return string
	 */
	public function column_status($item): string {

		if (! $item['exists']) {
			return '<span class="opcache-badge opcache-badge-error">' . esc_html__('File Missing', 'opcache-preload-generator') . '</span>';
		}

		// Get cached analysis if available.
		$analysis = $this->plugin->safety_analyzer->analyze_file($item['file']);

		return $this->plugin->admin_page->get_analysis_badge($analysis);
	}

	/**
	 * Render the size column.
	 *
	 * @param array<string, mixed> $item Item data.
	 * @return string
	 */
	public function column_size($item): string {

		if (! $item['exists']) {
			return '&mdash;';
		}

		return $this->plugin->admin_page->format_bytes($item['size']);
	}

	/**
	 * Render the modified column.
	 *
	 * @param array<string, mixed> $item Item data.
	 * @return string
	 */
	public function column_modified($item): string {

		if (! $item['exists'] || 0 === $item['modified']) {
			return '&mdash;';
		}

		return $this->plugin->admin_page->format_timestamp($item['modified']);
	}

	/**
	 * Render the actions column.
	 *
	 * @param array<string, mixed> $item Item data.
	 * @return string
	 */
	public function column_actions($item): string {

		$actions = [];

		$actions[] = sprintf(
			'<button type="button" class="button button-small opcache-analyze-file" data-file="%s">%s</button>',
			esc_attr($item['file']),
			esc_html__('Analyze', 'opcache-preload-generator')
		);

		$actions[] = sprintf(
			'<button type="button" class="button button-small button-link-delete opcache-remove-file" data-file="%s">%s</button>',
			esc_attr($item['file']),
			esc_html__('Remove', 'opcache-preload-generator')
		);

		return implode(' ', $actions);
	}

	/**
	 * Default column handler.
	 *
	 * @param array<string, mixed> $item        Item data.
	 * @param string               $column_name Column name.
	 * @return string
	 */
	public function column_default($item, $column_name): string {

		return isset($item[ $column_name ]) ? esc_html($item[ $column_name ]) : '';
	}

	/**
	 * Get bulk actions.
	 *
	 * @return array<string, string>
	 */
	public function get_bulk_actions(): array {

		return [
			'remove'  => __('Remove from list', 'opcache-preload-generator'),
			'analyze' => __('Analyze selected', 'opcache-preload-generator'),
		];
	}

	/**
	 * Display when no items are found.
	 *
	 * @return void
	 */
	public function no_items(): void {

		esc_html_e('No files added to the preload list yet. Go to the "Analyze Files" tab to add files.', 'opcache-preload-generator');
	}

	/**
	 * Get the relative path from ABSPATH.
	 *
	 * @param string $path Full file path.
	 * @return string
	 */
	private function get_relative_path(string $path): string {

		$abspath = realpath(ABSPATH);

		if (! $abspath) {
			return $path;
		}

		$realpath = realpath($path);

		if (! $realpath) {
			return $path;
		}

		if (strpos($realpath, $abspath) === 0) {
			return substr($realpath, strlen($abspath) + 1);
		}

		return $path;
	}

	/**
	 * Extra table navigation.
	 *
	 * @param string $which Top or bottom.
	 * @return void
	 */
	protected function extra_tablenav($which): void {

		if ('top' !== $which) {
			return;
		}

		$count = count($this->plugin->get_preload_files());
		?>
		<div class="alignleft actions">
			<span class="displaying-num">
				<?php
				printf(
					/* translators: %d: number of files */
					esc_html(_n('%d file in preload list', '%d files in preload list', $count, 'opcache-preload-generator')),
					(int) $count
				);
				?>
			</span>
		</div>
		<?php
	}
}
