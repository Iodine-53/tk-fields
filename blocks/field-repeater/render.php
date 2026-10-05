<?php
/*
 * This file is part of TK Fields.
 *
 * TK Fields is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License v2 or later.
 * See LICENSE in the plugin root for the full license text.
 */
/**
 * Server render for tk/field-repeater.
 *
 * Dynamic block: rows arrive already rendered in $content (each
 * tk/repeater-row through its own render.php, carrying its stable
 * data-row-id). This template only adds the field wrapper, the layout
 * class, and the field identity attributes the read path matches on.
 *
 * Read-path contract (Fields service): the wrapper is located by its
 * fieldName attribute; rows are its direct tk/repeater-row children in
 * document order; row identity is the rowId attribute (never the index);
 * scalar sub-fields are tk/field-value children keyed by short sub-field
 * name; a nested repeater is a tk/field-repeater child of a row, resolved
 * recursively — the tree is the path stack, nothing is flattened.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$layout = $attributes['layout'] ?? 'list';
if ( ! in_array( $layout, array( 'list', 'grid' ), true ) ) {
	$layout = 'list';
}

$field_name = isset( $attributes['fieldName'] ) ? (string) $attributes['fieldName'] : '';
$field_key  = isset( $attributes['fieldKey'] ) ? (string) $attributes['fieldKey'] : '';
?>
<div class="tk-field-repeater tk-field-repeater--<?php echo esc_attr( $layout ); ?>" data-field="<?php echo esc_attr( $field_name ); ?>" data-field-key="<?php echo esc_attr( $field_key ); ?>">
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $content is rendered inner blocks.
echo $content;
?>
</div>
