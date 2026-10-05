<?php
/**
 * Plugin Name:       TK Fields
 * Plugin URI:        https://github.com/iodine-53/tk-fields
 * Description:       Native WordPress custom fields — 35 field types, clean storage, built on core APIs. Free forever, no lock-in.
 * Version:           0.20.6
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Iodine
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tk-fields
 *
 * TK Fields is a free, native-API custom fields toolkit for WordPress:
 * 35 field types, a visual field-group builder, and interactive Gutenberg
 * blocks — plus a matching Elementor integration. Everything is built on
 * core WordPress APIs (Block Bindings, the Interactivity API, the block
 * editor's component library). The admin UI is compiled from the included
 * source (see "Build from source" in readme.txt). Field values live in
 * standard postmeta/options storage with zero lock-in, and there is no
 * tracking or telemetry — the only remote requests are two
 * opt-in/contextual editor features of the Map field (address search via
 * the Photon geocoder, and OpenStreetMap map tiles in the editor preview;
 * see "External services" in readme.txt). Values are readable through a
 * canonical tk_* function API, and an opt-in compatibility shim answers
 * the ACF function names your theme may already use.
 *
 * Highlights: repeatable rows with stable row IDs (repeaters nest two
 * levels deep), flexible-content layouts, clone fields, conditional
 * logic, location rules, AI-assisted import (describe the group, paste
 * back the JSON), one-click ACF field-group import, and a password type
 * that is unconditionally excluded from AI context export.
 *
 * Docs & source: https://github.com/iodine-53/tk-fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TK_FIELDS_VERSION', '0.20.6' );
define( 'TK_FIELDS_FILE', __FILE__ );
define( 'TK_FIELDS_DIR', plugin_dir_path( __FILE__ ) );
define( 'TK_FIELDS_URL', plugin_dir_url( __FILE__ ) );

// Core classes.
require_once TK_FIELDS_DIR . 'includes/class-unset-value.php';
require_once TK_FIELDS_DIR . 'includes/class-field-registry.php';
require_once TK_FIELDS_DIR . 'includes/class-map.php';
require_once TK_FIELDS_DIR . 'includes/class-storage-adapter.php';
require_once TK_FIELDS_DIR . 'includes/class-storage-postmeta.php';
require_once TK_FIELDS_DIR . 'includes/class-storage-termmeta.php';
require_once TK_FIELDS_DIR . 'includes/class-storage-usermeta.php';
require_once TK_FIELDS_DIR . 'includes/class-storage-options.php';
require_once TK_FIELDS_DIR . 'includes/class-flexible-content.php';
require_once TK_FIELDS_DIR . 'includes/class-fields.php';

// Shared post-listing renderer (v0.17.0) — one engine for the Elementor
// TK Post Listing widget and the Gutenberg tk/post-listing block.
require_once TK_FIELDS_DIR . 'includes/class-post-listing-renderer.php';

// Repeater rows + template loop (Repeater_Row, Repeater_Loop).
require_once TK_FIELDS_DIR . 'includes/class-repeater.php';

// Canonical tk_* function API (global namespace).
require_once TK_FIELDS_DIR . 'includes/api.php';

// Phase 4: field-group builder backend (admin). Loaded on every request — the
// registry consults persisted groups on the frontend too, so the CPT must be
// registered on init everywhere, not only in wp-admin.
require_once TK_FIELDS_DIR . 'includes/admin/class-group-store.php';
require_once TK_FIELDS_DIR . 'includes/admin/class-content-type-store.php';
require_once TK_FIELDS_DIR . 'includes/admin/class-rest.php';
require_once TK_FIELDS_DIR . 'includes/admin/class-admin.php';
require_once TK_FIELDS_DIR . 'includes/admin/class-classic-renderer.php';
require_once TK_FIELDS_DIR . 'includes/admin/class-acf-importer.php';
require_once TK_FIELDS_DIR . 'includes/admin/class-ai-import.php';

Group_Store::instance()->init();
// v0.16.0: Content Types (custom post type builder).
Content_Type_Store::instance()->init();

// v0.16.0: the internal CPTs (field groups, content types) map every
// capability to the custom `manage_tk_fields` primitive. Grant it to
// administrators (persisted on the role; the has_cap() guard makes this
// a one-time write).
add_action(
	'init',
	function (): void {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( 'manage_tk_fields' ) ) {
			$role->add_cap( 'manage_tk_fields' );
		}
	}
);

/**
 * v0.16.0: flush rewrite rules on deactivation. Our content types stop
 * registering the moment the plugin is inactive, but their rewrite rules
 * persist — stale rules can shadow page slugs and produce wrong 404s.
 * Definitions are NOT deleted: they reappear on reactivation.
 */
register_deactivation_hook(
	__FILE__,
	function (): void {
		delete_option( Content_Type_Store::FLUSH_FLAG );
		flush_rewrite_rules();
	}
);

Rest::instance()->init();
ACF_Importer::instance()->init();
AI_Import::init();
Admin::instance()->init();
// Round 3 Batch B: classic-editor meta boxes (wp_editor() for wysiwyg).
// Skips itself whenever the block editor is the active editor.
Classic_Renderer::instance()->init();

