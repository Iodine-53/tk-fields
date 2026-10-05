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
 * Shared post-listing renderer (v0.16.2).
 *
 * One source of truth for the listing markup, extracted from the
 * Elementor Post Listing widget (v0.16.0/0.16.1) so the new Gutenberg
 * tk/post-listing block renders byte-identical cards. Both consumers
 * pass a plain settings array (same key names as the Elementor widget
 * controls); block attributes are mapped to those keys in
 * blocks/post-listing/render.php.
 *
 * Every card value resolves through \TK\Fields\Fields — never postmeta.
 * Pagination is plain numbered prev/next (no AJAX — deferred, see docs).
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a designed post listing (grid/list cards) from a settings array.
 */
final class Post_Listing_Renderer {

	/**
	 * Render the complete listing: wrapper, cards, pagination.
	 *
	 * @param array<string, mixed> $settings Widget/block settings (sanitized internally).
	 * @param int                  $paged    Current page (1 inside editors).
	 */
	public static function render( array $settings, int $paged = 1 ): void {
		$settings = self::sanitize( $settings );
		$query    = self::build_query( $settings, $paged );

		if ( ! $query->have_posts() ) {
			self::render_empty( $settings['empty_text'] );
			return;
		}

		self::render_open( $settings );

		while ( $query->have_posts() ) {
			$query->the_post();
			$post = get_post();
			if ( $post instanceof \WP_Post ) {
				self::render_card( $post, $settings );
			}
		}

		echo '</div>';

		self::render_pagination( $query, $settings, $paged );

		wp_reset_postdata();
	}

