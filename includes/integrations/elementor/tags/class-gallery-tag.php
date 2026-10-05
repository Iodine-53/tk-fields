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
 * Gallery data tag for TK Fields.
 *
 * Returns a numerically-indexed array of ['id' => int, 'url' => string]
 * pairs, or an empty array when unset. Module only registers this tag once
 * the registry actually holds a gallery-type field.
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

class Gallery_Tag extends \Elementor\Core\DynamicTags\Data_Tag {

	public function get_name(): string {
		return 'tk-fields-gallery';
	}

	public function get_title(): string {
		return __( 'TK Gallery Field', 'tk-fields' );
	}

	final public function get_group(): array {
		return array( \TK\Fields\Integrations\Elementor\Module::GROUP );
	}

	public function get_categories(): array {
		return array(
			Dynamic_Tags_Module::GALLERY_CATEGORY,
			Dynamic_Tags_Module::MEDIA_CATEGORY,
		);
	}

	public function get_panel_template_setting_key() {
		return 'field_key';
	}

	protected function register_controls(): void {
		$fields  = \TK\Fields\Field_Registry::instance()->all();
		$options = array();

		foreach ( $fields as $name => $field ) {
			if ( 'gallery' === ( $field['type'] ?? '' ) ) {
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
	 * @return array<int, array{id: int, url: string}>
	 */
	protected function get_value( array $options = [] ) {
		$key = $this->get_settings( 'field_key' );

		if ( empty( $key ) || ! is_string( $key ) ) {
			return array();
		}

		// Raw list of attachment IDs, no formatting.
		$raw = Fields::get( $key, Context::resolve_post_id(), false );

		if ( Unset_Value::is_unset( $raw ) || ! is_array( $raw ) ) {
			return array();
		}

		$items = array();

		foreach ( $raw as $id ) {
			$id = absint( $id );
			if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
				continue;
			}

			$url = wp_get_attachment_url( $id );
			if ( ! $url ) {
				continue;
			}

			$items[] = array(
				'id'  => $id,
				'url' => $url,
			);
		}

		return $items;
	}
}
