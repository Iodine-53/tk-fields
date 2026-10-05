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
 * Server render for tk/repeater-row.
 *
 * Wraps the row's rendered inner blocks with the stable row ID
 * (data-row-id). Never relies on array position.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$row_id = \TK\Fields\Repeater_Row::row_id( $attributes ?? array(), $block ?? null );
?>
<div class="tk-repeater-row" data-row-id="<?php echo esc_attr( $row_id ); ?>">
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $content is rendered inner blocks.
echo $content;
?>
</div>
