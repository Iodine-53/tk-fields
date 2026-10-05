/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * Repeater builder-UI test: boots the built TK Fields admin bundle inside
 * jsdom (same harness as smoke.js) with a /field-types stub mirroring the
 * real PHP schema for the repeater type, then clicks through:
 *
 *  - type picker search matches the new aliases ("repeatable", "rows")
 *  - the repeater tile shows the block-editor-only badge + dashicons-editor-table icon
 *  - picking it renders every repeater setting, each with its tooltip help
 *    button (minimum/maximum rows, add-row button label, row layout
 *    list/grid select, row summary field select, sub-fields editor)
 *  - the "Row summary field" select options derive LIVE from the field's
 *    current sub-fields (group's dynamic pattern): they update when
 *    sub-fields are added via the sub-fields editor
 */
const { JSDOM } = require( '/home/hatch/workspace/wordpress-test/www/wp-content/plugins/tk-fields/src/admin/node_modules/jsdom' );

const ADMIN = '/home/hatch/workspace/wordpress-test/www/wp-content/plugins/tk-fields/src/admin';
const BUILD = '/home/hatch/workspace/wordpress-test/www/wp-content/plugins/tk-fields/assets/admin/build/index.js';
const req = ( p ) => require( ADMIN + '/node_modules/' + p );

const dom = new JSDOM(
	'<!DOCTYPE html><html><body><div id="tk-fields-admin-root"></div></body></html>',
	{ url: 'http://localhost/wp-admin/admin.php?page=tk-fields', pretendToBeVisual: true }
);

// ---- globals the bundle / components expect ----
global.window = dom.window;
global.document = dom.window.document;
global.navigator = dom.window.navigator;
global.HTMLElement = dom.window.HTMLElement;
global.Element = dom.window.Element;
global.Node = dom.window.Node;
global.Event = dom.window.Event;
global.MouseEvent = dom.window.MouseEvent;
global.KeyboardEvent = dom.window.KeyboardEvent;
global.getComputedStyle = dom.window.getComputedStyle.bind( dom.window );
global.MutationObserver = dom.window.MutationObserver;
global.requestAnimationFrame = ( cb ) => setTimeout( cb, 0 );
global.cancelAnimationFrame = ( id ) => clearTimeout( id );
dom.window.requestAnimationFrame = global.requestAnimationFrame;
dom.window.matchMedia = dom.window.matchMedia || ( () => ( {
	matches: false, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {},
} ) );
global.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
global.IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
dom.window.ResizeObserver = global.ResizeObserver;
dom.window.IntersectionObserver = global.IntersectionObserver;
if ( ! dom.window.Element.prototype.scrollIntoView ) {
	dom.window.Element.prototype.scrollIntoView = () => {};
}

// ---- wp.* externals: the real packages ----
dom.window.wp = {
	element: req( '@wordpress/element' ),
	components: req( '@wordpress/components' ),
	i18n: req( '@wordpress/i18n' ),
	compose: req( '@wordpress/compose' ),
	primitives: req( '@wordpress/primitives' ),
};
dom.window.ReactJSXRuntime = req( 'react/jsx-runtime' );

// ---- localized config ----
dom.window.TK_FIELDS_ADMIN = {
	root: 'http://localhost/wp-json/tk/v1',
	nonce: 'test-nonce',
	adminUrl: 'http://localhost/wp-admin/',
};

// ---- test double for the classic vanilla sub-fields editor ----
// (the real one is registered on window by the classic editor script;
// here we add sub-fields with fixed names so the collapsed-select
// derivation can be exercised)
dom.window.TKFSubFieldsEditor = function TestSubFieldsEditor( { value, onChange } ) {
	const h = dom.window.wp.element.createElement;
	const Button = dom.window.wp.components.Button;
	const rows = Array.isArray( value ) ? value : [];
	return h(
		'div',
		{ className: 'test-subfields-editor' },
		h( 'p', null, rows.length + ' sub-field(s)' ),
		h(
			Button,
			{
				variant: 'secondary',
				onClick: () =>
					onChange( [
						...rows,
						rows.length === 0
							? { key: 'sf1', name: 'title', label: 'Title', type: 'text' }
							: { key: 'sf2', name: 'hours', label: 'Hours', type: 'number' },
					] ),
			},
			'Add test sub-field'
		)
	);
};

// ---- stubbed REST: /field-types mirrors the real PHP schema ----
const fieldTypes = {
	text: {
		label: 'Text',
		description: 'A single line of text.',
		category: 'basic',
		icon: 'text-size',
		aliases: [],
		settings: [],
	},
	repeater: {
		label: 'Repeater',
		description:
			'Repeatable rows of sub-fields. Editors add, reorder and remove rows; each row holds the same set of sub-fields. Rows live as blocks in post content. Requires the block editor.',
		category: 'layout',
		icon: 'editor-table',
		aliases: [ 'repeater', 'repeatable', 'rows' ],
		block_editor_only: true,
		settings: [
			{ key: 'label', label: 'Label', tooltip: 'Shown above the field in the editor.', control: 'text' },
			{ key: 'name', label: 'Name', tooltip: 'Machine name: lowercase letters, digits, underscores.', control: 'text' },
			{ key: 'instructions', label: 'Instructions', tooltip: 'Helper text shown under the label.', control: 'text' },
			{ key: 'required', label: 'Required', tooltip: 'At least one row must be added.', control: 'toggle' },
			{ key: 'min', label: 'Minimum rows', tooltip: 'Fewest rows the editor may leave. Save is blocked below this.', control: 'number' },
			{ key: 'max', label: 'Maximum rows', tooltip: 'Most rows allowed. The add-row button disables at this count.', control: 'number' },
			{ key: 'button_label', label: 'Add-row button label', tooltip: 'Text of the "add row" button. Leave empty for the default "Add Row".', control: 'text' },
			{
				key: 'layout',
				label: 'Row layout',
				tooltip: 'How rows are arranged in the editor: stacked list or grid.',
				control: 'select',
				options: { list: 'List', grid: 'Grid' },
			},
			{
				key: 'collapsed',
				label: 'Row summary field',
				tooltip: "Which sub-field's value is shown as the collapsed row summary. Summaries are derived live, never stored.",
				control: 'select',
			},
			{
				key: 'sub_fields',
				label: 'Sub-fields',
				tooltip: 'The sub-fields inside every row. Each row holds the same set of sub-fields; add, reorder and remove them here.',
				control: 'subfields',
			},
		],
	},
};
global.fetch = async ( url, opts = {} ) => {
	const json = ( data, status = 200 ) => ( {
		ok: status >= 200 && status < 300, status,
		json: async () => data,
	} );
	if ( url.endsWith( '/wp-json/tk/v1/groups' ) && ( opts.method || 'GET' ) === 'GET' ) {
		return json( { groups: [] } );
	}
	if ( url.endsWith( '/wp-json/tk/v1/field-types' ) ) {
		return json( { types: fieldTypes } );
	}
	if ( url.includes( '/wp/v2/types' ) ) {
		return json( { post: { slug: 'post', name: 'Posts' } } );
	}
	return json( { code: 'not_found', message: 'stub: ' + url }, 404 );
};
dom.window.fetch = global.fetch;

// React 18 deprecation noise -> silence
const origError = console.error;
console.error = ( ...a ) => {
	if ( typeof a[0] === 'string' && a[0].includes( 'ReactDOM.render' ) ) return;
	origError( ...a );
};

const tick = ( ms = 30 ) => new Promise( ( r ) => setTimeout( r, ms ) );
const text = () => document.getElementById( 'tk-fields-admin-root' ).textContent;
const assert = ( cond, name ) => {
	console.log( ( cond ? 'PASS' : 'FAIL' ) + ' — ' + name );
	if ( ! cond ) process.exitCode = 1;
};
const click = async ( el ) => {
	el.dispatchEvent( new dom.window.MouseEvent( 'click', { bubbles: true, cancelable: true } ) );
	await tick();
};
const setInput = async ( input, value ) => {
	const proto = input.tagName === 'TEXTAREA'
		? dom.window.HTMLTextAreaElement.prototype
		: dom.window.HTMLInputElement.prototype;
	const setter = Object.getOwnPropertyDescriptor( proto, 'value' ).set;
	setter.call( input, value );
	input.dispatchEvent( new dom.window.Event( 'input', { bubbles: true } ) );
	await tick( 40 );
};

// The TKControl wrapper for a setting label — must carry a tooltip help btn.
const controlHasHelp = ( labelText ) => {
	const label = [ ...document.querySelectorAll( '.tkf-control__label' ) ].find(
		( l ) => l.textContent.trim().startsWith( labelText )
	);
	if ( ! label ) return false;
	const control = label.closest( '.tkf-control' );
	return !! ( control && control.querySelector( '.tkf-control__help-btn' ) );
};

// The <select> rendered for a setting label.
const selectFor = ( labelText ) => {
	const label = [ ...document.querySelectorAll( '.tkf-control__label' ) ].find(
		( l ) => l.textContent.trim().startsWith( labelText )
	);
	const control = label && label.closest( '.tkf-control' );
	return control ? control.querySelector( 'select' ) : null;
};

// ---- boot the bundle ----
require( BUILD );
dom.window.document.dispatchEvent( new dom.window.Event( 'DOMContentLoaded' ) );

( async () => {
	await tick( 150 );

	// Add New -> editor -> Add Field -> type picker
	const addBtn = [ ...document.querySelectorAll( 'button' ) ].find( ( b ) =>
		b.textContent.trim() === 'Add New'
	);
	await click( addBtn );
	await tick( 100 );
	const addFieldBtn = [ ...document.querySelectorAll( 'button' ) ].find( ( b ) =>
		b.textContent.includes( 'Add Your First Field' ) ||
		b.textContent.includes( 'Add Field' )
	);
	assert( !! addFieldBtn, 'add-field button present' );
	await click( addFieldBtn );
	await tick( 60 );
	assert(
		!! document.querySelector( '.tkf-type-picker' ),
		'type picker modal opens'
	);

	const searchInput = () => document.querySelector( '.tkf-type-picker input' );
	const repeaterTile = () =>
		[ ...document.querySelectorAll( '.tkf-type-tile' ) ].find( ( t ) =>
			t.querySelector( '.tkf-type-tile__label' )?.textContent === 'Repeater'
		);

	// Alias search: "repeatable" and "rows" must surface the repeater tile.
	await setInput( searchInput(), 'repeatable' );
	assert( !! repeaterTile(), 'alias search "repeatable" surfaces the repeater tile' );
	await setInput( searchInput(), 'rows' );
	assert( !! repeaterTile(), 'alias search "rows" surfaces the repeater tile' );
	await setInput( searchInput(), 'repeater' );
	const tile = repeaterTile();
	assert( !! tile, 'search "repeater" surfaces the repeater tile' );

	// Tile badge + icon glyph.
	assert(
		tile.textContent.includes( 'Block editor only' ),
		'repeater tile shows the block-editor-only badge'
	);
	const iconSpan = tile.querySelector( '.tkf-type-tile__icon' );
	assert(
		!! iconSpan &&
			iconSpan.classList.contains( 'dashicons-editor-table' ) &&
			iconSpan.classList.contains( 'dashicons' ),
		'repeater tile uses the dashicons-editor-table glyph'
	);

	// Layout tab also lists it (category = layout).
	await setInput( searchInput(), '' );
	await tick( 40 );
	const layoutTab = [ ...document.querySelectorAll( '.tkf-type-picker [role="tab"]' ) ].find(
		( b ) => b.textContent.trim() === 'Layout'
	);
	await click( layoutTab );
	await tick( 60 );
	assert( !! repeaterTile(), 'repeater tile appears under the Layout category tab' );

	// Pick it -> expanded card with the repeater settings.
	await click( repeaterTile() );
	await tick( 100 );
	assert(
		document.querySelectorAll( '.tkf-field-row' ).length === 1,
		'repeater field card added'
	);
	for ( const label of [ 'Add-row button label', 'Row layout', 'Row summary field', 'Sub-fields' ] ) {
		assert( text().includes( label ), `settings tab shows "${ label }"` );
	}
	for ( const label of [ 'Minimum rows', 'Maximum rows' ] ) {
		const rulesTab = [ ...document.querySelectorAll( '[role="tab"]' ) ].find(
			( b ) => b.textContent.trim() === 'Rules'
		);
		await click( rulesTab );
		await tick( 60 );
		assert( text().includes( label ), `rules tab shows "${ label }"` );
		// back to Settings for the tooltip/select assertions below
		const settingsTab = [ ...document.querySelectorAll( '[role="tab"]' ) ].find(
			( b ) => b.textContent.trim() === 'Settings'
		);
		await click( settingsTab );
		await tick( 60 );
	}

	// Every repeater control is wrapped with a tooltip help button.
	for ( const label of [
		'Add-row button label',
		'Row layout',
		'Row summary field',
		'Sub-fields',
		'Minimum rows',
		'Maximum rows',
	] ) {
		// Rules-tab controls are checked while the Rules tab is active.
		if ( label === 'Minimum rows' || label === 'Maximum rows' ) {
			const rulesTab = [ ...document.querySelectorAll( '[role="tab"]' ) ].find(
				( b ) => b.textContent.trim() === 'Rules'
			);
			await click( rulesTab );
			await tick( 60 );
		} else {
			const settingsTab = [ ...document.querySelectorAll( '[role="tab"]' ) ].find(
				( b ) => b.textContent.trim() === 'Settings'
			);
			await click( settingsTab );
			await tick( 60 );
		}
		assert( controlHasHelp( label ), `control "${ label }" has a tooltip help button` );
	}
	const settingsTab = [ ...document.querySelectorAll( '[role="tab"]' ) ].find(
		( b ) => b.textContent.trim() === 'Settings'
	);
	await click( settingsTab );
	await tick( 60 );

	// Row layout select: list | grid.
	const layoutSelect = selectFor( 'Row layout' );
	const layoutValues = layoutSelect
		? [ ...layoutSelect.options ].map( ( o ) => o.value )
		: [];
	assert(
		layoutValues.includes( 'list' ) && layoutValues.includes( 'grid' ),
		'row layout select offers list and grid'
	);

	// Row summary select: dynamic options — starts with the default only.
	const collapsedSelect = selectFor( 'Row summary field' );
	const collapsedLabels = () =>
		[ ...selectFor( 'Row summary field' ).options ].map( ( o ) => o.textContent );
	assert(
		JSON.stringify( collapsedLabels() ) === JSON.stringify( [ 'First field (default)' ] ),
		'row summary select starts with the "First field (default)" option only'
	);
	assert(
		!! collapsedSelect,
		'row summary control renders a select'
	);

	// Add sub-fields via the sub-fields editor -> options derive live.
	const addSub = [ ...document.querySelectorAll( '.test-subfields-editor button' ) ].find(
		( b ) => b.textContent.includes( 'Add test sub-field' )
	);
	assert( !! addSub, 'sub-fields editor renders for the repeater' );
	await click( addSub );
	await tick( 60 );
	await click( addSub );
	await tick( 60 );
	assert(
		JSON.stringify( collapsedLabels() ) ===
			JSON.stringify( [ 'First field (default)', 'Title (title)', 'Hours (hours)' ] ),
		'row summary options derive live from sub-fields: Title (title), Hours (hours)'
	);

	// Picking a summary field persists on the field.
	const setter = Object.getOwnPropertyDescriptor(
		dom.window.HTMLSelectElement.prototype,
		'value'
	).set;
	setter.call( selectFor( 'Row summary field' ), 'title' );
	selectFor( 'Row summary field' ).dispatchEvent(
		new dom.window.Event( 'change', { bubbles: true } )
	);
	await tick( 60 );
	assert(
		selectFor( 'Row summary field' ).value === 'title',
		'row summary field value persists on the field'
	);

	console.log( '\nDone. exitCode=' + ( process.exitCode || 0 ) );
} )().catch( ( e ) => {
	console.error( 'REPEATER-UI ERROR:', e );
	process.exitCode = 2;
} );
