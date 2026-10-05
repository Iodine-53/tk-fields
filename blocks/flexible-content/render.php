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
 * Server render for tk/flexible-content.
 *
 * The inner tk/flexible-layout blocks render themselves (each wraps its
 * rendered tk/field-value sub-fields in the row markup); this template
 * only adds the field wrapper. The formatted-rows read path
 * (tk_get_field() with format=true, Elementor) uses
 * Flexible_Content::render_rows_html() instead.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$field_name = isset( $attributes['fieldName'] ) ? (string) $attributes['fieldName'] : '';

printf(
	'<div class="tk-flexible-content" data-field="%s">%s</div>',
	esc_attr( $field_name ),
	// $content is the rendered inner layout blocks, escaped by their own templates.
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	$content
);
