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
 * Server render for tk/field-value.
 *
 * Displays the sub-field value with type-appropriate markup. Everything is
 * escaped; the raw value stays in the block attribute for tk_get_sub_field().
 *
 * @package TK\Fields
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$field_name = isset( $attributes['fieldName'] ) ? (string) $attributes['fieldName'] : '';
$field_type = isset( $attributes['fieldType'] ) ? (string) $attributes['fieldType'] : 'text';
$value      = $attributes['value'] ?? null;

if ( null === $value || '' === $value || array() === $value ) {
	return;
}

if ( ! function_exists( 'tk_field_value_render_relational' ) ) {
	/**
	 * Render the Batch A relational types. Everything is escaped.
	 *
	 * @return string|null Markup, or null when there is nothing to show.
	 */
	function tk_field_value_render_relational( $field_type, $value ) {
		switch ( $field_type ) {
			case 'post_object': {
				$post = get_post( (int) $value );
				if ( ! $post ) {
					return null;
				}
				return sprintf(
					'<a href="%s">%s</a>',
					esc_url( (string) get_permalink( $post ) ),
					esc_html( get_the_title( $post ) )
				);
			}

			case 'page_link': {
				if ( ! is_array( $value ) ) {
					return null;
				}
				$kind = (string) ( $value['kind'] ?? '' );
				if ( 'post' === $kind ) {
					$post = get_post( (int) ( $value['id'] ?? 0 ) );
					if ( ! $post ) {
						return null;
					}
					$url   = (string) get_permalink( $post );
					$label = get_the_title( $post );
				} elseif ( 'url' === $kind ) {
					$url = (string) ( $value['value'] ?? '' );
					if ( '' === $url ) {
						return null;
					}
					$label = $url;
				} else {
					// 'term' kind is PHP/API-only in v1; the block UI never stores it.
					return null;
				}
				return sprintf(
					'<a href="%s">%s</a>',
					esc_url( $url ),
					esc_html( $label )
				);
			}

			case 'taxonomy': {
				$links = array();
				foreach ( (array) $value as $term_id ) {
					$term = get_term( (int) $term_id );
					if ( ! $term || is_wp_error( $term ) ) {
						continue;
					}
					$term_link = get_term_link( $term );
					if ( is_wp_error( $term_link ) ) {
						continue;
					}
					$links[] = sprintf(
						'<a href="%s">%s</a>',
						esc_url( $term_link ),
						esc_html( $term->name )
					);
				}
				if ( ! $links ) {
					return null;
				}
				return implode( ', ', $links );
			}

			case 'user': {
				$ids   = is_array( $value ) ? $value : array( $value );
				$names = array();
				foreach ( $ids as $user_id ) {
					$user = get_userdata( (int) $user_id );
					if ( ! $user ) {
						continue;
					}
					// Display name only: never leak email addresses on the frontend.
					$names[] = esc_html( $user->display_name );
				}
				if ( ! $names ) {
					return null;
				}
				return implode( ', ', $names );
			}

			case 'relationship': {
				$items = array();
				foreach ( (array) $value as $post_id ) {
					$post = get_post( (int) $post_id );
					if ( ! $post ) {
						continue;
					}
					$items[] = sprintf(
						'<li><a href="%s">%s</a></li>',
						esc_url( (string) get_permalink( $post ) ),
						esc_html( get_the_title( $post ) )
					);
				}
				if ( ! $items ) {
					return null;
				}
				return '<ul class="tk-field-value-relationship">' . implode( '', $items ) . '</ul>';
			}

			case 'gallery': {
				$images = array();
				foreach ( (array) $value as $attachment_id ) {
					$image = wp_get_attachment_image( (int) $attachment_id, 'large' );
					if ( ! $image ) {
						continue;
					}
					$images[] = $image;
				}
				if ( ! $images ) {
					return null;
				}
				return '<figure class="tk-field-value-gallery">' . implode( '', $images ) . '</figure>';
			}

			default:
				return null;
		}
	}
}

