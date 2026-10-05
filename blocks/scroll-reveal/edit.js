/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * tk/scroll-reveal editor UI — classic script, wp.* globals only.
 */
( function( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InnerBlocks = wp.blockEditor.InnerBlocks;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var RangeControl = wp.components.RangeControl;
	var TextControl = wp.components.TextControl;
	var Tooltip = wp.components.Tooltip;

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

	registerBlockType( 'tk/scroll-reveal', {
		edit: function( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps( { className: 'tk-scroll-reveal-editor' } );

			var inspector = el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Reveal Settings', 'tk-fields' ), initialOpen: true },
					withTip(
						__( 'The entrance animation played when the block scrolls into view.', 'tk-fields' ),
						el( SelectControl, {
							label: __( 'Animation', 'tk-fields' ),
							value: attributes.animation,
							options: [
								{ label: __( 'Fade', 'tk-fields' ), value: 'fade' },
								{ label: __( 'Slide up', 'tk-fields' ), value: 'slide-up' },
								{ label: __( 'Slide left', 'tk-fields' ), value: 'slide-left' },
								{ label: __( 'Slide right', 'tk-fields' ), value: 'slide-right' },
								{ label: __( 'Zoom', 'tk-fields' ), value: 'zoom' }
							],
							onChange: function( v ) { setAttributes( { animation: v } ); }
						} )
					),
					withTip(
						__( 'Delay before the animation starts, in milliseconds.', 'tk-fields' ),
						el( TextControl, {
							label: __( 'Delay (ms)', 'tk-fields' ),
							type: 'number',
							min: 0,
							value: attributes.delay,
							onChange: function( v ) { setAttributes( { delay: parseInt( v, 10 ) || 0 } ); }
						} )
					),
					withTip(
						__( 'How long the animation takes, in milliseconds.', 'tk-fields' ),
						el( TextControl, {
							label: __( 'Duration (ms)', 'tk-fields' ),
							type: 'number',
							min: 0,
							value: attributes.duration,
							onChange: function( v ) { setAttributes( { duration: parseInt( v, 10 ) || 0 } ); }
						} )
					),
					withTip(
						__( 'How much of the block must be visible (0 = any part, 1 = fully) before the animation starts.', 'tk-fields' ),
						el( RangeControl, {
							label: __( 'Visibility threshold', 'tk-fields' ),
							value: attributes.threshold,
							min: 0,
							max: 1,
							step: 0.05,
							onChange: function( v ) { setAttributes( { threshold: v } ); }
						} )
					)
				)
			);

			return el(
				Fragment,
				null,
				inspector,
				el(
					'div',
					blockProps,
					el( InnerBlocks, {
						renderAppender: InnerBlocks.ButtonBlockAppender,
						placeholder: __( 'Add content to reveal…', 'tk-fields' )
					} )
				)
			);
		},
		save: function() {
			return null;
		}
	} );
} )( window.wp );
