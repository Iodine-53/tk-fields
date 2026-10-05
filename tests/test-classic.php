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
 * Classic-editor workstream test — run from the lab root:
 *
 *   ./bin/wp --allow-root --path=www eval-file \
 *     wp-content/plugins/tk-fields/tests/test-classic.php
 *
 * Exits 0 when every assertion passes, 1 otherwise. Creates its own field
 * group, post, terms, user and attachments, then deletes them — leaves no
 * trace.
 *
 * Covers the classic-editor workstream (Round 3 CQ2 v1 scope):
 *  - Every v1 type renders in the meta box (wysiwyg via wp_editor(),
 *    post_object select, page_link discriminated builder, taxonomy
 *    checkbox list, user select/multi-select, gallery wp.media shell,
 *    map reduced inputs).
 *  - Deferred types (relationship, flexible_content, clone) show the
 *    block-editor-only note instead of inputs.
 *  - Saves round-trip through the Fields service: post_object int,
 *    page_link discriminants, taxonomy/user/gallery ID lists, map shape,
 *    wysiwyg STILL wp_kses_post'd through this path (all roles).
 *  - Nonce + capability gates: bad nonce saves nothing; unknown POST keys
 *    are ignored; clearing every taxonomy checkbox clears the value.
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

$registry = Field_Registry::instance();
$renderer = Classic_Renderer::instance();

// ------------------------------------------------------- type coverage ---
$v1_types = array( 'wysiwyg', 'post_object', 'page_link', 'taxonomy', 'user', 'gallery', 'map' );
foreach ( $v1_types as $t ) {
	$check( in_array( $t, Field_Registry::types(), true ), "classic v1 type registered: {$t}" );
	$check( method_exists( $renderer, 'render_field_' . $t ), "classic renderer implements: {$t}" );
}

// ------------------------------------------------------------- fixtures ---
wp_set_current_user( 1 );

$post_id = wp_insert_post(
	array(
		'post_title'  => 'Classic Test Post',
		'post_type'   => 'post',
		'post_status' => 'draft',
	)
);
$check( $post_id > 0, 'test post created' );
$post = get_post( $post_id );

$cat_a = wp_insert_term( 'Classic Cat A', 'category' );
$cat_b = wp_insert_term( 'Classic Cat B', 'category', array( 'parent' => (int) ( $cat_a['term_id'] ?? 0 ) ) );
$check( ! is_wp_error( $cat_a ) && ! is_wp_error( $cat_b ), 'test categories created' );
$cat_a_id = (int) $cat_a['term_id'];

$tag = wp_insert_term( 'classic-tag-x', 'post_tag' );
$check( ! is_wp_error( $tag ), 'test tag created' );
$tag_id = (int) $tag['term_id'];

$test_user = wp_create_user( 'tkclassic_user', 'password123', 'tkclassic@example.com' );
$check( ! is_wp_error( $test_user ), 'test user created' );

$att_ids = array();
foreach ( array( 'classic-a.jpg', 'classic-b.jpg' ) as $file ) {
	$att = wp_insert_post(
		array(
			'post_title'     => $file,
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/jpeg',
		)
	);
	$att_ids[] = $att;
}
$check( 2 === count( array_filter( $att_ids ) ), 'test attachments created' );

$target_post = wp_insert_post(
	array(
		'post_title'  => 'Classic Link Target',
		'post_type'   => 'post',
		'post_status' => 'publish',
	)
);
$check( $target_post > 0, 'post_object target created' );

