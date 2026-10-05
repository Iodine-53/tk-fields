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
 * Date dynamic tag for TK Fields.
 *
 * The formatted value is already localized through date_i18n() with the
 * site's date format; the tag only escapes it.
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

class Date_Tag extends Base_Field_Tag {

	public function get_name(): string {
		return 'tk-fields-date';
	}

	public function get_title(): string {
		return __( 'TK Date Field', 'tk-fields' );
	}

	public function get_categories(): array {
		return array(
			Dynamic_Tags_Module::DATETIME_CATEGORY,
			Dynamic_Tags_Module::TEXT_CATEGORY,
		);
	}

	protected static function supported_types(): array {
		return array( 'date' );
	}

	protected function render(): void {
		$value = $this->get_field_value();

		if ( Unset_Value::is_unset( $value ) ) {
			return;
		}

		echo esc_html( (string) $value );
	}
}
