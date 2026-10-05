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
 * Repeater field test — run from the lab root:
 *
 *   ./bin/wp --allow-root --path=/home/hatch/workspace/wordpress-test/www eval-file \
 *     /home/hatch/workspace/wordpress-test/www/wp-content/plugins/tk-fields/tests/test-repeater.php
 *
 * Exits 0 when every assertion passes, 1 otherwise. Registers its own
 * repeater fields, creates field groups, a term, and posts, then deletes
 * them — leaves no trace.
 *
 * Locked Round 5 (repeater) contracts under test:
 * - Registry: TYPES/stores/is_container, register + setting carry,
 *   lenient sub normalization (drop malformed/clone/flexible/layout-only,
 *   depth-3 repeater dropped).
 * - sanitize: canonical {id, fields} rows, bare rows get UUIDs, per-sub
 *   sanitizers, nested recursion, group-leaf recursion, 'id'/'fields'
 *   reserved keys stripped from bare rows.
 * - validate: happy path, required sub, min/max ROW counts at validate
 *   (never clamped), non-array refused, empty allowed when not required.
 * - format (CQ1): list<array<string,mixed>> keyed by short sub name, no
 *   row ids, each sub value identical to that type's own format(),
 *   nested repeaters recurse to the same shape.
 * - Group store: settings persisted, deterministic sub keys, min>max /
 *   depth-3 / clone / flexible / layout-only / duplicate-name hard errors,
 *   group-in-repeater and repeater-in-group allowed with depth counting.
 * - Fields service: post updates refused, term round-trip as serialized
 *   block markup, formatted vs canonical reads, invalid rows rejected.
 * - Block store read: 2x2 nested editor markup, nested tk_have_rows loops
 *   scoped by the path stack, current_path() segments, CQ3 regression
 *   (row 1/1 byte-identical after editing row 2/2), revision-safe reads.
 * - Context export (CQ2): {type, name, min, max, button_label, layout,
 *   sub_fields} with recursive list sub_fields.
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

// ------------------------------------------------- registry basics ---------
$check( in_array( 'repeater', Field_Registry::types(), true ), 'repeater is a registered type' );
$check( Field_Registry::stores( 'repeater' ), 'repeater stores rows' );
$check( ! Field_Registry::is_container( 'repeater' ), 'repeater is not a fan-out container' );

