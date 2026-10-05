/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

( function () {
	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var InnerBlocks = wp.blockEditor.InnerBlocks;
	var __ = wp.i18n.__;

	registerBlockType( 'tk/slide', {
		edit: function () {
			return el(
				'div',
				{ className: 'tk-slide-block' },
				el(
					'span',
					{ className: 'tk-slide-block-label' },
					__( 'Slide', 'tk-fields' )
				),
				el( InnerBlocks, {} )
			);
		},
		save: function () {
			return null;
		}
	} );
} )();
