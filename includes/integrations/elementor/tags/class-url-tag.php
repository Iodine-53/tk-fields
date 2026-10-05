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
 * URL dynamic tag for TK Fields.
 *
 * Serves url fields, link fields (rendering the link's href; the title
 * is available through the TK Text tag) and page_link fields (the
 * formatted page_link value already IS the resolved URL string).
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

class URL_Tag extends Base_Field_Tag {

	public function get_name(): string {
		return 'tk-fields-url';
	}

	public function get_title(): string {
		return __( 'TK URL Field', 'tk-fields' );
	}

	public function get_categories(): array {
		return array( Dynamic_Tags_Module::URL_CATEGORY );
	}

	protected static function supported_types(): array {
		return array( 'url', 'link', 'oembed', 'file', 'page_link' );
	}

	protected function render(): void {
		$value = $this->get_field_value();

		if ( Unset_Value::is_unset( $value ) ) {
			return;
		}

		// Formatted link value is the {url, title, target} array: the URL
		// category wants the href.
		if ( is_array( $value ) ) {
			$value = $value['url'] ?? '';
		}

		$def = $this->get_field_def();
		if ( $def && 'oembed' === $def['type'] ) {
			// The formatted oembed value is embed HTML; the URL category
			// wants the raw stored URL instead.
			$key = $this->get_settings( 'field_key' );
			$value = \TK\Fields\Fields::get( $key, \TK\Fields\Integrations\Elementor\Context::resolve_post_id(), false );
			if ( \TK\Fields\Unset_Value::is_unset( $value ) ) {
				return;
			}
		}

		if ( '' === $value ) {
			return;
		}

		echo esc_url( (string) $value );
	}
}
