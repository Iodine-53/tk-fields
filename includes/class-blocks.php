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

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block registration for TK Fields.
 *
 * Convention (workers: follow this exactly, do not invent your own):
 *
 *   blocks/<slug>/block.json        — metadata. apiVersion 3, name "tk/<slug>".
 *                                     Use "render": "file:./render.php" for
 *                                     server rendering and
 *                                     "viewScriptModule": "file:./view.js"
 *                                     for Interactivity-API view modules.
 *                                     Do NOT put editorScript/viewScript
 *                                     (classic) fields here — the registrar
 *                                     below wires those up.
 *   blocks/<slug>/edit.js           — editor UI. Classic script using wp.*
 *                                     globals only (wp.blocks, wp.element,
 *                                     wp.components, wp.blockEditor,
 *                                     wp.data, wp.i18n). No build step, no
 *                                     bundled React. Every control gets a
 *                                     <Tooltip>.
 *   blocks/<slug>/view.js           — frontend. ES module, may
 *                                     `import { store, getContext } from
 *                                     "@wordpress/interactivity"`. Ship a
 *                                     sibling view.asset.php declaring the
 *                                     dependency:
 *                                     <?php return array( 'dependencies' =>
 *                                       array( '@wordpress/interactivity' ),
 *                                       'version' => TK_FIELDS_VERSION );
 *                                     For vanilla-JS blocks (no interactivity),
 *                                     use classic "viewScript": "file:./view.js"
 *                                     instead and skip the asset file.
 *   blocks/<slug>/render.php        — server render. Receives $attributes,
 *                                     $content, $block. Dynamic blocks only:
 *                                     never sync field values into attributes.
 *   blocks/<slug>/editor.css        — optional editor-only styles.
 *   blocks/<slug>/style.css         — optional frontend styles.
 *
 * The registrar auto-discovers every blocks/<slug>/ dir containing a
 * block.json, registers edit.js with the wp.* dependency set, wires optional
 * stylesheets, and calls register_block_type(). block.json-declared
 * render/viewScriptModule are honoured automatically by core.
 *
 * @package TK\Fields
 */

class Blocks {

