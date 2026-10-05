/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * tk/parallax editor UI — classic script, wp.* globals only.
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
	var MediaUpload = wp.blockEditor.MediaUpload;
	var MediaUploadCheck = wp.blockEditor.MediaUploadCheck;
	var PanelBody = wp.components.PanelBody;
	var Button = wp.components.Button;
	var RangeControl = wp.components.RangeControl;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
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

	registerBlockType( 'tk/parallax', {
		edit: function( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var overlay = attributes.overlay || 'bottom';

			var blockProps = useBlockProps( {
				className: 'tk-parallax-editor tk-parallax--overlay-' + overlay,
				style: {
					minHeight: attributes.minHeight + 'px',
					backgroundImage: attributes.imageUrl ? 'url(' + attributes.imageUrl + ')' : undefined
				}
			} );

			var inspector = el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Parallax Settings', 'tk-fields' ), initialOpen: true },
					withTip(
						__( 'Background image for the parallax section. Choose an image wider than the content area for best results.', 'tk-fields' ),
						el( MediaUploadCheck, null,
							el( MediaUpload, {
								onSelect: function( media ) {
									setAttributes( { imageId: media.id, imageUrl: media.url } );
								},
								allowedTypes: [ 'image' ],
								value: attributes.imageId,
								render: function( obj ) {
									return el(
										'div',
										{ className: 'tkf-media-field' },
										attributes.imageUrl
											? el( 'img', {
												src: attributes.imageUrl,
												alt: '',
												className: 'tkf-media-preview',
												style: { maxWidth: '100%', height: 'auto', display: 'block', marginBottom: '8px' }
											} )
											: null,
										el(
											Button,
											{ onClick: obj.open, variant: 'secondary' },
											attributes.imageUrl
												? __( 'Replace image', 'tk-fields' )
												: __( 'Choose image', 'tk-fields' )
										),
										attributes.imageUrl
											? el(
												Button,
												{
													onClick: function() { setAttributes( { imageId: 0, imageUrl: '' } ); },
													variant: 'tertiary',
													isDestructive: true,
													style: { marginLeft: '8px' }
												},
												__( 'Remove', 'tk-fields' )
											)
											: null
									);
								}
							} )
						)
					),
					withTip(
						__( 'A dark scrim over the background so text stays readable. Bottom fades up from the base; Full covers the whole image.', 'tk-fields' ),
						el( SelectControl, {
							label: __( 'Overlay', 'tk-fields' ),
							value: overlay,
							options: [
								{ label: __( 'None', 'tk-fields' ), value: 'none' },
								{ label: __( 'Bottom gradient', 'tk-fields' ), value: 'bottom' },
								{ label: __( 'Full', 'tk-fields' ), value: 'full' }
							],
							onChange: function( v ) { setAttributes( { overlay: v } ); }
						} )
					),
					withTip(
						__( 'How dark the overlay scrim is, from transparent (0) to solid black (1).', 'tk-fields' ),
						el( RangeControl, {
							label: __( 'Overlay opacity', 'tk-fields' ),
							value: attributes.overlayOpacity,
							min: 0,
							max: 1,
							step: 0.05,
							onChange: function( v ) { setAttributes( { overlayOpacity: v } ); }
						} )
					),
					withTip(
						__( 'How strongly the background lags behind the scroll. Lower values are subtler; 1 moves almost with the page; 0 is static.', 'tk-fields' ),
						el( RangeControl, {
							label: __( 'Parallax speed', 'tk-fields' ),
							value: attributes.speed,
							min: 0,
							max: 1,
							step: 0.05,
							onChange: function( v ) { setAttributes( { speed: v } ); }
						} )
					),
					withTip(
						__( 'On phones and small tablets the background stays fixed instead of moving — smoother and cheaper on mobile.', 'tk-fields' ),
						el( ToggleControl, {
							label: __( 'Disable effect on mobile', 'tk-fields' ),
							checked: attributes.disableOnMobile !== false,
							onChange: function( v ) { setAttributes( { disableOnMobile: v } ); }
						} )
					),
					withTip(
						__( 'Minimum section height in pixels.', 'tk-fields' ),
						el( TextControl, {
							label: __( 'Minimum height (px)', 'tk-fields' ),
							type: 'number',
							min: 0,
							value: attributes.minHeight,
							onChange: function( v ) { setAttributes( { minHeight: parseInt( v, 10 ) || 0 } ); }
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
					'none' !== overlay
						? el( 'div', {
							className: 'tk-parallax-overlay',
							'aria-hidden': true,
							style: { opacity: attributes.overlayOpacity }
						} )
						: null,
					el( 'div', { className: 'tk-parallax-editor-content' },
						el( InnerBlocks, {
							renderAppender: InnerBlocks.ButtonBlockAppender,
							placeholder: __( 'Add content…', 'tk-fields' )
						} )
					)
				)
			);
		},
		save: function() {
			return null;
		}
	} );
} )( window.wp );
