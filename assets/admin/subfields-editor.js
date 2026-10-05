/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * TK Fields — group sub-fields editor (classic script).
 *
 * Defines window.TKFSubFieldsEditor, a wp.element component consumed by the
 * "subfields" control in the builder bundle (assets/admin/build/index.js).
 * Props: { value: subFields[], onChange: fn }.
 *
 * v0.12.0 rebuild: every sub-field row is window.TKFFieldRow — literally
 * the same recursive row component the React builder uses for top-level
 * fields — rendered at depth 1. Nested validation errors render beneath
 * the sub-field's own name input only once that sub-field is touched
 * (blur on name/label), never on pristine rows.
 *
 * The sub-fields value shape matches what
 * Group_Store::sanitize_group_sub_fields() expects:
 * [{name, label, type, required, choices, instructions}].
 * Choice-based sub-fields edit their vocabulary as "value | Label" lines.
 * v1 limits are enforced by the shape: no nested group/clone/
 * flexible_content types in the type picker, no layout-only types.
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

	// Sub-field types allowed inside a group in v1. Nested group / clone /
	// flexible_content are rejected by the group store, and layout-only
	// types (message/separator/tab) carry no value — so they are not
	// offered here.
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
		var issueCount = Object.keys(errors).length;

		var body = el(
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
						onBlur: function () {
							setTouched(true);
						},
					})
				),
				el(
					LabeledControl,
					{
						label: __('Name', 'tk-fields'),
						required: true,
						error: errors.name,
						help: __(
							'Lowercase letters, digits, underscores. Stored as groupname_name.',
							'tk-fields'
						),
					},
					el(TextControl, {
						value: sub.name || '',
						className: 'code',
						onChange: function (next) {
							set({ name: next });
						},
						onBlur: function () {
							setTouched(true);
						},
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

		if (!FieldRow) {
			// Bundle failed to load — degrade to the bare settings body.
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
				depth: 1,
				expanded: expanded,
				onToggleExpand: function () {
					setExpanded(!expanded);
				},
				issues: issueCount,
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

	function TKFSubFieldsEditor(props) {
		var subFields = Array.isArray(props.value) ? props.value : [];
		var onChange = props.onChange;

		function setSub(index, next) {
			var copy = subFields.slice();
			copy[index] = next;
			onChange(copy);
		}

		function moveSub(from, to) {
			if (to < 0 || to >= subFields.length || from === to) {
				return;
			}
			var copy = subFields.slice();
			var moved = copy.splice(from, 1)[0];
			copy.splice(to, 0, moved);
			onChange(copy);
		}

		function addSub() {
			onChange(
				subFields.concat([
					{ name: '', label: '', type: 'text', required: false },
				])
			);
		}

		function removeSub(index) {
			var copy = subFields.slice();
			copy.splice(index, 1);
			onChange(copy);
		}

		return el(
			'div',
			{ className: 'tkf-subfields-editor' },
			subFields.length === 0
				? el(
						Notice,
						{ status: 'info', isDismissible: false },
						__(
							'No sub-fields yet. Add at least one — each sub-field is stored under groupname_subname and validated with its own rules.',
							'tk-fields'
						)
				  )
				: null,
			subFields.map(function (sub, i) {
				return el(SubFieldRow, {
					key: i,
					sub: sub,
					siblings: subFields,
					index: i,
					total: subFields.length,
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
					variant: 'primary',
					onClick: addSub,
					className: 'tkf-layouts-editor__add',
				},
				__('Add sub-field', 'tk-fields')
			)
		);
	}

	window.TKFSubFieldsEditor = TKFSubFieldsEditor;
})(window.wp.element, window.wp.components, window.wp.i18n);
