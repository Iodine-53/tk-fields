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
 * Map field test — run from the lab root:
 *
 *   ./bin/wp --allow-root --path=www eval-file \
 *     wp-content/plugins/tk-fields/tests/test-map.php
 *
 * Exits 0 when every assertion passes, 1 otherwise. Creates its own field
 * group and values, then deletes them — leaves no trace.
 *
 * Covers the Round 3 Batch B map type (LOCKED decisions):
 *  - sanitize(): canonical {lat,lng,zoom,address} shape; lat/lng clamped
 *    to -90..90 / -180..180; address tags stripped; denormalized address
 *    kept as-is (never re-geocoded); empty input collapses to ''.
 *  - validate(): rejects non-numeric lat/lng/zoom.
 *  - format(): returns the stored array untouched (no HTTP at render —
 *    the zero-live-external-dependency hard rule).
 *  - Settings: enable_search defaults OFF (opt-in Photon toggle);
 *    default_lat/default_lng/default_zoom carried on definitions.
 *  - REST field-types descriptor: enable_search toggle with Photon tooltip.
 *  - AI context export: JSON-Schema object {lat,lng,zoom,address} with
 *    settings enumerated.
 *  - Geocoder stays swappable behind the tk_fields_geocoder_url filter.
 *  - Elementor: map renders through the existing text tag (address, with
 *    "lat,lng" fallback).
 *  - Classic: reduced input (lat/lng/address, no Leaflet canvas).
 *  - Storage: one postmeta row, zero pointer duplication.
 *
 * NOTE: no `declare(strict_types=1)` here — wp-cli's eval-file wraps this
 * in eval(), where a declare is not the first statement and fatals. The
 * Photon API is NEVER hit: the geocoder filter is overridden with a fake
 * template for the one URL-building assertion.
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

// ------------------------------------------------------------ registry ---
$check( in_array( 'map', Field_Registry::types(), true ), 'map is a registered field type' );

// ------------------------------------------------------------ settings ---
$registry->register(
	array(
		'name'  => 'tkmap_settings_probe',
		'label' => 'Settings Probe',
		'type'  => 'map',
	)
);
$probe = $registry->get( 'tkmap_settings_probe' );
$check( is_array( $probe ), 'map field registers' );
$check( false === ( $probe['enable_search'] ?? null ), 'enable_search defaults to OFF (opt-in)' );
// NOTE: `??` cannot distinguish a null default from a missing key, so
// assert presence AND null-ness explicitly here.
$check( array_key_exists( 'default_lat', $probe ) && null === $probe['default_lat'], 'default_lat defaults to null' );
$check( array_key_exists( 'default_lng', $probe ) && null === $probe['default_lng'], 'default_lng defaults to null' );
$check( array_key_exists( 'default_zoom', $probe ) && null === $probe['default_zoom'], 'default_zoom defaults to null' );

// ------------------------------------------------------------ sanitize ---
$field = array( 'name' => 'tkmap_x', 'label' => 'X', 'type' => 'map' );

$v = $registry->sanitize( $field, array( 'lat' => '51.5074', 'lng' => '-0.1278', 'zoom' => '13', 'address' => 'London' ) );
$check( is_array( $v ), 'sanitize returns the canonical array' );
$check( 51.5074 === ( $v['lat'] ?? null ), 'lat kept as float' );
$check( -0.1278 === ( $v['lng'] ?? null ), 'lng kept as float' );
$check( 13 === ( $v['zoom'] ?? null ), 'zoom cast to int' );
$check( 'London' === ( $v['address'] ?? null ), 'denormalized address kept verbatim' );
$check( array( 'lat', 'lng', 'zoom', 'address' ) === array_keys( $v ), 'exact key set {lat,lng,zoom,address}' );

$v = $registry->sanitize( $field, array( 'lat' => '100', 'lng' => '-200', 'zoom' => 5, 'address' => 'Nowhere' ) );
$check( 90.0 === $v['lat'], 'lat clamped to 90' );
$check( -180.0 === $v['lng'], 'lng clamped to -180' );

$v = $registry->sanitize( $field, array( 'lat' => '-100', 'lng' => '200', 'zoom' => 5, 'address' => 'X' ) );
$check( -90.0 === $v['lat'], 'lat clamped to -90' );
$check( 180.0 === $v['lng'], 'lng clamped to 180' );

