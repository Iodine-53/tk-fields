/*
 * This file is part of TK Fields.
 *
 * TK Fields is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * See LICENSE in the plugin root for the full license text.
 */

/**
 * Field-group renderer (v0.20.1).
 *
 * The missing UI half of the repeater field type: `tk/field-repeater` is
 * deliberately hidden from the inserter (`supports.inserter: false`) and has
 * no editable field-name UI, so nothing the author does can orphan stored
 * rows (see the repeater synthesis, Q1). This script is the sanctioned
 * insertion path — the "field-group renderer" the synthesis assumed.
 *
 * On editor init it asks the server which repeater fields the current post's
 * assigned field groups contain (location rules are evaluated server-side,
 * via `tk/v1/post-repeaters/<post_id>`), then for each top-level repeater
 * field with no matching wrapper in the block tree it appends one wrapper
 * built by `window.tkFieldRepeater.createFromFieldDef()` — the same entry
 * point the console call uses. Block creation is never reimplemented here.
 *
 * Rules (all deliberate):
 * - Exactly one wrapper per repeater field: the tree is scanned for the
 *   canonical fieldKey before every insert, so reloads never duplicate.
 * - The scan runs ONCE per editor load. If the author removes a wrapper
 *   mid-session it is NOT re-inserted — the renderer never fights the user.
 * - Top-level repeater fields only. Nested repeaters are created through
 *   the row UI, never by this script.
 * - Post editor only. Term/user screens and the site/widgets editors bail
 *   out (the repeater is block_editor_only).
 *
 * Classic wp.* globals only — no build step, per the Blocks convention.
 */
