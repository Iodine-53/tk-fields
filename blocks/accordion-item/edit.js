/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

( function () {
	var el = wp.element.createElement;
	var useEffect = wp.element.useEffect;
	var registerBlockType = wp.blocks.registerBlockType;
	var InnerBlocks = wp.blockEditor.InnerBlocks;
	var RichText = wp.blockEditor.RichText;
	var __ = wp.i18n.__;

	registerBlockType( 'tk/accordion-item', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var clientId = props.clientId;

			// Stable per-instance id, set once from the block's clientId.
			useEffect( function () {
				if ( ! attributes.itemId ) {
					setAttributes( { itemId: 'tk-acc-' + clientId.slice( 0, 8 ) } );
				}
			}, [] );

			return el(
				'div',
				{ className: 'tk-accordion-item-block' },
				el( RichText, {
					tagName: 'div',
					className: 'tk-accordion-item-title',
					value: attributes.title,
					placeholder: __( 'Accordion title…', 'tk-fields' ),
					allowedFormats: [],
					onChange: function ( value ) {
						setAttributes( { title: value } );
					}
				} ),
				el( InnerBlocks, {} )
			);
		},
		save: function () {
			return null;
		}
	} );
} )();
