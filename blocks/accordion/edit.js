/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

( function () {
	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var InnerBlocks = wp.blockEditor.InnerBlocks;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var ToggleControl = wp.components.ToggleControl;
	var Tooltip = wp.components.Tooltip;
	var __ = wp.i18n.__;

	var ITEM_TEMPLATE = [
		[ 'tk/accordion-item', { title: __( 'Item 1', 'tk-fields' ) } ],
		[ 'tk/accordion-item', { title: __( 'Item 2', 'tk-fields' ) } ]
	];

	// Every inspector control gets a <Tooltip> (non-negotiable). The control
	// sits inside a plain div so Tooltip's cloned handlers land on DOM, not
	// on the component.
	function withTip( tip, control ) {
		return el(
			Tooltip,
			{ text: tip },
			el( 'div', { className: 'tkf-tip-wrap' }, control )
		);
	}

	registerBlockType( 'tk/accordion', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			return [
				el(
					InspectorControls,
					{ key: 'tk-accordion-inspector' },
					el(
						PanelBody,
						{ title: __( 'Accordion Settings', 'tk-fields' ), initialOpen: true },
						withTip(
							__( 'Card: white rounded panels with gaps. Minimal: divider lines only, no cards. Filled: soft tinted panels.', 'tk-fields' ),
							el( SelectControl, {
								label: __( 'Style', 'tk-fields' ),
								value: attributes.variant || 'card',
								options: [
									{ label: __( 'Card', 'tk-fields' ), value: 'card' },
									{ label: __( 'Minimal', 'tk-fields' ), value: 'minimal' },
									{ label: __( 'Filled', 'tk-fields' ), value: 'filled' }
								],
								onChange: function ( v ) {
									setAttributes( { variant: v } );
								}
							} )
						),
						withTip(
							__( 'Put the chevron before the title (left) or after it (right).', 'tk-fields' ),
							el( SelectControl, {
								label: __( 'Icon position', 'tk-fields' ),
								value: attributes.iconPosition || 'right',
								options: [
									{ label: __( 'Right', 'tk-fields' ), value: 'right' },
									{ label: __( 'Left', 'tk-fields' ), value: 'left' }
								],
								onChange: function ( v ) {
									setAttributes( { iconPosition: v } );
								}
							} )
						),
						withTip(
							__( 'When off, opening one item closes the others.', 'tk-fields' ),
							el( 'div', { className: 'tkf-tip-wrap' },
								el( ToggleControl, {
									label: __( 'Allow multiple open', 'tk-fields' ),
									checked: !! attributes.allowMultiple,
									onChange: function ( v ) {
										setAttributes( { allowMultiple: v } );
									}
								} )
							)
						),
						withTip(
							__( 'Open the first item automatically when the page loads.', 'tk-fields' ),
							el( 'div', { className: 'tkf-tip-wrap' },
								el( ToggleControl, {
									label: __( 'Open first item', 'tk-fields' ),
									checked: !! attributes.openFirst,
									onChange: function ( v ) {
										setAttributes( { openFirst: v } );
									}
								} )
							)
						)
					)
				),
				el(
					'div',
					{
						key: 'tk-accordion-canvas',
						className: 'tk-accordion-block tk-accordion--style-' + ( attributes.variant || 'card' )
					},
					el( InnerBlocks, {
						allowedBlocks: [ 'tk/accordion-item' ],
						template: ITEM_TEMPLATE
					} )
				)
			];
		},
		save: function () {
			return null;
		}
	} );
} )();
