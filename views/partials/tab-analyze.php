<?php
/**
 * Analyze Files tab template.
 *
 * @package OPcache_Preload_Generator
 * @var OPcache_Preload_Generator\Admin_Page $this
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

$settings = $this->plugin->get_settings();
?>

<div class="opcache-analyze-tab">
	<h2><?php esc_html_e('Analyze Files for Preloading', 'opcache-preload-generator'); ?></h2>
	<p class="description">
		<?php esc_html_e('Analyze files from your OPcache to find the best candidates for preloading. Files are ranked by hit count - the most frequently accessed files benefit most from preloading.', 'opcache-preload-generator'); ?>
	</p>

	<?php if (! $this->plugin->is_opcache_available()) : ?>
		<div class="notice notice-error inline">
			<p><?php esc_html_e('OPcache is not available. Please enable OPcache to analyze cached files.', 'opcache-preload-generator'); ?></p>
		</div>
	<?php else : ?>

		<!-- Analyze Controls -->
		<div class="opcache-analyze-controls">
			<div class="opcache-form-row">
				<label for="opcache-analyze-limit">
					<?php esc_html_e('Number of files to analyze:', 'opcache-preload-generator'); ?>
				</label>
				<select id="opcache-analyze-limit">
					<option value="50">50</option>
					<option value="100" <?php selected($settings['auto_suggest_top'], 100); ?>>100</option>
					<option value="250">250</option>
					<option value="500">500</option>
					<option value="1000" <?php selected($settings['auto_suggest_top'], 1000); ?>>1,000</option>
					<option value="2500">2,500</option>
					<option value="5000">5,000</option>
					<option value="10000">10,000</option>
				</select>
				<label for="opcache-per-page">
					<?php esc_html_e('Per page:', 'opcache-preload-generator'); ?>
				</label>
				<select id="opcache-per-page">
					<option value="25">25</option>
					<option value="50" selected>50</option>
					<option value="100">100</option>
					<option value="250">250</option>
				</select>
				<button type="button" id="opcache-analyze-suggested" class="button button-primary">
					<?php esc_html_e('Analyze Top Files', 'opcache-preload-generator'); ?>
				</button>
			</div>
		</div>

		<!-- Results Container -->
		<div id="opcache-analyze-results" style="display: none;">
			<h3><?php esc_html_e('Analysis Results', 'opcache-preload-generator'); ?></h3>

			<div class="opcache-results-summary">
				<span id="opcache-results-total"></span>
				<span id="opcache-results-safe"></span>
				<span id="opcache-results-warnings"></span>
				<span id="opcache-results-errors"></span>
			</div>

			<div class="opcache-results-actions">
				<button type="button" id="opcache-add-all-safe" class="button">
					<?php esc_html_e('Add All Safe Files', 'opcache-preload-generator'); ?>
				</button>
				<button type="button" id="opcache-add-selected" class="button">
					<?php esc_html_e('Add Selected Files', 'opcache-preload-generator'); ?>
				</button>
				<label class="opcache-checkbox-label">
					<input type="checkbox" id="opcache-select-all-safe">
					<?php esc_html_e('Select all safe files', 'opcache-preload-generator'); ?>
				</label>
			</div>

			<!-- Pagination Top -->
			<div class="tablenav top" id="opcache-pagination-top">
				<div class="tablenav-pages">
					<span class="displaying-num" id="opcache-displaying-num-top"></span>
					<span class="pagination-links" id="opcache-pagination-links-top">
						<button type="button" class="button opcache-page-first" title="<?php esc_attr_e('First page', 'opcache-preload-generator'); ?>">&laquo;</button>
						<button type="button" class="button opcache-page-prev" title="<?php esc_attr_e('Previous page', 'opcache-preload-generator'); ?>">&lsaquo;</button>
						<span class="paging-input">
							<input type="number" class="opcache-current-page current-page" min="1" value="1">
							<?php esc_html_e('of', 'opcache-preload-generator'); ?>
							<span class="opcache-total-pages total-pages">1</span>
						</span>
						<button type="button" class="button opcache-page-next" title="<?php esc_attr_e('Next page', 'opcache-preload-generator'); ?>">&rsaquo;</button>
						<button type="button" class="button opcache-page-last" title="<?php esc_attr_e('Last page', 'opcache-preload-generator'); ?>">&raquo;</button>
					</span>
				</div>
			</div>

			<table class="wp-list-table widefat fixed striped" id="opcache-results-table">
				<thead>
					<tr>
						<th class="check-column"><input type="checkbox" id="opcache-select-all"></th>
						<th><?php esc_html_e('File', 'opcache-preload-generator'); ?></th>
						<th class="column-status"><?php esc_html_e('Status', 'opcache-preload-generator'); ?></th>
						<th class="column-hits"><?php esc_html_e('Hits', 'opcache-preload-generator'); ?></th>
						<th class="column-memory"><?php esc_html_e('Memory', 'opcache-preload-generator'); ?></th>
						<th class="column-actions"><?php esc_html_e('Actions', 'opcache-preload-generator'); ?></th>
					</tr>
				</thead>
				<tbody id="opcache-results-body">
					<!-- Results loaded via AJAX -->
				</tbody>
			</table>

			<!-- Pagination Bottom -->
			<div class="tablenav bottom" id="opcache-pagination-bottom">
				<div class="tablenav-pages">
					<span class="displaying-num" id="opcache-displaying-num-bottom"></span>
					<span class="pagination-links" id="opcache-pagination-links-bottom">
						<button type="button" class="button opcache-page-first" title="<?php esc_attr_e('First page', 'opcache-preload-generator'); ?>">&laquo;</button>
						<button type="button" class="button opcache-page-prev" title="<?php esc_attr_e('Previous page', 'opcache-preload-generator'); ?>">&lsaquo;</button>
						<span class="paging-input">
							<input type="number" class="opcache-current-page current-page" min="1" value="1">
							<?php esc_html_e('of', 'opcache-preload-generator'); ?>
							<span class="opcache-total-pages total-pages">1</span>
						</span>
						<button type="button" class="button opcache-page-next" title="<?php esc_attr_e('Next page', 'opcache-preload-generator'); ?>">&rsaquo;</button>
						<button type="button" class="button opcache-page-last" title="<?php esc_attr_e('Last page', 'opcache-preload-generator'); ?>">&raquo;</button>
					</span>
				</div>
			</div>
		</div>

		<!-- Loading Indicator -->
		<div id="opcache-analyze-loading" style="display: none;">
			<span class="spinner is-active"></span>
			<?php esc_html_e('Analyzing files...', 'opcache-preload-generator'); ?>
		</div>

		<!-- No Results Message -->
		<div id="opcache-analyze-empty" style="display: none;">
			<p><?php esc_html_e('No cached files found. Make sure OPcache is enabled and has cached some files.', 'opcache-preload-generator'); ?></p>
		</div>

	<?php endif; ?>
</div>

<!-- Row Template -->
<script type="text/html" id="tmpl-opcache-result-row">
	<tr class="opcache-result-row opcache-result-{{ data.statusClass }}" data-file="{{ data.file }}">
		<td class="check-column">
			<input type="checkbox" class="opcache-file-checkbox"
					value="{{ data.file }}"
					{{ data.in_list ? 'disabled' : '' }}
					data-safe="{{ data.isSafe ? '1' : '0' }}">
		</td>
		<td class="column-file">
			<strong>{{ data.relative }}</strong>
			<# if (data.in_list) { #>
				<span class="opcache-badge opcache-badge-info"><?php esc_html_e('In List', 'opcache-preload-generator'); ?></span>
			<# } #>
			<br>
			<small class="opcache-full-path">{{ data.file }}</small>
		</td>
		<td class="column-status">
			{{{ data.badge }}}
			<# if (data.warnings.length > 0 || data.errors.length > 0) { #>
				<button type="button" class="button-link opcache-view-details" data-file="{{ data.file }}">
					<?php esc_html_e('View details', 'opcache-preload-generator'); ?>
				</button>
			<# } #>
		</td>
		<td class="column-hits">{{ data.hitsFormatted }}</td>
		<td class="column-memory">{{ data.memoryFormatted }}</td>
		<td class="column-actions">
			<# if (!data.in_list && data.isSafe) { #>
				<button type="button" class="button button-small opcache-add-file" data-file="{{ data.file }}">
					<?php esc_html_e('Add', 'opcache-preload-generator'); ?>
				</button>
			<# } else if (!data.in_list) { #>
				<button type="button" class="button button-small opcache-add-file" data-file="{{ data.file }}">
					<?php esc_html_e('Add Anyway', 'opcache-preload-generator'); ?>
				</button>
			<# } else { #>
				<span class="opcache-already-added"><?php esc_html_e('Added', 'opcache-preload-generator'); ?></span>
			<# } #>
		</td>
	</tr>
</script>
