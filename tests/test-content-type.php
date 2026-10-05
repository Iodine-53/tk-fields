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
 * Content type (CPT builder) test — run from the lab root:
 *
 *   ./bin/wp --allow-root --path=www eval-file \
 *     wp-content/plugins/tk-fields/tests/test-content-type.php
 *
 * Exits 0 when every assertion passes, 1 otherwise. Creates and deletes its
 * own definitions and posts — leaves no trace (snapshot + flush flag are
 * rebuilt/cleared as part of cleanup).
 *
 * Locked v0.15.0 contracts under test:
 * - slug validation: format regex, 20-char cap, reserved blocklist,
 *   existing post type collision, duplicate definition
 * - create() registers the type in the current request (post_type_exists)
 * - 28 labels generated from singular/plural
 * - rest_base: rejects core /wp/v2/, rejects duplicates
 * - taxonomy attach wires register_taxonomy_for_object_type
 * - update() locks the slug while posts exist; migrate_slug() moves posts
 * - trash() unregisters; delete() hard-deletes the definition only
 * - export()/import() round-trip; import is skip-on-conflict, never rename
 * - snapshot option reflects definitions
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

$store = Content_Type_Store::instance();

$is_err = static function ( $result ): bool {
	return $result instanceof \WP_Error;
};

// --- slug validation ------------------------------------------------------

$v = $store->validate_slug( 'movie' );
$check( is_array( $v ) && true === $v['valid'], 'slug "movie" is valid on clean lab' );

$v = $store->validate_slug( 'post' );
$check( is_array( $v ) && false === $v['valid'] && count( $v['errors'] ) > 0, 'slug "post" is reserved' );

$v = $store->validate_slug( 'page' );
$check( is_array( $v ) && false === $v['valid'], 'slug "page" is reserved' );

$v = $store->validate_slug( 'type' );
$check( is_array( $v ) && false === $v['valid'], 'slug "type" (Codex query var) is reserved' );

$v = $store->validate_slug( '123abc' );
$check( is_array( $v ) && false === $v['valid'], 'slug must start with a letter' );

$v = $store->validate_slug( 'a_very_long_slug_over_20' );
$check( is_array( $v ) && false === $v['valid'], 'slug longer than 20 chars is invalid' );

$v = $store->validate_slug( 'wp_thing' );
$check( is_array( $v ) && false === $v['valid'], 'slug with wp_ prefix is invalid' );

$v = $store->validate_slug( 'Movie' );
$check( is_array( $v ) && false === $v['valid'], 'uppercase slug fails the regex (REST layer sanitize_key\'s it)' );

// --- create ---------------------------------------------------------------

$created = $store->create(
	array(
		'slug'        => 'movie',
		'singular'    => 'Movie',
		'plural'      => 'Movies',
		'description' => 'Test movies',
		'show_in_rest'=> true,
		'taxonomies'  => array( 'category' ),
	)
);
$check( ! $is_err( $created ), 'create() accepts a valid definition' );
$id = $is_err( $created ) ? 0 : (int) $created['id'];

$check( post_type_exists( 'movie' ), 'created type registers in the current request' );
$snapshot = get_option( 'tk_fields_content_types', array() );
$check( is_array( $snapshot ) && isset( $snapshot['movie'] ), 'snapshot option includes the new type' );

$labels = get_post_type_object( 'movie' )->labels ?? null;
$check( $labels instanceof \stdClass && 'Movies' === $labels->name, 'labels generated: name = Movies' );
$check( $labels instanceof \stdClass && 'Add New Movie' === $labels->add_new_item, 'labels generated: add_new_item = Add New Movie' );

$check( is_object_in_taxonomy( 'movie', 'category' ), 'category attached via taxonomies arg' );

// --- duplicates / collisions -----------------------------------------------

$dup = $store->create( array( 'slug' => 'movie', 'singular' => 'Movie', 'plural' => 'Movies' ) );
$check( $is_err( $dup ), 'duplicate slug rejected' );

$res = $store->create( array( 'slug' => 'post', 'singular' => 'Post', 'plural' => 'Posts' ) );
$check( $is_err( $res ), 'reserved slug rejected at save time' );

// --- rest_base --------------------------------------------------------------

$v2 = $store->create( array( 'slug' => 'book', 'singular' => 'Book', 'plural' => 'Books', 'rest_base' => 'wp/v2' ) );
$check( $is_err( $v2 ), 'rest_base "wp/v2" rejected' );