// --------------------------------------- register + setting carry ---------
$rep_def = array(
	'key'          => 'f_rep_team',
	'name'         => 'rep_team',
	'label'        => 'Team',
	'type'         => 'repeater',
	'min'          => 1,
	'max'          => 4,
	'button_label' => 'Add Member',
	'layout'       => 'grid',
	'collapsed'    => 'name',
	'sub_fields'   => array(
		array( 'name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true ),
		array( 'name' => 'admin', 'label' => 'Admin', 'type' => 'checkbox' ),
		array( 'name' => 'age', 'label' => 'Age', 'type' => 'number' ),
		array(
			'name'       => 'lessons',
			'label'      => 'Lessons',
			'type'       => 'repeater',
			'sub_fields' => array(
				array( 'name' => 'title', 'label' => 'Title', 'type' => 'text' ),
				array(
					'name'       => 'drills',
					'label'      => 'Drills',
					'type'       => 'repeater',
					'sub_fields' => array(
						array( 'name' => 'label', 'label' => 'Label', 'type' => 'text' ),
						// Depth 3 — the whole drills subtree is dropped
						// leniently (cap is 2 levels: top + one nested).
						array(
							'name'       => 'steps',
							'label'      => 'Steps',
							'type'       => 'repeater',
							'sub_fields' => array( array( 'name' => 's', 'label' => 'S', 'type' => 'text' ) ),
						),
					),
				),
			),
		),
		array(
			'name'       => 'contact',
			'label'      => 'Contact',
			'type'       => 'group',
			'sub_fields' => array( array( 'name' => 'email', 'label' => 'Email', 'type' => 'text' ) ),
		),
		// Lenient drops: clone, flexible, layout-only, malformed, bad name.
		array( 'name' => 'bad_clone', 'label' => 'Bad', 'type' => 'clone' ),
		array( 'name' => 'bad_flex', 'label' => 'Bad', 'type' => 'flexible_content' ),
		array( 'name' => 'bad_msg', 'label' => 'Bad', 'type' => 'message' ),
		'not-an-array',
		array( 'name' => 'Bad Name', 'label' => 'Bad', 'type' => 'text' ),
	),
);
$check( true === $registry->register( $rep_def ), 'repeater registers' );
$rep_team = $registry->get( 'rep_team' );
$check( is_array( $rep_team ), 'repeater retrievable' );
$check( 'grid' === ( $rep_team['layout'] ?? '' ), 'layout carried' );
$check( 'name' === ( $rep_team['collapsed'] ?? '' ), 'collapsed carried' );
$check( 'Add Member' === ( $rep_team['button_label'] ?? '' ), 'button_label carried' );
$check( 1 === ( $rep_team['min'] ?? null ) && 4 === ( $rep_team['max'] ?? null ), 'min/max carried' );

$subs = $rep_team['sub_fields'];
$sub_names = array();
foreach ( $subs as $s ) {
	$sub_names[] = $s['name'];
}
$check( array( 'name', 'admin', 'age', 'lessons', 'contact' ) === $sub_names, 'lenient normalization keeps only valid subs' );

$lessons = null;
foreach ( $subs as $s ) {
	if ( 'lessons' === $s['name'] ) {
		$lessons = $s;
	}
}
$check( is_array( $lessons ) && 'list' === ( $lessons['layout'] ?? '' ), 'nested repeater gets default layout' );
$lesson_names = array();
foreach ( (array) ( $lessons['sub_fields'] ?? array() ) as $s ) {
	$lesson_names[] = $s['name'];
}
// Depth cap is 2 levels (top + one nested): the depth-2 'drills' repeater
// — and with it the depth-3 'steps' — is dropped leniently.
$check( array( 'title' ) === $lesson_names, 'past-cap nested repeaters dropped leniently' );

// ------------------------------------------------------- sanitize ---------
$rows_in = array(
	array(
		'id'     => 'row-1',
		'fields' => array(
			'name'    => '  Ada  ',
			'admin'   => '1',
			'age'     => 36,
			'junk'    => 'dropped',
			'lessons' => array( array( 'title' => ' L1 ' ) ),
			'contact' => array( 'email' => ' ada@example.com ' ),
		),
	),
	// Bare row: gets a UUID. Reserved 'id'/'fields' keys never survive as
	// sub-field values in the bare shape.
	array( 'name' => 'Bob', 'admin' => '0' ),
	array( 'name' => 'Zed', 'id' => 'x', 'fields' => 'y' ),
	'junk-row',
);
$san = $registry->sanitize( $rep_team, $rows_in );
$check( 3 === count( $san ), 'sanitize keeps 3 valid rows' );
$check( 'row-1' === ( $san[0]['id'] ?? '' ), 'canonical row id preserved' );
$check(
	1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) ( $san[1]['id'] ?? '' ) ),
	'bare row gets a UUID'
);
$check( 'Ada' === ( $san[0]['fields']['name'] ?? null ), 'text sub trimmed' );
$check( 1 === ( $san[0]['fields']['admin'] ?? null ), 'checkbox sub sanitized to 1/0' );
$check( 36 === ( $san[0]['fields']['age'] ?? null ), 'number sub kept' );
$check( ! isset( $san[0]['fields']['junk'] ), 'unknown sub dropped' );
$check( 'Bob' === ( $san[1]['fields']['name'] ?? null ), 'bare row fields preserved' );
$check(
	'Zed' === ( $san[2]['fields']['name'] ?? null ) && ! isset( $san[2]['fields']['id'] ) && ! isset( $san[2]['fields']['fields'] ),
	'reserved id/fields keys stripped from bare rows'
);
$check( 'L1' === ( $san[0]['fields']['lessons'][0]['fields']['title'] ?? null ), 'nested repeater sanitized recursively' );
$check( '' !== ( $san[0]['fields']['lessons'][0]['id'] ?? '' ), 'nested row gets an id' );
$check( 'ada@example.com' === ( $san[0]['fields']['contact']['email'] ?? null ), 'group leaf sanitized recursively' );
$check( array() === $registry->sanitize( $rep_team, 'nope' ), 'non-array sanitizes to empty' );
$check( array() === $registry->sanitize( $rep_team, array() ), 'empty sanitizes to empty' );

