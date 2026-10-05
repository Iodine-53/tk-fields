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
 * Field-group renderer test — run from the lab root:
 *
 *   ./bin/wp --allow-root --path=www eval-file \
 *     wp-content/plugins/tk-fields/tests/test-field-group-renderer.php
 *
 * Exits 0 when every assertion passes, 1 otherwise.
 *
 * Covers the renderer build contract:
 * - The tk/v1/post-repeaters/<id> route is registered.
 * - Capability gating: edit_posts (the editor-consumed gate, same as the
 *   sibling /field-def/<name> route) — denied without the cap.
 * - Response shape: only TOP-LEVEL repeater fields of the post's assigned
 *   groups, trimmed to the eight renderer-contract keys; nested repeaters
 *   (inside sub_fields) and non-repeater fields are excluded.
 * - 404 for an unknown post; empty fields list when no group matches.
 * - The editor script file exists and declares no build-step imports.
 *
 * NOTE: no `declare(strict_types=1)` here — wp-cli's eval-file wraps this
 * in eval(), where a declare is not the first statement and fatals.
 */

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The loader wiring (tk-fields.php require) is the final integration step;
// until then the test loads the class directly.
require_once dirname( __DIR__ ) . '/includes/class-field-group-renderer.php';

// eval-file runs with no current user; the permission probe needs one.
wp_set_current_user( 1 );

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

// ------------------------------------------------- route registration -----
Field_Group_Renderer::register_routes();
$routes = rest_get_server()->get_routes();
$found  = false;
foreach ( array_keys( $routes ) as $pattern ) {
	if ( false !== strpos( $pattern, 'post-repeaters' ) ) {
		$found = true;
		break;
	}
}
$check( $found, 'tk/v1/post-repeaters route is registered' );

// ------------------------------------------------------ capability gate ---
$check( true === Field_Group_Renderer::permissions_check(), 'permissions_check passes for an admin (has edit_posts)' );

$strip_edit_posts = function ( array $allcaps ): array {
	unset( $allcaps['edit_posts'] );
	$allcaps['edit_posts'] = false;
	return $allcaps;
};
add_filter( 'user_has_cap', $strip_edit_posts, 10, 1 );
$denied = Field_Group_Renderer::permissions_check();
remove_filter( 'user_has_cap', $strip_edit_posts, 10 );
$check( false === $denied, 'permissions_check denies without edit_posts' );

// ------------------------------------------------------- response shape ---
$store    = Group_Store::instance();
$registry = Field_Registry::instance();
$group_ids = array();

$mk_group = function ( string $title, array $fields ) use ( $store, &$group_ids ) {
	$res = $store->create(
		array(
			'title'          => $title,
			'location'       => array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ),
			'location_match' => 'all',
			'fields'         => $fields,
		)
	);
	if ( ! is_wp_error( $res ) ) {
		$group_ids[] = (int) $res['id'];
	}
	return $res;
};

$group_res = $mk_group(
	'Renderer Test — Groups',
	array(
		array(
			'key'          => 'f_renderer_modules',
			'name'         => 'modules',
			'label'        => 'Course Modules',
			'type'         => 'repeater',
			'min'          => 1,
			'max'          => 10,
			'button_label' => 'Add Module',
			'layout'       => 'grid',
			'collapsed'    => 'title',
			'sub_fields'   => array(
				array( 'name' => 'title', 'label' => 'Title', 'type' => 'text' ),
				array(
					'name'       => 'lessons',
					'label'      => 'Lessons',
					'type'       => 'repeater',
					'sub_fields' => array( array( 'name' => 'topic', 'label' => 'Topic', 'type' => 'text' ) ),
				),
			),
		),
		array( 'key' => 'f_renderer_subtitle', 'name' => 'subtitle', 'label' => 'Subtitle', 'type' => 'text' ),
		array(
			'key'          => 'f_renderer_details',
			'name'       => 'details',
			'label'      => 'Details',
			'type'       => 'group',
			'sub_fields' => array(
				array(
					'name'       => 'grouped_rep',
					'label'      => 'Grouped Repeater',
					'type'       => 'repeater',
					'sub_fields' => array( array( 'name' => 'note', 'label' => 'Note', 'type' => 'text' ) ),
				),
			),
		),
	)
);
$check( ! is_wp_error( $group_res ), 'test group created' . ( is_wp_error( $group_res ) ? ': ' . $group_res->get_error_message() : '' ) );
$registry->reset_group_cache();

