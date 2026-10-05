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
 * Repeater Elementor tag test — run from the lab root:
 *
 *   ./bin/wp --allow-root --path=www eval-file \
 *     wp-content/plugins/tk-fields/tests/test-repeater-tag.php
 *
 * Exits 0 when every assertion passes, 1 otherwise. Creates its own post,
 * then deletes it — leaves no trace. Registers a mock repeater field
 * programmatically (the same shape the group store persists) and seeds
 * rows through the canonical block path: Repeater::serialize_rows() into
 * post_content (locked Q4 — posts store repeater rows as tk/field-repeater
 * blocks, never postmeta, exactly like get_flexible()), then asserts the
 * tag's get_value() shape for several sub-field paths.
 *
 * Locked Q9 contract under test:
 * - new field-bound dynamic-tag class (tk-fields-repeater)
 * - resolves repeater field + sub-field path via the canonical Fields
 *   service typed read (never postmeta directly)
 * - one entry per row, in stored order — 1:1 with the TK Repeater
 *   widget's row sequence; rows missing the path keep their position
 *   as null (cardinality is never silently changed)
 * - nested repeater paths (lessons.title) map over nested rows
 * - empty path returns every row's full typed values
 * - read-only: no authoring surface
 *
 * NOTE: no `declare(strict_types=1)` here — wp-cli's eval-file wraps this in
 * eval(), where a declare is not the first statement and fatals.
 */

namespace TK\Fields;

use TK\Fields\Integrations\Elementor\Module;
use TK\Fields\Integrations\Elementor\Tags\Repeater_Tag;

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

$reg = Field_Registry::instance();

// --- Mock field definition: repeater with a nested repeater sub-field. ---
$check(
	$reg->register(
		array(
			'key'        => 'f_team',
			'label'      => 'Team',
			'name'       => 'team',
			'type'       => 'repeater',
			'min'        => 1,
			'max'        => 10,
			'button_label' => 'Add Member',
			'layout'     => 'list',
			'sub_fields' => array(
				array( 'key' => 'f_member_name', 'label' => 'Name', 'name' => 'member_name', 'type' => 'text' ),
				array( 'key' => 'f_member_hours', 'label' => 'Hours', 'name' => 'member_hours', 'type' => 'number' ),
				array(
					'key'        => 'f_member_lessons',
					'label'      => 'Lessons',
					'name'       => 'lessons',
					'type'       => 'repeater',
					'sub_fields' => array(
						array( 'key' => 'f_lesson_title', 'label' => 'Lesson title', 'name' => 'lesson_title', 'type' => 'text' ),
					),
				),
			),
		)
	),
	'mock repeater field registers'
);

$f = $reg->get( 'team' );
$check( null !== $f && 'repeater' === ( $f['type'] ?? '' ), 'repeater field definition resolves' );
$check( 3 === count( $reg->resolve_repeater_children( $f ) ), '3 sub-fields resolve (read-time children)' );

// --- Seed CQ1-shaped rows through the Fields service. ---
$post_id = wp_insert_post(
	array( 'post_title' => 'Repeater Tag Test Post', 'post_status' => 'draft', 'post_type' => 'post' )
);
$check( $post_id > 0, 'test post created' );

$rows = array(
	array(
		'member_name'  => 'Ada',
		'member_hours' => 6,
		'lessons'      => array(
			array( 'lesson_title' => 'Intro' ),
			array( 'lesson_title' => 'Deep dive' ),
		),
	),
	array(
		'member_name'  => 'Bola',
		'member_hours' => 4,
		'lessons'      => array(
			array( 'lesson_title' => 'Basics' ),
		),
	),
);

