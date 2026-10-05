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
 * Elementor "TK Taxonomy Terms" widget.
 *
 * Lists the terms of any public taxonomy as a pill cloud (default, per
 * DESIGN-0.16.0.md §5) or a divider list with counts. Pure display —
 * no TK field storage involved; styling consumes the shared tk-widgets
 * tokens.
 *
 * @package TK\Fields\Integrations\Elementor\Widgets
 */

declare(strict_types=1);

namespace TK\Fields\Integrations\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use TK\Fields\Widget_Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Taxonomy terms widget.
 */
final class Taxonomy_Terms_Widget extends \Elementor\Widget_Base {

	/**
	 * Register the widget stylesheet (depends on the shared tk-widgets
	 * tokens). Elementor enqueues it via get_style_depends().
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, mixed>|null $args
	 */
	public function __construct( $data = array(), $args = null ) {
		parent::__construct( $data, $args );

		wp_register_style(
			'tk-taxonomy-terms',
			plugins_url( 'assets/elementor/css/tk-taxonomy-terms.css', TK_FIELDS_FILE ),
			array( Widget_Assets::HANDLE ),
			TK_FIELDS_VERSION
		);
	}

	/**
	 * @return string
	 */
	public function get_name(): string {
		return 'tk-taxonomy-terms';
	}

	/**
	 * @return string
	 */
	public function get_title(): string {
		return __( 'TK Taxonomy Terms', 'tk-fields' );
	}

	/**
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-tags';
	}

	/**
	 * @return array<int, string>
	 */
	public function get_categories(): array {
		return array( 'tk-fields' );
	}

	/**
	 * @return array<int, string>
	 */
	public function get_keywords(): array {
		return array( 'tk', 'fields', 'taxonomy', 'terms', 'categories', 'tags', 'pill', 'cloud' );
	}

	/**
	 * @return array<int, string>
	 */
	public function get_style_depends(): array {
		return array( Widget_Assets::HANDLE, 'tk-taxonomy-terms' );
	}