$v = $registry->sanitize( $field, array( 'lat' => '1', 'lng' => '2', 'zoom' => 3, 'address' => '<b>Bold</b><script>alert(1)</script> Town' ) );
$check( 'Bold Town' === $v['address'], 'address tags stripped (sanitize_text_field)' );

$v = $registry->sanitize( $field, array( 'address' => 'Hand-edited address' ) );
$check( null === $v['lat'] && null === $v['lng'], 'missing coords stay null when address present' );
$check( 'Hand-edited address' === $v['address'], 'hand-edited address preserved, never overwritten' );

$check( '' === $registry->sanitize( $field, '' ), 'empty string collapses to empty' );
$check( '' === $registry->sanitize( $field, null ), 'null collapses to empty' );
$check( '' === $registry->sanitize( $field, 'not an array' ), 'non-array collapses to empty' );
$check( '' === $registry->sanitize( $field, array() ), 'empty array collapses to empty' );
$check( '' === $registry->sanitize( $field, array( 'zoom' => 5 ) ), 'zoom-only collapses to empty (no location)' );
$check( '' === $registry->sanitize( $field, array( 'lat' => 'abc', 'lng' => 'def', 'address' => '' ) ), 'non-numeric coords with empty address collapse to empty' );

// ------------------------------------------------------------ validate ---
$check( true === $registry->validate( $field, array( 'lat' => 51.5, 'lng' => -0.1, 'zoom' => 13, 'address' => 'London' ) ), 'validate accepts canonical shape' );
$check( false === $registry->validate( $field, array( 'lat' => 'north', 'lng' => -0.1, 'zoom' => 13, 'address' => 'X' ) ), 'validate rejects non-numeric lat' );
$check( false === $registry->validate( $field, array( 'lat' => 1.0, 'lng' => 'east', 'zoom' => 13, 'address' => 'X' ) ), 'validate rejects non-numeric lng' );
$check( false === $registry->validate( $field, array( 'lat' => 1.0, 'lng' => 2.0, 'zoom' => 'far', 'address' => 'X' ) ), 'validate rejects non-numeric zoom' );
$check( false === $registry->validate( $field, 'garbage' ), 'validate rejects non-array' );
$check( true === $registry->validate( $field, '' ), 'empty validates when not required' );

// ------------------------------------------------- zero HTTP at render ---
$http_calls = 0;
add_filter(
	'pre_http_request',
	function () use ( &$http_calls ) {
		$http_calls++;
		return false; // Count, then let it through (there should be none).
	},
	999
);

$stored = array( 'lat' => 51.5074, 'lng' => -0.1278, 'zoom' => 13, 'address' => 'London, UK' );
$fmt    = $registry->format( $field, $stored );
$check( $stored === $fmt, 'format() returns the stored array untouched' );
$check( 0 === $http_calls, 'format() makes zero HTTP calls' );

// render.php: address output, escaped, no HTTP.
$attributes = array( 'fieldName' => 'tkmap_x', 'fieldType' => 'map', 'value' => $stored );
ob_start();
include TK_FIELDS_DIR . 'blocks/field-value/render.php';
$out = (string) ob_get_clean();
$check( false !== strpos( $out, 'London, UK' ), 'render.php outputs the stored address' );
$check( 0 === $http_calls, 'render.php makes zero HTTP calls' );

// render.php: lat,lng fallback when no address.
$attributes = array( 'fieldName' => 'tkmap_x', 'fieldType' => 'map', 'value' => array( 'lat' => 51.5, 'lng' => -0.1, 'zoom' => 13, 'address' => '' ) );
ob_start();
include TK_FIELDS_DIR . 'blocks/field-value/render.php';
$out = (string) ob_get_clean();
$check( false !== strpos( $out, '51.5,-0.1' ), 'render.php falls back to "lat,lng" when address empty' );

// render.php: stored markup is escaped, not executed.
$attributes = array( 'fieldName' => 'tkmap_x', 'fieldType' => 'map', 'value' => array( 'lat' => 1.0, 'lng' => 2.0, 'zoom' => 1, 'address' => '<img src=x onerror=alert(1)>' ) );
ob_start();
include TK_FIELDS_DIR . 'blocks/field-value/render.php';
$out = (string) ob_get_clean();
$check( false === strpos( $out, '<img src=x' ), 'render.php escapes stored address markup' );
$check( 0 === $http_calls, 'still zero HTTP calls after all renders' );

