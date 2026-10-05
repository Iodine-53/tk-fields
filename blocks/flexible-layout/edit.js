/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * Editor UI for tk/flexible-layout.
 *
 * One layout row. The parent's embedded layouts (via block context) resolve
 * the layout definition: label, sub-fields. Inner blocks are the fixed
 * tk/field-value sub-field template (templateLock="all" — containment
 * without blocking the parent's programmatic Add-layout inserts, which
 * only the PARENT needs to perform).
 *
 * Collapsed rows show a summary: the first two sub-fields as
 * "Label: value" pairs, values truncated to 60 chars.
 */
(function (blocks, blockEditor, components, element, data, i18n) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var InnerBlocks = blockEditor.InnerBlocks;
	var Button = components.Button;
	var Tooltip = components.Tooltip;
	var useEffect = element.useEffect;
	var useSelect = data.useSelect;
	var useDispatch = data.useDispatch;

	function scalarText(value) {
		if (value === null || value === undefined || value === '') {
			return '—';
		}
		if (typeof value === 'boolean') {
			return value ? __('Yes', 'tk-fields') : __('No', 'tk-fields');
		}
		if (typeof value === 'object') {
			try {
				return JSON.stringify(value);
			} catch (e) {
				return String(value);
			}
		}
		return String(value);
	}

	function truncate(text, max) {
		text = String(text);
		return text.length > max ? text.slice(0, max - 1) + '…' : text;
	}

	function Edit(props) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var clientId = props.clientId;
		var context = props.context || {};

		var layouts = context['tk/flexible-layouts'] || [];
		var layoutKey = attributes.layout || '';
		var layoutDef = null;
		for (var i = 0; i < layouts.length; i++) {
			if (layouts[i].key === layoutKey) {
				layoutDef = layouts[i];
				break;
			}
		}
		if (!layoutDef) {
			layoutDef = { key: layoutKey, label: layoutKey, fields: [] };
		}

		var blockProps = useBlockProps({ className: 'tk-flexible-layout-editor' });

		// Stable row identity, set once from the clientId.
		useEffect(
			function () {
				if (!attributes.layoutId && clientId) {
					setAttributes({
						layoutId: 'layout-' + String(clientId).replace(/[^a-zA-Z0-9-]/g, '').slice(0, 24),
					});
				}
				// Intentionally run once on mount.
				// eslint-disable-next-line react-hooks/exhaustive-deps
			},
			[]
		);

		var innerBlocks = useSelect(
			function (select) {
				return select('core/block-editor').getBlocks(clientId);
			},
			[clientId]
		);

		var removeBlocks = useDispatch('core/block-editor').removeBlocks;

		function subValue(name) {
			for (var j = 0; j < (innerBlocks || []).length; j++) {
				var b = innerBlocks[j];
				if (b.name === 'tk/field-value' && b.attributes && b.attributes.fieldName === name) {
					return b.attributes.value;
				}
			}
			return undefined;
		}

		// Collapsed summary: first two sub-fields as "Label: value".
		var summaryParts = (layoutDef.fields || []).slice(0, 2).map(function (sub) {
			var label = sub.label || sub.name || '';
			return label + ': ' + truncate(scalarText(subValue(sub.name)), 60);
		});

		var collapsed = !!attributes.collapsed;

		return el(
			'div',
			blockProps,
			el(
				'div',
				{ className: 'tk-flexible-layout-editor__head' },
				el(
					Tooltip,
					{ text: __('One flexible content row. Its sub-fields are fixed by the layout.', 'tk-fields') },
					el('strong', null, layoutDef.label || layoutKey || __('Layout', 'tk-fields'))
				),
				el(
					'div',
					{ className: 'tk-flexible-layout-editor__actions' },
					el(
						Button,
						{
							variant: 'link',
							onClick: function () {
								setAttributes({ collapsed: !collapsed });
							},
						},
						collapsed ? __('Expand', 'tk-fields') : __('Collapse', 'tk-fields')
					),
					el(
						Button,
						{
							variant: 'link',
							isDestructive: true,
							onClick: function () {
								removeBlocks(clientId);
							},
						},
						__('Remove', 'tk-fields')
					)
				)
			),
			collapsed
				? el('div', { className: 'tk-flexible-layout-editor__summary' }, summaryParts.join(' · ') || '—')
				: el(InnerBlocks, {
						allowedBlocks: ['tk/field-value'],
						templateLock: 'all',
						renderAppender: false,
					})
		);
	}

	blocks.registerBlockType('tk/flexible-layout', {
		edit: Edit,
		save: function () {
			// Dynamic block: the frontend markup is produced by render.php.
			// InnerBlocks.Content is required so the sub-field value blocks
			// serialize into post_content (save() returning null would drop them).
			return el(InnerBlocks.Content, null);
		},
	});
})(
	window.wp.blocks,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.element,
	window.wp.data,
	window.wp.i18n
);
