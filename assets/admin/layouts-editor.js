/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * TK Fields — flexible_content layouts editor (classic script).
 *
 * Defines window.TKFLayoutsEditor, a wp.element component consumed by the
 * "layouts" control in the builder bundle (assets/admin/build/index.js).
 * Props: { value: layouts[], onChange: fn }.
 *
 * v0.12.0 rebuild: every layout is a window.TKFFieldRow card at depth 1 —
 * literally the same recursive row component the React builder uses —
 * with a layout thumbnail, min/max badges, and its sub-fields as depth-2
 * rows beneath. Nested validation errors render beneath the offending
 * input only once it is touched, never on pristine rows.
 *
 * The layouts value shape matches what Group_Store::sanitize_layouts()
 * expects: [{key, label, min, max, fields:[{name,label,type,required,
 * choices, instructions}]}]. Choice-based sub-fields edit their vocabulary
 * as "value | Label" lines; per-layout min/max are plain numbers (empty =
 * no limit). v1 limits are enforced by the shape: no nested
 * flexible_content/clone types in the type picker, no layout-only types.
 *
 * NOTE: window.TKFFieldRow is defined by the bundle, which loads AFTER
 * this classic script (it is a bundle dependency). It is read lazily at
 * render time, never at module top level.
 *
 * @package TK\Fields
 */
