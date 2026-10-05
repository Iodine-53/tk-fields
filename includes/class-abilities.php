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
 * Read-only Abilities API surface (phase 5).
 *
 * Exposes TK Fields through WordPress 6.9+'s Abilities API as two strictly
 * read-only abilities:
 *
 * - `tk-fields/get-field-schema` — field-group schema (names, labels, types,
 *   locations). Schema only, never values.
 * - `tk-fields/get-field-value`  — one field value for one post object.
 *
 * Disabled by default: abilities (and their category) are registered only
 * when the `tk_fields_abilities_enabled` option is truthy or the
 * `tk_fields_abilities_enabled` filter returns true. There are no write
 * abilities — writes are explicitly deferred to v1.1+.
 *
 * Every read resolves through the Fields service (`Fields::get()`) — raw
 * post meta is never touched here. Two permission layers guard every read:
 *
 * 1. Top-level capability: `current_user_can( apply_filters(
 *    'tk_fields_ability_capability', 'manage_options', $group_or_null ) )` —
 *    filterable per field group from day one.
 * 2. Object-level: for `get-field-value`, `current_user_can( 'read_post',
 *    $post_id )`, mirroring core's post-meta binding source. A missing post
 *    maps to do_not_allow, so bogus IDs are denied even when the top-level
 *    check passed.
 *
 * Denials return a 403 `WP_Error` — never the value. Every successful read
 * fires `tk_fields_ability_read`.
 *
 * No polyfill for WP < 6.9: `init()` feature-detects `wp_register_ability`
 * and bails when the Abilities API is absent.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Abilities {

	public const CATEGORY       = 'tk-fields';
	public const ABILITY_SCHEMA = 'tk-fields/get-field-schema';
	public const ABILITY_VALUE  = 'tk-fields/get-field-value';

	/**
	 * Wire up the Abilities API integration. No-op on WP < 6.9 (feature
	 * detection only — deliberately no polyfill).
	 */
	public static function init(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		// Categories must be registered on `wp_abilities_api_categories_init`
		// and abilities on `wp_abilities_api_init`. Registering anywhere else
		// makes core raise _doing_it_wrong and return null.
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	/**
	 * Whether the Abilities API surface is enabled. Disabled by default:
	 * opt in via the `tk_fields_abilities_enabled` option or filter.
	 */
	public static function enabled(): bool {
		$enabled = (bool) get_option( 'tk_fields_abilities_enabled', false );

		/**
		 * Filter whether the TK Fields read-only Abilities API surface is enabled.
		 *
		 * @param bool $enabled Whether the abilities are registered. Default false.
		 */
		return (bool) apply_filters( 'tk_fields_abilities_enabled', $enabled );
	}

	/**
	 * Register the `tk-fields` ability category.
	 */
	public static function register_category(): void {
		if ( ! self::enabled() ) {
			return;
		}

		// wp_register_ability_category() landed in WordPress 6.9; the plugin
		// still supports 6.8, so skip gracefully where it doesn't exist.
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'TK Fields', 'tk-fields' ),
				'description' => __( 'Read-only access to TK Fields field groups and field values.', 'tk-fields' ),
			)
		);
	}

	/**
	 * Register the two read-only abilities.
	 */
	public static function register_abilities(): void {
		if ( ! self::enabled() ) {
			return;
		}

		wp_register_ability(
			self::ABILITY_SCHEMA,
			array(
				'label'               => __( 'Get TK Field Group Schema', 'tk-fields' ),
				'description'         => __( 'Returns the schema (names, labels, types, locations) of TK Fields field groups. Schema only — never returns field values. Groups flagged exclude_from_ai are omitted.', 'tk-fields' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'default'    => array(),
					'properties' => array(
						'group_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'required'    => false,
							'description' => __( 'Return only this field group. Omit to list all groups visible to AI.', 'tk-fields' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'groups' => array(
							'type'        => 'array',
							'description' => __( 'Field groups, schema only.', 'tk-fields' ),
							'items'       => array(
								'type' => 'object',
							),
						),
					),
				),
				'execute_callback'    => array( self::class, 'execute_get_schema' ),
				'permission_callback' => array( self::class, 'check_schema_permission' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);

		wp_register_ability(
			self::ABILITY_VALUE,
			array(
				'label'               => __( 'Get TK Field Value', 'tk-fields' ),
				'description'         => __( 'Reads one TK Fields field value for a post. Returns the value, or an unset marker when nothing is stored.', 'tk-fields' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'field'     => array(
							'type'        => 'string',
							'required'    => true,
							'description' => __( 'Field name (selector).', 'tk-fields' ),
						),
						'object_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'required'    => true,
							'description' => __( 'Post ID to read the field from.', 'tk-fields' ),
						),
						'format'    => array(
							'type'        => 'boolean',
							'required'    => false,
							'default'     => true,
							'description' => __( 'Apply the field type display formatting. Default true.', 'tk-fields' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'value' => array(
							// Mixed type: field values are typed (string, int, bool, array, null).
							// An explicit `type` is required — omitting it makes
							// rest_validate_value_from_schema raise _doing_it_wrong.
							'type'        => array( 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ),
							'description' => __( 'The field value (typed and, by default, formatted). Absent when the field is unset.', 'tk-fields' ),
						),
						'unset' => array(
							'type'        => 'boolean',
							'description' => __( 'True when the field has no stored value.', 'tk-fields' ),
						),
					),
				),
				'execute_callback'    => array( self::class, 'execute_get_value' ),
				'permission_callback' => array( self::class, 'check_value_permission' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Top-level gate: filterable capability check.
	 *
	 * @param array|null $group Field group definition, or null when the check is not group-scoped.
	 */
	private static function top_level_allowed( ?array $group ): bool {
		/**
		 * Filter the capability required to use the TK Fields abilities.
		 *
		 * @param string     $capability Capability slug. Default 'manage_options'.
		 * @param array|null $group      The persisted field group the field belongs to, or null.
		 */
		$capability = apply_filters( 'tk_fields_ability_capability', 'manage_options', $group );

		return current_user_can( (string) $capability );
	}

	/**
	 * Find the persisted field group that defines a field, if any. Fields
	 * from the seed registry belong to no group (null).
	 */
	private static function field_group( string $field ): ?array {
		foreach ( Field_Registry::instance()->all_groups() as $group ) {
			foreach ( $group['fields'] ?? array() as $def ) {
				if ( ( $def['name'] ?? '' ) === $field ) {
					return $group;
				}
			}
		}

		return null;
	}

	/**
	 * Standard 403 denial. Never carries the value.
	 */
	private static function forbidden( string $ability_name ): \WP_Error {
		return new \WP_Error(
			'tk_fields_ability_forbidden',
			sprintf(
				/* translators: %s: ability name. */
				__( 'You do not have permission to use the "%s" ability.', 'tk-fields' ),
				$ability_name
			),
			array( 'status' => 403 )
		);
	}

	// -- tk-fields/get-field-schema ------------------------------------------

	/**
	 * Permission callback: top-level capability only (schema carries no values).
	 */
	public static function check_schema_permission( $input ): bool {
		return self::top_level_allowed( null );
	}

	/**
	 * Execute callback: return field-group schema, skipping exclude_from_ai groups.
	 *
	 * @param mixed $input
	 * @return array|\WP_Error
	 */
	public static function execute_get_schema( $input ): array|\WP_Error {
		// Defense in depth: permission_callback can be overridden by the
		// wp_ability_permission_result filter, so re-check here and deny with
		// a 403 rather than ever returning data.
		if ( ! self::check_schema_permission( $input ) ) {
			return self::forbidden( self::ABILITY_SCHEMA );
		}

		$input    = is_array( $input ) ? $input : array();
		$group_id = absint( $input['group_id'] ?? 0 );
		$registry = Field_Registry::instance();

		if ( $group_id > 0 ) {
			$group = $registry->get_group( $group_id );
			if ( null === $group || ! empty( $group['exclude_from_ai'] ) ) {
				return new \WP_Error(
					'tk_fields_group_not_found',
					__( 'Field group not found.', 'tk-fields' ),
					array( 'status' => 404 )
				);
			}
			$groups = array( self::schema_group( $group ) );
		} else {
			$groups = array();
			foreach ( $registry->all_groups() as $group ) {
				if ( ! empty( $group['exclude_from_ai'] ) ) {
					continue;
				}
				$groups[] = self::schema_group( $group );
			}
		}

		/**
		 * Fires after a successful TK Fields ability read.
		 *
		 * @param string $ability_name Ability name.
		 * @param array  $input        Normalized input.
		 * @param int    $user_id      Current user ID.
		 */
		do_action( 'tk_fields_ability_read', self::ABILITY_SCHEMA, $input, get_current_user_id() );

		return array( 'groups' => $groups );
	}

	/**
	 * One group as schema-only data: names, labels, types, locations.
	 * Never values.
	 */
	private static function schema_group( array $group ): array {
		$fields = array();
		foreach ( $group['fields'] ?? array() as $def ) {
			$field = array(
				'name'     => (string) ( $def['name'] ?? '' ),
				'label'    => (string) ( $def['label'] ?? '' ),
				'type'     => (string) ( $def['type'] ?? 'text' ),
				'required' => ! empty( $def['required'] ),
			);
			if ( array_key_exists( 'default', $def ) ) {
				$field['default'] = $def['default'];
			}
			if ( ! empty( $def['choices'] ) && is_array( $def['choices'] ) ) {
				$field['choices'] = $def['choices'];
			}
			$fields[] = $field;
		}

		return array(
			'id'             => (int) ( $group['id'] ?? 0 ),
			'title'          => (string) ( $group['title'] ?? '' ),
			'location'       => $group['location'] ?? array(),
			'location_match' => $group['location_match'] ?? 'all',
			'fields'         => $fields,
		);
	}

	// -- tk-fields/get-field-value -------------------------------------------

	/**
	 * Full access check for get-field-value: top-level capability AND the
	 * object-level `read_post` check. Returns a 403 WP_Error on denial —
	 * never the value.
	 */
	private static function check_value_access( array $input ): ?\WP_Error {
		$field     = (string) ( $input['field'] ?? '' );
		$object_id = absint( $input['object_id'] ?? 0 );

		if ( ! self::top_level_allowed( self::field_group( $field ) ) ) {
			return self::forbidden( self::ABILITY_VALUE );
		}

		// Object-level: mirrors core's post-meta binding source. A missing
		// post maps to do_not_allow, so bogus IDs are denied here too.
		if ( ! current_user_can( 'read_post', $object_id ) ) {
			return self::forbidden( self::ABILITY_VALUE );
		}

		return null;
	}

	/**
	 * Permission callback. Returns a plain bool: returning a WP_Error here
	 * would make WP_Ability::execute() log a _doing_it_wrong notice.
	 */
	public static function check_value_permission( $input ): bool {
		$input = is_array( $input ) ? $input : array();

		return null === self::check_value_access( $input );
	}

	/**
	 * Execute callback: resolve the value through the Fields service.
	 *
	 * @param mixed $input
	 * @return array|\WP_Error `{ value }` or `{ unset: true }`.
	 */
	public static function execute_get_value( $input ): array|\WP_Error {
		$input = is_array( $input ) ? $input : array();

		// Defense in depth: the permission_callback result can be overridden
		// by the wp_ability_permission_result filter, so re-check here and
		// deny with a 403 rather than ever returning the value.
		$denied = self::check_value_access( $input );
		if ( null !== $denied ) {
			return $denied;
		}

		$field     = (string) ( $input['field'] ?? '' );
		$object_id = absint( $input['object_id'] ?? 0 );
		$format    = array_key_exists( 'format', $input ) ? (bool) $input['format'] : true;

		$value = Fields::get( $field, $object_id, $format );

		/** This action is documented in execute_get_schema(). */
		do_action( 'tk_fields_ability_read', self::ABILITY_VALUE, $input, get_current_user_id() );

		if ( Unset_Value::is_unset( $value ) ) {
			return array( 'unset' => true );
		}

		return array( 'value' => $value );
	}
}
