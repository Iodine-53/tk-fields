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
 * Server render for tk/post-listing — the Gutenberg twin of the Elementor
 * TK Post Listing widget. Block attributes are mapped onto the shared
 * renderer settings (Post_Listing_Renderer), so both builders emit the
 * same card markup and design language.
 *
 * Pagination always shows page 1 inside the editor (ServerSideRender hits
 * the block-renderer REST endpoint); the frontend reads the real page.
 *
 * @package TK\\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$card_fields = array();
$rows        = $attributes['cardFields'] ?? array();
if ( is_array( $rows ) ) {
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$card_fields[] = array(
			'field_key'   => (string) ( $row['fieldKey'] ?? '' ),
			'field_label' => (string) ( $row['fieldLabel'] ?? '' ),
		);
	}
}

// Show Read More toggle is the explicit off switch; an empty text keeps
// the translated default.
$read_more_text  = (string) ( $attributes['readMoreText'] ?? '' );
$show_read_more  = array_key_exists( 'showReadMore', $attributes ) ? ! empty( $attributes['showReadMore'] ) : true;
if ( ! $show_read_more ) {
	$read_more = '';
} elseif ( '' === $read_more_text ) {
	$read_more = __( 'Read more', 'tk-fields' );
} else {
	$read_more = $read_more_text;
}

$empty_text = (string) ( $attributes['emptyText'] ?? '' );
if ( '' === $empty_text ) {
	$empty_text = __( 'No posts found — try adjusting filters.', 'tk-fields' );
}

$settings = array(
	'post_type'        => (string) ( $attributes['postType'] ?? 'post' ),
	'posts_per_page'   => $attributes['postsPerPage'] ?? 6,
	'orderby'          => (string) ( $attributes['orderby'] ?? 'date' ),
	'order'            => (string) ( $attributes['order'] ?? 'DESC' ),
	'offset'           => $attributes['offset'] ?? 0,
	'exclude_current'  => $attributes['excludeCurrent'] ?? true,
	'layout'           => (string) ( $attributes['layout'] ?? 'grid' ),
	'columns'          => $attributes['columns'] ?? 3,
	'columns_tablet'   => $attributes['columnsTablet'] ?? 2,
	'columns_mobile'   => $attributes['columnsMobile'] ?? 1,
	'equalize_heights' => $attributes['equalizeHeights'] ?? true,
	'show_image'       => $attributes['showImage'] ?? true,
	'image_ratio'      => (string) ( $attributes['imageRatio'] ?? '16/9' ),
	'show_title'       => $attributes['showTitle'] ?? true,
	'show_meta_date'   => $attributes['showMetaDate'] ?? true,
	'show_meta_terms'  => $attributes['showMetaTerms'] ?? true,
	'show_excerpt'     => $attributes['showExcerpt'] ?? true,
	'excerpt_lines'    => $attributes['excerptLines'] ?? 3,
	'excerpt_length'   => $attributes['excerptLength'] ?? 20,
	'read_more_text'   => $read_more,
	'card_fields'      => $card_fields,
	'show_pagination'  => $attributes['showPagination'] ?? false,
	'empty_text'       => $empty_text,
	'card_background'  => (string) ( $attributes['cardBackground'] ?? '' ),
	'card_radius'      => $attributes['cardRadius'] ?? '',
	'card_padding'     => $attributes['cardPadding'] ?? '',
	'title_color'      => (string) ( $attributes['titleColor'] ?? '' ),
	'meta_color'       => (string) ( $attributes['metaColor'] ?? '' ),
	'excerpt_color'    => (string) ( $attributes['excerptColor'] ?? '' ),
	'more_color'       => (string) ( $attributes['moreColor'] ?? '' ),
	'more_hover_color' => (string) ( $attributes['moreHoverColor'] ?? '' ),
);

// Inside the editor (ServerSideRender → block-renderer REST endpoint) there
// is no meaningful page — always preview page 1, like the Elementor widget.
$is_editor_request = ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_is_json_request();
$paged            = 1;
if ( ! $is_editor_request ) {
	$paged = get_query_var( 'paged' );
	if ( ! $paged ) {
		$paged = get_query_var( 'page' );
	}
	$paged = max( 1, absint( $paged ) );
}

$exclude_post_id = 0;
if ( ! empty( $settings['exclude_current'] ) && ! $is_editor_request ) {
	// get_the_ID() returns false outside the loop — cast keeps the
	// build_query() int contract.
	$exclude_post_id = (int) get_the_ID();
}

$query = Post_Listing_Renderer::build_query( $settings, $paged, $exclude_post_id );

if ( ! $query->have_posts() ) {
	Post_Listing_Renderer::render_empty( $settings['empty_text'] );
	return;
}

Post_Listing_Renderer::render_open( $settings );

while ( $query->have_posts() ) {
	$query->the_post();
	$post = get_post();
	if ( $post instanceof \WP_Post ) {
		Post_Listing_Renderer::render_card( $post, $settings );
	}
}

echo '</div>';

Post_Listing_Renderer::render_pagination( $query, $settings, $paged );

wp_reset_postdata();
