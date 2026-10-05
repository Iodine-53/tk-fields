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
 * Repeater REST type-map test — run from the lab root:
 *
 *   ./bin/wp --allow-root --path=www eval-file \
 *     wp-content/plugins/tk-fields/tests/test-repeater-rest.php
 *
 * Exits 0 when every assertion passes, 1 otherwise. Creates nothing —
 * read-only probes of the type picker map and the /field-types schema.
 *
 * Covers the repeater build contract items owned by the REST/UI workstream:
 *  - Picker meta: label/icon/category/aliases/block_editor_only, and the
 *    icon is a core Dashicons slug (no bundled font).
 *  - Settings schema: min, max, button_label, layout (list/grid select),
 *    collapsed (select with DYNAMIC options derived by the builder UI —
 *    no static options in the schema), sub_fields (group-pattern
 *    subfields editor); every setting carries a non-empty label + tooltip.
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

// ------------------------------------------------------- picker meta -----
$ref = new \ReflectionMethod( 'TK\\Fields\\Rest', 'type_picker_meta' );
$ref->setAccessible( true );
$meta = $ref->invoke( null );
$entry = $meta['repeater'] ?? null;

$check( is_array( $entry ), 'picker meta has a repeater entry' );
$check( 'layout' === ( $entry['category'] ?? null ), 'repeater picker category is layout' );
$check( 'editor-table' === ( $entry['icon'] ?? null ), 'repeater picker icon is editor-table' );
$check( true === ( $entry['block_editor_only'] ?? null ), 'repeater is block_editor_only' );
$check(
	array( 'repeater', 'repeatable', 'rows' ) === array_values( $entry['aliases'] ?? array() ),
	'repeater aliases are [repeater, repeatable, rows]'
);

// The icon must be a plain Dashicons slug — the UI renders it as
// `dashicons dashicons-<slug>`, and Dashicons ship with core.
$check(
	1 === preg_match( '/^[a-z0-9-]+$/', (string) ( $entry['icon'] ?? '' ) ),
	'repeater picker icon is a valid dashicons slug (core font, nothing bundled)'
);

// -------------------------------------------------- settings schema -----
// The case block fires through field_types() only once the registry lists
// 'repeater' (a separate workstream). Until then this section is skipped.
if ( ! in_array( 'repeater', Field_Registry::types(), true ) ) {
	echo "SKIP: 'repeater' not in Field_Registry::types() yet — settings schema section pending the registry workstream.\n";
} else {
	$resp     = Rest::instance()->field_types();
	$types    = $resp->get_data()['types'] ?? array();
	$repeater = $types['repeater'] ?? null;

	$check( is_array( $repeater ), 'REST /field-types includes repeater' );
	$check( 'Repeater' === ( $repeater['label'] ?? null ), 'repeater label is Repeater' );
	$desc = (string) ( $repeater['description'] ?? '' );
	$check(
		false !== stripos( $desc, 'block editor' ) && false !== stripos( $desc, 'rows' ),
		'repeater description mentions rows and the block editor'
	);

	$settings = array();
	foreach ( $repeater['settings'] ?? array() as $s ) {
		$settings[ $s['key'] ] = $s;
	}
	$check(
		array( 'label', 'name', 'instructions', 'required', 'min', 'max', 'button_label', 'layout', 'collapsed', 'sub_fields' ) === array_keys( $settings ),
		'repeater settings are exactly: label, name, instructions, required, min, max, button_label, layout, collapsed, sub_fields'
	);
	$check( 'number' === ( $settings['min']['control'] ?? null ), 'min is a number control' );
	$check( 'number' === ( $settings['max']['control'] ?? null ), 'max is a number control' );
	$check( 'text' === ( $settings['button_label']['control'] ?? null ), 'button_label is a text control' );
	$check( 'select' === ( $settings['layout']['control'] ?? null ), 'layout is a select control' );
	$layout_opts = $settings['layout']['options'] ?? array();
	$check(
		array( 'list', 'grid' ) === array_keys( $layout_opts ),
		'layout options are list | grid'
	);
	$check( 'select' === ( $settings['collapsed']['control'] ?? null ), 'collapsed is a select control' );
	$check(
		! isset( $settings['collapsed']['options'] ),
		'collapsed carries no static options — the builder derives them live from sub_fields'
	);
	$check( 'subfields' === ( $settings['sub_fields']['control'] ?? null ), 'sub_fields reuses the group subfields control' );

	$all_tooltipped = true;
	foreach ( $settings as $key => $s ) {
		if ( '' === trim( (string) ( $s['label'] ?? '' ) ) || '' === trim( (string) ( $s['tooltip'] ?? '' ) ) ) {
			$all_tooltipped = false;
			break;
		}
	}
	$check( $all_tooltipped, 'every repeater setting has a non-empty label and tooltip' );

	// Picker meta is merged into the REST response (aliases feed the picker
	// search index; block_editor_only gates the badge).
	$check( 'layout' === ( $repeater['category'] ?? null ), 'merged category is layout' );
	$check( 'editor-table' === ( $repeater['icon'] ?? null ), 'merged icon is editor-table' );
	$check( true === ( $repeater['block_editor_only'] ?? null ), 'merged block_editor_only is true' );
	$check(
		in_array( 'repeater', $repeater['aliases'] ?? array(), true ) &&
		in_array( 'repeatable', $repeater['aliases'] ?? array(), true ) &&
		in_array( 'rows', $repeater['aliases'] ?? array(), true ),
		'merged aliases feed the type-picker search index'
	);
}

echo $failures > 0 ? "\n{$failures} FAILURE(S)\n" : "\nALL CHECKS PASSED\n";
exit( $failures > 0 ? 1 : 0 );
