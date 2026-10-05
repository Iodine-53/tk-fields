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
 * Image data tag for TK Fields.
 *
 * Returns the strict ['id' => int, 'url' => string] pair Elementor expects
 * for image controls. Resolves the raw attachment ID (format=false) so no
 * formatted wrapper shape leaks in.
 *
 * @package TK\Fields\Integrations\Elementor\Tags
 */

declare(strict_types=1);

namespace TK\Fields\Integrations\Elementor\Tags;

use Elementor\Modules\DynamicTags\Module as Dynamic_Tags_Module;
use TK\Fields\Fields;
use TK\Fields\Integrations\Elementor\Context;
use TK\Fields\Unset_Value;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Image_Tag extends \Elementor\Core\DynamicTags\Data_Tag {

	public function get_name(): string {
		return 'tk-fields-image';
	}

	public function get_title(): string {
		return __( 'TK Image Field', 'tk-fields' );
	}

	final public function get_group(): array {
		return array( \TK\Fields\Integrations\Elementor\Module::GROUP );
	}

	public function get_categories(): array {
		return array( Dynamic_Tags_Module::IMAGE_CATEGORY );
	}

	public function get_panel_template_setting_key() {
		return 'field_key';
	}

	protected function register_controls(): void {
		$fields  = \TK\Fields\Field_Registry::instance()->all();
		$options = array();

		foreach ( $fields as $name => $field ) {
			if ( 'image' === ( $field['type'] ?? '' ) ) {
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
	 * @return array{id: int, url: string}|null
	 */
	protected function get_value( array $options = [] ) {
		$key = $this->get_settings( 'field_key' );

		if ( empty( $key ) || ! is_string( $key ) ) {
			return null;
		}

		// Raw attachment ID, no formatting.
		$raw = Fields::get( $key, Context::resolve_post_id(), false );

		if ( Unset_Value::is_unset( $raw ) ) {
			return null;
		}

		$id = absint( $raw );
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return null;
		}

		$url = wp_get_attachment_url( $id );
		if ( ! $url ) {
			return null;
		}

		return array(
			'id'  => $id,
			'url' => $url,
		);
	}
}
