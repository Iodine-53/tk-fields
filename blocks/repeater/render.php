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
 * Server render for tk/repeater.
 *
 * Dynamic block: rows arrive already rendered in $content (each row through
 * its own render.php). We only add the wrapper, layout class, and the
 * field-name data attribute that the PHP row API matches on.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$layout = $attributes['layout'] ?? 'list';
if ( ! in_array( $layout, array( 'list', 'grid' ), true ) ) {
	$layout = 'list';
}

$field_name = isset( $attributes['fieldName'] ) ? (string) $attributes['fieldName'] : '';
?>
<div class="tk-repeater tk-repeater--<?php echo esc_attr( $layout ); ?>" data-field-name="<?php echo esc_attr( $field_name ); ?>">
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $content is rendered inner blocks.
echo $content;
?>
</div>
