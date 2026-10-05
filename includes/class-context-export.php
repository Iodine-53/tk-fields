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
 * AI context export — schema-only snapshot of the field configuration.
 *
 * `Context_Export::generate()` serializes every field group (except those
 * flagged `exclude_from_ai`) into a JSON-schema-style document an LLM can
 * consume to learn the site's custom fields: names, labels, types, where
 * they apply, and how to read them (PHP API + Block Bindings markup).
 *
 * SCHEMA ONLY — this class NEVER reads field values. It consults
 * Field_Registry / Group_Store (definitions) and get_bloginfo() only. No
 * call to Fields::get(), tk_get_field(), get_post_meta() for field data, or
 * any storage adapter exists in this file, by design: the export must be
 * safe to hand to a third-party AI without leaking secrets, PII, or content.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Context_Export {

	/**
	 * Filter for the capability required to export the AI context.
	 */
	public const CAPABILITY_FILTER = 'tk_fields_context_export_capability';

	/**
	 * Default capability required to export the AI context.
	 */
	public const DEFAULT_CAPABILITY = 'edit_theme_options';

	/**
	 * The capability required to export the AI context (filterable).
	 */
	public static function capability(): string {
		/**
		 * Filter the capability required to export the AI context schema.
		 *
		 * @param string $capability Capability slug. Default 'edit_theme_options'.
		 */
		$cap = apply_filters( self::CAPABILITY_FILTER, self::DEFAULT_CAPABILITY );

		return is_string( $cap ) && '' !== $cap ? $cap : self::DEFAULT_CAPABILITY;
	}

	/**
	 * Whether the current user may export the AI context.
	 */
	public static function user_can_export(): bool {
		return current_user_can( self::capability() );
	}

	/**
	 * Generate the AI context document.
	 *
	 * SCHEMA ONLY: built exclusively from group/field DEFINITIONS (titles,
	 * labels, names, types, location rules, choices, instructions). Field
	 * VALUES are never read and cannot appear in the output.
	 *
	 * @param string|null $post_type Optional: only include groups that apply
	 *                              to this post type (token-window guard for
	 *                              large sites). Groups that do not restrict
	 *                              post_type at all are included — they may
	 *                              apply; groups explicitly targeting another
	 *                              post type are excluded.
	 * @return array The context document.
	 */
	public static function generate( ?string $post_type = null ): array {
		$entities = array();

		foreach ( Field_Registry::instance()->all_groups() as $group ) {
			// Honor the AI exclusion flag: excluded groups are skipped
			// entirely — not even their titles or field names leak out.
			if ( ! empty( $group['exclude_from_ai'] ) ) {
				continue;
			}

			if ( null !== $post_type && ! self::group_applies_to_post_type( $group, $post_type ) ) {
				continue;
			}

			$fields = array();
			$layout_omitted = array();
			foreach ( $group['fields'] ?? array() as $field ) {
				$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';

				// Clone fields are never exported as {"type":"clone"}: the
				// parent stores nothing, so the resolved children are
				// expanded inline as individual fields under their
				// namespaced keys (first definition wins on collision).
				if ( 'clone' === $type ) {
					foreach ( Field_Registry::instance()->resolve_clone_children( $field ) as $child ) {
						$child_name = (string) ( $child['name'] ?? '' );
						if ( '' === $child_name || isset( $fields[ $child_name ] ) ) {
							continue;
						}
						$child_entry = self::field_entry( $child, $layout_omitted );
						if ( null !== $child_entry ) {
							$fields[ $child_name ] = $child_entry;
						}
					}
					continue;
				}

				$entry = self::field_entry( $field, $layout_omitted );
				if ( null === $entry ) {
					continue;
				}

				$fields[ (string) $field['name'] ] = $entry;
			}

			$entity = array(
				'group_title'    => isset( $group['title'] ) ? (string) $group['title'] : '',
				'locations'      => self::normalize_locations( $group ),
				'location_match' => $group['location_match'] ?? 'all',
				'fields'         => $fields,
			);

			// Note layout-only fields that were omitted (they render UI but
			// store no value, so there is nothing for an AI to read).
			if ( array() !== $layout_omitted ) {
				$entity['layout_fields_omitted'] = array_values( $layout_omitted );
			}

			$entities[] = $entity;
		}

		// v0.15.0: content-type definitions (schema only — never values).
		// Declared from day one per the standing rule: every feature ships
		// its AI-export schema with its first release.
		$content_types = array();
		if ( class_exists( 'TK\Fields\Content_Type_Store' ) ) {
			foreach ( Content_Type_Store::instance()->all() as $type ) {
				$content_types[] = array(
					'slug'               => $type['slug'],
					'singular'           => $type['singular'],
					'plural'             => $type['plural'],
					'description'        => $type['description'] ?? '',
					'public'             => ! empty( $type['public'] ),
					'publicly_queryable' => ! empty( $type['publicly_queryable'] ),
					'show_in_rest'       => ! empty( $type['show_in_rest'] ),
					'rest_base'          => $type['rest_base'] ?? $type['slug'],
					'has_archive'        => ! empty( $type['has_archive'] ),
					'rewrite_slug'       => $type['rewrite_slug'] ?? $type['slug'],
					'hierarchical'       => ! empty( $type['hierarchical'] ),
					'supports'           => $type['supports'] ?? array(),
					'taxonomies'         => $type['taxonomies'] ?? array(),
					'capability_type'    => $type['capability_type'] ?? 'post',
					'registered'         => post_type_exists( $type['slug'] ),
				);
			}
		}

		return array(
			'$schema'        => 'https://json-schema.org/draft/2020-12/schema',
			'title'          => get_bloginfo( 'name' ) . ' Custom Fields Schema',
			'generated_at'   => gmdate( 'c' ),
			'plugin'         => 'tk-fields',
			'plugin_version' => defined( 'TK_FIELDS_VERSION' ) ? TK_FIELDS_VERSION : 'unknown',
			'entities'       => $entities,
			'content_types'  => $content_types,
		);
	}

	/**
	 * Build a group field's export entry.
	 *
	 * Groups are value-bearing containers (the parent stores nothing, the
	 * children carry the values), so they are exported as objects keyed by
	 * short sub-field name, with per-sub-field inline schemas mirroring the
	 * flexible_content sub-field loop. Sub-field choice vocabularies and
	 * instructions are enumerated for schema completeness.
	 *
	 * @param array  $field Field definition.
	 * @param string $name  Field name.
	 */
	private static function group_entry( array $field, string $name ): array {
		$children = Field_Registry::instance()->resolve_group_children( $field );

		$sub_fields = array();
		foreach ( $children as $short => $child ) {
			if ( ! is_array( $child ) || '' === (string) $short ) {
				continue;
			}
			// Password sub-fields are unconditionally excluded from AI
			// context — the same posture as top-level password fields in
			// field_entry(). Schema-only export must not disclose them.
			if ( 'password' === (string) ( $child['type'] ?? '' ) ) {
				continue;
			}
			$sub_type = (string) ( $child['type'] ?? 'text' );
			$sub_entry = array(
				'label'         => isset( $child['label'] ) && is_string( $child['label'] ) && '' !== $child['label'] ? (string) $child['label'] : (string) $short,
				'type'          => $sub_type,
				'required'      => ! empty( $child['required'] ),
				'return_format' => self::describe_return_format( $sub_type, $child ),
				'schema'        => self::field_schema( $sub_type, $child ),
				'sample_value'  => self::sample_value( $sub_type, $child ),
				'storage_key'   => (string) ( $child['name'] ?? '' ),
			);
			if ( in_array( $sub_entry['type'], array( 'select', 'radio', 'button_group' ), true ) && ! empty( $child['choices'] ) && is_array( $child['choices'] ) ) {
				$sub_entry['choices'] = $child['choices'];
			}
			if ( ! empty( $child['instructions'] ) && is_string( $child['instructions'] ) ) {
				$sub_entry['instructions'] = $child['instructions'];
			}
			$sub_fields[ (string) $short ] = $sub_entry;
		}

		$entry = array(
			'label'         => isset( $field['label'] ) ? (string) $field['label'] : $name,
			'type'          => 'group',
			'required'      => false, // Group-level required is meaningless; always false.
			'return_format' => self::describe_return_format( 'group', $field ),
			'schema'        => self::field_schema( 'group', $field ),
			'sample_value'  => self::sample_value( 'group', $field ),
			'sub_fields'    => $sub_fields,
			'php_access'    => self::php_access_snippet( $name ),
			'block_binding' => self::block_binding_markup( $name, 'group' ),
		);

		if ( ! empty( $field['instructions'] ) && is_string( $field['instructions'] ) ) {
			$entry['instructions'] = $field['instructions'];
		}

		return $entry;
	}

	/**
	 * Build a repeater field's export entry (CQ2 shape).
	 *
	 * {type, name, min, max, button_label, layout, sub_fields} where
	 * sub_fields is a LIST of recursive entries (nested repeaters export
	 * the same shape; group subs export their leaves as a list). min/max
	 * are the ROW count limits (0 = unlimited, mirroring flexible).
	 * Schema knowledge only — no value access.
	 *
	 * @param array  $field Repeater field definition.
	 * @param string $name  Field name.
	 */
	private static function repeater_entry( array $field, string $name ): array {
		$children = Field_Registry::instance()->resolve_repeater_children( $field );

		$sub_fields = array();
		foreach ( $children as $short => $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			$sub_entry = self::repeater_sub_entry( $child, (string) $short );
			if ( null !== $sub_entry ) {
				$sub_fields[] = $sub_entry;
			}
		}

		$entry = array(
			'label'         => isset( $field['label'] ) ? (string) $field['label'] : $name,
			'type'          => 'repeater',
			'name'          => $name,
			'required'      => ! empty( $field['required'] ),
			'min'           => max( 0, (int) ( $field['min'] ?? 0 ) ),
			'max'           => max( 0, (int) ( $field['max'] ?? 0 ) ),
			'button_label'  => (string) ( $field['button_label'] ?? '' ),
			'layout'        => in_array( $field['layout'] ?? '', array( 'list', 'grid' ), true ) ? $field['layout'] : 'list',
			'return_format' => self::describe_return_format( 'repeater', $field ),
			'schema'        => self::field_schema( 'repeater', $field ),
			'sample_value'  => self::sample_value( 'repeater', $field ),
			'sub_fields'    => $sub_fields,
			'php_access'    => self::php_access_snippet( $name ),
			'block_binding' => self::block_binding_markup( $name, 'repeater' ),
		);

		if ( ! empty( $field['instructions'] ) && is_string( $field['instructions'] ) ) {
			$entry['instructions'] = $field['instructions'];
		}

		return $entry;
	}

	/**
	 * Build one repeater sub-field's export entry (recursive).
	 *
	 * Nested repeaters recurse through repeater_entry() (same CQ2 shape);
	 * group subs export their leaves as a sub_fields list; scalar subs
	 * export the standard inline entry. Returns null for malformed subs.
	 *
	 * @param array  $child Sub-field definition.
	 * @param string $short Short sub-field name.
	 */
	private static function repeater_sub_entry( array $child, string $short ): ?array {
		if ( '' === $short ) {
			return null;
		}
		$sub_type = (string) ( $child['type'] ?? 'text' );

		// Password sub-fields are unconditionally excluded from AI
		// context — the same posture as top-level password fields in
		// field_entry(). Returning null drops the sub-field from the
		// parent's list (callers already skip null entries).
		if ( 'password' === $sub_type ) {
			return null;
		}

		if ( 'repeater' === $sub_type ) {
			return self::repeater_entry( $child, $short );
		}

		$sub_entry = array(
			'label'         => isset( $child['label'] ) && is_string( $child['label'] ) && '' !== $child['label'] ? (string) $child['label'] : $short,
			'type'          => $sub_type,
			'name'          => $short,
			'required'      => ! empty( $child['required'] ),
			'return_format' => self::describe_return_format( $sub_type, $child ),
			'schema'        => self::field_schema( $sub_type, $child ),
			'sample_value'  => self::sample_value( $sub_type, $child ),
		);

		if ( 'group' === $sub_type ) {
			$leaves = array();
			foreach ( (array) ( $child['sub_fields'] ?? array() ) as $leaf ) {
				if ( ! is_array( $leaf ) ) {
					continue;
				}
				$leaf_entry = self::repeater_sub_entry( $leaf, (string) ( $leaf['name'] ?? '' ) );
				if ( null !== $leaf_entry ) {
					$leaves[] = $leaf_entry;
				}
			}
			$sub_entry['sub_fields'] = $leaves;
		}

		if ( in_array( $sub_type, array( 'select', 'radio', 'button_group' ), true ) && ! empty( $child['choices'] ) && is_array( $child['choices'] ) ) {
			$sub_entry['choices'] = $child['choices'];
		}
		if ( ! empty( $child['instructions'] ) && is_string( $child['instructions'] ) ) {
			$sub_entry['instructions'] = $child['instructions'];
		}

		return $sub_entry;
	}

	/**
	 * Build one field's export entry, or null when the field must be omitted.
	 *
	 * Password fields are UNCONDITIONALLY excluded from AI context: not
	 * even the field name leaks. This is stronger than the per-group
	 * exclude_from_ai flag and cannot be overridden — secrets must never
	 * reach a third-party AI. Layout-only types (message/separator/tab)
	 * carry no value: skipped, with a note so the omission is visible.
	 *
	 * @param array $field          Field definition.
	 * @param array $layout_omitted Collector for omitted layout-only names.
	 * @return array|null Entry, or null when the field is omitted.
	 */
	private static function field_entry( array $field, array &$layout_omitted ): ?array {
		$name = $field['name'] ?? '';
		if ( ! is_string( $name ) || '' === $name ) {
			return null;
		}

		$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';

		if ( 'password' === $type ) {
			return null;
		}

		// Group is a value-bearing container (stores() is false for it
		// only because the parent holds no meta itself): export as an
		// object keyed by short sub-field name. Handled before the
		// stores() check, which would wrongly class it with layout-only
		// types.
		if ( 'group' === $type ) {
			return self::group_entry( $field, $name );
		}

		// Repeater: ordered rows of inline sub-fields — export with the
		// CQ2 shape {type, name, min, max, button_label, layout,
		// sub_fields}. stores('repeater') is true, so the generic entry
		// below would also fire; the dedicated entry keeps the container
		// contract explicit (nested repeaters recurse to the same shape).
		if ( 'repeater' === $type ) {
			return self::repeater_entry( $field, $name );
		}

		if ( ! Field_Registry::stores( $type ) ) {
			$layout_omitted[] = $name;
			return null;
		}

		$entry = array(
			'label'         => isset( $field['label'] ) ? (string) $field['label'] : $name,
			'type'          => $type,
			'required'      => ! empty( $field['required'] ),
			'return_format' => self::describe_return_format( $type, $field ),
			'schema'        => self::field_schema( $type, $field ),
			'sample_value'  => self::sample_value( $type, $field ),
			'php_access'    => self::php_access_snippet( $name ),
			'block_binding' => self::block_binding_markup( $name, $type ),
		);

		// Select choices are part of the SCHEMA (the allowed
		// vocabulary), not stored values — safe and useful for AI.
		// Radio and button_group share select's choice vocabulary.
		if ( in_array( $entry['type'], array( 'select', 'radio', 'button_group' ), true ) && ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
			$entry['choices'] = $field['choices'];
		}

		// Admin-authored help text is schema, not values.
		if ( ! empty( $field['instructions'] ) && is_string( $field['instructions'] ) ) {
			$entry['instructions'] = $field['instructions'];
		}

		return $entry;
	}

	/**
	 * Whether a group's location rules let it apply to a post type.
	 *
	 * Mirrors the registry's match semantics restricted to post_type params:
	 * an explicit `post_type == X` rule for a different X excludes the group;
	 * groups with no post_type rule at all are included (conservative — they
	 * may apply through other params like taxonomy or page_template).
	 *
	 * @param array  $group     Group definition.
	 * @param string $post_type Post type slug.
	 */
	private static function group_applies_to_post_type( array $group, string $post_type ): bool {
		$rules = $group['location'] ?? array();
		if ( ! is_array( $rules ) || array() === $rules ) {
			return false; // Zero rules: applies nowhere (registry semantics).
		}

		$type_rules = array();
		foreach ( $rules as $rule ) {
			if ( is_array( $rule ) && 'post_type' === ( $rule['param'] ?? '' ) ) {
				$type_rules[] = $rule;
			}
		}

		if ( array() === $type_rules ) {
			return true; // No post_type restriction — may apply.
		}

		$use_any = 'any' === ( $group['location_match'] ?? 'all' );
		$result  = ! $use_any; // AND starts true, OR starts false.
		foreach ( $type_rules as $rule ) {
			$hit    = ( '!=' === ( $rule['operator'] ?? '==' ) )
				? $post_type !== (string) ( $rule['value'] ?? '' )
				: $post_type === (string) ( $rule['value'] ?? '' );
			$result = $use_any ? ( $result || $hit ) : ( $result && $hit );
		}

		return $result;
	}

	/**
	 * Human-readable description of what tk_get_field() returns (formatted)
	 * for a field type. Derived from the Fields service's format() behavior —
	 * SCHEMA knowledge, no value access.
	 *
	 * @param string $type  Field type slug.
	 * @param array  $field Field definition (only used for multiple-aware types).
	 */
	private static function describe_return_format( string $type, array $field = array() ): string {
		switch ( $type ) {
			case 'number':
			case 'range':
				return 'int|float';
			case 'checkbox':
				return 'bool';
			case 'image':
				return 'array{id:int, url:string, alt:string}';
			case 'link':
				return 'array{url:string, title:string, target:"_blank"|"_self"}';
			case 'date':
				return 'string (formatted date)';
			case 'datetime':
				return 'string (YYYY-MM-DD HH:MM:SS, localized on display)';
			case 'time':
				return 'string (HH:MM:SS, localized on display)';
			case 'color':
				return 'string (hex color, e.g. #ff0000)';
			case 'oembed':
				return 'string (URL; embed HTML is generated on display and never exported)';
			case 'icon':
				return 'string (dashicons slug, e.g. admin-post)';
			case 'file':
				return 'string (file URL)';
			case 'radio':
			case 'button_group':
				return 'string (choice value)';
			case 'post_object':
				return 'array{id:int, title:string, url:string, post_type:string}';
			case 'page_link':
				return 'string (URL; typed read is the discriminated array)';
			case 'taxonomy':
				return 'array of array{id:int, name:string, slug:string, taxonomy:string, url:string}';
			case 'user':
				$single = 'array{id:int, display_name:string, avatar_url:string, profile_url:string} (+email only with list_users cap)';
				return ! empty( $field['multiple'] ) ? 'array of ' . $single : $single;
			case 'relationship':
				return 'array of array{id:int, title:string, url:string, post_type:string}';
			case 'gallery':
				return 'array of array{id:int, url:string, alt:string}';
			case 'map':
				return 'array{lat:float|null, lng:float|null, zoom:int|null, address:string} (address stored denormalized; never re-geocoded at render)';
			case 'wysiwyg':
				return 'string (HTML)';
			case 'flexible_content':
				return 'array of array{layout:string, id:string, layout_label:string, fields:array (per-layout sub-values, keys are sub-field names)}';
			case 'repeater':
				return 'array of array (one per row, in order; keys are short sub-field names, values are each sub-field type\'s own formatted value — no row ids, no block markup)';
			case 'group':
				return 'array (object keyed by short sub-field name, e.g. array{street:string, city:string})';
			case 'clone':
				// Defensive: generate() expands clone children inline, so a
				// {"type":"clone"} entry is never emitted. Kept total.
				return 'never exported directly: resolved children are expanded inline as individual fields';
			case 'password':
				// Unreachable in practice: password fields are excluded from
				// export entirely. Defined so the format map stays total.
				return 'string (never exported)';
			default:
				return 'string';
		}
	}

	/**
	 * JSON-Schema-style schema for a field's STORED value (CQ3).
	 *
	 * Settings are enumerated onto the schema (Claude's rule); page_link is
	 * a discriminated oneOf (ChatGPT's). Every type declares its schema from
	 * day one per standing rule. SCHEMA knowledge only — no value access.
	 *
	 * @param string $type  Field type slug.
	 * @param array  $field Field definition.
	 */
	private static function field_schema( string $type, array $field ): array {
		switch ( $type ) {
			case 'number':
			case 'range':
				return array( 'type' => array( 'integer', 'number' ) );
			case 'checkbox':
				return array( 'type' => 'boolean' );
			case 'image':
			case 'file':
				return array( 'type' => 'integer', 'description' => 'WordPress attachment ID' );
			case 'link':
				return array(
					'type'       => 'object',
					'properties' => array(
						'url'    => array( 'type' => 'string' ),
						'title'  => array( 'type' => 'string' ),
						'target' => array( 'type' => 'string', 'enum' => array( '_blank', '_self' ) ),
					),
					'required'   => array( 'url' ),
				);
			case 'select':
			case 'radio':
			case 'button_group':
				$schema = array( 'type' => 'string' );
				if ( ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
					$schema['enum'] = array_map( 'strval', array_keys( $field['choices'] ) );
				}
				return $schema;
			case 'oembed':
				return array( 'type' => 'string', 'format' => 'uri', 'description' => 'Embeddable URL' );
			case 'post_object':
				return array(
					'type'        => 'integer',
					'description' => 'WordPress post ID',
					'post_types'  => array_values( (array) ( $field['post_types'] ?? array() ) ),
				);
			case 'page_link':
				return array(
					'oneOf' => array(
						array(
							'type'       => 'object',
							'properties' => array(
								'kind' => array( 'const' => 'post' ),
								'id'   => array( 'type' => 'integer', 'description' => 'WordPress post ID' ),
							),
							'required'   => array( 'kind', 'id' ),
						),
						array(
							'type'       => 'object',
							'properties' => array(
								'kind'     => array( 'const' => 'term' ),
								'id'       => array( 'type' => 'integer', 'description' => 'WordPress term ID' ),
								'taxonomy' => array( 'type' => 'string', 'description' => 'Taxonomy slug' ),
							),
							'required'   => array( 'kind', 'id', 'taxonomy' ),
						),
						array(
							'type'       => 'object',
							'properties' => array(
								'kind'  => array( 'const' => 'url' ),
								'value' => array( 'type' => 'string', 'format' => 'uri' ),
							),
							'required'   => array( 'kind', 'value' ),
						),
					),
				);
			case 'taxonomy':
				return array(
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'description' => 'Ordered term IDs',
					'taxonomy'    => (string) ( $field['taxonomy'] ?? '' ),
				);
			case 'user':
				if ( ! empty( $field['multiple'] ) ) {
					return array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => 'Ordered user IDs',
						'roles'       => array_values( (array) ( $field['roles'] ?? array() ) ),
					);
				}
				return array(
					'type'        => 'integer',
					'description' => 'WordPress user ID',
					'roles'       => array_values( (array) ( $field['roles'] ?? array() ) ),
				);
			case 'relationship':
				$schema = array(
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'description' => 'Ordered post IDs',
				);
				if ( ! empty( $field['post_types'] ) ) {
					$schema['post_types'] = array_values( (array) $field['post_types'] );
				}
				return $schema;
			case 'gallery':
				return array(
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'description' => 'Ordered attachment IDs',
					'mime_types'  => (string) ( $field['mime_types'] ?? 'image' ),
					'image_size'  => (string) ( $field['image_size'] ?? 'large' ),
				);
			case 'map':
				// Stored value schema (CQ3). Settings enumerated (Claude's
				// rule): enable_search is the opt-in Photon search toggle
				// (OFF by default), default_lat/default_lng/default_zoom
				// are the editor canvas's initial view.
				$map_schema = array(
					'type'        => 'object',
					'description' => 'Map location. The address is stored denormalized alongside the coordinates and is never re-geocoded at render time.',
					'properties'  => array(
						'lat'     => array( 'type' => array( 'number', 'null' ), 'minimum' => -90, 'maximum' => 90, 'description' => 'Latitude, clamped to -90..90' ),
						'lng'     => array( 'type' => array( 'number', 'null' ), 'minimum' => -180, 'maximum' => 180, 'description' => 'Longitude, clamped to -180..180' ),
						'zoom'    => array( 'type' => array( 'integer', 'null' ), 'description' => 'Map zoom level' ),
						'address' => array( 'type' => 'string', 'description' => 'Address as entered or returned by the geocoder at save time; never overwritten silently' ),
					),
					'enable_search' => ! empty( $field['enable_search'] ),
				);
				foreach ( array( 'default_lat', 'default_lng', 'default_zoom' ) as $key ) {
					if ( null !== ( $field[ $key ] ?? null ) ) {
						$map_schema[ $key ] = $field[ $key ];
					}
				}
				return $map_schema;
			case 'wysiwyg':
				$toolbar = $field['toolbar'] ?? 'full';
				if ( ! in_array( $toolbar, Field_Registry::WYSIWYG_TOOLBAR_PRESETS, true ) ) {
					$toolbar = 'full';
				}
				return array(
					'type'             => 'string',
					'description'      => 'HTML content, wp_kses_post-filtered on every save unless allow_unfiltered',
					'toolbar'          => $toolbar,
					'allow_unfiltered' => ! empty( $field['allow_unfiltered'] ),
				);
			case 'flexible_content':
				// The ordered rows array; each layout's sub-fields are
				// enumerated with their own inline stored-value schemas.
				// min/max are the per-layout row count limits (0 max =
				// unlimited); button_label is the editor's add-row caption.
				$layouts_schema = array();
				foreach ( Field_Registry::instance()->flexible_layouts( $field ) as $layout_key => $layout ) {
					$sub_schemas = array();
					foreach ( $layout['fields'] ?? array() as $sub ) {
						if ( ! is_array( $sub ) ) {
							continue;
						}
						$sub_name = (string) ( $sub['name'] ?? '' );
						if ( '' === $sub_name ) {
							continue;
						}
						// Password sub-fields are unconditionally excluded
						// from AI context — same posture as field_entry().
						if ( 'password' === (string) ( $sub['type'] ?? '' ) ) {
							continue;
						}
						$sub_entry = array(
							'label'  => isset( $sub['label'] ) ? (string) $sub['label'] : $sub_name,
							'schema' => self::field_schema( (string) ( $sub['type'] ?? 'text' ), $sub ),
						);
						if ( ! empty( $sub['required'] ) ) {
							$sub_entry['required'] = true;
						}
						$sub_schemas[ $sub_name ] = $sub_entry;
					}
					$layouts_schema[ $layout_key ] = array(
						'label'  => (string) ( $layout['label'] ?? $layout_key ),
						'min'    => max( 0, (int) ( $layout['min'] ?? 0 ) ),
						'max'    => max( 0, (int) ( $layout['max'] ?? 0 ) ),
						'fields' => $sub_schemas,
					);
				}
				$flex_schema = array(
					'type'        => 'array',
					'description' => 'Ordered rows; each row is {layout:string, id:string (stable row identity), fields:object keyed by sub-field name}',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'layout' => array( 'type' => 'string' ),
							'id'     => array( 'type' => 'string' ),
							'fields' => array( 'type' => 'object' ),
						),
						'required'   => array( 'layout', 'fields' ),
					),
					'layouts'     => $layouts_schema,
				);
				if ( isset( $field['button_label'] ) && is_string( $field['button_label'] ) && '' !== $field['button_label'] ) {
					$flex_schema['button_label'] = $field['button_label'];
				}
				return $flex_schema;
			case 'repeater':
				// The ordered rows array; each sub-field is enumerated with
				// its own inline stored-value schema (recursive for nested
				// repeaters/groups). min/max are the ROW count limits
				// (0 = unlimited); layout is the row presentation;
				// button_label is the editor's add-row caption.
				$rep_sub_schemas = array();
				foreach ( Field_Registry::instance()->resolve_repeater_children( $field ) as $short => $child ) {
					if ( ! is_array( $child ) || '' === (string) $short ) {
						continue;
					}
					// Password sub-fields are unconditionally excluded
					// from AI context — same posture as field_entry().
					if ( 'password' === (string) ( $child['type'] ?? '' ) ) {
						continue;
					}
					$sub_entry = array(
						'label'  => isset( $child['label'] ) && is_string( $child['label'] ) && '' !== $child['label'] ? (string) $child['label'] : (string) $short,
						'schema' => self::field_schema( (string) ( $child['type'] ?? 'text' ), $child ),
					);
					if ( ! empty( $child['required'] ) ) {
						$sub_entry['required'] = true;
					}
					$rep_sub_schemas[ (string) $short ] = $sub_entry;
				}
				$rep_schema = array(
					'type'        => 'array',
					'description' => 'Ordered rows; each row is an object keyed by short sub-field name. Order is presentation; the persisted row id is identity and is NOT part of the public value namespace.',
					'items'       => array( 'type' => 'object' ),
					'min'         => max( 0, (int) ( $field['min'] ?? 0 ) ),
					'max'         => max( 0, (int) ( $field['max'] ?? 0 ) ),
					'layout'      => in_array( $field['layout'] ?? '', array( 'list', 'grid' ), true ) ? $field['layout'] : 'list',
					'sub_fields'  => $rep_sub_schemas,
				);
				if ( isset( $field['button_label'] ) && is_string( $field['button_label'] ) && '' !== $field['button_label'] ) {
					$rep_schema['button_label'] = $field['button_label'];
				}
				return $rep_schema;
			case 'group':
				// The group value is an object keyed by SHORT sub-field
				// name (each sub-field stored under groupname_subname). The
				// parent stores nothing; children are enumerated with
				// their own inline stored-value schemas (mirror of the
				// flexible_content sub-field loop).
				$sub_schemas = array();
				foreach ( Field_Registry::instance()->resolve_group_children( $field ) as $short => $child ) {
					if ( ! is_array( $child ) || '' === (string) $short ) {
						continue;
					}
					// Password sub-fields are unconditionally excluded
					// from AI context — same posture as field_entry().
					if ( 'password' === (string) ( $child['type'] ?? '' ) ) {
						continue;
					}
					$sub_entry = array(
						'label'         => isset( $child['label'] ) && is_string( $child['label'] ) && '' !== $child['label'] ? (string) $child['label'] : (string) $short,
						'schema'        => self::field_schema( (string) ( $child['type'] ?? 'text' ), $child ),
						'storage_key'   => (string) ( $child['name'] ?? '' ),
					);
					if ( ! empty( $child['required'] ) ) {
						$sub_entry['required'] = true;
					}
					$sub_schemas[ (string) $short ] = $sub_entry;
				}
				return array(
					'type'        => 'object',
					'description' => 'Group value: object keyed by short sub-field name. The group key itself stores nothing; each sub-field is stored under groupname_subname.',
					'properties'  => $sub_schemas,
				);
			case 'clone':
				// Defensive: generate() expands clone children inline, so
				// this is never reached. Kept so the schema map stays total.
				return array(
					'type'        => 'object',
					'description' => 'Never exported directly: clone children are expanded inline as individual fields under their namespaced keys.',
				);
			default:
				return array( 'type' => 'string' );
		}
	}

	/**
	 * PHP access snippet for a field, e.g. tk_get_field('price', $post_id).
	 *
	 * @param string $name Field name.
	 */
	private static function php_access_snippet( string $name ): string {
		return sprintf( 'tk_get_field(\'%s\', $post_id)', $name );
	}

	/**
	 * Type-aware Block Bindings markup for a field (v0.17.0).
	 *
	 * Previously every field got the same wp:paragraph/content binding,
	 * which misled AI consumers into generating broken markup for images,
	 * links, and array-valued fields. The snippet now matches the field
	 * type:
	 * - string-ish types → wp:paragraph binding `content`
	 * - image → wp:image binding `url`
	 * - link → wp:buttons/wp:button binding `url`
	 * - array/object types (user, post_object, taxonomy, relationship,
	 *   gallery, map, flexible_content, group, repeater) → an HTML comment note
	 *   instead of a binding. The tk-fields/field binding source only
	 *   resolves scalar values (non-scalars fall back to the block's
	 *   original content), so emitting a naive paragraph binding for an
	 *   array field would silently render nothing. The note tells the AI
	 *   which sub-properties are available via tk_get_field() instead.
	 *
	 * @param string $name Field name.
	 * @param string $type Field type slug.
	 */
	public static function block_binding_markup( string $name, string $type = 'text' ): string {
		switch ( $type ) {
			case 'image':
				return sprintf(
					'<!-- wp:image {"metadata":{"bindings":{"url":{"source":"tk-fields/field","args":{"field":"%s"}}}}} -->',
					$name
				);
			case 'link':
				return sprintf(
					'<!-- wp:buttons --><!-- wp:button {"metadata":{"bindings":{"url":{"source":"tk-fields/field","args":{"field":"%s"}}}}} --><div class="wp-block-button"><a class="wp-block-button__link">Link</a></div><!-- /wp:button --><!-- /wp:buttons -->',
					$name
				);
			case 'user':
				return sprintf(
					'<!-- tk-fields: "%s" is a user field (object: id, display_name, avatar_url, profile_url). Block bindings only resolve scalars — render with tk_get_field("%s", $post_id) and pick a sub-property, e.g. ["display_name"]. -->',
					$name,
					$name
				);
			case 'post_object':
				return sprintf(
					'<!-- tk-fields: "%s" is a post_object field (object: id, title, url, post_type). Block bindings only resolve scalars — render with tk_get_field("%s", $post_id) and pick a sub-property, e.g. ["title"]. -->',
					$name,
					$name
				);
			case 'taxonomy':
				return sprintf(
					'<!-- tk-fields: "%s" is a taxonomy field (array of term objects: id, name, slug, taxonomy, url). Block bindings only resolve scalars — render with tk_get_field("%s", $post_id) and loop the terms. -->',
					$name,
					$name
				);
			case 'relationship':
				return sprintf(
					'<!-- tk-fields: "%s" is a relationship field (array of post objects: id, title, url, post_type). Block bindings only resolve scalars — render with tk_get_field("%s", $post_id) and loop the posts. -->',
					$name,
					$name
				);
			case 'gallery':
				return sprintf(
					'<!-- tk-fields: "%s" is a gallery field (array of image objects: id, url, alt). Block bindings only resolve scalars — render with tk_get_field("%s", $post_id) and loop the images. -->',
					$name,
					$name
				);
			case 'map':
				return sprintf(
					'<!-- tk-fields: "%s" is a map field (object: lat, lng, zoom, address). Block bindings only resolve scalars — render with tk_get_field("%s", $post_id) and pick a sub-property. -->',
					$name,
					$name
				);
			case 'flexible_content':
				return sprintf(
					'<!-- tk-fields: "%s" is a flexible_content field (array of layout rows). Block bindings only resolve scalars — render with tk_get_field("%s", $post_id) and loop the rows. -->',
					$name,
					$name
				);
			case 'group':
				return sprintf(
					'<!-- tk-fields: "%s" is a group field (object keyed by sub-field name). Block bindings only resolve scalars — render with tk_get_field("%s", $post_id) and read sub-keys. -->',
					$name,
					$name
				);
			case 'repeater':
				return sprintf(
					'<!-- tk-fields: "%s" is a repeater field (array of row objects keyed by sub-field name). Block bindings only resolve scalars — render with tk_get_field("%s", $post_id) and loop the rows. -->',
					$name,
					$name
				);
			case 'text':
			case 'textarea':
			case 'number':
			case 'range':
			case 'email':
			case 'url':
			case 'checkbox':
			case 'select':
			case 'radio':
			case 'button_group':
			case 'date':
			case 'datetime':
			case 'time':
			case 'color':
			case 'oembed':
			case 'icon':
			case 'file':
			case 'page_link':
			case 'wysiwyg':
			default:
				return sprintf(
					'<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"tk-fields/field","args":{"field":"%s"}}}}} -->',
					$name
				);
		}
	}

	/**
	 * A realistic sample value for a field type (v0.17.0).
	 *
	 * Gives AI consumers a concrete example of the FORMATTED value shape
	 * (what tk_get_field() returns), which dramatically improves few-shot
	 * code generation versus a bare type name. Schema knowledge only — no
	 * field values are read.
	 *
	 * @param string $type  Field type slug.
	 * @param array  $field Field definition (choices used when present).
	 * @return mixed Sample value (scalar or array).
	 */
	public static function sample_value( string $type, array $field = array() ): mixed {
		switch ( $type ) {
			case 'number':
			case 'range':
				return 42;
			case 'checkbox':
				return true;
			case 'image':
				return array(
					'id'  => 102,
					'url' => 'https://example.com/uploads/photo.jpg',
					'alt' => 'Sample photo',
				);
			case 'user':
				return array(
					'id'           => 1,
					'display_name' => 'John Doe',
					'avatar_url'   => 'https://example.com/avatar.jpg',
				);
			case 'text':
				return 'Senior Web Developer';
			case 'textarea':
				return "Senior Web Developer\n10 years of experience building WordPress sites.";
			case 'email':
				return 'jane.doe@example.com';
			case 'url':
				return 'https://example.com';
			case 'select':
			case 'radio':
			case 'button_group':
				if ( ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
					$keys = array_keys( $field['choices'] );
					return (string) reset( $keys );
				}
				return 'option-one';
			case 'date':
				return '2026-09-27';
			case 'datetime':
				return '2026-09-27 14:30:00';
			case 'time':
				return '14:30:00';
			case 'color':
				return '#3858e9';
			case 'oembed':
				return 'https://example.com/embedded-video';
			case 'icon':
				return 'admin-post';
			case 'file':
				return 'https://example.com/uploads/document.pdf';
			case 'link':
				return array(
					'url'    => 'https://example.com',
					'title'  => 'Example',
					'target' => '_blank',
				);
			case 'post_object':
				return array(
					'id'        => 7,
					'title'     => 'Sample Post',
					'url'       => 'https://example.com/sample-post/',
					'post_type' => 'post',
				);
			case 'page_link':
				return 'https://example.com/sample-page/';
			case 'taxonomy':
				return array(
					array(
						'id'       => 3,
						'name'     => 'News',
						'slug'     => 'news',
						'taxonomy' => 'category',
						'url'      => 'https://example.com/category/news/',
					),
				);
			case 'relationship':
				return array(
					array(
						'id'        => 7,
						'title'     => 'Related Post',
						'url'       => 'https://example.com/related-post/',
						'post_type' => 'post',
					),
				);
			case 'gallery':
				return array(
					array(
						'id'  => 102,
						'url' => 'https://example.com/uploads/photo.jpg',
						'alt' => 'Sample photo',
					),
				);
			case 'wysiwyg':
				return '<p>Sample rich text with <strong>formatting</strong>.</p>';
			case 'map':
				return array(
					'lat'     => 6.5244,
					'lng'     => 3.3792,
					'zoom'    => 12,
					'address' => 'Lagos, Nigeria',
				);
			case 'flexible_content':
				return array(
					array(
						'layout' => 'sample_layout',
						'id'     => 'row_1',
						'fields' => array( 'heading' => 'Sample heading' ),
					),
				);
			case 'repeater':
				// One sample row: sub-field values keyed by short name,
				// each the sub-field type's own sample (recursive for
				// nested repeaters/groups).
				$samples = array();
				foreach ( Field_Registry::instance()->resolve_repeater_children( $field ) as $short => $child ) {
					if ( ! is_array( $child ) || '' === (string) $short ) {
						continue;
					}
					// Password sub-fields are unconditionally excluded
					// from AI context — same posture as field_entry().
					if ( 'password' === (string) ( $child['type'] ?? '' ) ) {
						continue;
					}
					$samples[ (string) $short ] = self::sample_value( (string) ( $child['type'] ?? 'text' ), $child );
				}
				return array( $samples );
			case 'group':
				$samples = array();
				foreach ( Field_Registry::instance()->resolve_group_children( $field ) as $short => $child ) {
					if ( ! is_array( $child ) || '' === (string) $short ) {
						continue;
					}
					// Password sub-fields are unconditionally excluded
					// from AI context — same posture as field_entry().
					if ( 'password' === (string) ( $child['type'] ?? '' ) ) {
						continue;
					}
					$samples[ (string) $short ] = self::sample_value( (string) ( $child['type'] ?? 'text' ), $child );
				}
				return $samples;
			default:
				return 'Sample value';
		}
	}

	/**
	 * Normalize a group's location rules for export (v0.17.0).
	 *
	 * Accepts the canonical `location` key, a legacy `locations` key, and
	 * nested rule groups (each rendered as one entry with its sub-rules).
	 * Rules are passed through as {param, operator, value} triples — the
	 * same shape the admin builder saves.
	 *
	 * @param array $group Group definition.
	 * @return array Normalized location entries.
	 */
	private static function normalize_locations( array $group ): array {
		$raw = $group['location'] ?? $group['locations'] ?? array();
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			// Nested rule group: {rules: [...]} — keep as one entry.
			if ( isset( $rule['rules'] ) && is_array( $rule['rules'] ) ) {
				$sub = array();
				foreach ( $rule['rules'] as $s ) {
					if ( ! is_array( $s ) ) {
						continue;
					}
					$sub[] = array(
						'param'    => (string) ( $s['param'] ?? '' ),
						'operator' => (string) ( $s['operator'] ?? '==' ),
						'value'    => $s['value'] ?? '',
					);
				}
				$out[] = array( 'rule_group' => $sub );
				continue;
			}
			$out[] = array(
				'param'    => (string) ( $rule['param'] ?? '' ),
				'operator' => (string) ( $rule['operator'] ?? '==' ),
				'value'    => $rule['value'] ?? '',
			);
		}

		return $out;
	}

	/**
	 * Short human-readable summary of a generated context document.
	 *
	 * @param array $context Output of generate().
	 */
	public static function to_markdown( array $context ): string {
		$lines   = array();
		$lines[] = '# ' . ( $context['title'] ?? 'Custom Fields Schema' );
		$lines[] = '';
		$lines[] = sprintf(
			'Generated %s · plugin %s %s',
			$context['generated_at'] ?? 'unknown',
			$context['plugin'] ?? 'tk-fields',
			$context['plugin_version'] ?? ''
		);
		$lines[] = '';

		$entities = $context['entities'] ?? array();
		if ( array() === $entities ) {
			$lines[] = '_No field groups exported._';
			return implode( "\n", $lines ) . "\n";
		}

		foreach ( $entities as $entity ) {
			$fields = $entity['fields'] ?? array();
			$count  = count( $fields );
			$lines[] = sprintf( '## %s (%d %s)', $entity['group_title'] ?? '(untitled)', $count, 1 === $count ? 'field' : 'fields' );

			$loc = self::describe_locations( $entity['locations'] ?? array(), $entity['location_match'] ?? 'all' );
			if ( '' !== $loc ) {
				$lines[] = 'Applies to: ' . $loc;
			}

			foreach ( $fields as $name => $field ) {
				$bits = array( (string) $field['type'] );
				if ( ! empty( $field['required'] ) ) {
					$bits[] = 'required';
				}
				$lines[] = sprintf( '- `%s` (%s) — `%s`', $name, implode( ', ', $bits ), $field['php_access'] ?? '' );
			}
			$lines[] = '';
		}

		// v0.15.0: content types section.
		$content_types = $context['content_types'] ?? array();
		if ( array() !== $content_types ) {
			$lines[] = '## Content Types';
			$lines[] = '';
			foreach ( $content_types as $type ) {
				$bits = array( '`' . ( $type['slug'] ?? '' ) . '`' );
				$bits[] = ! empty( $type['public'] ) ? 'public' : 'private';
				if ( ! empty( $type['show_in_rest'] ) ) {
					$bits[] = 'REST: /wp/v2/' . ( $type['rest_base'] ?? $type['slug'] );
				}
				if ( ! empty( $type['hierarchical'] ) ) {
					$bits[] = 'hierarchical';
				}
				$lines[] = sprintf(
					'- **%s** (%s)',
					$type['plural'] ?? $type['slug'] ?? '',
					implode( ', ', $bits )
				);
			}
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	/**
	 * One-line summary of location rules for the markdown format.
	 *
	 * @param array  $rules Location rules.
	 * @param string $match 'all' or 'any'.
	 */
	private static function describe_locations( array $rules, string $match ): string {
		$parts = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			// v0.12.0: rule groups render parenthesized, e.g.
			// "(post_type == post AND page_template == default)".
			if ( isset( $rule['rules'] ) && is_array( $rule['rules'] ) ) {
				$inner = array();
				foreach ( $rule['rules'] as $sub ) {
					if ( ! is_array( $sub ) ) {
						continue;
					}
					$inner[] = sprintf( '%s %s %s', $sub['param'] ?? '?', $sub['operator'] ?? '==', $sub['value'] ?? '' );
				}
				$parts[] = '(' . ( $inner ? implode( ' AND ', $inner ) : 'no rules' ) . ')';
				continue;
			}
			$parts[] = sprintf( '%s %s %s', $rule['param'] ?? '?', $rule['operator'] ?? '==', $rule['value'] ?? '' );
		}

		return implode( 'any' === $match ? ' OR ' : ' AND ', $parts );
	}
}
