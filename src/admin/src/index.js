/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * TK Fields admin app entry point.
 *
 * Mounts the React SPA into #tk-fields-admin-root (rendered by
 * includes/admin/class-admin.php). wp.* modules are externals — React and
 * ReactDOM come from WordPress core, never from this bundle.
 */
import '../tokens.css';
import './styles.css';
import { render } from '@wordpress/element';
import App from './components/App';
import ContentTypesApp from './components/ContentTypesApp';
import FieldRow from './components/FieldRow';

// Shared with the classic vanilla editors (assets/admin/subfields-editor.js
// and layouts-editor.js load before this bundle, so they read it lazily at
// render time): the ONE recursive field-row component, so nested rows can
// never drift out of sync with top-level rows.
window.TKFFieldRow = FieldRow;

function boot() {
	const root = document.getElementById( 'tk-fields-admin-root' );
	if ( ! root ) {
		return;
	}
	if ( ! window.TK_FIELDS_ADMIN || ! window.TK_FIELDS_ADMIN.root ) {
		root.innerHTML =
			'<div class="notice notice-error inline"><p>TK Fields: missing admin configuration (TK_FIELDS_ADMIN).</p></div>';
		return;
	}
	// v0.15.0: the Content Types screen mounts the CPT app; every other
	// screen mounts the field-group builder.
	const appName = root.getAttribute( 'data-tkf-app' );
	render( appName === 'content-types' ? <ContentTypesApp /> : <App />, root );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