// --------------------------------------------- storage: one row, no ptrs ---
$posts = get_posts( array( 'post_type' => 'post', 'posts_per_page' => 1, 'post_status' => 'any' ) );
$post_id = $posts ? $posts[0]->ID : 0;
$check( $post_id > 0, 'test post exists' );

// Persisted group carrying map settings (exercises load_group_fields()).
$store = Group_Store::instance();
$group = $store->create(
	array(
		'title'          => 'Map Test — Group',
		'location'       => array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ),
		'location_match' => 'all',
		'fields'         => array(
			array(
				'key'           => 'f_tkmap_location',
				'name'          => 'tkmap_location',
				'label'         => 'Location',
				'type'          => 'map',
				'enable_search' => true,
				'default_lat'   => '48.8566',
				'default_lng'   => '2.3522',
				'default_zoom'  => '12',
			),
		),
	)
);
$check( ! is_wp_error( $group ), 'map group created' );

$persisted = $registry->get( 'tkmap_location' );
$check( true === ( $persisted['enable_search'] ?? null ), 'persisted enable_search=true carried' );
$check( 48.8566 === ( $persisted['default_lat'] ?? null ), 'persisted default_lat carried as float' );
$check( 2.3522 === ( $persisted['default_lng'] ?? null ), 'persisted default_lng carried as float' );
$check( 12 === ( $persisted['default_zoom'] ?? null ), 'persisted default_zoom carried as int' );

$ok = tk_update_field( 'tkmap_location', array( 'lat' => '48.8566', 'lng' => '2.3522', 'zoom' => '12', 'address' => 'Paris, France' ), $post_id );
Fields::flush();
$check( true === $ok, 'tk_update_field accepts the map array' );

$typed = tk_get_field( 'tkmap_location', $post_id, false );
$check( is_array( $typed ) && 'Paris, France' === ( $typed['address'] ?? null ), 'typed read round-trips the denormalized address' );
$check( 48.8566 === ( $typed['lat'] ?? null ), 'typed read round-trips lat' );

$formatted = tk_get_field( 'tkmap_location', $post_id, true );
$check( $typed === $formatted, 'formatted read equals the stored array (no external resolution)' );
$check( 0 === $http_calls, 'end-to-end reads make zero HTTP calls' );

global $wpdb;
$row_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
		$post_id,
		'tkmap_location'
	)
);
$check( 1 === $row_count, 'exactly one postmeta row for the map field' );
$shadow = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
		$post_id,
		'\_tkmap\_location%'
	)
);
$check( 0 === $shadow, 'zero pointer/shadow rows (no ACF-style _key rows)' );

// ------------------------------------------------- REST field-types desc ---
$rest    = Rest::instance();
$resp    = $rest->field_types();
$types   = $resp->get_data()['types'] ?? array();
$check( isset( $types['map'] ), 'REST /field-types includes map' );
$settings = array();
foreach ( $types['map']['settings'] ?? array() as $s ) {
	$settings[ $s['key'] ] = $s;
}
$check( isset( $settings['enable_search'] ) && 'toggle' === ( $settings['enable_search']['control'] ?? '' ), 'enable_search is a toggle descriptor' );
$tooltip = $settings['enable_search']['tooltip'] ?? '';
$check( false !== stripos( $tooltip, 'photon' ) && false !== stripos( $tooltip, 'opt-in' ), 'enable_search tooltip names Photon and the opt-in default' );
$check( isset( $settings['default_zoom'] ) && 'number' === ( $settings['default_zoom']['control'] ?? '' ), 'default_zoom is a number descriptor' );
$check( isset( $settings['default_lat'], $settings['default_lng'] ), 'default_lat/default_lng descriptors present' );

