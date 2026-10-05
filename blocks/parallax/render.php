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
 * Server render for tk/parallax.
 *
 * Markup contract (kept framework-agnostic so the phase-3 Elementor adapter
 * can reuse it): .tk-parallax > .tk-parallax-bg[data-speed] +
 * .tk-parallax-overlay + .tk-parallax-content. The vanilla view.js only reads
 * the DOM contract — no WP JS runtime involved.
 *
 * The overlay (none|bottom|full scrim) keeps foreground text legible; its
 * opacity is adjustable. disableOnMobile (default on) degrades to a static
 * cover background on narrow screens.
 *
 * @package TK\Fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$speed = isset( $attributes['speed'] ) ? (float) $attributes['speed'] : 0.35;
$speed = max( 0, min( 1, $speed ) );

$min_height = isset( $attributes['minHeight'] ) ? (int) $attributes['minHeight'] : 400;
$min_height = max( 0, $min_height );

$image_url = isset( $attributes['imageUrl'] ) ? (string) $attributes['imageUrl'] : '';

$overlay = isset( $attributes['overlay'] ) ? (string) $attributes['overlay'] : 'bottom';
if ( ! in_array( $overlay, array( 'none', 'bottom', 'full' ), true ) ) {
	$overlay = 'bottom';
}

$overlay_opacity = isset( $attributes['overlayOpacity'] ) ? (float) $attributes['overlayOpacity'] : 0.5;
$overlay_opacity = max( 0, min( 1, $overlay_opacity ) );

$disable_mobile = $attributes['disableOnMobile'] ?? true;

$wrapper = get_block_wrapper_attributes(
	array(
		'class' => 'tk-parallax tk-parallax--overlay-' . $overlay,
		'style' => 'min-height:' . $min_height . 'px;',
	)
);
?>
<section <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="tk-parallax-bg" data-speed="<?php echo esc_attr( (string) $speed ); ?>" data-disable-mobile="<?php echo $disable_mobile ? '1' : '0'; ?>"<?php echo '' !== $image_url ? ' style="background-image:url(' . esc_url( $image_url ) . ')"' : ''; ?>></div>
	<?php if ( 'none' !== $overlay ) : ?>
	<div class="tk-parallax-overlay" aria-hidden="true" style="opacity:<?php echo esc_attr( (string) $overlay_opacity ); ?>"></div>
	<?php endif; ?>
	<div class="tk-parallax-content">
		<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
</section>