// Field group with one of each v1 type + one deferred type.
$store = Group_Store::instance();
$group = $store->create(
	array(
		'title'          => 'Classic Test — Group',
		'location'       => array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ),
		'location_match' => 'all',
		'fields'         => array(
			array( 'key' => 'f_tkc_wysiwyg', 'name' => 'tkc_wysiwyg', 'label' => 'Body', 'type' => 'wysiwyg', 'toolbar' => 'basic' ),
			array( 'key' => 'f_tkc_postobj', 'name' => 'tkc_postobj', 'label' => 'Related', 'type' => 'post_object', 'post_types' => array( 'post' ) ),
			array( 'key' => 'f_tkc_pagelink', 'name' => 'tkc_pagelink', 'label' => 'Link', 'type' => 'page_link', 'allow_external' => true ),
			array( 'key' => 'f_tkc_pagelink_int', 'name' => 'tkc_pagelink_int', 'label' => 'Internal link', 'type' => 'page_link' ),
			array( 'key' => 'f_tkc_taxcat', 'name' => 'tkc_taxcat', 'label' => 'Cats', 'type' => 'taxonomy', 'taxonomy' => 'category' ),
			array( 'key' => 'f_tkc_taxflat', 'name' => 'tkc_taxflat', 'label' => 'Tags', 'type' => 'taxonomy', 'taxonomy' => 'post_tag' ),
			array( 'key' => 'f_tkc_user1', 'name' => 'tkc_user1', 'label' => 'Author', 'type' => 'user' ),
			array( 'key' => 'f_tkc_users', 'name' => 'tkc_users', 'label' => 'Reviewers', 'type' => 'user', 'multiple' => true ),
			array( 'key' => 'f_tkc_gallery', 'name' => 'tkc_gallery', 'label' => 'Photos', 'type' => 'gallery', 'min' => 1, 'max' => 5 ),
			array( 'key' => 'f_tkc_map', 'name' => 'tkc_map', 'label' => 'Where', 'type' => 'map' ),
			array( 'key' => 'f_tkc_rel', 'name' => 'tkc_rel', 'label' => 'Deferred', 'type' => 'relationship' ),
		),
	)
);
$check( ! is_wp_error( $group ), 'classic test group created' );

// -------------------------------------------------------------- rendering ---
$box = array( 'args' => array( 'group' => $group ) );
ob_start();
$renderer->render_meta_box( $post, $box );
$html = (string) ob_get_clean();

$check( false !== strpos( $html, 'tk_fields[tkc_wysiwyg]' ), 'wysiwyg renders wp_editor textarea name' );
$check( false !== strpos( $html, 'tkw_tkc_wysiwyg' ), 'wysiwyg editor id is unique per field' );
$check( false !== strpos( $html, 'name="tk_fields[tkc_postobj]"' ), 'post_object renders a select' );
$check( false !== strpos( $html, 'value="' . $target_post . '"' ), 'post_object select lists the target post' );
$check( false !== strpos( $html, 'data-tkf-pagelink-kind' ), 'page_link renders the kind select' );
$check( false !== strpos( $html, 'tk_fields[tkc_pagelink][kind]' ), 'page_link kind input named' );
$check( false !== strpos( $html, 'tk_fields[tkc_taxcat][]' ), 'taxonomy renders checkbox inputs' );
$check( false !== strpos( $html, 'Classic Cat A' ), 'taxonomy checklist shows the test category' );
$check( false !== strpos( $html, 'tk_fields[tkc_taxflat][]' ), 'flat taxonomy renders checkboxes too' );
$check( false !== strpos( $html, 'name="tk_fields[tkc_user1]"' ), 'user single renders a select' );
$check( false !== strpos( $html, 'name="tk_fields[tkc_users][]"' ), 'user multiple renders a multi-select' );
$check( false !== strpos( $html, 'data-tkf-gallery-inputs' ), 'gallery renders the hidden ID inputs' );
$check( false !== strpos( $html, 'data-tkf-gallery-select' ), 'gallery renders the wp.media button' );
$check( false !== strpos( $html, 'tk_fields[tkc_map][lat]' ), 'map reduced renders lat input' );
$check( false !== strpos( $html, 'tk_fields[tkc_map][address]' ), 'map reduced renders address input' );
$check( false !== strpos( $html, 'tk_fields_classic_nonce' ), 'meta box prints the save nonce' );
$check(
	false !== strpos( $html, 'can only be edited in the block editor' ),
	'deferred relationship type shows the block-editor-only note'
);
$check(
	false === strpos( $html, 'name="tk_fields[tkc_rel]"' ),
	'deferred relationship type renders no input'
);
// URL kind hidden when allow_external is off.
$check(
	false === strpos( $html, 'tk_fields[tkc_pagelink_int][value]' ),
	'page_link without allow_external renders no URL input'
);

