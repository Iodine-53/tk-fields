/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * Field-group renderer JS test — standalone, no build step:
 *
 *   node src/admin/tests/field-group-renderer.js
 *
 * Loads assets/js/field-group-renderer.js in a vm sandbox with stubbed
 * wp.* globals and drives the idempotency/matching contract:
 *
 * - fieldKeyFor() uses the exact createFromFieldDef scheme
 *   ('fr-' + cleanKey(name).slice(0, 48)).
 * - findWrapper() matches by block name + fieldKey, recursively through
 *   innerBlocks; non-matching keys and wrong block names miss.
 * - Full flow: editor-not-ready does nothing; missing tkFieldRepeater
 *   retries on a timer instead of failing; a missing wrapper is appended
 *   once via createFromFieldDef; an existing matching wrapper is never
 *   duplicated; the scan runs once per editor load (no re-insert fights).
 */
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const PLUGIN = '/home/hatch/workspace/wordpress-test/www/wp-content/plugins/tk-fields';
const SRC = fs.readFileSync(path.join(PLUGIN, 'assets/js/field-group-renderer.js'), 'utf8');

// ---------------------------------------------------------------- stubs ---
function makeStubs() {
	const state = {
		postId: 0,
		postType: 'post',
		blocks: [],
		insertCalls: [],
		apiCalls: [],
		apiResponse: { fields: [] },
		timers: [],
		subscribers: [],
		unsubscribed: false,
	};

	const sandbox = {};
	sandbox.window = sandbox;
	sandbox.console = { warn: function () {} };
	sandbox.setTimeout = function (cb) {
		state.timers.push(cb);
		return state.timers.length;
	};

	sandbox.window.wp = {
		blocks: {},
		data: {
			select: function (store) {
				if (store === 'core/editor') {
					return {
						getCurrentPostId: function () { return state.postId; },
						getCurrentPostType: function () { return state.postType; },
					};
				}
				if (store === 'core/block-editor') {
					return {
						getBlocks: function () { return state.blocks; },
					};
				}
				throw new Error('unknown store: ' + store);
			},
			dispatch: function (store) {
				if (store === 'core/block-editor') {
					return {
						insertBlocks: function (block, index) {
							state.insertCalls.push({ block: block, index: index });
						},
					};
				}
				throw new Error('unknown store: ' + store);
			},
			subscribe: function (cb) {
				state.subscribers.push(cb);
				return function () { state.unsubscribed = true; };
			},
		},
		apiFetch: function (opts) {
			state.apiCalls.push(opts && opts.path);
			return Promise.resolve(state.apiResponse);
		},
		i18n: {
			__: function (s) { return s; },
		},
	};

	// Minimal tkFieldRepeater double with the REAL fieldKey scheme.
	sandbox.window.tkFieldRepeater = {
		createFromFieldDef: function (def, opts) {
			const clean = String((def && def.name) || '').replace(/[^a-zA-Z0-9_-]/g, '');
			return {
				name: 'tk/field-repeater',
				attributes: { fieldKey: 'fr-' + clean.slice(0, 48), fieldName: def.name },
				innerBlocks: [],
				_createdWith: { def: def, opts: opts },
			};
		},
	};

	vm.createContext(sandbox);
	vm.runInContext(SRC, sandbox, { filename: 'field-group-renderer.js' });

	return { state: state, renderer: sandbox.window.tkFieldGroupRenderer };
}

function tick(times) {
	let p = Promise.resolve();
	for (let i = 0; i < times; i++) {
		p = p.then(function () {});
	}
	return p;
}

