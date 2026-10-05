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
 * Flexible content rows and rendering — Round 3 Hard tier.
 *
 * A flexible_content field stores ordered layout rows. On posts the rows
 * live as native block markup in post_content (tk/flexible-content parent
 * with tk/flexible-layout children, each holding tk/field-value sub-field
 * blocks) — the block editor owns those writes, exactly like the repeater.
 * On non-post objects (terms, users, options) the same markup is kept as a
 * serialized string in a single meta/option row.
 *
 * This file owns:
 *
 * - Flexible_Row: a typed wrapper around one parsed tk/flexible-layout block.
 * - flexible_rows(): row objects for a field, post-only (block traversal).
 * - rows_raw(): canonical row arrays (layout/id/fields) for a field.
 * - rows_from_markup(): parse serialized markup into canonical row arrays.
 * - serialize_rows(): canonical rows -> block markup (non-post storage).
 * - render_rows_html(): formatted rows -> escaped layout HTML (Elementor
 *   text tag, field-value block render).
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One flexible layout row: a parsed tk/flexible-layout block and its
 * sub-fields.
 */
class Flexible_Row {

	/**
	 * @var array<string, mixed> Parsed block array (parse_blocks() shape).
	 */
	private array $block;

	public function __construct( array $block ) {
		$this->block = $block;
	}

	/**
	 * The layout key this row instantiates (the 'layout' attribute).
	 */
	public function get_layout(): string {
		return isset( $this->block['attrs']['layout'] ) ? (string) $this->block['attrs']['layout'] : '';
	}

	/**
	 * Stable row identity — the layoutId attribute set by the editor, or a
	 * deterministic hash of the row content when it is missing (legacy /
	 * hand-written markup). Never the array index.
	 */
	public function get_id(): string {
		$attrs    = $this->block['attrs'] ?? array();
		$layout_id = isset( $attrs['layoutId'] ) ? (string) $attrs['layoutId'] : '';
		if ( '' !== $layout_id ) {
			return $layout_id;
		}

		return 'tk-layout-' . substr( md5( wp_json_encode( $this->block ) ), 0, 12 );
	}

	/**
	 * The value of one sub-field (tk/field-value with matching fieldName).
	 * Scans inner blocks depth-first in document order; first match wins.
	 * Returns the canonicalized value attribute, or null when absent.
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
	 * Depth-first walk of parsed inner blocks (document order).
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return \Generator<int, array<string, mixed>>
	 */
	private static function walk_inner_blocks( array $blocks ): \Generator {
		foreach ( $blocks as $block ) {
			yield $block;
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				yield from self::walk_inner_blocks( $block['innerBlocks'] );
			}
		}
	}
}

/**
 * Static helpers for flexible content storage and rendering.
 */
final class Flexible_Content {

	/**
	 * Row objects for a flexible field on a post, in document order.
	 *
	 * First top-level tk/flexible-content block whose fieldName matches wins
	 * (repeater parity); nested flexible blocks are not traversed.
	 *
	 * @param string $selector Field name.
	 * @param int    $post_id  Post ID.
	 * @return Flexible_Row[]
	 */
	public static function flexible_rows( string $selector, int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		$rows = array();
		foreach ( self::layout_blocks_from_blocks( $selector, parse_blocks( $post->post_content ) ) as $inner ) {
			$rows[] = new Flexible_Row( $inner );
		}

		return $rows;
	}

