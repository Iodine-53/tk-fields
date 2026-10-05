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
 * Flexible content + clone test — run from the lab root:
 *
 *   ./bin/wp --allow-root --path=www eval-file \
 *     wp-content/plugins/tk-fields/tests/test-flexible-clone.php
 *
 * Exits 0 when every assertion passes, 1 otherwise. Creates its own field
 * groups, a term, a user, and option rows, then deletes them — leaves no
 * trace.
 *
 * Locked Round 3 contracts under test:
 * - flexible: sanitize/validate split, min/max at save, unknown layouts,
 *   post writes refused, term round-trip as serialized block markup.
 * - clone: auto-namespace (no prefix setting), label prefix, additive
 *   required, instructions override, fan-out/fan-in, nested-clone guard.
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

// ---------------------------------------------------------------- setup ---
$source = $store->create(
	array(
		'title'          => 'FC Test — Clone Source',
		'location'       => array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ),
		'location_match' => 'all',
		'fields'         => array(
			array( 'key' => 'f_fc_src_street', 'name' => 'addr_street', 'label' => 'Street', 'type' => 'text', 'required' => true, 'instructions' => 'Street instructions.' ),
			array( 'key' => 'f_fc_src_zip', 'name' => 'addr_zip', 'label' => 'ZIP', 'type' => 'number' ),
			array( 'key' => 'f_fc_src_kind', 'name' => 'addr_kind', 'label' => 'Kind', 'type' => 'select', 'choices' => array( 'home' => 'Home', 'work' => 'Work' ) ),
		),
	)
);
$check( ! is_wp_error( $source ), 'source group created' );
$source_id = is_wp_error( $source ) ? 0 : (int) $source['id'];
$registry->reset_group_cache();

$flexible = $store->create(
	array(
		'title'          => 'FC Test — Flexible',
		'location'       => array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ),
		'location_match' => 'all',
		'fields'         => array(
			array(
				'key'          => 'f_fc_sections',
				'name'         => 'fc_sections',
				'label'        => 'Sections',
				'type'         => 'flexible_content',
				'button_label' => 'Add section',
				'layouts'      => array(
					array(
						'key'    => 'hero',
						'label'  => 'Hero',
						'min'    => 1,
						'max'    => 2,
						'fields' => array(
							array( 'key' => 'f_fc_headline', 'name' => 'headline', 'label' => 'Headline', 'type' => 'text', 'required' => true ),
							array( 'key' => 'f_fc_count', 'name' => 'count', 'label' => 'Count', 'type' => 'number' ),
						),
					),
					array(
						'key'    => 'gallery_row',
						'label'  => 'Gallery Row',
						'fields' => array(
							array( 'key' => 'f_fc_caption', 'name' => 'caption', 'label' => 'Caption', 'type' => 'text' ),
						),
					),
				),
			),
		),
	)
);
$check( ! is_wp_error( $flexible ), 'flexible group created' );
$registry->reset_group_cache();

$clone_group = $store->create(
	array(
		'title'          => 'FC Test — Clone',
		'location'       => array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ),
		'location_match' => 'all',
		'fields'         => array(
			array( 'key' => 'f_fc_clonesrc', 'name' => 'clonesrc', 'label' => 'Office', 'type' => 'clone', 'clone' => $source_id, 'instructions' => 'Use the office address.' ),
			array( 'key' => 'f_fc_clonesrc2', 'name' => 'clonesrc2', 'label' => 'HQ', 'type' => 'clone', 'clone' => $source_id, 'required' => true ),
		),
	)
);
$check( ! is_wp_error( $clone_group ), 'clone group created' );
$registry->reset_group_cache();

// Group whose only field is a clone — cloning IT must yield no children
// (nested-clone cycle guard).
$nested = $store->create(
	array(
		'title'          => 'FC Test — Nested',
		'location'       => array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ),
		'location_match' => 'all',
		'fields'         => array(
			array( 'key' => 'f_fc_innerclone', 'name' => 'innerclone', 'label' => 'Inner', 'type' => 'clone', 'clone' => $source_id ),
		),
	)
);
$check( ! is_wp_error( $nested ), 'nested group created' );
$nested_id = is_wp_error( $nested ) ? 0 : (int) $nested['id'];
$registry->reset_group_cache();

