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
 * Field-group renderer (v0.20.1) — the server half of the repeater UI.
 *
 * Two responsibilities:
 *
 * 1. REST: `GET tk/v1/post-repeaters/<post_id>` returns the repeater-type
 *    fields (full defs) of every field group whose location rules match the
 *    post. Top-level repeater fields only — nested repeaters are created
 *    through the row UI, never the renderer. Schema only, no values.
 *    Capability: `edit_posts`, the same gate as the sibling editor-consumed
 *    route `/field-def/<name>` (the block editor calls this while authors —
 *    not just admins — edit posts).
 * 2. Editor script: enqueues `assets/js/field-group-renderer.js` on
 *    `enqueue_block_editor_assets`, which inserts one `tk/field-repeater`
 *    wrapper per repeater field on editor init.
 *
 * Wiring (final integration step — add to tk-fields.php, after the
 * Block_Bindings require/init):
 *
 *   require_once TK_FIELDS_DIR . 'includes/class-field-group-renderer.php';
 *
 *   Field_Group_Renderer::init();
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Field_Group_Renderer {

	/**
	 * Editor script handle.
	 */
	public const EDITOR_SCRIPT_HANDLE = 'tk-fields-field-group-renderer';

	/**
	 * Definition keys the renderer JS consumes (see
	 * window.tkFieldRepeater.createFromFieldDef in
	 * blocks/field-repeater/edit.js). Anything else is stripped so the
	 * endpoint stays a tight, stable contract.
	 *
	 * @var string[]
	 */
	private const DEF_KEYS = array(
		'name',
		'label',
		'min',
		'max',
		'button_label',
		'layout',
		'collapsed',
		'sub_fields',
	);

	/**
	 * Register hooks. Called from the main plugin file.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_editor_assets' ) );
	}

	/**
	 * The block editor calls this while authors edit posts, so it uses the
	 * lighter `edit_posts` cap — the same gate as the sibling
	 * `/field-def/<name>` route. The response carries field *schemas* only;
	 * no values are ever exposed.
	 */
	public static function permissions_check(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Register the tk/v1/post-repeaters route.
	 */
	public static function register_routes(): void {
		register_rest_route(
			'tk/v1',
			'/post-repeaters/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'post_repeaters' ),
					'permission_callback' => array( self::class, 'permissions_check' ),
					'args'                => array(
						'id' => array(
							'description' => __( 'Post ID.', 'tk-fields' ),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
			)
		);
	}

	/**
	 * GET /post-repeaters/<id> — repeater field defs for the post's groups.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function post_repeaters( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'tk_fields_unknown_post',
				__( 'Unknown post.', 'tk-fields' ),
				array( 'status' => 404 )
			);
		}

		$fields = array();
		foreach ( Field_Registry::instance()->groups_for_object( $post_id ) as $group ) {
			foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
				if ( ! is_array( $field ) || 'repeater' !== ( $field['type'] ?? '' ) ) {
					continue;
				}
				$name = (string) ( $field['name'] ?? '' );
				if ( '' === $name ) {
					continue;
				}
				$fields[] = self::renderer_def( $field );
			}
		}

		return rest_ensure_response( array( 'fields' => $fields ) );
	}

	/**
	 * Trim a stored repeater field definition to the renderer contract.
	 *
	 * @param array $field Stored field definition.
	 * @return array The eight keys createFromFieldDef consumes.
	 */
	private static function renderer_def( array $field ): array {
		$name = (string) ( $field['name'] ?? '' );

		$min = $field['min'] ?? null;
		$max = $field['max'] ?? null;

		$layout = (string) ( $field['layout'] ?? '' );
		if ( 'grid' !== $layout ) {
			$layout = 'list';
		}

		$def = array(
			'name'         => $name,
			'label'        => (string) ( $field['label'] ?? $name ),
			'min'          => ( null === $min || '' === $min ) ? null : (int) $min,
			'max'          => ( null === $max || '' === $max ) ? null : (int) $max,
			'button_label' => (string) ( $field['button_label'] ?? '' ),
			'layout'       => $layout,
			'collapsed'    => (string) ( $field['collapsed'] ?? '' ),
			'sub_fields'   => array_values( (array) ( $field['sub_fields'] ?? array() ) ),
		);

		// Belt and braces: never leak a key the contract doesn't name.
		return array_intersect_key( $def, array_flip( self::DEF_KEYS ) );
	}

	/**
	 * Enqueue the renderer script in the block editor.
	 */
	public static function enqueue_editor_assets(): void {
		wp_register_script(
			self::EDITOR_SCRIPT_HANDLE,
			TK_FIELDS_URL . 'assets/js/field-group-renderer.js',
			array( 'wp-blocks', 'wp-data', 'wp-api-fetch', 'wp-i18n' ),
			TK_FIELDS_VERSION,
			array( 'in_footer' => true )
		);
		wp_set_script_translations( self::EDITOR_SCRIPT_HANDLE, 'tk-fields' );
		wp_enqueue_script( self::EDITOR_SCRIPT_HANDLE );
	}
}
