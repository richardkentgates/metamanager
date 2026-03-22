/**
 * admin-repeater.js — add / remove / reorder rows in repeatable field lists.
 *
 * Works with both:
 *   - Table row repeaters (.gcm-repeater-table tbody + .gcm-repeater-row)
 *     Set data-sortable="true" on the tbody to enable drag-to-reorder.
 *     Set data-name-base="option_key[section]" so new rows get correct names.
 *   - Simple list repeaters (ul.gcm-simple-list + li.gcm-simple-list-item)
 *     Set data-name-base="option_key[section][key]" on the ul.
 *
 * The "Add" button must carry:
 *   data-target="<id of tbody or ul>"
 *   data-template="<hours|area|disallow|allow>"
 *
 * Row indices ([\d+]) are renumbered sequentially after every add/remove/sort.
 */
(function ($) {
	'use strict';

	$(document).ready(function () {

		// ── Drag-to-reorder (jQuery UI Sortable) ──────────────────────────
		$('tbody[data-sortable="true"]').sortable({
			handle:  '.gcm-drag-handle',
			axis:    'y',
			cursor:  'grabbing',
			helper:  'clone',
			opacity: 0.75,
			stop: function () {
				renumberRows( $(this) );
			}
		});

		// ── Add button ────────────────────────────────────────────────────
		$(document).on('click', '.gcm-repeater-add', function () {
			var $btn    = $(this);
			var target  = $btn.data('target');
			var tpl     = $btn.data('template');
			var $target = $( '#' + target );

			if ( ! $target.length ) return;

			var $row = buildRow( tpl, $target );
			if ( ! $row ) return;

			$target.append( $row );
			renumberRows( $target );
		});

		// ── Remove button ─────────────────────────────────────────────────
		$(document).on('click', '.gcm-repeater-remove', function () {
			var $row       = $( this ).closest( '.gcm-repeater-row, .gcm-simple-list-item' );
			var $container = $row.parent();
			$row.remove();
			renumberRows( $container );
		});

		// ── Row builders ──────────────────────────────────────────────────
		function buildRow( tpl, $target ) {
			switch ( tpl ) {
				case 'hours':   return hoursRow( $target );
				case 'area':
				case 'disallow':
				case 'allow':
				default:        return simpleListItem( $target );
			}
		}

		function simpleListItem( $target ) {
			var base     = $target ? ( $target.data('name-base') || '' ) : '';
			var name     = base ? ( base + '[]' ) : '';
			var nameAttr = name ? ( ' name="' + escAttr( name ) + '"' ) : '';
			return $(
				'<li class="gcm-simple-list-item">' +
					'<input type="text" class="regular-text"' + nameAttr + '>' +
					'<button type="button" class="button-link gcm-repeater-remove" style="margin-left:6px">&#x2715;</button>' +
				'</li>'
			);
		}

		function hoursRow( $target ) {
			var base = $target ? ( $target.data('name-base') || '' ) : '';
			var days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

			var checkboxes = days.map(function (d) {
				var chkName = base ? ( ' name="' + escAttr( base + '[0][days][]' ) + '"' ) : '';
				return '<label class="gcm-day-label">' +
					'<input type="checkbox"' + chkName + ' value="' + escAttr( d ) + '">' +
					' ' + d.substr( 0, 3 ) +
				'</label>';
			}).join('');

			var openAttr   = base ? ( ' name="' + escAttr( base + '[0][open]'   ) + '"' ) : '';
			var closeAttr  = base ? ( ' name="' + escAttr( base + '[0][close]'  ) + '"' ) : '';
			var closedAttr = base ? ( ' name="' + escAttr( base + '[0][closed]' ) + '"' ) : '';

			return $(
				'<tr class="gcm-repeater-row">' +
					'<td class="gcm-drag-handle-cell">' +
						'<span class="gcm-drag-handle dashicons dashicons-move" title="Drag to reorder"></span>' +
					'</td>' +
					'<td class="gcm-days-cell">' + checkboxes + '</td>' +
					'<td><input type="time"' + openAttr   + ' class="gcm-time-input" value="09:00"></td>' +
					'<td><input type="time"' + closeAttr  + ' class="gcm-time-input" value="17:00"></td>' +
					'<td><input type="checkbox"' + closedAttr + ' value="1"></td>' +
					'<td><button type="button" class="button-link gcm-repeater-remove">&#x2715;</button></td>' +
				'</tr>'
			);
		}

		// ── Renumber [index] in all inputs within a container ─────────────
		function renumberRows( $container ) {
			if ( ! $container.length ) return;
			var isTable = $container.is('tbody');
			var $rows   = isTable
				? $container.find('tr.gcm-repeater-row')
				: $container.find('li.gcm-simple-list-item');

			$rows.each(function (idx) {
				$( this ).find('input, select, textarea').each(function () {
					var name = $( this ).attr('name');
					if ( ! name ) return;
					// Replace the first [N] (row index) with the current idx.
					$( this ).attr('name', name.replace( /\[\d+\]/, '[' + idx + ']' ) );
				});
			});
		}

		// ── Utility ───────────────────────────────────────────────────────
		function escAttr( str ) {
			return String( str )
				.replace( /&/g, '&amp;'  )
				.replace( /"/g, '&quot;' )
				.replace( /</g, '&lt;'   )
				.replace( />/g, '&gt;'   );
		}
	});
}(jQuery));