$outer = $store->create(
	array(
		'title'          => 'FC Test — Outer',
		'location'       => array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ),
		'location_match' => 'all',
		'fields'         => array(
			array( 'key' => 'f_fc_outerclone', 'name' => 'outerclone', 'label' => 'Outer', 'type' => 'clone', 'clone' => $nested_id ),
		),
	)
);
$check( ! is_wp_error( $outer ), 'outer group created' );
$registry->reset_group_cache();

$flex = $registry->get( 'fc_sections' );
$check( null !== $flex && 'flexible_content' === $flex['type'], 'flexible field registered' );

// ------------------------------------------------- flexible: registry -----
$layouts = Field_Registry::flexible_layouts( $flex );
$check( isset( $layouts['hero'], $layouts['gallery_row'] ), 'layouts carried and indexed by key' );
$check( 'Add section' === ( $flex['button_label'] ?? '' ), 'button_label carried' );

$rows_happy = array(
	array( 'layout' => 'hero', 'id' => 'row-1', 'fields' => array( 'headline' => ' Hi ', 'count' => '42' ) ),
	array( 'layout' => 'gallery_row', 'id' => 'row-2', 'fields' => array( 'caption' => 'Nice pic' ) ),
);
$san = $registry->sanitize( $flex, $rows_happy );
$check( 'Hi' === ( $san[0]['fields']['headline'] ?? null ), 'sanitize trims text sub-field' );
$check( 42 === ( $san[0]['fields']['count'] ?? null ), 'sanitize casts number sub-field to int' );
$check( 'row-1' === ( $san[0]['id'] ?? null ), 'sanitize preserves supplied row id' );

$san_unknown = $registry->sanitize( $flex, array( array( 'layout' => 'nope', 'fields' => array( 'x' => 'y' ) ) ) );
$check( 'y' === ( $san_unknown[0]['fields']['x'] ?? null ), 'sanitize passes unknown-layout rows through' );

$check( false === $registry->validate( $flex, $san_unknown ), 'validate rejects unknown layout' );
$check( false === $registry->validate( $flex, $registry->sanitize( $flex, array( array( 'layout' => 'gallery_row', 'fields' => array( 'caption' => 'c' ) ) ) ) ), 'validate enforces layout min (hero min 1)' );
$check(
	false === $registry->validate(
		$flex,
		$registry->sanitize(
			$flex,
			array(
				array( 'layout' => 'hero', 'fields' => array( 'headline' => 'a' ) ),
				array( 'layout' => 'hero', 'fields' => array( 'headline' => 'b' ) ),
				array( 'layout' => 'hero', 'fields' => array( 'headline' => 'c' ) ),
			)
		)
	),
	'validate enforces layout max (hero max 2)'
);
$check( false === $registry->validate( $flex, $registry->sanitize( $flex, array( array( 'layout' => 'hero', 'fields' => array( 'count' => 1 ) ) ) ) ), 'validate enforces required sub-field' );
$check( true === $registry->validate( $flex, $san ), 'validate accepts the happy-path rows' );
$check( false === $registry->validate( $flex, $registry->sanitize( $flex, array( array( 'layout' => 'nope', 'fields' => array() ) ) ) ), 'invalid rows fail update-time validation too' );

$fmt = $registry->format( $flex, $san );
$check( 'hero' === ( $fmt[0]['layout'] ?? null ) && 'row-1' === ( $fmt[0]['id'] ?? null ), 'format keeps layout + id' );
$check( 'Hero' === ( $fmt[0]['layout_label'] ?? null ), 'format adds layout_label' );
$check( 'Hi' === ( $fmt[0]['fields']['headline'] ?? null ), 'format carries sub-values' );

// ------------------------------------------------- flexible: term round-trip
$term_id = 0;
$term_res = wp_insert_term( 'FC Test Term', 'category' );
if ( ! is_wp_error( $term_res ) ) {
	$term_id = (int) $term_res['term_id'];
}
$check( $term_id > 0, 'test term created' );
$term = $term_id > 0 ? get_term( $term_id ) : null;

if ( $term instanceof \WP_Term ) {
	$check( true === Fields::update( 'fc_sections', $rows_happy, $term ), 'flexible update on term accepted' );
	$meta = get_term_meta( $term_id, 'fc_sections', true );
	$check( is_string( $meta ) && false !== strpos( $meta, '<!-- wp:tk/flexible-content' ), 'term meta holds serialized block markup' );

	$got = Fields::get( 'fc_sections', $term );
	$check( is_array( $got ) && 'Hero' === ( $got[0]['layout_label'] ?? null ), 'term read returns formatted rows' );
	$check( 42 === ( $got[0]['fields']['count'] ?? null ), 'term read re-canonicalizes typed values' );

	$got_raw = Fields::get( 'fc_sections', $term, false );
	$check( is_array( $got_raw ) && 'hero' === ( $got_raw[0]['layout'] ?? null ) && ! isset( $got_raw[0]['layout_label'] ), 'unformatted term read is canonical shape' );

	$check( false === Fields::update( 'fc_sections', array( array( 'layout' => 'nope', 'fields' => array() ) ), $term ), 'invalid rows rejected at save on term' );
}

