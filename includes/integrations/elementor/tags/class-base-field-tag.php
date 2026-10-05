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
 * Base class for TK Fields Elementor dynamic tags.
 *
 * Owns the field-picker control, value resolution through Fields::get(),
 * and the group registration so every concrete tag only declares what
 * field types it serves and how to render them.
 *
 * @package TK\Fields\Integrations\Elementor\Tags
 */

declare(strict_types=1);

namespace TK\Fields\Integrations\Elementor\Tags;

use TK\Fields\Field_Registry;
use TK\Fields\Fields;
use TK\Fields\Integrations\Elementor\Context;
use TK\Fields\Integrations\Elementor\Module;
use TK\Fields\Unset_Value;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Base_Field_Tag extends \Elementor\Core\DynamicTags\Tag {

	final public function get_group(): array {
		return array( Module::GROUP );
	}

	/**
	 * Show the selected field label next to the tag name in the editor panel.
	 */
	public function get_panel_template_setting_key() {
		return 'field_key';
	}

	/**
	 * TK field types this tag serves. The field-picker control is filtered
	 * to these types, and Module gates registration on their presence.
	 *
	 * @return string[]
	 */
	abstract protected static function supported_types(): array;

	protected function register_controls(): void {
		$fields  = Field_Registry::instance()->all();
		$options = array();

		foreach ( $fields as $name => $field ) {
			if ( in_array( $field['type'] ?? '', static::supported_types(), true ) ) {
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
	}

	/**
	 * Resolve the selected field's value (formatted) for the current
	 * Elementor rendering context. Returns Unset_Value::get() when no
	 * field is selected or the field is unknown/unset.
	 *
	 * @return mixed
	 */
	protected function get_field_value(): mixed {
		$key = $this->get_settings( 'field_key' );

		if ( empty( $key ) || ! is_string( $key ) ) {
			return Unset_Value::get();
		}

		return Fields::get( $key, Context::resolve_post_id() );
	}

	/**
	 * Registry definition for the selected field, or null.
	 */
	protected function get_field_def(): ?array {
		$key = $this->get_settings( 'field_key' );

		if ( empty( $key ) || ! is_string( $key ) ) {
			return null;
		}

		return Field_Registry::instance()->get( $key );
	}
}
