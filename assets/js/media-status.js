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
			pollTimer = null;
		}

		if (document.hidden) {
			// If tab is hidden, only poll if bulk job is active, at a slow 12s rate.
			if (!isBulkRunning) {
				return;
			}
			delay = Math.max(delay || 0, 12000);
		}

		pollTimer = setTimeout(checkInFlight, delay || 2000);
	}

	// 1. AJAX Row Actions in Media Library List View
	$(document).on('click', '.omnioffloader-row-action', function(e) {
		var $link = $(this);
		var action = $link.data('action'); // 'offload' or 'restore'

		if ($link.hasClass('is-busy') || !$link.is('a') || !action) {
			return;
		}

		e.preventDefault();

		var attachmentId = $link.data('attachment-id');
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
				var $container = $link.closest('.omnioffloader_offload, .omnioffloader_restore, .omnioffloader_action, .omnioffloader_processing, .omnioffloader_queued');
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
				var $rowAction = $('.omnioffloader-row-action[data-attachment-id="' + attachmentId + '"]');
				var $container = $rowAction.closest('.omnioffloader_offload, .omnioffloader_restore, .omnioffloader_action, .omnioffloader_processing, .omnioffloader_queued');
				if ($container.length) {
					$container.replaceWith(response.row_action_html);
				} else if ($rowAction.length) {
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

	// 3. Helper to collect all visible attachment states on screen
	function getVisibleAttachmentStates() {
		var items = [];
		var seen = {};

		// Media library list view cells
		$('.omnioffloader-status-cell').each(function() {
			var id = $(this).data('attachment-id');
			var status = $(this).attr('data-status') || '';
			if (id && !seen[id]) {
				seen[id] = true;
				items.push({ id: id, status: status });
			}
		});

		// Meta Box on post.php
		$('.omnioffloader-meta-box-wrap').each(function() {
			var id = $(this).data('attachment-id');
			var status = $(this).attr('data-status') || '';
			if (id && !seen[id]) {
				seen[id] = true;
				items.push({ id: id, status: status });
			}
		});

		// Modal details badges
		$('.omnioffloader-details-badge').each(function() {
			var id = $(this).data('attachment-id');
			var status = $(this).attr('data-status') || '';
			if (id && !seen[id]) {
				seen[id] = true;
				items.push({ id: id, status: status });
			}
		});

		return items;
	}

	var isBulkRunning = false;
	var inFlightCheckRunning = false;

	// 4. In-flight Background Queue Poller (Checks visible cells, details badges & Meta Boxes)
	function checkInFlight(force) {
		if (!config.restUrl || inFlightCheckRunning) {
			return;
		}

		var visibleItems = getVisibleAttachmentStates();

		if (!visibleItems.length && !force) {
			return;
		}

		var queryItems = visibleItems.slice(0, 100);
		var queryIds = [];
		var knownPairs = [];

		for (var i = 0; i < queryItems.length; i++) {
			queryIds.push(queryItems[i].id);
			if (queryItems[i].status) {
				knownPairs.push(queryItems[i].id + ':' + queryItems[i].status);
			}
		}

		inFlightCheckRunning = true;

		var url = config.restUrl;
		var params = [];
		if (queryIds.length) {
			params.push('ids=' + queryIds.join(','));
		}
		if (knownPairs.length) {
			params.push('known=' + encodeURIComponent(knownPairs.join(',')));
		}
		if (params.length) {
			url += (url.indexOf('?') === -1 ? '?' : '&') + params.join('&');
		}

		$.ajax({
			url: url,
			method: 'GET',
			headers: { 'X-WP-Nonce': config.nonce },
			dataType: 'json'
		}).done(function(response) {
			var bulk = (response && response.bulk) ? response.bulk : null;
			var statuses = (response && response.statuses) ? response.statuses : response;

			isBulkRunning = (bulk && bulk.active) ? true : false;
			var hasProcessingItems = false;

			if (statuses && typeof statuses === 'object') {
				$.each(statuses, function(id, data) {
					if (!data || typeof data !== 'object') {
						return;
					}

					var statusKey = data.status;
					if (statusKey === 'processing' || statusKey === 'queued') {
						hasProcessingItems = true;
					}

					// Update list view cell
					var $cell = $('.omnioffloader-status-cell[data-attachment-id="' + id + '"]');
					if ($cell.length && data.column_html) {
						var currentStatus = $cell.attr('data-status');
						if (currentStatus !== statusKey) {
							$cell.replaceWith(data.column_html);
							var $newCell = $('.omnioffloader-status-cell[data-attachment-id="' + id + '"]');
							if (statusKey !== 'processing' && statusKey !== 'queued') {
								$newCell.addClass('omnioffloader-updated');
							}
						}
					}

					// Update list view row action
					if (data.row_action_html) {
						var $rowAction = $('.omnioffloader-row-action[data-attachment-id="' + id + '"]');
						var $container = $rowAction.closest('.omnioffloader_offload, .omnioffloader_restore, .omnioffloader_action, .omnioffloader_processing, .omnioffloader_queued');
						if ($container.length) {
							$container.replaceWith(data.row_action_html);
						} else if ($rowAction.length) {
							$rowAction.replaceWith(data.row_action_html);
						}
					}

					// Update Meta Box on post.php
					var $metaBox = $('.omnioffloader-meta-box-wrap[data-attachment-id="' + id + '"]');
					if ($metaBox.length && data.meta_box_html) {
						var currentMetaStatus = $metaBox.attr('data-status');
						if (currentMetaStatus !== statusKey) {
							$metaBox.replaceWith(data.meta_box_html);
						}
					}

					// Update modal details badge
					var $badge = $('.omnioffloader-details-badge[data-attachment-id="' + id + '"]:not(.omnioffloader-meta-box-wrap)');
					if ($badge.length && data.badge_html) {
						var currentBadgeStatus = $badge.attr('data-status');
						if (currentBadgeStatus !== statusKey) {
							$badge.replaceWith(data.badge_html);
							if (statusKey !== 'processing' && statusKey !== 'queued') {
								$('.omnioffloader-details-badge[data-attachment-id="' + id + '"]:not(.omnioffloader-meta-box-wrap)').addClass('omnioffloader-updated');
							}
						}
					}

					// Update Backbone media model if available
					if (window.wp && wp.media && wp.media.attachment) {
						var m = wp.media.attachment(id);
						if (m && m.get('omnioffloader')) {
							var prev = m.get('omnioffloader');
							if (prev && prev.status !== statusKey) {
								m.set('omnioffloader', $.extend({}, prev, {
									status: statusKey,
									is_offloaded: (statusKey === 'offloaded'),
									badge_html: data.badge_html,
									column_html: data.column_html
								}));
							}
						}
					}
				});
			}

			// If a bulk job is running, mark the current active item as busy if visible
			if (bulk && bulk.active && bulk.current_id) {
				var curId = bulk.current_id;
				var curType = bulk.type || 'offload';
				var curText = (curType === 'restore')
					? (config.i18n && config.i18n.restoring ? config.i18n.restoring : 'Restoring...')
					: (config.i18n && config.i18n.offloading ? config.i18n.offloading : 'Offloading...');
				var $curAction = $('.omnioffloader-row-action[data-attachment-id="' + curId + '"]');
				if ($curAction.length && !$curAction.hasClass('is-busy')) {
					$curAction.addClass('is-busy').text(curText);
				}
			}

			// Keep polling if a bulk job is running or any items on screen are in flight
			if (isBulkRunning || hasProcessingItems) {
				scheduleInFlightPoll(1800);
			}
		}).always(function() {
			inFlightCheckRunning = false;
		});
	}

	// 5. Intercept Media Library Bulk Action Dropdown for Instant AJAX Execution
	$(document).on('click', '#doaction, #doaction2', function(e) {
		var isTop = (this.id === 'doaction');
		var actionValue = isTop
			? $('#bulk-action-selector-top').val()
			: $('#bulk-action-selector-bottom').val();

		if (actionValue !== 'omnioffloader_bulk_offload' && actionValue !== 'omnioffloader_bulk_restore') {
			return;
		}

		var $checkedBoxes = $('tbody th.check-column input[type="checkbox"]:checked');
		if (!$checkedBoxes.length) {
			return;
		}

		e.preventDefault();

		var actionType = (actionValue === 'omnioffloader_bulk_offload') ? 'offload' : 'restore';
		var loadingText = (actionType === 'restore')
			? (config.i18n && config.i18n.restoring ? config.i18n.restoring : 'Restoring...')
			: (config.i18n && config.i18n.offloading ? config.i18n.offloading : 'Offloading...');

		var selectedIds = [];
		$checkedBoxes.each(function() {
			var val = parseInt($(this).val(), 10);
			if (val) {
				selectedIds.push(val);
			}
		});

		if (!selectedIds.length) {
			return;
		}

		// Immediately update all eligible selected rows to processing spinners and row action text
		$.each(selectedIds, function(idx, id) {
			var $cell = $('.omnioffloader-status-cell[data-attachment-id="' + id + '"]');
			var currentStatus = $cell.length ? $cell.attr('data-status') : '';

			// Skip items already in target state
			if (actionType === 'offload' && currentStatus === 'offloaded') {
				return;
			}
			if (actionType === 'restore' && (currentStatus === 'local' || currentStatus === 'failed')) {
				return;
			}

			if ($cell.length) {
				$cell.attr('class', 'omnioffloader-status-cell omnioffloader-status-cell--processing')
					.attr('data-status', 'processing')
					.html(
						'<span class="omnioffloader-status-icon omnioffloader-status-icon--processing" title="' + loadingText + '">' +
						spinnerIcon +
						'<span class="screen-reader-text">' + loadingText + '</span></span>'
					);
			}

			var $rowAction = $('.omnioffloader-row-action[data-attachment-id="' + id + '"]');
			if ($rowAction.length) {
				$rowAction.addClass('is-busy').text(loadingText);
			}
		});

		// Uncheck all selected checkboxes and reset dropdown
		$checkedBoxes.prop('checked', false);
		$('#cb-select-all-1, #cb-select-all-2').prop('checked', false);
		$('#bulk-action-selector-top, #bulk-action-selector-bottom').val('-1');

		var bulkEndpoint = config.bulkUrl || (config.restUrl ? config.restUrl.replace('offload/attachment-status', 'bulk/action') : '');
		if (bulkEndpoint) {
			$.ajax({
				url: bulkEndpoint,
				method: 'POST',
				headers: { 'X-WP-Nonce': config.nonce },
				contentType: 'application/json',
				data: JSON.stringify({
					action: actionType,
					ids: selectedIds
				}),
				dataType: 'json'
			}).done(function() {
				isBulkRunning = true;
				scheduleInFlightPoll(1000);
			}).fail(function() {
				scheduleInFlightPoll(1500);
			});
		}
	});

	$(document).ready(function() {
		// Run initial check on page load to detect running bulk jobs or in-flight items
		checkInFlight(true);
	});

	// Wake up poller when user returns to this browser tab
	$(window).on('focus', function() {
		checkInFlight(true);
	});

	if (typeof document.addEventListener === 'function') {
		document.addEventListener('visibilitychange', function() {
			if (!document.hidden) {
				checkInFlight(true);
			} else if (!isBulkRunning && pollTimer) {
				clearTimeout(pollTimer);
				pollTimer = null;
			}
		});
	}

	// Trigger in-flight check when an attachment modal is opened in Grid View
	$(document).on('click', '.attachments .attachment', function() {
		setTimeout(function() {
			checkInFlight(true);
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
