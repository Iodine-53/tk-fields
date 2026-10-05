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
 * Elementor "TK Related Posts" widget.
 *
 * Sources its posts from a TK Relationship field on the current post
 * (resolved through \TK\Fields\Fields — never postmeta), falling back to
 * same-term matching when the field is empty or unset. Cards reuse the TK
 * Post Listing card language (shared CSS classes + shared stylesheet), in
 * the compact horizontal-list spirit of DESIGN-0.16.0.md §5.
 *
 * @package TK\Fields\Integrations\Elementor\Widgets
 */

declare(strict_types=1);

namespace TK\Fields\Integrations\Elementor\Widgets;

use Elementor\Controls_Manager;
use TK\Fields\Field_Registry;
use TK\Fields\Fields;
use TK\Fields\Integrations\Elementor\Context as Elementor_Context;
use TK\Fields\Unset_Value;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( Post_Listing_Widget::class ) ) {
	require_once __DIR__ . '/class-post-listing-widget.php';
}

/**
 * Related posts widget.
 */
class Related_Posts_Widget extends Post_Listing_Widget {

	/**
	 * @return string
	 */
	public function get_name(): string {
		return 'tk-related-posts';
	}

	/**
	 * @return string
	 */
	public function get_title(): string {
		return __( 'TK Related Posts', 'tk-fields' );
	}

	/**
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-posts-carousel';
	}

	/**
	 * @return array<int, string>
	 */
	public function get_keywords(): array {
		return array( 'tk', 'fields', 'related', 'posts', 'relationship', 'similar' );
	}

