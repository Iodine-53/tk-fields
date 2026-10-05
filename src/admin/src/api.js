/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * REST client for the TK Fields admin app.
 *
 * The PHP worker implements the tk/v1 namespace. Config arrives via
 * `window.TK_FIELDS_ADMIN` (wp_add_inline_script, 'before' the bundle):
 *   { root: "<rest_url>/tk/v1", wpRoot: "<rest_url>/wp/v2", nonce: "<wp_rest nonce>", adminUrl: "<admin_url>" }
 */

const config = ( typeof window !== 'undefined' && window.TK_FIELDS_ADMIN ) || {};
export const API_ROOT = config.root || '/wp-json/tk/v1';
export const WP_API_ROOT = config.wpRoot || '/wp-json/wp/v2';
export const API_NONCE = config.nonce || '';

function request( path, { method = 'GET', body } = {} ) {
	// Absolute URLs are used as-is: API_ROOT may already carry a query
	// string (?rest_route=...) on plain-permalink sites, where naive
	// concatenation would produce a second '?'.
	const url = /^https?:\/\//i.test( path ) ? path : API_ROOT + path;
	return fetch( url, {
		method,
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': API_NONCE,
		},
		body: body !== undefined ? JSON.stringify( body ) : undefined,
	} ).then( async ( res ) => {
		let data = {};
		try {
			data = await res.json();
		} catch ( e ) {
			// Non-JSON error page — fall through to the generic message.
		}
		if ( ! res.ok ) {
			const message =
				( data && ( data.message || data.code ) ) ||
				`Request failed with status ${ res.status }`;
			const err = new Error( message );
			err.status = res.status;
			err.data = data;
			throw err;
		}
		return data;
	} );
}

export const api = {
	listGroups: () => request( '/groups' ),
	getGroup: ( id ) => request( `/groups/${ id }` ),
	createGroup: ( group ) => request( '/groups', { method: 'POST', body: group } ),
	updateGroup: ( id, group ) => request( `/groups/${ id }`, { method: 'PUT', body: group } ),
	deleteGroup: ( id ) => request( `/groups/${ id }`, { method: 'DELETE' } ),
	getFieldTypes: () => request( '/field-types' ),
	importAcf: () => request( '/import-acf', { method: 'POST' } ),
	// v0.20.1: AI transformer prompt for the no-ACF import path.
	acfAiPrompt: () => request( '/import-acf/ai-prompt' ),
	// v0.17.0: AI import (template + validate + confirm).
	aiImportTemplate: () => request( '/ai-import/template' ),
	aiImportValidate: ( json ) => request( '/ai-import/validate', { method: 'POST', body: { json } } ),
	aiImportConfirm: ( payload, conflict ) =>
		request( '/ai-import/confirm', { method: 'POST', body: { payload, conflict } } ),
	// v0.15.0: Content Types (custom post type builder).
	listContentTypes: () => request( '/content-types' ),
	listCtTaxonomies: () => request( '/content-types/taxonomies' ),
	getContentType: ( id ) => request( `/content-types/${ id }` ),
	createContentType: ( def ) =>
		request( '/content-types', { method: 'POST', body: def } ),
	updateContentType: ( id, def ) =>
		request( `/content-types/${ id }`, { method: 'PUT', body: def } ),
	deleteContentType: ( id ) =>
		request( `/content-types/${ id }`, { method: 'DELETE' } ),
	validateCtSlug: ( slug, id ) =>
		request( '/content-types/validate-slug', {
			method: 'POST',
			body: { slug, ...( id ? { id } : {} ) },
		} ),
	migrateCtSlug: ( id, slug ) =>
		request( `/content-types/${ id }/migrate-slug`, {
			method: 'POST',
			body: { slug },
		} ),
	exportContentTypes: ( ids ) => {
		// Build with the URL API: API_ROOT can already contain a query
		// string (?rest_route=...) on plain-permalink sites, where '?ids='
		// concatenation yields "No route was found".
		const url = new URL(
			API_ROOT + '/content-types/export',
			window.location.origin
		);
		if ( ids && ids.length ) {
			url.searchParams.set( 'ids', ids.join( ',' ) );
		}
		return request( url.toString() );
	},
	importContentTypes: ( doc ) =>
		request( '/content-types/import', { method: 'POST', body: doc } ),
};

/**
 * Post types for the location-rule value dropdown. Uses the core wp/v2
 * types endpoint; the list is cached for the session.
 *
 * The endpoint URL comes from PHP (rest_url respects the site's permalink
 * settings — plain permalinks use ?rest_route=, so the URL is built with
 * the URL API rather than string concatenation to keep query params
 * separate). The REST nonce is required for context=edit cookie auth.
 */
let postTypesCache = null;
export function getPostTypes() {
	if ( postTypesCache ) {
		return Promise.resolve( postTypesCache );
	}
	const url = new URL( WP_API_ROOT + '/types', window.location.origin );
	url.searchParams.set( 'context', 'edit' );
	return fetch( url.toString(), {
		credentials: 'same-origin',
		headers: { 'X-WP-Nonce': API_NONCE },
	} )
		.then( ( res ) => {
			if ( ! res.ok ) {
				throw new Error( `Could not load post types (${ res.status })` );
			}
			return res.json();
		} )
		.then( ( data ) => {
			postTypesCache = Object.values( data )
				.filter( ( t ) => t && t.slug && t.viewable !== false )
				.map( ( t ) => ( { slug: t.slug, label: t.name || t.slug } ) )
				.sort( ( a, b ) => a.label.localeCompare( b.label ) );
			return postTypesCache;
		} );
}
