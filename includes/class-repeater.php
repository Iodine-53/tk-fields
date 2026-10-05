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
 * Repeater rows and the template loop.
 *
 * Repeaters are parent/child blocks stored as native block markup: a
 * tk/field-repeater wrapper (identified by fieldKey, with a fieldName
 * fallback) holding tk/repeater-row children in post_content — they never
 * touch postmeta on posts. Nested repeaters (a tk/field-repeater inside a
 * tk/repeater-row) are supported to 2 levels. This file owns:
 *
 * - Repeater_Row: a typed wrapper around one parsed tk/repeater-row block.
 * - Repeater: the static parse/serialize engine (posts + serialized markup
 *   for term/user/option storage), mirroring Flexible_Content.
 * - Repeater_Loop: a static-stack template engine behind the
 *   while ( tk_have_rows( 'x' ) ) { tk_the_row(); ... } idiom, with a
 *   full path stack so nested loops stay row-scoped.
 * - The "TK Fields" block category registration.
 * - The editor-side default allowed-blocks list for rows (filterable via
 *   tk_fields_repeater_allowed_blocks), injected before the row editor
 *   script.
 *
 * Load-bearing invariant: value order is presentation; the persisted row
 * id is identity; context is a path stack end-to-end (render AND save).
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One repeater row: a parsed tk/repeater-row block and its sub-fields.
 */
class Repeater_Row {

	/**
	 * @var array<string, mixed> Parsed block array (parse_blocks() shape).
	 */
	private array $block;

	public function __construct( array $block ) {
		$this->block = $block;
	}

	/**
	 * Stable row identity — the rowId attribute set by the editor, or a
	 * deterministic hash of the row content when it is missing (legacy /
	 * hand-written markup). Never the array index.
	 */
	public function get_id(): string {
		return self::row_id( $this->block['attrs'] ?? array(), $this->block );
	}

	/**
	 * Compute the row ID from attributes, with a stable content-hash fallback.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array|\WP_Block|null $block      Parsed block array or WP_Block instance (for hashing).
	 */
	public static function row_id( array $attributes, $block = null ): string {
		$row_id = isset( $attributes['rowId'] ) ? (string) $attributes['rowId'] : '';
		if ( '' !== $row_id ) {
			return $row_id;
		}

		$hashable = $block instanceof \WP_Block ? $block->parsed_block : $block;
		return 'tk-row-' . substr( md5( wp_json_encode( $hashable ) ), 0, 12 );
	}

	/**
	 * The value of one sub-field (tk/field-value with matching fieldName).
	 * Scans inner blocks depth-first in document order; first match wins.
	 * Never descends into nested tk/field-repeater (or legacy tk/repeater)
	 * subtrees — a nested repeater's values belong to its own loop, not to
	 * this row's scalar namespace. Returns the canonicalized value
	 * attribute, or null when absent.
	 *
	 * @param string $name Sub-field name.
	 * @return mixed|null
	 */
	public function get_field( string $name ) {
		foreach ( self::walk_inner_blocks( $this->block['innerBlocks'] ?? array() ) as $inner ) {
			if ( 'tk/field-value' !== ( $inner['blockName'] ?? '' ) ) {
				continue;
			}
			if ( ( $inner['attrs']['fieldName'] ?? '' ) === $name ) {
				return $inner['attrs']['value'] ?? null;
			}
		}

		return null;
	}

	/**
	 * All sub-field values in this row: field name => canonicalized value.
	 * Same scoping as get_field(): nested repeater subtrees are pruned.
	 *
	 * @return array<string, mixed>
	 */
	public function get_fields(): array {
		$fields = array();

		foreach ( self::walk_inner_blocks( $this->block['innerBlocks'] ?? array() ) as $inner ) {
			if ( 'tk/field-value' !== ( $inner['blockName'] ?? '' ) ) {
				continue;
			}
			$name = (string) ( $inner['attrs']['fieldName'] ?? '' );
			if ( '' === $name || array_key_exists( $name, $fields ) ) {
				continue;
			}
			$fields[ $name ] = $inner['attrs']['value'] ?? null;
		}

		return $fields;
	}

