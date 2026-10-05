<?php
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
 * WYSIWYG field type test — run from the lab root:
 *
 *   ./bin/wp --allow-root --path=www eval-file \
 *     wp-content/plugins/tk-fields/tests/test-wysiwyg.php
 *
 * Exits 0 when every assertion passes, 1 otherwise. Creates its own field
 * group, post and (temporary) subscriber user, then deletes them — leaves
 * no trace.
 *
 * The central guarantee under test: wp_kses_post() runs on EVERY save, ALL
 * roles, unless the per-field allow_unfiltered opt-out (default OFF) is on.
 *
 * NOTE: no `declare(strict_types=1)` here — wp-cli's eval-file wraps this in
 * eval(), where a declare is not the first statement and fatals.
 */

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$failures = 0;

/**
 * @param bool   $cond
 * @param string $label
 */
$check = function ( bool $cond, string $label ) use ( &$failures ): void {
	if ( $cond ) {
		echo "PASS: {$label}\n";
	} else {
		$failures++;
		echo "FAIL: {$label}\n";
	}
};

$store    = Group_Store::instance();
$registry = Field_Registry::instance();

// ------------------------------------------------- registry: type + settings
$check( in_array( 'wysiwyg', Field_Registry::types(), true ), 'wysiwyg is a registered field type' );

$registry->register(
	array( 'name' => 'tkwy_probe', 'label' => 'Probe', 'type' => 'wysiwyg' )
);
$probe = $registry->get( 'tkwy_probe' );
$check( is_array( $probe ), 'wysiwyg field registers' );
$check( 'full' === ( $probe['toolbar'] ?? null ), 'default toolbar is full' );
$check( false === ( $probe['allow_unfiltered'] ?? null ), 'allow_unfiltered defaults to OFF' );

$registry->register(
	array( 'name' => 'tkwy_probe_bad', 'label' => 'Probe Bad', 'type' => 'wysiwyg', 'toolbar' => 'kitchen-sink' )
);
$check( 'full' === ( $registry->get( 'tkwy_probe_bad' )['toolbar'] ?? null ), 'bogus toolbar falls back to full' );

// ------------------------------------------------- sanitize: kses on save
$evil  = '<p>Hello <strong>world</strong></p><script>alert(1)</script><a href="https://example.com" onclick="steal()">x</a><iframe src="https://evil.example/"></iframe>';
$clean = $registry->sanitize( $probe, $evil );
$check( is_string( $clean ), 'sanitize returns a string' );
$check( false === stripos( $clean, '<script' ), 'kses strips <script> tags' );
$check( false === stripos( $clean, 'onclick' ), 'kses strips event handlers' );
$check( false === stripos( $clean, '<iframe' ), 'kses strips iframes' );
$check( false !== strpos( $clean, '<p>Hello <strong>world</strong></p>' ), 'kses keeps allowed markup' );
$check( false !== strpos( $clean, 'href="https://example.com"' ), 'kses keeps safe link href' );

// Opt-out: raw HTML preserved verbatim.
$registry->register(
	array( 'name' => 'tkwy_probe_raw', 'label' => 'Probe Raw', 'type' => 'wysiwyg', 'allow_unfiltered' => true )
);
$raw = $registry->sanitize( $registry->get( 'tkwy_probe_raw' ), $evil );
$check( $evil === $raw, 'allow_unfiltered=1 preserves raw HTML untouched' );

// Non-scalar input collapses to empty.
$check( '' === $registry->sanitize( $probe, array( 'x' ) ), 'non-scalar input sanitizes to empty string' );

// ------------------------------------------------- validate + format
$check( $registry->validate( $probe, '<p>x</p>' ), 'validate accepts the sanitized string' );
$field_req = $probe;
$field_req['required'] = true;
$check( ! $registry->validate( $field_req, '' ), 'validate: empty required wysiwyg fails' );
$check( $registry->validate( $probe, '' ), 'validate: empty non-required wysiwyg passes' );

$check( '<p>Exact</p>' === $registry->format( $probe, '<p>Exact</p>' ), 'format() returns the HTML string unchanged' );
$check( $evil === $registry->format( $registry->get( 'tkwy_probe_raw' ), $evil ), 'format() does not re-strip opt-out HTML' );

// ------------------------------------------------- pinned toolbars
$full = Field_Registry::WYSIWYG_TOOLBARS['full'];
$check(
	array( 'formatselect', 'bold', 'italic', 'bullist', 'numlist', 'blockquote', 'alignleft', 'aligncenter', 'alignright', 'link', 'wp_more', 'spellchecker', 'fullscreen', 'wp_adv' ) === $full['toolbar1'],
	'full toolbar1 pinned to core wp_editor() defaults'
);
$check(
	array( 'strikethrough', 'hr', 'forecolor', 'pastetext', 'removeformat', 'charmap', 'outdent', 'indent', 'undo', 'redo', 'wp_help' ) === $full['toolbar2'],
	'full toolbar2 pinned to core wp_editor() defaults'
);
$check( array() === Field_Registry::WYSIWYG_TOOLBARS['basic']['toolbar2'], 'basic toolbar is a single row' );

