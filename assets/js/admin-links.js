/**
 * admin-links.js — AJAX broken-links table for the Links tab.
 */
(function ($) {
	'use strict';

	var ajax_url  = (window.gcmLinks && gcmLinks.ajax_url) || window.ajaxurl || '';
	var nonce     = (window.gcmLinks && gcmLinks.nonce) || '';
	var PER_PAGE  = 50;

	var state = {
		filter : 'all',   // all | broken | ok | ignored
		paged  : 1,
		total  : 0,
	};

	// ── Helpers ───────────────────────────────────────────────────────────

	function esc(str) {
		return $('<span>').text(str || '').html();
	}

	function statusBadge(row) {
		if (row.is_ignored === '1') {
			return '<span class="gcm-badge gcm-badge--ignored">Ignored</span>';
		}
		if (row.is_broken === '1') {
			return '<span class="gcm-badge gcm-badge--broken">Broken</span>';
		}
		return '<span class="gcm-badge gcm-badge--ok">OK</span>';
	}

	function formatDate(dateStr) {
		if (!dateStr || dateStr === '0000-00-00 00:00:00') return '—';
		return dateStr.replace('T', ' ').substring(0, 16);
	}

	// ── Render ────────────────────────────────────────────────────────────

	function renderTable(rows, total) {
		var $wrap = $('#gcm-links-table-wrap');
		state.total = total;

		if (!rows || !rows.length) {
			$wrap.html('<p>No links found.</p>');
			renderPagination();
			return;
		}

		var html = '<table class="wp-list-table widefat fixed striped gcm-links-table">' +
			'<thead><tr>' +
			'<th style="width:30%">URL</th>' +
			'<th style="width:15%">Anchor</th>' +
			'<th style="width:15%">Post</th>' +
			'<th style="width:7%">HTTP</th>' +
			'<th style="width:10%">Status</th>' +
			'<th style="width:12%">Last Checked</th>' +
			'<th style="width:11%">Actions</th>' +
			'</tr></thead><tbody>';

		$.each(rows, function (i, row) {
			var ignored  = row.is_ignored === '1';
			var ignLabel = ignored ? 'Unignore' : 'Ignore';
			var ignVal   = ignored ? '0' : '1';

			html += '<tr data-id="' + esc(row.id) + '">' +
				'<td><a href="' + esc(row.url) + '" target="_blank" rel="noopener">' + esc(row.url) + '</a></td>' +
				'<td>' + esc(row.anchor_text) + '</td>' +
				'<td>' + (row.post_title ? '<a href="' + esc(row.post_edit_url) + '">' + esc(row.post_title) + '</a>' : esc(row.post_id)) + '</td>' +
				'<td>' + esc(row.http_code || '—') + '</td>' +
				'<td>' + statusBadge(row) + '</td>' +
				'<td>' + esc(formatDate(row.last_checked)) + '</td>' +
				'<td class="gcm-link-actions">' +
				'<button class="button button-small gcm-recheck-link" data-id="' + esc(row.id) + '">Re-check</button> ' +
				'<button class="button button-small gcm-ignore-link" data-id="' + esc(row.id) + '" data-ignore="' + ignVal + '">' + ignLabel + '</button>' +
				'</td>' +
				'</tr>';
		});

		html += '</tbody></table>';
		$wrap.html(html);
		renderPagination();
	}

	function renderPagination() {
		var $pager = $('#gcm-links-pager');
		$pager.empty();
		if (!state.total || state.total <= PER_PAGE) return;

		var pages = Math.ceil(state.total / PER_PAGE);
		if (state.paged > 1) {
			$pager.append('<button class="button gcm-page-btn" data-paged="' + (state.paged - 1) + '">&#9664; Prev</button> ');
		}
		$pager.append('<span>Page ' + state.paged + ' of ' + pages + '</span>');
		if (state.paged < pages) {
			$pager.append(' <button class="button gcm-page-btn" data-paged="' + (state.paged + 1) + '">Next &#9654;</button>');
		}
	}

	// ── Scan all posts (backfill) ─────────────────────────────────────────

	function scanAllPosts() {
		var $btn      = $('#gcm-scan-all-posts');
		var $progress = $('#gcm-scan-progress');
		var $done     = $('#gcm-scan-done');
		var $count    = $('#gcm-scan-count');
		var $total    = $('#gcm-scan-total');

		$btn.prop('disabled', true).text('Scanning…');
		$done.hide();
		$progress.show();

		function runBatch(offset) {
			$.post(ajax_url, {
				action : 'mm_meta_scan_all_posts',
				_nonce : nonce,
				offset : offset,
			}, function (res) {
				if (!res || !res.success) {
					$progress.hide();
					$btn.prop('disabled', false).text('Scan All Posts for Links');
					return;
				}
				var d = res.data;
				$total.text(d.total);
				$count.text(d.new_offset);
				if (d.done) {
					$progress.hide();
					$done.show();
					$btn.prop('disabled', false).text('Scan All Posts for Links');
					fetchLinks();
				} else {
					runBatch(d.new_offset);
				}
			});
		}

		runBatch(0);
	}

	// ── Data fetch ────────────────────────────────────────────────────────

	function fetchLinks() {
		var $wrap = $('#gcm-links-table-wrap');
		$wrap.html('<p>Loading…</p>');

		$.post(ajax_url, {
			action  : 'mm_meta_links_fetch',
			_nonce  : nonce,
			filter  : state.filter,
			paged   : state.paged,
			per_page: PER_PAGE,
		}, function (res) {
			if (res && res.success) {
				renderTable(res.data.rows, res.data.total);
			} else {
				$wrap.html('<p class="gcm-error">Could not load links: ' + esc((res && res.data) ? res.data : 'unknown error') + '</p>');
			}
		});
	}

	// ── Row actions ───────────────────────────────────────────────────────

	function recheckLink(id, $btn) {
		$btn.text('Checking…').prop('disabled', true);
		$.post(ajax_url, {
			action  : 'mm_meta_recheck_link',
			_nonce  : nonce,
			link_id : id,
		}, function (res) {
			if (res && res.success) {
				fetchLinks(); // reload table to reflect new status
			} else {
				$btn.text('Error').prop('disabled', false);
			}
		});
	}

	function ignoreLink(id, ignored, $btn) {
		$btn.prop('disabled', true);
		$.post(ajax_url, {
			action  : 'mm_meta_ignore_link',
			_nonce  : nonce,
			link_id : id,
			ignored : ignored,
		}, function (res) {
			if (res && res.success) {
				fetchLinks();
			} else {
				$btn.prop('disabled', false);
			}
		});
	}

	// ── Event bindings ────────────────────────────────────────────────────

	$(document).ready(function () {

		// Only run on links tab.
		if (!$('#gcm-links-table-wrap').length) return;

		fetchLinks();

		// Filter buttons.
		$(document).on('click', '.gcm-links-filter-btn', function () {
			state.filter = $(this).data('filter') || 'all';
			state.paged  = 1;
			$('.gcm-links-filter-btn').removeClass('gcm-links-filter-btn--active');
			$(this).addClass('gcm-links-filter-btn--active');
			fetchLinks();
		});

		// Pagination.
		$(document).on('click', '.gcm-page-btn', function () {
			state.paged = parseInt($(this).data('paged'), 10) || 1;
			fetchLinks();
		});

		// Re-check individual row.
		$(document).on('click', '.gcm-recheck-link', function () {
			recheckLink($(this).data('id'), $(this));
		});

		// Ignore / unignore individual row.
		$(document).on('click', '.gcm-ignore-link', function () {
			ignoreLink($(this).data('id'), $(this).data('ignore'), $(this));
		});

		// Scan all posts for links (initial backfill).
		$('#gcm-scan-all-posts').on('click', function () {
			scanAllPosts();
		});

		// Re-check all visible rows.
		$('#gcm-recheck-all').on('click', function () {
			var $self = $(this);
			$self.prop('disabled', true).text('Checking…');
			var ids = [];
			$('.gcm-recheck-link').each(function () {
				ids.push($(this).data('id'));
			});

			if (!ids.length) {
				$self.prop('disabled', false).text('Re-check All');
				return;
			}

			var done = 0;
			function next() {
				if (done >= ids.length) {
					fetchLinks();
					$self.prop('disabled', false).text('Re-check All');
					return;
				}
				$.post(ajax_url, {
					action  : 'mm_meta_recheck_link',
					_nonce  : nonce,
					link_id : ids[done],
				}, function () {
					done++;
					$self.text('Checking… (' + done + '/' + ids.length + ')');
					next();
				});
			}
			next();
		});
	});
}(jQuery));
