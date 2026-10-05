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
 * Group data tag for TK Fields.
 *
 * Returns the group's assembled value: an array keyed by short sub-field
 * name (each child already formatted by Fields::get()). Renders as an
 * escaped definition list of sub-field labels and values. Module only
 * registers this tag once the registry actually holds a group-type field.
 *
 * @package TK\Fields\Integrations\Elementor\Tags
 */

declare(strict_types=1);

namespace TK\Fields\Integrations\Elementor\Tags;

use Elementor\Modules\DynamicTags\Module as Dynamic_Tags_Module;
use TK\Fields\Unset_Value;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Group_Tag extends Base_Field_Tag {

	public function get_name(): string {
		return 'tk-fields-group';
	}

	public function get_title(): string {
		return __( 'TK Group Field', 'tk-fields' );
	}

	public function get_categories(): array {
		return array( Dynamic_Tags_Module::TEXT_CATEGORY );
	}

	protected static function supported_types(): array {
		return array( 'group' );
	}

	protected function render(): void {
		$value = $this->get_field_value();

		if ( Unset_Value::is_unset( $value ) || ! is_array( $value ) ) {
			return;
		}

		$def      = $this->get_field_def();
		$children = is_array( $def ) ? \TK\Fields\Field_Registry::instance()->resolve_group_children( $def ) : array();

		$rows = '';
		foreach ( $value as $short => $child_value ) {
			$child = $children[ (string) $short ] ?? array();
			$label = isset( $child['label'] ) && is_string( $child['label'] ) && '' !== $child['label']
				? $child['label']
				: (string) $short;
			$text  = self::display_text( $child_value );
			if ( '' === $text ) {
				continue;
			}
			$rows .= sprintf(
				'<div class="tkf-elementor-group__row"><span class="tkf-elementor-group__label">%s</span>: <span class="tkf-elementor-group__value">%s</span></div>',
				esc_html( $label ),
				esc_html( $text )
			);
		}

		if ( '' === $rows ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows escaped above.
		echo '<div class="tkf-elementor-group">' . $rows . '</div>';
	}

	/**
	 * Flatten one sub-field value to plain text for tag output.
	 *
	 * @param mixed $value Formatted sub-field value.
	 */
	private static function display_text( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'tk-fields' ) : __( 'No', 'tk-fields' );
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		if ( is_array( $value ) ) {
			$leaves = array();
			array_walk_recursive(
				$value,
				function ( $leaf ) use ( &$leaves ) {
					if ( is_scalar( $leaf ) && '' !== (string) $leaf ) {
						$leaves[] = (string) $leaf;
					}
				}
			);
			return implode( ', ', array_unique( $leaves ) );
		}
		return '';
	}
}
