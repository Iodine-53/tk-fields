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
 * Server render for tk/accordion.
 *
 * Instance state lives in context (openIds), never global state. When
 * openFirst is set, the first inner item's itemId seeds openIds.
 *
 * Visual style is driven by the `variant` attribute (card|minimal|filled)
 * and `iconPosition` (left|right), emitted as modifier classes on the root.
 * The tk/accordion-item children read these purely via CSS descendant
 * selectors — no extra context needed.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$allow_multiple = $attributes['allowMultiple'] ?? true;
$open_first     = ! empty( $attributes['openFirst'] );

$variant = isset( $attributes['variant'] ) ? (string) $attributes['variant'] : 'card';
if ( ! in_array( $variant, array( 'card', 'minimal', 'filled' ), true ) ) {
	$variant = 'card';
}

$icon_position = isset( $attributes['iconPosition'] ) ? (string) $attributes['iconPosition'] : 'right';
if ( ! in_array( $icon_position, array( 'left', 'right' ), true ) ) {
	$icon_position = 'right';
}

// Seed openIds with the first item's id when openFirst is set.
$open_ids = array();
if ( $open_first && ! empty( $block->inner_blocks ) ) {
	$first = $block->inner_blocks[0];
	if ( 'tk/accordion-item' === $first->name && ! empty( $first->attributes['itemId'] ) ) {
		$open_ids[] = (string) $first->attributes['itemId'];
	}
}

$classes = 'tk-accordion tk-accordion--style-' . $variant;
if ( 'left' === $icon_position ) {
	$classes .= ' tk-accordion--icon-left';
}

$context = wp_json_encode(
	array(
		'allowMultiple' => (bool) $allow_multiple,
		'openIds'       => $open_ids,
	)
);
?>
<div class="<?php echo esc_attr( $classes ); ?>" data-wp-interactive="tk/accordion" data-wp-context='<?php echo $context; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>'><?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