$post_id = wp_insert_post(
	array(
		'post_title'  => 'Renderer Test Post',
		'post_type'   => 'post',
		'post_status' => 'draft',
	)
);

$req = new \WP_REST_Request( 'GET', '/tk/v1/post-repeaters/' . $post_id );
$req->set_param( 'id', $post_id );
$res = Field_Group_Renderer::post_repeaters( $req );

$check( $res instanceof \WP_REST_Response, 'handler returns a WP_REST_Response' );
$data   = $res->get_data();
$fields = $data['fields'] ?? null;
$check( is_array( $fields ), 'response carries a fields list' );
$check( 1 === count( (array) $fields ), 'exactly one top-level repeater field returned' );

$names = array();
foreach ( (array) $fields as $f ) {
	$names[] = $f['name'] ?? '';
}
$check( array( 'modules' ) === $names, 'the top-level repeater (modules) is returned' );

$mod = $fields[0] ?? array();
$check(
	array( 'name', 'label', 'min', 'max', 'button_label', 'layout', 'collapsed', 'sub_fields' ) === array_keys( $mod ),
	'def trimmed to the eight renderer-contract keys'
);
$check( 'Course Modules' === ( $mod['label'] ?? '' ), 'label carried' );
$check( 1 === ( $mod['min'] ?? null ) && 10 === ( $mod['max'] ?? null ), 'min/max carried as ints' );
$check( 'Add Module' === ( $mod['button_label'] ?? '' ), 'button_label carried' );
$check( 'grid' === ( $mod['layout'] ?? '' ), 'layout carried' );
$check( 'title' === ( $mod['collapsed'] ?? '' ), 'collapsed carried' );
$check( is_array( $mod['sub_fields'] ?? null ) && 2 === count( $mod['sub_fields'] ), 'sub_fields carried with both sub-fields' );

// Nested repeater (lessons) must NOT appear as its own entry…
$check( ! in_array( 'lessons', $names, true ), 'nested repeater (lessons) excluded' );
// …nor the repeater nested inside the group field.
$check( ! in_array( 'grouped_rep', $names, true ), 'group-nested repeater excluded (top-level only)' );
$check( ! in_array( 'subtitle', $names, true ), 'non-repeater field excluded' );

// ------------------------------------------------------------------ 404 ---
$bad_req = new \WP_REST_Request( 'GET', '/tk/v1/post-repeaters/999999999' );
$bad_req->set_param( 'id', 999999999 );
$bad_res = Field_Group_Renderer::post_repeaters( $bad_req );
$check(
	$bad_res instanceof \WP_Error && 404 === (int) ( $bad_res->get_error_data()['status'] ?? 0 ),
	'unknown post id returns a 404 WP_Error'
);

// ------------------------------------------------------------ no groups ----
$page_id = wp_insert_post(
	array(
		'post_title'  => 'Renderer Test Page',
		'post_type'   => 'page',
		'post_status' => 'draft',
	)
);
$page_req = new \WP_REST_Request( 'GET', '/tk/v1/post-repeaters/' . $page_id );
$page_req->set_param( 'id', $page_id );
$page_res = Field_Group_Renderer::post_repeaters( $page_req );
$page_fields = $page_res instanceof \WP_REST_Response ? ( $page_res->get_data()['fields'] ?? null ) : null;
$check( array() === $page_fields, 'post with no matching groups gets an empty fields list' );

// ---------------------------------------------------------- editor script --
$js_path = dirname( __DIR__ ) . '/assets/js/field-group-renderer.js';
$check( file_exists( $js_path ), 'assets/js/field-group-renderer.js exists' );
$js = file_exists( $js_path ) ? (string) file_get_contents( $js_path ) : '';
$check( 0 === preg_match( '/^\s*import\s/m', $js ), 'script has no ES module import statements (no build step)' );
$check( false !== strpos( $js, "window.tkFieldRepeater.createFromFieldDef" ), 'script reuses createFromFieldDef (never reimplements block creation)' );
$check( false !== strpos( $js, "'fr-' + cleanKey(name).slice(0, 48)" ) || false !== strpos( $js, "'fr-' + cleanKey( name ).slice( 0, 48 )" ), 'fieldKey scheme matches createFromFieldDef' );

// ---------------------------------------------------------------- cleanup -
wp_delete_post( $post_id, true );
wp_delete_post( $page_id, true );
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