	/**
	 * Normalize a raw settings array: booleans accept true/'yes'/'1',
	 * numbers are clamped, enums fall back to defaults.
	 *
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $settings ): array {
		$bool = static function ( $v ): bool {
			return true === $v || 1 === $v || 'yes' === $v || '1' === $v || 'true' === $v;
		};

		$post_type = sanitize_key( (string) ( $settings['post_type'] ?? 'post' ) );
		$public    = get_post_types( array( 'public' => true ), 'names' );
		if ( ! in_array( $post_type, $public, true ) ) {
			$post_type = 'post';
		}

		$orderby = sanitize_key( (string) ( $settings['orderby'] ?? 'date' ) );
		if ( ! in_array( $orderby, array( 'date', 'modified', 'title', 'name', 'menu_order', 'comment_count', 'rand' ), true ) ) {
			$orderby = 'date';
		}

		$order = strtoupper( (string) ( $settings['order'] ?? 'DESC' ) );
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}

		$layout = (string) ( $settings['layout'] ?? 'grid' );
		if ( ! in_array( $layout, array( 'grid', 'list' ), true ) ) {
			$layout = 'grid';
		}

		$image_ratio = (string) ( $settings['image_ratio'] ?? '16/9' );
		if ( ! in_array( $image_ratio, array( '16/9', '4/3', '3/2', '1/1', 'auto' ), true ) ) {
			$image_ratio = '16/9';
		}

		$card_fields = array();
		$rows        = $settings['card_fields'] ?? array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$key = sanitize_key( (string) ( $row['field_key'] ?? '' ) );
				if ( '' === $key ) {
					continue;
				}
				$card_fields[] = array(
					'field_key'   => $key,
					'field_label' => sanitize_text_field( (string) ( $row['field_label'] ?? '' ) ),
				);
			}
		}

		$color = static function ( $v ): string {
			$v = trim( (string) $v );
			if ( '' === $v ) {
				return '';
			}
			$hex = sanitize_hex_color( $v );
			return is_string( $hex ) ? $hex : '';
		};

		return array(
			'post_type'       => $post_type,
			'posts_per_page'  => max( 1, min( 100, absint( $settings['posts_per_page'] ?? 6 ) ) ),
			'orderby'         => $orderby,
			'order'           => $order,
			'offset'          => absint( $settings['offset'] ?? 0 ),
			'exclude_current' => $bool( $settings['exclude_current'] ?? false ),
			'layout'          => $layout,
			'columns'         => max( 1, min( 6, absint( $settings['columns'] ?? 3 ) ) ),
			'columns_tablet'  => max( 1, min( 6, absint( $settings['columns_tablet'] ?? 2 ) ) ),
			'columns_mobile'  => max( 1, min( 6, absint( $settings['columns_mobile'] ?? 1 ) ) ),
			'equalize_heights' => $bool( $settings['equalize_heights'] ?? false ),
			'show_image'      => $bool( $settings['show_image'] ?? false ),
			'image_ratio'     => $image_ratio,
			'show_title'      => $bool( $settings['show_title'] ?? false ),
			'show_meta_date'  => $bool( $settings['show_meta_date'] ?? false ),
			'show_meta_terms' => $bool( $settings['show_meta_terms'] ?? false ),
			'show_excerpt'    => $bool( $settings['show_excerpt'] ?? false ),
			'excerpt_lines'   => max( 0, min( 10, absint( $settings['excerpt_lines'] ?? 3 ) ) ),
			'excerpt_length'  => max( 5, min( 200, absint( $settings['excerpt_length'] ?? 20 ) ) ),
			'read_more_text'  => sanitize_text_field( (string) ( $settings['read_more_text'] ?? '' ) ),
			'card_fields'     => $card_fields,
			'show_pagination' => $bool( $settings['show_pagination'] ?? false ),
			'empty_text'      => sanitize_text_field( (string) ( $settings['empty_text'] ?? '' ) ),
			// Style overrides (block controls; empty = designed default).
			'card_background' => $color( $settings['card_background'] ?? '' ),
			'card_radius'     => '' === (string) ( $settings['card_radius'] ?? '' ) ? '' : max( 0, min( 64, absint( $settings['card_radius'] ) ) ),
			'card_padding'    => '' === (string) ( $settings['card_padding'] ?? '' ) ? '' : max( 0, min( 64, absint( $settings['card_padding'] ) ) ),
			'title_color'     => $color( $settings['title_color'] ?? '' ),
			'meta_color'      => $color( $settings['meta_color'] ?? '' ),
			'excerpt_color'   => $color( $settings['excerpt_color'] ?? '' ),
			'more_color'      => $color( $settings['more_color'] ?? '' ),
			'more_hover_color' => $color( $settings['more_hover_color'] ?? '' ),
		);
	}

	/**
	 * Build the WP_Query from sanitized settings.
	 *
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @param int                  $paged    Current page.
	 * @param int                  $exclude_post_id Post ID to exclude (0 = none).
	 */
	public static function build_query( array $settings, int $paged = 1, int $exclude_post_id = 0 ): \WP_Query {
		$settings = self::sanitize( $settings );
		$paged    = max( 1, $paged );

		$per_page = $settings['posts_per_page'];
		$offset   = $settings['offset'];

		// WP_Query drops a plain offset when paged > 1, so fold the
		// per-page advance into the offset ourselves.
		if ( $settings['show_pagination'] && $paged > 1 && $offset > 0 ) {
			$offset += ( $paged - 1 ) * $per_page;
		}

		$not_in = array();
		if ( $settings['exclude_current'] && $exclude_post_id > 0 ) {
			$not_in[] = $exclude_post_id;
		}

		return new \WP_Query(
			array(
				'post_type'           => $settings['post_type'],
				'posts_per_page'      => $per_page,
				'paged'               => $paged,
				'orderby'             => $settings['orderby'],
				'order'               => $settings['order'],
				'offset'              => $offset,
				'post__not_in'        => $not_in,
				'post_status'         => 'publish',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => ! $settings['show_pagination'],
			)
		);
	}