	/**
	 * The raw parsed inner blocks of the row.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_inner_blocks(): array {
		return $this->block['innerBlocks'] ?? array();
	}

	/**
	 * The nested tk/field-repeater wrapper for one repeater sub-field of
	 * this row, or null when the row has none.
	 *
	 * Matched by fieldKey (the sub-field's key) first, fieldName (the
	 * sub-field's name) as fallback — the same precedence the parser uses
	 * for top-level wrappers. The search does not descend into OTHER
	 * nested repeater subtrees: a wrapper belonging to a different
	 * sub-field can never be mistaken for this one.
	 *
	 * @param string $name Sub-field name.
	 * @param string $key  Sub-field key (may be '').
	 * @return array<string, mixed>|null Parsed wrapper block.
	 */
	public function get_nested_wrapper( string $name, string $key ): ?array {
		foreach ( self::walk_sibling_blocks( $this->block['innerBlocks'] ?? array() ) as $block ) {
			if ( 'tk/field-repeater' !== ( $block['blockName'] ?? '' ) ) {
				continue;
			}
			$attrs       = $block['attrs'] ?? array();
			$wrapper_key = (string) ( $attrs['fieldKey'] ?? '' );
			if ( '' !== $key && '' !== $wrapper_key ) {
				if ( $wrapper_key === $key ) {
					return $block;
				}
				continue;
			}
			if ( (string) ( $attrs['fieldName'] ?? '' ) === $name ) {
				return $block;
			}
		}

		return null;
	}

	/**
	 * Depth-first walk of parsed inner blocks (document order).
	 *
	 * Nested repeater wrappers (tk/field-repeater, legacy tk/repeater) are
	 * yielded themselves but never descended into: their inner values
	 * belong to the nested repeater's own scope, and a row's own
	 * tk/field-value scan must not see them. Safe for legacy markup —
	 * V1 repeaters never nested, so no legacy row contains a wrapper.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return \Generator<int, array<string, mixed>>
	 */
	private static function walk_inner_blocks( array $blocks ): \Generator {
		foreach ( $blocks as $block ) {
			yield $block;
			$block_name = $block['blockName'] ?? '';
			if ( 'tk/field-repeater' === $block_name || 'tk/repeater' === $block_name ) {
				continue;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				yield from self::walk_inner_blocks( $block['innerBlocks'] );
			}
		}
	}

	/**
	 * Depth-first walk that prunes nested repeater wrappers ENTIRELY
	 * (neither yielded nor descended into). Used when locating a nested
	 * wrapper for one sub-field: wrappers belonging to other sub-fields
	 * are invisible to the search.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return \Generator<int, array<string, mixed>>
	 */
	private static function walk_sibling_blocks( array $blocks ): \Generator {
		foreach ( $blocks as $block ) {
			$block_name = $block['blockName'] ?? '';
			if ( 'tk/field-repeater' === $block_name || 'tk/repeater' === $block_name ) {
				yield $block;
				continue;
			}
			yield $block;
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				yield from self::walk_sibling_blocks( $block['innerBlocks'] );
			}
		}
	}

	/**
	 * Resolve a select field's stored value to its human label from the
	 * inspector "options" text (lines of "value | Label"). Falls back to the
	 * raw value when no label is found.
	 *
	 * @param string $options_text
	 * @param string $value
	 */
	public static function select_label( string $options_text, string $value ): string {
		foreach ( preg_split( '/\r\n|\r|\n/', $options_text ) as $line ) {
			$parts  = explode( '|', $line, 2 );
			$option = trim( $parts[0] );
			if ( '' === $option ) {
				continue;
			}
			if ( $option === $value ) {
				$label = isset( $parts[1] ) ? trim( $parts[1] ) : $option;
				return '' !== $label ? $label : $option;
			}
		}

		return $value;
	}
}

/**
 * The static parse/serialize engine for repeater fields, mirroring
 * Flexible_Content.
 *
 * Posts: rows live as tk/repeater-row children of the field's
 * tk/field-repeater wrapper in post_content (the block editor owns those
 * writes). Terms, users, options: rows are serialized block markup in a
 * single meta/option row. Parsed rows are canonical:
 * [ ['id' => row id, 'fields' => [name => value]], ... ] with nested
 * repeater values recursing to the same shape and group leaves nested as
 * short-name assocs.
 */
