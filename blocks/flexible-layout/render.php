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
 * Server render for tk/flexible-layout.
 *
 * Wraps the already-rendered tk/field-value sub-fields in the row markup.
 * The layout label comes from the field definition when resolvable (via
 * the parent context's fieldName); otherwise the layout key is used.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$layout    = isset( $attributes['layout'] ) ? (string) $attributes['layout'] : '';
$layout_id = isset( $attributes['layoutId'] ) ? (string) $attributes['layoutId'] : '';

printf(
	'<div class="tk-flexible-row tk-flexible-row--%1$s" data-layout="%1$s" data-layout-id="%2$s">%3$s</div>',
	esc_attr( $layout ),
	esc_attr( $layout_id ),
	// $content is the rendered sub-field blocks, escaped by their own templates.
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	$content
);