// ------------------------------------------------------- validate ---------
$check( true === $registry->validate( $rep_team, $san ), 'happy rows validate' );
$check( false === $registry->validate( $rep_team, 'nope' ), 'non-array fails validate' );

$missing_required = $registry->sanitize( $rep_team, array( array( 'fields' => array( 'admin' => '1' ) ) ) );
$check( false === $registry->validate( $rep_team, $missing_required ), 'required sub missing fails validate' );

$req_field            = $rep_team;
$req_field['required'] = true;
$check( false === $registry->validate( $req_field, array() ), 'required + empty fails validate' );
$check( true === $registry->validate( $rep_team, array() ), 'non-required + empty passes (empty-allowed semantics)' );

$min_field        = $rep_team;
$min_field['min'] = 2;
$one_row          = array_slice( $san, 0, 1 );
$check( false === $registry->validate( $min_field, $one_row ), 'min row count enforced at validate' );
$check( true === $registry->validate( $min_field, $san ), 'min satisfied passes' );

$max_field        = $rep_team;
$max_field['max'] = 1;
$check( false === $registry->validate( $max_field, $san ), 'max row count enforced at validate' );
$check( 3 === count( $san ), 'validate never clamps rows' );

// --------------------------------------------------------- format (CQ1) ---
$fmt = $registry->format( $rep_team, $san );
$check( is_array( $fmt ) && 3 === count( $fmt ) && array_keys( $fmt ) === array( 0, 1, 2 ), 'format returns a list of rows' );
$check( array( 'name', 'admin', 'age', 'lessons', 'contact' ) === array_keys( $fmt[0] ), 'format rows keyed by short sub name' );
$check( ! array_key_exists( 'id', $fmt[0] ), 'no row ids in the public value namespace' );
$check( 'Ada' === $fmt[0]['name'] && true === $fmt[0]['admin'] && 36 === $fmt[0]['age'], 'sub values typed (checkbox is bool)' );
$check( false === $fmt[1]['admin'], 'unchecked checkbox formats to false' );
$check(
	is_array( $fmt[0]['lessons'] ) && array( 'title' ) === array_keys( $fmt[0]['lessons'][0] ) && 'L1' === $fmt[0]['lessons'][0]['title'],
	'nested repeater formats to the same flat shape'
);
$check(
	array( 'email' => 'ada@example.com' ) === $fmt[0]['contact'],
	'group sub formats to its leaf object'
);

// CQ1: each sub value is identical to that sub type's own format() of the
// stored value (format() itself does not trim — sanitize() does).
$children  = $registry->resolve_repeater_children( $rep_team );
$check(
	$registry->format( $children['name'], 'Ada' ) === $fmt[0]['name'],
	'text sub value identical to its own format()'
);
$check(
	$registry->format( $children['admin'], '1' ) === $fmt[0]['admin'],
	'checkbox sub value identical to its own format()'
);

// ----------------------------------------------------- group store --------
$group_ids = array();

$mk_group = function ( string $title, array $fields ) use ( $store, $check, &$group_ids ) {
	$res = $store->create(
		array(
			'title'          => $title,
			'location'       => array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ),
			'location_match' => 'all',
			'fields'         => $fields,
		)
	);
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$group_ids[] = (int) $res['id'];
	return $res;
};

