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
 * Fields service — the single resolution path for every field value.
 *
 * All integrations (PHP API, Block Bindings, Elementor, REST, AI) read and
 * write through here. The service owns:
 *
 * - per-request memoization (read-your-writes within a request),
 * - batched writes (committed on shutdown via Fields::flush()),
 * - routing to storage adapters by object type,
 * - sanitize/validate/format through the field registry.
 *
 * Repeaters and flexible content never touch this storage on posts: they
 * live as blocks in post_content (the block editor owns those writes).
 * On non-post objects (terms, users, options) flexible rows are kept as
 * serialized block markup in a single meta/option row. Fields::rows() is
 * the repeater template loop; flexible rows have their own helpers on
 * the Flexible_Content class.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Fields {

	/**
	 * @var array<string, array<int, array<string, mixed>>> Memoized reads: [type][id][key].
	 */
	private static array $memo = array();

	/**
	 * @var array<string, array<int, array<string, mixed>>> Queued writes: [type][id][key].
	 */
	private static array $pending = array();

	/**
	 * @var array<string, Storage_Adapter>|null
	 */
	private static ?array $adapters = null;

	/**
	 * Storage adapters keyed by object type. Filterable for later phases.
	 *
	 * @return array<string, Storage_Adapter>
	 */
	private static function adapters(): array {
		if ( null === self::$adapters ) {
			/**
			 * Filter the storage adapters keyed by object type.
			 *
			 * @param array<string, Storage_Adapter> $adapters
			 */
			self::$adapters = apply_filters(
				'tk_fields_storage_adapters',
				array(
					'post'   => new Storage_Postmeta(),
					'term'   => new Storage_Termmeta(),
					'user'   => new Storage_Usermeta(),
					'option' => new Storage_Options(),
				)
			);
		}

		return self::$adapters;
	}

	/**
	 * Resolve an object reference to [object_type, object_id].
	 *
	 * $object may be a post ID, a WP_Post, a WP_Term, a WP_User, the string
	 * 'option' (site-wide option fields), or null (current post in the Loop).
	 *
	 * @param int|\WP_Post|\WP_Term|\WP_User|string|null $object
	 * @return array{0: string, 1: int}
	 */
	private static function resolve_object( int|\WP_Post|\WP_Term|\WP_User|string|null $object ): array {
		if ( $object instanceof \WP_Term ) {
			return array( 'term', (int) $object->term_id );
		}

		if ( $object instanceof \WP_User ) {
			return array( 'user', (int) $object->ID );
		}

		if ( is_string( $object ) && 'option' === $object ) {
			return array( 'option', 0 );
		}

		if ( $object instanceof \WP_Post ) {
			$object = $object->ID;
		}

		if ( null === $object ) {
			$object = get_the_ID();
		}

		return array( 'post', absint( $object ) );
	}

	/**
	 * Read a field value.
	 *
	 * @param string                                     $selector Field name.
	 * @param int|\WP_Post|\WP_Term|\WP_User|string|null $object   Post ID, WP_Post, WP_Term, WP_User, 'option', or null for the current post.
	 * @param bool                                       $format   Apply the field type's display formatting.
	 * @return mixed The value, or Unset_Value::get() when nothing is stored.
	 */
	public static function get( string $selector, int|\WP_Post|\WP_Term|\WP_User|string|null $object = null, bool $format = true ): mixed {
		$field = Field_Registry::instance()->get( $selector );
		if ( null === $field ) {
			return Unset_Value::get();
		}

		// Group: container with valued children — the group key stores
		// nothing; reads fan in over the namespaced sub-fields. Handled
		// before the stores() check (stores('group') is false).
		if ( 'group' === $field['type'] ) {
			return self::get_group( $field, $object, $format );
		}

		// Layout-only types (message/separator/tab) store nothing: always
		// unset, without touching storage.
		if ( ! Field_Registry::stores( $field['type'] ) ) {
			return Unset_Value::get();
		}

		[ $type, $id ] = self::resolve_object( $object );

		// Flexible content: block-backed rows on posts; serialized block
		// markup in a single meta/option row on other object types.
		if ( 'flexible_content' === $field['type'] ) {
			return self::get_flexible( $field, $selector, $type, $id, $format );
		}

		// Repeater: block-backed rows on posts (tk/field-repeater wrapper);
		// serialized block markup in a single meta/option row on other
		// object types. Nested to 2 levels, row-id identity, path-stack
		// context.
		if ( 'repeater' === $field['type'] ) {
			return self::get_repeater( $field, $selector, $type, $id, $format );
		}

		// Clone: the parent stores nothing; reads fan in over the resolved,
		// namespaced children, keyed by short child name.
		if ( 'clone' === $field['type'] ) {
			return self::get_clone( $field, $object, $format );
		}

		if ( array_key_exists( $selector, self::$memo[ $type ][ $id ] ?? array() ) ) {
			$value = self::$memo[ $type ][ $id ][ $selector ];
		} else {
			$adapter = self::adapters()[ $type ] ?? null;
			$value   = $adapter ? $adapter->get( $id, $type, $selector ) : Unset_Value::get();

			if ( ! Unset_Value::is_unset( $value ) ) {
				// Postmeta stores everything as strings; re-canonicalize to the
				// field's typed form so reads always return typed values.
				$value = Field_Registry::instance()->sanitize( $field, $value );
			} elseif ( 'taxonomy' === $field['type']
				&& ! empty( $field['load_terms'] )
				&& ! empty( $field['taxonomy'] )
			) {
				// Load Terms fallback: nothing is stored for this field, so
				// the post's own terms become the value (typed as an ID list).
				// Only ever fills an unset read — a stored value always wins.
				$terms = wp_get_post_terms( $id, $field['taxonomy'], array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					$value = array_map( 'absint', $terms );
				}
			}

			self::$memo[ $type ][ $id ][ $selector ] = $value;
		}

		if ( $format && ! Unset_Value::is_unset( $value ) ) {
			$value = Field_Registry::instance()->format( $field, $value, $id );
		}

		return $value;
	}

	/**
	 * Read a flexible_content field.
	 *
	 * Posts: parse the tk/flexible-content block in post_content (the block
	 * editor owns those writes). Other object types: read the serialized
	 * block markup from the single meta/option row. Parsed rows are
	 * re-sanitized so reads always return typed values. No rows stored
	 * (no block / no markup) reads as unset — consistent with "nothing
	 * stored" everywhere else.
	 *
	 * @param array  $field    Field definition.
	 * @param string $selector Field name.
	 * @param string $type     Object type ('post'|'term'|'user'|'option').
	 * @param int    $id       Object ID (0 for options).
	 * @param bool   $format   Apply the flexible display formatting.
	 */
	private static function get_flexible( array $field, string $selector, string $type, int $id, bool $format ): mixed {
		if ( array_key_exists( $selector, self::$memo[ $type ][ $id ] ?? array() ) ) {
			$rows = self::$memo[ $type ][ $id ][ $selector ];
		} else {
			if ( 'post' === $type && $id > 0 ) {
				$rows = Flexible_Content::rows_raw( $selector, $id );
			} else {
				$adapter = self::adapters()[ $type ] ?? null;
				$markup  = $adapter ? $adapter->get( $id, $type, $selector ) : Unset_Value::get();
				$rows    = Unset_Value::is_unset( $markup )
					? Unset_Value::get()
					: Flexible_Content::rows_from_markup( $selector, (string) $markup );
			}

			if ( ! Unset_Value::is_unset( $rows ) ) {
				// Re-canonicalize parsed rows so reads always return typed
				// values (meta stores everything as strings).
				$rows = Field_Registry::instance()->sanitize( $field, $rows );
			}
			if ( ! Unset_Value::is_unset( $rows ) && array() === $rows ) {
				$rows = Unset_Value::get();
			}

			self::$memo[ $type ][ $id ][ $selector ] = $rows;
		}

		if ( Unset_Value::is_unset( $rows ) ) {
			return $rows;
		}

		if ( $format ) {
			return Field_Registry::instance()->format( $field, $rows, 'post' === $type ? $id : null );
		}

		return $rows;
	}

	/**
	 * Read a clone field: fan in over the resolved, namespaced children.
	 * Returns the children keyed by SHORT child name (the clone prefix is
	 * stripped), or unset when no child has a value.
	 *
	 * @param array                                      $field  Clone field definition.
	 * @param int|\WP_Post|\WP_Term|\WP_User|string|null $object Object reference.
	 * @param bool                                       $format Format each child value.
	 */
	private static function get_clone( array $field, int|\WP_Post|\WP_Term|\WP_User|string|null $object, bool $format ): mixed {
		$children = Field_Registry::instance()->resolve_clone_children( $field );
		if ( array() === $children ) {
			return Unset_Value::get();
		}

		$values = array();
		foreach ( $children as $short => $child ) {
			$value = self::get( $child['name'], $object, $format );
			if ( ! Unset_Value::is_unset( $value ) ) {
				$values[ $short ] = $value;
			}
		}

		if ( array() === $values ) {
			return Unset_Value::get();
		}

		return $values;
	}

	/**
	 * Read a group field: fan in over the resolved, namespaced children.
	 *
	 * The group key itself stores NOTHING (zero meta rows) — each sub-field
	 * lives under its ACF-compatible namespaced key `{group}_{sub}`. The
	 * assembled value is keyed by SHORT sub-field name
	 * (['street' => '...', 'city' => '...']), each child read with the
	 * caller's $format so typed reads compose (image children become DTOs,
	 * checkbox children become bools, ...).
	 *
	 * @param array                                      $field  Group field definition.
	 * @param int|\WP_Post|\WP_Term|\WP_User|string|null $object Object reference.
	 * @param bool                                       $format Apply each child's display formatting.
	 */
	private static function get_group( array $field, int|\WP_Post|\WP_Term|\WP_User|string|null $object, bool $format ): mixed {
		$children = Field_Registry::instance()->resolve_group_children( $field );
		if ( array() === $children ) {
			return Unset_Value::get();
		}

		$values = array();
		foreach ( $children as $short => $child ) {
			$value = self::get( $child['name'], $object, $format );
			if ( ! Unset_Value::is_unset( $value ) ) {
				$values[ $short ] = $value;
			}
		}

		if ( array() === $values ) {
			return Unset_Value::get();
		}

		return $values;
	}

	/**
	 * Write a field value. Queued and committed in a batch on shutdown.
	 *
	 * @param string                                     $selector Field name.
	 * @param mixed                                      $value    Raw value; sanitized + validated per field type.
	 * @param int|\WP_Post|\WP_Term|\WP_User|string|null $object   Post ID, WP_Post, WP_Term, WP_User, 'option', or null for the current post.
	 */
	public static function update( string $selector, mixed $value, int|\WP_Post|\WP_Term|\WP_User|string|null $object = null ): bool {
		$registry = Field_Registry::instance();
		$field    = $registry->get( $selector );
		if ( null === $field ) {
			return false;
		}

		// Group: the parent stores nothing — the value fans out to the
		// resolved, namespaced children (two passes: validate all, then
		// write all). Handled before the stores() check.
		if ( 'group' === $field['type'] ) {
			return self::update_group( $field, $value, $object );
		}

		// Layout-only types (message/separator/tab) persist nothing: refuse
		// the write rather than storing a meaningless value.
		if ( ! Field_Registry::stores( $field['type'] ) ) {
			return false;
		}

		[ $type, $id ] = self::resolve_object( $object );

		// Clone: the parent stores nothing — the value fans out to the
		// resolved, namespaced children (two passes: validate all, then
		// write all).
		if ( 'clone' === $field['type'] ) {
			return self::update_clone( $field, $value, $object );
		}

		// Flexible content: posts are block-owned (the block editor writes
		// post_content — programmatic post writes are refused, not
		// half-done); other object types store one serialized-markup row.
		if ( 'flexible_content' === $field['type'] ) {
			return self::update_flexible( $field, $selector, $value, $type, $id );
		}

		// Repeater: posts are block-owned (the block editor writes
		// post_content — programmatic post writes are refused, not
		// half-done); other object types store one serialized-markup row.
		if ( 'repeater' === $field['type'] ) {
			return self::update_repeater( $field, $selector, $value, $type, $id );
		}

		$raw   = $value;
		$value = $registry->sanitize( $field, $value );

		// Sanitization destroyed a non-empty input (e.g. an invalid email or
		// URL reduced to ''): the value was invalid, not empty.
		$raw_empty = '' === $raw || null === $raw;
		if ( ! $raw_empty && ( '' === $value || null === $value ) ) {
			return false;
		}

		if ( ! $registry->validate( $field, $value ) ) {
			return false;
		}

		if ( 0 === $id && 'option' !== $type ) {
			return false;
		}

		self::$pending[ $type ][ $id ][ $selector ] = $value;
		// Read-your-writes: the memo sees the new value immediately.
		self::$memo[ $type ][ $id ][ $selector ] = $value;

		// Taxonomy term sync: when save_terms is on, the FIELD value is the
		// authority — the sanitized value overwrites the post's terms
		// (field value -> terms), never the other way round. An empty array
		// clears the post's terms: save [] to wipe terms set elsewhere.
		// load_terms only fills READS when nothing is stored (see get()).
		if ( 'taxonomy' === $field['type']
			&& ! empty( $field['save_terms'] )
			&& ! empty( $field['taxonomy'] )
			&& is_array( $value )
		) {
			wp_set_object_terms( $id, $value, $field['taxonomy'], false );
		}

		// oEmbed: warm core's _oembed_* cache now, in the save/admin
		// context, so the first frontend render is served from cache and
		// never pays for a cold provider HTTP fetch on a visitor's page
		// load. shortcode() no-ops on a warm cache, so this is cheap when
		// the value hasn't changed.
		if ( 'oembed' === $field['type'] && '' !== $value ) {
			/**
			 * Filters whether saving an oEmbed field warms the embed cache.
			 *
			 * @param bool   $warm  Whether to warm the cache. Default true.
			 * @param int    $id    Post ID being saved.
			 * @param string $value Sanitized oEmbed URL.
			 */
			if ( apply_filters( 'tk_fields_warm_oembed_cache', true, $id, $value ) ) {
				Field_Registry::oembed_html( (string) $value, $id );
			}
		}

		return true;
	}

	/**
	 * Write a clone field: fan out to the resolved, namespaced children.
	 *
	 * The parent stores NOTHING itself. $value is keyed by short child
	 * name ('street') or fully namespaced name ('billing_address.street');
	 * unknown keys are ignored. Two passes — validate everything first, so
	 * a late failure can never leave a half-written clone behind.
	 *
	 * @param array                                      $field  Clone field definition.
	 * @param mixed                                      $value  Keyed child values.
	 * @param int|\WP_Post|\WP_Term|\WP_User|string|null $object Object reference.
	 */
	private static function update_clone( array $field, mixed $value, int|\WP_Post|\WP_Term|\WP_User|string|null $object ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}

		$registry = Field_Registry::instance();
		$children = $registry->resolve_clone_children( $field );
		if ( array() === $children ) {
			return false; // Unresolvable source (missing/trashed group): fail closed.
		}

		$prefix = (string) ( $field['name'] ?? '' ) . '.';
		$writes = array();
		foreach ( $value as $key => $child_value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			$short = str_starts_with( $key, $prefix ) ? substr( $key, strlen( $prefix ) ) : $key;
			if ( isset( $children[ $short ] ) ) {
				$writes[ $short ] = $child_value;
			}
		}

		// Pass 1: sanitize + validate every child (child sanitizers and
		// validators are strictly inherited from the source field).
		foreach ( $writes as $short => $child_value ) {
			$child     = $children[ $short ];
			$san       = $registry->sanitize( $child, $child_value );
			$raw_empty = '' === $child_value || null === $child_value;
			if ( ! $raw_empty && ( '' === $san || null === $san ) ) {
				return false;
			}
			if ( ! $registry->validate( $child, $san ) ) {
				return false;
			}
		}

		// Pass 2: write. Each child goes through the normal update() path
		// (its own sanitize/validate/memo/queue), never the clone path —
		// nested clones are never expanded, so no recursion is possible.
		foreach ( $writes as $short => $child_value ) {
			if ( ! self::update( $children[ $short ]['name'], $child_value, $object ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Write a group field: fan out to the resolved, namespaced children.
	 *
	 * The parent stores NOTHING itself. $value is keyed by short sub-field
	 * name ('street') or fully namespaced name ('contact_street'); unknown
	 * keys are ignored. Two passes — validate everything first, so a late
	 * failure can never leave a half-written group behind. Each sub-field
	 * is validated with its own rules, exactly as if written directly.
	 *
	 * @param array                                      $field  Group field definition.
	 * @param mixed                                      $value  Keyed sub-field values.
	 * @param int|\WP_Post|\WP_Term|\WP_User|string|null $object Object reference.
	 */
	private static function update_group( array $field, mixed $value, int|\WP_Post|\WP_Term|\WP_User|string|null $object ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}

		$registry = Field_Registry::instance();
		$children = $registry->resolve_group_children( $field );
		if ( array() === $children ) {
			return false; // No resolvable sub-fields: fail closed.
		}

		$prefix = (string) ( $field['name'] ?? '' ) . '_';
		$writes = array();
		foreach ( $value as $key => $child_value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			$short = str_starts_with( $key, $prefix ) ? substr( $key, strlen( $prefix ) ) : $key;
			if ( isset( $children[ $short ] ) ) {
				$writes[ $short ] = $child_value;
			}
		}

		// Pass 1: sanitize + validate every child with its own rules.
		foreach ( $writes as $short => $child_value ) {
			$child     = $children[ $short ];
			$san       = $registry->sanitize( $child, $child_value );
			$raw_empty = '' === $child_value || null === $child_value;
			if ( ! $raw_empty && ( '' === $san || null === $san ) ) {
				return false;
			}
			if ( ! $registry->validate( $child, $san ) ) {
				return false;
			}
		}

		// Pass 2: write. Each child goes through the normal update() path
		// (its own sanitize/validate/memo/queue), never the group path —
		// nested groups are rejected, so no recursion is possible.
		foreach ( $writes as $short => $child_value ) {
			if ( ! self::update( $children[ $short ]['name'], $child_value, $object ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Write a flexible_content field.
	 *
	 * Posts: refused — flexible rows live as tk/flexible-content blocks in
	 * post_content and the block editor owns those writes (repeater
	 * parity); rewriting post_content programmatically is out of scope for
	 * v1. Terms, users, options: sanitize + validate (per-layout min/max
	 * enforced NOW, not just in UI), then store one serialized block-markup
	 * row. Written immediately rather than queued so read-your-writes holds
	 * without waiting for shutdown.
	 *
	 * @param array  $field    Field definition.
	 * @param string $selector Field name.
	 * @param mixed  $value    Raw rows value.
	 * @param string $type     Object type.
	 * @param int    $id       Object ID.
	 */
	private static function update_flexible( array $field, string $selector, mixed $value, string $type, int $id ): bool {
		$registry = Field_Registry::instance();

		if ( 'post' === $type ) {
			return false;
		}

		if ( 0 === $id && 'option' !== $type ) {
			return false;
		}

		$rows = $registry->sanitize( $field, $value );
		if ( ! is_array( $rows ) ) {
			return false;
		}

		$raw_empty = '' === $value || null === $value;
		if ( ! $raw_empty && ! is_array( $value ) ) {
			// Non-array garbage sanitizes to [] — that's invalid, not empty.
			return false;
		}

		if ( ! $registry->validate( $field, $rows ) ) {
			return false;
		}

		$adapter = self::adapters()[ $type ] ?? null;
		if ( ! $adapter ) {
			return false;
		}

		if ( ! $adapter->set( $id, $type, $selector, Flexible_Content::serialize_rows( $field, $rows ) ) ) {
			return false;
		}
		self::$memo[ $type ][ $id ][ $selector ] = $rows;

		return true;
	}

	/**
	 * Write a repeater field.
	 *
	 * Posts: refused — repeater rows live as tk/field-repeater blocks in
	 * post_content and the block editor owns those writes; rewriting
	 * post_content programmatically is out of scope for v1. Terms, users,
	 * options: sanitize + validate (repeater min/max ROW counts enforced
	 * NOW, not just in UI), then store one serialized block-markup row.
	 * Written immediately rather than queued so read-your-writes holds
	 * without waiting for shutdown (get_repeater() is deliberately not
	 * memoized, so the live adapter read sees it at once).
	 *
	 * @param array  $field    Field definition.
	 * @param string $selector Field name.
	 * @param mixed  $value    Raw rows value.
	 * @param string $type     Object type.
	 * @param int    $id       Object ID.
	 */
	private static function update_repeater( array $field, string $selector, mixed $value, string $type, int $id ): bool {
		$registry = Field_Registry::instance();

		if ( 'post' === $type ) {
			return false;
		}

		if ( 0 === $id && 'option' !== $type ) {
			return false;
		}

		$rows = $registry->sanitize( $field, $value );
		if ( ! is_array( $rows ) ) {
			return false;
		}

		$raw_empty = '' === $value || null === $value;
		if ( ! $raw_empty && ! is_array( $value ) ) {
			// Non-array garbage sanitizes to [] — that's invalid, not empty.
			return false;
		}

		if ( ! $registry->validate( $field, $rows ) ) {
			return false;
		}

		$adapter = self::adapters()[ $type ] ?? null;
		if ( ! $adapter ) {
			return false;
		}

		if ( ! $adapter->set( $id, $type, $selector, Repeater::serialize_rows( $field, $rows ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Read a repeater field.
	 *
	 * Posts: parse the tk/field-repeater wrapper in post_content (the block
	 * editor owns those writes). Other object types: read the serialized
	 * block markup from the single meta/option row. Parsed rows are
	 * re-sanitized so reads always return typed values. No rows stored
	 * (no wrapper / no markup) reads as unset — consistent with "nothing
	 * stored" everywhere else.
	 *
	 * Deliberately NOT memoized: every read re-parses, so a revision
	 * restore (or any post_content rewrite) is visible to the very next
	 * read in the same request. Read-your-writes still holds — non-post
	 * adapter reads are live, and update_repeater() writes immediately
	 * rather than queuing.
	 *
	 * @param array  $field    Field definition.
	 * @param string $selector Field name.
	 * @param string $type     Object type ('post'|'term'|'user'|'option').
	 * @param int    $id       Object ID (0 for options).
	 * @param bool   $format   Apply the repeater display formatting.
	 */
	private static function get_repeater( array $field, string $selector, string $type, int $id, bool $format ): mixed {
		if ( 'post' === $type && $id > 0 ) {
			$rows = Repeater::rows_raw( $field, $selector, $id );
		} else {
			$adapter = self::adapters()[ $type ] ?? null;
			$markup  = $adapter ? $adapter->get( $id, $type, $selector ) : Unset_Value::get();
			$rows    = Unset_Value::is_unset( $markup )
				? Unset_Value::get()
				: Repeater::rows_from_markup( $field, $selector, (string) $markup );
		}

		if ( ! Unset_Value::is_unset( $rows ) ) {
			// Re-canonicalize parsed rows so reads always return typed
			// values (meta stores everything as strings).
			$rows = Field_Registry::instance()->sanitize( $field, $rows );
		}
		if ( ! Unset_Value::is_unset( $rows ) && array() === $rows ) {
			$rows = Unset_Value::get();
		}

		if ( Unset_Value::is_unset( $rows ) ) {
			return $rows;
		}

		if ( $format ) {
			return Field_Registry::instance()->format( $field, $rows, 'post' === $type ? $id : null );
		}

		return $rows;
	}

	/**
	 * Delete a field value.
	 *
	 * @param string                                     $selector Field name.
	 * @param int|\WP_Post|\WP_Term|\WP_User|string|null $object   Post ID, WP_Post, WP_Term, WP_User, 'option', or null for the current post.
	 */
	public static function delete( string $selector, int|\WP_Post|\WP_Term|\WP_User|string|null $object = null ): bool {
		$field = Field_Registry::instance()->get( $selector );
		if ( null === $field ) {
			return false;
		}

		// Clone: the parent stores nothing — delete the resolved,
		// namespaced children instead.
		if ( 'clone' === $field['type'] ) {
			$ok = true;
			foreach ( Field_Registry::instance()->resolve_clone_children( $field ) as $child ) {
				$ok = self::delete( $child['name'], $object ) && $ok;
			}

			return $ok;
		}

		[ $type, $id ] = self::resolve_object( $object );
		if ( 0 === $id && 'option' !== $type ) {
			return false;
		}

		$adapter = self::adapters()[ $type ] ?? null;
		if ( ! $adapter ) {
			return false;
		}

		// Drop any queued write for the same key so the delete wins.
		unset( self::$pending[ $type ][ $id ][ $selector ] );

		$ok = $adapter->delete( $id, $type, $selector );

		unset( self::$memo[ $type ][ $id ][ $selector ] );

		return $ok;
	}

	/**
	 * All registered fields with values for an object. Unset fields are skipped.
	 *
	 * @param int|\WP_Post|\WP_Term|\WP_User|string|null $object Post ID, WP_Post, WP_Term, WP_User, 'option', or null for the current post.
	 * @return array<string, mixed> Field name => formatted value.
	 */
	public static function get_fields( int|\WP_Post|\WP_Term|\WP_User|string|null $object = null ): array {
		$values = array();

		foreach ( Field_Registry::instance()->all() as $name => $field ) {
			$value = self::get( $name, $object );
			if ( ! Unset_Value::is_unset( $value ) ) {
				$values[ $name ] = $value;
			}
		}

		return $values;
	}

	/**
	 * Repeater rows for a field, as Repeater_Row objects.
	 *
	 * Parses $post->post_content for the field's tk/field-repeater wrapper
	 * (fieldKey identity, fieldName fallback) and maps its direct
	 * tk/repeater-row inner blocks to Repeater_Row objects. Legacy
	 * tk/repeater blocks still match for BC.
	 *
	 * Nested repeaters are NOT traversed here — a nested repeater's rows
	 * resolve through the active row (Repeater_Loop, or
	 * Repeater_Row::get_nested_wrapper()).
	 *
	 * @param string            $selector Field name.
	 * @param int|\WP_Post|null $object   Post ID, WP_Post, or null for the current post.
	 * @return array<int, Repeater_Row>
	 */
	public static function rows( string $selector, int|\WP_Post|null $object = null ): array {
		[ $type, $id ] = self::resolve_object( $object );
		if ( 'post' !== $type || 0 === $id ) {
			return array();
		}

		$field = Field_Registry::instance()->get( $selector );
		if ( ! is_array( $field ) || 'repeater' !== ( $field['type'] ?? '' ) ) {
			// Not a registered repeater: V1 callers matched legacy
			// tk/repeater blocks by fieldName alone.
			return Repeater::legacy_rows( $selector, $id );
		}

		return Repeater::repeater_rows( $field, $selector, $id );
	}

	/**
	 * Commit all queued writes. Runs on shutdown; also callable directly
	 * (useful in tests and WP-CLI where shutdown timing matters).
	 */
	public static function flush(): void {
		if ( empty( self::$pending ) ) {
			return;
		}

		foreach ( self::$pending as $type => $objects ) {
			$adapter = self::adapters()[ $type ] ?? null;
			if ( ! $adapter ) {
				continue;
			}

			foreach ( $objects as $id => $fields ) {
				foreach ( $fields as $key => $value ) {
					$adapter->set( $id, $type, $key, $value );
				}
			}
		}

		self::$pending = array();
	}

	/**
	 * Drop the per-request memo cache (tests, mostly).
	 */
	public static function clear_memo(): void {
		self::$memo = array();
	}
}