// ------------------------------------------------- flexible: post refusal --
$post      = get_posts( array( 'post_type' => 'post', 'posts_per_page' => 1, 'post_status' => 'any' ) );
$post_id   = $post ? $post[0]->ID : 0;
$check( $post_id > 0, 'test post exists' );
$check( false === Fields::update( 'fc_sections', $rows_happy, $post_id ), 'flexible update on post refused (block editor owns post_content)' );

// ------------------------------------------- flexible: serialize round-trip
$markup = Flexible_Content::serialize_rows( $flex, $san );
$check( false !== strpos( $markup, 'tk/flexible-content' ) && false !== strpos( $markup, 'tk/flexible-layout' ), 'serialize_rows emits block markup' );
$back = Flexible_Content::rows_from_markup( 'fc_sections', $markup );
$check( 'hero' === ( $back[0]['layout'] ?? null ) && 'Hi' === ( $back[0]['fields']['headline'] ?? null ), 'serialize/parse round-trips rows' );

// --------------------------------- flexible: post read path (rows_raw) -----
// Regression: rows_raw() treated rows_from_blocks() arrays as Flexible_Row
// objects (fatal "Call to a member function get_layout() on array"). The
// markup below mirrors exactly what the editor serializes now that the
// container saves return InnerBlocks.Content.
$editor_markup = serialize_blocks( array(
	array(
		'blockName'    => 'tk/flexible-content',
		'attrs'        => array( 'fieldName' => 'fc_sections', 'fieldKey' => 'flex-test1' ),
		'innerBlocks'  => array(
			array(
				'blockName'   => 'tk/flexible-layout',
				'attrs'       => array( 'layout' => 'hero', 'layoutId' => 'layout-row1', 'fieldKey' => 'flex-test1' ),
				'innerBlocks' => array(
					array(
						'blockName'   => 'tk/field-value',
						'attrs'       => array( 'fieldName' => 'headline', 'fieldType' => 'text', 'value' => 'Hi', 'fieldKey' => 'flex-test1' ),
						'innerBlocks' => array(),
						'innerContent' => array(),
					),
				),
				'innerContent' => array( null ),
			),
		),
		'innerContent' => array( null ),
	),
) );
$flex_post_id = wp_insert_post( array( 'post_title' => 'FC Test Post', 'post_type' => 'post', 'post_status' => 'draft', 'post_content' => $editor_markup ) );
$check( $flex_post_id > 0, 'flexible test post created' );
$raw_rows = Flexible_Content::rows_raw( 'fc_sections', (int) $flex_post_id );
$check( 'hero' === ( $raw_rows[0]['layout'] ?? null ) && 'layout-row1' === ( $raw_rows[0]['id'] ?? null ) && 'Hi' === ( $raw_rows[0]['fields']['headline'] ?? null ), 'rows_raw reads editor markup from post_content' );
$fmt_rows = Fields::get( 'fc_sections', (int) $flex_post_id );
$check( is_array( $fmt_rows ) && 'Hero' === ( $fmt_rows[0]['layout_label'] ?? null ) && 'Hi' === ( $fmt_rows[0]['fields']['headline'] ?? null ), 'Fields::get formats flexible rows from post' );
$fmt_raw = Fields::get( 'fc_sections', (int) $flex_post_id, false );
$check( is_array( $fmt_raw ) && 'hero' === ( $fmt_raw[0]['layout'] ?? null ) && ! isset( $fmt_raw[0]['layout_label'] ), 'Fields::get unformatted flexible read is canonical shape' );

// ------------------------------------------------- flexible: render -------
$html = Flexible_Content::render_rows_html( $fmt );
$check( false !== strpos( $html, 'Hero' ) && false !== strpos( $html, 'Hi' ), 'render_rows_html shows layout label + value' );
$fmt_evil = $registry->format( $flex, $registry->sanitize( $flex, array( array( 'layout' => 'hero', 'id' => 'r', 'fields' => array( 'headline' => '5 > 3 & "quoted"' ) ) ) ) );
$html_evil = Flexible_Content::render_rows_html( $fmt_evil );
$check( false !== strpos( $html_evil, '&gt;' ) && false === strpos( $html_evil, '5 > 3' ), 'render_rows_html escapes sub-values' );