	/**
	 * Open the listing wrapper: layout class + card ratio/excerpt vars +
	 * optional style-override vars.
	 *
	 * @param array<string, mixed> $settings Sanitized settings.
	 */
	public static function render_open( array $settings ): void {
		$settings = self::sanitize( $settings );

		$classes = array( 'tk-post-listing', 'tk-post-listing--' . $settings['layout'] );
		if ( $settings['equalize_heights'] ) {
			$classes[] = 'tk-post-listing--equal';
		}

		$styles = array(
			'--tk-post-card-excerpt-lines: ' . $settings['excerpt_lines'] . ';',
			'--tk-post-listing-columns: ' . $settings['columns'] . ';',
			'--tk-post-listing-columns-tablet: ' . $settings['columns_tablet'] . ';',
			'--tk-post-listing-columns-mobile: ' . $settings['columns_mobile'] . ';',
		);

		if ( $settings['show_image'] ) {
			$styles[] = '--tk-post-card-ratio: ' . $settings['image_ratio'] . ';';
		}

		if ( '' !== $settings['card_background'] ) {
			$styles[] = '--tk-post-card-bg: ' . $settings['card_background'] . ';';
		}
		if ( '' !== $settings['card_radius'] ) {
			$styles[] = '--tk-post-card-radius: ' . $settings['card_radius'] . 'px;';
		}
		if ( '' !== $settings['card_padding'] ) {
			$styles[] = '--tk-post-card-padding: ' . $settings['card_padding'] . 'px;';
		}
		if ( '' !== $settings['title_color'] ) {
			$styles[] = '--tk-post-card-title-color: ' . $settings['title_color'] . ';';
		}
		if ( '' !== $settings['meta_color'] ) {
			$styles[] = '--tk-post-card-meta-color: ' . $settings['meta_color'] . ';';
			$styles[] = '--tk-post-card-meta-opacity: 1;';
		}
		if ( '' !== $settings['excerpt_color'] ) {
			$styles[] = '--tk-post-card-excerpt-color: ' . $settings['excerpt_color'] . ';';
		}
		if ( '' !== $settings['more_color'] ) {
			$styles[] = '--tk-post-card-more-color: ' . $settings['more_color'] . ';';
		}
		if ( '' !== $settings['more_hover_color'] ) {
			$styles[] = '--tk-post-card-more-hover: ' . $settings['more_hover_color'] . ';';
		}

		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" style="' . esc_attr( implode( ' ', $styles ) ) . '">';
	}

	/**
	 * Render one card.
	 *
	 * @param \WP_Post             $post
	 * @param array<string, mixed> $settings Sanitized settings.
	 */
	public static function render_card( \WP_Post $post, array $settings ): void {
		$settings   = self::sanitize( $settings );
		$thumb_id   = $settings['show_image'] ? get_post_thumbnail_id( $post ) : 0;
		$permalink  = get_permalink( $post );

		echo '<article class="tk-post-card">';

		if ( $thumb_id ) {
			$img = wp_get_attachment_image(
				$thumb_id,
				'large',
				false,
				array( 'alt' => get_the_title( $post ) )
			);
			if ( '' !== $img ) {
				// $img is <img> markup generated by wp_get_attachment_image() (WordPress core) — no unescaped user input.
				echo '<a class="tk-post-card__media" href="' . esc_url( $permalink ) . '" aria-hidden="true" tabindex="-1">' . $img . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}

		echo '<div class="tk-post-card__body">';

		if ( $settings['show_title'] ) {
			echo '<h3 class="tk-post-card__title"><a href="' . esc_url( $permalink ) . '">' . esc_html( get_the_title( $post ) ) . '</a></h3>';
		}

		self::render_meta( $post, $settings );

		if ( $settings['show_excerpt'] && $settings['excerpt_lines'] > 0 ) {
			$excerpt = has_excerpt( $post )
				? get_the_excerpt( $post )
				: wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), $settings['excerpt_length'] );
			if ( '' !== trim( $excerpt ) ) {
				echo '<p class="tk-post-card__excerpt">' . esc_html( $excerpt ) . '</p>';
			}
		}

		self::render_card_fields( $post, $settings );

		if ( '' !== $settings['read_more_text'] ) {
			echo '<a class="tk-post-card__more" href="' . esc_url( $permalink ) . '">' . esc_html( $settings['read_more_text'] ) . '</a>';
		}