// ------------------------------------------------------------ save: setup ---
$do_save = function ( array $tk_fields ) use ( $renderer, $post, $post_id ): void {
	$_POST['tk_fields_classic_nonce'] = wp_create_nonce( 'tk_fields_classic_save' );
	$_POST['tk_fields']               = $tk_fields;
	$renderer->save_post( $post_id, $post );
	Fields::flush();
	unset( $_POST['tk_fields_classic_nonce'], $_POST['tk_fields'] );
};

// ------------------------------------------------------------ save: values ---
$do_save(
	array(
		'tkc_wysiwyg'      => '<script>alert(1)</script><p>Hello <b>world</b></p>',
		'tkc_postobj'      => (string) $target_post,
		'tkc_pagelink'     => array( 'kind' => 'post', 'id' => (string) $target_post ),
		'tkc_pagelink_int' => array( 'kind' => 'url', 'value' => 'https://example.com/x' ),
		'tkc_taxcat'       => array( '', (string) $cat_a_id ),
		'tkc_taxflat'      => array( (string) $tag_id ),
		'tkc_user1'        => (string) $test_user,
		'tkc_users'        => array( '', '1', (string) $test_user ),
		'tkc_gallery'      => array_map( 'strval', $att_ids ),
		'tkc_map'          => array( 'lat' => '51.5', 'lng' => '-0.12', 'address' => 'London' ),
	)
);

$w = Fields::get( 'tkc_wysiwyg', $post_id, false );
$check( false === strpos( (string) $w, '<script>' ), 'wysiwyg: script tag stripped through the classic path (kses)' );
$check( false !== strpos( (string) $w, '<p>Hello <b>world</b></p>' ), 'wysiwyg: safe HTML survives the classic path' );

$po = Fields::get( 'tkc_postobj', $post_id, false );
$check( $target_post === $po, 'post_object: ID round-trips as int' );

$pl = Fields::get( 'tkc_pagelink', $post_id, false );
$check(
	is_array( $pl ) && 'post' === ( $pl['kind'] ?? '' ) && $target_post === ( $pl['id'] ?? 0 ),
	'page_link: post discriminant round-trips'
);

$pli = Fields::get( 'tkc_pagelink_int', $post_id, false );
$check(
	Unset_Value::is_unset( $pli ),
	'page_link: url kind rejected when allow_external is off (nothing stored)'
);

$tx = Fields::get( 'tkc_taxcat', $post_id, false );
$check( array( $cat_a_id ) === array_values( (array) $tx ), 'taxonomy: term IDs round-trip (empty marker filtered)' );

$tf = Fields::get( 'tkc_taxflat', $post_id, false );
$check( array( $tag_id ) === array_values( (array) $tf ), 'flat taxonomy: term ID round-trips' );

$u1 = Fields::get( 'tkc_user1', $post_id, false );
$check( (int) $test_user === $u1, 'user single: ID round-trips as int' );

$um = Fields::get( 'tkc_users', $post_id, false );
$check( array( 1, (int) $test_user ) === array_values( (array) $um ), 'user multiple: ID list round-trips' );

$g = Fields::get( 'tkc_gallery', $post_id, false );
$check( array_map( 'intval', $att_ids ) === array_values( array_map( 'intval', (array) $g ) ), 'gallery: attachment IDs round-trip in order' );

$m = Fields::get( 'tkc_map', $post_id, false );
$check(
	is_array( $m ) && 51.5 === ( $m['lat'] ?? null ) && -0.12 === ( $m['lng'] ?? null ) && 'London' === ( $m['address'] ?? null ),
	'map reduced: lat/lng/address round-trip through the classic path'
);

