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
 * Server render for tk/slider.
 *
 * Counts top-level tk/slide inner blocks, stamps each rendered slide with its
 * index via WP_HTML_Tag_Processor, and emits arrows/dots wired to the
 * tk/slider Interactivity store.
 *
 * Look (TK Studio v1): pill dots with a stretching active dot, circular
 * blurred arrows that reveal on hover (always on on touch), an optional thin
 * autoplay progress bar, and an aspect-ratio-driven track instead of fixed
 * heights. Slides get a subtle bottom scrim for caption legibility.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$autoplay      = ! empty( $attributes['autoplay'] );
$interval      = isset( $attributes['interval'] ) ? max( 100, (int) $attributes['interval'] ) : 5000;
$show_arrows   = $attributes['showArrows'] ?? true;
$show_dots     = $attributes['showDots'] ?? true;
$pause_on_hover = $attributes['pauseOnHover'] ?? true;
$show_progress = $attributes['showProgress'] ?? true;

$transition = isset( $attributes['transition'] ) ? (string) $attributes['transition'] : 'slide';
if ( ! in_array( $transition, array( 'slide', 'fade', 'slide-fade' ), true ) ) {
	$transition = 'slide';
}

$aspect_ratio = isset( $attributes['aspectRatio'] ) ? (string) $attributes['aspectRatio'] : '16/9';
if ( ! in_array( $aspect_ratio, array( '16/9', '4/3', '1/1', '3/2', '21/9' ), true ) ) {
	$aspect_ratio = '16/9';
}

// Count top-level tk/slide inner blocks.
$total = 0;
foreach ( $block->inner_blocks as $inner ) {
	if ( 'tk/slide' === $inner->name ) {
		++$total;
	}
}

// Stamp each rendered slide with its index. Nested contexts merge, so each
// slide's own {"index": i} merges with the root slider context.
$index = 0;
$p     = new \WP_HTML_Tag_Processor( $content );
while ( $p->next_tag( array( 'tag_name' => 'div', 'class_name' => 'tk-slide' ) ) ) {
	$p->set_attribute( 'data-wp-context', wp_json_encode( array( 'index' => $index ) ) );
	++$index;
}
$slides = $p->get_updated_html();

// Arrows: circular blurred buttons with chevron icons.
$arrows = '';
if ( $show_arrows ) {
	$prev_icon = '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M12.5 4.5 7 10l5.5 5.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	$next_icon = '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M7.5 4.5 13 10l-5.5 5.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	$arrows    = '<button type="button" class="tk-slider-arrow tk-slider-prev" data-wp-on--click="actions.prev" aria-label="' . esc_attr__( 'Previous slide', 'tk-fields' ) . '">' . $prev_icon . '</button>'
		. '<button type="button" class="tk-slider-arrow tk-slider-next" data-wp-on--click="actions.next" aria-label="' . esc_attr__( 'Next slide', 'tk-fields' ) . '">' . $next_icon . '</button>';
}

// Dots: one per slide, each with its own index context.
$dots = '';
if ( $show_dots && $total > 0 ) {
	$dots = '<nav class="tk-slider-dots" aria-label="' . esc_attr__( 'Choose slide', 'tk-fields' ) . '">';
	for ( $i = 0; $i < $total; $i++ ) {
		/* translators: %d: slide number */
		$dots .= '<button type="button" data-wp-context="' . esc_attr( wp_json_encode( array( 'index' => $i ) ) ) . '" data-wp-on--click="actions.goTo" data-wp-class--is-active="state.isActiveDot" aria-label="' . esc_attr( sprintf( __( 'Go to slide %1$d', 'tk-fields' ), $i + 1 ) ) . '"></button>';
	}
	$dots .= '</nav>';
}

// Thin autoplay progress bar across the top of the track.
$progress = '';
if ( $autoplay && $show_progress && $total > 1 ) {
	$progress = '<div class="tk-slider-progress" aria-hidden="true"><span class="tk-slider-progress-fill" style="--tk-progress-interval:' . $interval . 'ms"></span></div>';
}

$context = wp_json_encode(
	array(
		'currentSlide' => 0,
		'total'        => $total,
		'autoplay'     => $autoplay,
		'interval'     => $interval,
		'pauseOnHover' => (bool) $pause_on_hover,
	)
);
?>
<div class="tk-slider" data-wp-interactive="tk/slider" data-wp-context='<?php echo $context; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>' data-wp-init="callbacks.init" data-transition="<?php echo esc_attr( $transition ); ?>" data-aspect-ratio="<?php echo esc_attr( $aspect_ratio ); ?>">
	<div class="tk-slides-track"><?php echo $slides; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo $progress; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	<?php echo $arrows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<?php echo $dots; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</div>