// --- Seed rows through the canonical block path (locked Q4): posts
// store repeater rows as tk/field-repeater blocks in post_content, never
// postmeta. Bare CQ1 rows go through sanitize() (canonical {id, fields}
// with generated row IDs) then serialize_rows() into post_content.
$seed_rows = function ( int $post_id, array $field, array $bare_rows ) use ( $reg ): void {
	$markup = Repeater::serialize_rows( $field, $reg->sanitize( $field, $bare_rows ) );
	// wp_insert_post() unslashes its input first (it expects slashed data
	// as from $_POST), so slash the markup — otherwise backslash escapes
	// in block attributes (e.g. \u003c from serialize_block_attributes())
	// are eaten at write time and values corrupt on read-back.
	wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_slash( $markup ) ) );
};
$seed_rows( $post_id, $f, $rows );

$service_rows = Fields::get( 'team', $post_id );
$check(
	is_array( $service_rows ) && 2 === count( $service_rows ) && 'Ada' === ( $service_rows[0]['member_name'] ?? null ),
	'Fields::get() returns the seeded rows through the service'
);

// Elementor's Context::resolve_post_id() falls back to get_the_ID() outside
// the editor: point the global post at the test post.
$GLOBALS['post'] = get_post( $post_id );

/**
 * Invoke the tag's protected get_value() with the given settings.
 *
 * The settings property is injected via reflection (Base_Object only
 * builds settings from the controls stack when it is null), so the
 * test exercises the tag's resolution logic without the editor.
 */
$tag_value = function ( string $field_key, string $path ) {
	$tag = new Repeater_Tag();
	$rp  = new \ReflectionProperty( \Elementor\Core\Base\Base_Object::class, 'settings' );
	$rp->setAccessible( true );
	$rp->setValue( $tag, array( 'field_key' => $field_key, 'sub_field_path' => $path ) );
	$m = new \ReflectionMethod( Repeater_Tag::class, 'get_value' );
	$m->setAccessible( true );
	return $m->invoke( $tag );
};

// --- Tag identity. ---
$tag = new Repeater_Tag();
$check( 'tk-fields-repeater' === $tag->get_name(), 'tag name is tk-fields-repeater' );
$check( in_array( Module::GROUP, $tag->get_group(), true ), 'tag belongs to the tk-fields group' );
$check( 'field_key' === $tag->get_panel_template_setting_key(), 'panel template key is field_key' );

// --- Value resolution. ---
$check(
	array( 'Ada', 'Bola' ) === $tag_value( 'team', 'member_name' ),
	"get_value('member_name') returns one typed value per row, in order"
);
$check(
	array( 6, 4 ) === $tag_value( 'team', 'member_hours' ),
	"get_value('member_hours') returns the number sub-field values"
);
$check(
	array( array( 'Intro', 'Deep dive' ), array( 'Basics' ) ) === $tag_value( 'team', 'lessons.lesson_title' ),
	"get_value('lessons.lesson_title') maps the nested repeater rows"
);
$check(
	$rows === $tag_value( 'team', '' ),
	'empty path returns every row full values (CQ1 shape)'
);

// A row missing the path keeps its position as null — cardinality preserved.
$seed_rows( $post_id, $f, array( array( 'member_name' => 'Ada' ), array( 'member_hours' => 4 ) ) );
$check(
	array( 'Ada', null ) === $tag_value( 'team', 'member_name' ),
	'row missing the path contributes null (alignment preserved)'
);

// Fail-closed cases.
$check( array() === $tag_value( '', 'member_name' ), 'no field selected -> empty array' );
$check( array() === $tag_value( 'nope', 'member_name' ), 'unknown field -> empty array' );
$check( array() === $tag_value( 'team', '!!!' ), 'invalid path characters -> empty array' );

$post_id2 = wp_insert_post(
	array( 'post_title' => 'Repeater Tag Empty Post', 'post_status' => 'draft', 'post_type' => 'post' )
);
$GLOBALS['post'] = get_post( $post_id2 );
Fields::clear_memo();
$check( array() === $tag_value( 'team', 'member_name' ), 'field with no rows -> empty array' );
wp_delete_post( $post_id2, true );

