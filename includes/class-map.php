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
 * Map field helpers.
 *
 * Owns the two things the map field type needs outside the generic
 * registry pipeline:
 *
 *  1. The geocoder interface. Photon (https://photon.komoot.io) is the v1
 *     geocoder — CORS-friendly, no API key, designed for interactive
 *     search-as-you-type. Nominatim was rejected because its usage policy
 *     (1 req/s, mandatory User-Agent, IP bans) makes it unfit for
 *     production traffic. Geocoding is CLIENT-SIDE only: the admin's
 *     browser calls Photon directly (300ms debounced); the plugin never
 *     proxies or calls the geocoder server-side.
 *
 *  2. The classic-editor input (REDUCED UI in v1): address + lat/lng text
 *     inputs, no Leaflet canvas. The full map UI (Leaflet canvas,
 *     click-to-set, Photon search box) lives in the block editor
 *     (blocks/field-value/edit.js).
 *
 * The swappable geocoder contract is the `tk_fields_geocoder_url` filter:
 * it receives a URL template containing a literal `{query}` placeholder
 * and returns the template to use. Photon is the default template and can
 * be replaced without touching any call site:
 *
 *   add_filter( 'tk_fields_geocoder_url', function ( $template, $query ) {
 *       return 'https://my-geocoder.example/search?q={query}';
 *   }, 10, 2 );
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Map {

	/**
	 * Default geocoder URL template. `{query}` is replaced with the
	 * URL-encoded search string. Swappable via the `tk_fields_geocoder_url`
	 * filter — never hardcoded at call sites.
	 *
	 * @var string
	 */
	public const GEOCODER_URL_TEMPLATE = 'https://photon.komoot.io/api/?q={query}&limit=8';

	/**
	 * Leaflet release used by the block-editor map UI. Pinned so a future
	 * Leaflet release can't silently change the editor experience.
	 *
	 * @var string
	 */
	public const LEAFLET_VERSION = '1.9.4';

	/**
	 * Build the geocoder search URL for one query string.
	 *
	 * Pass the template through `tk_fields_geocoder_url` so the geocoder
	 * is replaceable without touching call sites (trap register #1).
	 *
	 * ADJUDICATION NOTE (Round 3, map decision #2): the build spec asked
	 * for the site domain to be sent as the User-Agent on geocoder
	 * requests. Browsers cannot set `User-Agent` on fetch() — it is a
	 * forbidden header name — so client-side Photon calls go out with the
	 * browser's normal User-Agent and no site identification. Photon
	 * currently requires no key and is CORS-friendly, so this is fine in
	 * v1. If Photon (or a replacement geocoder) ever requires an
	 * identifying User-Agent, the correct fix is a server-side proxy
	 * (v1.1 candidate) that sets the header server-side — never a
	 * client-side workaround.
	 *
	 * @param string $query Raw search text.
	 * @return string Search URL with the query encoded in.
	 */
	public static function geocoder_search_url( string $query ): string {
		/**
		 * Filter the geocoder URL template for map-field address search.
		 *
		 * The template must contain a literal `{query}` placeholder, which
		 * is replaced with the URL-encoded search text. The default is the
		 * Photon API. Return your own template to swap geocoders.
		 *
		 * @param string $template URL template containing `{query}`.
		 * @param string $query    Raw search text.
		 */
		$template = apply_filters( 'tk_fields_geocoder_url', self::GEOCODER_URL_TEMPLATE, $query );
		if ( ! is_string( $template ) || false === strpos( $template, '{query}' ) ) {
			$template = self::GEOCODER_URL_TEMPLATE;
		}

		return str_replace( '{query}', rawurlencode( $query ), $template );
	}

	/**
	 * Classic-editor REDUCED input for a map field (v1).
	 *
	 * Lat/lng/address inputs only — no Leaflet canvas (Round 3 CQ2). The
	 * address is stored denormalized alongside the coordinates and is
	 * never reverse-geocoded: what the editor types here is exactly what
	 * is stored. Render as a normal meta-box field row; the meta-box
	 * framework wires the `$name` map (lat/lng/address) into the save
	 * handler.
	 *
	 * @param array  $field Field definition (registry shape).
	 * @param mixed  $value Current value (sanitized map array, '' or null).
	 * @param string $name  Base input name, e.g. "tk_fields[field_name]".
	 *                      Sub-inputs are `$name[lat]`, `$name[lng]`, `$name[address]`.
	 * @return string HTML for the reduced input row.
	 */
	public static function classic_input_html( array $field, mixed $value, string $name ): string {
		$lat     = '';
		$lng     = '';
		$address = '';
		if ( is_array( $value ) ) {
			$lat     = isset( $value['lat'] ) && is_numeric( $value['lat'] ) ? (string) $value['lat'] : '';
			$lng     = isset( $value['lng'] ) && is_numeric( $value['lng'] ) ? (string) $value['lng'] : '';
			$address = isset( $value['address'] ) ? (string) $value['address'] : '';
		}

		$base     = $name;
		$req      = ! empty( $field['required'] ) ? ' required aria-required="true"' : '';
		$field_id = 'tk-map-' . sanitize_key( $field['name'] ?? 'map' );

		ob_start();
		?>
		<div class="tk-fields-map-classic" id="<?php echo esc_attr( $field_id ); ?>">
			<p class="tk-fields-map-classic__row">
				<label for="<?php echo esc_attr( $field_id ); ?>-address"><?php esc_html_e( 'Address', 'tk-fields' ); ?></label>
				<input type="text"
					id="<?php echo esc_attr( $field_id ); ?>-address"
					name="<?php echo esc_attr( $base ); ?>[address]"
					value="<?php echo esc_attr( $address ); ?>"
					class="regular-text"
					placeholder="<?php esc_attr_e( 'e.g. 10 Downing Street, London', 'tk-fields' ); ?>"<?php echo $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
			</p>
			<p class="tk-fields-map-classic__row">
				<label for="<?php echo esc_attr( $field_id ); ?>-lat"><?php esc_html_e( 'Latitude', 'tk-fields' ); ?></label>
				<input type="number" step="any" min="-90" max="90"
					id="<?php echo esc_attr( $field_id ); ?>-lat"
					name="<?php echo esc_attr( $base ); ?>[lat]"
					value="<?php echo esc_attr( $lat ); ?>"
					placeholder="51.5074" />
				<label for="<?php echo esc_attr( $field_id ); ?>-lng"><?php esc_html_e( 'Longitude', 'tk-fields' ); ?></label>
				<input type="number" step="any" min="-180" max="180"
					id="<?php echo esc_attr( $field_id ); ?>-lng"
					name="<?php echo esc_attr( $base ); ?>[lng]"
					value="<?php echo esc_attr( $lng ); ?>"
					placeholder="-0.1278" />
			</p>
			<p class="description"><?php esc_html_e( 'Coordinates are clamped to valid ranges on save. The full map picker is available in the block editor.', 'tk-fields' ); ?></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
