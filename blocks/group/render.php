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
 * Server render for tk/group.
 *
 * Displays a group field as a <fieldset>: the legend is the group label
 * and each row is one sub-field's label + formatted value. Values resolve
 * through the Fields service only — never synced into attributes. Empty
 * (unset) sub-fields are skipped; a fully empty group renders nothing.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$field_name = isset( $attributes['fieldName'] ) ? (string) $attributes['fieldName'] : '';
if ( '' === $field_name ) {
	return;
}

$registry = \TK\Fields\Field_Registry::instance();
$field    = $registry->get( $field_name );
if ( null === $field || 'group' !== ( $field['type'] ?? '' ) ) {
	return;
}

$post_id = isset( $block->context['postId'] ) ? (int) $block->context['postId'] : (int) get_the_ID();
if ( $post_id <= 0 ) {
	return;
}

$values = \TK\Fields\Fields::get( $field_name, $post_id, true );
if ( \TK\Fields\Unset_Value::is_unset( $values ) || ! is_array( $values ) ) {
	return;
}

if ( ! function_exists( 'tk_group_display_value' ) ) {
	/**
	 * Type-aware, fully escaped display markup for one sub-field value.
	 *
	 * @param array $child Sub-field definition.
	 * @param mixed $value Formatted value.
	 * @return string HTML (already escaped).
	 */
	function tk_group_display_value( $child, $value ) {
		$type = (string) ( $child['type'] ?? 'text' );

		if ( is_bool( $value ) ) {
			return $value ? esc_html__( 'Yes', 'tk-fields' ) : esc_html__( 'No', 'tk-fields' );
		}

		if ( is_array( $value ) ) {
			switch ( $type ) {
				case 'link':
					$url   = (string) ( $value['url'] ?? '' );
					$title = '' !== ( $value['title'] ?? '' ) ? (string) $value['title'] : $url;
					if ( '' === $url ) {
						return '';
					}
					$target = '_blank' === ( $value['target'] ?? '' ) ? ' target="_blank" rel="noopener"' : '';
					return sprintf( '<a href="%s"%s>%s</a>', esc_url( $url ), $target, esc_html( $title ) );
				case 'image':
					$url = (string) ( $value['url'] ?? '' );
					if ( '' === $url ) {
						return '';
					}
					return sprintf(
						'<img src="%s" alt="%s" />',
						esc_url( $url ),
						esc_attr( (string) ( $value['alt'] ?? '' ) )
					);
				case 'gallery':
					$out = '';
					foreach ( $value as $id ) {
						$id = absint( $id );
						if ( ! $id ) {
							continue;
						}
						$src = wp_get_attachment_image_src( $id, 'thumbnail' );
						if ( ! $src ) {
							continue;
						}
						$out .= sprintf( '<img src="%s" alt="" />', esc_url( $src[0] ) );
					}
					return '' !== $out ? '<span class="tkf-group__gallery">' . $out . '</span>' : '';
				case 'map':
					return esc_html( (string) ( $value['address'] ?? '' ) );
				default:
					// Any other array (taxonomy/user/relationship lists,
					// page_link discriminators): join the scalar leaves.
					$leaves = array();
					array_walk_recursive(
						$value,
						function ( $leaf ) use ( &$leaves ) {
							if ( is_scalar( $leaf ) && '' !== (string) $leaf ) {
								$leaves[] = (string) $leaf;
							}
						}
					);
					return esc_html( implode( ', ', array_unique( $leaves ) ) );
			}
		}

		if ( 'wysiwyg' === $type ) {
			// Already wp_kses_post-filtered on every save (or raw by
			// explicit admin opt-in): render as authored.
			return wp_kses_post( (string) $value );
		}

		return esc_html( (string) $value );
	}
}

$children = $registry->resolve_group_children( $field );
$label    = isset( $field['label'] ) && is_string( $field['label'] ) && '' !== $field['label']
	? $field['label']
	: $field_name;

$rows = '';
foreach ( $children as $short => $child ) {
	if ( ! array_key_exists( $short, $values ) ) {
		continue;
	}
	$html = tk_group_display_value( $child, $values[ $short ] );
	if ( '' === $html ) {
		continue;
	}
	$child_label = isset( $child['label'] ) && is_string( $child['label'] ) && '' !== $child['label']
		? $child['label']
		: $short;
	$rows .= sprintf(
		'<div class="tkf-group__row"><dt class="tkf-group__term">%s</dt><dd class="tkf-group__value">%s</dd></div>',
		esc_html( $child_label ),
		$html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in tk_group_display_value().
	);
}

if ( '' === $rows ) {
	return;
}

$wrapper = get_block_wrapper_attributes( array( 'class' => 'tkf-group' ) );
printf(
	'<fieldset %s><legend class="tkf-group__legend">%s</legend><dl class="tkf-group__list">%s</dl></fieldset>',
	$wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes.
	esc_html( $label ),
	$rows // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows escaped above.
);