final class Repeater {

	/**
	 * Row objects for a repeater field on a post, in document order.
	 *
	 * First matching top-level tk/field-repeater wrapper wins (repeater
	 * parity); nested wrappers are not traversed here — they resolve
	 * through the active row (Repeater_Row::get_nested_wrapper()).
	 *
	 * @param array  $field    Repeater field definition.
	 * @param string $selector Field name.
	 * @param int    $post_id  Post ID.
	 * @return Repeater_Row[]
	 */
	public static function repeater_rows( array $field, string $selector, int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		return self::rows_from_parsed( $field, $selector, parse_blocks( $post->post_content ) );
	}

	/**
	 * Legacy-only row parse: the FIRST top-level tk/repeater block whose
	 * fieldName matches, mapped to Repeater_Row objects. BC for callers
	 * that predate field registration (V1 matched by fieldName alone).
	 *
	 * @param string $selector Field name.
	 * @param int    $post_id  Post ID.
	 * @return Repeater_Row[]
	 */
	public static function legacy_rows( string $selector, int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		$rows = array();
		foreach ( parse_blocks( $post->post_content ) as $block ) {
			if ( 'tk/repeater' !== ( $block['blockName'] ?? '' ) ) {
				continue;
			}
			if ( (string) ( $block['attrs']['fieldName'] ?? '' ) !== $selector ) {
				continue;
			}
			foreach ( $block['innerBlocks'] ?? array() as $inner ) {
				if ( 'tk/repeater-row' === ( $inner['blockName'] ?? '' ) ) {
					$rows[] = new Repeater_Row( $inner );
				}
			}

			return $rows;
		}

		return $rows;
	}

	/**
	 * Canonical raw row arrays for a repeater field on a post:
	 * [ ['id' => row id, 'fields' => [name => value]], ... ].
	 *
	 * @param array  $field    Repeater field definition.
	 * @param string $selector Field name.
	 * @param int    $post_id  Post ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows_raw( array $field, string $selector, int $post_id ): array {
		$rows = array();
		foreach ( self::repeater_rows( $field, $selector, $post_id ) as $row ) {
			$rows[] = array(
				'id'     => $row->get_id(),
				'fields' => self::row_values( $field, $row->get_inner_blocks() ),
			);
		}

		return $rows;
	}

	/**
	 * Parse serialized repeater markup (as stored in term/user meta or an
	 * option) into canonical raw row arrays.
	 *
	 * @param array  $field    Repeater field definition.
	 * @param string $selector Field name.
	 * @param string $markup   serialize_blocks() output.
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows_from_markup( array $field, string $selector, string $markup ): array {
		if ( '' === trim( $markup ) ) {
			return array();
		}

		$rows = array();
		foreach ( self::rows_from_parsed( $field, $selector, parse_blocks( $markup ) ) as $row ) {
			$rows[] = array(
				'id'     => $row->get_id(),
				'fields' => self::row_values( $field, $row->get_inner_blocks() ),
			);
		}

		return $rows;
	}

	/**
	 * Canonical raw row arrays from an already-located wrapper block.
	 * Recursive: nested repeater wrappers resolve through each sub-field's
	 * own definition, so depth-2 rows parse to the same canonical shape.
	 *
	 * @param array<string, mixed> $field   Repeater field definition.
	 * @param array<string, mixed> $wrapper Parsed tk/field-repeater block.
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows_from_wrapper( array $field, array $wrapper ): array {
		$rows = array();

		foreach ( $wrapper['innerBlocks'] ?? array() as $inner ) {
			if ( 'tk/repeater-row' !== ( $inner['blockName'] ?? '' ) ) {
				continue;
			}
			$rows[] = array(
				'id'     => Repeater_Row::row_id( $inner['attrs'] ?? array(), $inner ),
				'fields' => self::row_values( $field, $inner['innerBlocks'] ?? array() ),
			);
		}

		return $rows;
	}

	/**
	 * Walk parsed top-level blocks for the first matching wrapper and map
	 * its tk/repeater-row children to Repeater_Row objects.
	 *
	 * The new tk/field-repeater wrapper is tried first; the legacy
	 * tk/repeater block (V1, matched by fieldName only) is the BC
	 * fallback. First matching top-level block wins.
	 *
	 * @param array<string, mixed>            $field    Repeater field definition.
	 * @param string                          $selector Field name.
	 * @param array<int, array<string,mixed>> $blocks   Parsed blocks.
	 * @return Repeater_Row[]
	 */
	private static function rows_from_parsed( array $field, string $selector, array $blocks ): array {
		foreach ( $blocks as $block ) {
			if ( ! self::wrapper_matches( $block, $field, $selector ) ) {
				continue;
			}

			$rows = array();
			foreach ( $block['innerBlocks'] ?? array() as $inner ) {
				if ( 'tk/repeater-row' === ( $inner['blockName'] ?? '' ) ) {
					$rows[] = new Repeater_Row( $inner );
				}
			}

			return $rows;
		}

		foreach ( $blocks as $block ) {
			if ( 'tk/repeater' !== ( $block['blockName'] ?? '' ) ) {
				continue;
			}
			if ( (string) ( $block['attrs']['fieldName'] ?? '' ) !== $selector ) {
				continue;
			}

			$rows = array();
			foreach ( $block['innerBlocks'] ?? array() as $inner ) {
				if ( 'tk/repeater-row' === ( $inner['blockName'] ?? '' ) ) {
					$rows[] = new Repeater_Row( $inner );
				}
			}

			return $rows;
		}

		return array();
	}

