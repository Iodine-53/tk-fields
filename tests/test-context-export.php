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
 * Context export test — run from the lab root:
 *
 *   ./bin/wp --allow-root --path=www eval-file \
 *     wp-content/plugins/tk-fields/tests/test-context-export.php
 *
 * Exits 0 when every assertion passes, 1 otherwise. Creates its own field
 * groups and values, then deletes them — leaves no trace.
 *
 * The central guarantee under test: Context_Export::generate() emits SCHEMA
 * only. A field NAMED stripe_secret_key (sensitive-looking) may appear; its
 * stored VALUE must never appear in the output.
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

$store = Group_Store::instance();

// ---------------------------------------------------------------- setup ---
$marker = 'sk_live_TESTVALUE_' . substr( md5( (string) time() ), 0, 12 );

$product = $store->create(
	array(
		'title'           => 'AI Export Test — Products',
		'location'        => array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ),
		'location_match'  => 'all',
		'exclude_from_ai' => false,
		'fields'          => array(
			array( 'key' => 'f_tkce_price', 'name' => 'tkce_price', 'label' => 'Price', 'type' => 'number', 'required' => true ),
			array( 'key' => 'f_tkce_stripe', 'name' => 'stripe_secret_key', 'label' => 'Stripe Secret Key', 'type' => 'text' ),
		),
	)
);
$check( ! is_wp_error( $product ), 'product group created' );

$hidden = $store->create(
	array(
		'title'           => 'AI Export Test — Hidden',
		'location'        => array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ),
		'location_match'  => 'all',
		'exclude_from_ai' => true,
		'fields'          => array(
			array( 'key' => 'f_tkce_note', 'name' => 'tkce_internal_note', 'label' => 'Internal Note', 'type' => 'textarea' ),
		),
	)
);
$check( ! is_wp_error( $hidden ), 'excluded group created' );

$post = get_posts( array( 'post_type' => 'post', 'posts_per_page' => 1, 'post_status' => 'any' ) );
$post_id = $post ? $post[0]->ID : 0;
$check( $post_id > 0, 'test post exists' );

tk_update_field( 'stripe_secret_key', $marker, $post_id );
tk_update_field( 'tkce_price', 4242, $post_id );
Fields::flush(); // Commit the batched writes before asserting.
$check( $marker === tk_get_field( 'stripe_secret_key', $post_id, false ), 'marker value actually stored' );

// ------------------------------------------------------------ assertions ---
$context = Context_Export::generate();
$json    = wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

$check( is_array( $context ), 'generate() returns an array' );
$check( is_string( $json ) && null !== json_decode( $json, true ), 'output is valid JSON' );
$check( 'https://json-schema.org/draft/2020-12/schema' === ( $context['$schema'] ?? '' ), '$schema is draft 2020-12' );
$check( 'tk-fields' === ( $context['plugin'] ?? '' ), 'plugin slug present' );
$check( TK_FIELDS_VERSION === ( $context['plugin_version'] ?? '' ), 'plugin version matches' );
$check( false !== strpos( $context['title'] ?? '', 'Custom Fields Schema' ), 'title mentions Custom Fields Schema' );
$check( false !== strtotime( $context['generated_at'] ?? '' ), 'generated_at is a parseable date' );

// Excluded group: nothing about it may leak — not even title or field names.
$check( false === strpos( $json, 'AI Export Test — Hidden' ), 'excluded group title absent' );
$check( false === strpos( $json, 'tkce_internal_note' ), 'excluded group field name absent' );

// No VALUES anywhere in the output.
$check( false === strpos( $json, $marker ), 'stored secret value absent from output' );
$check( false === strpos( $json, '4242' ), 'stored price value absent from output' );

// But the sensitive-looking NAME is fine — it's schema.
$check( false !== strpos( $json, 'stripe_secret_key' ), 'sensitive field NAME present (schema, not value)' );

// Per-field schema shape.
$fields = array();
foreach ( $context['entities'] as $entity ) {
	if ( 'AI Export Test — Products' === ( $entity['group_title'] ?? '' ) ) {
		$fields = $entity['fields'];
	}
}
$check( isset( $fields['tkce_price'], $fields['stripe_secret_key'] ), 'test group fields keyed by name' );
$price = $fields['tkce_price'] ?? array();
$check( 'Price' === ( $price['label'] ?? '' ), 'label exported' );
$check( 'number' === ( $price['type'] ?? '' ), 'type exported' );
$check( true === ( $price['required'] ?? false ), 'required exported' );
$check( 'int|float' === ( $price['return_format'] ?? '' ), 'return_format exported' );
$check( "tk_get_field('tkce_price', \$post_id)" === ( $price['php_access'] ?? '' ), 'php_access snippet exact' );
$check(
	'<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"tk-fields/field","args":{"field":"tkce_price"}}}}} -->' === ( $price['block_binding'] ?? '' ),
	'block_binding markup exact'
);