// Batch A relational types render before the generic array branch below,
// which would otherwise JSON-dump their values.
if ( in_array( $field_type, array( 'post_object', 'page_link', 'taxonomy', 'user', 'relationship', 'gallery' ), true ) ) {
	$inner = tk_field_value_render_relational( $field_type, $value );
	if ( null === $inner ) {
		return;
	}
} elseif ( 'wysiwyg' === $field_type ) {
	// Never trust the raw block attribute: block-comment JSON is
	// attacker-controllable post content (contributors/authors can
	// hand-craft it in the code editor), and the save-time wp_kses_post
	// never ran on this input path. Resolve the field through the
	// registry and sanitize the value with the REGISTERED field's own
	// sanitizer — which switches on the registry type, so a type
	// mismatch in the attributes (e.g. claiming wysiwyg for a
	// select field) still gets the right sanitizer.
	$wysiwyg_field = \TK\Fields\Field_Registry::instance()->get( $field_name );
	if ( null === $wysiwyg_field || 'wysiwyg' !== ( $wysiwyg_field['type'] ?? '' ) ) {
		// Unknown field or type mismatch: refuse to render rather than
		// risk raw HTML on the frontend.
		return;
	}
	$inner = (string) \TK\Fields\Field_Registry::instance()->sanitize( $wysiwyg_field, $value );
} elseif ( 'link' === $field_type ) {
	$url    = is_array( $value ) ? (string) ( $value['url'] ?? '' ) : (string) $value;
	$title  = is_array( $value ) && '' !== (string) ( $value['title'] ?? '' ) ? (string) $value['title'] : $url;
	$target = is_array( $value ) && '_blank' === ( $value['target'] ?? '' ) ? ' target="_blank" rel="noopener"' : '';
	if ( '' === $url ) {
		return;
	}
	$inner = sprintf(
		'<a href="%s"%s>%s</a>',
		esc_url( $url ),
		$target,
		esc_html( $title )
	);
} elseif ( 'flexible_content' === $field_type ) {
	// Rows are the canonical {layout, id, fields} shape (the attr is
	// written by the flexible block itself). Render the layouts HTML;
	// sub-field values are escaped inside render_rows_html().
	$flex_rows = array();
	foreach ( (array) $value as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$flex_rows[] = array(
			'layout' => (string) ( $row['layout'] ?? '' ),
			'id'     => (string) ( $row['id'] ?? '' ),
			'fields' => isset( $row['fields'] ) && is_array( $row['fields'] ) ? $row['fields'] : array(),
		);
	}
	$inner = \TK\Fields\Flexible_Content::render_rows_html( $flex_rows );
} elseif ( 'clone' === $field_type ) {
	// The value is the fan-in keyed array (short child name => value).
	// Render a plain definition list; both names and values escaped.
	if ( ! is_array( $value ) ) {
		return;
	}
	$clone_items = '';
	foreach ( $value as $child_name => $child_value ) {
		$clone_items .= sprintf(
			'<div class="tk-field-value-clone__item"><dt>%s</dt><dd>%s</dd></div>',
			esc_html( (string) $child_name ),
			is_array( $child_value ) || is_object( $child_value )
				? esc_html( wp_json_encode( $child_value ) )
				: esc_html( (string) $child_value )
		);
	}
	$inner = '<dl class="tk-field-value-clone">' . $clone_items . '</dl>';
} elseif ( 'map' === $field_type ) {
	// Map renders the stored denormalized address, falling back to
	// "lat,lng" when no address is stored. NEVER reverse-geocoded:
	// zero live external dependency at render is a hard rule.
	$map_address = is_array( $value ) ? trim( (string) ( $value['address'] ?? '' ) ) : '';
	if ( '' !== $map_address ) {
		$inner = esc_html( $map_address );
	} elseif ( is_array( $value ) && isset( $value['lat'], $value['lng'] ) && is_numeric( $value['lat'] ) && is_numeric( $value['lng'] ) ) {
		$inner = esc_html( $value['lat'] . ',' . $value['lng'] );
	} else {
		return;
	}
} elseif ( is_array( $value ) || is_object( $value ) ) {
	if ( 'image' !== $field_type ) {
		$inner = esc_html( wp_json_encode( $value ) );
	} else {
		return;
	}
} else {
	switch ( $field_type ) {
	case 'textarea':
		$inner = nl2br( esc_html( (string) $value ) );
		break;

	case 'number':
	case 'range':
	case 'date':
	case 'text':
	case 'password':
	case 'color':
		$inner = esc_html( (string) $value );
		break;

	case 'datetime': {
		$timestamp = strtotime( (string) $value );
		$inner     = false !== $timestamp
			? esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) )
			: esc_html( (string) $value );
		break;
	}

	case 'time': {
		$timestamp = strtotime( (string) $value );
		$inner     = false !== $timestamp
			? esc_html( date_i18n( (string) get_option( 'time_format' ), $timestamp ) )
			: esc_html( (string) $value );
		break;
	}

	case 'oembed': {
		// Embed HTML via core's cached shortcode path (see
		// Field_Registry::oembed_html()); falls back to a plain link
		// when no provider matches.
		$post_id = isset( $block->context['postId'] ) ? (int) $block->context['postId'] : null;
		$inner   = \TK\Fields\Field_Registry::oembed_html( (string) $value, $post_id );
		break;
	}

	case 'icon':
		$inner = sprintf(
			'<span class="dashicons dashicons-%s"></span>',
			esc_attr( (string) $value )
			);
		break;

	case 'file': {
		$file_url = wp_get_attachment_url( (int) $value );
		if ( ! $file_url ) {
			return;
		}
		$inner = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $file_url ),
			esc_html( get_the_title( (int) $value ) ?: basename( (string) wp_parse_url( $file_url, PHP_URL_PATH ) ) )
			);
		break;
	}

	case 'email':
		$inner = sprintf(
			'<a href="%s">%s</a>',
			esc_url( 'mailto:' . (string) $value ),
			esc_html( (string) $value )
		);
		break;

	case 'url':
		$inner = sprintf(
			'<a href="%s">%s</a>',
			esc_url( (string) $value ),
			esc_html( (string) $value )
		);
		break;

	case 'checkbox':
		$inner = $value
			? esc_html__( 'Yes', 'tk-fields' )
			: esc_html__( 'No', 'tk-fields' );
		break;

	case 'select':
	case 'radio':
	case 'button_group':
		$inner = esc_html( \TK\Fields\Repeater_Row::select_label( (string) ( $attributes['options'] ?? '' ), (string) $value ) );
		break;

	case 'image':
		$image = wp_get_attachment_image( (int) $value, 'large' );
		if ( ! $image ) {
			return;
		}
		$inner = $image;
		break;

	default:
		$inner = esc_html( (string) $value );
	}
}
?>
<span class="tk-field-value tk-field-value--<?php echo esc_attr( $field_type ); ?>" data-field-name="<?php echo esc_attr( $field_name ); ?>"><?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $inner is fully escaped/built above.
echo $inner;
?></span>