// ------------------------------------------------- flexible: REST + export
$rest      = REST::instance();
$resp      = $rest->field_types();
$resp_data = $resp instanceof \WP_REST_Response ? $resp->get_data() : array();
$type_data = $resp_data['types'] ?? array();
$flex_keys = array();
foreach ( $type_data['flexible_content']['settings'] ?? array() as $s ) {
	$flex_keys[] = $s['key'] ?? '';
}
$check( in_array( 'button_label', $flex_keys, true ) && in_array( 'layouts', $flex_keys, true ), 'REST flexible descriptor has button_label + layouts settings' );
$clone_keys = array();
foreach ( $type_data['clone']['settings'] ?? array() as $s ) {
	$clone_keys[] = $s['key'] ?? '';
}
$check( in_array( 'clone', $clone_keys, true ) && ! in_array( 'prefix', $clone_keys, true ), 'REST clone descriptor has source select, no prefix setting' );

$ctx = Context_Export::generate();
$flex_entry  = null;
$clone_entry = null;
foreach ( $ctx['entities'] as $entity ) {
	if ( 'FC Test — Flexible' === ( $entity['group_title'] ?? '' ) ) {
		$flex_entry = $entity['fields']['fc_sections'] ?? null;
	}
	if ( 'FC Test — Clone' === ( $entity['group_title'] ?? '' ) ) {
		$clone_entry = $entity['fields'];
	}
}
$hero_schema = $flex_entry['schema']['layouts']['hero'] ?? array();
$check( isset( $hero_schema['fields']['headline'] ), 'AI export: flexible layout sub-fields enumerated' );
$check( 1 === ( $hero_schema['min'] ?? null ) && 2 === ( $hero_schema['max'] ?? null ), 'AI export: layout min/max enumerated' );
$check( 'Add section' === ( $flex_entry['schema']['button_label'] ?? null ), 'AI export: button_label enumerated' );
$has_clone_type = false;
foreach ( (array) $clone_entry as $entry ) {
	if ( 'clone' === ( $entry['type'] ?? '' ) ) {
		$has_clone_type = true;
	}
}
$check( ! $has_clone_type, 'AI export: no {"type":"clone"} entry emitted' );
$check( 'text' === ( $clone_entry['clonesrc.addr_street']['type'] ?? null ), 'AI export: clone child expanded under namespaced key' );
$check( 'Office Street' === ( $clone_entry['clonesrc.addr_street']['label'] ?? null ), 'AI export: clone child label prefixed' );

// ------------------------------------------------- clone: resolution ------
$clone_def = $registry->get( 'clonesrc' );
$check( null !== $clone_def && 'clone' === $clone_def['type'], 'clone field registered' );
$children = $registry->resolve_clone_children( $clone_def );
$check( isset( $children['addr_street'], $children['addr_zip'], $children['addr_kind'] ), 'clone children resolved by short name' );
$check( 'clonesrc.addr_street' === ( $children['addr_street']['name'] ?? '' ), 'children auto-namespaced (clone_key.child_key)' );
$check( ! array_key_exists( 'prefix', $clone_def ), 'no prefix setting on clone def' );

// Label prefix / inheritance / overrides.
$check( 'Office Street' === ( $children['addr_street']['label'] ?? '' ), 'clone label prefixes child label' );
$check( 'number' === ( $children['addr_zip']['type'] ?? '' ), 'child type strictly inherited' );
$check( 42 === $registry->sanitize( $children['addr_zip'], '42' ), 'child sanitizer strictly inherited' );
$check( true === ! empty( $children['addr_street']['required'] ), 'required inherited from source (additive, source side)' );
$check( true === empty( $children['addr_zip']['required'] ), 'non-required source child stays non-required' );
$clone2_children = $registry->resolve_clone_children( $registry->get( 'clonesrc2' ) );
$check( true === ! empty( $clone2_children['addr_zip']['required'] ), 'required additive from clone side (clone required=true)' );
$check( 'Use the office address.' === ( $children['addr_street']['instructions'] ?? null ), 'clone instructions override wins when non-empty' );
$check( 'Street instructions.' === ( $clone2_children['addr_street']['instructions'] ?? null ), 'source instructions kept when clone has none' );