$rep_field_args = array(
	'key'          => 'f_rep_store',
	'name'         => 'rep_store',
	'label'        => 'Members',
	'type'         => 'repeater',
	'min'          => 2,
	'max'          => 6,
	'button_label' => 'Add Row X',
	'layout'       => 'grid',
	'collapsed'    => 'full_name',
	'sub_fields'   => array(
		array( 'name' => 'full_name', 'label' => 'Full Name', 'type' => 'text', 'required' => true ),
		array(
			'name'       => 'sessions',
			'label'      => 'Sessions',
			'type'       => 'repeater',
			'sub_fields' => array( array( 'name' => 'topic', 'label' => 'Topic', 'type' => 'text' ) ),
		),
		array(
			'name'       => 'address',
			'label'      => 'Address',
			'type'       => 'group',
			'sub_fields' => array( array( 'name' => 'city', 'label' => 'City', 'type' => 'text' ) ),
		),
	),
);

$store_group = $mk_group( 'Rep Test — Store', array( $rep_field_args ) );
$check( ! is_wp_error( $store_group ), 'group with repeater created' );
$registry->reset_group_cache();

$stored = $registry->get( 'rep_store' );
$check( is_array( $stored ) && 'repeater' === ( $stored['type'] ?? '' ), 'repeater field persisted' );
$check( 'grid' === ( $stored['layout'] ?? '' ), 'layout persisted' );
$check( 'full_name' === ( $stored['collapsed'] ?? '' ), 'collapsed persisted' );
$check( 'Add Row X' === ( $stored['button_label'] ?? '' ), 'button_label persisted' );
$check( 2 === (int) ( $stored['min'] ?? 0 ) && 6 === (int) ( $stored['max'] ?? 0 ), 'min/max persisted' );

$expect_key = 'f_' . substr( md5( 'tk_repeater:rep_store:full_name' ), 0, 20 );
$found_key  = '';
foreach ( (array) ( $stored['sub_fields'] ?? array() ) as $s ) {
	if ( 'full_name' === ( $s['name'] ?? '' ) ) {
		$found_key = (string) ( $s['key'] ?? '' );
	}
}
$check( $expect_key === $found_key, 'deterministic sub-field keys (md5 tk_repeater:...)' );

$nested_key_expect = 'f_' . substr( md5( 'tk_repeater:sessions:topic' ), 0, 20 );
$nested_key_found  = '';
foreach ( (array) ( $stored['sub_fields'] ?? array() ) as $s ) {
	if ( 'sessions' === ( $s['name'] ?? '' ) ) {
		foreach ( (array) ( $s['sub_fields'] ?? array() ) as $leaf ) {
			if ( 'topic' === ( $leaf['name'] ?? '' ) ) {
				$nested_key_found = (string) ( $leaf['key'] ?? '' );
			}
		}
	}
}
$check( $nested_key_expect === $nested_key_found, 'nested repeater subs get deterministic keys too' );

$group_leaf_ok = false;
foreach ( (array) ( $stored['sub_fields'] ?? array() ) as $s ) {
	if ( 'address' === ( $s['name'] ?? '' ) && 'group' === ( $s['type'] ?? '' ) ) {
		foreach ( (array) ( $s['sub_fields'] ?? array() ) as $leaf ) {
			if ( 'city' === ( $leaf['name'] ?? '' ) ) {
				$group_leaf_ok = true;
			}
		}
	}
}
$check( $group_leaf_ok, 'group sub-field allowed inside repeater' );

// Re-save stability: deterministic keys do not change.
$store_group2 = $mk_group( 'Rep Test — Store 2', array( $rep_field_args ) );
$registry->reset_group_cache();
$stored2    = $registry->get( 'rep_store' );
$found_key2 = '';
foreach ( (array) ( $stored2['sub_fields'] ?? array() ) as $s ) {
	if ( 'full_name' === ( $s['name'] ?? '' ) ) {
		$found_key2 = (string) ( $s['key'] ?? '' );
	}
}
$check( $expect_key === $found_key2, 'sub-field keys stable across group re-saves' );

// --- hard errors (fail closed, never silent) ---
$bad_minmax            = $rep_field_args;
$bad_minmax['min']     = 5;
$bad_minmax['max']     = 3;
$bad_minmax['name']    = 'rep_bad_minmax';
$bad_minmax['key']     = 'f_rep_bad_minmax';
$check( is_wp_error( $mk_group( 'Rep Test — min>max', array( $bad_minmax ) ) ), 'min > max is a hard error' );

