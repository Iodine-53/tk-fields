<?php
/*
 * This file is part of TK Fields.
 *
 * TK Fields is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License v2 or later.
 * See LICENSE in the plugin root for the full license text.
 */
/**
 * Field-repeater block test — run from the lab root:
 *
 *   ./bin/wp --allow-root --path=www eval-file \
 *     wp-content/plugins/tk-fields/tests/test-field-repeater-block.php
 *
 * Exits 0 when every assertion passes, 1 otherwise. Read-only: parses a
 * fixture markup tree and renders it — creates nothing, leaves no trace.
 *
 * Covers the block workstream (contract 5) of the repeater synthesis:
 * - tk/field-repeater is registered via the auto-discovery registrar
 *   (no class-blocks.php edit needed) with the expected attributes,
 *   inserter disabled, and providesContext keys.
 * - tk/repeater-row accepts tk/field-repeater as a parent (containment).
 * - A nested (depth-2) fixture parses into the unambiguous tree shape the
 *   Fields-service read path resolves: wrapper > row[rowId] > wrapper >
 *   row[rowId], short sub-field names, stable rowIds.
 * - Server render emits the wrapper with layout class + field identity
 *   data attributes and one data-row-id per row.
 *
 * NOTE: no `declare(strict_types=1)` here — wp-cli's eval-file wraps this
 * in eval(), where a declare is not the first statement and fatals.
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

$registry = \WP_Block_Type_Registry::get_instance();

// ------------------------------------------------- registration --------
$bt = $registry->get_registered( 'tk/field-repeater' );
$check( $bt instanceof \WP_Block_Type, 'tk/field-repeater is registered' );

if ( $bt instanceof \WP_Block_Type ) {
	$attrs = $bt->attributes;
	foreach ( array( 'fieldKey', 'fieldName', 'fieldLabel', 'layout', 'buttonLabel', 'min', 'max', 'collapsed', 'subFields', 'scopeStack', 'originPostId' ) as $key ) {
		$check( array_key_exists( $key, $attrs ), "attribute '{$key}' exists" );
	}
	$check( 'list' === ( $attrs['layout']['default'] ?? null ), 'layout defaults to list' );
	$check( false === ( $bt->supports['inserter'] ?? true ), 'inserter is disabled (renderer-only insertion)' );
	$check( false === ( $bt->supports['reusable'] ?? true ), 'reusable is disabled (field-owned identity)' );
	$provides = $bt->provides_context ?? array();
	$check( isset( $provides['tk/field-repeater-field-key'] ), 'provides tk/field-repeater-field-key context' );
	$check( isset( $provides['tk/field-repeater-field-name'] ), 'provides tk/field-repeater-field-name context' );
	$check( isset( $provides['tk/field-repeater-scope'] ), 'provides tk/field-repeater-scope (path stack) context' );
	$check( is_callable( $bt->render_callback ), 'has a render callback (render.php)' );
}

$row_bt = $registry->get_registered( 'tk/repeater-row' );
$check( $row_bt instanceof \WP_Block_Type, 'tk/repeater-row is registered' );
if ( $row_bt instanceof \WP_Block_Type ) {
	$parent = $row_bt->parent ?? array();
	$check( in_array( 'tk/repeater', (array) $parent, true ), 'tk/repeater-row still allows tk/repeater parent' );
	$check( in_array( 'tk/field-repeater', (array) $parent, true ), 'tk/repeater-row allows tk/field-repeater parent (containment)' );
}

// ------------------------------------------------- fixture parse -------
$fixture = '<!-- wp:tk/field-repeater {"fieldKey":"fr-course_modules","fieldName":"course_modules","fieldLabel":"Modules","layout":"list","buttonLabel":"Add Module","min":1,"max":10,"collapsed":"title","scopeStack":["course_modules"],"originPostId":123} -->'
	. '<!-- wp:tk/repeater-row {"rowId":"row-aaa111"} -->'
	. '<!-- wp:tk/field-value {"fieldName":"title","fieldType":"text","value":"Module 1"} /-->'
	. '<!-- wp:tk/field-value {"fieldName":"hours","fieldType":"number","value":6} /-->'
	. '<!-- wp:tk/field-repeater {"fieldKey":"fr-course_modules__lessons","fieldName":"lessons","fieldLabel":"Lessons","layout":"list","subFields":[],"scopeStack":["course_modules","lessons"]} -->'
	. '<!-- wp:tk/repeater-row {"rowId":"row-bbb222"} -->'
	. '<!-- wp:tk/field-value {"fieldName":"title","fieldType":"text","value":"Lesson 1"} /-->'
	. '<!-- /wp:tk/repeater-row -->'
	. '<!-- wp:tk/repeater-row {"rowId":"row-bbb333"} -->'
	. '<!-- wp:tk/field-value {"fieldName":"title","fieldType":"text","value":"Lesson 2"} /-->'
	. '<!-- /wp:tk/repeater-row -->'
	. '<!-- /wp:tk/field-repeater -->'
	. '<!-- /wp:tk/repeater-row -->'
	. '<!-- wp:tk/repeater-row {"rowId":"row-aaa444"} -->'
	. '<!-- wp:tk/field-value {"fieldName":"title","fieldType":"text","value":"Module 2"} /-->'
	. '<!-- /wp:tk/repeater-row -->'
	. '<!-- /wp:tk/field-repeater -->';

$parsed = parse_blocks( $fixture );
$check( 1 === count( $parsed ), 'fixture parses to one top-level block' );

$wrapper = $parsed[0] ?? array();
$check( 'tk/field-repeater' === ( $wrapper['blockName'] ?? '' ), 'top-level block is tk/field-repeater' );
$check( 'course_modules' === ( $wrapper['attrs']['fieldName'] ?? '' ), 'wrapper carries fieldName' );
$check( 'fr-course_modules' === ( $wrapper['attrs']['fieldKey'] ?? '' ), 'wrapper carries fieldKey' );
$check( 1 === ( $wrapper['attrs']['min'] ?? null ), 'min survives parse' );
$check( 10 === ( $wrapper['attrs']['max'] ?? null ), 'max survives parse' );
$check( 'title' === ( $wrapper['attrs']['collapsed'] ?? '' ), 'collapsed survives parse' );

$rows = $wrapper['innerBlocks'] ?? array();
$check( 2 === count( $rows ), 'wrapper has two direct rows (document order = presentation order)' );
$check( 'tk/repeater-row' === ( $rows[0]['blockName'] ?? '' ), 'children are tk/repeater-row blocks' );
$check( 'row-aaa111' === ( $rows[0]['attrs']['rowId'] ?? '' ), 'row 1 keeps its stable rowId (not the index)' );
$check( 'row-aaa444' === ( $rows[1]['attrs']['rowId'] ?? '' ), 'row 2 keeps its stable rowId' );

// Row 1 sub-fields: short names in row scope.
$row1_kids = $rows[0]['innerBlocks'] ?? array();
$field_values = array_values(
	array_filter(
		$row1_kids,
		function ( $b ) {
			return 'tk/field-value' === ( $b['blockName'] ?? '' );
		}
	)
);
$check( 2 === count( $field_values ), 'row 1 holds two scalar sub-field blocks' );
$check( 'title' === ( $field_values[0]['attrs']['fieldName'] ?? '' ), 'sub-field uses short name in row scope' );
$check( 'Module 1' === ( $field_values[0]['attrs']['value'] ?? '' ), 'sub-field value survives parse' );

// Nested repeater: the path stack is the tree, not a flat key.
$nested = null;
foreach ( $row1_kids as $kid ) {
	if ( 'tk/field-repeater' === ( $kid['blockName'] ?? '' ) ) {
		$nested = $kid;
		break;
	}
}
$check( null !== $nested, 'nested tk/field-repeater found inside row 1 (depth 2)' );
if ( null !== $nested ) {
	$check( 'lessons' === ( $nested['attrs']['fieldName'] ?? '' ), 'nested wrapper carries its own fieldName' );
	$check( 'fr-course_modules__lessons' === ( $nested['attrs']['fieldKey'] ?? '' ), 'nested fieldKey extends the parent key (path stack)' );
	$check( array( 'course_modules', 'lessons' ) === ( $nested['attrs']['scopeStack'] ?? null ), 'nested scopeStack pushes its own layer' );
	$nested_rows = $nested['innerBlocks'] ?? array();
	$check( 2 === count( $nested_rows ), 'nested wrapper has two rows' );
	$check( 'row-bbb222' === ( $nested_rows[0]['attrs']['rowId'] ?? '' ), 'nested row 1 rowId is stable' );
	$check( 'row-bbb333' === ( $nested_rows[1]['attrs']['rowId'] ?? '' ), 'nested row 2 rowId is stable' );
	$nested_titles = array();
	foreach ( $nested_rows as $nr ) {
		foreach ( $nr['innerBlocks'] ?? array() as $fv ) {
			if ( 'tk/field-value' === ( $fv['blockName'] ?? '' ) && 'title' === ( $fv['attrs']['fieldName'] ?? '' ) ) {
				$nested_titles[] = $fv['attrs']['value'] ?? '';
			}
		}
	}
	$check( array( 'Lesson 1', 'Lesson 2' ) === $nested_titles, 'nested row values resolve in row scope (no cross-scope bleed)' );
}

// ------------------------------------------------- server render ------
$rendered = do_blocks( $fixture );
$check( false !== strpos( $rendered, 'tk-field-repeater tk-field-repeater--list' ), 'render emits wrapper with layout class' );
$check( false !== strpos( $rendered, 'data-field="course_modules"' ), 'render emits data-field identity' );
$check( false !== strpos( $rendered, 'data-field-key="fr-course_modules"' ), 'render emits data-field-key identity' );
$check( 2 === substr_count( $rendered, 'data-row-id="row-aaa' ), 'render emits both outer row data-row-ids' );
$check( false !== strpos( $rendered, 'data-row-id="row-bbb222"' ), 'render emits nested row data-row-id' );
$check( false !== strpos( $rendered, 'data-field="lessons"' ), 'nested wrapper renders with its own field identity' );
$check( false !== strpos( $rendered, 'Module 1' ), 'sub-field value renders on the front end' );

if ( $failures > 0 ) {
	echo "\n{$failures} assertion(s) failed.\n";
	exit( 1 );
}

echo "\nAll field-repeater block assertions passed.\n";
exit( 0 );
