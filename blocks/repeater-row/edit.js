/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * Editor UI for tk/repeater-row.
 *
 * Assigns a stable rowId (derived from the block clientId) exactly once —
 * rows are never identified by array index. Inner blocks are restricted to
 * the repeater's per-instance allowedBlocks (from block context), falling
 * back to the PHP-filtered defaults injected as window.tkFieldsRepeaterDefaults.
 */
(function (blocks, blockEditor, components, element, i18n) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var registerBlockType = blocks.registerBlockType;
	var useBlockProps = blockEditor.useBlockProps;
	var InnerBlocks = blockEditor.InnerBlocks;
	var Tooltip = components.Tooltip;
	var useEffect = element.useEffect;

	var FALLBACK_DEFAULTS = [
		'core/paragraph',
		'core/heading',
		'core/image',
		'core/list',
		'core/buttons',
		'core/quote',
		'tk/field-value',
	];

	function Edit(props) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var clientId = props.clientId;
		var context = props.context || {};

		var blockProps = useBlockProps({
			className: 'tk-repeater-row-editor',
		});

		// Stable identity: set rowId once from the clientId; never touch it again.
		useEffect(
			function () {
				if (!attributes.rowId && clientId) {
					setAttributes({
						rowId: 'row-' + String(clientId).replace(/[^a-zA-Z0-9-]/g, '').slice(0, 24),
					});
				}
				// Intentionally run once on mount.
				// eslint-disable-next-line react-hooks/exhaustive-deps
			},
			[]
		);

		var injected =
			window.tkFieldsRepeaterDefaults && window.tkFieldsRepeaterDefaults.allowedBlocks
				? window.tkFieldsRepeaterDefaults.allowedBlocks
				: FALLBACK_DEFAULTS;

		var allowedBlocks =
			context['tk/repeater-allowed-blocks'] && context['tk/repeater-allowed-blocks'].length
				? context['tk/repeater-allowed-blocks']
				: injected;

		return el(
			'div',
			blockProps,
			el(
				Tooltip,
				{
					text: __(
						'One repeater row. Rows keep a stable ID (shown on the frontend as data-row-id) and are never addressed by position.',
						'tk-fields'
					),
				},
				el('span', { className: 'tk-repeater-row-label' }, __('Row', 'tk-fields'))
			),
			el(InnerBlocks, {
				allowedBlocks: allowedBlocks,
				renderAppender: InnerBlocks.ButtonBlockAppender,
			})
		);
	}

	function Save() {
		// Dynamic block: the frontend markup is produced by render.php.
		return el(InnerBlocks.Content, null);
	}

	registerBlockType('tk/repeater-row', {
		edit: Edit,
		save: Save,
	});
})(window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.i18n);