// Nested clone guard.
$check( array() === $registry->resolve_clone_children( $registry->get( 'outerclone' ) ), 'nested clone skipped (cycle guard)' );

// ------------------------------------------------- clone: fan-out/fan-in --
$check( true === Fields::update( 'clonesrc', array( 'addr_street' => 'Main St', 'type' => 'number', 'label' => 'Hacked' ), $post_id ), 'clone update with junk keys accepted' );
Fields::flush();
$check( 'Main St' === Fields::get( 'clonesrc.addr_street', $post_id, false ), 'fan-out wrote namespaced child' );
$check( 'Office Street' === ( $registry->resolve_clone_children( $registry->get( 'clonesrc' ) )['addr_street']['label'] ?? '' ), 'junk "label"/"type" keys cannot override the def' );

$check( true === Fields::update( 'clonesrc', array( 'addr_zip' => '42', 'addr_kind' => 'work' ), $post_id ), 'clone update with short keys accepted' );
Fields::flush();
$check( 42 === Fields::get( 'clonesrc.addr_zip', $post_id, false ), 'typed read after fan-out (number child)' );
$check( 'work' === Fields::get( 'clonesrc.addr_kind', $post_id, false ), 'select child stored' );

$fanin = Fields::get( 'clonesrc', $post_id );
$check( is_array( $fanin ) && 'Main St' === ( $fanin['addr_street'] ?? null ), 'fan-in keyed by short child name' );
$check( is_array( $fanin ) && ! isset( $fanin['clonesrc.addr_street'] ), 'fan-in strips the namespace prefix' );

$check( true === Fields::delete( 'clonesrc', $post_id ), 'clone delete fans out' );
Fields::flush();
$check( Unset_Value::is_unset( Fields::get( 'clonesrc', $post_id ) ), 'fan-in is unset when all children unset' );
$check( Unset_Value::is_unset( Fields::get( 'outerclone', $post_id ) ), 'unresolvable clone reads as unset' );

// ------------------------------------------------- adapters: user/option --
$user_id = wp_insert_user(
	array(
		'user_login' => 'tkfc_testuser',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => 'subscriber',
	)
);
$check( ! is_wp_error( $user_id ), 'test user created' );
if ( ! is_wp_error( $user_id ) ) {
	$user = get_user_by( 'id', $user_id );
	$check( true === Fields::update( 'addr_street', 'User St', $user ), 'usermeta adapter write' );
	Fields::flush();
	$check( 'User St' === Fields::get( 'addr_street', $user, false ), 'usermeta adapter read' );
	Fields::delete( 'addr_street', $user );
	Fields::flush();
}

$check( true === Fields::update( 'addr_zip', 90210, 'option' ), 'options adapter write' );
$check( 90210 === Fields::get( 'addr_zip', 'option', false ), 'options adapter read' );
Fields::delete( 'addr_zip', 'option' );
$check( Unset_Value::is_unset( Fields::get( 'addr_zip', 'option', false ) ), 'options adapter delete' );

// ------------------------------------------------- elementor wiring --------
$tag_file = dirname( __DIR__ ) . '/includes/integrations/elementor/tags/class-text-tag.php';
$tag_src  = is_readable( $tag_file ) ? (string) file_get_contents( $tag_file ) : '';
$check( false !== strpos( $tag_src, "'flexible_content'" ), 'elementor: flexible_content in supported_types (file check)' );
$check( false !== strpos( $tag_src, 'render_rows_html' ), 'elementor: rendered-layouts-HTML branch present (file check)' );

// ------------------------------------------------------------ cleanup -----
Fields::delete( 'clonesrc2', $post_id );
Fields::flush();
if ( isset( $flex_post_id ) && $flex_post_id > 0 ) {
	wp_delete_post( (int) $flex_post_id, true );
}
if ( $term_id > 0 ) {
	delete_term_meta( $term_id, 'fc_sections' );
	wp_delete_term( $term_id, 'category' );
}
if ( ! is_wp_error( $user_id ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $user_id );
}
foreach ( array( $source, $flexible, $clone_group, $nested, $outer ) as $group ) {
	if ( ! is_wp_error( $group ) && isset( $group['id'] ) ) {
		wp_delete_post( (int) $group['id'], true );
	}
}
$registry->reset_group_cache();

echo $failures > 0 ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit( $failures > 0 ? 1 : 0 );
