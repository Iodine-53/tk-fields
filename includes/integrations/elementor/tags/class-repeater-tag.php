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
 * Repeater data tag for TK Fields.
 *
 * Field-bound, read-only binding: resolves ONE repeater field plus a
 * sub-field path through the canonical Fields service typed read
 * (Fields::get() formatted — list<array<string,mixed>>, recursive per the
 * repeater CQ1 contract). Tag settings: the field selector plus a
 * dot-notation sub-field path (e.g. `title`, or `lessons.title` for a
 * nested repeater). get_value() returns one entry per row, in stored
 * order — the same row granularity and order as Fields::rows(), so tag
 * output aligns 1:1 with the TK Repeater widget's row sequence. The
 * widget stays the render primitive; this tag only exposes the typed
 * values (it never renders rows and never authors them — no Elementor
 * authoring UI, per the Q1 ownership boundary).
 *
 * Each entry is the sub-field's own formatted value (an image sub-field
 * yields the same Image DTO as a top-level image field). Rows missing
 * the path keep their position as null — output cardinality always
 * matches the row count. Leave the path empty to receive every row's
 * full value array.
 *
 * Module only registers this tag once the registry actually holds a
 * repeater-type field.
 *
 * @package TK\Fields\Integrations\Elementor\Tags
 */

declare(strict_types=1);

namespace TK\Fields\Integrations\Elementor\Tags;

