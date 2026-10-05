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
 * Block Bindings source for TK Fields.
 *
 * Registers the `tk-fields/field` bindings source so any core block that
 * supports the Block Bindings API (paragraph, heading, list item, image,
 * button…) can display a field value instead of its authored content:
 *
 *   <!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"tk-fields/field","args":{"field":"hero_price"}}}}} -->
 *   <p>$99</p>
 *   <!-- /wp:paragraph -->
 *
 * Value resolution goes through Fields::get() only — values are NEVER synced
 * into block attributes and get_post_meta() is never called directly.
 *
 * Unset/unknown/non-scalar values resolve to null on both the server and in
 * the editor, so a binding with nothing to show falls back to the block's
 * original content (core skips null source values when rendering, and the
 * editor shows the authored fallback while/after loading).
 *
 * The same file owns the tiny read/write REST surface the editor needs
 * (namespace `tk/v1`):
 *
 *   GET  /tk/v1/values/<post_id>?fields=a,b   -> { "values": { "a": "…"|"null" } }
 *   POST /tk/v1/values/<post_id>               -> { "values": { "a": raw } }
 *                                                 { "updated": { "a": "…"|null }, "failed": { "a": "reason" } }
 *
 * Both routes require `edit_post` on the target post. Writes go through
 * Fields::update() (sanitize + validate per field type) and are committed
 * immediately via Fields::flush().
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Block_Bindings {

	/**
	 * Bindings source name. Shared with assets/bindings/bindings.js.
	 */
	public const SOURCE_NAME = 'tk-fields/field';

	/**
	 * Editor script handle.
	 */
	public const EDITOR_SCRIPT_HANDLE = 'tk-fields-bindings-editor';

	/**
	 * Maximum number of fields per read/write request.
	 */
	private const MAX_FIELDS_PER_REQUEST = 50;

	/**
	 * Field-name allowlist pattern (matches the registry's machine-name rule).
	 */
	private const FIELD_NAME_PATTERN = '/\A[a-zA-Z0-9_]+\z/';

	/**
	 * Register hooks. Called from the main plugin file.
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register_source' ) );
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_editor_assets' ) );
	}

	/**
	 * Register the `tk-fields/field` source with core.
	 */
	public static function register_source(): void {
		register_block_bindings_source(
			self::SOURCE_NAME,
			array(
				'label'              => __( 'Custom Fields', 'tk-fields' ),
				'get_value_callback' => array( self::class, 'get_value' ),
				'uses_context'       => array( 'postId' ),
			)
		);
	}

	/**
	 * Server-side value resolution for a bound attribute.
	 *
	 * Returns the formatted field value cast to string, or null when the field
	 * is unset, unknown, or non-scalar. A null return tells core to leave the
	 * block's original content in place — the binding's fallback.
	 *
	 * @param array     $source_args    Source arguments; expects ['field' => name].
	 * @param \WP_Block $block_instance The block being rendered.
	 * @param string    $attribute_name The bound attribute (e.g. 'content').
	 */
	public static function get_value( array $source_args, \WP_Block $block_instance, string $attribute_name ): ?string {
		$field = $source_args['field'] ?? '';
		if ( ! is_string( $field ) || '' === $field ) {
			return null;
		}

		$post_id = (int) ( $block_instance->context['postId'] ?? get_the_ID() );
		if ( $post_id <= 0 ) {
			return null;
		}

		return self::display_value( $field, $post_id );
	}

	/**
	 * The single display-value resolution shared by the server binding and the
	 * REST read endpoint, so the editor preview matches the frontend render.
	 *
	 * @param string $field   Field name.
	 * @param int    $post_id Post ID.
	 * @return string|null Formatted value as a string, or null when unset,
	 *                     unknown, or not representable as text.
	 */
	public static function display_value( string $field, int $post_id ): ?string {
		$value = Fields::get( $field, $post_id, true );

		// Unset (or unknown field): fall back to the block's original content.
		if ( Unset_Value::is_unset( $value ) ) {
			return null;
		}

		// Only scalars can bind to a text attribute. Arrays/objects (e.g. a
		// formatted image field) resolve to null so the original content is
		// kept rather than replaced with a JSON dump.
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		return (string) $value;
	}

	/**
	 * Enqueue the editor-side source registration. Block editor only — the
	 * frontend never needs this script.
	 */
	public static function enqueue_editor_assets(): void {
		wp_register_script(
			self::EDITOR_SCRIPT_HANDLE,
			TK_FIELDS_URL . 'assets/bindings/bindings.js',
			array( 'wp-blocks', 'wp-data', 'wp-api-fetch', 'wp-i18n', 'wp-block-editor' ),
			TK_FIELDS_VERSION,
			array( 'in_footer' => true )
		);
		wp_set_script_translations( self::EDITOR_SCRIPT_HANDLE, 'tk-fields' );
		wp_enqueue_script( self::EDITOR_SCRIPT_HANDLE );
	}

	// ------------------------------------------------------------------
	// REST surface (editor read/write).
	// ------------------------------------------------------------------

	/**
	 * Register the values read/write routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			'tk/v1',
			'/values/(?P<post_id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'rest_read_values' ),
					'permission_callback' => array( self::class, 'rest_permissions' ),
					'args'                => array(
						'post_id' => array(
							'description' => __( 'Post the fields belong to.', 'tk-fields' ),
							'type'        => 'integer',
							'required'    => true,
							'minimum'     => 1,
						),
						'fields'  => array(
							'description' => __( 'Comma-separated field names to read.', 'tk-fields' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'rest_write_values' ),
					'permission_callback' => array( self::class, 'rest_permissions' ),
					'args'                => array(
						'post_id' => array(
							'description' => __( 'Post the fields belong to.', 'tk-fields' ),
							'type'        => 'integer',
							'required'    => true,
							'minimum'     => 1,
						),
					),
				),
			)
		);
	}

	/**
	 * Both routes require edit access to the target post. Logged-out requests
	 * get 401, logged-in-but-underprivileged requests get 403 — handled by
	 * core from this false.
	 *
	 * @param \WP_REST_Request $request
	 */
	public static function rest_permissions( \WP_REST_Request $request ): bool {
		$post_id = (int) $request['post_id'];
		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * GET /tk/v1/values/<post_id>?fields=a,b
	 *
	 * @param \WP_REST_Request $request
	 */
	public static function rest_read_values( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = (int) $request['post_id'];
		$fields  = self::parse_fields_param( (string) $request->get_param( 'fields' ) );

		if ( array() === $fields ) {
			return new \WP_Error(
				'tk_fields_no_fields',
				__( 'Provide at least one valid field name via the "fields" parameter.', 'tk-fields' ),
				array( 'status' => 400 )
			);
		}

		$values = array();
		foreach ( $fields as $field ) {
			$values[ $field ] = self::display_value( $field, $post_id );
		}

		return rest_ensure_response( array( 'values' => $values ) );
	}

	/**
	 * POST /tk/v1/values/<post_id>  { "values": { "field": raw_value } }
	 *
	 * Writes go through Fields::update() (sanitize + validate per field
	 * type) and are committed immediately. Per-field results are reported so
	 * the editor can keep valid writes and revert rejected ones.
	 *
	 * @param \WP_REST_Request $request
	 */
	public static function rest_write_values( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = (int) $request['post_id'];
		$payload = $request->get_json_params();
		$values  = is_array( $payload ) ? ( $payload['values'] ?? null ) : null;

		if ( ! is_array( $values ) || array() === $values ) {
			return new \WP_Error(
				'tk_fields_no_values',
				__( 'Request body must be a JSON object with a "values" map.', 'tk-fields' ),
				array( 'status' => 400 )
			);
		}

		if ( count( $values ) > self::MAX_FIELDS_PER_REQUEST ) {
			return new \WP_Error(
				'tk_fields_too_many',
				sprintf(
					/* translators: %d: maximum number of fields per request. */
					__( 'Too many fields: at most %d per request.', 'tk-fields' ),
					self::MAX_FIELDS_PER_REQUEST
				),
				array( 'status' => 400 )
			);
		}

		$updated = array();
		$failed  = array();

		foreach ( $values as $field => $value ) {
			if ( ! is_string( $field ) || 1 !== preg_match( self::FIELD_NAME_PATTERN, $field ) ) {
				$failed[ is_string( $field ) ? $field : '(invalid)' ] = __( 'Invalid field name.', 'tk-fields' );
				continue;
			}

			// Bindings write text-ish values; arrays/objects cannot be bound.
			if ( is_array( $value ) || is_object( $value ) ) {
				$failed[ $field ] = __( 'Only scalar values can be written through this endpoint.', 'tk-fields' );
				continue;
			}

			if ( ! Fields::update( $field, $value, $post_id ) ) {
				$failed[ $field ] = __( 'Unknown field or invalid value.', 'tk-fields' );
				continue;
			}

			// Return the canonical display value so the editor shows exactly
			// what the server will render.
			$updated[ $field ] = self::display_value( $field, $post_id );
		}

		// Commit immediately — editor AJAX requests must not rely on the
		// shutdown hook firing after a long-lived admin request.
		Fields::flush();

		return rest_ensure_response(
			array(
				'updated' => $updated,
				'failed'  => $failed,
			)
		);
	}

	/**
	 * Parse the comma-separated `fields` query parameter into a validated,
	 * de-duplicated list of field names.
	 *
	 * @param string $param Raw parameter value.
	 * @return string[]
	 */
	private static function parse_fields_param( string $param ): array {
		$fields = array();

		foreach ( explode( ',', $param ) as $name ) {
			$name = trim( $name );
			if ( '' === $name || 1 !== preg_match( self::FIELD_NAME_PATTERN, $name ) ) {
				continue;
			}
			$fields[ $name ] = true;

			if ( count( $fields ) >= self::MAX_FIELDS_PER_REQUEST ) {
				break;
			}
		}

		return array_keys( $fields );
	}
}
