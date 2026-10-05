/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * Editor UI for tk/field-value.
 *
 * One editable sub-field inside a repeater row. The inspector defines the
 * field (name, type, select options); the main area shows the value control
 * appropriate to the type. Every control gets a Tooltip.
 */
(function (blocks, blockEditor, components, element, data, i18n, apiFetch) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var registerBlockType = blocks.registerBlockType;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var MediaUpload = blockEditor.MediaUpload;
	var RichText = blockEditor.RichText;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var TextareaControl = components.TextareaControl;
	var SelectControl = components.SelectControl;
	var CheckboxControl = components.CheckboxControl;
	var RangeControl = components.RangeControl;
	var Button = components.Button;
	var RadioControl = components.RadioControl;
	var Spinner = components.Spinner;
	var Notice = components.Notice;
	var useSelect = data.useSelect;
	var Tooltip = components.Tooltip;
	var useState = element.useState;
	var useEffect = element.useEffect;
	var useRef = element.useRef;

	var FIELD_TYPES = [
		{ label: 'Text', value: 'text' },
		{ label: 'Textarea', value: 'textarea' },
		{ label: 'Number', value: 'number' },
		{ label: 'Email', value: 'email' },
		{ label: 'URL', value: 'url' },
		{ label: 'Date', value: 'date' },
		{ label: 'Checkbox', value: 'checkbox' },
		{ label: 'Select', value: 'select' },
		{ label: 'Image', value: 'image' },
		{ label: 'Password', value: 'password' },
		{ label: 'Range', value: 'range' },
		{ label: 'Link', value: 'link' },
		{ label: 'Radio', value: 'radio' },
		{ label: 'Button Group', value: 'button_group' },
		{ label: 'Color', value: 'color' },
		{ label: 'Date / Time', value: 'datetime' },
		{ label: 'Time', value: 'time' },
		{ label: 'oEmbed', value: 'oembed' },
		{ label: 'Icon', value: 'icon' },
		{ label: 'File', value: 'file' },
		{ label: 'Post Object', value: 'post_object' },
		{ label: 'Page Link', value: 'page_link' },
		{ label: 'Taxonomy', value: 'taxonomy' },
		{ label: 'User', value: 'user' },
		{ label: 'Relationship', value: 'relationship' },
		{ label: 'Gallery', value: 'gallery' },
		{ label: 'WYSIWYG', value: 'wysiwyg' },
		{ label: 'Flexible Content', value: 'flexible_content' },
		{ label: 'Clone', value: 'clone' },
	];

	/**
	 * Parse the inspector "options" textarea into SelectControl options.
	 * Lines look like "value | Label" (label optional).
	 */
	function parseOptions(text) {
		var out = [{ label: __('— Select —', 'tk-fields'), value: '' }];
		String(text || '')
			.split('\n')
			.forEach(function (line) {
				var trimmed = line.trim();
				if (!trimmed) {
					return;
				}
				var parts = trimmed.split('|');
				var value = parts[0].trim();
				var label = (parts[1] || value).trim();
				out.push({ label: label, value: value });
			});
		return out;
	}

	/**
	 * Searchable Dashicons grid picker. Dashicons ship with core, so the
	 * list is a static build-time array (window.TK_FIELD_VALUE_DASHICONS)
	 * — never parsed from CSS at runtime.
	 */
	function IconPicker(props) {
		var value = props.value;
		var setValue = props.onChange;
		var dashicons = props.dashicons || [];
		var label = props.label;
		var useState = element.useState;
		var useMemo = element.useMemo;
		var searchState = useState('');
		var search = searchState[0];
		var setSearch = searchState[1];

		var filtered = useMemo(
			function () {
				var q = search.trim().toLowerCase();
				var list = q
					? dashicons.filter(function (slug) {
							return slug.toLowerCase().indexOf(q) !== -1;
						})
					: dashicons;
				return list.slice(0, 120);
			},
			[search, dashicons]
		);

		return el(
			'div',
			{ className: 'tk-field-value-icon-picker' },
			el(
				Tooltip,
				{ text: __('Pick an icon from the WordPress Dashicons set.', 'tk-fields') },
				el(TextControl, {
					label: label,
					value: search,
					onChange: setSearch,
					placeholder: __('Search icons…', 'tk-fields'),
				})
			),
			el(
				'div',
				{ className: 'tk-field-value-icon-grid', role: 'listbox', 'aria-label': label },
				filtered.map(function (slug) {
					var active = value === slug;
					return el(
						'button',
						{
							key: slug,
							type: 'button',
							className:
								'tk-field-value-icon-grid__btn' + (active ? ' is-active' : ''),
							title: slug,
							'aria-pressed': active,
							onClick: function () {
								setValue(active ? '' : slug);
							},
						},
						el('span', { className: 'dashicons dashicons-' + slug })
					);
				})
			),
			value
				? el(
						'div',
						{ className: 'tk-field-value-icon-selected' },
						el('span', { className: 'dashicons dashicons-' + value }),
						el('code', null, value),
						el(
							Button,
							{ variant: 'link', onClick: function () { setValue(''); } },
							__('Clear', 'tk-fields')
						)
				)
				: null
		);
	}

	// ------------------------------------------------------------------
	// Batch A relational controls.
	//
	// The tk/v1 search endpoints (relationship-search, user-search) return
	// a paginated envelope shaped like {items: [...], total, total_pages},
	// where each item is a DTO like {id, title, display_name, post_type, url}.
	// ------------------------------------------------------------------

	/**
	 * Pull the DTO array out of a search/hydration response, tolerating a
	 * bare array (older shape) as well as the {items:[...]} envelope.
	 */
	function responseItems(res) {
		if (Array.isArray(res)) {
			return res;
		}
		if (res && Array.isArray(res.items)) {
			return res.items;
		}
		return [];
	}

	/**
	 * Normalise one search/hydration DTO into a stable local shape,
	 * tolerating title as a plain string or {rendered}.
	 */
	function normalizeItem(raw) {
		if (!raw || typeof raw !== 'object') {
			return null;
		}
		var id = Number(raw.id);
		if (!id) {
			return null;
		}
		var title = raw.title;
		if (title && typeof title === 'object') {
			title = title.rendered || '';
		}
		return {
			id: id,
			title: title != null ? String(title) : '',
			display_name: raw.display_name != null ? String(raw.display_name) : '',
			post_type: raw.post_type != null ? String(raw.post_type) : (raw.type != null ? String(raw.type) : ''),
			url: raw.url != null ? String(raw.url) : (raw.link != null ? String(raw.link) : ''),
		};
	}

	/**
	 * Hydrated display-data cache for selected items.
	 *
	 * Separate from (and never derived from) the visible search results:
	 * populated via ?include=<ids> on mount / whenever the id list gains
	 * ids not yet in the cache. Returns the Map itself; a state bump
	 * re-renders when a fetch lands.
	 */
	function useHydratedItems(endpoint, ids) {
		var cacheRef = useRef(null);
		if (!cacheRef.current) {
			cacheRef.current = new Map();
		}
		var bumpState = useState(0);
		var bump = bumpState[1];
		var idsKey = ids.join(',');

		useEffect(
			function () {
				var missing = ids.filter(function (id) {
					return !cacheRef.current.has(id);
				});
				if (!missing.length || typeof apiFetch !== 'function') {
					return undefined;
				}
				var cancelled = false;
				apiFetch({
					path:
						endpoint +
						'?include=' +
						missing.map(encodeURIComponent).join(',') +
						'&per_page=' +
						missing.length,
				}).then(
					function (res) {
						if (cancelled) {
							return;
						}
						responseItems(res).forEach(function (raw) {
							var item = normalizeItem(raw);
							if (item) {
								cacheRef.current.set(item.id, item);
							}
						});
						// Mark unresolvable ids so we never refetch them.
						missing.forEach(function (id) {
							if (!cacheRef.current.has(id)) {
								cacheRef.current.set(id, {
									id: id,
									title: '',
									display_name: '',
									post_type: '',
									url: '',
								});
							}
						});
						bump(function (n) {
							return n + 1;
						});
					},
					function () {
						// Swallowed on purpose: fallbacks render, no console noise.
					}
				);
				return function () {
					cancelled = true;
				};
			},
			[endpoint, idsKey]
		);

		return cacheRef.current;
	}

	/**
	 * Debounced search box + results dropdown. The results are a disposable
	 * cache (replaced on every query); callers own selection state.
	 */
	function SearchInput(props) {
		var endpoint = props.endpoint;
		var onSelect = props.onSelect;
		var selectedIds = props.selectedIds || [];
		var searchState = useState('');
		var search = searchState[0];
		var setSearch = searchState[1];
		var resultsState = useState(null); // null = idle; array = disposable cache
		var results = resultsState[0];
		var setResults = resultsState[1];
		var loadingState = useState(false);
		var loading = loadingState[0];
		var setLoading = loadingState[1];
		var requestRef = useRef(0);

		useEffect(
			function () {
				var q = search.trim();
				if (!q || typeof apiFetch !== 'function') {
					setResults(null);
					setLoading(false);
					return undefined;
				}
				var timer = setTimeout(function () {
					var requestId = requestRef.current + 1;
					requestRef.current = requestId;
					setLoading(true);
					apiFetch({
						path: endpoint + '?search=' + encodeURIComponent(q) + '&per_page=20',
					}).then(
						function (res) {
							if (requestRef.current !== requestId) {
								return;
							}
							var items = responseItems(res)
								.map(normalizeItem)
								.filter(Boolean);
							setResults(items);
							setLoading(false);
						},
						function () {
							if (requestRef.current !== requestId) {
								return;
							}
							setResults([]);
							setLoading(false);
						}
					);
				}, 300);
				return function () {
					clearTimeout(timer);
				};
			},
			[search, endpoint]
		);

		if (typeof apiFetch !== 'function') {
			return el(
				'div',
				{ className: 'tk-field-value-placeholder' },
				__('Search is unavailable: wp.apiFetch is missing.', 'tk-fields')
			);
		}

		return el(
			'div',
			{ className: 'tk-field-value-search' },
			el(
				Tooltip,
				{ text: props.hint },
				el(TextControl, {
					label: props.label,
					value: search,
					onChange: setSearch,
					placeholder: props.placeholder,
				})
			),
			loading ? el(Spinner) : null,
			results === null
				? null
				: el(
						'div',
						{ className: 'tk-field-value-search-results', role: 'listbox' },
						results.length
							? results.map(function (item) {
									var disabled = selectedIds.indexOf(item.id) !== -1;
									return el(
										'button',
										{
											key: item.id,
											type: 'button',
											className:
												'tk-field-value-search-result' +
												(disabled ? ' is-disabled' : ''),
											disabled: disabled,
											onClick: function () {
												onSelect(item);
											},
										},
										el(
											'span',
											{ className: 'tk-field-value-search-result__title' },
											item.title || item.display_name || '#' + item.id
										),
										item.post_type
											? el(
													'span',
													{ className: 'tk-field-value-search-result__meta' },
													item.post_type
												)
											: null,
										el(
											'span',
											{ className: 'tk-field-value-search-result__action' },
											disabled
												? __('Added', 'tk-fields')
												: props.actionLabel || __('Select', 'tk-fields')
										)
									);
								})
							: el(
									'div',
									{ className: 'tk-field-value-search-empty' },
									__('No results found.', 'tk-fields')
								)
					)
		);
	}

	/**
	 * Single-entity picker: search, then a selected card with Clear.
	 * The selected display data comes from the hydrated cache only.
	 */
	function EntityPicker(props) {
		var id =
			typeof props.value === 'number' && props.value > 0 ? props.value : null;
		var hydrated = useHydratedItems(props.endpoint, id ? [id] : []);
		var onChange = props.onChange;

		if (!id) {
			return el(SearchInput, {
				endpoint: props.endpoint,
				label: props.searchLabel,
				hint: props.searchHint,
				placeholder: props.placeholder,
				actionLabel: __('Select', 'tk-fields'),
				onSelect: function (item) {
					onChange(item.id);
				},
			});
		}

		var item = hydrated.get(id);
		var title =
			item && (item.title || item.display_name)
				? item.title || item.display_name
				: props.fallback(id);
		var meta = item && item.post_type ? item.post_type : '';

		return el(
			'div',
			{ className: 'tk-field-value-selected-card' },
			el(
				Tooltip,
				{ text: props.selectHint },
				el(
					'div',
					{ className: 'tk-field-value-selected-card__body' },
					el('strong', null, title),
					meta
						? el('span', { className: 'tk-field-value-selected-card__meta' }, meta)
						: null
				)
			),
			el(
				Button,
				{
					variant: 'link',
					onClick: function () {
						onChange(null);
					},
				},
				__('Clear', 'tk-fields')
			)
		);
	}

	/**
	 * Page Link: "Post" mode picks one post (stores {kind:'post',id});
	 * "Custom URL" mode stores {kind:'url',value}.
	 */
	function PageLinkControl(props) {
		var value = props.value;
		var setValue = props.setValue;
		var label = props.label;
		var link = value && typeof value === 'object' ? value : {};
		var mode = link.kind === 'url' ? 'url' : 'post';
		var postId =
			link.kind === 'post' && typeof link.id === 'number' && link.id > 0
				? link.id
				: null;

		return el(
			'div',
			{ className: 'tk-field-value-page-link' },
			el(
				Tooltip,
				{ text: __('Link to a post, or to a custom URL.', 'tk-fields') },
				el(
					'div',
					{ className: 'tk-field-value-mode-toggle', role: 'group', 'aria-label': label },
					el(
						Button,
						{
							variant: mode === 'post' ? 'primary' : 'secondary',
							onClick: function () {
								setValue({ kind: 'post', id: postId });
							},
							'aria-pressed': mode === 'post',
						},
						__('Post', 'tk-fields')
					),
					el(
						Button,
						{
							variant: mode === 'url' ? 'primary' : 'secondary',
							onClick: function () {
								setValue({
									kind: 'url',
									value: link.kind === 'url' ? String(link.value || '') : '',
								});
							},
							'aria-pressed': mode === 'url',
						},
						__('Custom URL', 'tk-fields')
					)
				)
			),
			mode === 'post'
				? el(EntityPicker, {
						endpoint: '/tk/v1/relationship-search',
						value: postId,
						onChange: function (nextId) {
							setValue({ kind: 'post', id: nextId });
						},
						searchLabel: label,
						searchHint: __(
							'Search posts to link to. The post ID is stored; the permalink resolves at render time.',
							'tk-fields'
						),
						placeholder: __('Search posts…', 'tk-fields'),
						selectHint: __(
							'The linked post. The post ID is stored; the permalink resolves at render time.',
							'tk-fields'
						),
						fallback: function (id) {
							return __('Post #', 'tk-fields') + id;
						},
					})
				: el(
						Tooltip,
						{
							text: __(
								'Custom URL, stored as {kind:"url",value}. Note: if the field disallows external URLs, the backend rejects URL-kind values on save.',
								'tk-fields'
							),
						},
						el(TextControl, {
							label: label,
							type: 'url',
							value: link.kind === 'url' ? String(link.value || '') : '',
							onChange: function (next) {
								setValue({ kind: 'url', value: next });
							},
							placeholder: 'https://',
						})
					)
		);
	}

	/**
	 * Taxonomy: checkbox list of terms from the inspector-chosen taxonomy
	 * slug. Checkbox list only in v1; autocomplete for flat taxonomies is a
	 * planned follow-up.
	 */
	function TaxonomyControl(props) {
		var taxonomy = props.taxonomy || '';
		var value = props.value;
		var setValue = props.setValue;
		var label = props.label;
		var selected = Array.isArray(value) ? value : [];

		var terms = useSelect(
			function (select) {
				if (!taxonomy) {
					return null;
				}
				return select('core').getEntityRecords('taxonomy', taxonomy, {
					per_page: 100,
					_fields: 'id,name,slug,parent',
					orderby: 'name',
					order: 'asc',
				});
			},
			[taxonomy]
		);

		if (!taxonomy) {
			return el(
				'div',
				{ className: 'tk-field-value-placeholder' },
				__('Set the taxonomy slug in the inspector.', 'tk-fields')
			);
		}

		var toggle = function (id) {
			setValue(
				selected.indexOf(id) !== -1
					? selected.filter(function (x) {
							return x !== id;
						})
					: selected.concat([id])
			);
		};

		var body;
		if (terms === undefined) {
			body = el(Spinner);
		} else if (!terms || !terms.length) {
			body = el(
				'div',
				{ className: 'tk-field-value-placeholder' },
				__('No terms found in this taxonomy.', 'tk-fields')
			);
		} else {
			var byId = {};
			terms.forEach(function (t) {
				byId[t.id] = t;
			});
			var depthOf = function (t) {
				var d = 0;
				var cur = t;
				var guard = 0;
				while (cur && cur.parent && byId[cur.parent] && guard < 10) {
					d++;
					cur = byId[cur.parent];
					guard++;
				}
				return d;
			};
			body = el(
				'div',
				{ className: 'tk-field-value-taxonomy-list' },
				terms.map(function (t) {
					return el(CheckboxControl, {
						key: t.id,
						label: el(
							'span',
							{ style: { paddingLeft: depthOf(t) * 14 + 'px' } },
							t.name
						),
						checked: selected.indexOf(t.id) !== -1,
						onChange: function () {
							toggle(t.id);
						},
					});
				})
			);
		}

		return el(
			'div',
			{ className: 'tk-field-value-taxonomy' },
			el(
				Tooltip,
				{
					text: __(
						'Tick terms to store. Term IDs are stored; names and links resolve at render time.',
						'tk-fields'
					),
				},
				el('span', { className: 'tk-field-value-label' }, label)
			),
			body
		);
	}

	/**
	 * User: single mode (default, stores int) or multi mode when the value
	 * is already an array (stores int[], token list with per-token remove).
	 */
	function UserControl(props) {
		var value = props.value;
		var setValue = props.setValue;
		var label = props.label;
		var isMulti = Array.isArray(value);
		var ids = isMulti
			? value
					.map(function (v) {
						return Number(v);
					})
					.filter(function (v) {
						return v > 0;
					})
			: [];
		var singleId =
			!isMulti && typeof value === 'number' && value > 0 ? value : null;

		// Unconditional: hooks cannot be conditional; only one list is live.
		var multiHydrated = useHydratedItems('/tk/v1/user-search', isMulti ? ids : []);
		var singleHydrated = useHydratedItems(
			'/tk/v1/user-search',
			singleId ? [singleId] : []
		);

		if (!isMulti) {
			return el(EntityPicker, {
				endpoint: '/tk/v1/user-search',
				value: singleId,
				onChange: function (nextId) {
					setValue(nextId);
				},
				searchLabel: label,
				searchHint: __(
					'Search users. The user ID is stored; the display name resolves at render time.',
					'tk-fields'
				),
				placeholder: __('Search users…', 'tk-fields'),
				selectHint: __('The selected user. Only the user ID is stored.', 'tk-fields'),
				fallback: function (id) {
					return __('User #', 'tk-fields') + id;
				},
			});
		}

		var userName = function (id) {
			var item = multiHydrated.get(id);
			return item && item.display_name
				? item.display_name
				: __('User #', 'tk-fields') + id;
		};

		return el(
			'div',
			{ className: 'tk-field-value-user-multi' },
			el(
				Tooltip,
				{
					text: __(
						'Add users by search. User IDs are stored; display names resolve at render time.',
						'tk-fields'
					),
				},
				el('span', { className: 'tk-field-value-label' }, label)
			),
			ids.length
				? el(
						'div',
						{ className: 'tk-field-value-tokens' },
						ids.map(function (id) {
							var name = userName(id);
							return el(
								'span',
								{ key: id, className: 'tk-field-value-token' },
								el('span', { className: 'tk-field-value-token__label' }, name),
								el(
									'button',
									{
										type: 'button',
										className: 'tk-field-value-token__remove',
										'aria-label': __('Remove', 'tk-fields') + ' ' + name,
										onClick: function () {
											setValue(
												ids.filter(function (x) {
													return x !== id;
												})
											);
										},
									},
									'×'
								)
							);
						})
					)
				: null,
			el(SearchInput, {
				endpoint: '/tk/v1/user-search',
				label: __('Add user', 'tk-fields'),
				hint: __('Search users to add to the list.', 'tk-fields'),
				placeholder: __('Search users…', 'tk-fields'),
				actionLabel: __('Add', 'tk-fields'),
				selectedIds: ids,
				onSelect: function (item) {
					if (ids.indexOf(item.id) === -1) {
						setValue(ids.concat([item.id]));
					}
				},
			})
		);
	}

	/**
	 * Relationship: inline search-to-add + always-visible ordered selected
	 * list with per-row reorder and remove.
	 *
	 * STATE INVARIANT (synthesis §7, unanimous): selectedIds — the ordered
	 * id list from props.value — is the SINGLE source of truth. The search
	 * results are a disposable cache (useState, replaced on every query).
	 * Selected-item display data lives in the separate hydrated cache
	 * (useHydratedItems, populated via ?include=), NEVER derived from the
	 * visible search results.
	 */
	function RelationshipControl(props) {
		var value = props.value;
		var setValue = props.setValue;
		var label = props.label;

		var selectedIds = Array.isArray(value)
			? value
					.map(function (v) {
						return Number(v);
					})
					.filter(function (v) {
						return v > 0;
					})
			: [];

		var hydrated = useHydratedItems('/tk/v1/relationship-search', selectedIds);

		var move = function (index, dir) {
			var j = index + dir;
			if (j < 0 || j >= selectedIds.length) {
				return;
			}
			var next = selectedIds.slice();
			var tmp = next[index];
			next[index] = next[j];
			next[j] = tmp;
			setValue(next);
		};

		var remove = function (id) {
			setValue(
				selectedIds.filter(function (x) {
					return x !== id;
				})
			);
		};

		var add = function (item) {
			if (selectedIds.indexOf(item.id) === -1) {
				setValue(selectedIds.concat([item.id]));
			}
		};

		return el(
			'div',
			{ className: 'tk-field-value-relationship' },
			el(SearchInput, {
				endpoint: '/tk/v1/relationship-search',
				label: __('Search posts', 'tk-fields'),
				hint: __(
					'Search posts to add. Ordered post IDs are stored; search results are never the selection.',
					'tk-fields'
				),
				placeholder: __('Search posts…', 'tk-fields'),
				actionLabel: __('Add', 'tk-fields'),
				selectedIds: selectedIds,
				onSelect: add,
			}),
			el(
				Tooltip,
				{
					text: __(
						'The selected posts, in order. Post IDs are stored; titles resolve at render time.',
						'tk-fields'
					),
				},
				el(
					'span',
					{ className: 'tk-field-value-label' },
					__('Selected', 'tk-fields') +
						(selectedIds.length ? ' (' + selectedIds.length + ')' : '')
				)
			),
			selectedIds.length
				? el(
						'ol',
						{ className: 'tk-field-value-selected-list' },
						selectedIds.map(function (id, index) {
							var item = hydrated.get(id);
							var title =
								item && item.title ? item.title : __('Post #', 'tk-fields') + id;
							var meta = item && item.post_type ? item.post_type : '';
							return el(
								'li',
								{ key: id, className: 'tk-field-value-selected-row' },
								el('span', { className: 'tk-field-value-selected-row__title' }, title),
								meta
									? el(
											'span',
											{ className: 'tk-field-value-selected-row__meta' },
											meta
										)
									: null,
								el(
									'span',
									{ className: 'tk-field-value-selected-row__actions' },
									el(
										'button',
										{
											type: 'button',
											title: __('Move up', 'tk-fields'),
											'aria-label': __('Move up', 'tk-fields'),
											disabled: index === 0,
											onClick: function () {
												move(index, -1);
											},
										},
										'↑'
									),
									el(
										'button',
										{
											type: 'button',
											title: __('Move down', 'tk-fields'),
											'aria-label': __('Move down', 'tk-fields'),
											disabled: index === selectedIds.length - 1,
											onClick: function () {
												move(index, 1);
											},
										},
										'↓'
									),
									el(
										'button',
										{
											type: 'button',
											title: __('Remove', 'tk-fields'),
											'aria-label': __('Remove', 'tk-fields') + ' ' + title,
											onClick: function () {
												remove(id);
											},
										},
										'×'
									)
								)
							);
						})
					)
				: el(
						'div',
						{ className: 'tk-field-value-placeholder' },
						__('No posts selected yet.', 'tk-fields')
					)
		);
	}

	/**
	 * Gallery: the native gallery frame (multiple + gallery). Ordered
	 * attachment IDs are stored; thumbnails resolve at render/edit time.
	 */
	function GalleryControl(props) {
		var value = props.value;
		var setValue = props.setValue;
		var label = props.label;
		var ids = Array.isArray(value)
			? value
					.map(function (v) {
						return Number(v);
					})
					.filter(function (v) {
						return v > 0;
					})
			: [];
		var idsKey = ids.join(',');

		var attachments = useSelect(
			function (select) {
				if (!ids.length) {
					return [];
				}
				return select('core').getEntityRecords('postType', 'attachment', {
					include: ids,
					per_page: ids.length,
					_fields: 'id,source_url,alt_text,media_details',
				});
			},
			[idsKey]
		);

		var byId = {};
		(attachments || []).forEach(function (a) {
			if (a && a.id) {
				byId[a.id] = a;
			}
		});

		var thumbSrc = function (a) {
			if (!a) {
				return null;
			}
			if (
				a.media_details &&
				a.media_details.sizes &&
				a.media_details.sizes.thumbnail
			) {
				return a.media_details.sizes.thumbnail.source_url;
			}
			return a.source_url || null;
		};

		var move = function (index, dir) {
			var j = index + dir;
			if (j < 0 || j >= ids.length) {
				return;
			}
			var next = ids.slice();
			var tmp = next[index];
			next[index] = next[j];
			next[j] = tmp;
			setValue(next);
		};

		var remove = function (id) {
			setValue(
				ids.filter(function (x) {
					return x !== id;
				})
			);
		};

		return el(
			'div',
			{ className: 'tk-field-value-gallery' },
			el(MediaUpload, {
				multiple: true,
				gallery: true,
				allowedTypes: ['image'],
				value: ids,
				onSelect: function (items) {
					setValue(
						(items || [])
							.map(function (m) {
								return m && m.id ? Number(m.id) : 0;
							})
							.filter(function (id) {
								return id > 0;
							})
					);
				},
				render: function (obj) {
					return el(
						Tooltip,
						{
							text: __(
								'Attachment IDs are stored; URLs/alt are resolved at render time, never persisted.',
								'tk-fields'
							),
						},
						el(
							Button,
							{ variant: 'secondary', onClick: obj.open },
							ids.length
								? __('Edit gallery', 'tk-fields')
								: __('Select images', 'tk-fields')
						)
					);
				},
			}),
			ids.length
				? el(
						'div',
						{ className: 'tk-field-value-gallery-grid' },
						ids.map(function (id, index) {
							var a = byId[id];
							var src = thumbSrc(a);
							return el(
								'div',
								{ key: id, className: 'tk-field-value-gallery-thumb' },
								src
									? el('img', { src: src, alt: (a && a.alt_text) || '' })
									: el(
											'span',
											{ className: 'tk-field-value-gallery-thumb__missing' },
											'#' + id
										),
								el(
									'span',
									{ className: 'tk-field-value-gallery-thumb__actions' },
									el(
										'button',
										{
											type: 'button',
											title: __('Move left', 'tk-fields'),
											disabled: index === 0,
											onClick: function () {
												move(index, -1);
											},
										},
										'←'
									),
									el(
										'button',
										{
											type: 'button',
											title: __('Move right', 'tk-fields'),
											disabled: index === ids.length - 1,
											onClick: function () {
												move(index, 1);
											},
										},
										'→'
									),
									el(
										'button',
										{
											type: 'button',
											title: __('Remove', 'tk-fields'),
											onClick: function () {
												remove(id);
											},
										},
										'×'
									)
								)
							);
						})
					)
				: null
		);
	}

	// ------------------------------------------------------------------
	// Map control (Round 3 Batch B).
	//
	// Full UI in the block editor: Leaflet canvas (click-to-set + draggable
	// marker) + latitude/longitude/address/zoom inputs, which are ALWAYS
	// available regardless of the search toggle. The optional Photon
	// address search box (300ms debounce, client-side, from the admin's
	// browser) shows only when enableSearch is on. If Leaflet cannot load
	// (offline CDN), the inputs still work — the canvas degrades to a
	// notice, never to a broken field.
	//
	// The address is stored DENORMALIZED with the coordinates and is never
	// reverse-geocoded: picking a search result sets the address once, at
	// choose time; nothing afterwards overwrites it silently.
	// ------------------------------------------------------------------

	/**
	 * Leaflet is vendored at assets/leaflet/ (BSD-2-Clause) and registered
	 * as a local script dependency of this editor bundle — never loaded
	 * from a CDN. By the time this runs, window.L is ready; resolve then.
	 */
	var leafletLoadPromise = null;

	function ensureLeaflet() {
		if (leafletLoadPromise) {
			return leafletLoadPromise;
		}
		leafletLoadPromise = new Promise(function (resolve, reject) {
			if (window.L && window.L.map) {
				resolve();
			} else {
				reject(new Error('leaflet not loaded'));
			}
		});
		return leafletLoadPromise;
	}

	function round6(n) {
		return Math.round(n * 1e6) / 1e6;
	}

	/**
	 * Human label for one Photon feature. Prefers street address parts,
	 * then the named place, then city/state, then country.
	 */
	function photonLabel(properties) {
		var parts = [];
		var street = [properties.housenumber, properties.street]
			.filter(Boolean)
			.join(' ');
		if (street) {
			parts.push(street);
		} else if (properties.name) {
			parts.push(properties.name);
		}
		if (properties.city) {
			parts.push(properties.city);
		} else if (properties.state) {
			parts.push(properties.state);
		}
		if (properties.country) {
			parts.push(properties.country);
		}
		return parts.join(', ');
	}

	/**
	 * Debounced Photon search box. Calls onPick({lat, lng, address}).
	 * Pure client-side fetch against the filter-provided URL template.
	 */
	function MapSearchInput(props) {
		var searchState = useState('');
		var search = searchState[0];
		var setSearch = searchState[1];
		var resultsState = useState(null);
		var results = resultsState[0];
		var setResults = resultsState[1];
		var loadingState = useState(false);
		var loading = loadingState[0];
		var setLoading = loadingState[1];
		var requestRef = useRef(0);

		useEffect(
			function () {
				var q = search.trim();
				var cfg = window.TK_FIELDS_MAP || {};
				var template = cfg.geocoderTemplate || '';
				if (!q || !template) {
					setResults(null);
					setLoading(false);
					return undefined;
				}
				var timer = setTimeout(function () {
					var requestId = requestRef.current + 1;
					requestRef.current = requestId;
					setLoading(true);
					fetch(template.split('{query}').join(encodeURIComponent(q)))
						.then(function (res) {
							if (!res.ok) {
								throw new Error('geocoder error');
							}
							return res.json();
						})
						.then(function (data) {
							if (requestRef.current !== requestId) {
								return;
							}
							var features = (data && data.features) || [];
							setResults(
								features
									.map(function (f) {
										var coords =
											f && f.geometry && f.geometry.coordinates;
										var p = (f && f.properties) || {};
										if (
											!coords ||
											typeof coords[1] !== 'number' ||
											typeof coords[0] !== 'number'
										) {
											return null;
										}
										return {
											lat: coords[1],
											lng: coords[0],
											address: photonLabel(p),
										};
									})
									.filter(Boolean)
									.slice(0, 8)
							);
							setLoading(false);
						})
						.catch(function () {
							if (requestRef.current !== requestId) {
								return;
							}
							setResults([]);
							setLoading(false);
						});
				}, 300);
				return function () {
					clearTimeout(timer);
				};
			},
			[search]
		);

		return el(
			'div',
			{ className: 'tk-field-value-map-search' },
			el(
				Tooltip,
				{
					text: __(
						'Search addresses via the Photon geocoder (client-side, debounced). Picking a result sets the coordinates and the address. The map preview loads OpenStreetMap tiles (tile.openstreetmap.org) whenever it renders.',
						'tk-fields'
					),
				},
				el(TextControl, {
					label: __('Search address', 'tk-fields'),
					value: search,
					onChange: setSearch,
					placeholder: __('Type an address…', 'tk-fields'),
				})
			),
			loading ? el(Spinner) : null,
			results === null
				? null
				: el(
						'div',
						{ className: 'tk-field-value-search-results', role: 'listbox' },
						results.length
							? results.map(function (r, i) {
									return el(
										'button',
										{
											key: i,
											type: 'button',
											className: 'tk-field-value-search-result',
											onClick: function () {
												props.onPick({
													lat: round6(r.lat),
													lng: round6(r.lng),
													address: r.address,
												});
												setSearch('');
												setResults(null);
											},
										},
										el(
											'span',
											{ className: 'tk-field-value-search-result__title' },
											r.address || r.lat + ',' + r.lng
										)
									);
								})
							: el(
									'div',
									{ className: 'tk-field-value-search-empty' },
									__('No results found.', 'tk-fields')
								)
					)
		);
	}

	/**
	 * Map: Leaflet canvas + always-available lat/lng/address/zoom inputs.
	 */
	function MapControl(props) {
		var value = props.value;
		var setValue = props.setValue;
		var label = props.label;
		var enableSearch = !!props.enableSearch;

		var loc = value && typeof value === 'object' ? value : {};
		var lat = typeof loc.lat === 'number' ? loc.lat : null;
		var lng = typeof loc.lng === 'number' ? loc.lng : null;
		var zoom = typeof loc.zoom === 'number' ? loc.zoom : 13;
		var address = typeof loc.address === 'string' ? loc.address : '';

		// valueRef keeps Leaflet event handlers (registered once) reading
		// the CURRENT value instead of a stale render closure — otherwise
		// a map click could clobber an address typed afterwards.
		var valueRef = useRef(null);
		valueRef.current = { lat: lat, lng: lng, zoom: zoom, address: address };
		var patch = function (p) {
			setValue(Object.assign({}, valueRef.current, p));
		};

		var mapRef = useRef(null);
		var markerRef = useRef(null);
		var containerRef = useRef(null);
		var leafletState = useState('loading');
		var leafletStatus = leafletState[0];
		var setLeafletStatus = leafletState[1];

		useEffect(function () {
			var cancelled = false;
			ensureLeaflet().then(
				function () {
					if (!cancelled) {
						setLeafletStatus('ready');
					}
				},
				function () {
					if (!cancelled) {
						setLeafletStatus('failed');
					}
				}
			);
			return function () {
				cancelled = true;
			};
		}, []);

		// Create the map once Leaflet is ready and the container mounted.
		useEffect(
			function () {
				if (
					leafletStatus !== 'ready' ||
					!containerRef.current ||
					mapRef.current ||
					!window.L
				) {
					return undefined;
				}
				var hasCoords = lat !== null && lng !== null;
				var map = window.L
					.map(containerRef.current)
					.setView(hasCoords ? [lat, lng] : [20, 0], hasCoords ? zoom : 2);
				window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
					maxZoom: 19,
					attribution:
						'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
				}).addTo(map);
				map.on('click', function (e) {
					patch({ lat: round6(e.latlng.lat), lng: round6(e.latlng.lng) });
				});
				mapRef.current = map;
				return function () {
					map.remove();
					mapRef.current = null;
					markerRef.current = null;
				};
			},
			[leafletStatus]
		);

		// Keep the marker in sync with the coordinates (and removable when
		// the coordinates are cleared). The marker is draggable too.
		useEffect(
			function () {
				var map = mapRef.current;
				if (!map || !window.L) {
					return undefined;
				}
				if (lat === null || lng === null) {
					if (markerRef.current) {
						markerRef.current.remove();
						markerRef.current = null;
					}
					return undefined;
				}
				if (!markerRef.current) {
					markerRef.current = window.L.marker([lat, lng], {
						draggable: true,
					}).addTo(map);
					markerRef.current.on('dragend', function () {
						var p = markerRef.current.getLatLng();
						patch({ lat: round6(p.lat), lng: round6(p.lng) });
					});
				} else {
					markerRef.current.setLatLng([lat, lng]);
				}
				return undefined;
			},
			[lat, lng]
		);

		var parseCoord = function (next) {
			var t = String(next).trim();
			if ('' === t) {
				return null;
			}
			var n = parseFloat(t);
			return isNaN(n) ? null : n;
		};

		var canvas;
		if ('failed' === leafletStatus) {
			canvas = el(
				'div',
				{ className: 'tk-field-value-map-offline' },
				__('Map preview unavailable — the Leaflet library could not be loaded (offline?). The inputs below still work.', 'tk-fields')
			);
		} else {
			canvas = el('div', {
				ref: containerRef,
				className: 'tk-field-value-map-canvas',
				'aria-label': label,
			});
		}

		return el(
			'div',
			{ className: 'tk-field-value-map' },
			el(
				Tooltip,
				{
					text: __(
						'Click the map to set the location, or drag the marker. Coordinates are clamped to valid ranges on save; the address is stored as entered and never re-geocoded.',
						'tk-fields'
					),
				},
				el('span', { className: 'tk-field-value-label' }, label)
			),
			'loading' === leafletStatus ? el(Spinner) : null,
			canvas,
			enableSearch
				? el(MapSearchInput, {
						onPick: function (pick) {
							patch(pick);
						},
					})
				: null,
			el(
				Tooltip,
				{
					text: __(
						'Address stored with the coordinates (denormalized). Never overwritten silently — only you or a search pick changes it.',
						'tk-fields'
					),
				},
				el(TextControl, {
					label: __('Address', 'tk-fields'),
					value: address,
					onChange: function (next) {
						patch({ address: next });
					},
					placeholder: __('e.g. 10 Downing Street, London', 'tk-fields'),
				})
			),
			el(
				'div',
				{ className: 'tk-field-value-map-coords' },
				el(TextControl, {
					label: __('Latitude', 'tk-fields'),
					type: 'number',
					step: 'any',
					value: lat === null ? '' : String(lat),
					onChange: function (next) {
						patch({ lat: parseCoord(next) });
					},
					placeholder: '51.5074',
				}),
				el(TextControl, {
					label: __('Longitude', 'tk-fields'),
					type: 'number',
					step: 'any',
					value: lng === null ? '' : String(lng),
					onChange: function (next) {
						patch({ lng: parseCoord(next) });
					},
					placeholder: '-0.1278',
				}),
				el(TextControl, {
					label: __('Zoom', 'tk-fields'),
					type: 'number',
					min: 0,
					max: 18,
					value: String(zoom),
					onChange: function (next) {
						var z = parseInt(next, 10);
						patch({ zoom: isNaN(z) ? 13 : Math.max(0, Math.min(18, z)) });
					},
				})
			),
			lat !== null || lng !== null || '' !== address
				? el(
						Button,
						{
							variant: 'link',
							onClick: function () {
								setValue(null);
							},
						},
						__('Clear location', 'tk-fields')
					)
				: null
		);
	}

	// ------------------------------------------------------------------
	// Map control (Round 3 Batch B).
	//
	// Full UI in the block editor: Leaflet canvas (click-to-set + draggable
	// marker) + latitude/longitude/address/zoom inputs, which are ALWAYS
	// available regardless of the search toggle. The optional Photon
	// address search box (300ms debounce, client-side, from the admin's
	// browser) shows only when enableSearch is on. If Leaflet cannot load
	// (offline CDN), the inputs still work — the canvas degrades to a
	// notice, never to a broken field.
	//
	// The address is stored DENORMALIZED with the coordinates and is never
	// reverse-geocoded: picking a search result sets the address once, at
	// choose time; nothing afterwards overwrites it silently.
	// ------------------------------------------------------------------

	/**
	 * Leaflet is vendored at assets/leaflet/ (BSD-2-Clause) and registered
	 * as a local script dependency of this editor bundle — never loaded
	 * from a CDN. By the time this runs, window.L is ready; resolve then.
	 */
	var leafletLoadPromise = null;

	function ensureLeaflet() {
		if (leafletLoadPromise) {
			return leafletLoadPromise;
		}
		leafletLoadPromise = new Promise(function (resolve, reject) {
			if (window.L && window.L.map) {
				resolve();
			} else {
				reject(new Error('leaflet not loaded'));
			}
		});
		return leafletLoadPromise;
	}

	function round6(n) {
		return Math.round(n * 1e6) / 1e6;
	}

	/**
	 * Human label for one Photon feature. Prefers street address parts,
	 * then the named place, then city/state, then country.
	 */
	function photonLabel(properties) {
		var parts = [];
		var street = [properties.housenumber, properties.street]
			.filter(Boolean)
			.join(' ');
		if (street) {
			parts.push(street);
		} else if (properties.name) {
			parts.push(properties.name);
		}
		if (properties.city) {
			parts.push(properties.city);
		} else if (properties.state) {
			parts.push(properties.state);
		}
		if (properties.country) {
			parts.push(properties.country);
		}
		return parts.join(', ');
	}

	/**
	 * Debounced Photon search box. Calls onPick({lat, lng, address}).
	 * Pure client-side fetch against the filter-provided URL template.
	 */
	function MapSearchInput(props) {
		var searchState = useState('');
		var search = searchState[0];
		var setSearch = searchState[1];
		var resultsState = useState(null);
		var results = resultsState[0];
		var setResults = resultsState[1];
		var loadingState = useState(false);
		var loading = loadingState[0];
		var setLoading = loadingState[1];
		var requestRef = useRef(0);

		useEffect(
			function () {
				var q = search.trim();
				var cfg = window.TK_FIELDS_MAP || {};
				var template = cfg.geocoderTemplate || '';
				if (!q || !template) {
					setResults(null);
					setLoading(false);
					return undefined;
				}
				var timer = setTimeout(function () {
					var requestId = requestRef.current + 1;
					requestRef.current = requestId;
					setLoading(true);
					fetch(template.split('{query}').join(encodeURIComponent(q)))
						.then(function (res) {
							if (!res.ok) {
								throw new Error('geocoder error');
							}
							return res.json();
						})
						.then(function (data) {
							if (requestRef.current !== requestId) {
								return;
							}
							var features = (data && data.features) || [];
							setResults(
								features
									.map(function (f) {
										var coords =
											f && f.geometry && f.geometry.coordinates;
										var p = (f && f.properties) || {};
										if (
											!coords ||
											typeof coords[1] !== 'number' ||
											typeof coords[0] !== 'number'
										) {
											return null;
										}
										return {
											lat: coords[1],
											lng: coords[0],
											address: photonLabel(p),
										};
									})
									.filter(Boolean)
									.slice(0, 8)
							);
							setLoading(false);
						})
						.catch(function () {
							if (requestRef.current !== requestId) {
								return;
							}
							setResults([]);
							setLoading(false);
						});
				}, 300);
				return function () {
					clearTimeout(timer);
				};
			},
			[search]
		);

		return el(
			'div',
			{ className: 'tk-field-value-map-search' },
			el(
				Tooltip,
				{
					text: __(
						'Search addresses via the Photon geocoder (client-side, debounced). Picking a result sets the coordinates and the address. The map preview loads OpenStreetMap tiles (tile.openstreetmap.org) whenever it renders.',
						'tk-fields'
					),
				},
				el(TextControl, {
					label: __('Search address', 'tk-fields'),
					value: search,
					onChange: setSearch,
					placeholder: __('Type an address…', 'tk-fields'),
				})
			),
			loading ? el(Spinner) : null,
			results === null
				? null
				: el(
						'div',
						{ className: 'tk-field-value-search-results', role: 'listbox' },
						results.length
							? results.map(function (r, i) {
									return el(
										'button',
										{
											key: i,
											type: 'button',
											className: 'tk-field-value-search-result',
											onClick: function () {
												props.onPick({
													lat: round6(r.lat),
													lng: round6(r.lng),
													address: r.address,
												});
												setSearch('');
												setResults(null);
											},
										},
										el(
											'span',
											{ className: 'tk-field-value-search-result__title' },
											r.address || r.lat + ',' + r.lng
										)
									);
								})
							: el(
									'div',
									{ className: 'tk-field-value-search-empty' },
									__('No results found.', 'tk-fields')
								)
					)
		);
	}

	/**
	 * Map: Leaflet canvas + always-available lat/lng/address/zoom inputs.
	 */
	function MapControl(props) {
		var value = props.value;
		var setValue = props.setValue;
		var label = props.label;
		var enableSearch = !!props.enableSearch;

		var loc = value && typeof value === 'object' ? value : {};
		var lat = typeof loc.lat === 'number' ? loc.lat : null;
		var lng = typeof loc.lng === 'number' ? loc.lng : null;
		var zoom = typeof loc.zoom === 'number' ? loc.zoom : 13;
		var address = typeof loc.address === 'string' ? loc.address : '';

		// valueRef keeps Leaflet event handlers (registered once) reading
		// the CURRENT value instead of a stale render closure — otherwise
		// a map click could clobber an address typed afterwards.
		var valueRef = useRef(null);
		valueRef.current = { lat: lat, lng: lng, zoom: zoom, address: address };
		var patch = function (p) {
			setValue(Object.assign({}, valueRef.current, p));
		};

		var mapRef = useRef(null);
		var markerRef = useRef(null);
		var containerRef = useRef(null);
		var leafletState = useState('loading');
		var leafletStatus = leafletState[0];
		var setLeafletStatus = leafletState[1];

		useEffect(function () {
			var cancelled = false;
			ensureLeaflet().then(
				function () {
					if (!cancelled) {
						setLeafletStatus('ready');
					}
				},
				function () {
					if (!cancelled) {
						setLeafletStatus('failed');
					}
				}
			);
			return function () {
				cancelled = true;
			};
		}, []);

		// Create the map once Leaflet is ready and the container mounted.
		useEffect(
			function () {
				if (
					leafletStatus !== 'ready' ||
					!containerRef.current ||
					mapRef.current ||
					!window.L
				) {
					return undefined;
				}
				var hasCoords = lat !== null && lng !== null;
				var map = window.L
					.map(containerRef.current)
					.setView(hasCoords ? [lat, lng] : [20, 0], hasCoords ? zoom : 2);
				window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
					maxZoom: 19,
					attribution:
						'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
				}).addTo(map);
				map.on('click', function (e) {
					patch({ lat: round6(e.latlng.lat), lng: round6(e.latlng.lng) });
				});
				mapRef.current = map;
				return function () {
					map.remove();
					mapRef.current = null;
					markerRef.current = null;
				};
			},
			[leafletStatus]
		);

		// Keep the marker in sync with the coordinates (and removable when
		// the coordinates are cleared). The marker is draggable too.
		useEffect(
			function () {
				var map = mapRef.current;
				if (!map || !window.L) {
					return undefined;
				}
				if (lat === null || lng === null) {
					if (markerRef.current) {
						markerRef.current.remove();
						markerRef.current = null;
					}
					return undefined;
				}
				if (!markerRef.current) {
					markerRef.current = window.L.marker([lat, lng], {
						draggable: true,
					}).addTo(map);
					markerRef.current.on('dragend', function () {
						var p = markerRef.current.getLatLng();
						patch({ lat: round6(p.lat), lng: round6(p.lng) });
					});
				} else {
					markerRef.current.setLatLng([lat, lng]);
				}
				return undefined;
			},
			[lat, lng]
		);

		var parseCoord = function (next) {
			var t = String(next).trim();
			if ('' === t) {
				return null;
			}
			var n = parseFloat(t);
			return isNaN(n) ? null : n;
		};

		var canvas;
		if ('failed' === leafletStatus) {
			canvas = el(
				'div',
				{ className: 'tk-field-value-map-offline' },
				__('Map preview unavailable — the Leaflet library could not be loaded (offline?). The inputs below still work.', 'tk-fields')
			);
		} else {
			canvas = el('div', {
				ref: containerRef,
				className: 'tk-field-value-map-canvas',
				'aria-label': label,
			});
		}

		return el(
			'div',
			{ className: 'tk-field-value-map' },
			el(
				Tooltip,
				{
					text: __(
						'Click the map to set the location, or drag the marker. Coordinates are clamped to valid ranges on save; the address is stored as entered and never re-geocoded.',
						'tk-fields'
					),
				},
				el('span', { className: 'tk-field-value-label' }, label)
			),
			'loading' === leafletStatus ? el(Spinner) : null,
			canvas,
			enableSearch
				? el(MapSearchInput, {
						onPick: function (pick) {
							patch(pick);
						},
					})
				: null,
			el(
				Tooltip,
				{
					text: __(
						'Address stored with the coordinates (denormalized). Never overwritten silently — only you or a search pick changes it.',
						'tk-fields'
					),
				},
				el(TextControl, {
					label: __('Address', 'tk-fields'),
					value: address,
					onChange: function (next) {
						patch({ address: next });
					},
					placeholder: __('e.g. 10 Downing Street, London', 'tk-fields'),
				})
			),
			el(
				'div',
				{ className: 'tk-field-value-map-coords' },
				el(TextControl, {
					label: __('Latitude', 'tk-fields'),
					type: 'number',
					step: 'any',
					value: lat === null ? '' : String(lat),
					onChange: function (next) {
						patch({ lat: parseCoord(next) });
					},
					placeholder: '51.5074',
				}),
				el(TextControl, {
					label: __('Longitude', 'tk-fields'),
					type: 'number',
					step: 'any',
					value: lng === null ? '' : String(lng),
					onChange: function (next) {
						patch({ lng: parseCoord(next) });
					},
					placeholder: '-0.1278',
				}),
				el(TextControl, {
					label: __('Zoom', 'tk-fields'),
					type: 'number',
					min: 0,
					max: 18,
					value: String(zoom),
					onChange: function (next) {
						var z = parseInt(next, 10);
						patch({ zoom: isNaN(z) ? 13 : Math.max(0, Math.min(18, z)) });
					},
				})
			),
			lat !== null || lng !== null || '' !== address
				? el(
						Button,
						{
							variant: 'link',
							onClick: function () {
								setValue(null);
							},
						},
						__('Clear location', 'tk-fields')
					)
				: null
		);
	}

	function ValueControl(props) {
		var fieldType = props.fieldType;
		var value = props.value;
		var setValue = props.setValue;
		var options = props.options;
		var label = props.label;
		var taxonomy = props.taxonomy;

		// Unconditional: only used by the image branch, but hooks cannot be conditional.
		var media = useSelect(
			function (select) {
				if ((fieldType !== 'image' && fieldType !== 'file') || !value) {
					return null;
				}
				return select('core').getMedia(value);
			},
			[fieldType, value]
		);

		var common = { label: label };

		switch (fieldType) {
			case 'textarea':
				return el(
					Tooltip,
					{ text: __('Multi-line text value for this sub-field.', 'tk-fields') },
					el(TextareaControl, Object.assign({}, common, {
						value: value == null ? '' : String(value),
						onChange: setValue,
					}))
				);
			case 'number':
				return el(
					Tooltip,
					{ text: __('Numeric value for this sub-field.', 'tk-fields') },
					el(TextControl, Object.assign({}, common, {
						type: 'number',
						value: value == null ? '' : String(value),
						onChange: function (next) {
							setValue(next === '' ? null : Number(next));
						},
					}))
				);
			case 'email':
				return el(
					Tooltip,
					{ text: __('Email address for this sub-field.', 'tk-fields') },
					el(TextControl, Object.assign({}, common, {
						type: 'email',
						value: value == null ? '' : String(value),
						onChange: setValue,
					}))
				);
			case 'url':
				return el(
					Tooltip,
					{ text: __('URL for this sub-field.', 'tk-fields') },
					el(TextControl, Object.assign({}, common, {
						type: 'url',
						value: value == null ? '' : String(value),
						onChange: setValue,
					}))
				);
			case 'date':
				return el(
					Tooltip,
					{ text: __('Date value for this sub-field.', 'tk-fields') },
					el(TextControl, Object.assign({}, common, {
						type: 'date',
						value: value == null ? '' : String(value),
						onChange: setValue,
					}))
				);
			case 'checkbox':
				return el(
					Tooltip,
					{ text: __('True/false value for this sub-field.', 'tk-fields') },
					el(CheckboxControl, {
						label: label,
						checked: !!value,
						onChange: setValue,
					})
				);
			case 'select':
				return el(
					Tooltip,
					{ text: __('Pick one of the options defined in the inspector.', 'tk-fields') },
					el(SelectControl, Object.assign({}, common, {
						options: parseOptions(options),
						value: value == null ? '' : String(value),
						onChange: setValue,
					}))
				);
			case 'radio':
			return el(
				Tooltip,
				{ text: __('Pick one of the options defined in the inspector.', 'tk-fields') },
				el(RadioControl, Object.assign({}, common, {
					options: parseOptions(options).filter(function (o) { return o.value !== ''; }),
					selected: value == null ? '' : String(value),
					onChange: setValue,
				}))
			);
		case 'button_group': {
			var bgOptions = parseOptions(options).filter(function (o) { return o.value !== ''; });
			return el(
				'div',
				{ className: 'tk-field-value-button-group' },
				el(
					Tooltip,
					{ text: __('Pick one option. Same storage as radio — visual variant only.', 'tk-fields') },
					el('span', { className: 'tk-field-value-label' }, label)
				),
				el(
					'div',
					{ className: 'tk-field-value-button-group__buttons', role: 'group' },
					bgOptions.map(function (o) {
						var active = String(value) === String(o.value);
						return el(
							Button,
							{
								key: o.value,
								variant: active ? 'primary' : 'secondary',
									onClick: function () { setValue(o.value); },
									'aria-pressed': active,
							},
							o.label
						);
					})
				)
			);
		}
		case 'color': {
			var hex = value == null ? '' : String(value);
			return el(
				'div',
				{ className: 'tk-field-value-color-control' },
				el(
					Tooltip,
					{ text: __('Pick a color. Stored as a lowercase hex string (#rrggbb).', 'tk-fields') },
					el('input', {
						type: 'color',
						value: /^#[0-9a-f]{6}$/i.test(hex) ? hex : '#000000',
						onChange: function (e) { setValue(e.target.value.toLowerCase()); },
						'aria-label': label,
						className: 'tk-field-value-color-swatch',
					})
				),
				el(TextControl, Object.assign({}, common, {
					value: hex,
					onChange: setValue,
					placeholder: '#ff0000',
				}))
			);
		}
		case 'datetime': {
			var dt = value == null ? '' : String(value);
			var dtDate = dt.slice(0, 10);
			var dtTime = dt.slice(11, 16);
			var setDateTime = function (nextDate, nextTime) {
				if (!nextDate || !nextTime) {
					return;
				}
				setValue(nextDate + ' ' + nextTime + ':00');
			};
			return el(
				'div',
				{ className: 'tk-field-value-datetime-control' },
				el(
					Tooltip,
				{ text: __('Pick a date.', 'tk-fields') },
					el(TextControl, {
						label: __('Date', 'tk-fields'),
						type: 'date',
						value: dtDate,
						onChange: function (next) { setDateTime(next, dtTime || '00:00'); },
					})
				),
				el(
					Tooltip,
				{ text: __('Pick a time.', 'tk-fields') },
					el(TextControl, {
						label: __('Time', 'tk-fields'),
						type: 'time',
						value: dtTime,
						onChange: function (next) { setDateTime(dtDate, next); },
					})
				)
			);
		}
		case 'time': {
			var tv = value == null ? '' : String(value);
			return el(
				Tooltip,
				{ text: __('Time of day, stored as HH:MM:SS (24-hour).', 'tk-fields') },
				el(TextControl, Object.assign({}, common, {
					type: 'time',
					step: 1,
					value: tv.slice(0, 8),
					onChange: function (next) {
						if (!next) {
							setValue(null);
							return;
						}
						setValue(next.length <= 5 ? next + ':00' : next);
					},
				}))
			);
		}
		case 'oembed':
			return el(
				Tooltip,
				{ text: __('URL of embeddable content (YouTube, X, Spotify…). The URL is stored; the embed HTML renders on the frontend.', 'tk-fields') },
				el(TextControl, Object.assign({}, common, {
					type: 'url',
					value: value == null ? '' : String(value),
					onChange: setValue,
					placeholder: 'https://',
				}))
			);
		case 'icon': {
			var dashicons = window.TK_FIELD_VALUE_DASHICONS || [];
			return el(IconPicker, {
				label: label,
				value: value == null ? '' : String(value),
				onChange: setValue,
				dashicons: dashicons,
			});
		}
		case 'file': {
			return el(MediaUpload, {
				onSelect: function (media) {
					setValue(media && media.id ? media.id : null);
				},
				value: value,
				render: function (obj) {
					var fileLabel = null;
					if (value) {
						var fname = media && (media.filename || (media.source_url || '').split('/').pop());
						fileLabel = fname
							? el('span', { className: 'tk-field-value-file-name' }, fname)
							: el('span', null, __('Attachment #', 'tk-fields') + value);
					}
					return el(
						'div',
						{ className: 'tk-field-value-file-control' },
						el(
							Tooltip,
							{
								text: __(
									'Choose a file from the media library (any type). The attachment ID is stored.',
									'tk-fields'
								),
							},
							el(
								Button,
								{
									variant: 'secondary',
									onClick: obj.open,
									className: 'tk-field-value-file-button',
								},
								value ? __('Replace file', 'tk-fields') : __('Select file', 'tk-fields')
							)
						),
						fileLabel
					);
				},
			});
		}
		case 'image': {
				return el(MediaUpload, {
					onSelect: function (media) {
						setValue(media && media.id ? media.id : null);
					},
					allowedTypes: ['image'],
					value: value,
					render: function (obj) {
						var preview = null;
						if (value) {
							var src =
								media &&
								media.media_details &&
								media.media_details.sizes &&
								media.media_details.sizes.thumbnail
									? media.media_details.sizes.thumbnail.source_url
									: media && media.source_url
										? media.source_url
										: null;
							preview = src
								? el('img', {
										src: src,
										alt: '',
										className: 'tk-field-value-image-preview',
									})
								: el('span', null, __('Attachment #', 'tk-fields') + value);
						}
						return el(
							'div',
							{ className: 'tk-field-value-image-control' },
							el(
								Tooltip,
								{
									text: __(
										'Choose an image from the media library. The attachment ID is stored.',
										'tk-fields'
									),
								},
								el(
									Button,
									{
										variant: 'secondary',
										onClick: obj.open,
										className: 'tk-field-value-image-button',
									},
									value ? __('Replace image', 'tk-fields') : __('Select image', 'tk-fields')
								)
							),
							preview
						);
					},
				});
			}
			case 'password':
				return el(
					Tooltip,
					{ text: __('Masked text value for this sub-field.', 'tk-fields') },
					el(TextControl, Object.assign({}, common, {
						type: 'password',
						value: value == null ? '' : String(value),
						onChange: setValue,
					}))
				);
			case 'range':
				return el(
					Tooltip,
					{ text: __('Numeric slider value for this sub-field (0–100).', 'tk-fields') },
					el(RangeControl, Object.assign({}, common, {
						min: 0,
						max: 100,
						step: 1,
						value: value == null ? 0 : Number(value),
						onChange: setValue,
					}))
				);
			case 'link': {
				var link = value && typeof value === 'object' ? value : {};
				var setLink = function (patch) {
					setValue(Object.assign({}, link, patch));
				};
				return el(
					'div',
					{ className: 'tk-field-value-link-control' },
					el(
						Tooltip,
						{ text: __('Destination URL for this sub-field.', 'tk-fields') },
						el(TextControl, {
							label: __('URL', 'tk-fields'),
							type: 'url',
							value: link.url || '',
							onChange: function (next) { setLink({ url: next }); },
						})
					),
					el(
						Tooltip,
						{ text: __('Link text shown on the frontend (falls back to the URL).', 'tk-fields') },
						el(TextControl, {
							label: __('Link text', 'tk-fields'),
							value: link.title || '',
							onChange: function (next) { setLink({ title: next }); },
						})
					),
					el(
						Tooltip,
						{ text: __('Open the link in a new tab.', 'tk-fields') },
						el(CheckboxControl, {
							label: __('Open in new tab', 'tk-fields'),
							checked: link.target === '_blank',
							onChange: function (next) { setLink({ target: next ? '_blank' : '_self' }); },
						})
					)
				);
			}
			case 'post_object': {
				var poId = typeof value === 'number' && value > 0 ? value : null;
				return el(EntityPicker, {
					endpoint: '/tk/v1/relationship-search',
					value: poId,
					onChange: function (nextId) {
						setValue(nextId);
					},
					searchLabel: label,
					searchHint: __(
						'Search posts to pick a single related post. The post ID is stored.',
						'tk-fields'
					),
					placeholder: __('Search posts…', 'tk-fields'),
					selectHint: __(
						'The selected post. The post ID is stored; the title and URL resolve at render time.',
						'tk-fields'
					),
					fallback: function (id) {
						return __('Post #', 'tk-fields') + id;
					},
				});
			}
			case 'page_link':
				return el(PageLinkControl, {
					value: value,
					setValue: setValue,
					label: label,
				});
			case 'taxonomy':
				return el(TaxonomyControl, {
					value: value,
					setValue: setValue,
					label: label,
					taxonomy: taxonomy,
				});
			case 'user':
				return el(UserControl, {
					value: value,
					setValue: setValue,
					label: label,
				});
			case 'relationship':
				return el(RelationshipControl, {
					value: value,
					setValue: setValue,
					label: label,
				});
			case 'gallery':
				return el(GalleryControl, {
					value: value,
					setValue: setValue,
					label: label,
				});
			case 'map':
				return el(MapControl, {
					value: value,
					setValue: setValue,
					label: label,
					enableSearch: !!props.enableSearch,
				});
			case 'wysiwyg':
				// RichText per the Round 3 decision (block input for the
				// WYSIWYG type). The toolbar preset applies to the classic
				// wp_editor() render; here Gutenberg's own RichText toolbar
				// is used. HTML is sanitized server-side on save.
				return el(
					Tooltip,
					{ text: __('Rich text value for this sub-field.', 'tk-fields') },
					el(RichText, Object.assign({}, common, {
						value: value == null ? '' : String(value),
						onChange: setValue,
						multiline: 'p',
					}))
				);
			case 'flexible_content':
			case 'clone':
				// Composite types: their values are managed by their own
				// blocks (tk/flexible-content rows, clone fan-out), not by a
				// scalar input here.
				return el(
					Notice,
					{ status: 'info', isDismissible: false },
					fieldType === 'flexible_content'
						? __('Flexible content rows are edited in the Flexible Content block.', 'tk-fields')
						: __('Clone values are edited through the source group\u2019s fields.', 'tk-fields')
				);
			case 'text':
			default:
				return el(
					Tooltip,
					{ text: __('Single-line text value for this sub-field.', 'tk-fields') },
					el(TextControl, Object.assign({}, common, {
						value: value == null ? '' : String(value),
						onChange: setValue,
					}))
				);
		}
	}

	// ------------------------------------------------------------------
	// Field-group → field cascade picker (v0.12.0).
	//
	// Replaces the manual field-slug text input with two cascading
	// SelectControls: field group → field. Group-type fields expand their
	// sub-fields as "Group label › Sub-field label" options that store the
	// namespaced sub-field name (groupname_subname — the same underscore
	// convention the backend uses). A manual-entry toggle keeps the old
	// text input for edge cases (e.g. fields not in any group).
	//
	// Module-level cache so multiple blocks on one screen fetch once.
	// ------------------------------------------------------------------

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
	 * Field options for one group: flat fields plus expanded group
	 * sub-fields ("Group label › Sub-field label"). Each option carries
	 * fieldType so the caller can sync the block's fieldType attribute.
	 */
	function fieldOptionsFor(group) {
		var opts = [{ label: __('— Select —', 'tk-fields'), value: '' }];
		(group.fields || []).forEach(function (f) {
			if ('group' === f.type && f.sub_fields && f.sub_fields.length) {
				f.sub_fields.forEach(function (s) {
					opts.push({
						label: (f.label || f.name) + ' › ' + (s.label || s.name),
						value: f.name + '_' + s.name,
						fieldType: s.type || 'text',
					});
				});
			} else {
				opts.push({
					label: (f.label || f.name) + ' (' + (f.type || 'text') + ')',
					value: f.name,
					fieldType: f.type || 'text',
				});
			}
		});
		return opts;
	}

	function supportedFieldType(type) {
		return FIELD_TYPES.some(function (t) {
			return t.value === type;
		});
	}

	/**
	 * The inspector's field picker: group → field cascade + manual fallback.
	 */
	function FieldPicker(props) {
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

		// Pre-select the group that owns the current fieldName, so the
		// cascade reflects a previously saved block.
		useEffect(
			function () {
				if (!ready || groupId || !attributes.fieldName) {
					return;
				}
				var current = attributes.fieldName;
				for (var i = 0; i < groups.length; i++) {
					var fields = groups[i].fields || [];
					for (var j = 0; j < fields.length; j++) {
						var f = fields[j];
						if (f.name === current) {
							setGroupId(String(groups[i].id));
							return;
						}
						if ('group' === f.type && (f.sub_fields || []).some(function (s) {
							return f.name + '_' + s.name === current;
						})) {
							setGroupId(String(groups[i].id));
							return;
						}
					}
				}
			},
			[ready, attributes.fieldName]
		);

		if (manual || 'error' === cache.state) {
			return el(
				'div',
				null,
				el(
					Tooltip,
					{
						text: __(
							"The sub-field key, read with tk_get_sub_field('name') inside a row loop. Must be unique within its row.",
							'tk-fields'
						),
					},
					el(TextControl, {
						label: __('Field name', 'tk-fields'),
						value: attributes.fieldName || '',
						onChange: function (next) {
							setAttributes({ fieldName: next });
						},
					})
				),
				el(
					Button,
					{ variant: 'link', onClick: function () { setManual(false); } },
					__('Pick from a field group', 'tk-fields')
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
		var fieldOptions = group ? fieldOptionsFor(group) : [];

		return el(
			'div',
			null,
			el(
				Tooltip,
				{
					text: __(
						'Pick the field group first, then the field. Group-type fields expand into their sub-fields.',
						'tk-fields'
					),
				},
				el(SelectControl, {
					label: __('Field group', 'tk-fields'),
					value: groupId,
					options: groupOptions,
					onChange: function (next) {
						setGroupId(next);
					},
				})
			),
			group
				? el(
						Tooltip,
						{
							text: __(
								'The field to display. Sub-fields of group-type fields are shown as "Group label › Sub-field label".',
								'tk-fields'
							),
						},
						el(SelectControl, {
							label: __('Field', 'tk-fields'),
							value: attributes.fieldName || '',
							options: fieldOptions,
							onChange: function (next) {
								var patch = { fieldName: next };
								var opt = fieldOptions.filter(function (o) {
									return o.value === next;
								})[0];
								if (opt && opt.fieldType && supportedFieldType(opt.fieldType)) {
									patch.fieldType = opt.fieldType;
								}
								setAttributes(patch);
							},
						})
					)
				: null,
			!ready
				? el(Spinner)
				: null,
			el(
				Button,
				{ variant: 'link', onClick: function () { setManual(true); } },
				__('Enter field name manually', 'tk-fields')
			)
		);
	}

	function Edit(props) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;

		var blockProps = useBlockProps({
			className: 'tk-field-value-editor tk-field-value--' + (attributes.fieldType || 'text'),
		});

		var label = attributes.fieldName
			? attributes.fieldName
			: __('Unnamed field', 'tk-fields');

		return el(
			element.Fragment,
			null,
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __('Field', 'tk-fields'), initialOpen: true },
					el(FieldPicker, { attributes: attributes, setAttributes: setAttributes }),
					el(
						Tooltip,
						{
							text: __(
								'The value type. Controls which editor control is shown and how the value renders on the frontend.',
								'tk-fields'
							),
						},
						el(SelectControl, {
							label: __('Field type', 'tk-fields'),
							value: attributes.fieldType || 'text',
							options: FIELD_TYPES.map(function (t) {
								return { label: __(t.label, 'tk-fields'), value: t.value };
							}),
							onChange: function (next) {
								setAttributes({ fieldType: next });
							},
						})
					),
					( [ 'select', 'radio', 'button_group' ].indexOf( attributes.fieldType ) !== -1
						? el(
								Tooltip,
								{
									text: __(
										'One option per line, as "value | Label". The value is stored; the label is shown on the frontend.',
										'tk-fields'
									),
								},
								el(TextareaControl, {
									label: __('Options', 'tk-fields'),
									value: attributes.options || '',
									onChange: function (next) {
										setAttributes({ options: next });
									},
									help: __('Example: red | Red', 'tk-fields'),
								})
							)
						: null),
					( 'map' === attributes.fieldType
						? el(
								Tooltip,
								{
									text: __(
										"Opt-in address search for this field's editor UI: a search box that queries the Photon geocoder from the admin's browser (debounced). Off by default — the map canvas and lat/lng/address inputs are always available.",
										'tk-fields'
									),
								},
								el(CheckboxControl, {
									label: __('Enable address search', 'tk-fields'),
									checked: !!attributes.enableSearch,
									onChange: function (next) {
										setAttributes({ enableSearch: !!next });
									},
								})
							)
						: null),
					( 'taxonomy' === attributes.fieldType
						? el(
								Tooltip,
								{
									text: __(
										'Taxonomy slug (e.g. category or post_tag). In v1 the editor shows a checkbox list, indented for hierarchical taxonomies — hierarchical taxonomies like categories suit it best; an autocomplete picker for flat taxonomies like tags is a planned follow-up.',
										'tk-fields'
									),
								},
								el(TextControl, {
									label: __('Taxonomy', 'tk-fields'),
									value: attributes.taxonomy || '',
									onChange: function (next) {
										setAttributes({ taxonomy: next });
									},
									placeholder: 'category',
								})
							)
						: null)
				)
			),
			el(
				'div',
				blockProps,
				el(ValueControl, {
					fieldType: attributes.fieldType || 'text',
					value: attributes.value,
					options: attributes.options,
					taxonomy: attributes.taxonomy,
					label: label,
					enableSearch: !!attributes.enableSearch,
					setValue: function (next) {
						setAttributes({ value: next });
					},
				})
			)
		);
	}

	function Save() {
		// Dynamic block: the frontend markup is produced by render.php, so
		// the block stores no inner content. Returning null (v0.12.1) makes
		// hand-written self-closing markup validate exactly like
		// inserter-produced markup — previously Save returned an empty
		// <div>, which failed validation whenever the stored content was
		// empty (i.e. any hand-typed block).
		return null;
	}

	function DeprecatedSaveV120() {
		// v0.12.0 shape: Save returned an empty <div>, so inserter-created
		// blocks serialized with <div></div> inner content. Kept as a
		// deprecation so those posts keep validating after the fix above.
		return el('div', null);
	}

	registerBlockType('tk/field-value', {
		edit: Edit,
		save: Save,
		deprecated: [
			{
				save: DeprecatedSaveV120,
			},
		],
	});
})(window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.data, window.wp.i18n, window.wp.apiFetch);
