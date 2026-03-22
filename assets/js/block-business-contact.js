/**
 * Metamanager — Business Contact Card block registration.
 *
 * Server-side rendered. No per-instance settings — all configuration is
 * managed at SEO → Contact Card in the WordPress admin.
 */
( function () {
	'use strict';

	var el         = wp.element.createElement;
	var __         = wp.i18n.__;
	var BlockIcon  = el( 'svg', { xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24', width: 24, height: 24 },
		el( 'path', { d: 'M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zM8 9a2 2 0 1 1 0 4 2 2 0 0 1 0-4zm4 8H4v-.5c0-1.38 2.69-2.5 4-2.5s4 1.12 4 2.5V17zm8-2h-6v-1h6v1zm0-2h-6v-1h6v1zm0-2h-6v-1h6v1z' } )
	);

	wp.blocks.registerBlockType( 'gcm-seo/business-contact', {
		title:    __( 'Business Contact Card', 'metamanager' ),
		icon:     BlockIcon,
		category: 'widgets',
		description: __( 'Displays the business contact card with click-to-call, SMS, email, and contact download buttons. Configure appearance at SEO → Contact Card.', 'metamanager' ),
		supports: {
			html:              false,
			multiple:          true,
			reusable:          true,
			align:             false,
			customClassName:   true,
		},
		attributes: {},

		edit: function ( props ) {
			return el(
				'div',
				{
					className:   'gcm-biz-block-editor-preview',
					style: {
						padding:      '16px 20px',
						background:   '#f6f7f7',
						border:       '1px solid #c3c4c7',
						borderRadius: '4px',
						textAlign:    'center',
					},
				},
				el( 'div', { style: { marginBottom: '8px' } }, BlockIcon ),
				el( 'strong', {}, __( 'Business Contact Card', 'metamanager' ) ),
				el(
					'p',
					{ style: { margin: '6px 0 10px', color: '#646970', fontSize: '13px' } },
					__( 'Renders the business contact card with the actions and styles configured at SEO → Contact Card.', 'metamanager' )
				),
				el(
					'a',
					{
						href:   gcmBizBlock.settingsUrl,
						target: '_blank',
						rel:    'noopener noreferrer',
						style:  { fontSize: '12px' },
					},
					__( 'Open Contact Card settings ↗', 'metamanager' )
				)
			);
		},

		save: function () {
			return null; // Server-side rendered via PHP render_callback.
		},
	} );
} )();
