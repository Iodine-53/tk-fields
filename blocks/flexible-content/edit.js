/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * Editor UI for tk/flexible-content.
 *
 * The parent block owns the field name. On fieldName entry it fetches the
 * resolved field definition from GET /tk/v1/field-def/<name> and embeds the
 * layouts in its own attributes — the block is self-contained (copy/paste
 * keeps working, including across fields) at the cost of going stale if
 * the group definition changes (the editor re-fetches on fieldName
 * change).
 *
 * Containment adjudication (Round 3): templateLock="all" on the PARENT
 * would also lock the programmatic Add-layout inserts, so containment is
 * instead: allowedBlocks=['tk/flexible-layout'], no appender, custom
 * Add-layout buttons, the "parent" restriction on tk/flexible-layout
 * (blocks core drag-out), and a fieldKey adoption effect that re-owns
 * moved/pasted layouts. templateLock="all" IS used on the layout block's
 * own InnerBlocks, where no programmatic inserts are needed.
 */
(function (blocks, blockEditor, components, element, data, i18n) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var InnerBlocks = blockEditor.InnerBlocks;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var Button = components.Button;
	var Tooltip = components.Tooltip;
	var Notice = components.Notice;
	var useEffect = element.useEffect;
	var useState = element.useState;
	var useSelect = data.useSelect;
	var useDispatch = data.useDispatch;

	var apiFetch = window.wp.apiFetch;

	var defCache = {};

	function fetchFieldDef(fieldName) {
		if (!fieldName) {
			return Promise.resolve(null);
		}
		if (defCache[fieldName]) {
			return defCache[fieldName];
		}
		defCache[fieldName] = apiFetch({ path: '/tk/v1/field-def/' + encodeURIComponent(fieldName) })
			.then(function (res) {
				return res && res.field ? res.field : null;
			})
			.catch(function () {
				return null;
			});
		return defCache[fieldName];
	}

	function choicesToOptionsText(choices) {
		if (!choices || typeof choices !== 'object') {
			return '';
		}
		return Object.keys(choices)
			.map(function (v) {
				return v + ' | ' + choices[v];
			})
			.join('\n');
	}

	// One tk/field-value block per layout sub-field — the canonical
	// sub-field input, so layout rows edit exactly like repeater rows.
	function fieldValueBlock(sub) {
		var attrs = {
			fieldName: sub.name || '',
			fieldType: sub.type || 'text',
		};
		if (
			(sub.type === 'select' || sub.type === 'radio' || sub.type === 'button_group') &&
			sub.choices
		) {
			attrs.options = choicesToOptionsText(sub.choices);
		}
		if (sub.type === 'taxonomy' && sub.taxonomy) {
			attrs.taxonomy = sub.taxonomy;
		}
		return blocks.createBlock('tk/field-value', attrs);
	}

	function Edit(props) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var clientId = props.clientId;

		var layouts = attributes.layouts || [];
		var fieldName = attributes.fieldName || '';
		var buttonLabel = attributes.buttonLabel || '';

		var blockProps = useBlockProps({ className: 'tk-flexible-content-editor' });

		var _useState = useState(false);
		var loading = _useState[0];
		var setLoading = _useState[1];
		var _useState2 = useState('');
		var loadError = _useState2[0];
		var setLoadError = _useState2[1];

		// Stable instance identity, set once from the clientId.
		useEffect(
			function () {
				if (!attributes.fieldKey && clientId) {
					setAttributes({
						fieldKey: 'flex-' + String(clientId).replace(/[^a-zA-Z0-9-]/g, '').slice(0, 24),
					});
				}
				// Intentionally run once on mount.
				// eslint-disable-next-line react-hooks/exhaustive-deps
			},
			[]
		);

		var childClientIds = useSelect(
			function (select) {
				return select('core/block-editor').getBlockOrder(clientId);
			},
			[clientId]
		);

		var _useDispatch = useDispatch('core/block-editor');
		var insertBlocks = _useDispatch.insertBlocks;
		var updateBlockAttributes = _useDispatch.updateBlockAttributes;

		// Adoption: keep every child layout owned by THIS instance. When a
		// layout is pasted in from another field (or moved across parents),
		// its fieldKey is rewritten so it can never belong to two fields.
		useEffect(
			function () {
				if (!attributes.fieldKey) {
					return;
				}
				var getBlock = data.select('core/block-editor').getBlock;
				(childClientIds || []).forEach(function (id) {
					var b = getBlock(id);
					if (b && b.attributes && b.attributes.fieldKey !== attributes.fieldKey) {
						updateBlockAttributes(id, { fieldKey: attributes.fieldKey });
					}
				});
			},
			[attributes.fieldKey, (childClientIds || []).join(',')] // eslint-disable-line react-hooks/exhaustive-deps
		);

		// Per-layout row counts (for min/max UI).
		var layoutCounts = useSelect(
			function (select) {
				var getBlock = select('core/block-editor').getBlock;
				var counts = {};
				(childClientIds || []).forEach(function (id) {
					var b = getBlock(id);
					var layoutKey = b && b.attributes && b.attributes.layout;
					if (layoutKey) {
						counts[layoutKey] = (counts[layoutKey] || 0) + 1;
					}
				});
				return counts;
			},
			[(childClientIds || []).join(',')] // eslint-disable-line react-hooks/exhaustive-deps
		);

		function loadLayouts() {
			if (!fieldName) {
				return;
			}
			setLoading(true);
			setLoadError('');
			fetchFieldDef(fieldName).then(function (def) {
				setLoading(false);
				if (!def) {
					setLoadError(__('No field with that name was found.', 'tk-fields'));
					return;
				}
				if (def.type !== 'flexible_content') {
					setLoadError(__('That field is not a Flexible Content field.', 'tk-fields'));
					return;
				}
				setAttributes({
					layouts: def.layouts || [],
					buttonLabel: buttonLabel || def.button_label || '',
				});
			});
		}

		function addLayout(layout) {
			var inner = (layout.fields || []).map(fieldValueBlock);
			var layoutBlock = blocks.createBlock(
				'tk/flexible-layout',
				{
					layout: layout.key,
					layoutId: 'layout-' + Math.random().toString(36).slice(2, 10),
					fieldKey: attributes.fieldKey || '',
				},
				inner
			);
			insertBlocks(layoutBlock, undefined, clientId);
		}

		function addButtonLabel(layout) {
			var label = buttonLabel || __('Add {layout}', 'tk-fields');
			return label.replace('{layout}', layout.label || layout.key);
		}

		var addButtons = layouts.map(function (layout) {
			var count = layoutCounts[layout.key] || 0;
			var maxed = layout.max !== null && layout.max !== undefined && count >= layout.max;
			return el(
				Tooltip,
				{
					key: layout.key,
					text: maxed
						? __('Maximum rows reached for this layout.', 'tk-fields')
						: __('Add a row using the "%s" layout.', 'tk-fields').replace('%s', layout.label || layout.key),
				},
				el(
					Button,
					{
						variant: 'secondary',
						disabled: maxed,
						onClick: function () {
							addLayout(layout);
						},
					},
					addButtonLabel(layout)
				)
			);
		});

		return el(
			element.Fragment,
			null,
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __('Field', 'tk-fields'), initialOpen: true },
					el(
						Tooltip,
						{ text: __('The flexible_content field name. Layouts are loaded from the field definition.', 'tk-fields') },
						el(TextControl, {
							label: __('Field name', 'tk-fields'),
							value: fieldName,
							onChange: function (next) {
								// Invalidate the embedded layouts when the
								// field changes; the author re-loads them.
								setAttributes({ fieldName: next, layouts: [] });
							},
						})
					),
					el(
						Button,
						{
							variant: 'secondary',
							isBusy: loading,
							disabled: loading || !fieldName,
							onClick: loadLayouts,
							style: { marginTop: '8px' },
						},
						__('Load layouts', 'tk-fields')
					),
					loadError
						? el(Notice, { status: 'warning', isDismissible: false }, loadError)
						: null
				)
			),
			el(
				'div',
				blockProps,
				el(
					Tooltip,
					{ text: __('Flexible content rows. Each row uses one of the field\u2019s layouts; add rows with the buttons below.', 'tk-fields') },
					el(
						'div',
						{ className: 'tk-flexible-content-editor__head' },
						el('strong', null, fieldName || __('Unnamed flexible field', 'tk-fields'))
					)
				),
				layouts.length === 0
					? el(
							Notice,
							{ status: 'info', isDismissible: false },
							__('Enter the field name in the sidebar and click “Load layouts”.', 'tk-fields')
						)
					: null,
				el(InnerBlocks, {
					allowedBlocks: ['tk/flexible-layout'],
					renderAppender: false,
					templateLock: false,
				}),
				layouts.length > 0
					? el('div', { className: 'tk-flexible-content-editor__add' }, addButtons)
					: null
			)
		);
	}

	blocks.registerBlockType('tk/flexible-content', {
		edit: Edit,
		save: function () {
			// Dynamic block: the frontend markup is produced by render.php.
			// InnerBlocks.Content is required so the layout rows serialize
			// into post_content (save() returning null would drop them).
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
