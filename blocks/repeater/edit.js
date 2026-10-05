/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * Editor UI for tk/repeater.
 *
 * No build step: classic script using wp.* globals only. The block metadata
 * (attributes, providesContext, category) comes from block.json via the
 * server-side registration; this file only supplies edit + save.
 */
(function (blocks, blockEditor, components, element, i18n) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var registerBlockType = blocks.registerBlockType;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var InnerBlocks = blockEditor.InnerBlocks;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var SelectControl = components.SelectControl;
	var Tooltip = components.Tooltip;

	function Edit(props) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var layout = attributes.layout || 'list';

		var blockProps = useBlockProps({
			className: 'tk-repeater-editor tk-repeater--' + layout,
		});

		return el(
			element.Fragment,
			null,
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __('Repeater Settings', 'tk-fields'), initialOpen: true },
					el(
						Tooltip,
						{
							text: __(
								"Used by tk_have_rows('name') in templates — must be unique per post.",
								'tk-fields'
							),
						},
						el(TextControl, {
							label: __('Field name', 'tk-fields'),
							value: attributes.fieldName || '',
							onChange: function (value) {
								setAttributes({ fieldName: value });
							},
							help: __(
								'The repeater selector, e.g. "team".',
								'tk-fields'
							),
						})
					),
					el(
						Tooltip,
						{
							text: __(
								'How rows are laid out on the frontend: stacked list or responsive grid.',
								'tk-fields'
							),
						},
						el(SelectControl, {
							label: __('Layout', 'tk-fields'),
							value: layout,
							options: [
								{ label: __('List', 'tk-fields'), value: 'list' },
								{ label: __('Grid', 'tk-fields'), value: 'grid' },
							],
							onChange: function (value) {
								setAttributes({ layout: value });
							},
						})
					)
				)
			),
			el(
				'div',
				blockProps,
				el(InnerBlocks, {
					allowedBlocks: ['tk/repeater-row'],
					template: [['tk/repeater-row']],
					renderAppender: InnerBlocks.ButtonBlockAppender,
				})
			)
		);
	}

	function Save() {
		// Dynamic block: the frontend markup is produced by render.php.
		return el(InnerBlocks.Content, null);
	}

	registerBlockType('tk/repeater', {
		edit: Edit,
		save: Save,
	});
})(window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.i18n);