use Elementor\Modules\DynamicTags\Module as Dynamic_Tags_Module;
use TK\Fields\Field_Registry;
use TK\Fields\Fields;
use TK\Fields\Integrations\Elementor\Context;
use TK\Fields\Integrations\Elementor\Module;
use TK\Fields\Unset_Value;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Repeater_Tag extends \Elementor\Core\DynamicTags\Data_Tag {

	public function get_name(): string {
		return 'tk-fields-repeater';
	}

	public function get_title(): string {
		return __( 'TK Repeater Field', 'tk-fields' );
	}

	final public function get_group(): array {
		return array( Module::GROUP );
	}

	public function get_categories(): array {
		return array(
			Dynamic_Tags_Module::TEXT_CATEGORY,
		);
	}

	public function get_panel_template_setting_key() {
		return 'field_key';
	}

	protected function register_controls(): void {
		$fields  = Field_Registry::instance()->all();
		$options = array();

		foreach ( $fields as $name => $field ) {
			if ( 'repeater' === ( $field['type'] ?? '' ) ) {
				$options[ $name ] = sprintf(
					/* translators: 1: field label, 2: field name. */
					__( '%1$s (%2$s)', 'tk-fields' ),
					$field['label'],
					$name
				);
			}
		}

		if ( empty( $options ) ) {
			$options = array(
				'' => __( 'No fields of this type yet', 'tk-fields' ),
			);
		}

		$this->add_control(
			'field_key',
			array(
				'label'       => __( 'Field', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => $options,
				'description' => __( 'Value comes from TK Fields for the post being rendered.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'sub_field_path',
			array(
				'label'       => __( 'Sub-field path', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => 'title',
				'description' => __(
					'Sub-field name, or a dot path into a nested repeater (e.g. lessons.title). Leave empty to return every row\'s full values.',
					'tk-fields'
				),
			)
		);
	}

	/**
	 * Resolve the selected repeater field + sub-field path for the current
	 * Elementor rendering context.
	 *
	 * @return array<int, mixed> One entry per row in stored order (null
	 *                           where a row lacks the path); empty array
	 *                           when no field is selected, the field is
	 *                           unknown/unset, or it is not a repeater.
	 */
	protected function get_value( array $options = [] ) {
		$key = $this->get_settings( 'field_key' );

		if ( empty( $key ) || ! is_string( $key ) ) {
			return array();
		}

		// Fail closed: the picker only offers repeater fields, but the
		// setting can be forced programmatically.
		$def = Field_Registry::instance()->get( $key );
		if ( null === $def || 'repeater' !== ( $def['type'] ?? '' ) ) {
			return array();
		}

		// Canonical typed read (formatted): list<array<string,mixed>>,
		// recursive. Adapted at this integration boundary; the canonical
		// data model is never distorted.
		$rows = Fields::get( $key, Context::resolve_post_id() );

		if ( Unset_Value::is_unset( $rows ) || ! is_array( $rows ) ) {
			return array();
		}

		$path = $this->get_settings( 'sub_field_path' );
		$path = is_string( $path ) ? trim( $path ) : '';

		return self::resolve_path( array_values( $rows ), $path );
	}

	/**
	 * Elementor rendering entry point — the tag's output-escaping layer.
	 *
	 * Data_Tag::get_content() returns get_value() raw and Elementor
	 * performs no escaping on that path, so without this override every
	 * row value would reach page output unescaped (stored XSS when a
	 * sub-field carries the "allow unfiltered HTML" opt-in). This plays
	 * the same role render() plays in the sibling Tag subclasses: one
	 * escaped text line per row. get_value() stays raw as the typed data
	 * layer for programmatic consumers and unit tests.
	 *
	 * @param array $options Tag options.
	 * @return string Escaped text, one row per line.
	 */
	public function get_content( array $options = [] ): string {
		$lines = array();
		foreach ( $this->get_value( $options ) as $entry ) {
			$lines[] = self::entry_to_text( $entry );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Reduce one row entry to escaped display text.
	 *
	 * Scalars (and null) become a single escaped line; arrays (typed
	 * sub-field DTOs, or whole rows when no sub-field path is set)
	 * reduce their scalar leaves to comma-joined escaped text —
	 * mirroring the taxonomy sibling tag's "join the names" precedent
	 * for array values in the text category.
	 *
	 * @param mixed $entry One row's resolved value.
	 * @return string
	 */
	private static function entry_to_text( mixed $entry ): string {
		if ( null === $entry || is_scalar( $entry ) ) {
			return esc_html( (string) $entry );
		}
		if ( is_array( $entry ) ) {
			$parts = array();
			array_walk_recursive(
				$entry,
				function ( $leaf ) use ( &$parts ) {
					if ( is_scalar( $leaf ) ) {
						$parts[] = (string) $leaf;
					}
				}
			);
			return esc_html( implode( ', ', $parts ) );
		}
		return '';
	}

	/**
	 * Navigate a dot-notation sub-field path over CQ1-shaped rows.
	 *
	 * Pure function over the canonical value shape (no Elementor, no
	 * Fields service) so the resolution logic is unit-testable. Segments
	 * are sub-field short names; a segment landing on a nested repeater
	 * (a list of row arrays) maps the remaining path over each nested
	 * row. Output cardinality always matches the input row count at
	 * every level — rows missing the path contribute null, never a
	 * dropped position.
	 *
	 * @param array<int, array<string, mixed>> $rows CQ1 row list.
	 * @param string                           $path Dot-notation path ('' = whole rows).
	 * @return array<int, mixed>
	 */
	public static function resolve_path( array $rows, string $path ): array {
		$rows = array_values( $rows );

		if ( '' === trim( $path ) ) {
			return $rows;
		}

		$segments = array();
		foreach ( explode( '.', $path ) as $raw ) {
			$segment = trim( (string) $raw );
			if ( '' === $segment ) {
				continue;
			}
			// Fail closed: a segment that cannot be a sub-field short
			// name invalidates the whole path.
			if ( 1 !== preg_match( '/^[a-z0-9_]+$/i', $segment ) ) {
				return array();
			}
			$segments[] = $segment;
		}

		if ( [] === $segments ) {
			return $rows;
		}

		$resolved = self::descend( $rows, $segments );

		return is_array( $resolved ) ? array_values( $resolved ) : array();
	}

	/**
	 * Recursive path descent. Lists (repeater rows at any depth) map the
	 * current segment over their items; associative arrays (a row, or a
	 * typed sub-field DTO like an image) index the segment directly.
	 *
	 * @param mixed    $node     Row list, row array, sub-field value, or null.
	 * @param string[] $segments Remaining path segments.
	 * @return mixed
	 */
	private static function descend( mixed $node, array $segments ): mixed {
		if ( [] === $segments ) {
			return $node;
		}

		$segment = array_shift( $segments );

		if ( is_array( $node ) && self::is_list( $node ) ) {
			$out = array();
			foreach ( $node as $item ) {
				$out[] = self::descend( is_array( $item ) ? ( $item[ $segment ] ?? null ) : null, $segments );
			}
			return $out;
		}

		if ( is_array( $node ) ) {
			return self::descend( $node[ $segment ] ?? null, $segments );
		}

		return null;
	}

	/**
	 * Whether the value is a numerically-indexed list (the CQ1 row-list
	 * shape), as opposed to an associative row or DTO.
	 */
	private static function is_list( array $value ): bool {
		if ( [] === $value ) {
			return true;
		}

		return array_key_exists( 0, $value );
	}
}