// ------------------------------------------------- save: page_link kinds ---
$do_save( array( 'tkc_pagelink' => array( 'kind' => 'term', 'id' => (string) $cat_a_id, 'taxonomy' => 'category' ) ) );
$pl2 = Fields::get( 'tkc_pagelink', $post_id, false );
$check(
	is_array( $pl2 ) && 'term' === ( $pl2['kind'] ?? '' ) && $cat_a_id === ( $pl2['id'] ?? 0 ) && 'category' === ( $pl2['taxonomy'] ?? '' ),
	'page_link: term discriminant round-trips'
);

$do_save( array( 'tkc_pagelink' => array( 'kind' => 'url', 'value' => 'https://example.com/allowed' ) ) );
$pl3 = Fields::get( 'tkc_pagelink', $post_id, false );
$check(
	is_array( $pl3 ) && 'url' === ( $pl3['kind'] ?? '' ) && 'https://example.com/allowed' === ( $pl3['value'] ?? '' ),
	'page_link: url discriminant round-trips when allow_external is on'
);

// ------------------------------------------------------- save: clearing ---
$do_save( array( 'tkc_taxcat' => array( '' ) ) ); // hidden empty marker only: every box unchecked.
$txc = Fields::get( 'tkc_taxcat', $post_id, false );
$check( array() === array_values( (array) $txc ) || Unset_Value::is_unset( $txc ), 'taxonomy: unchecking every box clears the value' );

$do_save( array( 'tkc_gallery' => '' ) );
$gc = Fields::get( 'tkc_gallery', $post_id, false );
$check( array() === array_values( (array) $gc ) || Unset_Value::is_unset( $gc ), 'gallery: empty hidden input clears the value' );

$do_save( array( 'tkc_postobj' => '0' ) );
$poc = Fields::get( 'tkc_postobj', $post_id, false );
$check( 0 === $poc || Unset_Value::is_unset( $poc ), 'post_object: selecting empty clears the value' );

// -------------------------------------------------------------- security ---
$before = Fields::get( 'tkc_user1', $post_id, false );
$_POST['tk_fields_classic_nonce'] = 'bad-nonce';
$_POST['tk_fields']               = array( 'tkc_user1' => '1' );
$renderer->save_post( $post_id, $post );
Fields::flush();
unset( $_POST['tk_fields_classic_nonce'], $_POST['tk_fields'] );
$after = Fields::get( 'tkc_user1', $post_id, false );
$check( $before === $after, 'bad nonce: save rejected' );

$_POST['tk_fields_classic_nonce'] = wp_create_nonce( 'tk_fields_classic_save' );
$_POST['tk_fields']               = array(
	'not_a_field'      => 'x',
	'../evil'          => 'x',
	'tkc_user1'        => '1',
);
$renderer->save_post( $post_id, $post );
Fields::flush();
unset( $_POST['tk_fields_classic_nonce'], $_POST['tk_fields'] );
$check( 1 === Fields::get( 'tkc_user1', $post_id, false ), 'unknown/malformed POST keys ignored, valid keys still save' );

// Unknown types can't sneak in via forged field names: forged name fails
// the registry lookup and is skipped.
$_POST['tk_fields_classic_nonce'] = wp_create_nonce( 'tk_fields_classic_save' );
$_POST['tk_fields']               = array( 'tkc_rel' => '123' );
$renderer->save_post( $post_id, $post );
Fields::flush();
unset( $_POST['tk_fields_classic_nonce'], $_POST['tk_fields'] );
$check( Unset_Value::is_unset( Fields::get( 'tkc_rel', $post_id, false ) ), 'deferred relationship type not writable from the classic path' );

// ---------------------------------------------------------------- cleanup ---
wp_delete_user( $test_user );
foreach ( $att_ids as $att_id ) {
	wp_delete_post( (int) $att_id, true );
}
wp_delete_term( (int) $cat_b['term_id'], 'category' );
wp_delete_term( $cat_a_id, 'category' );
wp_delete_term( $tag_id, 'post_tag' );
wp_delete_post( $target_post, true );
wp_delete_post( $post_id, true );
wp_delete_post( (int) $group['id'], true ); // group CPT post: removes the test fields.

$check( null === get_post( (int) $group['id'] ), 'test group post deleted' );

echo $failures > 0 ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit( $failures > 0 ? 1 : 0 );
