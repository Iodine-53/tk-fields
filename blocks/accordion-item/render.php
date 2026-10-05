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
 * Server render for tk/accordion-item.
 *
 * Each item carries its own id in context; the item's state.isOpen resolves
 * against the nearest data-wp-interactive ancestor (the tk/accordion root)
 * using the merged context (own {id} + ancestor {allowMultiple, openIds}).
 *
 * Open/close animates via the grid-template-rows 0fr -> 1fr trick on
 * .tk-accordion-panel (no JS measuring, no jump). The chevron icon rotates
 * 180 degrees when the item is open. The panel is inert while closed so
 * collapsed content stays out of the tab order and the accessibility tree.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$item_id = isset( $attributes['itemId'] ) ? (string) $attributes['itemId'] : '';
$title   = isset( $attributes['title'] ) ? (string) $attributes['title'] : '';
?>
<div class="tk-accordion-item" data-wp-context="<?php echo esc_attr( wp_json_encode( array( 'id' => $item_id ) ) ); ?>" data-wp-class--is-open="state.isOpen">
	<button type="button" class="tk-accordion-trigger" data-wp-on--click="actions.toggle" data-wp-bind--aria-expanded="state.isOpen"><span class="tk-accordion-item-title"><?php echo esc_html( $title ); ?></span><span class="tk-accordion-icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M5 7.5 10 12.5 15 7.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span></button>
	<div class="tk-accordion-panel" data-wp-bind--inert="!state.isOpen"><div class="tk-accordion-panel-inner"><div class="tk-accordion-panel-body"><?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div></div></div>
</div>
