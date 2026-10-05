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
 * Text dynamic tag for TK Fields.
 *
 * Serves text, textarea, email, password, select, radio, button_group,
 * color, datetime, time, icon, oembed, and wysiwyg field types. Select/radio/
 * button_group values are rendered as their choice labels; textarea values
 * pass through wp_kses_post so stored line breaks and basic markup survive;
 * oembed renders the embed HTML, icon the dashicons span, and wysiwyg the
 * stored HTML (all built from escaped or save-sanitized values, safe to
 * echo); everything else is escaped. Link fields render their title
 * (falling back to the URL) as plain text — use the TK URL tag when the
 * href itself is needed.
 *
 * Batch A relational types: post_object renders the post title; taxonomy
 * renders term names joined with ", "; user renders the display name
 * (multiple: display names joined with ", "); relationship renders related
 * post titles joined with ", ".
 *
 * Map renders the stored address, falling back to "lat,lng" when the
 * address is empty.
 *
 * @package TK\Fields\Integrations\Elementor\Tags
 */

declare(strict_types=1);

namespace TK\Fields\Integrations\Elementor\Tags;

use Elementor\Modules\DynamicTags\Module as Dynamic_Tags_Module;
use TK\Fields\Unset_Value;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Text_Tag extends Base_Field_Tag {

	public function get_name(): string {
		return 'tk-fields-text';
	}

	public function get_title(): string {
		return __( 'TK Text Field', 'tk-fields' );
	}

	public function get_categories(): array {
		return array( Dynamic_Tags_Module::TEXT_CATEGORY );
	}

	protected static function supported_types(): array {
		return array( 'text', 'textarea', 'email', 'password', 'select', 'radio', 'button_group', 'color', 'datetime', 'time', 'icon', 'oembed', 'link', 'post_object', 'taxonomy', 'user', 'relationship', 'wysiwyg', 'map', 'flexible_content' );
	}

	protected function render(): void {
		$value = $this->get_field_value();

		if ( Unset_Value::is_unset( $value ) ) {
			return;
		}

		$def = $this->get_field_def();

		if ( $def && 'link' === $def['type'] ) {
			// Formatted link value is the {url, title, target} array: show
			// the human title, falling back to the URL.
			$label = '';
			if ( is_array( $value ) ) {
				$label = '' !== ( $value['title'] ?? '' ) ? (string) $value['title'] : (string) ( $value['url'] ?? '' );
			}
			echo esc_html( $label );

			return;
		}

		if ( $def && 'flexible_content' === $def['type'] ) {
			// Formatted flexible content is the ordered rows array
			// [{layout, id, layout_label, fields}]: output the rendered
			// layouts HTML (rows escaped inside render_rows_html()).
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo \TK\Fields\Flexible_Content::render_rows_html( is_array( $value ) ? $value : array() );

			return;
		}

		if ( $def && 'post_object' === $def['type'] ) {
			// Formatted post_object is the {id, title, url, post_type} DTO
			// (or null when the post is gone): the TEXT category wants the
			// human title.
			echo esc_html( is_array( $value ) ? (string) ( $value['title'] ?? '' ) : '' );

			return;
		}

		if ( $def && 'taxonomy' === $def['type'] ) {
			// Formatted taxonomy is an array of term DTOs: join the names.
			$names = array();
			if ( is_array( $value ) ) {
				foreach ( $value as $term ) {
					if ( is_array( $term ) && isset( $term['name'] ) ) {
						$names[] = (string) $term['name'];
					}
				}
			}
			echo esc_html( implode( ', ', $names ) );

			return;
		}

		if ( $def && 'user' === $def['type'] ) {
			// Formatted user is one DTO, or an array of DTOs when multiple
			// is on: the TEXT category wants display name(s).
			if ( ! empty( $def['multiple'] ) ) {
				$names = array();
				if ( is_array( $value ) ) {
					foreach ( $value as $user ) {
						if ( is_array( $user ) && isset( $user['display_name'] ) ) {
							$names[] = (string) $user['display_name'];
						}
					}
				}
				echo esc_html( implode( ', ', $names ) );
			} else {
				echo esc_html( is_array( $value ) ? (string) ( $value['display_name'] ?? '' ) : '' );
			}

			return;
		}

		if ( $def && 'relationship' === $def['type'] ) {
			// Formatted relationship is an array of post DTOs: join the
			// titles in the stored selection order.
			$titles = array();
			if ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					if ( is_array( $item ) && isset( $item['title'] ) ) {
						$titles[] = (string) $item['title'];
					}
				}
			}
			echo esc_html( implode( ', ', $titles ) );

			return;
		}

		if ( $def && 'map' === $def['type'] ) {
			// Formatted map is the canonical {lat, lng, zoom, address}
			// array: the TEXT category wants the human address, falling
			// back to "lat,lng" when no address is stored. Never a
			// geocoder call — the stored denormalized values only.
			$address = '';
			$lat     = null;
			$lng     = null;
			if ( is_array( $value ) ) {
				$address = (string) ( $value['address'] ?? '' );
				$lat     = $value['lat'] ?? null;
				$lng     = $value['lng'] ?? null;
			}
			if ( '' !== $address ) {
				echo esc_html( $address );
			} elseif ( null !== $lat && null !== $lng ) {
				echo esc_html( $lat . ',' . $lng );
			}

			return;
		}

		if ( $def && in_array( $def['type'], array( 'select', 'radio', 'button_group' ), true ) ) {
			$choices = $def['choices'] ?? array();
			$value   = $choices[ (string) $value ] ?? $value;
		}

		if ( $def && in_array( $def['type'], array( 'oembed', 'icon', 'wysiwyg' ), true ) ) {
			// Formatted oembed is provider embed HTML and icon is a
			// dashicons span — both built from escaped values in
			// Field_Registry::format(), safe to echo directly. Wysiwyg is
			// the stored HTML: wp_kses_post-filtered at save time, or raw
			// by explicit admin opt-in (allow_unfiltered).
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo (string) $value;
			return;
		}

		if ( $def && 'textarea' === $def['type'] ) {
			echo wp_kses_post( (string) $value );
			return;
		}

		echo esc_html( (string) $value );
	}
}
