/**
 * admin-media.js — WP media library modal integration.
 *
 * Targets any .gcm-media-picker container:
 *   data-target="<id of hidden image-ID input>"
 *   data-preview="<id of preview img>"
 *
 * The container must include:
 *   - A hidden <input type="hidden" id="…"> for attachment ID
 *   - A text/url <input> for the URL (id = data-target + '_url' by convention
 *     but can be any sibling with class .gcm-url-input or the next text input)
 *   - A button.gcm-btn-pick-media
 *   - An <img> with the preview id
 */
(function ($) {
	'use strict';

	$(document).ready(function () {
		$(document).on('click', '.gcm-btn-pick-media', function (e) {
			e.preventDefault();

			var $btn       = $(this);
			var $picker    = $btn.closest('.gcm-media-picker');
			var targetId   = $picker.data('target');
			var previewId  = $picker.data('preview');
			var $idInput   = $('#' + targetId);
			var $urlInput  = $picker.find('input[type="url"], input[type="text"]').first();
			var $preview   = $('#' + previewId);

			// Create a fresh frame each time.
			var frame = wp.media({
				title:    'Select Image',
				button:   { text: 'Use this image' },
				multiple: false,
				library:  { type: 'image' }
			});

			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				if ($idInput.length)  $idInput.val(attachment.id);
				if ($urlInput.length) $urlInput.val(attachment.url);
				if ($preview.length) {
					$preview.attr('src', attachment.url).show();
				}
			});

			frame.open();
		});
	});
}(jQuery));
