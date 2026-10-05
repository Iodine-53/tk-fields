/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * Editor UI for tk/field-repeater.
 *
 * Dedicated wrapper for a TK Fields *repeater* field (synthesis Q1, locked
 * unanimous). Composes the existing tk/repeater-row machinery — rows are
 * tk/repeater-row blocks with stable rowId attributes, never array indexes.
 *
 * Identity & ownership (the load-bearing invariant):
 * - fieldKey is the stable field identity. It is set ONLY by the
 *   field-group renderer (window.tkFieldRepeater.createFromFieldDef) or by
 *   the enclosing repeater for nested repeaters — there is NO editable
 *   fieldName UI in the inspector (Claude's attribute-drift trap: a
 *   hand-editable field name silently orphans stored rows).
 * - The block is hidden from the general inserter (supports.inserter=false
 *   in block.json). Inserted raw without a fieldKey it renders an inert
 *   placeholder instead of row UI — never a half-owned field.
 * - Nested repeaters (depth 2 max): the inner wrapper pushes its own
 *   context layer (scopeStack = parent stack + own field name) without
 *   clobbering the parent's — a path stack, not a flat key. The tree
 *   itself (wrapper > row[rowId] > wrapper > row[rowId]) is the path
 *   stack the Fields-service read/save path resolves against.
 * - Containment (Round 3(a), one layer up): rows cannot be dragged out
 *   into free post content — tk/repeater-row's block.json `parent`
 *   restricts it to tk/repeater / tk/field-repeater (core enforces this),
 *   the InnerBlocks here allows only tk/repeater-row, and there is no
 *   appender: rows are added only through the Add Row button.
 *   templateLock="all" is deliberately NOT used on the parent: it would
 *   freeze row reordering (value order is presentation) and lock the
 *   programmatic Add Row inserts (same adjudication as tk/flexible-content).
 * - Cross-post paste: rowIds are scoped to (post_id, field_name), never
 *   globally unique. On mount the wrapper compares its originPostId
 *   attribute against the current post; a mismatch regenerates every
 *   direct row's rowId from its (new) clientId. Duplicate rowIds within
 *   one wrapper (e.g. duplicated rows) are regenerated too. Nested
 *   wrappers handle their own rows via their own effect.
 *
 * Collapsed summaries are derived live from the `collapsed` sub-field's
 * value (first sub-field when unset) — derived presentation state only,
 * never persisted (ChatGPT's stale-summary trap).
 *
 * Read-path contract (for the Fields-service workstream): the wrapper is
 * located by its fieldName attribute (first top-level match wins, mirroring
 * Flexible_Content::rows_raw). Rows are its direct tk/repeater-row
 * children in document order (presentation order). Each row's identity is
 * its rowId attribute. Scalar sub-field values are tk/field-value children
 * keyed by SHORT sub-field name; a nested repeater sub-field is a
 * tk/field-repeater child of the row, resolved recursively. Nothing about
 * the path stack is flattened into a single context key.
 *
 * Classic script using wp.* globals only — no build step. Every control
 * gets a Tooltip.
 */
(function (blocks, blockEditor, components, element, data, i18n) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var InnerBlocks = blockEditor.InnerBlocks;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var Button = components.Button;
	var Notice = components.Notice;
	var Tooltip = components.Tooltip;
	var useEffect = element.useEffect;
	var useSelect = data.useSelect;
	var useDispatch = data.useDispatch;

	var BLOCK_NAME = 'tk/field-repeater';
	var ROW_NAME = 'tk/repeater-row';
	var FIELD_VALUE_NAME = 'tk/field-value';

	function cleanId(s) {
		return String(s || '').replace(/[^a-zA-Z0-9-]/g, '');
	}

	// Field keys keep underscores (field names use them); rowIds mirror
	// tk/repeater-row's clientId-derived formula exactly.
	function cleanKey(s) {
		return String(s || '').replace(/[^a-zA-Z0-9_-]/g, '');
	}

	function freshRowId(clientId) {
		var base = cleanId(clientId).slice(0, 24);
		if (base) {
			return 'row-' + base;
		}
		return 'row-' + Math.random().toString(36).slice(2, 10) + Date.now().toString(36).slice(-4);
	}

	function toIntOrNull(v) {
		if (v === null || v === undefined || v === '') {
			return null;
		}
		var n = parseInt(v, 10);
		return isNaN(n) ? null : n;
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

	/**
	 * One tk/field-value block per scalar sub-field — the canonical
	 * sub-field input, mirroring tk/flexible-content's fieldValueBlock().
	 */
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
		return blocks.createBlock(FIELD_VALUE_NAME, attrs);
	}

	/**
	 * Build the nested tk/field-repeater block for a repeater sub-field.
	 * The inner wrapper pushes its own context layer: its fieldKey and
	 * scopeStack extend the parent's (path stack, never a flat key).
	 */
	function nestedRepeaterBlock(sub, parentAttrs) {
		var parentKey = parentAttrs.fieldKey || '';
		var parentScope = parentAttrs.scopeStack && parentAttrs.scopeStack.length
			? parentAttrs.scopeStack
			: [parentAttrs.fieldName || ''];
		return blocks.createBlock(BLOCK_NAME, {
			fieldKey: parentKey + '__' + (sub.name || 'nested'),
			fieldName: sub.name || '',
			fieldLabel: sub.label || sub.name || '',
			layout: sub.layout || 'list',
			buttonLabel: sub.button_label || '',
			min: toIntOrNull(sub.min),
			max: toIntOrNull(sub.max),
			collapsed: sub.collapsed || '',
			subFields: sub.sub_fields || [],
			scopeStack: parentScope.concat([sub.name || '']),
			originPostId: parentAttrs.originPostId || parentAttrs.currentPostId || null,
		});
	}

	/**
	 * Depth-first search for the tk/field-value block carrying a sub-field
	 * name inside one row. Never descends into nested tk/field-repeater
	 * blocks — those are a different scope (path stack, not flat).
	 */
	function findFieldValue(blockList, name) {
		for (var i = 0; i < blockList.length; i++) {
			var b = blockList[i];
			if (!b) {
				continue;
			}
			if (b.name === BLOCK_NAME) {
				continue;
			}
			if (
				b.name === FIELD_VALUE_NAME &&
				b.attributes &&
				b.attributes.fieldName === name
			) {
				return b;
			}
			var kids = b.innerBlocks || [];
			if (kids.length) {
				var found = findFieldValue(kids, name);
				if (found) {
					return found;
				}
			}
		}
		return null;
	}

	function valueToText(value) {
		if (value === null || value === undefined || value === '') {
			return '';
		}
		var t;
		if (Array.isArray(value)) {
			t = value
				.map(function (v) {
					return v === null || v === undefined ? '' : String(v);
				})
				.filter(function (v) {
					return v !== '';
				})
				.join(', ');
		} else if (typeof value === 'object') {
			try {
				t = JSON.stringify(value);
			} catch (e) {
				t = '';
			}
		} else {
			t = String(value);
		}
		t = t.replace(/\s+/g, ' ').trim();
		return t.length > 80 ? t.slice(0, 77) + '…' : t;
	}

	function Edit(props) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var clientId = props.clientId;
		var context = props.context || {};

		var fieldKey = attributes.fieldKey || '';
		var fieldName = attributes.fieldName || '';
		var fieldLabel = attributes.fieldLabel || fieldName;
		var layout = attributes.layout || 'list';
		var buttonLabel = attributes.buttonLabel || '';
		var min = toIntOrNull(attributes.min);
		var max = toIntOrNull(attributes.max);
		var collapsed = attributes.collapsed || '';
		var subFields = attributes.subFields || [];
		var scopeStack = attributes.scopeStack || [];

		var blockProps = useBlockProps({
			className: 'tk-field-repeater-editor tk-field-repeater-editor--' + layout,
		});

		var currentPostId = useSelect(function (select) {
			var editor = select('core/editor');
			return editor ? editor.getCurrentPostId() : 0;
		}, []);

		var childClientIds = useSelect(
			function (select) {
				return select('core/block-editor').getBlockOrder(clientId);
			},
			[clientId]
		);

		var rowsInfo = useSelect(
			function (select) {
				var getBlock = select('core/block-editor').getBlock;
				var out = [];
				(childClientIds || []).forEach(function (id) {
					var b = getBlock(id);
					if (b) {
						out.push({
							clientId: id,
							name: b.name,
							rowId: (b.attributes && b.attributes.rowId) || '',
							block: b,
						});
					}
				});
				return out;
			},
			[(childClientIds || []).join(',')] // eslint-disable-line react-hooks/exhaustive-deps
		);

		var editorStore = useDispatch('core/block-editor');
		var insertBlocks = editorStore.insertBlocks;
		var updateBlockAttributes = editorStore.updateBlockAttributes;
		var selectBlock = editorStore.selectBlock;

		var rowCount = rowsInfo.filter(function (r) {
			return r.name === ROW_NAME;
		}).length;

		// ---- Nested-wrapper adoption: keep this instance owned by its
		// actual parent. When the wrapper is nested (an ancestor
		// tk/field-repeater provides context), its fieldKey and scopeStack
		// extend the parent's — a path stack, never a flat key. Pasted or
		// moved wrappers are re-owned so a row subtree can never resolve
		// into the wrong parent scope (the CQ3 write-path trap).
		var parentKey = context['tk/field-repeater-field-key'];
		var parentName = context['tk/field-repeater-field-name'];
		var parentScope = context['tk/field-repeater-scope'];
		useEffect(
			function () {
				if (parentKey && parentName && fieldName) {
					var wantKey = parentKey + '__' + fieldName;
					var wantScope = (parentScope || []).concat([fieldName]);
					if (
						attributes.fieldKey !== wantKey ||
						JSON.stringify(scopeStack) !== JSON.stringify(wantScope)
					) {
						setAttributes({ fieldKey: wantKey, scopeStack: wantScope });
					}
				} else if (fieldName && (scopeStack.length === 0 || scopeStack[scopeStack.length - 1] !== fieldName)) {
					// Top-level wrapper: the scope is the path to itself.
					setAttributes({ scopeStack: [fieldName] });
				}
				// Intentionally keyed on identity inputs only.
				// eslint-disable-next-line react-hooks/exhaustive-deps
			},
			[parentKey, parentName, JSON.stringify(parentScope || []), fieldName]
		);

		// ---- Cross-post paste: rowIds are scoped to (post_id, field_name),
		// never globally unique. If this wrapper arrives in a different
		// post than the one it was created in, regenerate every direct
		// row's rowId from its (new) clientId. Nested wrappers run their
		// own effect for their own rows.
		useEffect(
			function () {
				if (!currentPostId) {
					return;
				}
				if (!attributes.originPostId) {
					setAttributes({ originPostId: currentPostId });
					return;
				}
				if (attributes.originPostId !== currentPostId) {
					(childClientIds || []).forEach(function (id) {
						var b = data.select('core/block-editor').getBlock(id);
						if (b && b.name === ROW_NAME) {
							updateBlockAttributes(id, { rowId: freshRowId(id) });
						}
					});
					setAttributes({ originPostId: currentPostId });
				}
				// Runs when the edited post identity is (re)known.
				// eslint-disable-next-line react-hooks/exhaustive-deps
			},
			[currentPostId]
		);

		// ---- Same-post duplicate guard: two rows must never share a
		// rowId (duplicated rows keep the source's id). Regenerate the
		// later duplicates from their own clientIds.
		useEffect(
			function () {
				var seen = {};
				var dupes = [];
				rowsInfo.forEach(function (r) {
					if (r.name !== ROW_NAME) {
						return;
					}
					var id = r.rowId || '';
					if (!id || seen[id]) {
						dupes.push(r);
					} else {
						seen[id] = true;
					}
				});
				dupes.forEach(function (r) {
					updateBlockAttributes(r.clientId, { rowId: freshRowId(r.clientId) });
				});
				// eslint-disable-next-line react-hooks/exhaustive-deps
			},
			[
				rowsInfo
					.map(function (r) {
						return r.clientId + ':' + r.name + ':' + r.rowId;
					})
					.join(','),
			]
		);

		// Inert without a fieldKey: the block is inserted only by the
		// field-group renderer (inserter is disabled). Rendered raw, it
		// must not offer row UI that would orphan data.
		if (!fieldKey) {
			return el(
				'div',
				blockProps,
				el(
					Notice,
					{ status: 'warning', isDismissible: false },
					__(
						'Field Repeater: no field assigned. This block is inserted automatically for repeater fields.',
						'tk-fields'
					)
				)
			);
		}

		// Depth-2 cap: a repeater sub-field inside an already-nested
		// wrapper would be depth 3 — the group store hard-errors those at
		// save, but defend here too (never silently build them).
		var nestedDepth = scopeStack.length;
		var overDeep = subFields.filter(function (s) {
			return s && s.type === 'repeater' && nestedDepth >= 2;
		});

		function addRow() {
			var inner = [];
			subFields.forEach(function (sub) {
				if (!sub || !sub.name) {
					return;
				}
				if (sub.type === 'repeater') {
					if (nestedDepth >= 2) {
						return;
					}
					inner.push(
						nestedRepeaterBlock(sub, {
							fieldKey: fieldKey,
							fieldName: fieldName,
							scopeStack: scopeStack,
							originPostId: attributes.originPostId,
							currentPostId: currentPostId,
						})
					);
				} else {
					inner.push(fieldValueBlock(sub));
				}
			});
			var row = blocks.createBlock(ROW_NAME, {}, inner);
			// Persist the stable rowId now (the row's own mount effect
			// would derive the same value from the clientId).
			row.attributes.rowId = freshRowId(row.clientId);
			insertBlocks(row, undefined, clientId);
		}

		var atMax = max !== null && rowCount >= max;
		var belowMin = min !== null && rowCount < min;

		// Collapsed summaries: derived live from the `collapsed`
		// sub-field's value (first sub-field when unset). Presentation
		// state only — never written to attributes.
		var summaryName = collapsed || ((subFields[0] && subFields[0].name) || '');
		var summaries = rowsInfo
			.filter(function (r) {
				return r.name === ROW_NAME;
			})
			.map(function (r, i) {
				var text = '';
				if (summaryName) {
					var fv = findFieldValue(r.block.innerBlocks || [], summaryName);
					text = fv ? valueToText(fv.attributes && fv.attributes.value) : '';
				}
				return { index: i, clientId: r.clientId, rowId: r.rowId, text: text };
			});

		var summaryStrip =
			summaries.length > 0
				? el(
						'div',
						{ className: 'tk-field-repeater-editor__summaries' },
						el(
							'span',
							{ className: 'tk-field-repeater-editor__summaries-label' },
							__('Rows', 'tk-fields') + ' (' + summaries.length + ')'
						),
						el(
							'ol',
							{ className: 'tk-field-repeater-editor__summaries-list' },
							summaries.map(function (s) {
								return el(
									'li',
									{ key: s.clientId },
									el(
										Tooltip,
										{
											text: __('Select this row.', 'tk-fields'),
										},
										el(
											'button',
											{
												type: 'button',
												className: 'tk-field-repeater-editor__summary',
												onClick: function () {
													selectBlock(s.clientId);
												},
											},
											el(
												'span',
												{ className: 'tk-field-repeater-editor__summary-index' },
												String(s.index + 1) + '.'
											),
											' ',
											s.text ||
												__('(empty)', 'tk-fields')
										)
									)
								);
							})
						)
					)
				: null;

		return el(
			element.Fragment,
			null,
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __('Repeater Field', 'tk-fields'), initialOpen: true },
					el(
						Tooltip,
						{
							text: __(
								'The repeater field this block was inserted for. Set by the field-group renderer — not editable, so rows can never be orphaned by a rename.',
								'tk-fields'
							),
						},
						el(
							'div',
							{ className: 'tk-field-repeater-editor__field-id' },
							el('strong', null, fieldLabel || fieldName),
							el('code', null, fieldName)
						)
					),
					el(
						Tooltip,
						{
							text: __(
								'How rows are arranged: stacked list or responsive grid. Presentation only.',
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
					),
					el(
						'div',
						{ className: 'tk-field-repeater-editor__counts' },
						__('Rows:', 'tk-fields') +
							' ' +
							rowCount +
							(min !== null ? ' · ' + __('min', 'tk-fields') + ' ' + min : '') +
							(max !== null ? ' · ' + __('max', 'tk-fields') + ' ' + max : '')
					)
				)
			),
			el(
				'div',
				blockProps,
				el(
					Tooltip,
					{
						text: __(
							'Repeatable rows for this field. Rows keep a stable ID and are never addressed by position; drag to reorder.',
							'tk-fields'
						),
					},
					el(
						'div',
						{ className: 'tk-field-repeater-editor__head' },
						el('strong', null, fieldLabel || __('Repeater', 'tk-fields')),
						fieldName
							? el('code', { className: 'tk-field-repeater-editor__head-name' }, fieldName)
							: null
					)
				),
				overDeep.length > 0
					? el(
							Notice,
							{ status: 'warning', isDismissible: false },
							__(
								'Sub-field(s) exceeding the depth-2 nesting cap were not added: ',
								'tk-fields'
							) + overDeep.map(function (s) { return s.name; }).join(', ')
						)
					: null,
				summaryStrip,
				el(InnerBlocks, {
					allowedBlocks: [ROW_NAME],
					renderAppender: false,
					templateLock: false,
				}),
				belowMin
					? el(
							Notice,
							{ status: 'warning', isDismissible: false },
							__('This field needs at least {min} row(s).', 'tk-fields').replace('{min}', String(min))
						)
					: null,
				el(
					'div',
					{ className: 'tk-field-repeater-editor__add' },
					el(
						Tooltip,
						{
							text: atMax
								? __('Maximum rows reached.', 'tk-fields')
								: __('Add a row to this repeater field.', 'tk-fields'),
						},
						el(
							Button,
							{
								variant: 'secondary',
								disabled: atMax,
								onClick: addRow,
							},
							buttonLabel || __('Add Row', 'tk-fields')
						)
					)
				)
			)
		);
	}

	function Save() {
		// Dynamic block: the frontend markup is produced by render.php.
		// InnerBlocks.Content is required so rows serialize into
		// post_content (save() returning null would drop them).
		return el(InnerBlocks.Content, null);
	}

	blocks.registerBlockType(BLOCK_NAME, {
		edit: Edit,
		save: Save,
	});

	/**
	 * Field-group renderer entry point.
	 *
	 * Builds a tk/field-repeater block instance from a repeater field
	 * definition (shape: {name, label, sub_fields, min, max, button_label,
	 * layout, collapsed}). The renderer — not the author — owns the
	 * fieldKey, so attribute drift can never orphan stored rows.
	 *
	 * @param {Object} def  Repeater field definition.
	 * @param {Object} opts Optional: {postId}.
	 * @return {Object} Block instance ready for insertBlocks().
	 */
	window.tkFieldRepeater = {
		BLOCK_NAME: BLOCK_NAME,
		ROW_NAME: ROW_NAME,
		createFromFieldDef: function (def, opts) {
			def = def || {};
			opts = opts || {};
			var name = def.name || '';
			return blocks.createBlock(BLOCK_NAME, {
				fieldKey: 'fr-' + cleanKey(name).slice(0, 48),
				fieldName: name,
				fieldLabel: def.label || name,
				layout: def.layout || 'list',
				buttonLabel: def.button_label || '',
				min: toIntOrNull(def.min),
				max: toIntOrNull(def.max),
				collapsed: def.collapsed || '',
				subFields: def.sub_fields || [],
				scopeStack: name ? [name] : [],
				originPostId: opts.postId || null,
			});
		},
	};
})(
	window.wp.blocks,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.element,
	window.wp.data,
	window.wp.i18n
);