		echo '</div></article>';
	}

	/**
	 * Render the micro-type meta row: date · terms.
	 *
	 * @param \WP_Post             $post
	 * @param array<string, mixed> $settings Sanitized settings.
	 */
	public static function render_meta( \WP_Post $post, array $settings ): void {
		$settings = self::sanitize( $settings );
		$parts    = array();

		if ( $settings['show_meta_date'] ) {
			$parts[] = get_the_date( '', $post );
		}

		if ( $settings['show_meta_terms'] ) {
			$names = array();
			$taxes = get_object_taxonomies( $post->post_type, 'objects' );
			foreach ( $taxes as $tax ) {
				if ( empty( $tax->public ) || ( ! empty( $tax->_builtin ) && 'post_format' === $tax->name ) ) {
					continue;
				}
				$terms = get_the_terms( $post, $tax->name );
				if ( is_array( $terms ) ) {
					foreach ( $terms as $term ) {
						$names[] = $term->name;
					}
				}
			}
			if ( ! empty( $names ) ) {
				$parts[] = implode( ', ', array_unique( $names ) );
			}
		}

		if ( empty( $parts ) ) {
			return;
		}

		echo '<div class="tk-post-card__meta">' . esc_html( implode( ' · ', $parts ) ) . '</div>';
	}

	/**
	 * Render the TK field rows for one card. Every value resolves through
	 * Fields::get() — the formatted Fields API, never postmeta.
	 *
	 * @param \WP_Post             $post
	 * @param array<string, mixed> $settings Sanitized settings.
	 */
	public static function render_card_fields( \WP_Post $post, array $settings ): void {
		$settings = self::sanitize( $settings );
		$rows     = $settings['card_fields'];
		if ( empty( $rows ) ) {
			return;
		}

		$out = '';
		foreach ( $rows as $row ) {
			$key = $row['field_key'];
			$def = Field_Registry::instance()->get( $key );
			if ( null === $def ) {
				continue;
			}

			$value = Fields::get( $key, $post->ID );
			if ( Unset_Value::is_unset( $value ) ) {
				continue;
			}

			$html = self::format_field_value( $def, $value );
			if ( '' === $html ) {
				continue;
			}

			$label = '' !== $row['field_label'] ? $row['field_label'] : (string) ( $def['label'] ?? $key );

			$out .= '<div class="tk-post-card__field">'
				. '<span class="tk-post-card__field-label">' . esc_html( $label ) . '</span>'
				. '<span class="tk-post-card__field-value">' . $html . '</span>'
				. '</div>';
		}

		if ( '' !== $out ) {
			// $out is built from escaped/formatted fragments above.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<div class="tk-post-card__fields">' . $out . '</div>';
		}
	}

	/**
	 * Format one resolved field value as display HTML, mirroring the
	 * typed Elementor dynamic tags (class-text-tag.php etc.). All output
	 * is escaped or save-sanitized HTML.
	 *
	 * @param array<string, mixed> $def   Registry field definition.
	 * @param mixed                $value Formatted value from Fields::get().
	 */
	public static function format_field_value( array $def, mixed $value ): string {
		$type = (string) ( $def['type'] ?? '' );

		if ( in_array( $type, array( 'select', 'radio', 'button_group' ), true ) ) {
			$choices = $def['choices'] ?? array();
			$value   = $choices[ (string) $value ] ?? $value;
		}

		if ( 'true_false' === $type ) {
			return esc_html( $value ? __( 'Yes', 'tk-fields' ) : __( 'No', 'tk-fields' ) );
		}

		if ( 'image' === $type && is_array( $value ) && ! empty( $value['url'] ) ) {
			return '<img src="' . esc_url( (string) $value['url'] ) . '" alt="' . esc_attr( (string) ( $def['label'] ?? '' ) ) . '">';
		}

		if ( 'gallery' === $type && is_array( $value ) ) {
			$count = count( $value );
			/* translators: %d: number of images */
			return esc_html( sprintf( _n( '%1$d image', '%1$d images', $count, 'tk-fields' ), $count ) );
		}

		if ( 'link' === $type && is_array( $value ) && ! empty( $value['url'] ) ) {
			$label = '' !== ( $value['title'] ?? '' ) ? (string) $value['title'] : (string) $value['url'];
			return '<a href="' . esc_url( (string) $value['url'] ) . '">' . esc_html( $label ) . '</a>';
		}

		if ( 'color' === $type && is_string( $value ) && '' !== $value ) {
			return '<span class="tk-post-card__field-color" style="background-color:' . esc_attr( $value ) . '"></span>' . esc_html( $value );
		}

		if ( 'post_object' === $type ) {
			return esc_html( is_array( $value ) ? (string) ( $value['title'] ?? '' ) : '' );
		}

		if ( 'taxonomy' === $type ) {
			$names = array();
			if ( is_array( $value ) ) {
				foreach ( $value as $term ) {
					if ( is_array( $term ) && isset( $term['name'] ) ) {
						$names[] = (string) $term['name'];
					}
				}
			}
			return esc_html( implode( ', ', $names ) );
		}

		if ( 'user' === $type ) {
			$names = array();
			if ( is_array( $value ) ) {
				$users = isset( $value['display_name'] ) ? array( $value ) : $value;
				foreach ( $users as $user ) {
					if ( is_array( $user ) && isset( $user['display_name'] ) ) {
						$names[] = (string) $user['display_name'];
					}
				}
			}
			return esc_html( implode( ', ', $names ) );
		}

		if ( 'relationship' === $type ) {
			$titles = array();
			if ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					if ( is_array( $item ) && isset( $item['title'] ) ) {
						$titles[] = (string) $item['title'];
					}
				}
			}
			return esc_html( implode( ', ', $titles ) );
		}

		if ( 'map' === $type && is_array( $value ) ) {
			$address = (string) ( $value['address'] ?? '' );
			if ( '' !== $address ) {
				return esc_html( $address );
			}
			$lat = $value['lat'] ?? null;
			$lng = $value['lng'] ?? null;
			if ( null !== $lat && null !== $lng ) {
				return esc_html( $lat . ',' . $lng );
			}
			return '';
		}

		if ( in_array( $type, array( 'oembed', 'wysiwyg' ), true ) ) {
			// Formatted oembed is provider embed HTML; wysiwyg is stored
			// HTML, wp_kses_post-filtered at save (or raw by explicit
			// admin opt-in). Safe to echo, like the dynamic tags do.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return (string) $value;
		}

		if ( 'textarea' === $type ) {
			return wp_kses_post( (string) $value );
		}

		if ( is_scalar( $value ) ) {
			return esc_html( (string) $value );
		}

		return '';
	}

	/**
	 * Render the designed empty state.
	 */
	public static function render_empty( string $message ): void {
		if ( '' === trim( $message ) ) {
			return;
		}

		echo '<div class="tk-widget-empty">'
			. '<p class="tk-widget-empty__title">' . esc_html__( 'Nothing here yet', 'tk-fields' ) . '</p>'
			. '<p class="tk-widget-empty__text">' . esc_html( $message ) . '</p>'
			. '</div>';
	}

	/**
	 * Render numbered prev/next pagination under the listing.
	 *
	 * @param \WP_Query            $query
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @param int                  $paged    Current page.
	 */
	public static function render_pagination( \WP_Query $query, array $settings, int $paged = 1 ): void {
		$settings = self::sanitize( $settings );
		if ( ! $settings['show_pagination'] ) {
			return;
		}

		$total = (int) $query->max_num_pages;
		if ( $total < 2 ) {
			return;
		}

		$links = paginate_links(
			array(
				'current'   => max( 1, $paged ),
				'total'     => $total,
				'prev_text' => __( '&larr; Prev', 'tk-fields' ),
				'next_text' => __( 'Next &rarr;', 'tk-fields' ),
				'type'      => 'list',
			)
		);

		if ( is_string( $links ) && '' !== $links ) {
			// $links is pagination markup generated by paginate_links() (WordPress core) — no unescaped user input.
			echo '<nav class="tk-post-listing__pagination" aria-label="' . esc_attr__( 'Posts pages', 'tk-fields' ) . '">' . $links . '</nav>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}
