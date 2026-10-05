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
 * Group field test — run from the lab root:
 *
 *   ./bin/wp --allow-root --path=www eval-file \
 *     wp-content/plugins/tk-fields/tests/test-group.php
 *
 * Exits 0 when every assertion passes, 1 otherwise. Creates its own post,
 * then deletes it — leaves no trace. Registers a group field
 * programmatically (the same shape the group store persists).
 *
 * Locked v0.11.0 contracts under test:
 * - group is a container with valued children (is_container true, stores false)
 * - group key stores NOTHING: zero meta rows for the parent
 * - underscore namespace: contact_details + street -> contact_details_street
 * - get() fans in keyed by SHORT sub-field name, each child formatted
 * - update() fans out: short or namespaced keys accepted, unknown ignored
 * - two-pass: a failing child refuses the whole write
 * - nested group/clone/flexible sub-fields and layout-only types are rejected
 * - direct child get() works (children are first-class registry fields)
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

$reg = Field_Registry::instance();

$check( Field_Registry::is_container( 'group' ), 'group is a container' );
$check( ! Field_Registry::stores( 'group' ), 'group stores() is false (parent holds no meta)' );

$reg->register(
	array(
		'key'   => 'f_contact_details',
		'label' => 'Contact Details',
		'name'  => 'contact_details',
		'type'  => 'group',
		'sub_fields' => array(
			array( 'key' => 'f_street', 'label' => 'Street', 'name' => 'street', 'type' => 'text' ),
			array( 'key' => 'f_city', 'label' => 'City', 'name' => 'city', 'type' => 'text', 'required' => true ),
			array( 'key' => 'f_primary', 'label' => 'Primary?', 'name' => 'primary', 'type' => 'checkbox' ),
		),
	)
);

$f = $reg->get( 'contact_details' );
$check( null !== $f && 'group' === ( $f['type'] ?? '' ), 'group field registered' );
$check( 3 === count( $f['sub_fields'] ?? array() ), '3 sub-fields on the definition' );

$children = $reg->resolve_group_children( $f );
$check(
	array( 'street', 'city', 'primary' ) === array_keys( $children ),
	'children keyed by short name'
);
$check(
	'contact_details_street' === ( $children['street']['name'] ?? '' ),
	'underscore namespace (contact_details_street)'
);

$child_def = $reg->get( 'contact_details_city' );
$check( null !== $child_def, 'child is a first-class registry field' );
$check( ! empty( $child_def['_tk_group_child'] ), 'child carries the _tk_group_child marker' );

// Read-time fail-closed guards: nested group sub-field is skipped.
$reg->register(
	array(
		'key'   => 'f_nested',
		'label' => 'Nested',
		'name'  => 'nested_group',
		'type'  => 'group',
		'sub_fields' => array(
			array( 'key' => 'f_inner', 'label' => 'Inner', 'name' => 'inner', 'type' => 'group', 'sub_fields' => array() ),
			array( 'key' => 'f_ok', 'label' => 'OK', 'name' => 'ok', 'type' => 'text' ),
		),
	)
);
$nested = $reg->resolve_group_children( $reg->get( 'nested_group' ) );
$check( array( 'ok' ) === array_keys( $nested ), 'nested group sub-field rejected at read time' );

// Layout-only sub-field rejected at read time.
$reg->register(
	array(
		'key'   => 'f_withlayout',
		'label' => 'WithLayout',
		'name'  => 'withlayout_group',
		'type'  => 'group',
		'sub_fields' => array(
			array( 'key' => 'f_msg', 'label' => 'Msg', 'name' => 'msg', 'type' => 'message' ),
			array( 'key' => 'f_ok2', 'label' => 'OK', 'name' => 'ok', 'type' => 'text' ),
		),
	)
);
$wl = $reg->resolve_group_children( $reg->get( 'withlayout_group' ) );
$check( array( 'ok' ) === array_keys( $wl ), 'layout-only sub-field rejected at read time' );

// Round-trip on a real post.
$post_id = wp_insert_post(
	array( 'post_title' => 'Group Test Post', 'post_status' => 'draft', 'post_type' => 'post' )
);
$check( $post_id > 0, 'test post created' );

$check(
	Fields::update( 'contact_details', array( 'street' => '12 Palm Ave', 'city' => 'Lekki', 'primary' => true ), $post_id ),
	'group update fans out'
);

$v = Fields::get( 'contact_details', $post_id, true );
$check(
	is_array( $v ) && '12 Palm Ave' === ( $v['street'] ?? null ) && 'Lekki' === ( $v['city'] ?? null ) && true === ( $v['primary'] ?? null ),
	'group get fans in keyed by short name (checkbox formatted to bool)'
);
$check( 'Lekki' === Fields::get( 'contact_details_city', $post_id, true ), 'direct child get works' );

// The parent stores NOTHING: write queue flushes at shutdown, so assert via
// the storage layer directly after forcing the flush.
do_action( 'shutdown' );
$meta = get_post_meta( $post_id );
$check( ! array_key_exists( 'tk_contact_details', $meta ) && ! array_key_exists( 'contact_details', $meta ), 'parent key has zero meta rows' );
$check( array_key_exists( 'contact_details_street', $meta ), 'child stored under namespaced key' );

// Two-pass: required child empty -> whole write refused, nothing half-written.
$before = Fields::get( 'contact_details_street', $post_id, true );
$check(
	! Fields::update( 'contact_details', array( 'street' => 'Changed', 'city' => '' ), $post_id ),
	'required child violation refuses the group write'
);
do_action( 'shutdown' );
$check(
	$before === Fields::get( 'contact_details_street', $post_id, true ),
	'failed write leaves prior values untouched'
);

// Unknown keys ignored; namespaced keys accepted.
$check(
	Fields::update( 'contact_details', array( 'city' => 'Ikeja', 'nope' => 'zz' ), $post_id ),
	'unknown sub-field keys ignored'
);
$check( 'Ikeja' === Fields::get( 'contact_details_city', $post_id, true ), 'city updated via short key' );
$check(
	Fields::update( 'contact_details', array( 'contact_details_street' => '5 Marina Rd' ), $post_id ),
	'namespaced write key accepted'
);
do_action( 'shutdown' );
$check( '5 Marina Rd' === Fields::get( 'contact_details_street', $post_id, true ), 'street updated via namespaced key' );

// Non-array value refused.
$check( ! Fields::update( 'contact_details', 'nope', $post_id ), 'non-array group value refused' );

// Context export: group exported as object, never omitted.
$rc = new \ReflectionClass( Context_Export::class );
$fe = $rc->getMethod( 'field_entry' );
$fe->setAccessible( true );
$layout_omitted = array();
$entry          = $fe->invokeArgs( null, array( $f, &$layout_omitted ) );
$check( is_array( $entry ) && 'group' === ( $entry['type'] ?? '' ), 'group exported (not omitted)' );
$check( false === ( $entry['required'] ?? null ), 'export hard-codes group required=false' );
$check( array( 'street', 'city', 'primary' ) === array_keys( $entry['sub_fields'] ?? array() ), 'export sub-fields keyed by short name' );
$check( 'contact_details_city' === ( $entry['sub_fields']['city']['storage_key'] ?? '' ), 'export carries storage_key' );

wp_delete_post( $post_id, true );

echo $failures > 0 ? "\n{$failures} FAILURES\n" : "\nALL PASS\n";
exit( $failures > 0 ? 1 : 0 );
