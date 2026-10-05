/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * tk/countdown editor UI — classic script, wp.* globals only.
 */
( function( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
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

	registerBlockType( 'tk/countdown', {
		edit: function( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps( { className: 'tk-countdown-editor' } );

			var inspector = el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Countdown Settings', 'tk-fields' ), initialOpen: true },
					withTip(
						__( 'The date and time the countdown counts down to. It is interpreted in each visitor\'s own timezone — the first-paint value is computed on the server, so it may briefly differ if the server timezone does not match.', 'tk-fields' ),
						el( TextControl, {
							label: __( 'Target date & time', 'tk-fields' ),
							type: 'datetime-local',
							value: attributes.target,
							onChange: function( v ) { setAttributes( { target: v } ); }
						} )
					),
					withTip(
						__( 'Cards: each unit in its own soft panel. Inline: a plain row of numbers.', 'tk-fields' ),
						el( SelectControl, {
							label: __( 'Layout', 'tk-fields' ),
							value: attributes.layout || 'cards',
							options: [
								{ label: __( 'Cards', 'tk-fields' ), value: 'cards' },
								{ label: __( 'Inline', 'tk-fields' ), value: 'inline' }
							],
							onChange: function( v ) { setAttributes( { layout: v } ); }
						} )
					),
					withTip(
						__( 'The divider shown between units.', 'tk-fields' ),
						el( SelectControl, {
							label: __( 'Separator', 'tk-fields' ),
							value: attributes.separator || 'colon',
							options: [
								{ label: __( 'Colon (:)', 'tk-fields' ), value: 'colon' },
								{ label: __( 'Dot (·)', 'tk-fields' ), value: 'dot' },
								{ label: __( 'None', 'tk-fields' ), value: 'none' }
							],
							onChange: function( v ) { setAttributes( { separator: v } ); }
						} )
					),
					withTip(
						__( 'Include a days unit in the countdown.', 'tk-fields' ),
						el( ToggleControl, {
							label: __( 'Show days', 'tk-fields' ),
							checked: !! attributes.showDays,
							onChange: function( v ) { setAttributes( { showDays: v } ); }
						} )
					),
					withTip(
						__( 'Include an hours unit in the countdown.', 'tk-fields' ),
						el( ToggleControl, {
							label: __( 'Show hours', 'tk-fields' ),
							checked: !! attributes.showHours,
							onChange: function( v ) { setAttributes( { showHours: v } ); }
						} )
					),
					withTip(
						__( 'Include a minutes unit in the countdown.', 'tk-fields' ),
						el( ToggleControl, {
							label: __( 'Show minutes', 'tk-fields' ),
							checked: !! attributes.showMinutes,
							onChange: function( v ) { setAttributes( { showMinutes: v } ); }
						} )
					),
					withTip(
						__( 'Include a seconds unit in the countdown.', 'tk-fields' ),
						el( ToggleControl, {
							label: __( 'Show seconds', 'tk-fields' ),
							checked: !! attributes.showSeconds,
							onChange: function( v ) { setAttributes( { showSeconds: v } ); }
						} )
					),
					withTip(
						__( 'Message shown after the countdown reaches zero.', 'tk-fields' ),
						el( TextControl, {
							label: __( 'Expired message', 'tk-fields' ),
							value: attributes.expiredText,
							onChange: function( v ) { setAttributes( { expiredText: v } ); }
						} )
					),
					withTip(
						__( 'When on, the whole countdown disappears at zero instead of showing the expired message.', 'tk-fields' ),
						el( ToggleControl, {
							label: __( 'Hide when expired', 'tk-fields' ),
							checked: !! attributes.hideOnExpire,
							onChange: function( v ) { setAttributes( { hideOnExpire: v } ); }
						} )
					)
				)
			);

			var preview = attributes.target
				? el(
					'div',
					{ className: 'tk-cd-preview' },
					el( 'strong', null, __( 'Countdown', 'tk-fields' ) ),
					el( 'div', null, __( 'Target: ', 'tk-fields' ) + attributes.target )
				)
				: el(
					'div',
					{ className: 'tk-cd-preview tk-cd-preview--empty' },
					__( 'Select a target date in the block settings.', 'tk-fields' )
				);

			return el( Fragment, null, inspector, el( 'div', blockProps, preview ) );
		},
		save: function() {
			return null;
		}
	} );
} )( window.wp );
