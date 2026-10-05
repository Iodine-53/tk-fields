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
 * Field-group persistence.
 *
 * Field groups are stored as a custom post type (`tk-field-group`, non-public,
 * no core UI — we render our own). The group definition lives as JSON in a
 * single post meta key (`_tk_fields_group`); the post title is the group
 * title and the post status is the group status.
 *
 * Every payload is fully sanitized and validated on the way in. Fail closed:
 * an invalid payload returns a WP_Error and nothing is written.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Group_Store {

	public const CPT      = 'tk-field-group';
	public const META_KEY = '_tk_fields_group';

	/**
	 * @var self|null
	 */
	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Register hooks. Called from the main plugin file.
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		// v0.12.2: programmatic paths (wp-cli, direct wp_insert_post) bypass
		// create() and leave the group post without its definition meta, which
		// made the group invisible to all()/get() and every consumer of them
		// (REST list, admin list table, classic renderer, …). Backfill a
		// minimal valid definition on insert so every group post is a group.
		add_action( 'save_post_' . self::CPT, array( $this, 'backfill_definition_meta' ), 20, 3 );
	}

	/**
	 * Backfill the group-definition meta when a group post is saved without
	 * one. Fires for wp-cli / API / direct wp_insert_post creations; never
	 * overwrites an existing definition. update_post_meta() does not re-fire
	 * save_post, so there is no recursion risk.
	 *
	 * @param int     $post_id Post ID.
	 * @param \WP_Post $post   Post object.
	 * @param bool    $update  Whether this is an update.
	 */
	public function backfill_definition_meta( int $post_id, \WP_Post $post, bool $update ): void {
		$existing = get_post_meta( $post_id, self::META_KEY, true );
		if ( is_string( $existing ) && '' !== $existing ) {
			return;
		}

		$json = wp_json_encode(
			array(
				'location'        => array(),
				'location_match'  => 'all',
				'fields'          => array(),
				'presentation'    => array(),
				'exclude_from_ai' => false,
			)
		);
		if ( is_string( $json ) ) {
			update_post_meta( $post_id, self::META_KEY, $json );
		}
	}

	/**
	 * Register the field-group post type. Non-public, no core UI.
	 *
	 * v0.15.0: every capability maps to the custom `manage_tk_fields` primitive
 * (granted to administrators on init). The default `post`
	 * caps would let any Author+ inject group definitions via XML-RPC
	 * wp.newPost custom fields; read paths (get_post/get_posts) perform no
	 * cap checks, so frontend rendering is unaffected.
	 */
	public function register_post_type(): void {
		register_post_type(
			self::CPT,
			array(
				'label'               => __( 'Field Groups', 'tk-fields' ),
				'labels'              => array(
					'name'          => __( 'Field Groups', 'tk-fields' ),
					'singular_name' => __( 'Field Group', 'tk-fields' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'       => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'can_export'          => true,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'capabilities'        => array(
					'edit_post'          => 'manage_tk_fields',
					'read_post'          => 'manage_tk_fields',
					'delete_post'        => 'manage_tk_fields',
					'edit_posts'         => 'manage_tk_fields',
					'edit_others_posts'  => 'manage_tk_fields',
					'publish_posts'      => 'manage_tk_fields',
					'read_private_posts' => 'manage_tk_fields',
					'create_posts'       => 'manage_tk_fields',
				),
			)
		);
	}

	/**
	 * Allowed location rule params.
	 *
	 * @var string[]
	 */
	private const LOCATION_PARAMS = array( 'post_type', 'page_template', 'taxonomy', 'post' );

	/**
	 * Allowed location / conditional operators.
	 *
	 * @var string[]
	 */
	private const OPERATORS = array( '==', '!=', '>', '<', '>=', '<=', 'empty', '!empty' );

	/**
	 * Sanitize + validate a full group payload. Fail closed: returns WP_Error
	 * and nothing should be written when the payload is invalid.
	 *
	 * Expected shape:
	 *   title, location[], location_match, fields[], presentation{}, exclude_from_ai
	 *
	 * @param array $payload Raw payload (e.g. decoded REST body).
	 * @return array|WP_Error Sanitized group definition (without id/title/status), or WP_Error.
	 */
	public function sanitize_group( array $payload ): array|\WP_Error {
		$title = isset( $payload['title'] ) ? sanitize_text_field( (string) $payload['title'] ) : '';
		if ( '' === $title ) {
			return new \WP_Error( 'tk_fields_group_invalid_title', __( 'Group title is required.', 'tk-fields' ), array( 'status' => 400 ) );
		}

		$location = $payload['location'] ?? array();
		if ( ! is_array( $location ) ) {
			return new \WP_Error( 'tk_fields_group_invalid_location', __( 'Location must be an array of rules.', 'tk-fields' ), array( 'status' => 400 ) );
		}
		$rules = array();
		foreach ( $location as $i => $rule ) {
			// v0.12.0: rule-group shape { rules: [...] }. AND within the
			// group; groups are combined with flat rules via location_match.
			// A zero-rule group is kept (it matches nothing by design).
			if ( is_array( $rule ) && isset( $rule['rules'] ) ) {
				if ( ! is_array( $rule['rules'] ) ) {
					return new \WP_Error(
						'tk_fields_group_invalid_location_rule',
						sprintf(
							/* translators: %d: rule index */
							__( 'Location rule %1$d is invalid: a rule group must have a "rules" array.', 'tk-fields' ),
							(int) $i
						),
						array( 'status' => 400 )
					);
				}
				$clean_group = array();
				foreach ( $rule['rules'] as $j => $sub ) {
					$clean_sub = $this->sanitize_location_rule( $sub );
					if ( is_wp_error( $clean_sub ) ) {
						return new \WP_Error(
							'tk_fields_group_invalid_location_rule',
							sprintf(
								/* translators: %1$d: rule index, %2$d: sub-rule index, %3$s: reason */
								__( 'Location rule %1$d, sub-rule %2$d is invalid: %3$s', 'tk-fields' ),
								(int) $i,
								(int) $j,
								$clean_sub->get_error_message()
							),
							array( 'status' => 400 )
						);
					}
					$clean_group[] = $clean_sub;
				}
				$rules[] = array( 'rules' => $clean_group );
				continue;
			}
			$clean = $this->sanitize_location_rule( $rule );
			if ( is_wp_error( $clean ) ) {
				return new \WP_Error(
					'tk_fields_group_invalid_location_rule',
					sprintf(
						/* translators: %d: rule index, %s: reason */
						__( 'Location rule %1$d is invalid: %2$s', 'tk-fields' ),
						(int) $i,
						$clean->get_error_message()
					),
					array( 'status' => 400 )
				);
			}
			$rules[] = $clean;
		}

		$location_match = $payload['location_match'] ?? 'all';
		if ( ! in_array( $location_match, array( 'all', 'any' ), true ) ) {
			return new \WP_Error( 'tk_fields_group_invalid_location_match', __( 'location_match must be "all" or "any".', 'tk-fields' ), array( 'status' => 400 ) );
		}

		$fields = $payload['fields'] ?? array();
		if ( ! is_array( $fields ) ) {
			return new \WP_Error( 'tk_fields_group_invalid_fields', __( 'Fields must be an array.', 'tk-fields' ), array( 'status' => 400 ) );
		}
		$clean_fields = array();
		$seen_names   = array();
		$seen_keys    = array();
		foreach ( $fields as $i => $field ) {
			$clean = $this->sanitize_field( $field );
			if ( is_wp_error( $clean ) ) {
				return new \WP_Error(
					'tk_fields_group_invalid_field',
					sprintf(
						/* translators: %d: field index, %s: reason */
						__( 'Field %1$d is invalid: %2$s', 'tk-fields' ),
						(int) $i,
						$clean->get_error_message()
					),
					array( 'status' => 400 )
				);
			}
			if ( isset( $seen_names[ $clean['name'] ] ) ) {
				/* translators: %s: field name */
				return new \WP_Error( 'tk_fields_group_duplicate_name', sprintf( __( 'Duplicate field name "%1$s".', 'tk-fields' ), $clean['name'] ), array( 'status' => 400 ) );
			}
			if ( isset( $seen_keys[ $clean['key'] ] ) ) {
				/* translators: %s: field key */
				return new \WP_Error( 'tk_fields_group_duplicate_key', sprintf( __( 'Duplicate field key "%1$s".', 'tk-fields' ), $clean['key'] ), array( 'status' => 400 ) );
			}
			$seen_names[ $clean['name'] ] = true;
			$seen_keys[ $clean['key'] ]   = true;
			$clean_fields[]               = $clean;
		}

		// Conditional logic may only reference fields within this group.
		foreach ( $clean_fields as $field ) {
			foreach ( $field['conditional_logic'] as $cond ) {
				if ( ! isset( $seen_keys[ $cond['field'] ] ) ) {
					return new \WP_Error(
						'tk_fields_group_bad_conditional',
						/* translators: 1: field name, 2: field key referenced by the condition */
						sprintf( __( 'Field "%1$s" has conditional logic referencing unknown field key "%2$s".', 'tk-fields' ), $field['name'], $cond['field'] ),
						array( 'status' => 400 )
					);
				}
			}
		}

		$presentation = $this->sanitize_presentation( $payload['presentation'] ?? array() );
		if ( is_wp_error( $presentation ) ) {
			return $presentation;
		}

		return array(
			'location'       => $rules,
			'location_match' => $location_match,
			'fields'         => $clean_fields,
			'presentation'   => $presentation,
			'exclude_from_ai' => ! empty( $payload['exclude_from_ai'] ),
			'_title'         => $title, // Internal: consumed by create/update, never persisted in JSON.
		);
	}

	/**
	 * Sanitize one location rule.
	 *
	 * @param mixed $rule Raw rule array.
	 * @return array|WP_Error
	 */
	private function sanitize_location_rule( mixed $rule ): array|\WP_Error {
		if ( ! is_array( $rule ) ) {
			return new \WP_Error( 'rule', __( 'Rule must be an object.', 'tk-fields' ) );
		}

		$param = $rule['param'] ?? '';
		if ( ! in_array( $param, self::LOCATION_PARAMS, true ) ) {
			/* translators: %s: unknown parameter */
			return new \WP_Error( 'rule', sprintf( __( 'Unknown param "%1$s".', 'tk-fields' ), is_scalar( $param ) ? (string) $param : gettype( $param ) ) );
		}

		$operator = $rule['operator'] ?? '==';
		if ( ! in_array( $operator, array( '==', '!=' ), true ) ) {
			/* translators: %s: unknown operator */
			return new \WP_Error( 'rule', sprintf( __( 'Unknown operator "%1$s" for location rules.', 'tk-fields' ), is_scalar( $operator ) ? (string) $operator : gettype( $operator ) ) );
		}

		$value = isset( $rule['value'] ) ? sanitize_text_field( (string) $rule['value'] ) : '';
		if ( '' === $value ) {
			return new \WP_Error( 'rule', __( 'Rule value is required.', 'tk-fields' ) );
		}

		switch ( $param ) {
			case 'post_type':
				if ( ! post_type_exists( $value ) ) {
					/* translators: %s: unknown post type */
					return new \WP_Error( 'rule', sprintf( __( 'Unknown post type "%1$s".', 'tk-fields' ), $value ) );
				}
				break;
			case 'page_template':
				if ( 'default' !== $value && ! preg_match( '/^[A-Za-z0-9_\-\/]+\.php$/', $value ) ) {
					/* translators: %s: invalid page template */
					return new \WP_Error( 'rule', sprintf( __( 'Invalid page template "%1$s".', 'tk-fields' ), $value ) );
				}
				break;
			case 'taxonomy':
				// Value format: "taxonomy|term_slug", e.g. "category|news".
				if ( ! preg_match( '/^([a-z0-9_]+)\|([A-Za-z0-9_\-]+)$/', $value, $m ) ) {
					return new \WP_Error( 'rule', __( 'Taxonomy rule value must be "taxonomy|term_slug".', 'tk-fields' ) );
				}
				if ( ! taxonomy_exists( $m[1] ) ) {
					/* translators: %s: unknown taxonomy */
					return new \WP_Error( 'rule', sprintf( __( 'Unknown taxonomy "%1$s".', 'tk-fields' ), $m[1] ) );
				}
				break;
			case 'post':
				if ( ! ctype_digit( $value ) || (int) $value <= 0 ) {
					/* translators: %s: invalid post id */
					return new \WP_Error( 'rule', sprintf( __( 'Invalid post ID "%1$s".', 'tk-fields' ), $value ) );
				}
				break;
		}

		return array(
			'param'    => $param,
			'operator' => $operator,
			'value'    => $value,
		);
	}

	/**
	 * Sanitize one field definition.
	 *
	 * @param mixed $field Raw field array.
	 * @return array|WP_Error
	 */
	private function sanitize_field( mixed $field, int $repeater_depth = 0 ): array|\WP_Error {
		if ( ! is_array( $field ) ) {
			return new \WP_Error( 'field', __( 'Field must be an object.', 'tk-fields' ) );
		}

		$key = isset( $field['key'] ) ? (string) $field['key'] : '';
		if ( ! preg_match( '/^f_[A-Za-z0-9_]{1,64}$/', $key ) ) {
			return new \WP_Error( 'field', __( 'Field key must look like "f_<unique id>".', 'tk-fields' ) );
		}

		$name = isset( $field['name'] ) ? (string) $field['name'] : '';
		if ( ! preg_match( '/^[a-z0-9_]+$/', $name ) ) {
			/* translators: %s: field name */
			return new \WP_Error( 'field', sprintf( __( 'Invalid field name "%1$s": lowercase letters, digits and underscores only.', 'tk-fields' ), $name ) );
		}

		$label = isset( $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : '';
		if ( '' === $label ) {
			/* translators: %s: field name */
			return new \WP_Error( 'field', sprintf( __( 'Field "%1$s" needs a label.', 'tk-fields' ), $name ) );
		}

		$type = $field['type'] ?? 'text';
		if ( ! in_array( $type, Field_Registry::types(), true ) ) {
			/* translators: %s: unknown field type */
			return new \WP_Error( 'field', sprintf( __( 'Unknown field type "%1$s".', 'tk-fields' ), is_scalar( $type ) ? (string) $type : gettype( $type ) ) );
		}

		$choices = $field['choices'] ?? array();
		if ( ! is_array( $choices ) ) {
			/* translators: %s: field name */
			return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": choices must be an object.', 'tk-fields' ), $name ) );
		}
		$clean_choices = array();
		foreach ( $choices as $cval => $clabel ) {
			$cval   = sanitize_text_field( (string) $cval );
			$clabel = sanitize_text_field( (string) $clabel );
			if ( '' === $cval ) {
				continue;
			}
			$clean_choices[ $cval ] = $clabel;
		}
		if ( in_array( $type, array( 'select', 'radio', 'button_group' ), true ) && empty( $clean_choices ) ) {
			/* translators: %s: field name */
			return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": choice-based fields need at least one choice.', 'tk-fields' ), $name ) );
		}
		if ( ! in_array( $type, array( 'select', 'radio', 'button_group' ), true ) ) {
			$clean_choices = array();
		}

		// --- Round 3 Batch A relational settings ---
		$post_types = array_values( array_filter( array_map( 'sanitize_key', (array) ( $field['post_types'] ?? array() ) ) ) );

		$taxonomy = isset( $field['taxonomy'] ) ? sanitize_key( (string) $field['taxonomy'] ) : '';
		if ( '' !== $taxonomy && ! taxonomy_exists( $taxonomy ) ) {
			/* translators: 1: field name, 2: taxonomy slug */
			return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": unknown taxonomy "%2$s".', 'tk-fields' ), $name, $taxonomy ) );
		}
		$save_terms = ! empty( $field['save_terms'] );
		$load_terms = ! empty( $field['load_terms'] );
		if ( ( $save_terms || $load_terms ) && '' === $taxonomy ) {
			/* translators: %s: field name */
			return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": Save/Load Terms need a taxonomy selected.', 'tk-fields' ), $name ) );
		}
		$field_type = in_array( $field['field_type'] ?? '', array( 'checkbox', 'autocomplete' ), true ) ? $field['field_type'] : 'autocomplete';

		// Role filter: intersect with real role slugs; an empty list means
		// "all roles".
		$roles = array_values(
			array_intersect(
				array_filter( array_map( 'sanitize_key', (array) ( $field['roles'] ?? array() ) ) ),
				array_keys( wp_roles()->get_names() )
			)
		);

		$filter_taxonomy = isset( $field['filter_taxonomy'] ) ? sanitize_key( (string) $field['filter_taxonomy'] ) : '';
		if ( '' !== $filter_taxonomy && ! taxonomy_exists( $filter_taxonomy ) ) {
			/* translators: 1: field name, 2: filter taxonomy slug */
			return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": unknown filter taxonomy "%2$s".', 'tk-fields' ), $name, $filter_taxonomy ) );
		}

		$image_size = in_array( $field['image_size'] ?? '', array( 'thumbnail', 'medium', 'large', 'full' ), true ) ? $field['image_size'] : 'large';

		// --- Round 3 Batch B: flexible_content settings ---
		$layouts      = array();
		$button_label = '';
		if ( 'flexible_content' === $type ) {
			$layouts = $this->sanitize_layouts( $field['layouts'] ?? array(), $name );
			if ( is_wp_error( $layouts ) ) {
				return $layouts;
			}
			$button_label = isset( $field['button_label'] ) ? sanitize_text_field( (string) $field['button_label'] ) : '';
		}

		// --- Round 3 Batch B: clone settings ---
		// v1 references the source group by numeric ID only. The source
		// must exist; nested clones (a clone inside the source group) are
		// skipped at expansion time, so cycles are impossible.
		$clone_source = 0;
		if ( 'clone' === $type ) {
			$clone_source = absint( $field['clone'] ?? 0 );
			if ( $clone_source <= 0 || null === $this->get( $clone_source ) ) {
				/* translators: %s: field name */
				return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": clone needs an existing source group ID.', 'tk-fields' ), $name ) );
			}
		}

		// --- v0.11.0: group settings ---
		$sub_fields = array();
		if ( 'group' === $type ) {
			$sub_fields = $this->sanitize_group_sub_fields( $field['sub_fields'] ?? array(), $name, $repeater_depth );
			if ( is_wp_error( $sub_fields ) ) {
				return $sub_fields;
			}
		}

		// --- v0.20.0: repeater settings ---
		// v1: nested repeaters capped at 2 levels; clone/flexible_content
		// and layout-only types excluded outright (hard errors in
		// sanitize_repeater_sub_fields()). min/max are ROW counts: a
		// min > max combination is a definition error, rejected here.
		$repeater_layout    = 'list';
		$repeater_collapsed = '';
		if ( 'repeater' === $type ) {
			$sub_fields = $this->sanitize_repeater_sub_fields( $field['sub_fields'] ?? array(), $name, $repeater_depth + 1 );
			if ( is_wp_error( $sub_fields ) ) {
				return $sub_fields;
			}
			$repeater_layout    = in_array( $field['layout'] ?? '', array( 'list', 'grid' ), true ) ? $field['layout'] : 'list';
			$repeater_collapsed = isset( $field['collapsed'] ) ? sanitize_key( (string) $field['collapsed'] ) : '';
			$button_label       = isset( $field['button_label'] ) ? sanitize_text_field( (string) $field['button_label'] ) : '';
		}

		$logic = $field['conditional_logic'] ?? array();
		if ( ! is_array( $logic ) ) {
			/* translators: %s: field name */
			return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": conditional_logic must be an array.', 'tk-fields' ), $name ) );
		}
		$clean_logic = array();
		foreach ( $logic as $cond ) {
			if ( ! is_array( $cond ) ) {
				/* translators: %s: field name */
				return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": conditional logic entries must be objects.', 'tk-fields' ), $name ) );
			}
			$cfield = isset( $cond['field'] ) ? (string) $cond['field'] : '';
			if ( ! preg_match( '/^f_[A-Za-z0-9_]{1,64}$/', $cfield ) ) {
				/* translators: %s: field name */
				return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": bad conditional field key.', 'tk-fields' ), $name ) );
			}
			$coperator = $cond['operator'] ?? '==';
			// v0.20.2 renamed the builder's "is not empty" operator from
			// 'not_empty' to '!empty'; translate legacy saved rules forward
			// so groups last saved by 0.20.1 still validate.
			if ( 'not_empty' === $coperator ) {
				$coperator = '!empty';
			}
			if ( ! in_array( $coperator, self::OPERATORS, true ) ) {
				/* translators: %s: field name */
				return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": unknown conditional operator.', 'tk-fields' ), $name ) );
			}
			$clean_logic[] = array(
				'field'    => $cfield,
				'operator' => $coperator,
				'value'    => isset( $cond['value'] ) ? sanitize_text_field( (string) $cond['value'] ) : '',
			);
		}

		$min = $this->sanitize_nullable_number( $field['min'] ?? null );
		$max = $this->sanitize_nullable_number( $field['max'] ?? null );
		if ( 'repeater' === $type && null !== $min && null !== $max && $min > $max ) {
			/* translators: %s: field name */
			return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": min rows cannot be greater than max rows.', 'tk-fields' ), $name ) );
		}

		return array(
			'key'               => $key,
			'name'              => $name,
			'label'             => $label,
			'type'              => $type,
			// Layout-only types carry no value, so "required" is meaningless:
			// always stored false.
			'required'          => Field_Registry::stores( $type ) ? ! empty( $field['required'] ) : false,
			'default'           => self::sanitize_default( $type, $field ),
			'placeholder'       => isset( $field['placeholder'] ) ? sanitize_text_field( (string) $field['placeholder'] ) : '',
			'instructions'      => isset( $field['instructions'] ) ? wp_kses_post( (string) $field['instructions'] ) : '',
			// Static admin text for the message layout type. kses-filtered:
			// messages render in wp-admin only, basic markup allowed.
			'message'           => 'message' === $type && isset( $field['message'] ) ? wp_kses_post( (string) $field['message'] ) : '',
			'choices'           => $clean_choices,
			'conditional_logic' => $clean_logic,
			// Repeater min/max are ROW counts, enforced at save (not just
			// UI) by Field_Registry::validate_repeater().
			'min'               => $min,
			'max'               => $max,
			'step'              => $this->sanitize_nullable_number( $field['step'] ?? null ),
			'maxlength'         => isset( $field['maxlength'] ) && '' !== $field['maxlength'] ? absint( $field['maxlength'] ) : null,
			// Round 3 Batch A relational settings (see above for validation).
			'post_types'        => $post_types,
			'allow_external'    => ! empty( $field['allow_external'] ),
			'taxonomy'          => $taxonomy,
			'field_type'        => $field_type,
			'save_terms'        => $save_terms,
			'load_terms'        => $load_terms,
			'roles'             => $roles,
			'multiple'          => ! empty( $field['multiple'] ),
			'filter_taxonomy'   => $filter_taxonomy,
			'filter_term'       => isset( $field['filter_term'] ) ? sanitize_text_field( (string) $field['filter_term'] ) : '',
			'mime_types'        => isset( $field['mime_types'] ) && '' !== (string) $field['mime_types'] ? sanitize_text_field( (string) $field['mime_types'] ) : 'image',
			'image_size'        => $image_size,
			// Round 3 Batch B content settings (wysiwyg).
			'toolbar'           => in_array( $field['toolbar'] ?? '', Field_Registry::WYSIWYG_TOOLBAR_PRESETS, true ) ? $field['toolbar'] : 'full',
			'allow_unfiltered'  => ! empty( $field['allow_unfiltered'] ),
			// Round 3 Batch B map settings: the opt-in Photon search toggle
			// and the editor canvas defaults. Kept on the definition so
			// load_group_fields() can carry them (see Field_Registry::
			// map_settings()) — without these keys the builder toggle could
			// never persist.
			'enable_search'     => ! empty( $field['enable_search'] ),
			'default_lat'       => $this->sanitize_nullable_number( $field['default_lat'] ?? null ),
			'default_lng'       => $this->sanitize_nullable_number( $field['default_lng'] ?? null ),
			'default_zoom'      => isset( $field['default_zoom'] ) && '' !== $field['default_zoom'] && is_numeric( $field['default_zoom'] )
				? (int) $field['default_zoom']
				: null,
			// Round 3 Batch B: flexible_content settings. Strict-sanitized
			// by sanitize_layouts() (fail-closed with WP_Error).
			'layouts'           => $layouts,
			'button_label'      => $button_label,
			// Round 3 Batch B: clone source group ID (0 when not a clone).
			'clone'             => $clone_source,
			// v0.11.0: group inline sub-fields (array() when not a group).
			// Strict-sanitized by sanitize_group_sub_fields() (fail-closed
			// with WP_Error).
			'sub_fields'        => $sub_fields,
			// v0.20.0: repeater settings — layout (list|grid), collapsed
			// (summary sub-field; presentation state only), button_label
			// (shared key, also used by flexible_content), sub_fields
			// (strict-sanitized by sanitize_repeater_sub_fields()).
			'layout'            => $repeater_layout,
			'collapsed'         => $repeater_collapsed,
		);
	}

	/**
	 * Sanitize a field's default value following the type's save-time
	 * policy. Wysiwyg defaults are HTML: wp_kses_post (or raw under the
	 * allow_unfiltered opt-out) instead of sanitize_text_field, which would
	 * strip the tags.
	 *
	 * @param string $type  Field type.
	 * @param array  $field Raw field array.
	 */
	private static function sanitize_default( string $type, array $field ): string {
		if ( ! isset( $field['default'] ) ) {
			return '';
		}
		if ( 'wysiwyg' === $type ) {
			return ! empty( $field['allow_unfiltered'] )
				? (string) $field['default']
				: wp_kses_post( (string) $field['default'] );
		}

		return sanitize_text_field( (string) $field['default'] );
	}

	/**
	 * @param mixed $v
	 */
	private function sanitize_nullable_number( mixed $v ): int|float|null {
		if ( null === $v || '' === $v ) {
			return null;
		}
		if ( ! is_numeric( $v ) ) {
			return null;
		}
		$num = $v + 0;
		return is_float( $num ) ? $num : (int) $num;
	}

	/**
	 * Strict-sanitize the layouts of a flexible_content field (fail-closed:
	 * returns WP_Error with a specific message on the first problem).
	 *
	 * v1 layout rules:
	 * - each layout needs a unique key (a-z0-9_) and a non-empty label;
	 * - each layout needs at least one sub-field with a unique name;
	 * - nested flexible_content / clone sub-fields are rejected (v1);
	 * - layout-only types (message/separator/tab) are rejected inside
	 *   layouts (they render no value and the layout editor cannot show
	 *   them);
	 * - sub-fields are sanitized through sanitize_field() recursively, so
	 *   they get the same strict validation as top-level fields; their
	 *   conditional_logic is stripped (v1: no conditional logic inside
	 *   layouts) and missing keys are filled with a deterministic
	 *   layout-scoped key ('f_' . md5('tk_layout:'.$layout_key.':'.$name)).
	 *
	 * @param mixed  $layouts    Raw layouts value.
	 * @param string $field_name Parent field name (for error messages).
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private function sanitize_layouts( mixed $layouts, string $field_name ): array|\WP_Error {
		if ( ! is_array( $layouts ) ) {
			/* translators: %s: field name */
			return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": layouts must be an array.', 'tk-fields' ), $field_name ) );
		}

		$clean = array();
		$seen  = array();

		foreach ( $layouts as $i => $layout ) {
			if ( ! is_array( $layout ) ) {
				/* translators: 1: field name, 2: layout number */
				return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": layout %2$d must be an object.', 'tk-fields' ), $field_name, (int) $i ) );
			}
			$key = isset( $layout['key'] ) ? (string) $layout['key'] : '';
			if ( ! preg_match( '/^[a-z0-9_]+$/', $key ) ) {
				/* translators: 1: field name, 2: layout number */
				return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": layout %2$d needs a key (lowercase letters, digits, underscores).', 'tk-fields' ), $field_name, (int) $i ) );
			}
			if ( isset( $seen[ $key ] ) ) {
				/* translators: 1: field name, 2: layout key */
				return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": duplicate layout key "%2$s".', 'tk-fields' ), $field_name, $key ) );
			}
			$seen[ $key ] = true;

			$label = isset( $layout['label'] ) ? sanitize_text_field( (string) $layout['label'] ) : '';
			if ( '' === $label ) {
				/* translators: 1: field name, 2: layout key */
				return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": layout "%2$s" needs a label.', 'tk-fields' ), $field_name, $key ) );
			}

			$sub_fields = $layout['fields'] ?? array();
			if ( ! is_array( $sub_fields ) || array() === $sub_fields ) {
				/* translators: 1: field name, 2: layout key */
				return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": layout "%2$s" needs at least one sub-field.', 'tk-fields' ), $field_name, $key ) );
			}

			$clean_fields = array();
			$seen_names   = array();
			foreach ( $sub_fields as $j => $sub ) {
				if ( ! is_array( $sub ) ) {
					/* translators: 1: field name, 2: layout key, 3: sub-field number */
					return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": layout "%2$s" sub-field %3$d must be an object.', 'tk-fields' ), $field_name, $key, (int) $j ) );
				}
				$sub_name = isset( $sub['name'] ) ? (string) $sub['name'] : '';
				if ( ! preg_match( '/^[a-z0-9_]+$/', $sub_name ) ) {
					/* translators: 1: field name, 2: layout key, 3: sub-field number */
					return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": layout "%2$s" sub-field %3$d needs a valid name.', 'tk-fields' ), $field_name, $key, (int) $j ) );
				}
				if ( isset( $seen_names[ $sub_name ] ) ) {
					/* translators: 1: field name, 2: layout key, 3: sub-field name */
					return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": layout "%2$s" has a duplicate sub-field name "%3$s".', 'tk-fields' ), $field_name, $key, $sub_name ) );
				}
				$seen_names[ $sub_name ] = true;

				$sub_type = (string) ( $sub['type'] ?? 'text' );
				if ( in_array( $sub_type, array( 'flexible_content', 'clone' ), true ) ) {
					/* translators: 1: field name, 2: layout key */
					return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": layout "%2$s" — nested flexible/clone sub-fields are not supported in v1.', 'tk-fields' ), $field_name, $key ) );
				}
				if ( ! Field_Registry::stores( $sub_type ) ) {
					/* translators: 1: field name, 2: layout key */
					return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": layout "%2$s" — layout-only types are not supported as sub-fields in v1.', 'tk-fields' ), $field_name, $key ) );
				}

				// Deterministic layout-scoped key when none is supplied, so
				// keys are stable across group saves.
				if ( empty( $sub['key'] ) || ! is_string( $sub['key'] ) || ! preg_match( '/^f_[A-Za-z0-9_]{1,64}$/', $sub['key'] ) ) {
					$sub['key'] = 'f_' . substr( md5( 'tk_layout:' . $key . ':' . $sub_name ), 0, 20 );
				}
				// v1: no conditional logic inside layouts.
				$sub['conditional_logic'] = array();

				$clean_sub = $this->sanitize_field( $sub );
				if ( is_wp_error( $clean_sub ) ) {
					return new \WP_Error(
						'field',
						sprintf(
							/* translators: %1$s: field name, %2$s: layout key, %3$s: sub-field error. */
							__( 'Field "%1$s": layout "%2$s": %3$s', 'tk-fields' ),
							$field_name,
							$key,
							$clean_sub->get_error_message()
						)
					);
				}
				$clean_fields[] = $clean_sub;
			}

			$clean[] = array(
				'key'    => $key,
				'label'  => $label,
				'min'    => $this->sanitize_nullable_number( $layout['min'] ?? null ),
				'max'    => $this->sanitize_nullable_number( $layout['max'] ?? null ),
				'fields' => $clean_fields,
			);
		}

		return $clean;
	}

	/**
	 * Sanitize a repeater field's inline sub-fields.
	 *
	 * Mirrors the sub-field loop in sanitize_group_sub_fields(): names must
	 * be valid and unique; each sub-field is strict-sanitized via
	 * sanitize_field() so per-type settings are validated exactly as for
	 * top-level fields; keys are deterministic
	 * (md5('tk_repeater:' . repeater . ':' . sub)) so re-saves are stable;
	 * conditional logic is forced off inside repeaters in v1.
	 *
	 * v1 container rules (hard errors, never silent):
	 * - clone/flexible_content are excluded outright (Q3) — they never
	 *   enter the depth counter;
	 * - nested repeaters are allowed only while $depth < 2, capping
	 *   nesting at 2 levels (Q2);
	 * - group sub-fields are allowed and recurse through
	 *   sanitize_group_sub_fields() with the depth threaded through (a
	 *   repeater inside a group still counts toward the 2-level cap);
	 * - layout-only types are rejected (they carry no value).
	 *
	 * @param mixed  $sub_fields Raw sub_fields value.
	 * @param string $field_name Parent repeater field name (for errors).
	 * @param int    $depth      The repeater's own nesting depth
	 *                           (1 = the top-level repeater).
	 * @return array|\WP_Error Clean sub-fields, or WP_Error (fail closed).
	 */
	private function sanitize_repeater_sub_fields( mixed $sub_fields, string $field_name, int $depth ): array|\WP_Error {
		if ( ! is_array( $sub_fields ) ) {
			/* translators: %s: field name */
			return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": sub-fields must be an array.', 'tk-fields' ), $field_name ) );
		}

		$clean      = array();
		$seen_names = array();

		foreach ( $sub_fields as $j => $sub ) {
			if ( ! is_array( $sub ) ) {
				/* translators: 1: field name, 2: sub-field number */
				return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": sub-field %2$d must be an object.', 'tk-fields' ), $field_name, (int) $j ) );
			}
			$sub_name = isset( $sub['name'] ) ? (string) $sub['name'] : '';
			if ( ! preg_match( '/^[a-z0-9_]+$/', $sub_name ) ) {
				/* translators: 1: field name, 2: sub-field number */
				return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": sub-field %2$d needs a valid name (lowercase letters, digits, underscores).', 'tk-fields' ), $field_name, (int) $j ) );
			}
			if ( isset( $seen_names[ $sub_name ] ) ) {
				/* translators: 1: field name, 2: sub-field name */
				return new \WP_Error( 'field', sprintf( __( 'Field "%1$s" has a duplicate sub-field name "%2$s".', 'tk-fields' ), $field_name, $sub_name ) );
			}
			$seen_names[ $sub_name ] = true;

			$sub_type = (string) ( $sub['type'] ?? 'text' );
			if ( in_array( $sub_type, array( 'clone', 'flexible_content' ), true ) ) {
				/* translators: %s: field name */
				return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": clone/flexible sub-fields are not supported inside repeaters in v1.', 'tk-fields' ), $field_name ) );
			}
			if ( 'repeater' === $sub_type && $depth >= 2 ) {
				/* translators: %s: field name */
				return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": repeater nesting is limited to 2 levels in v1.', 'tk-fields' ), $field_name ) );
			}
			if ( 'repeater' !== $sub_type && 'group' !== $sub_type && ! Field_Registry::stores( $sub_type ) ) {
				/* translators: %s: field name */
				return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": layout-only types are not supported as sub-fields in v1.', 'tk-fields' ), $field_name ) );
			}

			// Deterministic sub-field key when none is supplied, so keys
			// are stable across group saves.
			if ( empty( $sub['key'] ) || ! is_string( $sub['key'] ) || ! preg_match( '/^f_[A-Za-z0-9_]{1,64}$/', $sub['key'] ) ) {
				$sub['key'] = 'f_' . substr( md5( 'tk_repeater:' . $field_name . ':' . $sub_name ), 0, 20 );
			}
			// v1: no conditional logic inside repeaters.
			$sub['conditional_logic'] = array();

			// The depth is threaded through unchanged for scalar and group
			// subs; the repeater branch of sanitize_field() adds one for
			// the nested repeater's own children.
			$clean_sub = $this->sanitize_field( $sub, $depth );
			if ( is_wp_error( $clean_sub ) ) {
				return new \WP_Error(
					'field',
					sprintf(
						/* translators: %1$s: field name, %2$s: sub-field error. */
						__( 'Field "%1$s": %2$s', 'tk-fields' ),
						$field_name,
						$clean_sub->get_error_message()
					)
				);
			}
			$clean[] = $clean_sub;
		}

		return $clean;
	}

	/**
	 * Sanitize a group field's inline sub-fields.
	 *
	 * Mirrors the sub-field loop in sanitize_layouts(): names must be valid
	 * and unique within the group; nested containers (group/clone/
	 * flexible_content) and layout-only types are rejected in v1; each
	 * sub-field is strict-sanitized via sanitize_field() so per-type
	 * settings (choices, min/max, post_types, ...) are validated exactly as
	 * for top-level fields. Keys are deterministic
	 * (md5('tk_group:' . group . ':' . sub)) so re-saves are stable.
	 * Conditional logic is forced off inside groups in v1.
	 *
	 * @param mixed  $sub_fields     Raw sub_fields value.
	 * @param string $field_name     Parent group field name (for errors).
	 * @param int    $repeater_depth How many repeaters enclose this group
	 *                               (0 = top level); a repeater sub-field is
	 *                               allowed only while the depth stays
	 *                               under the 2-level cap.
	 * @return array|\WP_Error Clean sub-fields, or WP_Error (fail closed).
	 */
	private function sanitize_group_sub_fields( mixed $sub_fields, string $field_name, int $repeater_depth = 0 ): array|\WP_Error {
		if ( ! is_array( $sub_fields ) ) {
			/* translators: %s: field name */
			return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": sub-fields must be an array.', 'tk-fields' ), $field_name ) );
		}

		$clean      = array();
		$seen_names = array();

		foreach ( $sub_fields as $j => $sub ) {
			if ( ! is_array( $sub ) ) {
				/* translators: 1: field name, 2: sub-field number */
				return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": sub-field %2$d must be an object.', 'tk-fields' ), $field_name, (int) $j ) );
			}
			$sub_name = isset( $sub['name'] ) ? (string) $sub['name'] : '';
			if ( ! preg_match( '/^[a-z0-9_]+$/', $sub_name ) ) {
				/* translators: 1: field name, 2: sub-field number */
				return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": sub-field %2$d needs a valid name (lowercase letters, digits, underscores).', 'tk-fields' ), $field_name, (int) $j ) );
			}
			if ( isset( $seen_names[ $sub_name ] ) ) {
				/* translators: 1: field name, 2: sub-field name */
				return new \WP_Error( 'field', sprintf( __( 'Field "%1$s" has a duplicate sub-field name "%2$s".', 'tk-fields' ), $field_name, $sub_name ) );
			}
			$seen_names[ $sub_name ] = true;

			$sub_type = (string) ( $sub['type'] ?? 'text' );
			if ( 'repeater' === $sub_type ) {
				// A repeater inside a group still counts toward the 2-level
				// nesting cap: allowed only while the enclosing depth is
				// under 2.
				if ( $repeater_depth >= 2 ) {
					/* translators: %s: field name */
					return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": repeater nesting is limited to 2 levels in v1.', 'tk-fields' ), $field_name ) );
				}
			} elseif ( Field_Registry::is_container( $sub_type ) || in_array( $sub_type, array( 'clone', 'flexible_content' ), true ) ) {
				/* translators: %s: field name */
				return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": nested group/clone/flexible sub-fields are not supported in v1.', 'tk-fields' ), $field_name ) );
			} elseif ( ! Field_Registry::stores( $sub_type ) ) {
				/* translators: %s: field name */
				return new \WP_Error( 'field', sprintf( __( 'Field "%1$s": layout-only types are not supported as sub-fields in v1.', 'tk-fields' ), $field_name ) );
			}

			// Deterministic sub-field key when none is supplied, so keys
			// are stable across group saves.
			if ( empty( $sub['key'] ) || ! is_string( $sub['key'] ) || ! preg_match( '/^f_[A-Za-z0-9_]{1,64}$/', $sub['key'] ) ) {
				$sub['key'] = 'f_' . substr( md5( 'tk_group:' . $field_name . ':' . $sub_name ), 0, 20 );
			}
			// v1: no conditional logic inside groups.
			$sub['conditional_logic'] = array();

			$clean_sub = $this->sanitize_field( $sub, $repeater_depth );
			if ( is_wp_error( $clean_sub ) ) {
				return new \WP_Error(
					'field',
					sprintf(
						/* translators: %1$s: field name, %2$s: sub-field error. */
						__( 'Field "%1$s": %2$s', 'tk-fields' ),
						$field_name,
						$clean_sub->get_error_message()
					)
				);
			}
			$clean[] = $clean_sub;
		}

		return $clean;
	}

	/**
	 * Sanitize the presentation block.
	 *
	 * @param mixed $presentation Raw presentation array.
	 * @return array|WP_Error
	 */
	private function sanitize_presentation( mixed $presentation ): array|\WP_Error {
		if ( ! is_array( $presentation ) ) {
			$presentation = array();
		}

		$position = $presentation['position'] ?? 'normal';
		if ( ! in_array( $position, array( 'normal', 'side', 'high' ), true ) ) {
			return new \WP_Error( 'tk_fields_group_invalid_presentation', __( 'presentation.position must be "normal", "side" or "high".', 'tk-fields' ), array( 'status' => 400 ) );
		}

		$style = $presentation['style'] ?? 'default';
		if ( ! in_array( $style, array( 'default', 'seamless' ), true ) ) {
			return new \WP_Error( 'tk_fields_group_invalid_presentation', __( 'presentation.style must be "default" or "seamless".', 'tk-fields' ), array( 'status' => 400 ) );
		}

		$label_placement = $presentation['label_placement'] ?? 'top';
		if ( ! in_array( $label_placement, array( 'top', 'left' ), true ) ) {
			return new \WP_Error( 'tk_fields_group_invalid_presentation', __( 'presentation.label_placement must be "top" or "left".', 'tk-fields' ), array( 'status' => 400 ) );
		}

		$instruction_placement = $presentation['instruction_placement'] ?? 'label';
		if ( ! in_array( $instruction_placement, array( 'label', 'field' ), true ) ) {
			return new \WP_Error( 'tk_fields_group_invalid_presentation', __( 'presentation.instruction_placement must be "label" or "field".', 'tk-fields' ), array( 'status' => 400 ) );
		}

		return array(
			'position'              => $position,
			'style'                 => $style,
			'label_placement'       => $label_placement,
			'instruction_placement' => $instruction_placement,
		);
	}

	/**
	 * Create a group from a raw payload.
	 *
	 * @param array $payload Raw group payload.
	 * @return array|WP_Error Full group array (id/title/status included), or WP_Error.
	 */
	public function create( array $payload ): array|\WP_Error {
		$clean = $this->sanitize_group( $payload );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$title = $clean['_title'];
		unset( $clean['_title'] );

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_title'  => $title,
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$json = wp_json_encode( $clean );
		if ( false === $json ) {
			wp_delete_post( $post_id, true );
			return new \WP_Error( 'tk_fields_group_encode_failed', __( 'Could not encode the group definition.', 'tk-fields' ), array( 'status' => 500 ) );
		}
		update_post_meta( $post_id, self::META_KEY, $json );

		return $this->get( $post_id );
	}

	/**
	 * Replace a group's definition from a raw payload.
	 *
	 * @param int   $id      Group post ID.
	 * @param array $payload Raw group payload.
	 * @return array|WP_Error Full group array, or WP_Error.
	 */
	public function update( int $id, array $payload ): array|\WP_Error {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || self::CPT !== $post->post_type ) {
			return new \WP_Error( 'tk_fields_group_not_found', __( 'Field group not found.', 'tk-fields' ), array( 'status' => 404 ) );
		}

		$clean = $this->sanitize_group( $payload );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$title = $clean['_title'];
		unset( $clean['_title'] );

		$updated = wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => $title,
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$json = wp_json_encode( $clean );
		if ( false === $json ) {
			return new \WP_Error( 'tk_fields_group_encode_failed', __( 'Could not encode the group definition.', 'tk-fields' ), array( 'status' => 500 ) );
		}
		update_post_meta( $id, self::META_KEY, $json );

		return $this->get( $id );
	}

	/**
	 * Trash a group (never hard-deletes via this path).
	 *
	 * @param int $id Group post ID.
	 * @return bool|WP_Error True on success, WP_Error when missing.
	 */
	public function trash( int $id ): bool|\WP_Error {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || self::CPT !== $post->post_type ) {
			return new \WP_Error( 'tk_fields_group_not_found', __( 'Field group not found.', 'tk-fields' ), array( 'status' => 404 ) );
		}

		if ( 'trash' !== $post->post_status ) {
			$trashed = wp_trash_post( $id );
			if ( ! $trashed ) {
				return new \WP_Error( 'tk_fields_group_trash_failed', __( 'Could not trash the field group.', 'tk-fields' ), array( 'status' => 500 ) );
			}
		}

		return true;
	}

	/**
	 * Get one group by ID: full definition plus id/title/status/modified.
	 *
	 * @param int $id Group post ID.
	 */
	public function get( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || self::CPT !== $post->post_type || 'trash' === $post->post_status ) {
			return null;
		}

		$def = $this->read_definition( $post );
		if ( null === $def ) {
			return null;
		}

		return array_merge(
			array(
				'id'       => $post->ID,
				'title'    => $post->post_title,
				'status'   => $post->post_status,
				'modified' => get_post_modified_time( 'c', false, $post ),
			),
			$def
		);
	}

	/**
	 * All non-trashed groups, full definitions. Ordered by ID ascending.
	 *
	 * @return array<int, array>
	 */
	public function all(): array {
		if ( ! post_type_exists( self::CPT ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		$groups = array();
		foreach ( $posts as $post ) {
			$def = $this->read_definition( $post );
			if ( null === $def ) {
				continue;
			}
			$groups[] = array_merge(
				array(
					'id'       => $post->ID,
					'title'    => $post->post_title,
					'status'   => $post->post_status,
					'modified' => get_post_modified_time( 'c', false, $post ),
				),
				$def
			);
		}

		return $groups;
	}

	/**
	 * Read the stored JSON definition off a group post.
	 *
	 * @param \WP_Post $post Group post.
	 */
	private function read_definition( \WP_Post $post ): ?array {
		$raw = get_post_meta( $post->ID, self::META_KEY, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$def = json_decode( $raw, true );
		if ( ! is_array( $def ) ) {
			return null;
		}

		// Backfill defaults so older definitions keep working.
		$def['location']         = $def['location'] ?? array();
		$def['location_match']   = $def['location_match'] ?? 'all';
		$def['fields']           = $def['fields'] ?? array();
		$def['presentation']     = $def['presentation'] ?? array();
		$def['exclude_from_ai']  = ! empty( $def['exclude_from_ai'] );

		return $def;
	}
}
