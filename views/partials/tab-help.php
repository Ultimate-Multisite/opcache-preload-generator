<?php
/**
 * Help tab template.
 *
 * @package OPcache_Preload_Generator
 * @var OPcache_Preload_Generator\Admin_Page $this
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}
?>

<div class="opcache-help-tab">
	<h2><?php esc_html_e('How OPcache Preloading Works', 'opcache-preload-generator'); ?></h2>

	<div class="opcache-help-section">
		<h3><?php esc_html_e('What is Preloading?', 'opcache-preload-generator'); ?></h3>
		<p>
			<?php esc_html_e('OPcache preloading is a PHP 7.4+ feature that loads specified PHP files into memory when PHP starts (not on each request). These files become instantly available to all requests without needing to be compiled from source code.', 'opcache-preload-generator'); ?>
		</p>
		<p>
			<?php esc_html_e('Think of it like having a book already open to frequently-referenced pages instead of having to flip to them each time.', 'opcache-preload-generator'); ?>
		</p>
	</div>

	<div class="opcache-help-section">
		<h3><?php esc_html_e('How Does This Plugin Help?', 'opcache-preload-generator'); ?></h3>
		<ol>
			<li>
				<strong><?php esc_html_e('Identifies Best Candidates', 'opcache-preload-generator'); ?></strong>
				<p><?php esc_html_e('The plugin analyzes your OPcache statistics to find files that are accessed most frequently. These files benefit most from preloading.', 'opcache-preload-generator'); ?></p>
			</li>
			<li>
				<strong><?php esc_html_e('Safety Analysis', 'opcache-preload-generator'); ?></strong>
				<p><?php esc_html_e('Not all files can be safely preloaded. The plugin checks each file for potential issues like side effects, missing dependencies, and code that executes on include.', 'opcache-preload-generator'); ?></p>
			</li>
			<li>
				<strong><?php esc_html_e('Generates Preload File', 'opcache-preload-generator'); ?></strong>
				<p><?php esc_html_e('Creates a preload.php file that you can configure in php.ini. The file includes proper error handling and PHP version checks.', 'opcache-preload-generator'); ?></p>
			</li>
		</ol>
	</div>

	<div class="opcache-help-section">
		<h3><?php esc_html_e('Safety Checks Explained', 'opcache-preload-generator'); ?></h3>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e('Check', 'opcache-preload-generator'); ?></th>
					<th><?php esc_html_e('Why It Matters', 'opcache-preload-generator'); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><strong><?php esc_html_e('Blacklisted Files', 'opcache-preload-generator'); ?></strong></td>
					<td><?php esc_html_e('Some WordPress core files are known to cause issues with preloading. These are automatically blocked.', 'opcache-preload-generator'); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e('Side Effects', 'opcache-preload-generator'); ?></strong></td>
					<td><?php esc_html_e('Files that output content or execute code when included can cause errors during preload when WordPress is not fully initialized.', 'opcache-preload-generator'); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e('Define Conflicts', 'opcache-preload-generator'); ?></strong></td>
					<td><?php esc_html_e('Files with define() calls may conflict when the same constant is defined elsewhere during normal WordPress loading.', 'opcache-preload-generator'); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e('Path Constants', 'opcache-preload-generator'); ?></strong></td>
					<td><?php esc_html_e('Using __DIR__ or __FILE__ in class properties will resolve to the preload file location instead of the original file location.', 'opcache-preload-generator'); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e('Conditional Definitions', 'opcache-preload-generator'); ?></strong></td>
					<td><?php esc_html_e('class_exists() or function_exists() checks will behave differently when files are preloaded vs. loaded normally.', 'opcache-preload-generator'); ?></td>
				</tr>
			</tbody>
		</table>
	</div>

	<div class="opcache-help-section">
		<h3><?php esc_html_e('Best Practices', 'opcache-preload-generator'); ?></h3>
		<ul class="opcache-best-practices">
			<li>
				<strong><?php esc_html_e('Start Small', 'opcache-preload-generator'); ?></strong>
				<p><?php esc_html_e('Begin with a small number of safe files (20-50) and gradually add more after testing.', 'opcache-preload-generator'); ?></p>
			</li>
			<li>
				<strong><?php esc_html_e('Test in Staging', 'opcache-preload-generator'); ?></strong>
				<p><?php esc_html_e('Always test your preload configuration in a staging environment before deploying to production.', 'opcache-preload-generator'); ?></p>
			</li>
			<li>
				<strong><?php esc_html_e('Focus on Frequently Used Files', 'opcache-preload-generator'); ?></strong>
				<p><?php esc_html_e('Files with high hit counts benefit most from preloading. The plugin sorts files by hit count to help you identify these.', 'opcache-preload-generator'); ?></p>
			</li>
			<li>
				<strong><?php esc_html_e('Avoid Plugin/Theme Files', 'opcache-preload-generator'); ?></strong>
				<p><?php esc_html_e('Plugin and theme files often have dependencies on WordPress functions and may cause issues. Stick to core WordPress files and well-known libraries.', 'opcache-preload-generator'); ?></p>
			</li>
			<li>
				<strong><?php esc_html_e('Keep It Updated', 'opcache-preload-generator'); ?></strong>
				<p><?php esc_html_e('After WordPress updates, regenerate your preload file to ensure file paths are still valid.', 'opcache-preload-generator'); ?></p>
			</li>
		</ul>
	</div>

	<div class="opcache-help-section">
		<h3><?php esc_html_e('Configuration Steps', 'opcache-preload-generator'); ?></h3>
		<ol class="opcache-steps">
			<li>
				<strong><?php esc_html_e('Analyze Files', 'opcache-preload-generator'); ?></strong>
				<p><?php esc_html_e('Go to the "Analyze Files" tab and click "Analyze Top Files" to scan your OPcache.', 'opcache-preload-generator'); ?></p>
			</li>
			<li>
				<strong><?php esc_html_e('Add Safe Files', 'opcache-preload-generator'); ?></strong>
				<p><?php esc_html_e('Review the results and add files marked as "Safe" to your preload list.', 'opcache-preload-generator'); ?></p>
			</li>
			<li>
				<strong><?php esc_html_e('Generate Preload File', 'opcache-preload-generator'); ?></strong>
				<p><?php esc_html_e('Go to the "Generate" tab and click "Generate Preload File" to create preload.php.', 'opcache-preload-generator'); ?></p>
			</li>
			<li>
				<strong><?php esc_html_e('Update php.ini', 'opcache-preload-generator'); ?></strong>
				<p><?php esc_html_e('Copy the provided configuration and add it to your php.ini file.', 'opcache-preload-generator'); ?></p>
			</li>
			<li>
				<strong><?php esc_html_e('Restart PHP', 'opcache-preload-generator'); ?></strong>
				<p><?php esc_html_e('Restart PHP-FPM or your web server for the changes to take effect.', 'opcache-preload-generator'); ?></p>
			</li>
		</ol>
	</div>

	<div class="opcache-help-section">
		<h3><?php esc_html_e('Troubleshooting', 'opcache-preload-generator'); ?></h3>
		<dl>
			<dt><strong><?php esc_html_e('PHP fails to start after enabling preloading', 'opcache-preload-generator'); ?></strong></dt>
			<dd>
				<?php esc_html_e('One of the preloaded files is causing an error. Remove the opcache.preload line from php.ini temporarily, then review your preload list for problematic files.', 'opcache-preload-generator'); ?>
			</dd>

			<dt><strong><?php esc_html_e('No files shown in OPcache analysis', 'opcache-preload-generator'); ?></strong></dt>
			<dd>
				<?php esc_html_e('OPcache may need some traffic to populate. Visit several pages on your site, then try analyzing again.', 'opcache-preload-generator'); ?>
			</dd>

			<dt><strong><?php esc_html_e('Preload file not being used', 'opcache-preload-generator'); ?></strong></dt>
			<dd>
				<?php esc_html_e('Ensure the opcache.preload path is correct and the opcache.preload_user matches your web server user. Check PHP error logs for any messages.', 'opcache-preload-generator'); ?>
			</dd>
		</dl>
	</div>

	<div class="opcache-help-section">
		<h3><?php esc_html_e('Additional Resources', 'opcache-preload-generator'); ?></h3>
		<ul>
			<li><a href="https://www.php.net/manual/en/opcache.preloading.php" target="_blank" rel="noopener noreferrer"><?php esc_html_e('PHP Manual: OPcache Preloading', 'opcache-preload-generator'); ?></a></li>
			<li><a href="https://stitcher.io/blog/preloading-in-php-74" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Stitcher.io: Preloading in PHP 7.4', 'opcache-preload-generator'); ?></a></li>
		</ul>
	</div>
</div>