$deep                      = $rep_field_args;
$deep['name']              = 'rep_deep';
$deep['key']               = 'f_rep_deep';
$deep['sub_fields']        = array(
	array(
		'name'       => 'l1',
		'label'      => 'L1',
		'type'       => 'repeater',
		'sub_fields' => array(
			array(
				'name'       => 'l2',
				'label'      => 'L2',
				'type'       => 'repeater',
				'sub_fields' => array( array( 'name' => 'x', 'label' => 'X', 'type' => 'text' ) ),
			),
		),
	),
);
$check( is_wp_error( $mk_group( 'Rep Test — depth 3', array( $deep ) ) ), 'third nesting level is a hard error' );

foreach ( array( 'clone', 'flexible_content' ) as $bad_type ) {
	$bad              = $rep_field_args;
	$bad['name']      = 'rep_bad_' . $bad_type;
	$bad['key']       = 'f_rep_bad_' . $bad_type;
	$bad['sub_fields'] = array( array( 'name' => 'x', 'label' => 'X', 'type' => $bad_type ) );
	$check( is_wp_error( $mk_group( 'Rep Test — ' . $bad_type, array( $bad ) ) ), $bad_type . ' sub-field is a hard error' );
}

$bad_layoutonly              = $rep_field_args;
$bad_layoutonly['name']      = 'rep_bad_msg';
$bad_layoutonly['key']       = 'f_rep_bad_msg';
$bad_layoutonly['sub_fields'] = array( array( 'name' => 'x', 'label' => 'X', 'type' => 'message' ) );
$check( is_wp_error( $mk_group( 'Rep Test — message', array( $bad_layoutonly ) ) ), 'layout-only sub-field is a hard error' );

$bad_dup              = $rep_field_args;
$bad_dup['name']      = 'rep_bad_dup';
$bad_dup['key']       = 'f_rep_bad_dup';
$bad_dup['sub_fields'] = array(
	array( 'name' => 'x', 'label' => 'X', 'type' => 'text' ),
	array( 'name' => 'x', 'label' => 'X2', 'type' => 'text' ),
);
$check( is_wp_error( $mk_group( 'Rep Test — dup', array( $bad_dup ) ) ), 'duplicate sub-field name is a hard error' );

$bad_layoutval              = $rep_field_args;
$bad_layoutval['name']      = 'rep_bad_layout';
$bad_layoutval['key']       = 'f_rep_bad_layout';
$bad_layoutval['layout']    = 'masonry';
$layoutval_group            = $mk_group( 'Rep Test — bad layout', array( $bad_layoutval ) );
$check( ! is_wp_error( $layoutval_group ), 'invalid layout value does not fail the group' );
$registry->reset_group_cache();
$layoutval_stored = $registry->get( 'rep_bad_layout' );
$check( 'list' === ( $layoutval_stored['layout'] ?? '' ), 'invalid layout falls back to list' );

// repeater-in-group: allowed, and the depth cap counts through the group.
$rig = $mk_group(
	'Rep Test — repeater in group',
	array(
		array(
			'key'        => 'f_rig_outer',
			'name'       => 'rig_outer',
			'label'      => 'Outer',
			'type'       => 'group',
			'sub_fields' => array(
				array(
					'name'       => 'inner_rep',
					'label'      => 'Inner',
					'type'       => 'repeater',
					'sub_fields' => array( array( 'name' => 't', 'label' => 'T', 'type' => 'text' ) ),
				),
			),
		),
	)
);
$check( ! is_wp_error( $rig ), 'repeater inside a group is allowed' );

$rig_deep = $mk_group(
	'Rep Test — repeater in group deep',
	array(
		array(
			'key'        => 'f_rig_deep',
			'name'       => 'rig_deep',
			'label'      => 'Deep',
			'type'       => 'group',
			'sub_fields' => array(
				array(
					'name'       => 'r1',
					'label'      => 'R1',
					'type'       => 'repeater',
					'sub_fields' => array(
						array(
							'name'       => 'r2',
							'label'      => 'R2',
							'type'       => 'repeater',
							'sub_fields' => array(
								array(
									'name'       => 'r3',
									'label'      => 'R3',
									'type'       => 'repeater',
									'sub_fields' => array( array( 'name' => 't', 'label' => 'T', 'type' => 'text' ) ),
								),
							),
						),
					),
				),
			),
		),
	)
);
$check( is_wp_error( $rig_deep ), 'depth cap counts through groups (group > rep > rep > rep fails)' );