	/**
	 * Related Posts has no WP_Query of its own — slimmed controls plus
	 * the shared card/field/pagination-empty sections from the parent.
	 */
	protected function register_controls(): void {
		$this->start_controls_section(
			'tk_related_source',
			array(
				'label' => __( 'Source', 'tk-fields' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'relationship_field',
			array(
				'label'       => __( 'Relationship Field', 'tk-fields' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => $this->relationship_options(),
				'description' => __( 'The TK Relationship field on the current post that hand-picks the related posts. Values resolve through the TK Fields API.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'use_fallback',
			array(
				'label'        => __( 'Fall Back to Same Terms', 'tk-fields' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'tk-fields' ),
				'label_off'    => __( 'No', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'When the relationship field is empty, list posts that share the current post’s categories/tags instead of showing nothing.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'posts_per_page',
			array(
				'label'       => __( 'Max Posts', 'tk-fields' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => 4,
				'min'         => 1,
				'max'         => 20,
				'step'        => 1,
				'description' => __( 'Maximum related posts to show.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'orderby',
			array(
				'label'       => __( 'Fallback Order By', 'tk-fields' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'date',
				'options'     => array(
					'date'  => __( 'Date', 'tk-fields' ),
					'title' => __( 'Title (A–Z)', 'tk-fields' ),
					'rand'  => __( 'Random', 'tk-fields' ),
				),
				'description' => __( 'Sort order for the same-term fallback. Hand-picked relationship posts always keep their stored order.', 'tk-fields' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'tk_related_heading',
			array(
				'label' => __( 'Heading', 'tk-fields' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'show_heading',
			array(
				'label'        => __( 'Show Heading', 'tk-fields' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'tk-fields' ),
				'label_off'    => __( 'Hide', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Show a heading above the related cards.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'heading_text',
			array(
				'label'       => __( 'Heading Text', 'tk-fields' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Related posts', 'tk-fields' ),
				'condition'   => array( 'show_heading' => 'yes' ),
				'description' => __( 'The heading shown above the related cards.', 'tk-fields' ),
			)
		);

		$this->end_controls_section();

		$this->register_card_controls();
		$this->register_fields_controls();

		$this->start_controls_section(
			'tk_related_empty',
			array(
				'label' => __( 'Empty State', 'tk-fields' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'empty_text',
			array(
				'label'       => __( 'Empty Message', 'tk-fields' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'No related posts found.', 'tk-fields' ),
				'description' => __( 'Shown in a styled notice when neither the relationship field nor the term fallback finds posts.', 'tk-fields' ),
			)
		);

		$this->end_controls_section();

		$this->register_style_controls();
	}

	/**
	 * Render the related posts for the current document post.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();

		$current = Elementor_Context::resolve_post_id();
		if ( $current <= 0 ) {
			$this->render_empty( (string) ( $settings['empty_text'] ?? '' ) );
			return;
		}

		$count = absint( $settings['posts_per_page'] ?? 4 );
		$count = max( 1, min( 20, $count ) );

		$posts = $this->related_posts( $current, $settings, $count );

		if ( empty( $posts ) ) {
			$this->render_empty( (string) ( $settings['empty_text'] ?? '' ) );
			return;
		}

		if ( 'yes' === ( $settings['show_heading'] ?? '' ) ) {
			$heading = trim( (string) ( $settings['heading_text'] ?? '' ) );
			if ( '' !== $heading ) {
				echo '<h2 class="tk-related-posts__heading">' . esc_html( $heading ) . '</h2>';
			}
		}

		$this->render_open( $settings );

		foreach ( $posts as $post ) {
			$this->render_card( $post, $settings );
		}

		echo '</div>';
	}

	/**
	 * Resolve the related posts: relationship field first (stored order),
	 * same-term fallback when empty.
	 *
	 * @param array<string, mixed> $settings
	 * @return array<int, \WP_Post>
	 */
	protected function related_posts( int $current, array $settings, int $count ): array {
		$key = sanitize_key( (string) ( $settings['relationship_field'] ?? '' ) );

		if ( '' !== $key ) {
			$value = Fields::get( $key, $current );
			if ( ! Unset_Value::is_unset( $value ) && is_array( $value ) ) {
				$posts = array();
				foreach ( $value as $item ) {
					if ( ! is_array( $item ) || ! isset( $item['id'] ) ) {
						continue;
					}
					$post = get_post( absint( $item['id'] ) );
					if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
						$posts[] = $post;
					}
					if ( count( $posts ) >= $count ) {
						break;
					}
				}
				if ( ! empty( $posts ) ) {
					return $posts;
				}
			}
		}

		if ( 'yes' !== ( $settings['use_fallback'] ?? '' ) ) {
			return array();
		}

		$orderby = sanitize_key( (string) ( $settings['orderby'] ?? 'date' ) );
		if ( ! in_array( $orderby, array( 'date', 'title', 'rand' ), true ) ) {
			$orderby = 'date';
		}

		return $this->same_term_posts( $current, $count, $orderby );
	}

	/**
	 * Posts sharing the current post's terms across its public taxonomies.
	 *
	 * @return array<int, \WP_Post>
	 */
	protected function same_term_posts( int $current, int $count, string $orderby ): array {
		$post = get_post( $current );
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		$tax_query = array( 'relation' => 'OR' );
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $tax ) {
			if ( empty( $tax->public ) ) {
				continue;
			}
			if ( ! empty( $tax->_builtin ) && 'post_format' === $tax->name ) {
				continue;
			}
			$terms = wp_get_post_terms( $current, $tax->name, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			$tax_query[] = array(
				'taxonomy' => $tax->name,
				'field'    => 'term_id',
				'terms'    => array_map( 'absint', $terms ),
			);
		}

		if ( 1 === count( $tax_query ) ) {
			return array(); // The post has no terms to match on.
		}

		$query = new \WP_Query(
			array(
				'post_type'           => $post->post_type,
				'posts_per_page'      => $count,
				'post__not_in'        => array( $current ),
				'post_status'         => 'publish',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				'tax_query'           => $tax_query,
				'orderby'             => $orderby,
				'order'               => 'rand' === $orderby ? 'ASC' : 'DESC',
			)
		);

		return $query->posts;
	}

	/**
	 * Registered relationship-type TK fields as select options.
	 *
	 * @return array<string, string>
	 */
	protected function relationship_options(): array {
		$options = array(
			'' => __( 'None — always use the term fallback', 'tk-fields' ),
		);

		foreach ( Field_Registry::instance()->all() as $name => $field ) {
			if ( 'relationship' !== ( $field['type'] ?? '' ) ) {
				continue;
			}
			$options[ $name ] = sprintf(
				/* translators: 1: field label, 2: field name. */
				__( '%1$s (%2$s)', 'tk-fields' ),
				$field['label'] ?? $name,
				$name
			);
		}

		return $options;
	}
}
