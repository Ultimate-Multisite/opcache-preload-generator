/**
 * OPcache Preload Generator Admin JavaScript
 *
 * @package OPcache_Preload_Generator
 */

(function($) {
	'use strict';

	var OPcachePreload = {
		// Pagination state
		allFiles: [],
		currentPage: 1,
		perPage: 50,
		totalPages: 1,
		safeCount: 0,
		warningCount: 0,
		errorCount: 0,

		init: function() {
			this.bindEvents();
		},

		bindEvents: function() {
			// Analyze tab
			$('#opcache-analyze-suggested').on('click', this.analyzeSuggested.bind(this));
			$(document).on('click', '.opcache-add-file', this.addFile.bind(this));
			$(document).on('click', '.opcache-view-details', this.viewDetails.bind(this));
			$('#opcache-add-all-safe').on('click', this.addAllSafe.bind(this));
			$('#opcache-add-selected').on('click', this.addSelected.bind(this));
			$('#opcache-select-all-safe').on('change', this.selectAllSafe.bind(this));
			$('#opcache-select-all').on('change', this.selectAll.bind(this));

			// Pagination
			$(document).on('click', '.opcache-page-first', this.goToFirstPage.bind(this));
			$(document).on('click', '.opcache-page-prev', this.goToPrevPage.bind(this));
			$(document).on('click', '.opcache-page-next', this.goToNextPage.bind(this));
			$(document).on('click', '.opcache-page-last', this.goToLastPage.bind(this));
			$(document).on('change', '.opcache-current-page', this.goToPage.bind(this));
			$('#opcache-per-page').on('change', this.changePerPage.bind(this));

			// Files tab
			$('#opcache-add-manual-file').on('click', this.addManualFile.bind(this));
			$(document).on('click', '.opcache-remove-file', this.removeFile.bind(this));
			$(document).on('click', '.opcache-analyze-file', this.analyzeFile.bind(this));
			$(document).on('change', '.opcache-method-select', this.updateFileMethod.bind(this));

			// Generate tab
			$('#opcache-preview-btn').on('click', this.previewPreload.bind(this));
			$('#opcache-generate-btn').on('click', this.generatePreload.bind(this));
			$('#opcache-delete-btn').on('click', this.deletePreload.bind(this));
			$('.opcache-copy-btn').on('click', this.copyToClipboard.bind(this));

			// Optimize tab
			$('#opcache-start-optimize').on('click', this.startOptimize.bind(this));
			$('#opcache-stop-optimize').on('click', this.stopOptimize.bind(this));
			$('#opcache-reset-optimize').on('click', this.resetOptimize.bind(this));

			// Modal
			$('.opcache-modal-close').on('click', this.closeModal.bind(this));
			$(window).on('click', this.closeModalOnOutsideClick.bind(this));
		},

		// Optimization state
		optimizing: false,
		optimizeState: null,

		analyzeSuggested: function() {
			var self = this;
			var limit = $('#opcache-analyze-limit').val();
			var $results = $('#opcache-analyze-results');
			var $loading = $('#opcache-analyze-loading');
			var $empty = $('#opcache-analyze-empty');

			// Store per-page setting
			this.perPage = parseInt($('#opcache-per-page').val(), 10) || 50;
			this.currentPage = 1;

			$results.hide();
			$empty.hide();
			$loading.show();

			$.ajax({
				url: opcachePreload.ajaxUrl,
				type: 'POST',
				data: {
					action: 'opcache_preload_analyze_suggested',
					nonce: opcachePreload.nonce,
					limit: limit
				},
				success: function(response) {
					$loading.hide();

					if (!response.success) {
						alert(opcachePreload.i18n.error + ' ' + response.data.message);
						return;
					}

					if (response.data.files.length === 0) {
						// Show debug info if available
						if (response.data.debug) {
							var debugInfo = '';

							// Check if this is a path mismatch issue
							if (response.data.debug.scripts_count > 0 && response.data.debug.matching_scripts_count === 0) {
								debugInfo += '<div class="notice notice-warning inline"><p><strong>Path Mismatch Detected:</strong> ';
								debugInfo += 'OPcache contains ' + response.data.debug.scripts_count + ' cached scripts, but none belong to this WordPress installation. ';
								debugInfo += 'This typically happens when OPcache is shared across multiple sites.</p></div>';
							}

							debugInfo += '<p><strong>Debug Info:</strong></p><ul>';
							debugInfo += '<li>OPcache Available: ' + (response.data.debug.opcache_available ? 'Yes' : 'No') + '</li>';
							debugInfo += '<li>Total Cached Scripts: ' + response.data.debug.scripts_count + '</li>';
							debugInfo += '<li>Scripts matching this site: ' + (response.data.debug.matching_scripts_count || 0) + '</li>';
							debugInfo += '<li>This site ABSPATH: <code>' + response.data.debug.abspath_realpath + '</code></li>';

							if (response.data.debug.matching_sample_paths && response.data.debug.matching_sample_paths.length > 0) {
								debugInfo += '<li>Matching scripts:<ul>';
								response.data.debug.matching_sample_paths.forEach(function(path) {
									debugInfo += '<li><code>' + path + '</code></li>';
								});
								debugInfo += '</ul></li>';
							}

							if (response.data.debug.non_matching_sample && response.data.debug.non_matching_sample.length > 0) {
								debugInfo += '<li>Sample non-matching scripts (from other sites):<ul>';
								response.data.debug.non_matching_sample.forEach(function(path) {
									debugInfo += '<li><code>' + path + '</code></li>';
								});
								debugInfo += '</ul></li>';
							}

							debugInfo += '</ul>';
							debugInfo += '<p><strong>Solution:</strong> Browse around this WordPress site to populate the OPcache with files from this installation, then try again.</p>';
							$empty.html(debugInfo);
						}
						$empty.show();
						return;
					}

					// Store all files for pagination
					self.allFiles = self.processFiles(response.data.files);
					self.totalPages = Math.ceil(self.allFiles.length / self.perPage);
					self.renderCurrentPage();
					$results.show();
				},
				error: function() {
					$loading.hide();
					alert(opcachePreload.i18n.error + ' ' + 'Request failed');
				}
			});
		},

		// Process files and calculate counts (done once after AJAX)
		processFiles: function(files) {
			var self = this;
			self.safeCount = 0;
			self.warningCount = 0;
			self.errorCount = 0;

			return files.map(function(file) {
				var isSafe = file.safe && file.errors.length === 0;
				var hasWarnings = file.warnings.length > 0;
				var hasErrors = file.errors.length > 0;

				var statusClass = 'safe';
				var badge = '<span class="opcache-badge opcache-badge-safe">Safe</span>';

				if (hasErrors) {
					statusClass = 'error';
					badge = '<span class="opcache-badge opcache-badge-error">Error</span>';
					self.errorCount++;
				} else if (hasWarnings) {
					statusClass = 'warning';
					badge = '<span class="opcache-badge opcache-badge-warning">Warning</span>';
					self.warningCount++;
				} else {
					self.safeCount++;
				}

				return {
					file: file.file,
					relative: file.relative,
					isSafe: isSafe,
					statusClass: statusClass,
					badge: badge,
					warnings: file.warnings,
					errors: file.errors,
					hits: file.hits,
					hitsFormatted: self.formatNumber(file.hits),
					memory: file.memory,
					memoryFormatted: self.formatBytes(file.memory),
					in_list: file.in_list
				};
			});
		},

		// Render current page of results
		renderCurrentPage: function() {
			var start = (this.currentPage - 1) * this.perPage;
			var end = start + this.perPage;
			var pageFiles = this.allFiles.slice(start, end);

			this.renderResults(pageFiles);
			this.updatePagination();
			this.updateSummary();
		},

		// Update pagination controls
		updatePagination: function() {
			var start = (this.currentPage - 1) * this.perPage + 1;
			var end = Math.min(this.currentPage * this.perPage, this.allFiles.length);
			var displayText = this.formatNumber(start) + '–' + this.formatNumber(end) + ' of ' + this.formatNumber(this.allFiles.length) + ' items';

			$('#opcache-displaying-num-top, #opcache-displaying-num-bottom').text(displayText);
			$('.opcache-total-pages').text(this.formatNumber(this.totalPages));
			$('.opcache-current-page').val(this.currentPage).attr('max', this.totalPages);

			// Enable/disable buttons
			var isFirst = this.currentPage === 1;
			var isLast = this.currentPage === this.totalPages;

			$('.opcache-page-first, .opcache-page-prev').prop('disabled', isFirst);
			$('.opcache-page-next, .opcache-page-last').prop('disabled', isLast);
		},

		// Update summary counts
		updateSummary: function() {
			$('#opcache-results-total').text(this.formatNumber(this.allFiles.length) + ' files analyzed');
			$('#opcache-results-safe').html('<span class="opcache-badge opcache-badge-safe">' + this.formatNumber(this.safeCount) + ' safe</span>');
			$('#opcache-results-warnings').html('<span class="opcache-badge opcache-badge-warning">' + this.formatNumber(this.warningCount) + ' warnings</span>');
			$('#opcache-results-errors').html('<span class="opcache-badge opcache-badge-error">' + this.formatNumber(this.errorCount) + ' errors</span>');
		},

		// Pagination navigation
		goToFirstPage: function() {
			if (this.currentPage !== 1) {
				this.currentPage = 1;
				this.renderCurrentPage();
			}
		},

		goToPrevPage: function() {
			if (this.currentPage > 1) {
				this.currentPage--;
				this.renderCurrentPage();
			}
		},

		goToNextPage: function() {
			if (this.currentPage < this.totalPages) {
				this.currentPage++;
				this.renderCurrentPage();
			}
		},

		goToLastPage: function() {
			if (this.currentPage !== this.totalPages) {
				this.currentPage = this.totalPages;
				this.renderCurrentPage();
			}
		},

		goToPage: function(e) {
			var page = parseInt($(e.currentTarget).val(), 10);
			if (page >= 1 && page <= this.totalPages && page !== this.currentPage) {
				this.currentPage = page;
				this.renderCurrentPage();
			} else {
				// Reset to current page if invalid
				$(e.currentTarget).val(this.currentPage);
			}
		},

		changePerPage: function(e) {
			this.perPage = parseInt($(e.currentTarget).val(), 10) || 50;
			this.totalPages = Math.ceil(this.allFiles.length / this.perPage);
			this.currentPage = 1;
			this.renderCurrentPage();
		},

		// Render a subset of files (used by renderCurrentPage)
		renderResults: function(files) {
			var $tbody = $('#opcache-results-body');
			var template = wp.template('opcache-result-row');

			$tbody.empty();

			files.forEach(function(data) {
				$tbody.append(template(data));
			});
		},

		addFile: function(e) {
			var $btn = $(e.currentTarget);
			var file = $btn.data('file');

			$btn.prop('disabled', true).text(opcachePreload.i18n.analyzing);

			$.ajax({
				url: opcachePreload.ajaxUrl,
				type: 'POST',
				data: {
					action: 'opcache_preload_add_file',
					nonce: opcachePreload.nonce,
					file: file
				},
				success: function(response) {
					if (response.success) {
						$btn.closest('tr').find('.opcache-file-checkbox').prop('disabled', true);
						$btn.replaceWith('<span class="opcache-already-added">Added</span>');
						$btn.closest('tr').find('.column-file strong').after(' <span class="opcache-badge opcache-badge-info">In List</span>');
					} else {
						alert(opcachePreload.i18n.error + ' ' + response.data.message);
						$btn.prop('disabled', false).text('Add');
					}
				},
				error: function() {
					alert(opcachePreload.i18n.error + ' Request failed');
					$btn.prop('disabled', false).text('Add');
				}
			});
		},

		addAllSafe: function() {
			var $checkboxes = $('.opcache-file-checkbox[data-safe="1"]:not(:disabled)');
			$checkboxes.prop('checked', true);
			this.addSelected();
		},

		addSelected: function() {
			var $checked = $('.opcache-file-checkbox:checked:not(:disabled)');

			if ($checked.length === 0) {
				alert(opcachePreload.i18n.no_files_selected);
				return;
			}

			var files = [];
			$checked.each(function() {
				files.push($(this).val());
			});

			var $btn = $('#opcache-add-selected');
			$btn.prop('disabled', true);

			var processed = 0;
			var total = files.length;

			files.forEach(function(file) {
				$.ajax({
					url: opcachePreload.ajaxUrl,
					type: 'POST',
					data: {
						action: 'opcache_preload_add_file',
						nonce: opcachePreload.nonce,
						file: file
					},
					success: function(response) {
						processed++;
						if (response.success) {
							var $row = $('tr[data-file="' + file + '"]');
							$row.find('.opcache-file-checkbox').prop('disabled', true).prop('checked', false);
							$row.find('.opcache-add-file').replaceWith('<span class="opcache-already-added">Added</span>');
							$row.find('.column-file strong').after(' <span class="opcache-badge opcache-badge-info">In List</span>');
						}
						if (processed === total) {
							$btn.prop('disabled', false);
						}
					},
					error: function() {
						processed++;
						if (processed === total) {
							$btn.prop('disabled', false);
						}
					}
				});
			});
		},

		selectAllSafe: function(e) {
			var checked = $(e.currentTarget).prop('checked');
			$('.opcache-file-checkbox[data-safe="1"]:not(:disabled)').prop('checked', checked);
		},

		selectAll: function(e) {
			var checked = $(e.currentTarget).prop('checked');
			$('.opcache-file-checkbox:not(:disabled)').prop('checked', checked);
		},

		viewDetails: function(e) {
			var file = $(e.currentTarget).data('file');
			var $row = $('tr[data-file="' + file + '"]');

			// Get the stored data
			var $modal = $('#opcache-analysis-modal');
			var $body = $modal.find('.opcache-modal-body');

			// For now, analyze the file again to get details
			$.ajax({
				url: opcachePreload.ajaxUrl,
				type: 'POST',
				data: {
					action: 'opcache_preload_analyze_file',
					nonce: opcachePreload.nonce,
					file: file
				},
				success: function(response) {
					if (!response.success) {
						alert(opcachePreload.i18n.error + ' ' + response.data.message);
						return;
					}

					var html = '<p><strong>File:</strong> ' + response.data.file + '</p>';

					if (response.data.errors.length > 0) {
						html += '<h4>Errors</h4><ul>';
						response.data.errors.forEach(function(error) {
							html += '<li style="color: #8a2424;">' + error + '</li>';
						});
						html += '</ul>';
					}

					if (response.data.warnings.length > 0) {
						html += '<h4>Warnings</h4><ul>';
						response.data.warnings.forEach(function(warning) {
							html += '<li style="color: #996800;">' + warning + '</li>';
						});
						html += '</ul>';
					}

					if (response.data.dependencies.length > 0) {
						html += '<h4>Dependencies</h4><ul>';
						response.data.dependencies.forEach(function(dep) {
							html += '<li><code>' + dep + '</code></li>';
						});
						html += '</ul>';
					}

					$body.html(html);
					$modal.show();
				}
			});
		},

		closeModal: function() {
			$('#opcache-analysis-modal').hide();
		},

		closeModalOnOutsideClick: function(e) {
			if ($(e.target).hasClass('opcache-modal')) {
				$('#opcache-analysis-modal').hide();
			}
		},

		addManualFile: function() {
			var file = $('#opcache-manual-file-path').val().trim();
			var $result = $('#opcache-manual-add-result');

			if (!file) {
				$result.removeClass('success').addClass('error').text('Please enter a file path').show();
				return;
			}

			$.ajax({
				url: opcachePreload.ajaxUrl,
				type: 'POST',
				data: {
					action: 'opcache_preload_add_file',
					nonce: opcachePreload.nonce,
					file: file
				},
				success: function(response) {
					if (response.success) {
						$result.removeClass('error').addClass('success').text(response.data.message).show();
						$('#opcache-manual-file-path').val('');
						// Reload page to show new file in list
						setTimeout(function() {
							location.reload();
						}, 1000);
					} else {
						$result.removeClass('success').addClass('error').text(response.data.message).show();
					}
				},
				error: function() {
					$result.removeClass('success').addClass('error').text('Request failed').show();
				}
			});
		},

		removeFile: function(e) {
			if (!confirm(opcachePreload.i18n.confirm_remove)) {
				return;
			}

			var $btn = $(e.currentTarget);
			var file = $btn.data('file');

			$.ajax({
				url: opcachePreload.ajaxUrl,
				type: 'POST',
				data: {
					action: 'opcache_preload_remove_file',
					nonce: opcachePreload.nonce,
					file: file
				},
				success: function(response) {
					if (response.success) {
						$btn.closest('tr').fadeOut(function() {
							$(this).remove();
						});
					} else {
						alert(opcachePreload.i18n.error + ' ' + response.data.message);
					}
				},
				error: function() {
					alert(opcachePreload.i18n.error + ' Request failed');
				}
			});
		},

		updateFileMethod: function(e) {
			var $select = $(e.currentTarget);
			var file = $select.data('file');
			var method = $select.val();

			$select.prop('disabled', true);

			$.ajax({
				url: opcachePreload.ajaxUrl,
				type: 'POST',
				data: {
					action: 'opcache_preload_update_file_method',
					nonce: opcachePreload.nonce,
					file: file,
					method: method
				},
				success: function(response) {
					$select.prop('disabled', false);
					if (!response.success) {
						alert(opcachePreload.i18n.error + ' ' + response.data.message);
						// Revert to previous value
						location.reload();
					}
				},
				error: function() {
					$select.prop('disabled', false);
					alert(opcachePreload.i18n.error + ' Request failed');
					location.reload();
				}
			});
		},

		analyzeFile: function(e) {
			var file = $(e.currentTarget).data('file');
			var $modal = $('#opcache-analysis-modal');
			var $body = $modal.find('.opcache-modal-body');

			$body.html('<p>' + opcachePreload.i18n.analyzing + '</p>');
			$modal.show();

			$.ajax({
				url: opcachePreload.ajaxUrl,
				type: 'POST',
				data: {
					action: 'opcache_preload_analyze_file',
					nonce: opcachePreload.nonce,
					file: file
				},
				success: function(response) {
					if (!response.success) {
						$body.html('<p style="color: #8a2424;">' + response.data.message + '</p>');
						return;
					}

					var html = '<p><strong>File:</strong> ' + response.data.file + '</p>';

					var statusBadge = '<span class="opcache-badge opcache-badge-safe">Safe</span>';
					if (response.data.errors.length > 0) {
						statusBadge = '<span class="opcache-badge opcache-badge-error">Error</span>';
					} else if (response.data.warnings.length > 0) {
						statusBadge = '<span class="opcache-badge opcache-badge-warning">Warning</span>';
					}
					html += '<p><strong>Status:</strong> ' + statusBadge + '</p>';

					if (response.data.errors.length > 0) {
						html += '<h4>Errors</h4><ul>';
						response.data.errors.forEach(function(error) {
							html += '<li style="color: #8a2424;">' + error + '</li>';
						});
						html += '</ul>';
					}

					if (response.data.warnings.length > 0) {
						html += '<h4>Warnings</h4><ul>';
						response.data.warnings.forEach(function(warning) {
							html += '<li style="color: #996800;">' + warning + '</li>';
						});
						html += '</ul>';
					}

					if (response.data.dependencies.length > 0) {
						html += '<h4>Dependencies</h4><ul>';
						response.data.dependencies.forEach(function(dep) {
							html += '<li><code>' + dep + '</code></li>';
						});
						html += '</ul>';
					}

					if (response.data.errors.length === 0 && response.data.warnings.length === 0) {
						html += '<p style="color: #006908;">This file appears safe for preloading.</p>';
					}

					$body.html(html);
				},
				error: function() {
					$body.html('<p style="color: #8a2424;">Request failed</p>');
				}
			});
		},

		previewPreload: function() {
			var $container = $('#opcache-preview-container');
			var $content = $('#opcache-preview-content');

			$.ajax({
				url: opcachePreload.ajaxUrl,
				type: 'POST',
				data: {
					action: 'opcache_preload_preview',
					nonce: opcachePreload.nonce
				},
				success: function(response) {
					if (response.success) {
						$content.val(response.data.content);
						$container.show();
					} else {
						alert(opcachePreload.i18n.error + ' ' + response.data.message);
					}
				},
				error: function() {
					alert(opcachePreload.i18n.error + ' Request failed');
				}
			});
		},

		generatePreload: function() {
			var $btn = $('#opcache-generate-btn');
			var $result = $('#opcache-generate-result');

			$btn.prop('disabled', true).text(opcachePreload.i18n.generating);

			// Get settings from form
			var outputPath = $('#opcache-output-path').val();
			var useRequire = $('input[name="opcache-use-require"]:checked').val() === '1';

			// First save settings
			$.ajax({
				url: opcachePreload.ajaxUrl,
				type: 'POST',
				data: {
					action: 'opcache_preload_save_settings',
					nonce: opcachePreload.nonce,
					output_path: outputPath,
					use_require: useRequire ? 1 : 0
				},
				success: function() {
					// Then generate
					$.ajax({
						url: opcachePreload.ajaxUrl,
						type: 'POST',
						data: {
							action: 'opcache_preload_generate',
							nonce: opcachePreload.nonce
						},
						success: function(response) {
							$btn.prop('disabled', false).text('Generate Preload File');

							if (response.success) {
								$result.removeClass('error').addClass('success').html(
									'<strong>' + opcachePreload.i18n.success + '</strong> ' + response.data.message +
									'<br>File saved to: <code>' + response.data.path + '</code>'
								).show();

								// Update php.ini config display
								$('#opcache-phpini-config').text(response.data.php_config);
								$('#opcache-phpini-section').show();

								// Show delete button if not already present
								if ($('#opcache-delete-btn').length === 0) {
									$btn.after(' <button type="button" id="opcache-delete-btn" class="button button-link-delete">Delete Existing File</button>');
									$('#opcache-delete-btn').on('click', OPcachePreload.deletePreload.bind(OPcachePreload));
								}
							} else {
								$result.removeClass('success').addClass('error').html(
									'<strong>' + opcachePreload.i18n.error + '</strong> ' + response.data.message
								).show();
							}
						},
						error: function() {
							$btn.prop('disabled', false).text('Generate Preload File');
							$result.removeClass('success').addClass('error').text('Request failed').show();
						}
					});
				},
				error: function() {
					$btn.prop('disabled', false).text('Generate Preload File');
					$result.removeClass('success').addClass('error').text('Failed to save settings').show();
				}
			});
		},

		deletePreload: function() {
			if (!confirm(opcachePreload.i18n.confirm_delete)) {
				return;
			}

			var $result = $('#opcache-generate-result');

			$.ajax({
				url: opcachePreload.ajaxUrl,
				type: 'POST',
				data: {
					action: 'opcache_preload_delete',
					nonce: opcachePreload.nonce
				},
				success: function(response) {
					if (response.success) {
						$result.removeClass('error').addClass('success').text(response.data.message).show();
						$('#opcache-delete-btn').remove();
						$('.opcache-existing-file').fadeOut();
						$('#opcache-phpini-section').hide();
					} else {
						$result.removeClass('success').addClass('error').text(response.data.message).show();
					}
				},
				error: function() {
					$result.removeClass('success').addClass('error').text('Request failed').show();
				}
			});
		},

		copyToClipboard: function(e) {
			var targetId = $(e.currentTarget).data('target');
			var $target = $('#' + targetId);
			var text = $target.text();

			navigator.clipboard.writeText(text).then(function() {
				var $btn = $(e.currentTarget);
				var originalText = $btn.text();
				$btn.text(opcachePreload.i18n.copied);
				setTimeout(function() {
					$btn.text(originalText);
				}, 2000);
			}).catch(function() {
				alert(opcachePreload.i18n.copy_failed);
			});
		},

		// Optimization functions
		startOptimize: function() {
			var self = this;
			var maxFiles = $('#optimize-max-files').val();

			$('#opcache-start-optimize').prop('disabled', true).text(opcachePreload.i18n.opt_starting);
			$('#opcache-stop-optimize').prop('disabled', false);
			$('#opcache-reset-optimize').prop('disabled', true);
			$('#optimize-progress').show();
			$('#optimize-log').show();

			this.optimizing = true;

			$.ajax({
				url: opcachePreload.ajaxUrl,
				type: 'POST',
				data: {
					action: 'opcache_preload_start_optimize',
					nonce: opcachePreload.nonce,
					max_files: maxFiles
				},
				success: function(response) {
					if (!response.success) {
						self.optimizing = false;
						$('#opcache-start-optimize').prop('disabled', false).text('Start Optimization');
						$('#opcache-stop-optimize').prop('disabled', true);
						alert(opcachePreload.i18n.opt_error + ' ' + response.data.error);
						return;
					}

					self.optimizeState = response.data.state;
					self.updateOptimizeUI();
					
					// Build log message based on mode
					var logMsg = 'Started optimization with ' + response.data.candidates_count + ' candidate files';
					if (response.data.auto_detected) {
						logMsg += ' (auto: ' + response.data.auto_detected + ' detected';
						if (response.data.reference_file) {
							logMsg += ', ref: ' + response.data.reference_file + ' @ ' + response.data.reference_hits + ' hits';
						}
						logMsg += ', threshold: ' + response.data.cutoff_hits + ' hits)';
					}
					self.addLogEntry(logMsg);

					// Run baseline test
					self.runBaselineTest();
				},
				error: function() {
					self.optimizing = false;
					$('#opcache-start-optimize').prop('disabled', false).text('Start Optimization');
					$('#opcache-stop-optimize').prop('disabled', true);
					alert(opcachePreload.i18n.opt_error + ' Request failed');
				}
			});
		},

		runBaselineTest: function() {
			var self = this;

			if (!this.optimizing) return;

			$('#optimize-status-text').text(opcachePreload.i18n.opt_baseline);
			$('#optimize-phase-text').text(opcachePreload.i18n.opt_baseline);

			$.ajax({
				url: opcachePreload.ajaxUrl,
				type: 'POST',
				data: {
					action: 'opcache_preload_run_baseline',
					nonce: opcachePreload.nonce
				},
				success: function(response) {
					if (!response.success) {
						self.optimizing = false;
						self.handleOptimizeError(response.data.error);
						return;
					}

					self.optimizeState = response.data.state;
					self.updateOptimizeUI();
					self.addLogEntry('Baseline: ' + response.data.baseline_time + ' ms');

					// Start processing files
					self.processNextFile();
				},
				error: function() {
					self.optimizing = false;
					self.handleOptimizeError('Baseline test request failed');
				}
			});
		},

		processNextFile: function() {
			var self = this;

			if (!this.optimizing) return;

			$('#optimize-status-text').text(opcachePreload.i18n.opt_testing);
			$('#optimize-phase-text').text(opcachePreload.i18n.opt_testing);

			$.ajax({
				url: opcachePreload.ajaxUrl,
				type: 'POST',
				data: {
					action: 'opcache_preload_process_next',
					nonce: opcachePreload.nonce
				},
				success: function(response) {
					if (!response.success) {
						self.optimizing = false;
						self.handleOptimizeError(response.data.error);
						return;
					}

					self.optimizeState = response.data.state;
					self.updateOptimizeUI();

					// Check if completed
					if (response.data.completed) {
						self.optimizing = false;
						self.handleOptimizeComplete(response.data);
						return;
					}

					// Log result
					if (response.data.file_status === 'added') {
						self.addLogEntry('<code>' + self.getShortPath(response.data.file) + '</code> → ' + response.data.time_ms + ' ms → <code>' + (response.data.method ?? 'require_once') + '()</code>', 'success');
					} else if (response.data.file_status === 'failed') {
						self.addLogEntry('<code>' + self.getShortPath(response.data.file) + '</code> failed: ' + response.data.error, 'error');
					}

					// Continue with next file
					if (self.optimizing) {
						setTimeout(function() {
							self.processNextFile();
						}, 100);
					}
				},
				error: function() {
					self.optimizing = false;
					self.handleOptimizeError('Request failed');
				}
			});
		},

		stopOptimize: function() {
			var self = this;

			this.optimizing = false;

			$.ajax({
				url: opcachePreload.ajaxUrl,
				type: 'POST',
				data: {
					action: 'opcache_preload_stop_optimize',
					nonce: opcachePreload.nonce
				},
				success: function(response) {
					if (response.success) {
						self.optimizeState = response.data.state;
						self.updateOptimizeUI();
						self.addLogEntry(opcachePreload.i18n.opt_stopped, 'warning');
					}

					$('#opcache-start-optimize').prop('disabled', false).text('Start Optimization');
					$('#opcache-stop-optimize').prop('disabled', true);
					$('#opcache-reset-optimize').prop('disabled', false);
					$('#optimize-status-text').text('Paused');
				}
			});
		},

		resetOptimize: function() {
			var self = this;

			if (!confirm(opcachePreload.i18n.confirm_reset)) {
				return;
			}

			$.ajax({
				url: opcachePreload.ajaxUrl,
				type: 'POST',
				data: {
					action: 'opcache_preload_reset_optimize',
					nonce: opcachePreload.nonce
				},
				success: function(response) {
					if (response.success) {
						// Reset UI
						$('#optimize-status-text').text('Ready');
						$('#optimize-phase-text').text('');
						$('#optimize-baseline-time').text('—');
						$('#optimize-best-time').text('—');
						$('#optimize-time-saved').text('—');
						$('#optimize-progress').hide();
						$('#optimize-log-entries').empty();
						$('#optimize-log').hide();
					$('#opcache-start-optimize').prop('disabled', false).text('Start Optimization');
					$('#opcache-stop-optimize').prop('disabled', true);
					$('#opcache-reset-optimize').prop('disabled', true);

						self.optimizeState = null;
					}
				}
			});
		},

		updateOptimizeUI: function() {
			var state = this.optimizeState;
			if (!state) return;

			// Update status
			var statusLabels = {
				'idle': 'Ready',
				'running': 'Running',
				'paused': 'Paused',
				'completed': 'Completed',
				'error': 'Error'
			};
			$('#optimize-status-text').text(statusLabels[state.status] || state.status);

			// Update times
			if (state.baseline_time > 0) {
				$('#optimize-baseline-time').text(state.baseline_time + ' ms');
			}
			if (state.best_time > 0) {
				$('#optimize-best-time').text(state.best_time + ' ms');
			}
			if (state.time_saved_ms > 0) {
				$('#optimize-time-saved').text(state.time_saved_ms + ' ms (' + state.time_saved_pct + '%)');
			}

			// Update progress
			$('#optimize-files-tested').text(state.files_tested + ' ' + opcachePreload.i18n.files_tested);
			$('#optimize-files-added').text(state.files_added + ' ' + opcachePreload.i18n.added);
			$('#optimize-files-failed').text(state.files_failed + ' ' + opcachePreload.i18n.failed);

			// Update progress bar (use total_candidates from state for persistence across page refreshes)
			var totalCandidates = state.total_candidates || 0;
			var progress = totalCandidates > 0 ? (state.files_tested / totalCandidates) * 100 : 0;
			$('#optimize-progress-bar').css('width', progress + '%');

			// Update current file
			if (state.current_file) {
				$('#optimize-current-file').html(opcachePreload.i18n.testing + ' <code>' + state.current_file + '</code>');
			} else {
				$('#optimize-current-file').text('');
			}
		},

		handleOptimizeError: function(error) {
			$('#optimize-status-text').text('Error');
			$('#opcache-start-optimize').prop('disabled', false).text('Start Optimization');
			$('#opcache-stop-optimize').prop('disabled', true);
			$('#opcache-reset-optimize').prop('disabled', false);
			this.addLogEntry(opcachePreload.i18n.opt_error + ' ' + error, 'error');
		},

		handleOptimizeComplete: function(data) {
			$('#optimize-status-text').text('Completed');
			$('#optimize-phase-text').text('');
			$('#opcache-start-optimize').prop('disabled', false).text('Start Optimization');
			$('#opcache-stop-optimize').prop('disabled', true);
			$('#opcache-reset-optimize').prop('disabled', false);
			$('#optimize-current-file').text('');

			var message = opcachePreload.i18n.opt_complete + ' ';
			message += data.files_added + ' files added. ';
			message += 'Time saved: ' + data.time_saved_ms + ' ms (' + data.time_saved_pct + '%)';

			this.addLogEntry(message, 'complete');
		},

		addLogEntry: function(message, type) {
			type = type || 'info';
			var time = new Date().toLocaleTimeString();
			var entry = '<div class="opcache-log-entry opcache-log-' + type + '">';
			entry += '<span class="opcache-log-time">' + time + '</span>';
			entry += '<span class="opcache-log-message">' + message + '</span>';
			entry += '</div>';

			$('#optimize-log-entries').append(entry);

			// Scroll to bottom
			var $log = $('#optimize-log-entries');
			$log.scrollTop($log[0].scrollHeight);
		},

		getBasename: function(path) {
			return path.split('/').pop();
		},

		getShortPath: function(path) {
			// Return last 2 path components (parent/filename) for better identification
			var parts = path.split('/');
			if (parts.length >= 2) {
				return parts.slice(-2).join('/');
			}
			return parts.pop();
		},

		formatBytes: function(bytes) {
			if (bytes === 0) return '0 B';
			var k = 1024;
			var sizes = ['B', 'KB', 'MB', 'GB'];
			var i = Math.floor(Math.log(bytes) / Math.log(k));
			return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
		},

		formatNumber: function(num) {
			return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
		}
	};

	$(document).ready(function() {
		OPcachePreload.init();
	});

})(jQuery);