// --------------------------------------------- Fields service (term) -----
// Pre-cleanup: an earlier crashed run may have left the term behind.
$leftover = get_term_by( 'name', 'Rep Test Term', 'category' );
if ( $leftover instanceof \WP_Term ) {
	wp_delete_term( (int) $leftover->term_id, 'category' );
}
$term_id = 0;
$term_res = wp_insert_term( 'Rep Test Term', 'category' );
if ( ! is_wp_error( $term_res ) ) {
	$term_id = (int) $term_res['term_id'];
}
$check( $term_id > 0, 'test term created' );
$term = $term_id > 0 ? get_term( $term_id ) : null;

if ( $term instanceof \WP_Term ) {
	$term_rows = $registry->sanitize(
		$rep_team,
		array(
			array(
				'id'     => 't1',
				'fields' => array(
					'name'    => 'Cara',
					'admin'   => '1',
					'age'     => 29,
					'lessons' => array( array( 'title' => 'Intro' ) ),
					'contact' => array( 'email' => 'cara@example.com' ),
				),
			),
			array( 'name' => 'Dan' ),
		)
	);
	$check( true === Fields::update( 'rep_team', $term_rows, $term ), 'repeater update on term accepted' );
	$meta = get_term_meta( $term_id, 'rep_team', true );
	$check( is_string( $meta ) && false !== strpos( $meta, '<!-- wp:tk/field-repeater' ), 'term meta holds serialized repeater block markup' );
	$check( false !== strpos( $meta, '<!-- wp:tk/repeater-row' ), 'term meta holds row blocks' );

	$got = Fields::get( 'rep_team', $term );
	$check( is_array( $got ) && 'Cara' === ( $got[0]['name'] ?? null ) && true === $got[0]['admin'], 'term read returns formatted rows' );
	$check( 'Intro' === ( $got[0]['lessons'][0]['title'] ?? null ), 'term read formats nested rows' );
	$check( ! array_key_exists( 'id', $got[0] ), 'term read has no row ids in value namespace' );

	$got_raw = Fields::get( 'rep_team', $term, false );
	$check(
		is_array( $got_raw ) && 't1' === ( $got_raw[0]['id'] ?? null ) && 'Cara' === ( $got_raw[0]['fields']['name'] ?? null ),
		'unformatted term read is the canonical {id, fields} shape'
	);

	$over_max = $rep_team;
	$over_max['max'] = 1;
	$registry->register( $over_max + array( 'key' => 'f_rep_team' ) );
	$check( false === Fields::update( 'rep_team', $term_rows, $term ), 'rows violating max rejected at save on term' );
	$registry->register( $rep_def );
	$registry->reset_group_cache();
}

// ------------------------------------------------- post update refused ----
$post_any = get_posts( array( 'post_type' => 'post', 'posts_per_page' => 1, 'post_status' => 'any' ) );
$post_any_id = $post_any ? (int) $post_any[0]->ID : 0;
$check( $post_any_id > 0, 'test post exists' );
$check( false === Fields::update( 'rep_team', $san, $post_any_id ), 'repeater update on post refused (block editor owns post_content)' );

// --------------------------------- post read: 2x2 nested markup + CQ3 ----
$modules_def = array(
	'key'        => 'f_rep_modules',
	'name'       => 'rep_modules',
	'label'      => 'Modules',
	'type'       => 'repeater',
	'min'        => 0,
	'max'        => 0,
	'sub_fields' => array(
		array( 'name' => 'title', 'label' => 'Title', 'type' => 'text' ),
		array(
			'name'       => 'lessons',
			'label'      => 'Lessons',
			'type'       => 'repeater',
			'sub_fields' => array( array( 'name' => 'heading', 'label' => 'Heading', 'type' => 'text' ) ),
		),
	),
);
$registry->register( $modules_def );
$modules_field = $registry->get( 'rep_modules' );