	/**
	 * Whether a parsed block is this field's tk/field-repeater wrapper.
	 * fieldKey (Q1 identity) wins when both sides carry one; fieldName is
	 * the fallback (covers definitions built without keys).
	 *
	 * @param array<string, mixed> $block    Parsed block.
	 * @param array<string, mixed> $field    Repeater field definition.
	 * @param string               $selector Field name.
	 */
	private static function wrapper_matches( array $block, array $field, string $selector ): bool {
		if ( 'tk/field-repeater' !== ( $block['blockName'] ?? '' ) ) {
			return false;
		}
		$attrs     = $block['attrs'] ?? array();
		$field_key = (string) ( $field['key'] ?? '' );
		$wrap_key  = (string) ( $attrs['fieldKey'] ?? '' );
		if ( '' !== $field_key && '' !== $wrap_key ) {
			return $wrap_key === $field_key;
		}

		return (string) ( $attrs['fieldName'] ?? '' ) === $selector;
	}

	/**
	 * One row's sub-field values from its parsed inner blocks.
	 *
	 * The row's OWN tk/field-value blocks are collected without descending
	 * into nested repeater subtrees (the CQ3 scoping fix: a nested
	 * repeater's values can never leak into the parent row's namespace).
	 * Nested repeater sub-fields recurse through rows_from_wrapper() on
	 * the matching nested wrapper; group sub-fields nest their flattened
	 * leaf field-values back under the group sub name (never stealing a
	 * name claimed by a top-level sub). Unknown names pass through raw.
	 *
	 * @param array<string, mixed>            $field  Repeater field definition.
	 * @param array<int, array<string,mixed>> $blocks Row inner blocks.
	 * @return array<string, mixed>
	 */
	private static function row_values( array $field, array $blocks ): array {
		$children = Field_Registry::instance()->resolve_repeater_children( $field );

		$flat = array();
		foreach ( self::walk_scoped_blocks( $blocks ) as $inner ) {
			if ( 'tk/field-value' !== ( $inner['blockName'] ?? '' ) ) {
				continue;
			}
			$name = (string) ( $inner['attrs']['fieldName'] ?? '' );
			if ( '' === $name || array_key_exists( $name, $flat ) ) {
				continue;
			}
			$flat[ $name ] = $inner['attrs']['value'] ?? null;
		}

		$values   = $flat;
		$consumed = array();

		foreach ( $children as $sname => $sub ) {
			$stype = (string) ( $sub['type'] ?? '' );

			if ( 'repeater' === $stype ) {
				$row  = new Repeater_Row( array( 'innerBlocks' => $blocks ) );
				$nest = $row->get_nested_wrapper( $sname, (string) ( $sub['key'] ?? '' ) );
				if ( null !== $nest ) {
					$values[ $sname ] = self::rows_from_wrapper( $sub, $nest );
				}
				continue;
			}

			if ( 'group' === $stype ) {
				$leaves = array();
				foreach ( (array) ( $sub['sub_fields'] ?? array() ) as $leaf ) {
					if ( ! is_array( $leaf ) ) {
						continue;
					}
					$lname = (string) ( $leaf['name'] ?? '' );
					if ( '' === $lname || isset( $children[ $lname ] ) || isset( $consumed[ $lname ] ) ) {
						continue;
					}
					if ( array_key_exists( $lname, $flat ) ) {
						$leaves[ $lname ]   = $flat[ $lname ];
						$consumed[ $lname ] = true;
					}
				}
				if ( array() !== $leaves ) {
					$values[ $sname ] = $leaves;
					foreach ( array_keys( $leaves ) as $lname ) {
						unset( $values[ $lname ] );
					}
				}
			}
		}

		return $values;
	}