(function (blocks, data, apiFetch, i18n) {
	'use strict';

	var BLOCK_NAME = 'tk/field-repeater';
	var REST_PATH = '/tk/v1/post-repeaters/';
	var MAX_ATTEMPTS = 40; // ~12s of retries waiting for tkFieldRepeater.

	/**
	 * Machine-key cleanup — the exact scheme used by
	 * window.tkFieldRepeater.createFromFieldDef (blocks/field-repeater/edit.js).
	 *
	 * @param {string} s Raw field name.
	 * @return {string} Key-safe string.
	 */
	function cleanKey(s) {
		return String(s || '').replace(/[^a-zA-Z0-9_-]/g, '');
	}

	/**
	 * The canonical wrapper identity for a repeater field. Must stay in
	 * lockstep with createFromFieldDef's `'fr-' + cleanKey(name).slice(0, 48)`.
	 *
	 * @param {string} name Field name.
	 * @return {string} Expected fieldKey attribute value.
	 */
	function fieldKeyFor(name) {
		return 'fr-' + cleanKey(name).slice(0, 48);
	}

	/**
	 * Depth-first scan of a block list (including innerBlocks) for a
	 * tk/field-repeater whose fieldKey matches.
	 *
	 * @param {Array}  blockList   Blocks from getBlocks().
	 * @param {string} expectedKey The canonical fieldKey.
	 * @return {Object|null} The matching block, or null.
	 */
	function findWrapper(blockList, expectedKey) {
		for (var i = 0; i < blockList.length; i++) {
			var b = blockList[i];
			if (!b) {
				continue;
			}
			if (
				b.name === BLOCK_NAME &&
				b.attributes &&
				b.attributes.fieldKey === expectedKey
			) {
				return b;
			}
			var inner = b.innerBlocks || [];
			if (inner.length) {
				var found = findWrapper(inner, expectedKey);
				if (found) {
					return found;
				}
			}
		}
		return null;
	}

	/**
	 * True once the post editor has a real post loaded. False in the
	 * site/widgets editors (no usable core/editor post) and for template
	 * post types.
	 *
	 * @return {boolean}
	 */
	function editorReady() {
		var editorSelect;
		try {
			editorSelect = data.select('core/editor');
		} catch (e) {
			return false;
		}
		if (!editorSelect || !editorSelect.getCurrentPostId) {
			return false;
		}
		var postId;
		try {
			postId = editorSelect.getCurrentPostId();
		} catch (e) {
			return false;
		}
		if (!postId || postId <= 0) {
			return false;
		}
		var postType = null;
		try {
			postType = editorSelect.getCurrentPostType
				? editorSelect.getCurrentPostType()
				: null;
		} catch (e) {
			postType = null;
		}
		if (postType && String(postType).indexOf('wp_') === 0) {
			return false; // Templates, template parts, global styles…
		}
		return true;
	}

	var didRun = false;
	var attempts = 0;
	var unsubscribe = null;

	function currentPostId() {
		try {
			return data.select('core/editor').getCurrentPostId();
		} catch (e) {
			return 0;
		}
	}

	/**
	 * Insert wrappers for every repeater field missing from the tree.
	 * Best-effort: failures never break the editor.
	 */
	function run() {
		var postId = currentPostId();
		if (!postId || postId <= 0) {
			return;
		}
		var blockEditorSelect = data.select('core/block-editor');
		var blockEditorDispatch = data.dispatch('core/block-editor');
		if (
			!blockEditorSelect ||
			!blockEditorSelect.getBlocks ||
			!blockEditorDispatch ||
			!blockEditorDispatch.insertBlocks
		) {
			return;
		}

		apiFetch({ path: REST_PATH + encodeURIComponent(String(postId)) }).then(
			function (res) {
				var fields = (res && res.fields) || [];
				if (!fields.length) {
					return;
				}
				// Snapshot once; keep it in sync as we insert so two defs
				// can never collide on the same fieldKey.
				var existing = blockEditorSelect.getBlocks() || [];
				fields.forEach(function (def) {
					if (!def || !def.name) {
						return;
					}
					var key = fieldKeyFor(def.name);
					if (findWrapper(existing, key)) {
						return; // Already present — never duplicate.
					}
					var block = window.tkFieldRepeater.createFromFieldDef(def, {
						postId: postId,
					});
					if (!block) {
						return;
					}
					var at = (data.select('core/block-editor').getBlocks() || []).length;
					blockEditorDispatch.insertBlocks(block, at);
					existing = existing.concat([block]);
				});
			}
		).catch(function () {
			/* The renderer is best-effort; a failed fetch must never break the editor. */
		});
	}

	function maybeRun() {
		if (didRun) {
			return;
		}
		if (!editorReady()) {
			return;
		}
		if (
			!window.tkFieldRepeater ||
			!window.tkFieldRepeater.createFromFieldDef
		) {
			// The field-repeater editor script hasn't landed yet — retry on
			// a timer (the store may be quiet by now, so don't rely on
			// subscribe alone).
			attempts++;
			if (attempts < MAX_ATTEMPTS) {
				setTimeout(maybeRun, 300);
			} else if (window.console && window.console.warn) {
				window.console.warn(
					i18n.__(
						'TK Fields: field-repeater block script not loaded; repeater wrappers were not auto-inserted.',
						'tk-fields'
					)
				);
			}
			return;
		}
		didRun = true;
		if (unsubscribe) {
			try {
				unsubscribe();
			} catch (e) {}
			unsubscribe = null;
		}
		run();
	}

	// Test seam: pure helpers plus the boot entry point.
	window.tkFieldGroupRenderer = {
		BLOCK_NAME: BLOCK_NAME,
		fieldKeyFor: fieldKeyFor,
		findWrapper: findWrapper,
		_reset: function () {
			didRun = false;
			attempts = 0;
		},
		_maybeRun: maybeRun,
	};

	unsubscribe = data.subscribe(maybeRun);
	// The editor may already be ready before we subscribed.
	maybeRun();
})(
	window.wp.blocks,
	window.wp.data,
	window.wp.apiFetch,
	window.wp.i18n
);