$settings_full = Field_Registry::wysiwyg_editor_settings( $probe );
$check( str_starts_with( $settings_full['tinymce']['toolbar1'], 'formatselect,bold,italic,' ), 'editor settings carry the pinned full toolbar1' );
$check( false !== strpos( $settings_full['tinymce']['toolbar2'], 'wp_help' ), 'editor settings carry the pinned full toolbar2' );

$settings_none = Field_Registry::wysiwyg_editor_settings( array( 'toolbar' => 'none' ) );
$check( false === $settings_none['tinymce'] && false === $settings_none['quicktags'], 'toolbar=none disables visual editor and quicktags' );

$settings_bogus = Field_Registry::wysiwyg_editor_settings( array( 'toolbar' => 'nope' ) );
$check( $settings_full['tinymce'] === $settings_bogus['tinymce'], 'bogus toolbar resolves to full in editor settings' );

// ------------------------------------------------- group store persistence
$group = $store->create(
	array(
		'title'          => 'WYSIWYG Test Group',
		'location'       => array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ),
		'location_match' => 'all',
		'fields'         => array(
			array(
				'key'              => 'f_tkwy_body',
				'name'             => 'tkwy_body',
				'label'            => 'Body',
				'type'             => 'wysiwyg',
				'toolbar'          => 'basic',
				'allow_unfiltered' => false,
				'default'          => '<p>Default <em>text</em></p><script>bad()</script>',
			),
			array(
				'key'              => 'f_tkwy_raw',
				'name'             => 'tkwy_raw',
				'label'            => 'Raw',
				'type'             => 'wysiwyg',
				'toolbar'          => 'none',
				'allow_unfiltered' => true,
			),
		),
	)
);
$check( ! is_wp_error( $group ), 'wysiwyg test group created' );

$registry->reset_group_cache();
$body_def = $registry->get( 'tkwy_body' );
$raw_def  = $registry->get( 'tkwy_raw' );
$check( 'basic' === ( $body_def['toolbar'] ?? null ), 'toolbar=basic persisted through the group store' );
$check( false === ( $body_def['allow_unfiltered'] ?? null ), 'allow_unfiltered=false persisted' );
$check( 'none' === ( $raw_def['toolbar'] ?? null ), 'toolbar=none persisted' );
$check( true === ( $raw_def['allow_unfiltered'] ?? null ), 'allow_unfiltered=true persisted' );
$check( '<p>Default <em>text</em></p>bad()' === ( $body_def['default'] ?? null ), 'wysiwyg default goes through wp_kses_post, not sanitize_text_field' );

// ------------------------------------------------- post + save as admin
$post_id = wp_insert_post(
	array(
		'post_title'  => 'WYSIWYG Test Post',
		'post_type'   => 'post',
		'post_status' => 'draft',
	)
);
$check( $post_id > 0, 'test post created' );

$check( tk_update_field( 'tkwy_body', $evil, $post_id ), 'admin save accepted' );
Fields::flush();
$stored = tk_get_field( 'tkwy_body', $post_id, false );
$check( is_string( $stored ), 'stored value reads back as string' );
$check( false === stripos( (string) $stored, '<script' ), 'stored value has no <script>' );
$check( false === stripos( (string) $stored, 'onclick' ), 'stored value has no event handlers' );
$check( false !== strpos( (string) $stored, '<p>Hello <strong>world</strong></p>' ), 'stored value keeps allowed markup' );

// Formatted read returns the HTML string (consistent with format()).
$formatted = tk_get_field( 'tkwy_body', $post_id, true );
$check( $stored === $formatted, 'formatted read returns the same HTML string' );

// Opt-out field stores raw HTML.
$check( tk_update_field( 'tkwy_raw', $evil, $post_id ), 'opt-out save accepted' );
Fields::flush();
$check( $evil === tk_get_field( 'tkwy_raw', $post_id, false ), 'opt-out field stores raw HTML verbatim' );

// ------------------------------------------------- save as a low-privilege role
$sub_name = 'tkwy_sub_' . substr( md5( (string) time() ), 0, 8 );
$sub_id   = wp_create_user( $sub_name, wp_generate_password( 24 ), $sub_name . '@example.invalid' );
$check( $sub_id > 0 && ! is_wp_error( $sub_id ), 'subscriber test user created' );

$admin_id = get_current_user_id();
wp_set_current_user( $sub_id );
$check( tk_update_field( 'tkwy_body', $evil, $post_id ), 'subscriber save accepted (kses still applies)' );
Fields::flush();
$sub_stored = tk_get_field( 'tkwy_body', $post_id, false );
$check( false === stripos( (string) $sub_stored, '<script' ), 'subscriber-saved value has no <script>' );
$check( false === stripos( (string) $sub_stored, 'onclick' ), 'subscriber-saved value has no event handlers' );
wp_set_current_user( $admin_id );