	/**
	 * Depth-first walk that prunes nested repeater subtrees: nested
	 * wrappers are never descended into (their values belong to the nested
	 * repeater's own scope). Wrappers themselves are not yielded — the
	 * caller only wants the row's own blocks.
	 *
	 * @param array<int, array<string,mixed>> $blocks
	 * @return \Generator<int, array<string, mixed>>
	 */
	private static function walk_scoped_blocks( array $blocks ): \Generator {
		foreach ( $blocks as $block ) {
			$block_name = $block['blockName'] ?? '';
			if ( 'tk/field-repeater' === $block_name || 'tk/repeater' === $block_name ) {
				continue;
			}
			yield $block;
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				yield from self::walk_scoped_blocks( $block['innerBlocks'] );
			}
		}
	}

	/**
	 * Serialize canonical rows into block markup for non-post storage
	 * (term meta, user meta, options). The markup round-trips through
	 * rows_from_markup() losslessly.
	 *
	 * Structure: tk/field-repeater > tk/repeater-row (rowId attr) >
	 * tk/field-value per scalar sub (value attribute canonicalized exactly
	 * as the editor writes it), nested tk/field-repeater per nested
	 * repeater sub, flattened tk/field-value per group leaf (keyed by short
	 * leaf name; never shadowing a top-level sub name).
	 *
	 * @param array<string, mixed>            $field Repeater field definition (needs name, key, sub_fields).
	 * @param array<int, array<string,mixed>> $rows  Canonical sanitized rows.
	 */
	public static function serialize_rows( array $field, array $rows ): string {
		return serialize_blocks( array( self::wrapper_block( $field, $rows ) ) );
	}

	/**
	 * Build the tk/field-repeater wrapper block array for canonical rows.
	 *
	 * @param array<string, mixed>            $field Repeater field definition.
	 * @param array<int, array<string,mixed>> $rows  Canonical sanitized rows.
	 * @return array<string, mixed> Parsed-block-shaped array.
	 */
	private static function wrapper_block( array $field, array $rows ): array {
		$children = Field_Registry::instance()->resolve_repeater_children( $field );
		$inner    = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$subs    = self::serialize_row_values( $children, (array) ( $row['fields'] ?? array() ) );
			$inner[] = array(
				'blockName'    => 'tk/repeater-row',
				'attrs'        => array( 'rowId' => (string) ( $row['id'] ?? '' ) ),
				'innerBlocks'  => $subs,
				'innerHTML'    => '',
				// serialize_block() renders inner blocks ONLY from
				// innerContent: one null marker per inner block.
				'innerContent' => array_fill( 0, count( $subs ), null ),
			);
		}

		return array(
			'blockName'    => 'tk/field-repeater',
			'attrs'        => array(
				'fieldName' => (string) ( $field['name'] ?? '' ),
				'fieldKey'  => isset( $field['key'] ) ? (string) $field['key'] : '',
			),
			'innerBlocks'  => $inner,
			'innerHTML'    => '',
			'innerContent' => array_fill( 0, count( $inner ), null ),
		);
	}

	/**
	 * Serialize one row's field values to inner blocks.
	 *
	 * @param array<string, array> $children Child definitions keyed by short sub name.
	 * @param array<string, mixed> $fields   Canonical row fields.
	 * @return array<int, array<string, mixed>>
	 */
	private static function serialize_row_values( array $children, array $fields ): array {
		$subs = array();

		foreach ( $fields as $name => $value ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			$sub_def = $children[ $name ] ?? null;
			$stype   = $sub_def ? (string) ( $sub_def['type'] ?? 'text' ) : 'text';

			if ( 'repeater' === $stype && is_array( $value ) ) {
				// Nested wrapper: rows are canonical {id, fields} arrays.
				$subs[] = self::wrapper_block( $sub_def, $value );
				continue;
			}

			if ( 'group' === $stype && is_array( $value ) ) {
				// Group leaves are flattened into the row's field-values,
				// keyed by short leaf name; the parser nests them back.
				// Never shadow a top-level sub name.
				$leaf_defs = array();
				foreach ( (array) ( $sub_def['sub_fields'] ?? array() ) as $leaf ) {
					if ( is_array( $leaf ) && isset( $leaf['name'] ) ) {
						$leaf_defs[ (string) $leaf['name'] ] = $leaf;
					}
				}
				foreach ( $value as $lname => $lvalue ) {
					if ( ! is_string( $lname ) || isset( $children[ $lname ] ) ) {
						continue;
					}
					$subs[] = self::field_value_block( $lname, $lvalue, $leaf_defs[ $lname ] ?? null );
				}
				continue;
			}

			$subs[] = self::field_value_block( $name, $value, $sub_def );
		}

		return $subs;
	}

	/**
	 * Build one tk/field-value block array, mirroring the editor's
	 * attribute canonicalization (Flexible_Content::serialize_rows()).
	 *
	 * @param string               $name  Sub-field name.
	 * @param mixed                $value Canonical sub-field value.
	 * @param array<string, mixed>|null $def Sub-field definition (for fieldType/options/taxonomy attrs).
	 * @return array<string, mixed> Parsed-block-shaped array.
	 */
	private static function field_value_block( string $name, mixed $value, ?array $def ): array {
		$attrs = array(
			'fieldName' => $name,
			'fieldType' => $def['type'] ?? 'text',
			'value'     => $value,
		);
		if ( $def && in_array( $def['type'], array( 'select', 'radio', 'button_group' ), true ) ) {
			$attrs['options'] = Flexible_Content::options_text( $def['choices'] ?? array() );
		}
		if ( $def && 'taxonomy' === $def['type'] && ! empty( $def['taxonomy'] ) ) {
			$attrs['taxonomy'] = $def['taxonomy'];
		}

		return array(
			'blockName'    => 'tk/field-value',
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}
}

