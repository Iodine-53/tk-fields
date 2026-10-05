/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * Smoke test: boot the built TK Fields admin bundle inside jsdom with the
 * real @wordpress/* packages standing in for the wp.* script handles, and a
 * stubbed fetch layer. Clicks through: list (empty) -> Add New -> editor
 * tabs -> type picker -> add field -> validation -> save payload.
 */
const { JSDOM } = require( '/home/hatch/workspace/wordpress-test/www/wp-content/plugins/tk-fields/src/admin/node_modules/jsdom' );
const Module = require( 'module' );

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
// react-jsx-runtime is a WP core script handle (window.ReactJSXRuntime).
dom.window.ReactJSXRuntime = req( 'react/jsx-runtime' );

// ---- localized config ----
dom.window.TK_FIELDS_ADMIN = {
	root: 'http://localhost/wp-json/tk/v1',
	nonce: 'test-nonce',
	adminUrl: 'http://localhost/wp-admin/',
};

// ---- stubbed REST ----
const calls = [];
const fieldTypes = {
	text: { label: 'Text', description: 'A single line of text.', settings: [
		{ key: 'maxlength', label: 'Max length', tooltip: 'Max chars.', control: 'number' },
	] },
	select: { label: 'Select', description: 'A dropdown.', settings: [] },
	checkbox: { label: 'Checkbox', description: 'On/off.', settings: [
		{ key: 'ui_on', label: 'UI on text', control: 'text' },
	] },
};
let savedPayload = null;
global.fetch = async ( url, opts = {} ) => {
	calls.push( { url, method: opts.method || 'GET' } );
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
		return json( { post: { slug: 'post', name: 'Posts' }, page: { slug: 'page', name: 'Pages' } } );
	}
	if ( url.endsWith( '/wp-json/tk/v1/groups' ) && opts.method === 'POST' ) {
		savedPayload = JSON.parse( opts.body );
		return json( { group: { ...savedPayload, id: 7 } }, 201 );
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

// ---- boot the bundle ----
require( BUILD );
dom.window.document.dispatchEvent( new dom.window.Event( 'DOMContentLoaded' ) );

( async () => {
	await tick( 150 );

	assert( text().includes( 'No field groups yet' ), 'empty state renders' );

	// Add New -> editor
	const addBtn = [ ...document.querySelectorAll( 'button' ) ].find( ( b ) =>
		b.textContent.trim() === 'Add New'
	);
	assert( !! addBtn, 'Add New button present' );
	await click( addBtn );
	await tick( 100 );
	assert( calls.some( ( c ) => c.url.endsWith( '/field-types' ) ), 'GET /field-types issued' );
	assert( text().includes( 'New field group title' ), 'editor header renders' );
	assert( text().includes( 'Add Your First Field' ), 'fields tab empty state' );
	for ( const t of [ 'Fields', 'Location', 'Presentation', 'Help' ] ) {
		assert( text().includes( t ), `tab "${ t }" present` );
	}

	// Help tab content
	const helpTab = [ ...document.querySelectorAll( '[role="tab"]' ) ].find( ( b ) =>
		b.textContent.trim() === 'Help'
	);
	await click( helpTab );
	await tick( 60 );
	assert( text().includes( 'tk_get_field' ), 'help shows tk_get_field snippet' );
	assert( text().includes( 'Migrating from ACF' ), 'help shows ACF migration notes' );
	assert( text().includes( 'TK_FIELDS_ACF_COMPAT' ), 'help shows shim opt-in constant' );

	// Back to Fields tab, open type picker
	const fieldsTab = [ ...document.querySelectorAll( '[role="tab"]' ) ].find( ( b ) =>
		b.textContent.trim() === 'Fields'
	);
	await click( fieldsTab );
	await tick( 60 );
	const addFieldBtn = [ ...document.querySelectorAll( 'button' ) ].find( ( b ) =>
		b.textContent.includes( 'Add Your First Field' ) ||
		b.textContent.includes( 'Add Field' )
	);
	await click( addFieldBtn );
	await tick( 60 );
	// Picker search matches type descriptions (they feed the search index).
	const pickerSearch = document.querySelector( '.tkf-type-picker input' );
	const searchSetter = Object.getOwnPropertyDescriptor(
		dom.window.HTMLInputElement.prototype,
		'value'
	).set;
	searchSetter.call( pickerSearch, 'single line' );
	pickerSearch.dispatchEvent( new dom.window.Event( 'input', { bubbles: true } ) );
	await tick( 60 );
	assert(
		[ ...document.querySelectorAll( '.tkf-type-tile' ) ].some(
			( t ) =>
				t.querySelector( '.tkf-type-tile__label' )?.textContent ===
				'Text'
		),
		'type picker search matches type descriptions'
	);
	searchSetter.call( pickerSearch, '' );
	pickerSearch.dispatchEvent( new dom.window.Event( 'input', { bubbles: true } ) );
	await tick( 60 );

	// Pick Text type -> card appears, expanded
	const pickText = [ ...document.querySelectorAll( '.tkf-type-tile' ) ].find( ( b ) =>
		b.textContent.includes( 'Text' )
	);
	await click( pickText );
	await tick( 80 );
	assert( document.querySelectorAll( '.tkf-field-row' ).length === 1, 'field card added' );
	assert( text().includes( 'Field name' ), 'field settings expanded' );
	assert( text().includes( 'Max length' ), 'generic schema setting rendered (number control)' );

	// Conditional logic builder present
	assert( text().includes( 'Conditional logic' ), 'conditional logic builder present' );

	// Location tab
	const locTab = [ ...document.querySelectorAll( '[role="tab"]' ) ].find( ( b ) =>
		b.textContent.trim() === 'Location'
	);
	await click( locTab );
	await tick( 80 );
	assert( text().includes( 'Match all rules' ), 'location match toggle present' );
	const addRule = [ ...document.querySelectorAll( 'button' ) ].find( ( b ) =>
		b.textContent.trim() === '+ Add rule'
	);
	await click( addRule );
	await tick( 60 );
	assert( document.querySelectorAll( '.tkf-rule-row' ).length === 1, 'location rule row added' );

	// Presentation tab
	const presTab = [ ...document.querySelectorAll( '[role="tab"]' ) ].find( ( b ) =>
		b.textContent.trim() === 'Presentation'
	);
	await click( presTab );
	await tick( 60 );
	assert( text().includes( 'Exclude from AI export' ), 'exclude_from_ai toggle present' );

	// Save with empty title -> validation error notice, no POST.
	// NOTE: the failed save remounts the tab panel, so re-query tab nodes.
	await click( [ ...document.querySelectorAll( '[role="tab"]' ) ].find( ( b ) =>
		b.textContent.trim() === 'Fields'
	) );
	await tick( 60 );
	const saveBtn = [ ...document.querySelectorAll( '.tkf-editor__save button' ) ][ 0 ];
	await click( saveBtn );
	await tick( 80 );
	assert( ! calls.some( ( c ) => c.method === 'POST' ), 'no POST on invalid form' );
	assert( text().includes( 'needs a title' ) || text().includes( 'problems need fixing' ), 'validation notice shown' );

	// Fill title + field label/name via React-safe input simulation
	const setInput = async ( input, value ) => {
		const proto = input.tagName === 'TEXTAREA'
			? dom.window.HTMLTextAreaElement.prototype
			: dom.window.HTMLInputElement.prototype;
		const setter = Object.getOwnPropertyDescriptor( proto, 'value' ).set;
		setter.call( input, value );
		input.dispatchEvent( new dom.window.Event( 'input', { bubbles: true } ) );
		await tick( 30 );
	};
	const titleInput = document.querySelector( '.tkf-editor__title input' );
	await setInput( titleInput, 'Event details' );
	// label is the first text input inside the expanded card body
	const cardBody = document.querySelector( '.tkf-field-row__body' );
	const labelInput = cardBody.querySelectorAll( 'input[type="text"]' )[ 0 ];
	await setInput( labelInput, 'Hero Price' );
	await tick( 30 );
	assert( text().includes( "tk_get_field( 'hero_price' )" ), 'auto-slug generated hero_price' );

	await click( saveBtn );
	await tick( 120 );
	assert( calls.some( ( c ) => c.method === 'POST' && c.url.endsWith( '/tk/v1/groups' ) ), 'POST /groups issued' );
	assert( savedPayload && savedPayload.title === 'Event details', 'payload has title' );
	assert( savedPayload.fields[ 0 ].name === 'hero_price', 'payload field name slugged' );
	assert(
		savedPayload.fields[ 0 ].key.startsWith( 'f_' ) &&
		! ( '_errors' in savedPayload.fields[ 0 ] ) &&
		! ( '_nameTouched' in savedPayload.fields[ 0 ] ),
		'payload key stable, UI-only keys stripped'
	);
	assert( text().includes( 'Field group created.' ), 'success notice shown' );

	console.log( '\nDone. exitCode=' + ( process.exitCode || 0 ) );
} )().catch( ( e ) => {
	console.error( 'SMOKE ERROR:', e );
	process.exitCode = 2;
} );