$book = $store->create( array( 'slug' => 'book', 'singular' => 'Book', 'plural' => 'Books', 'rest_base' => 'movie' ) );
$check( $is_err( $book ), 'rest_base colliding with another type rejected' );

$book = $store->create( array( 'slug' => 'book', 'singular' => 'Book', 'plural' => 'Books', 'rest_base' => 'books' ) );
$check( ! $is_err( $book ), 'rest_base "books" accepted' );
$book_id = $is_err( $book ) ? 0 : (int) $book['id'];

// --- update + slug lock -----------------------------------------------------

if ( $id ) {
	$upd = $store->update( $id, array( 'slug' => 'movie', 'singular' => 'Movie', 'plural' => 'Films', 'description' => 'x' ) );
	$check( ! $is_err( $upd ) && 'Films' === $upd['plural'], 'update() changes labels' );

	// Create one post so the slug locks.
	$post_id = wp_insert_post(
		array(
			'post_type'   => 'movie',
			'post_title'  => 'Test Movie',
			'post_status' => 'publish',
		)
	);
	$check( $post_id > 0, 'test post created in the new type' );

	$locked = $store->update( $id, array( 'slug' => 'film', 'singular' => 'Movie', 'plural' => 'Movies' ) );
	$check( $is_err( $locked ), 'slug change rejected while posts exist' );

	// migrate_slug() moves the posts and re-registers.
	$mig = $store->migrate_slug( $id, 'film' );
	$check( ! $is_err( $mig ) && 'film' === $mig['slug'], 'migrate_slug() renames the definition' );
	$check( 'film' === get_post_type( $post_id ), 'migrate_slug() re-keys the posts' );
	$check( post_type_exists( 'film' ) && ! post_type_exists( 'movie' ), 'old slug unregistered, new slug live' );

	wp_delete_post( $post_id, true );
}

// --- trash / delete ---------------------------------------------------------

if ( $id ) {
	$trashed = $store->trash( $id );
	$check( true === $trashed, 'trash() returns true' );
	$check( ! post_type_exists( 'film' ), 'trashed type unregisters in the current request' );
}

if ( $book_id ) {
	$deleted = $store->delete( $book_id );
	$check( true === $deleted, 'delete() returns true' );
	$check( ! post_type_exists( 'book' ), 'deleted type unregisters in the current request' );
}

// --- export / import round-trip ---------------------------------------------

$created2 = $store->create( array( 'slug' => 'event', 'singular' => 'Event', 'plural' => 'Events' ) );
$event_id = $is_err( $created2 ) ? 0 : (int) $created2['id'];

$doc = $store->export();
$check( is_array( $doc ) && 'tk-content-types' === ( $doc['format'] ?? '' ), 'export() returns a tk-content-types document' );
$slugs = array_column( $doc['content_types'] ?? array(), 'slug' );
$check( in_array( 'event', $slugs, true ), 'export includes the event type' );

if ( $event_id ) {
	$store->delete( $event_id );
	$check( ! post_type_exists( 'event' ), 'event type deleted before re-import' );
}

$report = $store->import( $doc );
$check( ! $is_err( $report ), 'import() accepts our own export' );
$created_slugs = array_column( $report['created'] ?? array(), 'slug' );
$check( in_array( 'event', $created_slugs, true ), 'import re-creates the event type' );
$check( post_type_exists( 'event' ), 'imported type registers in the current request' );

$report2 = $store->import( $doc );
$check( ! $is_err( $report2 ), 'import() is idempotent' );
$check( 0 === count( $report2['created'] ) && count( $report2['skipped'] ) > 0, 'second import skips existing slugs (never renames)' );

$bad = $store->import( array( 'format' => 'nope' ) );
$check( $is_err( $bad ), 'import() rejects a foreign document' );

$bad2 = $store->import( array( 'format' => 'tk-content-types', 'version' => 1, 'content_types' => array( array( 'slug' => 'post', 'singular' => 'P', 'plural' => 'Ps' ) ) ) );
$check( ! $is_err( $bad2 ) && 0 === count( $bad2['created'] ), 'import skips invalid entries and keeps going' );

// --- cleanup -----------------------------------------------------------------