/**
 * Static-stack template engine for the repeater loop idiom.
 *
 * while ( tk_have_rows( 'team' ) ) {
 *     tk_the_row();
 *     echo tk_get_sub_field( 'name' );
 * }
 *
 * Nested repeaters loop through the ACTIVE ROW's nested wrapper:
 *
 * while ( tk_have_rows( 'modules' ) ) {
 *     tk_the_row();
 *     while ( tk_have_rows( 'lessons' ) ) {
 *         tk_the_row();
 *         echo tk_get_sub_field( 'title' );
 *     }
 * }
 *
 * have_rows() either continues the top loop for the same selector+post+path
 * (returning whether more rows remain, popping the loop when exhausted) or
 * starts a new loop by pushing onto the stack. the_row() advances the top
 * loop's cursor.
 *
 * The path stack is the scoping mechanism: each loop entry carries the
 * full segment path (field names and row ids from the outermost repeater
 * down), so sibling rows' nested loops can never collide — editing row 2
 * of a nested repeater leaves row 1 byte-identical. When the parent field
 * expects a nested repeater for the selector but the active row has no
 * wrapper, have_rows() returns false rather than leaking into a
 * top-level parse.
 */
final class Repeater_Loop {

	/**
	 * @var array<int, array{selector: string, post_id: int, path: array<int, string>, field_key: string, rows: array<int, Repeater_Row>, index: int, current: Repeater_Row|null}>
	 */
	private static array $stack = array();

