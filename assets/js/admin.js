/**
 * OPcache Preload Generator Admin JavaScript
 *
 * @package OPcache_Preload_Generator
 */

(function($) {
	'use strict';

	var OPcachePreload = {
		currentThreshold: 70,
		candidatesCache: null,

		init: function() {
			this.bindEvents();
			this.initThresholdSlider();
		},

		bindEvents: function() {
			// Generate section
			$('#opcache-preview-btn').on('click', this.previewPreload.bind(this));
			$('#opcache-generate-btn').on('click', this.generatePreload.bind(this));
			$('#opcache-delete-btn').on('click', this.deletePreload.bind(this));
			$('.opcache-copy-btn').on('click', this.copyToClipboard.bind(this));

			// Advanced toggle
			$('#opcache-advanced-toggle-btn').on('click', this.toggleAdvanced.bind(this));

			// Modal
			$('.opcache-modal-close').on('click', this.closeModal.bind(this));
			$(window).on('click', this.closeModalOnOutsideClick.bind(this));
		},

		toggleAdvanced: function() {
			var $container = $('#opcache-advanced-container');
			var $arrow = $('#opcache-advanced-arrow');
			var isVisible = $container.is(':visible');

			if (isVisible) {
				$container.slideUp(200);
				$arrow.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
			} else {
				$container.slideDown(200);
				$arrow.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
				// Initialize slider if needed
				if ($('#opcache-threshold-slider').length && !this.sliderInitialized) {
					this.initThresholdSlider();
					this.sliderInitialized = true;
				}
			}
		},

		initThresholdSlider: function() {
			var self = this;
			var $slider = $('#opcache-threshold-slider');
			var $display = $('#opcache-threshold-display');

			if (!$slider.length) {
				return;
			}

			// Initial load
			self.updateCandidatesPreview($slider.val());

			$slider.on('input change', function() {
				var value = $(this).val();
				self.currentThreshold = value;
				$display.text(value);
				self.updateCandidatesPreview(value);
			});
		},

		updateCandidatesPreview: function(threshold) {
			var self = this;
			var $count = $('#opcache-candidate-count');
			var $cutoff = $('#opcache-cutoff-info');
			var $tbody = $('#opcache-candidates-tbody');

			// Show loading state
			$tbody.html('<tr><td colspan="3" class="opcache-candidates-loading">' + opcachePreload.i18n.loading + '</td></tr>');

			$.ajax({
				url: opcachePreload.ajaxUrl,
				type: 'POST',
				data: {
					action: 'opcache_preload_candidates_preview',
					nonce: opcachePreload.nonce,
					threshold: threshold / 100
				},
				success: function(response) {
					if (!response.success) {
						$tbody.html('<tr><td colspan="3" class="opcache-candidates-loading">' + (response.data && response.data.message ? response.data.message : 'Unknown error') + '</td></tr>');
						return;
					}

					var data = response.data || {};

					// Update count
					$count.text(data.total.toLocaleString());

					// Update cutoff info
					var cutoffText = '';
					if (data.total > 0 && data.reference) {
						cutoffText = opcachePreload.i18n.cutoff_info || 'Cutoff: %s hits (most-hit file: %s with %s hits)';
						cutoffText = cutoffText.replace('%s', data.cutoff_hits.toLocaleString());
						cutoffText = cutoffText.replace('%s', data.reference.file.split('/').pop());
						cutoffText = cutoffText.replace('%s', data.reference.hits.toLocaleString());
					} else {
						cutoffText = opcachePreload.i18n.no_candidates || 'No candidate files found at this threshold. Try a lower value.';
					}
					$cutoff.text(cutoffText);

					// Update table
					var html = '';
					if (data.candidates.length > 0) {
						data.candidates.forEach(function(candidate) {
							html += '<tr>';
							html += '<td><code>' + self.escapeHtml(candidate.path) + '</code></td>';
							html += '<td>' + candidate.hits.toLocaleString() + '</td>';
							html += '<td>' + self.formatBytes(candidate.memory) + '</td>';
							html += '</tr>';
						});
					} else {
						html = '<tr><td colspan="3" class="opcache-candidates-loading">' + opcachePreload.i18n.no_candidates_near + '</td></tr>';
					}
					$tbody.html(html);
				},
				error: function() {
					$tbody.html('<tr><td colspan="3" class="opcache-candidates-loading">' + opcachePreload.i18n.error + '</td></tr>');
				}
			});
		},

		previewPreload: function() {
			var $container = $('#opcache-preview-container');
			var $content = $('#opcache-preview-content');
			var $btn = $('#opcache-preview-btn');

			if ($container.is(':visible')) {
				$container.hide();
				$btn.text(opcachePreload.i18n.show_preview || 'Show File Contents');
				return;
			}

			$btn.prop('disabled', true).text(opcachePreload.i18n.loading || 'Loading...');

			$.ajax({
				url: opcachePreload.ajaxUrl,
				type: 'POST',
				data: {
					action: 'opcache_preload_preview',
					nonce: opcachePreload.nonce
				},
				success: function(response) {
					$btn.prop('disabled', false).text(opcachePreload.i18n.hide_preview || 'Hide Preview');
					if (response.success) {
						$content.val(response.data.content);
						$container.show();
					} else {
						alert(opcachePreload.i18n.error + ' ' + response.data.message);
					}
				},
				error: function() {
					$btn.prop('disabled', false).text(opcachePreload.i18n.show_preview || 'Show File Contents');
					alert(opcachePreload.i18n.error + ' Request failed');
				}
			});
		},

		generatePreload: function() {
			var $btn = $('#opcache-generate-btn');
			var $result = $('#opcache-generate-result');
			var originalText = $btn.text();

			$btn.prop('disabled', true).text(opcachePreload.i18n.generating);

			// Get output path from form
			var outputPath = $('#opcache-output-path').val();

			// Save settings first
			$.ajax({
				url: opcachePreload.ajaxUrl,
				type: 'POST',
				data: {
					action: 'opcache_preload_save_settings',
					nonce: opcachePreload.nonce,
					output_path: outputPath
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
							$btn.prop('disabled', false).text(originalText);

							if (response.success) {
								$result.removeClass('error').addClass('success').html(
									'<strong>' + opcachePreload.i18n.success + '</strong> ' + response.data.message +
									'<br>File saved to: <code>' + response.data.path + '</code>'
								).show();

								// Reload page to show updated state
								setTimeout(function() {
									location.reload();
								}, 1500);
							} else {
								$result.removeClass('success').addClass('error').html(
									'<strong>' + opcachePreload.i18n.error + '</strong> ' + response.data.message
								).show();
							}
						},
						error: function() {
							$btn.prop('disabled', false).text(originalText);
							$result.removeClass('success').addClass('error').text('Request failed').show();
						}
					});
				},
				error: function() {
					$btn.prop('disabled', false).text(originalText);
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

						// Reload page after delay
						setTimeout(function() {
							location.reload();
						}, 1500);
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

		closeModal: function() {
			$('#opcache-analysis-modal').hide();
		},

		closeModalOnOutsideClick: function(e) {
			if ($(e.target).hasClass('opcache-modal')) {
				$('#opcache-analysis-modal').hide();
			}
		},

		formatBytes: function(bytes) {
			if (bytes === 0) return '0 B';
			var k = 1024;
			var sizes = ['B', 'KB', 'MB', 'GB'];
			var i = Math.floor(Math.log(bytes) / Math.log(k));
			return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
		},

		escapeHtml: function(text) {
			var div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		}
	};

	$(document).ready(function() {
		OPcachePreload.init();
	});

})(jQuery);
