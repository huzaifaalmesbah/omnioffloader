/**
 * OmniOffloader Media Status & AJAX Actions
 *
 * Provides real-time status updates and seamless actions in the Media Library,
 * Media Editor Meta Box, and Attachment Details modal.
 *
 * @package OmniOffloader
 */

(function($) {
	'use strict';

	var config = window.omnioffloaderMedia || {};
	var cloudIcon = config.iconCloud || '';
	var warningIcon = config.iconWarning || '';
	var spinnerIcon = config.iconSpinner || '';

	var pollTimer = null;

	function scheduleInFlightPoll(delay) {
		if (pollTimer) {
			clearTimeout(pollTimer);
		}
		pollTimer = setTimeout(checkInFlight, delay || 2000);
	}

	// 1. AJAX Row Actions in Media Library List View
	$(document).on('click', '.omnioffloader-row-action', function(e) {
		e.preventDefault();

		var $link = $(this);
		var attachmentId = $link.data('attachment-id');
		var action = $link.data('action'); // 'offload' or 'restore'

		if ($link.hasClass('is-busy')) {
			return;
		}
		$link.addClass('is-busy');

		var $row = $link.closest('tr');
		var $cell = $row.find('.column-omnioffloader_status .omnioffloader-status-cell');
		var loadingText = (action === 'restore')
			? (config.i18n && config.i18n.restoring ? config.i18n.restoring : 'Restoring...')
			: (config.i18n && config.i18n.offloading ? config.i18n.offloading : 'Offloading...');

		// Immediately show animated spinner in status column cell
		if ($cell.length) {
			$cell.attr('class', 'omnioffloader-status-cell omnioffloader-status-cell--processing')
				.attr('data-status', 'processing')
				.html(
					'<span class="omnioffloader-status-icon omnioffloader-status-icon--processing" title="' + loadingText + '">' +
					spinnerIcon +
					'<span class="screen-reader-text">' + loadingText + '</span></span>'
				);
		}

		$link.text(loadingText);

		var endpoint = (action === 'restore')
			? (config.restoreUrl + attachmentId)
			: (config.offloadUrl + attachmentId);

		$.ajax({
			url: endpoint,
			method: 'POST',
			headers: { 'X-WP-Nonce': config.nonce },
			dataType: 'json'
		}).done(function(response) {
			if (response && response.column_html && $cell.length) {
				$cell.replaceWith(response.column_html);
				var $newCell = $row.find('.column-omnioffloader_status .omnioffloader-status-cell');
				$newCell.addClass('omnioffloader-updated');
			}

			if (response && response.row_action_html) {
				var $container = $link.closest('.omnioffloader_offload, .omnioffloader_restore');
				if ($container.length) {
					$container.replaceWith(response.row_action_html);
				} else {
					$link.replaceWith(response.row_action_html);
				}
			}

			if (response && response.badge_html) {
				var $badge = $('.omnioffloader-details-badge[data-attachment-id="' + attachmentId + '"]:not(.omnioffloader-meta-box-wrap)');
				if ($badge.length) {
					$badge.replaceWith(response.badge_html);
					$('.omnioffloader-details-badge[data-attachment-id="' + attachmentId + '"]:not(.omnioffloader-meta-box-wrap)').addClass('omnioffloader-updated');
				}
			}

			if (response && response.meta_box_html) {
				var $metaBox = $('.omnioffloader-meta-box-wrap[data-attachment-id="' + attachmentId + '"]');
				if ($metaBox.length) {
					$metaBox.replaceWith(response.meta_box_html);
				}
			}

			var statusKey = (response && response.status && typeof response.status === 'object')
				? response.status.status
				: (response ? response.status : '');

			if (statusKey === 'processing' || statusKey === 'queued') {
				scheduleInFlightPoll(1500);
			}
		}).fail(function() {
			window.location.reload();
		});
	});

	// 2. AJAX Button in Media Editor Meta Box & Attachment Details Modal
	$(document).on('click', '.omnioffloader-details-action', function(e) {
		e.preventDefault();
		e.stopPropagation();

		var $btn = $(this);
		var attachmentId = $btn.data('attachment-id');
		var action = $btn.data('action'); // 'offload' or 'restore'

		if ($btn.hasClass('is-busy') || $btn.prop('disabled')) {
			return;
		}
		$btn.addClass('is-busy').prop('disabled', true);

		var loadingText = (action === 'restore')
			? (config.i18n && config.i18n.restoring ? config.i18n.restoring : 'Restoring...')
			: (config.i18n && config.i18n.offloading ? config.i18n.offloading : 'Offloading...');

		// Check if inside Meta Box on post.php
		var $metaBoxWrap = $btn.closest('.omnioffloader-meta-box-wrap');
		if ($metaBoxWrap.length) {
			$metaBoxWrap.attr('class', 'omnioffloader-meta-box-wrap omnioffloader-details-badge omnioffloader-details-badge--processing')
				.attr('data-status', 'processing');
			$metaBoxWrap.find('.omnioffloader-meta-box-status')
				.html('<span class="omnioffloader-badge omnioffloader-badge--processing">' + spinnerIcon + ' <span class="omnioffloader-badge__text">' + loadingText + '</span></span>');
			$metaBoxWrap.find('.omnioffloader-meta-box-actions').empty();
		} else {
			// Inside modal: replace container with single spinner badge (never duplicate text)
			var $badgeContainer = $btn.closest('.omnioffloader-details-badge');
			if ($badgeContainer.length) {
				$badgeContainer.attr('class', 'omnioffloader-details-badge omnioffloader-details-badge--processing')
					.attr('data-status', 'processing')
					.html('<span class="omnioffloader-badge omnioffloader-badge--processing">' + spinnerIcon + ' <span class="omnioffloader-badge__text">' + loadingText + '</span></span>');
			}
		}

		// Also update any list view column cell if present
		var $cell = $('.column-omnioffloader_status .omnioffloader-status-cell[data-attachment-id="' + attachmentId + '"]');
		if ($cell.length) {
			$cell.attr('class', 'omnioffloader-status-cell omnioffloader-status-cell--processing')
				.attr('data-status', 'processing')
				.html(
					'<span class="omnioffloader-status-icon omnioffloader-status-icon--processing" title="' + loadingText + '">' +
					spinnerIcon +
					'<span class="screen-reader-text">' + loadingText + '</span></span>'
				);
		}

		var endpoint = (action === 'restore')
			? (config.restoreUrl + attachmentId)
			: (config.offloadUrl + attachmentId);

		$.ajax({
			url: endpoint,
			method: 'POST',
			headers: { 'X-WP-Nonce': config.nonce },
			dataType: 'json'
		}).done(function(response) {
			if (response && response.meta_box_html) {
				var $metaBox = $('.omnioffloader-meta-box-wrap[data-attachment-id="' + attachmentId + '"]');
				if ($metaBox.length) {
					$metaBox.replaceWith(response.meta_box_html);
				}
			}

			if (response && response.badge_html) {
				var $modalBadges = $('.omnioffloader-details-badge[data-attachment-id="' + attachmentId + '"]:not(.omnioffloader-meta-box-wrap)');
				if ($modalBadges.length) {
					$modalBadges.replaceWith(response.badge_html);
					$('.omnioffloader-details-badge[data-attachment-id="' + attachmentId + '"]:not(.omnioffloader-meta-box-wrap)').addClass('omnioffloader-updated');
				}
			}

			if (response && response.column_html && $cell.length) {
				$cell.replaceWith(response.column_html);
				$('.column-omnioffloader_status .omnioffloader-status-cell[data-attachment-id="' + attachmentId + '"]').addClass('omnioffloader-updated');
			}

			if (response && response.row_action_html) {
				var $rowAction = $('.omnioffloader-row-action[data-attachment-id="' + attachmentId + '"]').closest('.omnioffloader_offload, .omnioffloader_restore');
				if ($rowAction.length) {
					$rowAction.replaceWith(response.row_action_html);
				}
			}

			if (response && response.url) {
				$('#attachment_url, input.urlfield').val(response.url);
				$('.misc-pub-attachment a').attr('href', response.url);
			}

			var statusKey = (response && response.status && typeof response.status === 'object')
				? response.status.status
				: (response ? response.status : '');

			if (window.wp && wp.media && wp.media.attachment) {
				var model = wp.media.attachment(attachmentId);
				if (model && model.get('omnioffloader')) {
					model.set('omnioffloader', $.extend({}, model.get('omnioffloader'), {
						status: statusKey,
						is_offloaded: (statusKey === 'offloaded'),
						badge_html: response.badge_html,
						column_html: response.column_html
					}));
				}
			}

			if (statusKey === 'processing' || statusKey === 'queued') {
				scheduleInFlightPoll(1500);
			}
		}).fail(function() {
			window.location.reload();
		});
	});

	// 3. In-flight Background Queue Poller (Checks cells, details badges & Meta Boxes)
	function checkInFlight() {
		if (!config.restUrl) {
			return;
		}

		var $cells = $('.omnioffloader-status-cell--processing, .omnioffloader-status-cell--queued');
		var $badges = $('.omnioffloader-details-badge--processing, .omnioffloader-details-badge--queued');

		if (!$cells.length && !$badges.length) {
			return;
		}

		var ids = [];
		$cells.each(function() {
			var id = $(this).data('attachment-id');
			if (id && ids.indexOf(id) === -1) {
				ids.push(id);
			}
		});
		$badges.each(function() {
			var id = $(this).data('attachment-id');
			if (id && ids.indexOf(id) === -1) {
				ids.push(id);
			}
		});

		if (!ids.length) {
			return;
		}

		$.ajax({
			url: config.restUrl + '?ids=' + ids.join(','),
			method: 'GET',
			headers: { 'X-WP-Nonce': config.nonce },
			dataType: 'json'
		}).done(function(statuses) {
			var stillBusy = 0;

			$.each(statuses, function(id, data) {
				if (data.status === 'processing' || data.status === 'queued') {
					stillBusy++;
				} else {
					// Update list view cell
					var $cell = $('.omnioffloader-status-cell[data-attachment-id="' + id + '"]');
					if ($cell.length && data.column_html) {
						$cell.replaceWith(data.column_html);
						$('.omnioffloader-status-cell[data-attachment-id="' + id + '"]').addClass('omnioffloader-updated');
					}

					// Update list view row action
					if (data.row_action_html) {
						var $rowAction = $('.omnioffloader-row-action[data-attachment-id="' + id + '"]').closest('.omnioffloader_offload, .omnioffloader_restore');
						if ($rowAction.length) {
							$rowAction.replaceWith(data.row_action_html);
						}
					}

					// Update Meta Box on post.php
					var $metaBox = $('.omnioffloader-meta-box-wrap[data-attachment-id="' + id + '"]');
					if ($metaBox.length && data.meta_box_html) {
						$metaBox.replaceWith(data.meta_box_html);
					}

					// Update modal details badge
					var $badge = $('.omnioffloader-details-badge[data-attachment-id="' + id + '"]:not(.omnioffloader-meta-box-wrap)');
					if ($badge.length && data.badge_html) {
						$badge.replaceWith(data.badge_html);
						$('.omnioffloader-details-badge[data-attachment-id="' + id + '"]:not(.omnioffloader-meta-box-wrap)').addClass('omnioffloader-updated');
					}

					// Update Backbone media model if available
					if (window.wp && wp.media && wp.media.attachment) {
						var m = wp.media.attachment(id);
						if (m && m.get('omnioffloader')) {
							m.set('omnioffloader', $.extend({}, m.get('omnioffloader'), {
								status: data.status,
								is_offloaded: (data.status === 'offloaded'),
								badge_html: data.badge_html,
								column_html: data.column_html
							}));
						}
					}
				}
			});

			if (stillBusy > 0) {
				pollTimer = setTimeout(checkInFlight, 2500);
			}
		});
	}

	$(document).ready(function() {
		if ($('.omnioffloader-status-cell--processing, .omnioffloader-status-cell--queued, .omnioffloader-details-badge--processing, .omnioffloader-details-badge--queued').length) {
			scheduleInFlightPoll(1500);
		}
	});

	// Trigger in-flight check when an attachment modal is opened in Grid View
	$(document).on('click', '.attachments .attachment', function() {
		setTimeout(function() {
			if ($('.omnioffloader-details-badge--processing, .omnioffloader-details-badge--queued').length) {
				scheduleInFlightPoll(800);
			}
		}, 300);
	});

	// 4. Grid View & Backbone Attachment View Patching
	if (window.wp && wp.media && wp.media.view && wp.media.view.Attachment) {
		var AttachmentView = wp.media.view.Attachment;
		var proto = AttachmentView.prototype;

		if (!proto._omnioffloaderPatched) {
			var originalRender = proto.render;
			var originalInitialize = proto.initialize;

			proto.initialize = function() {
				if (typeof originalInitialize === 'function') {
					originalInitialize.apply(this, arguments);
				}
				if (this.model) {
					this.listenTo(this.model, 'change:omnioffloader', this.render);
				}
			};

			proto.render = function() {
				var result = originalRender.apply(this, arguments);

				if (!this.model) {
					return result;
				}

				var data = this.model.get('omnioffloader');
				var $thumbnail = this.$el.find('.thumbnail');

				$thumbnail.find('.omnioffloader-grid-badge').remove();

				if (data) {
					if (data.is_offloaded && data.status !== 'processing') {
						$thumbnail.append('<span class="omnioffloader-grid-badge omnioffloader-grid-badge--offloaded" title="' + (data.label || 'Offloaded') + '">' + cloudIcon + '</span>');
					} else if (data.status === 'processing' || data.status === 'queued') {
						$thumbnail.append('<span class="omnioffloader-grid-badge omnioffloader-grid-badge--processing" title="' + (data.label || 'Offloading...') + '">' + spinnerIcon + '</span>');
					} else if (data.status === 'failed') {
						$thumbnail.append('<span class="omnioffloader-grid-badge omnioffloader-grid-badge--failed" title="' + (data.label || 'Offload failed') + '">' + warningIcon + '</span>');
					}
				}

				return result;
			};

			proto._omnioffloaderPatched = true;
		}

		// Patch Details view if available to live-update sidebar badge
		if (wp.media.view.Attachment.Details) {
			var DetailsView = wp.media.view.Attachment.Details;
			var detailsProto = DetailsView.prototype;

			if (!detailsProto._omnioffloaderPatched) {
				var origDetailsRender = detailsProto.render;
				detailsProto.render = function() {
					var res = origDetailsRender.apply(this, arguments);
					if (this.model) {
						var data = this.model.get('omnioffloader');
						if (data && data.badge_html) {
							this.$el.find('.omnioffloader-details-badge:not(.omnioffloader-meta-box-wrap)').replaceWith(data.badge_html);
						}
					}
					return res;
				};
				detailsProto._omnioffloaderPatched = true;
			}
		}
	}

})(jQuery);