$modules_rows = $registry->sanitize(
	$modules_field,
	array(
		array(
			'id'     => 'm1',
			'fields' => array(
				'title'   => 'M1',
				'lessons' => array(
					array( 'id' => 'l1', 'fields' => array( 'heading' => 'L1' ) ),
					array( 'id' => 'l2', 'fields' => array( 'heading' => 'L2' ) ),
				),
			),
		),
		array(
			'id'     => 'm2',
			'fields' => array(
				'title'   => 'M2',
				'lessons' => array(
					array( 'id' => 'l3', 'fields' => array( 'heading' => 'L3' ) ),
					array( 'id' => 'l4', 'fields' => array( 'heading' => 'L4' ) ),
				),
			),
		),
	)
);
$editor_markup = Repeater::serialize_rows( $modules_field, $modules_rows );
$check( false !== strpos( $editor_markup, 'tk/field-repeater' ), 'serialize_rows emits the wrapper block' );

$mod_post_id = wp_insert_post(
	array(
		'post_title'   => 'Rep Test Post',
		'post_type'    => 'post',
		'post_status'  => 'draft',
		'post_content' => $editor_markup,
	)
);
$check( $mod_post_id > 0, 'repeater test post created' );

$raw_before = Repeater::rows_raw( $modules_field, 'rep_modules', (int) $mod_post_id );
$check( 2 === count( $raw_before ) && 'm1' === ( $raw_before[0]['id'] ?? '' ), 'rows_raw reads nested editor markup' );
$check( 'l2' === ( $raw_before[0]['fields']['lessons'][1]['id'] ?? '' ), 'nested row ids survive the parse' );
$row1_snapshot = wp_json_encode( $raw_before[0] );

$fmt_post = Fields::get( 'rep_modules', (int) $mod_post_id );
$check( 'M1' === ( $fmt_post[0]['title'] ?? null ) && 'L4' === ( $fmt_post[1]['lessons'][1]['heading'] ?? null ), 'Fields::get formats nested post rows (CQ1)' );

// Nested loop traversal with the path stack.
$paths        = array();
$last_heading = null;
$outer_titles = array();
$outer_ids    = array();
while ( tk_have_rows( 'rep_modules', (int) $mod_post_id ) ) {
	tk_the_row();
	$outer_titles[] = tk_get_sub_field( 'title' );
	$outer_ids[]    = tk_get_row_id();
	while ( tk_have_rows( 'lessons', (int) $mod_post_id ) ) {
		tk_the_row();
		$paths[]      = Repeater_Loop::current_path();
		$last_heading = tk_get_sub_field( 'heading' );
	}
}
$check( array( 'M1', 'M2' ) === $outer_titles, 'outer loop visits both rows in order' );
$check( array( 'm1', 'm2' ) === $outer_ids, 'tk_get_row_id returns the persisted row id' );
$check(
	array(
		array( 'rep_modules', 'm1', 'lessons', 'l1' ),
		array( 'rep_modules', 'm1', 'lessons', 'l2' ),
		array( 'rep_modules', 'm2', 'lessons', 'l3' ),
		array( 'rep_modules', 'm2', 'lessons', 'l4' ),
	) === $paths,
	'nested loops resolve through the full path stack'
);
$check( 'L4' === $last_heading, 'sub-field reads the addressed row (row 2 of row 2)' );
$check( false === tk_have_rows( 'lessons', (int) $mod_post_id ), 'nested selector with no active row returns false' );

// CQ3 regression: edit row 2 of the inner repeater of outer row 2;
// row 1 (outer row 1) must be byte-identical afterwards.
$edited = $raw_before;
$edited[1]['fields']['lessons'][1]['fields']['heading'] = 'L4-edited';
wp_update_post(
	array(
		'ID'           => (int) $mod_post_id,
		'post_content' => Repeater::serialize_rows( $modules_field, $edited ),
	)
);
$raw_after = Repeater::rows_raw( $modules_field, 'rep_modules', (int) $mod_post_id );
$check( wp_json_encode( $raw_after[0] ) === $row1_snapshot, 'CQ3: outer row 1 byte-identical after editing row 2/2' );
$check( 'L4-edited' === ( $raw_after[1]['fields']['lessons'][1]['fields']['heading'] ?? null ), 'CQ3: the addressed row 2/2 carries the edit' );