foreach ( $store->all() as $type ) {
	$store->delete( (int) $type['id'] );
}
delete_option( Content_Type_Store::FLUSH_FLAG );

// --- menu icons, with_front, rewrite toggles --------------------------------

$check( 'dashicons-calendar' === $store->resolve_menu_icon( 'tkf-uicon fi-sr-calendar' ), 'resolve_menu_icon translates the legacy editor class pair' );
$check( 'dashicons-calendar' === $store->resolve_menu_icon( 'fi-sr-calendar' ), 'resolve_menu_icon translates the bare legacy glyph class' );
$check( 'dashicons-admin-post' === $store->resolve_menu_icon( 'dashicons dashicons-admin-post' ), 'resolve_menu_icon passes the editor dashicons pair through' );
$check( 'dashicons-admin-post' === $store->resolve_menu_icon( 'dashicons-admin-post' ), 'resolve_menu_icon passes a bare dashicons class through' );
$check( '' === $store->resolve_menu_icon( 'fi-sr-nope' ), 'resolve_menu_icon ignores unknown legacy glyphs' );
$check( '' === $store->resolve_menu_icon( 'not-an-icon' ), 'resolve_menu_icon ignores garbage' );

// Core-default semantics: visibility flags follow `public` when unset.
$core = $store->create( array( 'slug' => 'coredef', 'singular' => 'Coredef', 'plural' => 'Coredefs', 'public' => true ) );
$check( ! $is_err( $core ), 'create() accepts a minimal public definition' );
if ( ! $is_err( $core ) ) {
	$got = $store->get( (int) $core['id'] );
	$check( true === ( $got['publicly_queryable'] ?? null ), 'publicly_queryable defaults to public' );
	$check( true === ( $got['show_ui'] ?? null ), 'show_ui defaults to public' );
	$check( true === ( $got['show_in_menu'] ?? null ), 'show_in_menu defaults to show_ui' );
	$check( true === (bool) ( $got['with_front'] ?? null ), 'with_front defaults to true' );
	$check( true === (bool) ( $got['rewrite'] ?? null ), 'rewrite defaults to true' );
}

$iconed = $store->create(
	array(
		'slug'        => 'venue',
		'singular'    => 'Venue',
		'plural'      => 'Venues',
		'menu_icon'   => 'tkf-uicon fi-sr-map-marker',
		'with_front'  => false,
		'rewrite_slug'=> 'places',
	)
);
$check( ! $is_err( $iconed ), 'create() accepts a legacy uicon menu_icon + with_front=false' );
if ( ! $is_err( $iconed ) ) {
	$pto = get_post_type_object( 'venue' );
	$check( 'dashicons-location-alt' === $pto->menu_icon, 'legacy uicon type registers with the mapped dashicons class' );
	$check( is_array( $pto->rewrite ) && false === $pto->rewrite['with_front'], 'with_front=false honored in rewrite rules' );
	$check( 'places' === $pto->rewrite['slug'], 'custom rewrite slug honored' );
	$got = $store->get( (int) $iconed['id'] );
	$check( 'dashicons dashicons-location-alt' === ( $got['menu_icon'] ?? '' ), 'legacy menu_icon migrated to the dashicons pair on save' );
}

$plain = $store->create(
	array(
		'slug'     => 'memo',
		'singular' => 'Memo',
		'plural'   => 'Memos',
		'public'   => false,
		'rewrite'  => false,
	)
);
$check( ! $is_err( $plain ), 'create() accepts rewrite=false' );
if ( ! $is_err( $plain ) ) {
	$check( false === get_post_type_object( 'memo' )->rewrite, 'rewrite=false honored at registration' );
	$check( 'dashicons-admin-post' === get_post_type_object( 'memo' )->menu_icon, 'empty menu_icon falls back to dashicons-admin-post' );
}

// --- cleanup -----------------------------------------------------------------

foreach ( $store->all() as $type ) {
	$store->delete( (int) $type['id'] );
}
delete_option( Content_Type_Store::FLUSH_FLAG );

$check( array() === $store->all(), 'all test definitions removed' );
$check( array() === get_option( 'tk_fields_content_types', array() ), 'snapshot empty after cleanup' );

echo $failures > 0 ? "\n{$failures} FAILURE(S)\n" : "\nALL CONTENT-TYPE TESTS PASSED\n";
exit( $failures > 0 ? 1 : 0 );
