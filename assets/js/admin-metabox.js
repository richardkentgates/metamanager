/**
 * admin-metabox.js — post SEO metabox behaviour.
 *
 * Features:
 *   1. Live SERP title preview (title input → #gcm-serp-title-text)
 *   2. Description character counter with colour-coded bar (green ≤110, amber 111-155, red >155)
 *   3. Collapsible sections (▶ buttons)
 *   4. Description counter for term + user profile pages too
 *
 * Expects mmMetabox.sep and mmMetabox.sitetitle to be localised by PHP.
 */
(function ($) {
	'use strict';

	$(document).ready(function () {

		// ── Config ────────────────────────────────────────────────────────
		var sep       = (window.mmMetabox && mmMetabox.sep)       || '|';
		var sitetitle = (window.mmMetabox && mmMetabox.sitetitle) || '';

		// ── 1. Title live preview ─────────────────────────────────────────
		var $titleInput   = $('#mm_meta_title');
		var $titlePreview = $('#gcm-serp-title-text');
		var $postTitle    = $('#title'); // native WP post title field

		function updateTitlePreview() {
			var custom = $.trim($titleInput.val());
			var raw    = $.trim($postTitle.val() || ($titlePreview.length ? $titlePreview.text() : ''));
			var text   = custom || (raw ? (raw + ' ' + sep + ' ' + sitetitle) : sitetitle);
			if ($titlePreview.length) {
				$titlePreview.text(text);
			}
		}

		$titleInput.on('input', updateTitlePreview);
		$postTitle.on('input', function () {
			if (!$.trim($titleInput.val())) updateTitlePreview();
		});

		// ── 2. Description counter ────────────────────────────────────────
		function setupDescCounter($textarea, $count, $bar) {
			if (!$textarea.length) return;

			function update() {
				var len = $textarea.val().length;
				if ($count.length) $count.text(len);

				if ($bar && $bar.length) {
					var pct = Math.min(100, Math.round(len / 160 * 100));
					$bar.css('width', pct + '%');

					$bar.removeClass('gcm-bar--green gcm-bar--amber gcm-bar--red');
					if (len <= 110) {
						$bar.addClass('gcm-bar--green');
					} else if (len <= 155) {
						$bar.addClass('gcm-bar--amber');
					} else {
						$bar.addClass('gcm-bar--red');
					}
				}
			}

			$textarea.on('input', update);
			update(); // run on load
		}

		// Post metabox description.
		setupDescCounter(
			$('#mm_meta_description'),
			$('#gcm-desc-count'),
			$('#gcm-desc-progress')
		);

		// Term / user pages (no bar, just count).
		$('.gcm-desc-textarea').each(function () {
			var $ta    = $(this);
			var $count = $ta.siblings('.gcm-char-bar').find('.gcm-char-count');
			setupDescCounter($ta, $count, null);
		});

		// ── 3. Collapsible sections ───────────────────────────────────────
		$(document).on('click', '.gcm-toggle-section', function () {
			var $btn    = $(this);
			var $target = $('#' + $btn.data('target'));
			if (!$target.length) return;
			var open = $target.is(':visible');
			$target.slideToggle(150);
			$btn.text($btn.text().replace(open ? '▼' : '▶', open ? '▶' : '▼'));
		});

		// ── 4. Update SERP description preview ───────────────────────────
		var $descInput   = $('#mm_meta_description');
		var $descPreview = $('#gcm-serp-desc-text');

		if ($descInput.length && $descPreview.length) {
			$descInput.on('input', function () {
				$descPreview.text($.trim($descInput.val()) || $descPreview.data('default') || '');
			});
			$descPreview.data('default', $descPreview.text());
		}

		// ── 5. Schema type → field panel expansion ────────────────────────
		var $schemaTypeSelect = $('#mm_meta_schema_type');
		var $schemaPanels     = $('.gcm-schema-fields-panel');

		function updateSchemaPanel() {
			var chosen = $schemaTypeSelect.val();
			$schemaPanels.hide();
			if (chosen) {
				$schemaPanels.filter('[data-schema-type="' + chosen + '"]').show();
			}
		}

		$schemaTypeSelect.on('change', updateSchemaPanel);
		// Sync on initial load so panel matches a pre-selected type after JS runs.
		updateSchemaPanel();

		// Initial preview run.
		updateTitlePreview();
	});
}(jQuery));