// Revision safety: no stale memoization — a fresh write reads fresh.
wp_update_post(
	array(
		'ID'           => (int) $mod_post_id,
		'post_content' => str_replace( '"value":"M1"', '"value":"M1-rev"', $editor_markup ),
	)
);
$rev_read = Fields::get( 'rep_modules', (int) $mod_post_id );
$check( 'M1-rev' === ( $rev_read[0]['title'] ?? null ), 'post re-parse after wp_update_post (no stale memo)' );

// ------------------------------------------------ context export (CQ2) ---
$context = Context_Export::generate();
$rep_entry = null;
foreach ( (array) ( $context['entities'] ?? array() ) as $entity ) {
	if ( 'Rep Test — Store' === ( $entity['group_title'] ?? '' ) ) {
		$rep_entry = $entity['fields']['rep_store'] ?? null;
	}
}
$check( is_array( $rep_entry ), 'repeater appears in the AI context export' );
$check( 'repeater' === ( $rep_entry['type'] ?? '' ) && 'rep_store' === ( $rep_entry['name'] ?? '' ), 'CQ2: type + name present' );
foreach ( array( 'min', 'max', 'button_label', 'layout', 'sub_fields' ) as $cq2_key ) {
	$check( array_key_exists( $cq2_key, (array) $rep_entry ), 'CQ2: key "' . $cq2_key . '" present' );
}
$check( 2 === ( $rep_entry['min'] ?? null ) && 6 === ( $rep_entry['max'] ?? null ), 'CQ2: min/max are the row limits' );
$check( 'Add Row X' === ( $rep_entry['button_label'] ?? '' ) && 'grid' === ( $rep_entry['layout'] ?? '' ), 'CQ2: button_label + layout' );
$sub_list = $rep_entry['sub_fields'] ?? null;
$check( is_array( $sub_list ) && array_keys( $sub_list ) === range( 0, count( $sub_list ) - 1 ), 'CQ2: sub_fields is a list' );
$sub_names_cq2 = array();
foreach ( (array) $sub_list as $se ) {
	$sub_names_cq2[] = $se['name'] ?? '';
}
$check( array( 'full_name', 'sessions', 'address' ) === $sub_names_cq2, 'CQ2: sub_fields listed by short name' );
$nested_cq2 = null;
foreach ( (array) $sub_list as $se ) {
	if ( 'sessions' === ( $se['name'] ?? '' ) ) {
		$nested_cq2 = $se;
	}
}
$check(
	is_array( $nested_cq2 ) && 'repeater' === ( $nested_cq2['type'] ?? '' ) && 'topic' === ( ( $nested_cq2['sub_fields'][0]['name'] ?? '' ) ),
	'CQ2: nested repeater recurses to the same shape'
);
$check( is_string( $rep_entry['return_format'] ?? null ) && '' !== $rep_entry['return_format'], 'return_format exported' );
$check( is_array( $rep_entry['schema'] ?? null ) && 'array' === ( $rep_entry['schema']['type'] ?? '' ), 'schema exported' );
$check( is_array( $rep_entry['sample_value'] ?? null ), 'sample_value exported' );
$check( false !== strpos( (string) ( $rep_entry['block_binding'] ?? '' ), 'repeater' ), 'block_binding carries the repeater note' );

// ---------------------------------------------------------------- cleanup -
wp_delete_post( (int) $mod_post_id, true );
if ( $term_id > 0 ) {
	wp_delete_term( $term_id, 'category' );
}
foreach ( $group_ids as $gid ) {
	wp_delete_post( (int) $gid, true );
}
$registry->reset_group_cache();

if ( $failures > 0 ) {
	echo "\n{$failures} FAILURE(S)\n";
	exit( 1 );
}

echo "\nALL PASS\n";
exit( 0 );
