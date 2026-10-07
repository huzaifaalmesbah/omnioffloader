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

	// Poll interval while something runs: starts fast, eases off while
	// nothing changes, and is reset as soon as a status changes. A failed
	// poll waits longer instead of stopping the badges for good.
	var POLL_MIN = 1800;
	var POLL_MAX = 6000;
	var POLL_STEP = 600;
	var POLL_FAILED_MAX = 12000;
	var pollDelay = POLL_MIN;

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

	/**
	 * Repaint one item from the server after a failed action.
	 *
	 * Its optimistic "processing" look is forgotten, so the next status check
	 * sends the real markup; a check already in flight gets a follow-up.
	 *
	 * @param {number} attachmentId Attachment ID.
	 */
	function refreshItem(attachmentId) {
		$('.omnioffloader-status-cell[data-attachment-id="' + attachmentId + '"]').attr('data-status', '');
		$('.omnioffloader-meta-box-wrap[data-attachment-id="' + attachmentId + '"]').attr('data-status', '');
		$('.omnioffloader-details-badge[data-attachment-id="' + attachmentId + '"]').attr('data-status', '');
		$('.attachments .attachment[data-id="' + attachmentId + '"]').attr('data-omnioffloader-status', '');

		if (inFlightCheckRunning) {
			scheduleInFlightPoll(800);
		} else {
			checkInFlight(true);
		}
	}

	/**
	 * Show why an action failed, as a dismissible admin notice.
	 *
	 * The message comes from the server (a WP_Error) and is set as text,
	 * never as markup.
	 *
	 * @param {Object} xhr jQuery XHR of the failed request.
	 */
	function showActionError(xhr) {
		var i18n = config.i18n || {};
		var message = (xhr && xhr.responseJSON && xhr.responseJSON.message)
			? String(xhr.responseJSON.message)
			: (i18n.failedTry || 'The action could not be completed. Please try again.');

		var $notice = $('<div class="notice notice-error is-dismissible omnioffloader-action-error"><p></p></div>');
		$notice.find('p').text(message);
		$notice.append(
			$('<button type="button" class="notice-dismiss"><span class="screen-reader-text"></span></button>')
		);
		$notice.find('.screen-reader-text').text(i18n.dismiss || 'Dismiss this notice.');
		$notice.on('click', '.notice-dismiss', function() {
			$notice.remove();
		});

		$('.omnioffloader-action-error').remove();

		var $anchor = $('.wp-header-end').first();
		if ($anchor.length) {
			$anchor.after($notice);
		} else {
			$('#wpbody-content .wrap').first().prepend($notice);
		}
	}

	/**
	 * Build HTML for grid view badges.
	 *
	 * @param {Object|string} data Attachment status data or status key.
	 * @return {string} Badge markup.
	 */
	/**
	 * Escape a value for use inside a double-quoted HTML attribute.
	 *
	 * Labels carry error text (file names, object keys) that may come from
	 * third-party filters, so they are never trusted as markup.
	 *
	 * @param {*} value Raw value.
	 * @return {string} Escaped value.
	 */
	function escAttr(value) {
		return String(value)
			.replace(/&/g, '&amp;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;');
	}

	function renderGridBadgeHtml(data) {
		if (!data) {
			return '';
		}

		var status = (data && typeof data === 'object') ? (data.status || '') : data;
		var isOffloaded = (data && typeof data === 'object') ? !!data.is_offloaded : (status === 'offloaded');
		var label = (data && typeof data === 'object') ? (data.label || '') : '';

		if (isOffloaded && status !== 'processing') {
			return '<span class="omnioffloader-grid-badge omnioffloader-grid-badge--offloaded" title="' + escAttr((label || (config.i18n && config.i18n.offloaded ? config.i18n.offloaded : 'Offloaded'))) + '">' + cloudIcon + '</span>';
		}
		if (status === 'processing') {
			return '<span class="omnioffloader-grid-badge omnioffloader-grid-badge--processing" title="' + escAttr((label || (config.i18n && config.i18n.offloading ? config.i18n.offloading : 'Offloading...'))) + '">' + spinnerIcon + '</span>';
		}
		if (status === 'queued') {
			return '<span class="omnioffloader-grid-badge omnioffloader-grid-badge--queued" title="' + escAttr((label || (config.i18n && config.i18n.queued ? config.i18n.queued : 'Queued...'))) + '">' + spinnerIcon + '</span>';
		}
		if (status === 'failed') {
			return '<span class="omnioffloader-grid-badge omnioffloader-grid-badge--failed" title="' + escAttr((label || (config.i18n && config.i18n.failed ? config.i18n.failed : 'Offload failed'))) + '">' + warningIcon + '</span>';
		}

		return '';
	}

	/**
	 * Update grid view attachment item badge and data attribute in the DOM.
	 *
	 * @param {jQuery} $item Attachment list element (li.attachment).
	 * @param {Object|string} data Attachment status data.
	 */
	function updateGridBadge($item, data) {
		if (!$item || !$item.length) {
			return;
		}

		var $thumb = $item.find('.thumbnail');
		if (!$thumb.length) {
			$thumb = $item.find('.attachment-preview');
		}
		if (!$thumb.length) {
			$thumb = $item;
		}

		var prevStatus = $item.attr('data-omnioffloader-status') || '';
		var newStatus = (data && typeof data === 'object') ? (data.status || 'local') : (data || 'local');

		$thumb.find('.omnioffloader-grid-badge').remove();
		var badgeHtml = renderGridBadgeHtml(data);
		if (badgeHtml) {
			var $badge = $(badgeHtml);
			if (prevStatus && prevStatus !== newStatus && newStatus !== 'processing' && newStatus !== 'queued') {
				$badge.addClass('omnioffloader-updated');
			}
			$thumb.append($badge);
		}

		$item.attr('data-omnioffloader-status', newStatus);

		// Keep Backbone model in sync silently without triggering recursive view renders
		var rawId = $item.data('id') || $item.attr('data-id');
		var id = parseInt(rawId, 10);
		if (id && window.wp && wp.media && wp.media.attachment) {
			var m = wp.media.attachment(id);
			if (m) {
				var prev = m.get('omnioffloader') || {};
				if (prev.status !== newStatus) {
					m.set('omnioffloader', $.extend({}, prev, {
						status: newStatus,
						is_offloaded: (data && typeof data === 'object') ? !!data.is_offloaded : (newStatus === 'offloaded'),
						label: (data && typeof data === 'object') ? (data.label || '') : '',
						badge_html: (data && typeof data === 'object' && data.badge_html) ? data.badge_html : prev.badge_html,
						column_html: (data && typeof data === 'object' && data.column_html) ? data.column_html : prev.column_html
					}), { silent: true });
				}
			}
		}
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
					'<span class="omnioffloader-status-icon omnioffloader-status-icon--processing" title="' + escAttr(loadingText) + '">' +
					spinnerIcon +
					'<span class="screen-reader-text">' + escAttr(loadingText) + '</span></span>'
				);
		}

		$link.text(loadingText);

		// Optimistically update grid badge if item is in the grid
		var $gridItem = $('.attachments .attachment[data-id="' + attachmentId + '"]');
		if ($gridItem.length) {
			updateGridBadge($gridItem, {
				status: 'processing',
				is_offloaded: (action === 'restore'),
				label: loadingText
			});
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

			var $doneGridItem = $('.attachments .attachment[data-id="' + attachmentId + '"]');
			if ($doneGridItem.length) {
				updateGridBadge($doneGridItem, {
					status: statusKey,
					is_offloaded: (statusKey === 'offloaded'),
					label: (response && response.status && response.status.label) ? response.status.label : (response ? response.label : '')
				});
			}

			if (statusKey === 'processing' || statusKey === 'queued') {
				scheduleInFlightPoll(1500);
			}
		}).fail(function(xhr) {
			// Show the reason and put the item back as the server sees it.
			showActionError(xhr);
			refreshItem(attachmentId);
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
				.html('<span class="omnioffloader-badge omnioffloader-badge--processing">' + spinnerIcon + ' <span class="omnioffloader-badge__text">' + escAttr(loadingText) + '</span></span>');
			$metaBoxWrap.find('.omnioffloader-meta-box-actions').empty();
		} else {
			// Inside modal: replace container with single spinner badge (never duplicate text)
			var $badgeContainer = $btn.closest('.omnioffloader-details-badge');
			if ($badgeContainer.length) {
				$badgeContainer.attr('class', 'omnioffloader-details-badge omnioffloader-details-badge--processing')
					.attr('data-status', 'processing')
					.html('<span class="omnioffloader-badge omnioffloader-badge--processing">' + spinnerIcon + ' <span class="omnioffloader-badge__text">' + escAttr(loadingText) + '</span></span>');
			}
		}

		// Also update any list view column cell if present
		var $cell = $('.column-omnioffloader_status .omnioffloader-status-cell[data-attachment-id="' + attachmentId + '"]');
		if ($cell.length) {
			$cell.attr('class', 'omnioffloader-status-cell omnioffloader-status-cell--processing')
				.attr('data-status', 'processing')
				.html(
					'<span class="omnioffloader-status-icon omnioffloader-status-icon--processing" title="' + escAttr(loadingText) + '">' +
					spinnerIcon +
					'<span class="screen-reader-text">' + escAttr(loadingText) + '</span></span>'
				);
		}

		// Also update any grid item if present
		var $gridItem = $('.attachments .attachment[data-id="' + attachmentId + '"]');
		if ($gridItem.length) {
			updateGridBadge($gridItem, {
				status: 'processing',
				is_offloaded: (action === 'restore'),
				label: loadingText
			});
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

			var $doneGridItem = $('.attachments .attachment[data-id="' + attachmentId + '"]');
			if ($doneGridItem.length) {
				updateGridBadge($doneGridItem, {
					status: statusKey,
					is_offloaded: (statusKey === 'offloaded'),
					label: (response && response.status && response.status.label) ? response.status.label : (response ? response.label : '')
				});
			}

			if (window.wp && wp.media && wp.media.attachment) {
				var model = wp.media.attachment(attachmentId);
				if (model && model.get('omnioffloader')) {
					model.set('omnioffloader', $.extend({}, model.get('omnioffloader'), {
						status: statusKey,
						is_offloaded: (statusKey === 'offloaded'),
						badge_html: response.badge_html,
						column_html: response.column_html
					}), { silent: true });
				}
			}

			if (statusKey === 'processing' || statusKey === 'queued') {
				scheduleInFlightPoll(1500);
			}
		}).fail(function(xhr) {
			// Show the reason and put the item back as the server sees it.
			showActionError(xhr);
			refreshItem(attachmentId);
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

		// Media library grid view items
		$('.attachments .attachment').each(function() {
			var rawId = $(this).data('id') || $(this).attr('data-id');
			if (!rawId) {
				return;
			}
			var id = parseInt(rawId, 10);
			if (!id || seen[id]) {
				return;
			}
			seen[id] = true;

			var status = $(this).attr('data-omnioffloader-status');
			if (!status) {
				var $badge = $(this).find('.omnioffloader-grid-badge');
				if ($badge.hasClass('omnioffloader-grid-badge--offloaded')) {
					status = 'offloaded';
				} else if ($badge.hasClass('omnioffloader-grid-badge--processing')) {
					status = 'processing';
				} else if ($badge.hasClass('omnioffloader-grid-badge--queued')) {
					status = 'queued';
				} else if ($badge.hasClass('omnioffloader-grid-badge--failed')) {
					status = 'failed';
				} else {
					status = 'local';
				}
				$(this).attr('data-omnioffloader-status', status);
			}

			items.push({ id: id, status: status });
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

		// Prioritize transitional items (processing/queued) so in-flight tasks are never starved
		var activeItems = [];
		var inactiveItems = [];
		for (var j = 0; j < visibleItems.length; j++) {
			var itemStatus = visibleItems[j].status;
			if (itemStatus === 'processing' || itemStatus === 'queued') {
				activeItems.push(visibleItems[j]);
			} else {
				inactiveItems.push(visibleItems[j]);
			}
		}
		var queryItems = activeItems.concat(inactiveItems).slice(0, 100);
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
			var changed = false;

			if (statuses && typeof statuses === 'object') {
				$.each(statuses, function(id, data) {
					if (!data || typeof data !== 'object') {
						return;
					}

					var statusKey = data.status;
					if (statusKey === 'processing' || statusKey === 'queued') {
						hasProcessingItems = true;
					}

					var shownStatus = $('.omnioffloader-status-cell[data-attachment-id="' + id + '"]').attr('data-status') ||
						$('.attachments .attachment[data-id="' + id + '"]').attr('data-omnioffloader-status') || '';
					if (shownStatus && shownStatus !== statusKey) {
						changed = true;
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

					// Update grid view item
					var $gridItem = $('.attachments .attachment[data-id="' + id + '"]');
					if ($gridItem.length) {
						var currentGridStatus = $gridItem.attr('data-omnioffloader-status');
						if (currentGridStatus !== statusKey) {
							updateGridBadge($gridItem, data);
						}
					}

					// Update Backbone media model silently if available
					if (window.wp && wp.media && wp.media.attachment) {
						var m = wp.media.attachment(id);
						if (m) {
							var prev = m.get('omnioffloader') || {};
							if (prev.status !== statusKey) {
								m.set('omnioffloader', $.extend({}, prev, {
									status: statusKey,
									is_offloaded: (statusKey === 'offloaded'),
									label: data.label || '',
									badge_html: data.badge_html || prev.badge_html,
									column_html: data.column_html || prev.column_html
								}), { silent: true });
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

				var $curGrid = $('.attachments .attachment[data-id="' + curId + '"]');
				if ($curGrid.length) {
					var curGridStatus = $curGrid.attr('data-omnioffloader-status');
					if (curGridStatus !== 'processing') {
						updateGridBadge($curGrid, {
							status: 'processing',
							is_offloaded: (curType === 'restore'),
							label: curText
						});
					}
				}
			}

			// Keep polling if a bulk job is running or any items on screen are in flight
			if (isBulkRunning || hasProcessingItems) {
				pollDelay = changed ? POLL_MIN : Math.min(POLL_MAX, pollDelay + POLL_STEP);
				scheduleInFlightPoll(pollDelay);
			} else {
				pollDelay = POLL_MIN;
			}
		}).fail(function() {
			// Keep the badges alive through a server hiccup, but slower.
			var stillActive = isBulkRunning;
			$.each(getVisibleAttachmentStates(), function(i, item) {
				if (item.status === 'processing' || item.status === 'queued') {
					stillActive = true;
				}
			});
			if (stillActive) {
				pollDelay = Math.min(POLL_FAILED_MAX, Math.max(pollDelay, POLL_MIN) * 2);
				scheduleInFlightPoll(pollDelay);
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
						'<span class="omnioffloader-status-icon omnioffloader-status-icon--processing" title="' + escAttr(loadingText) + '">' +
						spinnerIcon +
						'<span class="screen-reader-text">' + escAttr(loadingText) + '</span></span>'
					);
			}

			var $rowAction = $('.omnioffloader-row-action[data-attachment-id="' + id + '"]');
			if ($rowAction.length) {
				$rowAction.addClass('is-busy').text(loadingText);
			}

			var $gridItem = $('.attachments .attachment[data-id="' + id + '"]');
			if ($gridItem.length) {
				updateGridBadge($gridItem, {
					status: 'processing',
					is_offloaded: (actionType === 'restore'),
					label: loadingText
				});
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
			}).fail(function(xhr) {
				showActionError(xhr);
				scheduleInFlightPoll(1500);
			});
		}
	});

	// The media library shows bulk progress even before any item is on
	// screen; other screens (post editors) only need a check when there is
	// something visible to update or a run is already known.
	var isMediaLibrary = !!(document.body && document.body.classList && document.body.classList.contains('upload-php'));

	// Wake up poller when user returns to this browser tab
	$(window).on('focus', function() {
		checkInFlight(isBulkRunning);
	});

	if (typeof document.addEventListener === 'function') {
		document.addEventListener('visibilitychange', function() {
			if (!document.hidden) {
				checkInFlight(isBulkRunning);
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

	// 6. Grid View & Backbone Attachment View Patching
	function patchAttachmentView(ViewClass) {
		if (!ViewClass || !ViewClass.prototype || ViewClass.prototype._omnioffloaderPatched) {
			return;
		}

		var proto = ViewClass.prototype;
		var originalRender = proto.render;
		var originalInitialize = proto.initialize;

		proto.initialize = function() {
			if (typeof originalInitialize === 'function') {
				originalInitialize.apply(this, arguments);
			}
			if (this.model) {
				this.listenTo(this.model, 'change:omnioffloader', function() {
					if (this.$el) {
						var data = this.model.get('omnioffloader');
						updateGridBadge(this.$el, data);
					}
				});
			}
		};

		proto.render = function() {
			var result = originalRender.apply(this, arguments);

			if (this.model && this.$el) {
				var data = this.model.get('omnioffloader');
				if (data) {
					updateGridBadge(this.$el, data);
				}
			}

			return result;
		};

		proto._omnioffloaderPatched = true;
	}

	function patchDetailsView(DetailsClass) {
		if (!DetailsClass || !DetailsClass.prototype || DetailsClass.prototype._omnioffloaderPatched) {
			return;
		}

		var detailsProto = DetailsClass.prototype;
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

	function initGridPatching() {
		if (!window.wp || !wp.media || !wp.media.view) {
			return;
		}

		if (wp.media.view.Attachment) {
			patchAttachmentView(wp.media.view.Attachment);
		}
		if (wp.media.view.Attachment && wp.media.view.Attachment.Library) {
			patchAttachmentView(wp.media.view.Attachment.Library);
		}
		if (wp.media.view.Attachment && wp.media.view.Attachment.EditLibrary) {
			patchAttachmentView(wp.media.view.Attachment.EditLibrary);
		}
		if (wp.media.view.Attachment && wp.media.view.Attachment.Details) {
			patchDetailsView(wp.media.view.Attachment.Details);
		}
	}

	// Run patching immediately and on document ready
	initGridPatching();
	$(document).ready(function() {
		initGridPatching();
		checkInFlight(isMediaLibrary);
	});

	// Trigger in-flight check when media uploader finishes an upload in Grid View
	$(document).ajaxComplete(function(event, xhr, settings) {
		if (settings && settings.data && typeof settings.data === 'string') {
			if (settings.data.indexOf('action=upload-attachment') !== -1) {
				scheduleInFlightPoll(1000);
			}
		}
	});

	if (window.wp && wp.Uploader && wp.Uploader.queue) {
		wp.Uploader.queue.on('add reset', function() {
			scheduleInFlightPoll(1000);
		});
	}

	var scrollTimer = null;
	if (window.addEventListener) {
		window.addEventListener('scroll', function() {
			if (!isBulkRunning || scrollTimer) {
				return;
			}
			scrollTimer = setTimeout(function() {
				scrollTimer = null;
				if (isBulkRunning) {
					checkInFlight();
				}
			}, 1500);
		}, { passive: true });
	}

})(jQuery);