// A non-repeater field is refused even when forced programmatically.
$reg->register(
	array( 'key' => 'f_plain', 'label' => 'Plain', 'name' => 'plain_text', 'type' => 'text' )
);
update_post_meta( $post_id, 'plain_text', 'hello' );
Fields::clear_memo();
$GLOBALS['post'] = get_post( $post_id );
$check( array() === $tag_value( 'plain_text', '' ), 'non-repeater field refused (fail closed)' );

// --- get_content(): the tag's output-escaping layer. ---
// get_value() stays raw (typed data layer, asserted above); get_content()
// is what Elementor renders, so markup in row values must reach output
// escaped — the stored-XSS case when a wysiwyg sub-field carries the
// "allow unfiltered HTML" opt-in (raw HTML is then stored deliberately).
$reg->register(
	array(
		'key'        => 'f_xss',
		'label'      => 'XSS Probe',
		'name'       => 'xss_probe',
		'type'       => 'repeater',
		'sub_fields' => array(
			array( 'key' => 'f_xss_bio', 'label' => 'Bio', 'name' => 'bio', 'type' => 'wysiwyg', 'allow_unfiltered' => true ),
		),
	)
);
$check( 'repeater' === ( $reg->get( 'xss_probe' )['type'] ?? '' ), 'xss-probe repeater registers' );

$tag_content = function ( string $field_key, string $path ) {
	$tag = new Repeater_Tag();
	$rp  = new \ReflectionProperty( \Elementor\Core\Base\Base_Object::class, 'settings' );
	$rp->setAccessible( true );
	$rp->setValue( $tag, array( 'field_key' => $field_key, 'sub_field_path' => $path ) );
	return $tag->get_content();
};

$seed_rows( $post_id, $reg->get( 'xss_probe' ), array( array( 'bio' => '<img src=x onerror=alert(1)>' ) ) );
$raw_rows = $tag_value( 'xss_probe', 'bio' );
$check(
	array( '<img src=x onerror=alert(1)>' ) === $raw_rows,
	'get_value still returns the raw unfiltered markup (data layer unchanged)'
);
$escaped = $tag_content( 'xss_probe', 'bio' );
$check(
	'&lt;img src=x onerror=alert(1)&gt;' === $escaped,
	'get_content escapes unfiltered row markup (stored-XSS neutralized)'
);
$check(
	false === strpos( $escaped, '<img' ),
	'get_content output contains no raw markup'
);
$full = $tag_content( 'xss_probe', '' );
$check(
	is_string( $full ) && false !== strpos( $full, '&lt;img src=x onerror=alert(1)&gt;' ) && false === strpos( $full, '<img' ),
	'get_content reduces array rows to escaped text (no raw markup)'
);
$check(
	is_string( $tag_content( 'nope', 'member_name' ) ) && '' === $tag_content( 'nope', 'member_name' ),
	'get_content returns a string (never an array) on fail-closed paths'
);

// --- Pure path-resolution unit checks (no service involved). ---
$check(
	array( array( 'a' ), array() ) === Repeater_Tag::resolve_path(
		array( array( 'x' => array( array( 'y' => 'a' ) ) ), array( 'x' => array() ) ),
		'x.y'
	),
	'resolve_path: nested list maps, empty nested list stays empty'
);
$check(
	array( null ) === Repeater_Tag::resolve_path( array( array( 'a' => 1 ) ), 'a.b' ),
	'resolve_path: path through a scalar dead-ends as null'
);
$check(
	array( array( 'id' => 7, 'url' => 'http://x.test' ) ) === Repeater_Tag::resolve_path(
		array( array( 'photo' => array( 'id' => 7, 'url' => 'http://x.test' ) ) ),
		'photo'
	),
	'resolve_path: whole typed DTO returned for a DTO sub-field'
);

wp_delete_post( $post_id, true );

echo $failures > 0 ? "\n{$failures} FAILURES\n" : "\nALL PASS\n";
exit( $failures > 0 ? 1 : 0 );
