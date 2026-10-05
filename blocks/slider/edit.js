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
	var TextControl = wp.components.TextControl;
	var Tooltip = wp.components.Tooltip;
	var __ = wp.i18n.__;

	var SLIDE_TEMPLATE = [
		[ 'tk/slide', {}, [ [ 'core/heading', { level: 3, content: __( 'Slide 1', 'tk-fields' ) } ] ] ],
		[ 'tk/slide', {}, [ [ 'core/heading', { level: 3, content: __( 'Slide 2', 'tk-fields' ) } ] ] ],
		[ 'tk/slide', {}, [ [ 'core/heading', { level: 3, content: __( 'Slide 3', 'tk-fields' ) } ] ] ]
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

	registerBlockType( 'tk/slider', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			function onIntervalChange( value ) {
				var n = parseInt( value, 10 );
				setAttributes( { interval: isNaN( n ) ? 0 : n } );
			}

			return [
				el(
					InspectorControls,
					{ key: 'tk-slider-inspector' },
					el(
						PanelBody,
						{ title: __( 'Slider Settings', 'tk-fields' ), initialOpen: true },
						withTip(
							__( 'How slides change: slide in from the right, cross-fade, or both. All run at 420ms.', 'tk-fields' ),
							el( SelectControl, {
								label: __( 'Transition', 'tk-fields' ),
								value: attributes.transition || 'slide',
								options: [
									{ label: __( 'Slide', 'tk-fields' ), value: 'slide' },
									{ label: __( 'Fade', 'tk-fields' ), value: 'fade' },
									{ label: __( 'Slide + Fade', 'tk-fields' ), value: 'slide-fade' }
								],
								onChange: function ( v ) {
									setAttributes( { transition: v } );
								}
							} )
						),
						withTip(
							__( 'The slider keeps this shape on every screen instead of a fixed pixel height. 16:9 is the classic widescreen look.', 'tk-fields' ),
							el( SelectControl, {
								label: __( 'Aspect ratio', 'tk-fields' ),
								value: attributes.aspectRatio || '16/9',
								options: [
									{ label: '16:9', value: '16/9' },
									{ label: '4:3', value: '4/3' },
									{ label: '1:1', value: '1/1' },
									{ label: '3:2', value: '3/2' },
									{ label: '21:9', value: '21/9' }
								],
								onChange: function ( v ) {
									setAttributes( { aspectRatio: v } );
								}
							} )
						),
						withTip(
							__( 'Automatically advance to the next slide on a timer.', 'tk-fields' ),
							el( ToggleControl, {
								label: __( 'Autoplay', 'tk-fields' ),
								checked: !! attributes.autoplay,
								onChange: function ( v ) {
									setAttributes( { autoplay: v } );
								}
							} )
						),
						withTip(
							__( 'Delay between slides in milliseconds. 5000 = 5 seconds.', 'tk-fields' ),
							el( TextControl, {
								label: __( 'Interval (ms)', 'tk-fields' ),
								type: 'number',
								min: 100,
								step: 100,
								value: attributes.interval,
								onChange: onIntervalChange
							} )
						),
						withTip(
							__( 'Pause the autoplay timer while the visitor\'s cursor is over the slider.', 'tk-fields' ),
							el( ToggleControl, {
								label: __( 'Pause on hover', 'tk-fields' ),
								checked: !! attributes.pauseOnHover,
								onChange: function ( v ) {
									setAttributes( { pauseOnHover: v } );
								}
							} )
						),
						withTip(
							__( 'Show a thin progress bar across the top of the slider while autoplay runs.', 'tk-fields' ),
							el( ToggleControl, {
								label: __( 'Show progress bar', 'tk-fields' ),
								checked: !! attributes.showProgress,
								onChange: function ( v ) {
									setAttributes( { showProgress: v } );
								}
							} )
						),
						withTip(
							__( 'Show previous/next arrow buttons on the slider.', 'tk-fields' ),
							el( ToggleControl, {
								label: __( 'Show arrows', 'tk-fields' ),
								checked: !! attributes.showArrows,
								onChange: function ( v ) {
									setAttributes( { showArrows: v } );
								}
							} )
						),
						withTip(
							__( 'Show clickable dot navigation under the slider.', 'tk-fields' ),
							el( ToggleControl, {
								label: __( 'Show dots', 'tk-fields' ),
								checked: !! attributes.showDots,
								onChange: function ( v ) {
									setAttributes( { showDots: v } );
								}
							} )
						)
					)
				),
				el(
					'div',
					{ key: 'tk-slider-canvas', className: 'tk-slider-block' },
					el( InnerBlocks, {
						allowedBlocks: [ 'tk/slide' ],
						template: SLIDE_TEMPLATE,
						orientation: 'horizontal'
					} )
				)
			];
		},
		save: function () {
			return null;
		}
	} );
} )();
