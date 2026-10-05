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
 * Server render for tk/scroll-reveal.
 *
 * Duration/delay are emitted as inline styles; animation + threshold as data
 * attributes read by the vanilla view.js IntersectionObserver. One-shot: the
 * .is-visible class is added once and the element is unobserved.
 *
 * @package TK\Fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$allowed = array( 'fade', 'slide-up', 'slide-left', 'slide-right', 'zoom' );
$animation = isset( $attributes['animation'] ) ? (string) $attributes['animation'] : 'slide-up';
if ( ! in_array( $animation, $allowed, true ) ) {
	$animation = 'slide-up';
}

$delay = isset( $attributes['delay'] ) ? max( 0, (int) $attributes['delay'] ) : 0;
$duration = isset( $attributes['duration'] ) ? max( 0, (int) $attributes['duration'] ) : 600;
$threshold = isset( $attributes['threshold'] ) ? (float) $attributes['threshold'] : 0.2;
$threshold = max( 0, min( 1, $threshold ) );

$wrapper = get_block_wrapper_attributes( array( 'class' => 'tk-scroll-reveal' ) );

// NOTE: the transition styles are printed directly (not via the wrapper's
// 'style' arg) because get_block_wrapper_attributes() runs styles through
// safecss_filter_attr(), which strips transition-duration and custom
// properties. The delay maps to --tk-reveal-delay so staggered reveals can
// also set it inline per element.
$inline_style = 'transition-duration:' . $duration . 'ms;--tk-reveal-delay:' . $delay . 'ms;';
?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> style="<?php echo esc_attr( $inline_style ); ?>" data-animation="<?php echo esc_attr( $animation ); ?>" data-threshold="<?php echo esc_attr( (string) $threshold ); ?>">
	<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</div>