// Phase 5: read-only Abilities API surface (WP 6.9+). Disabled by default —
// opt in via the `tk_fields_abilities_enabled` option or filter. The class
// feature-detects `wp_register_ability` in init(); no polyfill for WP < 6.9.
require_once TK_FIELDS_DIR . 'includes/class-abilities.php';
Abilities::init();

// Phase 5: AI context export (schema only — never field values) + WP-CLI.
// Context_Export has no hooks of its own; CLI::init() self-guards on WP_CLI.
require_once TK_FIELDS_DIR . 'includes/class-context-export.php';
require_once TK_FIELDS_DIR . 'includes/class-cli.php';

CLI::init();

/**
 * Invalidate the registry's persisted-group cache when a group is saved,
 * trashed, restored, or deleted. `delete_post` fires for trash, restore and
 * permanent delete alike (with $force_delete distinguishing them).
 */
add_action( 'save_post_' . Group_Store::CPT, array( Field_Registry::instance(), 'reset_group_cache' ) );
add_action(
	'delete_post',
	function ( int $post_id, \WP_Post $post ): void {
		if ( Group_Store::CPT === $post->post_type ) {
			Field_Registry::instance()->reset_group_cache();
		}
	},
	10,
	2
);

// Block registration (auto-discovers blocks/<slug>/ — see class-blocks.php).
require_once TK_FIELDS_DIR . 'includes/class-blocks.php';

// Shared widget design tokens (TK Studio v1) — consumed by Gutenberg blocks
// and Elementor widgets alike.
require_once TK_FIELDS_DIR . 'includes/class-widget-assets.php';
Widget_Assets::init();

// Phase 5: Block Bindings source (tk-fields/field) — server value resolution,
// editor-side source callbacks, and the tiny tk/v1 read/write REST surface
// the editor uses. Values are never synced into block attributes.
require_once TK_FIELDS_DIR . 'includes/class-block-bindings.php';

Block_Bindings::init();

// Field-group renderer: auto-inserts tk/field-repeater wrappers for repeater
// fields in the post's assigned groups + the tk/v1/post-repeaters endpoint.
require_once TK_FIELDS_DIR . 'includes/class-field-group-renderer.php';

Field_Group_Renderer::init();

/**
 * Load the Elementor integration — only when Elementor is actually present.
 *
 * Elementor fires `elementor/loaded` from Plugin::instance(), which runs at
 * its own file-inclusion time — potentially BEFORE this file is parsed
 * (plugin load order). So: if it already fired, load immediately; otherwise
 * hook it. Either way, sites without Elementor pay nothing: no files loaded,
 * no hooks registered, zero autoload cost.
 */
function maybe_load_elementor_integration(): void {
	$base = TK_FIELDS_DIR . 'includes/integrations/elementor/';

	require_once $base . 'class-context.php';
	require_once $base . 'class-module.php';
	require_once $base . 'tags/class-base-field-tag.php';
	require_once $base . 'tags/class-text-tag.php';
	require_once $base . 'tags/class-number-tag.php';
	require_once $base . 'tags/class-url-tag.php';
	require_once $base . 'tags/class-image-tag.php';
	require_once $base . 'tags/class-date-tag.php';
	require_once $base . 'tags/class-true-false-tag.php';
	require_once $base . 'tags/class-gallery-tag.php';
	require_once $base . 'tags/class-group-tag.php';
	require_once $base . 'tags/class-repeater-tag.php';
	// NOTE: the widget class is required inside Module::register_widgets(),
	// not here — Elementor\Widget_Base is only available once the widgets
	// manager initializes (on init), which is after `elementor/loaded`.

	Integrations\Elementor\Module::init();
}
if ( did_action( 'elementor/loaded' ) ) {
	// Elementor initializes at its own file-inclusion time, which can run
	// before this file is parsed (plugin load order) — load immediately.
	maybe_load_elementor_integration();
} else {
	add_action( 'elementor/loaded', __NAMESPACE__ . '\\maybe_load_elementor_integration' );
}

/**
 * Load the opt-in ACF compatibility shim late, only when explicitly enabled.
 *
 * Never loaded by default: collisions with ACF / Secure Custom Fields cause
 * fatals or silent mis-resolution, so Migration Mode is a conscious opt-in
 * (settings toggle or TK_FIELDS_ACF_COMPAT constant).
 */
function maybe_load_acf_shim(): void {
	$enabled = ( defined( 'TK_FIELDS_ACF_COMPAT' ) && TK_FIELDS_ACF_COMPAT )
		|| (bool) get_option( 'tk_fields_acf_compat_enabled', false );

	if ( ! $enabled ) {
		return;
	}

	require_once TK_FIELDS_DIR . 'includes/compat/acf-shim.php';
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\maybe_load_acf_shim', 20 );

// Commit batched field writes at the end of the request.
add_action( 'shutdown', array( Fields::class, 'flush' ) );