(function (element, components, i18n) {
	'use strict';

	if (!element || !components || !i18n) {
		return;
	}

	var el = element.createElement;
	var useState = element.useState;
	var __ = i18n.__;
	var sprintf = i18n.sprintf;

	var TextControl = components.TextControl;
	var TextareaControl = components.TextareaControl;
	var SelectControl = components.SelectControl;
	var ToggleControl = components.ToggleControl;
	var Button = components.Button;
	var Notice = components.Notice;
	var Dashicon = components.Dashicon;
	var Tooltip = components.Tooltip;

	// Sub-field types allowed inside a layout in v1. Nested
	// flexible_content / clone are rejected by the group store, and
	// layout-only types (message/separator/tab) render no value — so they
	// are not offered here.
	var SUB_FIELD_TYPES = [
		'text',
		'textarea',
		'number',
		'range',
		'email',
		'url',
		'checkbox',
		'select',
		'radio',
		'button_group',
		'color',
		'date',
		'datetime',
		'time',
		'image',
		'file',
		'gallery',
		'post_object',
		'page_link',
		'taxonomy',
		'user',
		'relationship',
		'link',
		'oembed',
		'icon',
		'wysiwyg',
		'map',
	];

	var CHOICE_TYPES = ['select', 'radio', 'button_group'];

	// Category map mirrors the field_types endpoint (used for the badge
	// color on each row).
	var TYPE_CATEGORIES = {
		text: 'basic',
		textarea: 'basic',
		number: 'basic',
		range: 'basic',
		email: 'basic',
		url: 'basic',
		checkbox: 'basic',
		date: 'basic',
		datetime: 'basic',
		time: 'basic',
		wysiwyg: 'content',
		image: 'content',
		file: 'content',
		gallery: 'content',
		oembed: 'content',
		link: 'content',
		icon: 'content',
		map: 'content',
		color: 'content',
		select: 'choice',
		radio: 'choice',
		button_group: 'choice',
		post_object: 'relational',
		page_link: 'relational',
		taxonomy: 'relational',
		user: 'relational',
		relationship: 'relational',
	};

	function prettyLabel(type) {
		var t = String(type || '');
		return t.charAt(0).toUpperCase() + t.slice(1).replace(/_/g, ' ');
	}

	function choicesToText(choices) {
		if (!choices || typeof choices !== 'object') {
			return '';
		}
		return Object.keys(choices)
			.map(function (v) {
				return v + ' | ' + choices[v];
			})
			.join('\n');
	}

	function textToChoices(text) {
		var out = {};
		String(text || '')
			.split('\n')
			.forEach(function (line) {
				var parts = line.split('|');
				var value = (parts[0] || '').trim();
				if (!value) {
					return;
				}
				out[value] = parts.length > 1 ? parts.slice(1).join('|').trim() : value;
			});
		return out;
	}

	function numOrNull(v) {
		if (v === '' || v === null || v === undefined) {
			return null;
		}
		var n = Number(v);
		return isNaN(n) ? null : n;
	}

	function validateSub(sub, siblings) {
		var errors = {};
		if (!(sub.label || '').trim()) {
			errors.label = __('Label is required.', 'tk-fields');
		}
		var name = sub.name || '';
		if (!name) {
			errors.name = __('Field name is required.', 'tk-fields');
		} else if (!/^[a-z0-9_]+$/.test(name)) {
			errors.name = __(
				'Use only lowercase letters, numbers and underscores.',
				'tk-fields'
			);
		} else if (
			siblings.some(function (s) {
				return s !== sub && s.name === name;
			})
		) {
			errors.name = __('This name is already used by another sub-field.', 'tk-fields');
		}
		return errors;
	}

	function validateLayout(layout, siblings) {
		var errors = {};
		if (!(layout.label || '').trim()) {
			errors.label = __('Layout label is required.', 'tk-fields');
		}
		var key = layout.key || '';
		if (!key) {
			errors.key = __('Layout key is required.', 'tk-fields');
		} else if (!/^[a-z0-9_]+$/.test(key)) {
			errors.key = __(
				'Use only lowercase letters, numbers and underscores.',
				'tk-fields'
			);
		} else if (
			siblings.some(function (s) {
				return s !== layout && s.key === key;
			})
		) {
			errors.key = __('This key is already used by another layout.', 'tk-fields');
		}
		return errors;
	}

	// Minimal TKControl equivalent: label row (+ tooltip), control, and
	// error/help text below — same .tkf-control classes as the bundle.
	function LabeledControl(props) {
		return el(
			'div',
			{
				className:
					'tkf-control' + (props.error ? ' tkf-control--error' : ''),
			},
			el(
				'div',
				{ className: 'tkf-control__label-row' },
				el(
					'label',
					{ className: 'tkf-control__label' },
					props.label,
					props.required
						? el(
								'span',
								{
									className: 'tkf-control__required',
									'aria-hidden': 'true',
								},
								' *'
						  )
						: null
				),
				props.tooltip
					? el(
							Tooltip,
							{ text: props.tooltip, position: 'top center' },
							el(
								'button',
								{
									type: 'button',
									className: 'tkf-control__help-btn',
									'aria-label': sprintf(
										__('Help for: %s', 'tk-fields'),
										props.label
									),
								},
								el(Dashicon, { icon: 'editor-help' })
							)
					  )
					: null
			),
			el('div', { className: 'tkf-control__field' }, props.children),
			props.error
				? el(
						'p',
						{ className: 'tkf-control__error', role: 'alert' },
						props.error
				  )
				: null,
			props.help && !props.error
				? el('p', { className: 'tkf-control__help-text' }, props.help)
				: null
		);
	}

	function subFieldBody(sub, set, errors, markTouched) {
		return el(
			'div',
			{ className: 'tkf-field-settings' },
			el(
				'div',
				{ className: 'tkf-field-settings__grid' },
				el(
					LabeledControl,
					{
						label: __('Label', 'tk-fields'),
						required: true,
						error: errors.label,
					},
					el(TextControl, {
						value: sub.label || '',
						onChange: function (next) {
							set({ label: next });
						},
						onBlur: markTouched,
					})
				),
				el(
					LabeledControl,
					{
						label: __('Name', 'tk-fields'),
						required: true,
						error: errors.name,
						help: __(
							'Lowercase letters, digits, underscores.',
							'tk-fields'
						),
					},
					el(TextControl, {
						value: sub.name || '',
						className: 'code',
						onChange: function (next) {
							set({ name: next });
						},
						onBlur: markTouched,
					})
				),
				el(
					LabeledControl,
					{ label: __('Type', 'tk-fields') },
					el(SelectControl, {
						value: sub.type || 'text',
						options: SUB_FIELD_TYPES.map(function (t) {
							return { value: t, label: prettyLabel(t) };
						}),
						onChange: function (next) {
							set({ type: next });
						},
					})
				),
				el(
					LabeledControl,
					{ label: __('Required', 'tk-fields') },
					el(ToggleControl, {
						checked: !!sub.required,
						onChange: function (next) {
							set({ required: next });
						},
					})
				)
			),
			CHOICE_TYPES.indexOf(sub.type) !== -1
				? el(
						LabeledControl,
						{
							label: __('Choices', 'tk-fields'),
							help: __('One per line: value | Label', 'tk-fields'),
						},
						el(TextareaControl, {
							value: choicesToText(sub.choices),
							rows: 3,
							onChange: function (next) {
								set({ choices: textToChoices(next) });
							},
						})
				  )
				: null,
			el(
				LabeledControl,
				{ label: __('Instructions', 'tk-fields') },
				el(TextControl, {
					value: sub.instructions || '',
					onChange: function (next) {
						set({ instructions: next });
					},
				})
			)
		);
	}

	function SubFieldRow(props) {
		var sub = props.sub;
		var siblings = props.siblings;
		var index = props.index;
		var total = props.total;
		var onChange = props.onChange;
		var onRemove = props.onRemove;
		var onMove = props.onMove;

		var expandedState = useState(false);
		var expanded = expandedState[0];
		var setExpanded = expandedState[1];
		var touchedState = useState(false);
		var touched = touchedState[0];
		var setTouched = touchedState[1];

		var FieldRow = window.TKFFieldRow;

		function set(patch) {
			onChange(Object.assign({}, sub, patch));
		}

		var errors = touched ? validateSub(sub, siblings) : {};

		var body = subFieldBody(sub, set, errors, function () {
			setTouched(true);
		});

		if (!FieldRow) {
			return el('div', { className: 'tkf-field-row--fallback' }, body);
		}

		return el(
			FieldRow,
			{
				field: {
					key: sub.key || 'sub-' + index,
					label: sub.label,
					name: sub.name,
					type: sub.type || 'text',
				},
				typeLabel: prettyLabel(sub.type || 'text'),
				category: TYPE_CATEGORIES[sub.type] || 'basic',
				depth: 2,
				expanded: expanded,
				onToggleExpand: function () {
					setExpanded(!expanded);
				},
				issues: Object.keys(errors).length,
				index: index,
				total: total,
				onMoveUp: function () {
					onMove(index, index - 1);
				},
				onMoveDown: function () {
					onMove(index, index + 1);
				},
				onDelete: onRemove,
			},
			body
		);
	}

	function LayoutCard(props) {
		var layout = props.layout;
		var siblings = props.siblings;
		var index = props.index;
		var total = props.total;
		var onChange = props.onChange;
		var onRemove = props.onRemove;
		var onMove = props.onMove;

		var expandedState = useState(true);
		var expanded = expandedState[0];
		var setExpanded = expandedState[1];
		var touchedState = useState(false);
		var touched = touchedState[0];
		var setTouched = touchedState[1];

		var FieldRow = window.TKFFieldRow;

		function set(patch) {
			onChange(Object.assign({}, layout, patch));
		}

		var layoutErrors = touched ? validateLayout(layout, siblings) : {};
		var fields = layout.fields || [];

		function setSub(i, next) {
			var copy = fields.slice();
			copy[i] = next;
			set({ fields: copy });
		}

		function moveSub(from, to) {
			if (to < 0 || to >= fields.length || from === to) {
				return;
			}
			var copy = fields.slice();
			var moved = copy.splice(from, 1)[0];
			copy.splice(to, 0, moved);
			set({ fields: copy });
		}

		function addSub() {
			set({
				fields: fields.concat([
					{ name: '', label: '', type: 'text', required: false },
				]),
			});
		}

		function removeSub(i) {
			var copy = fields.slice();
			copy.splice(i, 1);
			set({ fields: copy });
		}

		var badges = [];
		if (layout.min !== null && layout.min !== undefined && layout.min !== '') {
			badges.push(sprintf(__('min %d', 'tk-fields'), layout.min));
		}
		if (layout.max !== null && layout.max !== undefined && layout.max !== '') {
			badges.push(sprintf(__('max %d', 'tk-fields'), layout.max));
		}

		var body = el(
			'div',
			{ className: 'tkf-layout-card__body' },
			el(
				'div',
				{ className: 'tkf-field-settings__grid tkf-layout-card__row' },
				el(
					LabeledControl,
					{
						label: __('Layout key', 'tk-fields'),
						required: true,
						error: layoutErrors.key,
						help: __(
							'Lowercase letters, digits, underscores. Stable: renaming it orphans existing rows.',
							'tk-fields'
						),
					},
					el(TextControl, {
						value: layout.key || '',
						className: 'code',
						onChange: function (next) {
							set({ key: next });
						},
						onBlur: function () {
							setTouched(true);
						},
					})
				),
				el(
					LabeledControl,
					{
						label: __('Layout label', 'tk-fields'),
						required: true,
						error: layoutErrors.label,
					},
					el(TextControl, {
						value: layout.label || '',
						onChange: function (next) {
							set({ label: next });
						},
						onBlur: function () {
							setTouched(true);
						},
					})
				),
				el(
					LabeledControl,
					{ label: __('Min rows', 'tk-fields') },
					el(TextControl, {
						type: 'number',
						min: 0,
						value:
							layout.min === null || layout.min === undefined
								? ''
								: String(layout.min),
						onChange: function (next) {
							set({ min: numOrNull(next) });
						},
					})
				),
				el(
					LabeledControl,
					{ label: __('Max rows', 'tk-fields') },
					el(TextControl, {
						type: 'number',
						min: 0,
						value:
							layout.max === null || layout.max === undefined
								? ''
								: String(layout.max),
						onChange: function (next) {
							set({ max: numOrNull(next) });
						},
					})
				)
			),
			el('h4', { className: 'tkf-layout-card__subhead' }, __('Sub-fields', 'tk-fields')),
			fields.map(function (sub, i) {
				return el(SubFieldRow, {
					key: i,
					sub: sub,
					siblings: fields,
					index: i,
					total: fields.length,
					onChange: function (next) {
						setSub(i, next);
					},
					onRemove: function () {
						removeSub(i);
					},
					onMove: moveSub,
				});
			}),
			el(
				Button,
				{
					variant: 'secondary',
					onClick: addSub,
					className: 'tkf-layout-card__add-sub',
				},
				__('Add sub-field', 'tk-fields')
			)
		);

		if (!FieldRow) {
			return el('div', { className: 'tkf-layout-card' }, body);
		}

		return el(
			'div',
			{ className: 'tkf-layout-card' },
			el(
				FieldRow,
				{
					field: {
						key: layout.key || 'layout-' + index,
						label: layout.label,
						name: layout.key,
						type: 'layout',
					},
					typeLabel: __('Layout', 'tk-fields'),
					category: 'layout',
					icon: 'layout',
					badges: badges,
					depth: 1,
					expanded: expanded,
					onToggleExpand: function () {
						setExpanded(!expanded);
					},
					issues: Object.keys(layoutErrors).length,
					index: index,
					total: total,
					onMoveUp: function () {
						onMove(index, index - 1);
					},
					onMoveDown: function () {
						onMove(index, index + 1);
					},
					onDelete: onRemove,
				},
				body
			)
		);
	}

	function TKFLayoutsEditor(props) {
		var layouts = Array.isArray(props.value) ? props.value : [];
		var onChange = props.onChange;

		function setLayout(index, next) {
			var copy = layouts.slice();
			copy[index] = next;
			onChange(copy);
		}

		function moveLayout(from, to) {
			if (to < 0 || to >= layouts.length || from === to) {
				return;
			}
			var copy = layouts.slice();
			var moved = copy.splice(from, 1)[0];
			copy.splice(to, 0, moved);
			onChange(copy);
		}

		function addLayout() {
			onChange(
				layouts.concat([
					{
						key: '',
						label: '',
						min: null,
						max: null,
						fields: [{ name: '', label: '', type: 'text', required: false }],
					},
				])
			);
		}

		function removeLayout(index) {
			var copy = layouts.slice();
			copy.splice(index, 1);
			onChange(copy);
		}

		return el(
			'div',
			{ className: 'tkf-layouts-editor' },
			layouts.length === 0
				? el(
						Notice,
						{ status: 'info', isDismissible: false },
						__(
							'No layouts yet. Add at least one layout — each layout is a named set of sub-fields editors can insert as a row.',
							'tk-fields'
						)
				  )
				: null,
			layouts.map(function (layout, i) {
				return el(LayoutCard, {
					key: i,
					layout: layout,
					siblings: layouts,
					index: i,
					total: layouts.length,
					onChange: function (next) {
						setLayout(i, next);
					},
					onRemove: function () {
						removeLayout(i);
					},
					onMove: moveLayout,
				});
			}),
			el(
				Button,
				{
					variant: 'primary',
					onClick: addLayout,
					className: 'tkf-layouts-editor__add',
				},
				__('Add layout', 'tk-fields')
			)
		);
	}

	window.TKFLayoutsEditor = TKFLayoutsEditor;
})(window.wp.element, window.wp.components, window.wp.i18n);