	/**
	 * Drop all active loops (tests, mostly).
	 */
	public static function reset(): void {
		self::$stack = array();
	}

	/**
	 * Whether the repeater has rows: starts a new loop on first call,
	 * continues it on subsequent calls, pops it when exhausted.
	 *
	 * A selector that is a repeater sub-field of the top loop's field
	 * resolves against the ACTIVE ROW's nested wrapper (row-scoped); any
	 * other selector parses post_content top-level. Continuation requires
	 * the same selector, post, AND full path — sibling rows' nested loops
	 * can never collide.
	 *
	 * @param string            $selector Field name.
	 * @param int|\WP_Post|null $post     Post ID, WP_Post, or null for the current post.
	 */
	public static function have_rows( string $selector, int|\WP_Post|null $post = null ): bool {
		$post_id = self::resolve_post_id( $post );
		if ( 0 === $post_id ) {
			return false;
		}

		$top = end( self::$stack );

		// Nested context: the top loop's active row owns this selector as a
		// repeater sub-field. The expected path is row-scoped.
		$nested_sub = null;
		$top_row    = null;
		$nested_key = '';
		if ( is_array( $top ) && $top['post_id'] === $post_id && $top['current'] instanceof Repeater_Row ) {
			$parent = Field_Registry::instance()->get( $top['selector'] );
			if ( is_array( $parent ) && 'repeater' === ( $parent['type'] ?? '' ) ) {
				$sub = Field_Registry::instance()->resolve_repeater_children( $parent )[ $selector ] ?? null;
				if ( is_array( $sub ) && 'repeater' === ( $sub['type'] ?? '' ) ) {
					$nested_sub = $sub;
					$top_row    = $top['current'];
					$nested_key = (string) ( $sub['key'] ?? '' );
				}
			}
		}

		// Continuation: same selector + post + full path. When the top loop
		// IS this selector's loop its own path is authoritative; otherwise
		// the expected path distinguishes nested from top-level.
		$expected_path = null !== $nested_sub && ( ! is_array( $top ) || $top['selector'] !== $selector )
			? array_merge( $top['path'], array( $top_row->get_id(), $selector ) )
			: ( is_array( $top ) && $top['selector'] === $selector ? $top['path'] : array( $selector ) );

		if ( is_array( $top )
			&& $top['selector'] === $selector
			&& $top['post_id'] === $post_id
			&& $top['path'] === $expected_path
		) {
			if ( $top['index'] < count( $top['rows'] ) ) {
				return true;
			}
			// Exhausted: pop this loop so a fresh call restarts it.
			array_pop( self::$stack );
			return false;
		}

		// Nested loop under the active row. When the parent expects a
		// nested repeater here but the row carries no wrapper, return
		// false — never leak into a top-level parse of the same name.
		if ( null !== $nested_sub ) {
			$wrapper = $top_row->get_nested_wrapper( $selector, $nested_key );
			if ( null === $wrapper ) {
				return false;
			}
			$rows = array();
			foreach ( $wrapper['innerBlocks'] ?? array() as $inner ) {
				if ( 'tk/repeater-row' === ( $inner['blockName'] ?? '' ) ) {
					$rows[] = new Repeater_Row( $inner );
				}
			}
			if ( array() === $rows ) {
				return false;
			}
			self::$stack[] = array(
				'selector'  => $selector,
				'post_id'   => $post_id,
				'path'      => $expected_path,
				'field_key' => $nested_key,
				'rows'      => $rows,
				'index'     => 0,
				'current'   => null,
			);
			return true;
		}

		// Top-level: the field must be a registered repeater; parse the
		// first matching wrapper in post_content (new tk/field-repeater
		// first, legacy tk/repeater for BC).
		$field = Field_Registry::instance()->get( $selector );
		if ( ! is_array( $field ) || 'repeater' !== ( $field['type'] ?? '' ) ) {
			return false;
		}
		$rows = Repeater::repeater_rows( $field, $selector, $post_id );
		if ( array() === $rows ) {
			return false;
		}

		self::$stack[] = array(
			'selector'  => $selector,
			'post_id'   => $post_id,
			'path'      => array( $selector ),
			'field_key' => (string) ( $field['key'] ?? '' ),
			'rows'      => $rows,
			'index'     => 0,
			'current'   => null,
		);

		return true;
	}