	/**
	 * Register every block found under blocks/<slug>/.
	 */
	public static function register(): void {
		$dirs = glob( TK_FIELDS_DIR . 'blocks/*', GLOB_ONLYDIR );
		if ( ! $dirs ) {
			return;
		}

		foreach ( $dirs as $dir ) {
			if ( ! file_exists( $dir . '/block.json' ) ) {
				continue;
			}

			$slug = basename( (string) $dir );
			$args = array();

			// Editor script (classic, wp.* globals — see file docblock).
			if ( file_exists( $dir . '/edit.js' ) ) {
				$handle = 'tkf-' . $slug . '-editor';
				$deps   = array( 'wp-blocks', 'wp-i18n', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-data', 'wp-server-side-render' );
				// Optional static data scripts (e.g. field-value/dashicons.js:
				// build-time generated, never hot-parsed on admin load).
				if ( file_exists( $dir . '/dashicons.js' ) ) {
					$data_handle = 'tkf-' . $slug . '-dashicons';
					wp_register_script(
						$data_handle,
						TK_FIELDS_URL . 'blocks/' . $slug . '/dashicons.js',
						array(),
						TK_FIELDS_VERSION,
						array( 'in_footer' => true )
						);
					$deps[] = $data_handle;
				}
				// Leaflet (map field input) is vendored at assets/leaflet/
				// (BSD-2-Clause): registered as a local script dependency
				// of the editor bundle — never loaded from a CDN.
				if ( 'field-value' === $slug ) {
					wp_register_script(
						'tk-leaflet',
						TK_FIELDS_URL . 'assets/leaflet/leaflet.js',
						array(),
						Map::LEAFLET_VERSION,
						array( 'in_footer' => true )
					);
					wp_register_style(
						'tk-leaflet',
						TK_FIELDS_URL . 'assets/leaflet/leaflet.css',
						array(),
						Map::LEAFLET_VERSION
					);
					$deps[]                          = 'tk-leaflet';
					$args['editor_style_handles'][]  = 'tk-leaflet';
				}
				wp_register_script(
					$handle,
					TK_FIELDS_URL . 'blocks/' . $slug . '/edit.js',
					$deps,
					TK_FIELDS_VERSION,
					array( 'in_footer' => true )
				);
				wp_set_script_translations( $handle, 'tk-fields' );

				// The field-value block's map input calls the geocoder
				// client-side (see Map::geocoder_search_url()): hand the
				// editable `{query}` template to the editor script. Browsers
				// cannot set a User-Agent header on fetch(), so no site
				// identification is sent — see the adjudication note there.
				if ( 'field-value' === $slug ) {
					$geocoder_template = apply_filters( 'tk_fields_geocoder_url', Map::GEOCODER_URL_TEMPLATE, '' );
					if ( ! is_string( $geocoder_template ) || false === strpos( $geocoder_template, '{query}' ) ) {
						$geocoder_template = Map::GEOCODER_URL_TEMPLATE;
					}
					wp_add_inline_script(
						$handle,
						'window.TK_FIELDS_MAP = ' . wp_json_encode(
							array(
								'geocoderTemplate' => $geocoder_template,
							)
						) . ';',
						'before'
					);
				}
				// The post-listing block's inspector needs the public post
				// types (incl. TK Content Types) and the registered TK
				// fields as select options. Localized once per editor load.
				if ( 'post-listing' === $slug ) {
					wp_add_inline_script(
						$handle,
						'window.TK_POST_LISTING_DATA = ' . wp_json_encode( self::post_listing_data() ) . ';',
						'before'
					);
				}
				$args['editor_script_handles'] = array( $handle );
			}

			// Optional stylesheets.
			foreach ( array( 'editor.css' => 'editor_style_handles', 'style.css' => 'style_handles' ) as $file => $arg_key ) {
				if ( ! file_exists( $dir . '/' . $file ) ) {
					continue;
				}
				$handle = 'tkf-' . $slug . '-' . ( 'editor.css' === $file ? 'editor-style' : 'style' );
				// The post-listing block consumes the shared TK Studio
				// tokens — declare them so the custom properties resolve.
				$style_deps = ( 'post-listing' === $slug ) ? array( Widget_Assets::HANDLE ) : array();
				wp_register_style(
					$handle,
					TK_FIELDS_URL . 'blocks/' . $slug . '/' . $file,
					$style_deps,
					TK_FIELDS_VERSION
				);
				$args[ $arg_key ]   = isset( $args[ $arg_key ] ) && is_array( $args[ $arg_key ] )
					? array_merge( $args[ $arg_key ], array( $handle ) )
					: array( $handle );
			}

			register_block_type( $dir, $args );
		}
	}

	/**
	 * Editor data for the tk/post-listing inspector: public post types
	 * (incl. TK Content Types) and registered TK fields (layout-only
	 * types excluded — they store nothing).
	 *
	 * @return array{postTypes: array<int, array{value: string, label: string}>, fields: array<int, array{value: string, label: string}>}
	 */
	private static function post_listing_data(): array {
		$post_types = array();
		$types      = get_post_types( array( 'public' => true ), 'objects' );
		foreach ( $types as $type ) {
			$post_types[] = array(
				'value' => $type->name,
				'label' => $type->labels->singular_name . ' (' . $type->name . ')',
			);
		}

		$fields = array();
		foreach ( Field_Registry::instance()->all() as $name => $field ) {
			$type = (string) ( $field['type'] ?? '' );
			if ( in_array( $type, array( 'message', 'separator', 'tab' ), true ) ) {
				continue;
			}
			$fields[] = array(
				'value' => $name,
				'label' => sprintf(
					'%1$s (%2$s) — %3$s',
					$field['label'] ?? $name,
					$name,
					$type
				),
			);
		}

		return array(
			'postTypes' => $post_types,
			'fields'    => $fields,
		);
	}
}

add_action( 'init', array( Blocks::class, 'register' ) );