	/**
	 * Canonical raw row arrays for a flexible field on a post:
	 * [ ['layout' => key, 'id' => row id, 'fields' => [name => value]], ... ].
	 *
	 * @param string $selector Field name.
	 * @param int    $post_id  Post ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows_raw( string $selector, int $post_id ): array {
		$rows = array();
		foreach ( self::flexible_rows( $selector, $post_id ) as $row ) {
			$rows[] = array(
				'layout' => $row->get_layout(),
				'id'     => $row->get_id(),
				'fields' => $row->get_fields(),
			);
		}

		return $rows;
	}

	/**
	 * Parse serialized flexible markup (as stored in term/user meta or an
	 * option) into canonical raw row arrays.
	 *
	 * @param string $selector Field name.
	 * @param string $markup   serialize_blocks() output.
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows_from_markup( string $selector, string $markup ): array {
		if ( '' === trim( $markup ) ) {
			return array();
		}

		return self::rows_from_blocks( $selector, parse_blocks( $markup ) );
	}

	/**
	 * Walk parsed top-level blocks for the first matching tk/flexible-content
	 * block and return its layout rows as canonical arrays.
	 *
	 * @param string                         $selector Field name.
	 * @param array<int, array<string,mixed>> $blocks  Parsed blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows_from_blocks( string $selector, array $blocks ): array {
		$rows = array();

		foreach ( self::layout_blocks_from_blocks( $selector, $blocks ) as $inner ) {
			$row    = new Flexible_Row( $inner );
			$rows[] = array(
				'layout' => $row->get_layout(),
				'id'     => $row->get_id(),
				'fields' => $row->get_fields(),
			);
		}

		return $rows;
	}

	/**
	 * Walk parsed top-level blocks and yield the raw tk/flexible-layout
	 * block arrays of the first tk/flexible-content block whose fieldName
	 * matches. First matching top-level block wins (repeater parity);
	 * nested flexible blocks are not traversed.
	 *
	 * @param string                          $selector Field name.
	 * @param array<int, array<string,mixed>> $blocks   Parsed blocks.
	 * @return \Generator<int, array<string, mixed>>
	 */
	private static function layout_blocks_from_blocks( string $selector, array $blocks ): \Generator {
		foreach ( $blocks as $block ) {
			if ( 'tk/flexible-content' !== ( $block['blockName'] ?? '' ) ) {
				continue;
			}
			if ( ( $block['attrs']['fieldName'] ?? '' ) !== $selector ) {
				continue;
			}

			foreach ( $block['innerBlocks'] ?? array() as $inner ) {
				if ( 'tk/flexible-layout' !== ( $inner['blockName'] ?? '' ) ) {
					continue;
				}
				yield $inner;
			}

			// First matching top-level block wins (repeater parity).
			return;
		}
	}

	/**
	 * Serialize canonical rows into block markup for non-post storage
	 * (term meta, user meta, options). The markup round-trips through
	 * rows_from_markup() losslessly.
	 *
	 * Sub-field values are written through tk/field-value blocks with the
	 * value attribute canonicalized exactly as the editor writes it.
	 *
	 * @param array<string, mixed>            $field Field definition (needs name, key, layouts).
	 * @param array<int, array<string,mixed>> $rows  Canonical sanitized rows.
	 */
	public static function serialize_rows( array $field, array $rows ): string {
		$layouts  = self::index_layouts( $field['layouts'] ?? array() );
		$inner    = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$layout_key = (string) ( $row['layout'] ?? '' );
			$layout     = $layouts[ $layout_key ] ?? null;
			$subs       = array();

			foreach ( (array) ( $row['fields'] ?? array() ) as $name => $value ) {
				if ( ! is_string( $name ) ) {
					continue;
				}
				$sub_def = $layout ? self::layout_subfield( $layout, $name ) : null;
				$attrs   = array(
					'fieldName' => $name,
					'fieldType' => $sub_def['type'] ?? 'text',
					'value'     => $value,
				);
				if ( $sub_def && in_array( $sub_def['type'], array( 'select', 'radio', 'button_group' ), true ) ) {
					$attrs['options'] = self::options_text( $sub_def['choices'] ?? array() );
				}
				if ( $sub_def && 'taxonomy' === $sub_def['type'] && ! empty( $sub_def['taxonomy'] ) ) {
					$attrs['taxonomy'] = $sub_def['taxonomy'];
				}
				$subs[] = array(
					'blockName'    => 'tk/field-value',
					'attrs'        => $attrs,
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				);
			}

			$inner[] = array(
				'blockName'    => 'tk/flexible-layout',
				'attrs'        => array(
					'layout'   => $layout_key,
					'layoutId' => (string) ( $row['id'] ?? '' ),
					'fieldKey' => isset( $field['key'] ) ? (string) $field['key'] : '',
				),
				'innerBlocks'  => $subs,
				'innerHTML'    => '',
				// serialize_block() renders inner blocks ONLY from
				// innerContent: one null marker per inner block.
				'innerContent' => array_fill( 0, count( $subs ), null ),
			);
		}