	/**
	 * Advance the top loop to the next row.
	 */
	public static function the_row(): void {
		$i = count( self::$stack ) - 1;
		if ( $i < 0 ) {
			return;
		}

		$loop = &self::$stack[ $i ];
		$loop['current'] = $loop['rows'][ $loop['index'] ] ?? null;
		$loop['index']++;
	}

	/**
	 * The current row of the top loop, if any.
	 */
	public static function current_row(): ?Repeater_Row {
		$top = end( self::$stack );
		return ( is_array( $top ) && $top['current'] instanceof Repeater_Row ) ? $top['current'] : null;
	}

	/**
	 * Sub-field value from the current row; null when there is no active row
	 * or the field is absent.
	 */
	public static function get_sub_field( string $name ) {
		$row = self::current_row();
		return $row ? $row->get_field( $name ) : null;
	}

	/**
	 * Stable ID of the current row, or null when there is no active row.
	 */
	public static function get_row_id(): ?string {
		$row = self::current_row();
		return $row ? $row->get_id() : null;
	}

	/**
	 * The full segment path of the top loop: field names and row ids from
	 * the outermost repeater down to the current row, e.g.
	 * ['modules', '<rowId>', 'lessons', '<rowId>']. Null when no loop is
	 * active. This is the context path stack — identity end-to-end.
	 *
	 * @return array<int, string>|null
	 */
	public static function current_path(): ?array {
		$top = end( self::$stack );
		if ( ! is_array( $top ) ) {
			return null;
		}
		$path = $top['path'];
		$row  = $top['current'] ?? null;
		if ( $row instanceof Repeater_Row ) {
			$path[] = $row->get_id();
		}

		return $path;
	}

	/**
	 * @param int|\WP_Post|null $post
	 */
	private static function resolve_post_id( int|\WP_Post|null $post ): int {
		if ( $post instanceof \WP_Post ) {
			return (int) $post->ID;
		}

		if ( null === $post ) {
			return (int) get_the_ID();
		}

		return absint( $post );
	}
}

/**
 * Register the "TK Fields" block category.
 *
 * @param array<int, array<string, string>> $categories
 * @return array<int, array<string, string>>
 */
function register_block_category( array $categories ): array {
	foreach ( $categories as $category ) {
		if ( 'tk-fields' === ( $category['slug'] ?? '' ) ) {
			return $categories;
		}
	}

	$categories[] = array(
		'slug'  => 'tk-fields',
		'title' => __( 'TK Fields', 'tk-fields' ),
	);

	return $categories;
}
add_filter( 'block_categories_all', __NAMESPACE__ . '\\register_block_category' );

/**
 * Inject the filterable default allowed-blocks list for repeater rows before
 * the row editor script. The row editor falls back to this when the
 * repeater instance has no per-instance allowedBlocks attribute set.
 */
function inject_repeater_editor_defaults(): void {
	$defaults = (array) apply_filters(
		'tk_fields_repeater_allowed_blocks',
		array(
			'core/paragraph',
			'core/heading',
			'core/image',
			'core/list',
			'core/buttons',
			'core/quote',
			'tk/field-value',
		)
	);

	wp_add_inline_script(
		'tkf-repeater-row-editor',
		'window.tkFieldsRepeaterDefaults = ' . wp_json_encode(
			array( 'allowedBlocks' => array_values( $defaults ) )
		) . ';',
		'before'
	);
}
add_action( 'init', __NAMESPACE__ . '\\inject_repeater_editor_defaults', 20 );