// Post-type filter: the test group targets post, so it must appear for post
// and vanish for page.
$for_post = Context_Export::generate( 'post' );
$for_page = Context_Export::generate( 'page' );
$in_post  = false;
$in_page  = false;
foreach ( $for_post['entities'] as $e ) {
	if ( 'AI Export Test — Products' === ( $e['group_title'] ?? '' ) ) {
		$in_post = true;
	}
}
foreach ( $for_page['entities'] as $e ) {
	if ( 'AI Export Test — Products' === ( $e['group_title'] ?? '' ) ) {
		$in_page = true;
	}
}
$check( $in_post, 'post-type filter keeps matching group' );
$check( ! $in_page, 'post-type filter drops non-matching group' );

// Markdown format renders a human summary.
$md = Context_Export::to_markdown( $context );
$check( is_string( $md ) && false !== strpos( $md, 'AI Export Test — Products' ), 'markdown includes group title' );
$check( false === strpos( $md, $marker ), 'markdown has no values either' );

// Capability gate.
$check( is_string( Context_Export::capability() ) && '' !== Context_Export::capability(), 'capability() returns a non-empty string' );

// Password fields are UNCONDITIONALLY excluded from AI context — the
// guarantee must hold for nested sub-fields too (repeater, group, and
// flexible_content layouts), not just top-level fields.
$pwgroup = $store->create(
	array(
		'title'           => 'AI Export Test — Passwords',
		'location'        => array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ),
		'location_match'  => 'all',
		'exclude_from_ai' => false,
		'fields'          => array(
			array( 'key' => 'f_pw_top', 'name' => 'pw_top', 'label' => 'Top Password', 'type' => 'password' ),
			array(
				'key'        => 'f_pw_rep',
				'name'       => 'pw_rep',
				'label'      => 'Rep With Password',
				'type'       => 'repeater',
				'sub_fields' => array(
					array( 'key' => 'f_pw_rep_txt', 'name' => 'rep_text', 'label' => 'Rep Text', 'type' => 'text' ),
					array( 'key' => 'f_pw_rep_pw', 'name' => 'rep_secret', 'label' => 'Rep Secret', 'type' => 'password' ),
				),
			),
			array(
				'key'        => 'f_pw_grp',
				'name'       => 'pw_grp',
				'label'      => 'Group With Password',
				'type'       => 'group',
				'sub_fields' => array(
					array( 'key' => 'f_pw_grp_txt', 'name' => 'grp_text', 'label' => 'Grp Text', 'type' => 'text' ),
					array( 'key' => 'f_pw_grp_pw', 'name' => 'grp_secret', 'label' => 'Grp Secret', 'type' => 'password' ),
				),
			),
			array(
				'key'     => 'f_pw_flex',
				'name'    => 'pw_flex',
				'label'   => 'Flex With Password',
				'type'    => 'flexible_content',
				'layouts' => array(
					array(
						'key'    => 'pw_layout',
						'label'  => 'PW Layout',
						'fields' => array(
							array( 'key' => 'f_pw_flex_txt', 'name' => 'flex_text', 'label' => 'Flex Text', 'type' => 'text' ),
							array( 'key' => 'f_pw_flex_pw', 'name' => 'flex_secret', 'label' => 'Flex Secret', 'type' => 'password' ),
						),
					),
				),
			),
		),
	)
);
$check( ! is_wp_error( $pwgroup ), 'password group created' );
$pw_json = wp_json_encode( Context_Export::generate( 'post' ) );
foreach ( array( 'pw_top', 'rep_secret', 'grp_secret', 'flex_secret', 'Rep Secret', 'Grp Secret', 'Flex Secret' ) as $needle ) {
	$check( false === strpos( $pw_json, $needle ), "password token '{$needle}' absent from export" );
}
foreach ( array( 'rep_text', 'grp_text', 'flex_text' ) as $needle ) {
	$check( false !== strpos( $pw_json, $needle ), "non-password sibling '{$needle}' still exported" );
}
if ( ! is_wp_error( $pwgroup ) ) {
	wp_delete_post( $pwgroup['id'], true );
}
Field_Registry::instance()->reset_group_cache();

// ------------------------------------------------------------ cleanup ----
tk_delete_field( 'stripe_secret_key', $post_id );
tk_delete_field( 'tkce_price', $post_id );
Fields::flush();
if ( ! is_wp_error( $product ) ) {
	wp_delete_post( $product['id'], true );
}
if ( ! is_wp_error( $hidden ) ) {
	wp_delete_post( $hidden['id'], true );
}
Field_Registry::instance()->reset_group_cache();

echo $failures > 0 ? "\n{$failures} FAILURE(S)\n" : "\nALL CHECKS PASSED\n";
exit( $failures > 0 ? 1 : 0 );