		return serialize_blocks(
			array(
				array(
					'blockName'    => 'tk/flexible-content',
					'attrs'        => array(
						'fieldName' => (string) ( $field['name'] ?? '' ),
						'fieldKey'  => isset( $field['key'] ) ? (string) $field['key'] : '',
					),
					'innerBlocks'  => $inner,
					'innerHTML'    => '',
					'innerContent' => array_fill( 0, count( $inner ), null ),
				),
			)
		);
	}

	/**
	 * Render formatted rows (Field_Registry::format() output) as escaped
	 * layout HTML. Used by the Elementor text tag and the tk/field-value
	 * render path. Every dynamic string is escaped inside; the return is
	 * safe to echo.
	 *
	 * @param array<int, array<string,mixed>> $rows Formatted rows.
	 */
	public static function render_rows_html( array $rows ): string {
		$html = '';

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$layout = (string) ( $row['layout'] ?? '' );
			$fields_html = '';

			foreach ( (array) ( $row['fields'] ?? array() ) as $name => $value ) {
				$fields_html .= sprintf(
					'<div class="tk-flexible-field" data-field="%s">%s</div>',
					esc_attr( (string) $name ),
					self::render_scalar( $value )
				);
			}

			// The human label comes from formatted rows (layout_label);
			// raw canonical rows fall back to the layout key.
			$label = (string) ( $row['layout_label'] ?? '' );
			if ( '' === $label ) {
				$label = $layout;
			}

			$html .= sprintf(
				'<div class="tk-flexible-row tk-flexible-row--%s" data-layout="%s"><div class="tk-flexible-row__label">%s</div>%s</div>',
				esc_attr( $layout ),
				esc_attr( $layout ),
				esc_html( $label ),
				$fields_html
			);
		}

		return $html;
	}

	/**
	 * Escape one formatted sub-field value for the layouts HTML.
	 *
	 * @param mixed $value
	 */
	private static function render_scalar( mixed $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			return esc_html( wp_json_encode( $value ) );
		}

		return esc_html( (string) $value );
	}

	/**
	 * Index a normalized layouts list by layout key.
	 *
	 * @param mixed $layouts List of layout defs (or keyed array — tolerated).
	 * @return array<string, array<string, mixed>>
	 */
	public static function index_layouts( mixed $layouts ): array {
		$out = array();
		if ( ! is_array( $layouts ) ) {
			return $out;
		}
		foreach ( $layouts as $key => $layout ) {
			if ( ! is_array( $layout ) ) {
				continue;
			}
			$layout_key = isset( $layout['key'] ) ? (string) $layout['key'] : (string) $key;
			if ( '' === $layout_key ) {
				continue;
			}
			$out[ $layout_key ] = $layout;
		}

		return $out;
	}

	/**
	 * Find a sub-field definition inside a layout by name.
	 *
	 * @param array<string, mixed> $layout Layout definition.
	 * @param string               $name   Sub-field name.
	 */
	public static function layout_subfield( array $layout, string $name ): ?array {
		foreach ( (array) ( $layout['fields'] ?? array() ) as $sub ) {
			if ( is_array( $sub ) && (string) ( $sub['name'] ?? '' ) === $name ) {
				return $sub;
			}
		}

		return null;
	}

	/**
	 * Choices array -> "value | Label" lines (the inspector options format
	 * used by the field-value block and the editor).
	 *
	 * @param mixed $choices
	 */
	public static function options_text( mixed $choices ): string {
		if ( ! is_array( $choices ) ) {
			return '';
		}
		$lines = array();
		foreach ( $choices as $value => $label ) {
			$lines[] = (string) $value . ' | ' . (string) $label;
		}

		return implode( "\n", $lines );
	}
}