	/**
	 * Register content + style controls.
	 */
	protected function register_controls(): void {
		$this->start_controls_section(
			'tk_terms_content',
			array(
				'label' => __( 'Content', 'tk-fields' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'taxonomy',
			array(
				'label'       => __( 'Taxonomy', 'tk-fields' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => $this->taxonomy_options(),
				'default'     => 'category',
				'description' => __( 'Which taxonomy to list terms from — categories, tags, or any custom taxonomy.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'layout',
			array(
				'label'       => __( 'Layout', 'tk-fields' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'pills',
				'options'     => array(
					'pills' => __( 'Pill Cloud', 'tk-fields' ),
					'list'  => __( 'List', 'tk-fields' ),
				),
				'description' => __( 'Pill Cloud wraps terms as clickable chips; List stacks them as rows with counts on the right.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'link_terms',
			array(
				'label'        => __( 'Link to Term Archives', 'tk-fields' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'tk-fields' ),
				'label_off'    => __( 'No', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Make each term a link to its archive page. Off renders plain text — useful for tag clouds that are decorative.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'show_counts',
			array(
				'label'        => __( 'Show Counts', 'tk-fields' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'tk-fields' ),
				'label_off'    => __( 'Hide', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Show the number of posts in each term next to its name.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'hide_empty',
			array(
				'label'        => __( 'Hide Empty Terms', 'tk-fields' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'tk-fields' ),
				'label_off'    => __( 'No', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Skip terms with zero posts. Turn off to list every term, even unused ones.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'number',
			array(
				'label'       => __( 'Max Terms', 'tk-fields' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => 20,
				'min'         => 0,
				'max'         => 500,
				'step'        => 1,
				'description' => __( 'Maximum terms to show. 0 means no limit — every term in the taxonomy.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'orderby',
			array(
				'label'       => __( 'Order By', 'tk-fields' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'name',
				'options'     => array(
					'name'  => __( 'Name', 'tk-fields' ),
					'count' => __( 'Post Count', 'tk-fields' ),
					'slug'  => __( 'Slug', 'tk-fields' ),
					'term_id' => __( 'Term ID', 'tk-fields' ),
				),
				'description' => __( 'How the terms are sorted. Count puts the most-used terms first.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'order',
			array(
				'label'       => __( 'Order', 'tk-fields' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'ASC',
				'options'     => array(
					'ASC'  => __( 'Ascending (A–Z)', 'tk-fields' ),
					'DESC' => __( 'Descending (Z–A)', 'tk-fields' ),
				),
				'description' => __( 'Sort direction.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'empty_text',
			array(
				'label'       => __( 'Empty Message', 'tk-fields' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'No terms found.', 'tk-fields' ),
				'description' => __( 'Shown in a styled notice when the taxonomy has no terms to display.', 'tk-fields' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'tk_terms_style',
			array(
				'label' => __( 'Terms', 'tk-fields' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'        => 'term_typography',
				'label'       => __( 'Typography', 'tk-fields' ),
				'selector'    => '{{WRAPPER}} .tk-term-pill, {{WRAPPER}} .tk-term-list__link',
				'description' => __( 'Type for the term names in both layouts.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'term_color',
			array(
				'label'       => __( 'Text Color', 'tk-fields' ),
				'type'        => Controls_Manager::COLOR,
				'selectors'   => array(
					'{{WRAPPER}} .tk-term-pill, {{WRAPPER}} .tk-term-list__link' => 'color: {{VALUE}};',
				),
				'description' => __( 'Term text color. Empty inherits the theme text color.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'term_bg',
			array(
				'label'       => __( 'Pill Background', 'tk-fields' ),
				'type'        => Controls_Manager::COLOR,
				'selectors'   => array(
					'{{WRAPPER}} .tk-term-pill' => 'background: {{VALUE}};',
				),
				'condition'   => array( 'layout' => 'pills' ),
				'description' => __( 'Pill chip background. Empty uses the soft neutral tint.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'term_hover_bg',
			array(
				'label'       => __( 'Pill Hover Background', 'tk-fields' ),
				'type'        => Controls_Manager::COLOR,
				'selectors'   => array(
					'{{WRAPPER}} a.tk-term-pill:hover' => 'background: {{VALUE}};',
				),
				'condition'   => array( 'layout' => 'pills' ),
				'description' => __( 'Pill background on hover. Empty uses the TK accent blue.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'term_hover_color',
			array(
				'label'       => __( 'Hover Text Color', 'tk-fields' ),
				'type'        => Controls_Manager::COLOR,
				'selectors'   => array(
					'{{WRAPPER}} a.tk-term-pill:hover, {{WRAPPER}} .tk-term-list__link:hover' => 'color: {{VALUE}};',
				),
				'description' => __( 'Term text color on hover.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'pill_radius',
			array(
				'label'       => __( 'Pill Radius', 'tk-fields' ),
				'type'        => Controls_Manager::DIMENSIONS,
				'size_units'  => array( 'px', '%' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-term-pill' => '--tk-term-pill-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'   => array( 'layout' => 'pills' ),
				'description' => __( 'Chip corner rounding. Full rounding is the designed default.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'pill_padding',
			array(
				'label'       => __( 'Pill Padding', 'tk-fields' ),
				'type'        => Controls_Manager::DIMENSIONS,
				'size_units'  => array( 'px', 'em' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-term-pill' => '--tk-term-pill-padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'condition'   => array( 'layout' => 'pills' ),
				'description' => __( 'Inner spacing of each pill chip.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'count_color',
			array(
				'label'       => __( 'Count Color', 'tk-fields' ),
				'type'        => Controls_Manager::COLOR,
				'selectors'   => array(
					'{{WRAPPER}} .tk-term-pill__count, {{WRAPPER}} .tk-term-list__count' => 'color: {{VALUE}}; opacity: 1;',
				),
				'description' => __( 'Color of the post-count badges. Empty keeps the default muted treatment.', 'tk-fields' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render the terms.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();

		$taxonomy = sanitize_key( (string) ( $settings['taxonomy'] ?? 'category' ) );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$tax_obj = get_taxonomy( $taxonomy );
		if ( ! $tax_obj || empty( $tax_obj->public ) ) {
			return;
		}

		$number = absint( $settings['number'] ?? 20 );

		$orderby = sanitize_key( (string) ( $settings['orderby'] ?? 'name' ) );
		if ( ! in_array( $orderby, array( 'name', 'count', 'slug', 'term_id' ), true ) ) {
			$orderby = 'name';
		}

		$order = strtoupper( (string) ( $settings['order'] ?? 'ASC' ) );
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'ASC';
		}

		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => 'yes' === ( $settings['hide_empty'] ?? '' ),
			'orderby'    => $orderby,
			'order'      => $order,
		);
		if ( $number > 0 ) {
			$args['number'] = min( 500, $number );
		}

		$terms = get_terms( $args );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			$this->render_empty( (string) ( $settings['empty_text'] ?? '' ) );
			return;
		}

		$layout = (string) ( $settings['layout'] ?? 'pills' );
		if ( ! in_array( $layout, array( 'pills', 'list' ), true ) ) {
			$layout = 'pills';
		}

		$link_terms  = 'yes' === ( $settings['link_terms'] ?? '' );
		$show_counts = 'yes' === ( $settings['show_counts'] ?? '' );

		if ( 'list' === $layout ) {
			echo '<ul class="tk-term-list">';
			foreach ( $terms as $term ) {
				$this->render_list_item( $term, $link_terms, $show_counts );
			}
			echo '</ul>';
			return;
		}

		echo '<ul class="tk-term-cloud">';
		foreach ( $terms as $term ) {
			$this->render_pill( $term, $link_terms, $show_counts );
		}
		echo '</ul>';
	}

	/**
	 * Render one pill chip.
	 */
	protected function render_pill( \WP_Term $term, bool $link_terms, bool $show_counts ): void {
		$inner = '<span class="tk-term-pill__name">' . esc_html( $term->name ) . '</span>';
		if ( $show_counts ) {
			$inner .= '<span class="tk-term-pill__count">' . esc_html( (string) absint( $term->count ) ) . '</span>';
		}

		if ( $link_terms ) {
			$link = get_term_link( $term );
			if ( ! is_wp_error( $link ) ) {
				// $inner holds only esc_html()'d components (name, absint count) plus static tags — already escaped upstream.
				echo '<li><a class="tk-term-pill" href="' . esc_url( $link ) . '">' . $inner . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				return;
			}
		}

		// $inner holds only esc_html()'d components (name, absint count) plus static tags — already escaped upstream.
		echo '<li><span class="tk-term-pill">' . $inner . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Render one list row.
	 */
	protected function render_list_item( \WP_Term $term, bool $link_terms, bool $show_counts ): void {
		$name = esc_html( $term->name );

		if ( $link_terms ) {
			$link = get_term_link( $term );
			if ( ! is_wp_error( $link ) ) {
				$name = '<a class="tk-term-list__link" href="' . esc_url( $link ) . '">' . $name . '</a>';
			}
		} else {
			$name = '<span class="tk-term-list__link">' . $name . '</span>';
		}

		$count = '';
		if ( $show_counts ) {
			$count = '<span class="tk-term-list__count">' . esc_html( (string) absint( $term->count ) ) . '</span>';
		}

		// $name / $count are built from escaped fragments above.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<li class="tk-term-list__item">' . $name . $count . '</li>';
	}

	/**
	 * Render the designed empty state.
	 */
	protected function render_empty( string $message ): void {
		if ( '' === trim( $message ) ) {
			return;
		}

		echo '<div class="tk-widget-empty">'
			. '<p class="tk-widget-empty__title">' . esc_html__( 'Nothing here yet', 'tk-fields' ) . '</p>'
			. '<p class="tk-widget-empty__text">' . esc_html( $message ) . '</p>'
			. '</div>';
	}

	/**
	 * All public taxonomies as select options.
	 *
	 * @return array<string, string>
	 */
	protected function taxonomy_options(): array {
		$taxes   = get_taxonomies( array( 'public' => true ), 'objects' );
		$options = array();
		foreach ( $taxes as $tax ) {
			$options[ $tax->name ] = $tax->labels->singular_name . ' (' . $tax->name . ')';
		}
		return $options;
	}
}
