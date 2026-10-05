/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * Editor UI for tk/group.
 *
 * Display block: the inspector names the group field; the canvas shows the
 * server render (render.php). Classic script using wp.* globals only —
 * no build step. Every control gets a Tooltip.
 */
(function (blocks, blockEditor, components, element, i18n, apiFetch) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var SelectControl = components.SelectControl;
	var Button = components.Button;
	var Spinner = components.Spinner;
	var Tooltip = components.Tooltip;
	var useBlockProps = blockEditor.useBlockProps;
	var useState = element.useState;
	var useEffect = element.useEffect;

	// v0.12.0: field-group → group-field cascade picker (shared pattern
	// with tk/field-value/edit.js; classic scripts can't share modules).
	var fieldGroupsCache = { state: 'idle', groups: [], waiters: [] };

	function loadFieldGroups() {
		if (fieldGroupsCache.state !== 'idle' || typeof apiFetch !== 'function') {
			return;
		}
		fieldGroupsCache.state = 'loading';
		var wake = function () {
			fieldGroupsCache.waiters.forEach(function (w) {
				w(function (n) {
					return n + 1;
				});
			});
			fieldGroupsCache.waiters = [];
		};
		apiFetch({ path: '/tk/v1/groups' }).then(
			function (res) {
				var list = (res && res.groups) || [];
				var fetches = list.map(function (g) {
					return apiFetch({ path: '/tk/v1/groups/' + g.id }).then(
						function (full) {
							return (full && full.group) || null;
						},
						function () {
							return null;
						}
					);
				});
				Promise.all(fetches).then(function (groups) {
					fieldGroupsCache.groups = groups.filter(Boolean);
					fieldGroupsCache.state = 'ready';
					wake();
				});
			},
			function () {
				fieldGroupsCache.state = 'error';
				wake();
			}
		);
	}

	function useFieldGroups() {
		var tickState = useState(0);
		var tick = tickState[1];
		useEffect(function () {
			if ('ready' === fieldGroupsCache.state || 'error' === fieldGroupsCache.state) {
				return undefined;
			}
			fieldGroupsCache.waiters.push(tick);
			loadFieldGroups();
			return function () {
				fieldGroupsCache.waiters = fieldGroupsCache.waiters.filter(function (w) {
					return w !== tick;
				});
			};
		}, []);
		return fieldGroupsCache;
	}

	/**
	 * The inspector's group-field picker: group → group-field cascade
	 * (only group-type fields; this block displays a fieldset) + a
	 * manual-entry fallback for edge cases.
	 */
	function GroupFieldPicker(props) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var cache = useFieldGroups();
		var manualState = useState(false);
		var manual = manualState[0];
		var setManual = manualState[1];
		var groupState = useState('');
		var groupId = groupState[0];
		var setGroupId = groupState[1];

		var groups = cache.groups;
		var ready = 'ready' === cache.state;

		useEffect(
			function () {
				if (!ready || groupId || !attributes.fieldName) {
					return;
				}
				var current = attributes.fieldName;
				for (var i = 0; i < groups.length; i++) {
					var fields = groups[i].fields || [];
					for (var j = 0; j < fields.length; j++) {
						if ('group' === fields[j].type && fields[j].name === current) {
							setGroupId(String(groups[i].id));
							return;
						}
					}
				}
			},
			[ready, attributes.fieldName]
		);

		if (manual || 'error' === cache.state) {
			return withTooltip(
				__('Group field name', 'tk-fields'),
				__(
					'Machine name of the group field to display (e.g. contact_details). The fieldset legend and sub-field values come from the Fields service.',
					'tk-fields'
				),
				el(
					'div',
					null,
					el(TextControl, {
						value: attributes.fieldName || '',
						placeholder: 'contact_details',
						onChange: function (next) {
							setAttributes({ fieldName: next });
						},
					}),
					el(
						Button,
						{ variant: 'link', onClick: function () { setManual(false); } },
						__('Pick from a field group', 'tk-fields')
					)
				)
			);
		}

		var groupOptions = [{ label: __('— Select —', 'tk-fields'), value: '' }].concat(
			groups.map(function (g) {
				return { label: g.title, value: String(g.id) };
			})
		);
		var group = groups.filter(function (g) {
			return String(g.id) === String(groupId);
		})[0];
		var fieldOptions = [{ label: __('— Select —', 'tk-fields'), value: '' }];
		if (group) {
			(group.fields || []).forEach(function (f) {
				if ('group' === f.type) {
					fieldOptions.push({
						label: (f.label || f.name) + ' (' + f.name + ')',
						value: f.name,
					});
				}
			});
		}

		return el(
			'div',
			null,
			withTooltip(
				__('Field group', 'tk-fields'),
				__('Pick the field group, then the group field to display as a fieldset.', 'tk-fields'),
				el(SelectControl, {
					value: groupId,
					options: groupOptions,
					onChange: function (next) {
						setGroupId(next);
					},
				})
			),
			group
				? withTooltip(
						__('Group field', 'tk-fields'),
						__('Only group-type fields are listed — this block renders a fieldset.', 'tk-fields'),
						el(SelectControl, {
							value: attributes.fieldName || '',
							options: fieldOptions,
							onChange: function (next) {
								setAttributes({ fieldName: next });
							},
						})
					)
				: null,
			!ready ? el(Spinner) : null,
			el(
				Button,
				{ variant: 'link', onClick: function () { setManual(true); } },
				__('Enter field name manually', 'tk-fields')
			)
		);
	}

	function withTooltip(label, tip, control) {
		return el(
			Tooltip,
			{ text: tip },
			el(
				'div',
				null,
				el('span', { className: 'tkf-group__tip-label' }, label),
				control
			)
		);
	}

	blocks.registerBlockType('tk/group', {
		edit: function (props) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps();

			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __('Field Group', 'tk-fields'), initialOpen: true },
						el(GroupFieldPicker, { attributes: attributes, setAttributes: setAttributes })
					)
				),
				attributes.fieldName
					? null
					: el(
							'p',
							{ className: 'tkf-group__placeholder' },
							__(
								'Field Group: pick a group field name in the block settings.',
								'tk-fields'
							)
						)
			);
		},

		// Server-rendered (render.php).
		save: function () {
			return null;
		},
	});
})(
	window.wp.blocks,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.element,
	window.wp.i18n,
	window.wp.apiFetch
);
