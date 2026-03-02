/**
 * Metamanager — Media Library Compression Status
 *
 * Polls the REST endpoint every 10 seconds to update compression status
 * indicators in the Media Library list view without a page reload.
 *
 * @package Metamanager
 */
/* global MMStatus, jQuery */

jQuery( function ( $ ) {
	'use strict';

	/**
	 * Collect all attachment IDs in the current Media Library view.
	 *
	 * @returns {number[]}
	 */
	function getVisibleIds() {
		var ids = [];
		$( '.mm-compress-status[data-id]' ).each( function () {
			ids.push( parseInt( $( this ).data( 'id' ), 10 ) );
		} );
		return ids;
	}

	/**
	 * Request status for all visible attachments and update the DOM.
	 */
	function updateStatusColumns() {
		var ids = getVisibleIds();
		if ( ! ids.length ) {
			return;
		}

		$.ajax( {
			url:    MMStatus.restUrl,
			method: 'POST',
			beforeSend: function ( xhr ) {
				xhr.setRequestHeader( 'X-WP-Nonce', MMStatus.nonce );
			},
			contentType: 'application/json',
			data:        JSON.stringify( { ids: ids } ),
			success: function ( response ) {
				$.each( response, function ( id, info ) {
					var $el = $( '#mm-status-' + id );
					if ( $el.length ) {
						$el.css( 'color', info.color )
						   .text( info.label );
					}
				} );
			}
		} );
	}

	// Run immediately on page load, then every 10 seconds.
	updateStatusColumns();
	setInterval( updateStatusColumns, 10000 );
} );
