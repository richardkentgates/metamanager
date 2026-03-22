/**
 * admin-tabs.js — general settings-page UI helpers.
 *
 * Features:
 *   1. Collapsible sections (.gcm-toggle-section → target element)
 *   2. Description character counter (.gcm-desc-field textarea +
 *      adjacent .gcm-char-counter display element).
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {

		// ── 1. Collapsible sections ────────────────────────────────────────
		document.querySelectorAll('.gcm-toggle-section').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var target = document.getElementById(btn.dataset.target);
				if (!target) return;
				var isOpen = target.style.display !== 'none';
				target.style.display = isOpen ? 'none' : '';
				btn.textContent = btn.textContent.replace(isOpen ? '▼' : '▶', isOpen ? '▶' : '▼');
			});
		});

		// ── 2. Description character counter ──────────────────────────────
		document.querySelectorAll('.gcm-desc-field').forEach(function (textarea) {
			var counter = textarea.nextElementSibling;
			if (!counter || !counter.classList.contains('gcm-char-counter')) return;

			function update() {
				var len = textarea.value.length;
				counter.textContent = len + ' / 160';
				counter.style.color = len > 155 ? '#d63638' : len > 110 ? '#ff9800' : '';
			}

			textarea.addEventListener('input', update);
			update(); // initialise on load
		});
	});
}());