// ------------------------------------------------- REST field-types descriptor
$rest_data = Rest::instance()->field_types()->get_data();
$wy_desc   = $rest_data['types']['wysiwyg'] ?? null;
$check( is_array( $wy_desc ), 'REST /field-types exposes wysiwyg' );

$by_key = array();
foreach ( $wy_desc['settings'] ?? array() as $s ) {
	$by_key[ $s['key'] ] = $s;
}
$toolbar_desc = $by_key['toolbar'] ?? array();
$check( 'select' === ( $toolbar_desc['control'] ?? null ), 'toolbar setting is a select control' );
$check(
	array( 'basic', 'full', 'none' ) === array_keys( $toolbar_desc['options'] ?? array() ),
	'toolbar select offers basic/full/none'
);
$optout_desc = $by_key['allow_unfiltered'] ?? array();
$check( 'toggle' === ( $optout_desc['control'] ?? null ), 'allow_unfiltered is a toggle' );
$check( false !== stripos( (string) ( $optout_desc['tooltip'] ?? '' ), 'stored-XSS' ), 'opt-out tooltip carries the stored-XSS warning' );
$check( 'textarea' === ( ( $by_key['default'] ?? array() )['control'] ?? null ), 'default value uses a textarea control' );

// ------------------------------------------------- AI context export schema
$context = Context_Export::generate();
$schema  = array();
foreach ( $context['entities'] as $entity ) {
	if ( 'WYSIWYG Test Group' === ( $entity['group_title'] ?? '' ) ) {
		$schema = $entity['fields']['tkwy_body']['schema'] ?? array();
		$ret    = $entity['fields']['tkwy_body']['return_format'] ?? '';
	}
}
$check( 'string' === ( $schema['type'] ?? null ), 'AI schema: wysiwyg is a string' );
$check( 'basic' === ( $schema['toolbar'] ?? null ), 'AI schema enumerates the toolbar setting' );
$check( false === ( $schema['allow_unfiltered'] ?? null ), 'AI schema enumerates allow_unfiltered' );
$check( 'string (HTML)' === ( $ret ?? '' ), 'AI return_format describes HTML' );

// ------------------------------------------------- Elementor text tag
$check( class_exists( 'TK\\Fields\\Integrations\\Elementor\\Tags\\Text_Tag' ), 'Elementor Text_Tag class loaded' );
$ref = new \ReflectionMethod( 'TK\\Fields\\Integrations\\Elementor\\Tags\\Text_Tag', 'supported_types' );
$ref->setAccessible( true );
$tag_types = $ref->invoke( null );
$check( is_array( $tag_types ) && in_array( 'wysiwyg', $tag_types, true ), 'Elementor text tag serves wysiwyg' );

// ------------------------------------------------- Block Bindings (scalar HTML)
$bound = Block_Bindings::display_value( 'tkwy_body', $post_id );
$check( is_string( $bound ) && $bound === $stored, 'Block Bindings resolve wysiwyg to the HTML string' );

// ------------------------------------------------- field-value block render
$attributes = array(
	'fieldName' => 'tkwy_body',
	'fieldType' => 'wysiwyg',
	'value'     => '<p>Rendered <strong>body</strong></p>',
);
$block      = null;
ob_start();
include TK_FIELDS_DIR . 'blocks/field-value/render.php';
$html = (string) ob_get_clean();
$check( false !== strpos( $html, '<p>Rendered <strong>body</strong></p>' ), 'render.php outputs the HTML unescaped' );
$check( false !== strpos( $html, 'tk-field-value--wysiwyg' ), 'render.php wraps with the wysiwyg class' );

// ------------------------------------------------- classic renderer
$check( class_exists( 'TK\\Fields\\Classic_Renderer' ), 'Classic_Renderer class exists' );
$check( has_action( 'add_meta_boxes', array( Classic_Renderer::instance(), 'add_meta_boxes' ) ) !== false, 'classic renderer hooks add_meta_boxes' );
$check( has_action( 'save_post', array( Classic_Renderer::instance(), 'save_post' ) ) !== false, 'classic renderer hooks save_post' );
$check( method_exists( Classic_Renderer::instance(), 'render_field_wysiwyg' ), 'classic renderer implements render_field_wysiwyg' );

// ------------------------------------------------- field-value block enum
$block_json = json_decode( file_get_contents( TK_FIELDS_DIR . 'blocks/field-value/block.json' ), true );
$check( in_array( 'wysiwyg', $block_json['attributes']['fieldType']['enum'], true ), 'block.json fieldType enum includes wysiwyg' );

// ------------------------------------------------- cleanup
tk_delete_field( 'tkwy_body', $post_id );
tk_delete_field( 'tkwy_raw', $post_id );
Fields::flush();
wp_delete_post( $post_id, true );
if ( ! is_wp_error( $group ) ) {
	wp_delete_post( $group['id'], true );
}
if ( isset( $sub_id ) && $sub_id > 0 ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $sub_id );
}
$registry->reset_group_cache();

echo $failures > 0 ? "\n{$failures} FAILURE(S)\n" : "\nALL CHECKS PASSED\n";
exit( $failures > 0 ? 1 : 0 );