async function main() {
	// ------------------------------------------------- fieldKeyFor scheme ---
	let t = makeStubs();
	assert.strictEqual(t.renderer.fieldKeyFor('course_modules'), 'fr-course_modules', 'plain name');
	assert.strictEqual(t.renderer.fieldKeyFor('a b!c@d'), 'fr-abcd', 'cleanKey strips junk');
	assert.strictEqual(
		t.renderer.fieldKeyFor('x'.repeat(100)),
		'fr-' + 'x'.repeat(48),
		'truncates to 48 chars after the fr- prefix'
	);

	// ------------------------------------------------------- findWrapper ---
	t = makeStubs();
	const F = t.renderer.findWrapper;
	assert.strictEqual(F([], 'fr-a'), null, 'empty tree misses');
	assert.strictEqual(
		F([{ name: 'tk/field-repeater', attributes: { fieldKey: 'fr-a' }, innerBlocks: [] }], 'fr-a').name,
		'tk/field-repeater',
		'top-level match found'
	);
	assert.strictEqual(
		F([{ name: 'tk/field-repeater', attributes: { fieldKey: 'fr-b' }, innerBlocks: [] }], 'fr-a'),
		null,
		'wrong fieldKey misses'
	);
	assert.strictEqual(
		F([{ name: 'core/paragraph', attributes: { fieldKey: 'fr-a' }, innerBlocks: [] }], 'fr-a'),
		null,
		'wrong block name misses even with matching fieldKey'
	);
	assert.strictEqual(
		F(
			[{
				name: 'core/group',
				attributes: {},
				innerBlocks: [{ name: 'tk/field-repeater', attributes: { fieldKey: 'fr-a' }, innerBlocks: [] }],
			}],
			'fr-a'
		).attributes.fieldKey,
		'fr-a',
		'nested match found through innerBlocks'
	);

	// --------------------------------------------- editor not ready: idle ---
	t = makeStubs();
	t.renderer._maybeRun();
	await tick(3);
	assert.strictEqual(t.state.apiCalls.length, 0, 'no fetch before the editor has a post');

	// ------------------------------- tkFieldRepeater missing: timer retry --
	// Rebuild the sandbox without the tkFieldRepeater double to simulate
	// the field-repeater editor script landing late.
	t = makeStubsNoRepeater();
	t.state.postId = 7;
	t.renderer._maybeRun();
	await tick(3);
	assert.strictEqual(t.state.timers.length, 1, 'missing tkFieldRepeater schedules a retry, not a failure');
	assert.strictEqual(t.state.apiCalls.length, 0, 'no fetch until tkFieldRepeater exists');

	// ------------------------------------------ missing wrapper: one insert -
	t = makeStubs();
	t.state.postId = 12;
	t.state.apiResponse = {
		fields: [{ name: 'modules', label: 'Modules', sub_fields: [] }],
	};
	t.renderer._maybeRun();
	await tick(5);
	assert.deepStrictEqual(t.state.apiCalls, ['/tk/v1/post-repeaters/12'], 'fetches repeater defs for the post');
	assert.strictEqual(t.state.insertCalls.length, 1, 'one wrapper inserted');
	assert.strictEqual(t.state.insertCalls[0].block.name, 'tk/field-repeater', 'inserted block is the wrapper');
	assert.strictEqual(t.state.insertCalls[0].block.attributes.fieldKey, 'fr-modules', 'fieldKey matches the scheme');
	assert.strictEqual(t.state.insertCalls[0].index, 0, 'appended at the end of (empty) content');
	assert.strictEqual(t.state.insertCalls[0].block._createdWith.def.name, 'modules', 'def passed through to createFromFieldDef');
	assert.strictEqual(t.state.insertCalls[0].block._createdWith.opts.postId, 12, 'postId passed through');

	// -------------------------------------- existing wrapper: no duplicate -
	t = makeStubs();
	t.state.postId = 12;
	t.state.apiResponse = {
		fields: [{ name: 'modules', label: 'Modules', sub_fields: [] }],
	};
	t.state.blocks = [{ name: 'tk/field-repeater', attributes: { fieldKey: 'fr-modules' }, innerBlocks: [] }];
	t.renderer._maybeRun();
	await tick(5);
	assert.strictEqual(t.state.insertCalls.length, 0, 'matching wrapper present: no insert');

	// --------------------------------- one present, one missing: one insert -
	t = makeStubs();
	t.state.postId = 12;
	t.state.apiResponse = {
		fields: [
			{ name: 'modules', label: 'Modules', sub_fields: [] },
			{ name: 'faq', label: 'FAQ', sub_fields: [] },
		],
	};
	t.state.blocks = [{ name: 'tk/field-repeater', attributes: { fieldKey: 'fr-modules' }, innerBlocks: [] }];
	t.renderer._maybeRun();
	await tick(5);
	assert.strictEqual(t.state.insertCalls.length, 1, 'only the missing field gets a wrapper');
	assert.strictEqual(t.state.insertCalls[0].block.attributes.fieldKey, 'fr-faq', 'the missing one is faq');

	// --------------------------------------- run-once: no re-insert fights --
	t = makeStubs();
	t.state.postId = 12;
	t.state.apiResponse = { fields: [{ name: 'modules', label: 'Modules', sub_fields: [] }] };
	t.renderer._maybeRun();
	await tick(5);
	assert.strictEqual(t.state.apiCalls.length, 1, 'first run fetches');
	// Simulate the author removing the wrapper mid-session, then a re-run:
	t.state.blocks = [];
	t.renderer._maybeRun();
	await tick(5);
	assert.strictEqual(t.state.apiCalls.length, 1, 'second run does nothing: never fights the user');
	assert.strictEqual(t.state.insertCalls.length, 1, 'no re-insert after removal');

	// ----------------------------------------------- site editor: bail out --
	t = makeStubs();
	t.state.postId = 99;
	t.state.postType = 'wp_template';
	t.renderer._maybeRun();
	await tick(3);
	assert.strictEqual(t.state.apiCalls.length, 0, 'template post types are ignored');

	console.log('\nALL PASS\n');
}

// Variant of makeStubs without the tkFieldRepeater double (late script).
function makeStubsNoRepeater() {
	const state = {
		postId: 0,
		postType: 'post',
		blocks: [],
		insertCalls: [],
		apiCalls: [],
		apiResponse: { fields: [] },
		timers: [],
		subscribers: [],
		unsubscribed: false,
	};
	const sandbox = {};
	sandbox.window = sandbox;
	sandbox.console = { warn: function () {} };
	sandbox.setTimeout = function (cb) { state.timers.push(cb); return state.timers.length; };
	sandbox.window.wp = {
		blocks: {},
		data: {
			select: function (store) {
				if (store === 'core/editor') {
					return {
						getCurrentPostId: function () { return state.postId; },
						getCurrentPostType: function () { return state.postType; },
					};
				}
				if (store === 'core/block-editor') {
					return { getBlocks: function () { return state.blocks; } };
				}
				throw new Error('unknown store: ' + store);
			},
			dispatch: function () { throw new Error('no dispatch expected'); },
			subscribe: function (cb) { state.subscribers.push(cb); return function () {}; },
		},
		apiFetch: function (opts) { state.apiCalls.push(opts && opts.path); return Promise.resolve(state.apiResponse); },
		i18n: { __: function (s) { return s; } },
	};
	// NOTE: no window.tkFieldRepeater here.
	vm.createContext(sandbox);
	vm.runInContext(SRC, sandbox, { filename: 'field-group-renderer.js' });
	return { state: state, renderer: sandbox.window.tkFieldGroupRenderer };
}

main().catch(function (err) {
	console.error('FAIL:', err && err.message);
	if (err && err.stack) {
		console.error(err.stack.split('\n').slice(1, 4).join('\n'));
	}
	process.exit(1);
});