// ------------------------------------------------------- AI export schema ---
$context = Context_Export::generate();
$map_entry = array();
foreach ( $context['entities'] as $entity ) {
	if ( 'Map Test — Group' === ( $entity['group_title'] ?? '' ) ) {
		$map_entry = $entity['fields']['tkmap_location'] ?? array();
	}
}
$check( 'map' === ( $map_entry['type'] ?? '' ), 'AI export includes the map field' );
$schema = $map_entry['schema'] ?? array();
$check( 'object' === ( $schema['type'] ?? '' ), 'AI schema type is object' );
$props = $schema['properties'] ?? array();
$check( isset( $props['lat'], $props['lng'], $props['zoom'], $props['address'] ), 'AI schema has lat/lng/zoom/address properties' );
$check( array( 'number', 'null' ) === ( $props['lat']['type'] ?? null ), 'AI schema lat is number|null' );
$check( -90 === ( $props['lat']['minimum'] ?? null ) && 90 === ( $props['lat']['maximum'] ?? null ), 'AI schema lat bounds -90..90' );
$check( -180 === ( $props['lng']['minimum'] ?? null ) && 180 === ( $props['lng']['maximum'] ?? null ), 'AI schema lng bounds -180..180' );
$check( 'string' === ( $props['address']['type'] ?? null ), 'AI schema address is string' );
$check( true === ( $schema['enable_search'] ?? null ), 'AI schema enumerates enable_search' );
$check( 12 === ( $schema['default_zoom'] ?? null ), 'AI schema enumerates default_zoom' );
$check( 0 === strpos( (string) ( $map_entry['return_format'] ?? '' ), 'array{lat:float|null, lng:float|null, zoom:int|null, address:string}' ), 'AI return_format describes the map shape' );

// ------------------------------------------------------ geocoder swappable ---
$url = Map::geocoder_search_url( 'Berlin Mitte' );
$check( 'https://photon.komoot.io/api/?q=Berlin%20Mitte&limit=8' === $url, 'default Photon URL built with encoded query' );

add_filter(
	'tk_fields_geocoder_url',
	function ( $template, $query ) {
		return 'https://geocoder.example/search?q={query}&query=' . rawurlencode( $query );
	},
	10,
	2
);
$swapped = Map::geocoder_search_url( 'Paris' );
$check( 'https://geocoder.example/search?q=Paris&query=Paris' === $swapped, 'tk_fields_geocoder_url filter swaps the geocoder' );
remove_all_filters( 'tk_fields_geocoder_url' );

// A filter returning a template without {query} falls back to Photon.
add_filter( 'tk_fields_geocoder_url', function () { return 'https://broken.example/'; } );
$check( 'https://photon.komoot.io/api/?q=x&limit=8' === Map::geocoder_search_url( 'x' ), 'template without {query} falls back to Photon default' );
remove_all_filters( 'tk_fields_geocoder_url' );

// --------------------------------------------------------------- Elementor ---
$check( class_exists( 'TK\\Fields\\Integrations\\Elementor\\Tags\\Text_Tag' ), 'Elementor Text_Tag class is loaded' );
$ref    = new \ReflectionClass( 'TK\\Fields\\Integrations\\Elementor\\Tags\\Text_Tag' );
$method = $ref->getMethod( 'supported_types' );
$method->setAccessible( true );
$supported = $method->invoke( null );
$check( in_array( 'map', $supported, true ), 'Text_Tag supports the map type (CQ1: no new tag)' );

// -------------------------------------------------- classic reduced input ---
$check( method_exists( 'TK\\Fields\\Classic_Renderer', 'render_field_map' ), 'Classic_Renderer has render_field_map' );
$classic_html = Map::classic_input_html( $field, $stored, 'tk_fields[tkmap_x]' );
$check( false !== strpos( $classic_html, 'tk_fields[tkmap_x][lat]' ), 'classic input has a lat input' );
$check( false !== strpos( $classic_html, 'tk_fields[tkmap_x][lng]' ), 'classic input has an lng input' );
$check( false !== strpos( $classic_html, 'tk_fields[tkmap_x][address]' ), 'classic input has an address input' );
$check( false === stripos( $classic_html, 'leaflet' ), 'classic input has NO Leaflet canvas (reduced UI)' );
$check( false !== strpos( $classic_html, 'London, UK' ), 'classic input pre-fills the stored address' );

// Classic save path accepts the map array (save_post's scalar gate).
$check( method_exists( 'TK\\Fields\\Classic_Renderer', 'save_post' ), 'Classic_Renderer::save_post exists' );

// --------------------------------------------------------------- cleanup ---
tk_delete_field( 'tkmap_location', $post_id );
Fields::flush();
if ( ! is_wp_error( $group ) && ! empty( $group['id'] ) ) {
	wp_delete_post( (int) $group['id'], true );
}
Field_Registry::instance()->reset_group_cache();

$leftover = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ( 'tkmap_location', 'tkmap_settings_probe' )",
		$post_id
	)
);
$check( 0 === $leftover, 'no test meta rows left behind' );

echo $failures > 0 ? "\n{$failures} FAILURE(S)\n" : "\nALL CHECKS PASSED\n";
exit( $failures > 0 ? 1 : 0 );
