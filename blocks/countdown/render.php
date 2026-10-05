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
 * Server render for tk/countdown.
 *
 * Computes the initial remaining time server-side (strtotime($target) - time(),
 * clamped at 0) so the first paint never flashes wrong values; the Interactivity
 * API store then takes over ticking client-side every second.
 *
 * Look (TK Studio v1): unit cards with a soft surface, 10px radius, tabular
 * numerals, semibold 2xl figures and micro uppercase labels. The `layout`
 * attribute switches to a plain inline row; `separator` picks the divider
 * between units (colon/dot/none). `hideOnExpire` hides the whole block once
 * the countdown reaches zero instead of showing the expired message.
 *
 * @package TK\Fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$target = isset( $attributes['target'] ) ? trim( (string) $attributes['target'] ) : '';

// Guard: nothing to count down to, render nothing.
if ( '' === $target ) {
	return '';
}

$now       = time();
$ts        = strtotime( $target );
$remaining = ( false === $ts ) ? 0 : max( 0, $ts - $now );
$expired   = ( false === $ts ) || ( $ts <= $now );

$d = (int) floor( $remaining / 86400 );
$h = (int) floor( ( $remaining % 86400 ) / 3600 );
$m = (int) floor( ( $remaining % 3600 ) / 60 );
$s = (int) ( $remaining % 60 );

$labels = array(
	'days'    => __( 'Days', 'tk-fields' ),
	'hours'   => __( 'Hours', 'tk-fields' ),
	'minutes' => __( 'Minutes', 'tk-fields' ),
	'seconds' => __( 'Seconds', 'tk-fields' ),
);

$units = array(
	'days'    => array( 'attr' => 'showDays', 'value' => $d ),
	'hours'   => array( 'attr' => 'showHours', 'value' => $h ),
	'minutes' => array( 'attr' => 'showMinutes', 'value' => $m ),
	'seconds' => array( 'attr' => 'showSeconds', 'value' => $s ),
);

$layout = isset( $attributes['layout'] ) ? (string) $attributes['layout'] : 'cards';
if ( ! in_array( $layout, array( 'cards', 'inline' ), true ) ) {
	$layout = 'cards';
}

$separator = isset( $attributes['separator'] ) ? (string) $attributes['separator'] : 'colon';
$separators = array(
	'colon' => ':',
	'dot'   => "\u{00B7}", // middot
	'none'  => null,
);
if ( ! array_key_exists( $separator, $separators ) ) {
	$separator = 'colon';
}
$sep_char = $separators[ $separator ];

$hide_on_expire = ! empty( $attributes['hideOnExpire'] );

$expired_text = isset( $attributes['expiredText'] ) ? (string) $attributes['expiredText'] : 'Expired';

/*
 * Unit values live in per-instance context (padded strings) and the markup
 * binds to `context.*` directly. Server-side directive processing evaluates
 * these against the element's own data-wp-context, so SSR keeps the
 * pre-filled values. State getters calling getContext() were removed: they
 * evaluate outside directive context and throw on the client.
 */
$pad = static function ( $n ) {
	return str_pad( (string) $n, 2, '0', STR_PAD_LEFT );
};

$context = array(
	'target'       => $target,
	'expired'      => $expired,
	'hideOnExpire' => $hide_on_expire,
	'days'         => $pad( $d ),
	'hours'        => $pad( $h ),
	'minutes'      => $pad( $m ),
	'seconds'      => $pad( $s ),
);

$wrapper = get_block_wrapper_attributes( array( 'class' => 'tk-countdown' ) );
?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> data-wp-interactive="tk/countdown" data-wp-context='<?php echo esc_attr( wp_json_encode( $context ) ); ?>' data-wp-init="callbacks.start" data-layout="<?php echo esc_attr( $layout ); ?>" data-wp-bind--hidden="context.hideOnExpire && context.expired"<?php echo ( $expired && $hide_on_expire ) ? ' hidden' : ''; ?>>
	<div class="tk-cd-units" data-wp-bind--hidden="context.expired"<?php echo $expired ? ' hidden' : ''; ?>>
		<?php
		$first = true;
		foreach ( $units as $unit => $def ) {
			if ( empty( $attributes[ $def['attr'] ] ) ) {
				continue;
			}
			if ( ! $first && null !== $sep_char ) {
				echo '<span class="tk-cd-sep" aria-hidden="true">' . esc_html( $sep_char ) . '</span>';
			}
			$first = false;
			printf(
				'<span class="tk-cd-unit"><span class="tk-cd-%1$s" data-wp-text="context.%1$s">%2$s</span><span class="tk-cd-label">%3$s</span></span>',
				esc_attr( $unit ),
				esc_html( $pad( $def['value'] ) ),
				esc_html( $labels[ $unit ] )
			);
		}
		?>
	</div>
	<div class="tk-cd-expired" data-wp-bind--hidden="!context.expired"<?php echo $expired ? '' : ' hidden'; ?>>
		<?php echo esc_html( $expired_text ); ?>
	</div>
</div>
