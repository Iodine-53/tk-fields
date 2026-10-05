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
 * Elementor "TK Post Listing" widget.
 *
 * The free equivalent of Elementor Pro's Loop Grid: queries posts (any
 * public post type, including TK Content Types) and renders designed cards
 * per DESIGN-0.16.0.md §5 — 16px-radius image, 20px padding, 600-weight
 * title, micro-type meta row, configurable excerpt line-clamp, read-more
 * link — with hover lift + image zoom. Card heights can be equalized with
 * the Read More link pinned to the bottom (v0.16.1). Custom TK field values can be shown per card through
 * the fields repeater; every value resolves through \TK\Fields\Fields —
 * no postmeta reads anywhere.
 *
 * Pagination is plain numbered prev/next (no AJAX — deferred, see docs).
 *
 * @package TK\Fields\Integrations\Elementor\Widgets
 */

declare(strict_types=1);

namespace TK\Fields\Integrations\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Typography;
use TK\Fields\Field_Registry;
use TK\Fields\Fields;
use TK\Fields\Integrations\Elementor\Context as Elementor_Context;
use TK\Fields\Post_Listing_Renderer;
use TK\Fields\Unset_Value;
use TK\Fields\Widget_Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post listing widget.
 */
class Post_Listing_Widget extends \Elementor\Widget_Base {

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
			'tk-post-listing',
			plugins_url( 'assets/elementor/css/tk-post-listing.css', TK_FIELDS_FILE ),
			array( Widget_Assets::HANDLE ),
			TK_FIELDS_VERSION
		);
	}

	/**
	 * @return string
	 */
	public function get_name(): string {
		return 'tk-post-listing';
	}

	/**
	 * @return string
	 */
	public function get_title(): string {
		return __( 'TK Post Listing', 'tk-fields' );
	}

	/**
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-posts-grid';
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
		return array( 'tk', 'fields', 'posts', 'listing', 'grid', 'query', 'loop', 'archive' );
	}

	/**
	 * @return array<int, string>
	 */
	public function get_style_depends(): array {
		return array( Widget_Assets::HANDLE, 'tk-post-listing' );
	}

	/**
	 * Register all controls: query, layout/card, TK fields, pagination,
	 * then the Style tab sections.
	 */
	protected function register_controls(): void {
		$this->register_query_controls();
		$this->register_card_controls();
		$this->register_fields_controls();
		$this->register_pagination_controls();
		$this->register_style_controls();
	}

	/**
	 * Content section: the WP_Query behind the listing.
	 */
	protected function register_query_controls(): void {
		$this->start_controls_section(
			'tk_post_listing_query',
			array(
				'label' => __( 'Query', 'tk-fields' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'post_type',
			array(
				'label'       => __( 'Post Type', 'tk-fields' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => $this->post_type_options(),
				'default'     => 'post',
				'description' => __( 'Which content to list. TK Content Types you build in TK Fields appear here alongside posts and pages.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'posts_per_page',
			array(
				'label'       => __( 'Posts Per Page', 'tk-fields' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => 6,
				'min'         => 1,
				'max'         => 100,
				'step'        => 1,
				'description' => __( 'How many cards show at once. With pagination on, this is the page size.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'orderby',
			array(
				'label'       => __( 'Order By', 'tk-fields' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'date',
				'options'     => array(
					'date'          => __( 'Date', 'tk-fields' ),
					'modified'      => __( 'Last Modified', 'tk-fields' ),
					'title'         => __( 'Title (A–Z)', 'tk-fields' ),
					'name'          => __( 'Slug', 'tk-fields' ),
					'menu_order'    => __( 'Menu Order', 'tk-fields' ),
					'comment_count' => __( 'Comment Count', 'tk-fields' ),
					'rand'          => __( 'Random', 'tk-fields' ),
				),
				'description' => __( 'What the listing sorts by. Random is re-shuffled on every page load.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'order',
			array(
				'label'       => __( 'Order', 'tk-fields' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'DESC',
				'options'     => array(
					'DESC' => __( 'Descending (newest first)', 'tk-fields' ),
					'ASC'  => __( 'Ascending (oldest first)', 'tk-fields' ),
				),
				'description' => __( 'Sort direction. Has no effect when Order By is Random.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'offset',
			array(
				'label'       => __( 'Offset', 'tk-fields' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => 0,
				'min'         => 0,
				'step'        => 1,
				'description' => __( 'Skip this many posts from the start — e.g. 3 skips the posts already shown in a hero block above.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'exclude_current',
			array(
				'label'        => __( 'Exclude Current Post', 'tk-fields' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'tk-fields' ),
				'label_off'    => __( 'No', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Leave the post this page is about out of the listing. Useful on single-post templates so the page never lists itself.', 'tk-fields' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content section: card layout and which parts of each card show.
	 */
	protected function register_card_controls(): void {
		$this->start_controls_section(
			'tk_post_listing_card',
			array(
				'label' => __( 'Card', 'tk-fields' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'layout',
			array(
				'label'       => __( 'Layout', 'tk-fields' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'grid',
				'options'     => array(
					'grid' => __( 'Grid', 'tk-fields' ),
					'list' => __( 'List', 'tk-fields' ),
				),
				'description' => __( 'Grid shows cards in columns; List stacks horizontal cards with the image on the left.', 'tk-fields' ),
			)
		);

		$this->add_responsive_control(
			'columns',
			array(
				'label'       => __( 'Columns', 'tk-fields' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => 3,
				'tablet_default' => 2,
				'mobile_default' => 1,
				'min'         => 1,
				'max'         => 6,
				'step'        => 1,
				'condition'   => array( 'layout' => 'grid' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-post-listing--grid' => '--tk-post-listing-columns: {{VALUE}};',
				),
				'description' => __( 'How many cards sit side by side. Set per device — e.g. 3 on desktop, 1 on phones.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'equalize_heights',
			array(
				'label'        => __( 'Equalize Card Heights', 'tk-fields' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'On', 'tk-fields' ),
				'label_off'    => __( 'Off', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Make every card in a row the same height, with the Read More link pinned to the bottom — so all Read More links sit at one level no matter how long each excerpt is.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'show_image',
			array(
				'label'        => __( 'Show Image', 'tk-fields' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'tk-fields' ),
				'label_off'    => __( 'Hide', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Show the featured image at the top of each card. Cards without a featured image simply skip the image area.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'image_ratio',
			array(
				'label'       => __( 'Image Aspect Ratio', 'tk-fields' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => '16/9',
				'options'     => array(
					'16/9' => __( '16:9 (widescreen)', 'tk-fields' ),
					'4/3'  => __( '4:3', 'tk-fields' ),
					'3/2'  => __( '3:2', 'tk-fields' ),
					'1/1'  => __( '1:1 (square)', 'tk-fields' ),
					'auto' => __( 'Natural (no crop)', 'tk-fields' ),
				),
				'condition'   => array( 'show_image' => 'yes' ),
				'description' => __( 'The image box keeps this shape and crops to fill it. Natural shows the full image uncropped.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'show_title',
			array(
				'label'        => __( 'Show Title', 'tk-fields' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'tk-fields' ),
				'label_off'    => __( 'Hide', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Show the post title, linked to the post.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'show_meta_date',
			array(
				'label'        => __( 'Show Date', 'tk-fields' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'tk-fields' ),
				'label_off'    => __( 'Hide', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Show the publish date in the small meta row under the title.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'show_meta_terms',
			array(
				'label'        => __( 'Show Terms', 'tk-fields' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'tk-fields' ),
				'label_off'    => __( 'Hide', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Show the post’s categories/tags (from its public taxonomies) in the meta row, after the date.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'show_excerpt',
			array(
				'label'        => __( 'Show Excerpt', 'tk-fields' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'tk-fields' ),
				'label_off'    => __( 'Hide', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Show a short excerpt under the meta row. The line count comes from Excerpt Lines below.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'excerpt_lines',
			array(
				'label'        => __( 'Excerpt Lines', 'tk-fields' ),
				'type'         => Controls_Manager::NUMBER,
				'default'      => 3,
				'min'          => 0,
				'max'          => 10,
				'step'         => 1,
				'condition'    => array( 'show_excerpt' => 'yes' ),
				'description'  => __( 'How many lines the excerpt may take — longer text is cut with an ellipsis so excerpts occupy uniform space. Set to 0 to hide the excerpt.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'excerpt_length',
			array(
				'label'       => __( 'Excerpt Length (words)', 'tk-fields' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => 20,
				'min'         => 5,
				'max'         => 200,
				'step'        => 1,
				'condition'   => array( 'show_excerpt' => 'yes' ),
				'description' => __( 'Word count used when a post has no manual excerpt. Manual excerpts are always shown as written.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'read_more_text',
			array(
				'label'       => __( 'Read More Text', 'tk-fields' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Read more', 'tk-fields' ),
				'description' => __( 'Link text under each card. Leave empty to hide the link.', 'tk-fields' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content section: TK field rows shown on every card.
	 */
	protected function register_fields_controls(): void {
		$this->start_controls_section(
			'tk_post_listing_fields',
			array(
				'label' => __( 'TK Fields', 'tk-fields' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$repeater = new \Elementor\Repeater();

		$repeater->add_control(
			'field_key',
			array(
				'label'       => __( 'Field', 'tk-fields' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => $this->field_options(),
				'description' => __( 'The TK field to show on each card. Values come from each listed post — posts with nothing stored for this field skip the row.', 'tk-fields' ),
			)
		);

		$repeater->add_control(
			'field_label',
			array(
				'label'       => __( 'Custom Label', 'tk-fields' ),
				'type'        => Controls_Manager::TEXT,
				'description' => __( 'Optional label shown before the value. Leave empty to use the field’s own label.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'card_fields',
			array(
				'label'       => __( 'Fields', 'tk-fields' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'title_field' => '{{{ field_label || field_key }}}',
				'description' => __( 'Extra rows on each card — e.g. a “Price” or “Rating” field. Rows render only when the post actually has a value.', 'tk-fields' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content section: pagination and the empty state.
	 */
	protected function register_pagination_controls(): void {
		$this->start_controls_section(
			'tk_post_listing_pagination',
			array(
				'label' => __( 'Pagination & Empty State', 'tk-fields' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'show_pagination',
			array(
				'label'        => __( 'Numbered Pagination', 'tk-fields' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'tk-fields' ),
				'label_off'    => __( 'Hide', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => '',
				'description'  => __( 'Show numbered prev/next links under the grid on the live page. Page 1 always shows inside the Elementor editor preview.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'empty_text',
			array(
				'label'       => __( 'Empty Message', 'tk-fields' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'No posts found — try adjusting filters.', 'tk-fields' ),
				'description' => __( 'Shown in a styled notice when the query returns nothing.', 'tk-fields' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: card chrome, image, title, meta, excerpt, read-more.
	 */
	protected function register_style_controls(): void {
		$this->start_controls_section(
			'tk_post_listing_style_card',
			array(
				'label' => __( 'Card', 'tk-fields' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'        => 'card_background',
				'label'       => __( 'Background', 'tk-fields' ),
				'types'       => array( 'classic', 'gradient' ),
				'selector'    => '{{WRAPPER}} .tk-post-card',
				'description' => __( 'Card background. Leave the default to inherit the plain surface.', 'tk-fields' ),
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'        => 'card_border',
				'label'       => __( 'Border', 'tk-fields' ),
				'selector'    => '{{WRAPPER}} .tk-post-card',
				'description' => __( 'The 1px translucent card border.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'card_radius',
			array(
				'label'       => __( 'Border Radius', 'tk-fields' ),
				'type'        => Controls_Manager::DIMENSIONS,
				'size_units'  => array( 'px', '%' ),
				'default'     => array(
					'unit'  => 'px',
					'top'   => '16',
					'right' => '16',
					'bottom'=> '16',
					'left'  => '16',
				),
				'selectors'   => array(
					'{{WRAPPER}} .tk-post-card' => '--tk-post-card-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'description' => __( 'Corner rounding for the card (and the image, which follows the top corners).', 'tk-fields' ),
			)
		);

		$this->add_control(
			'card_padding',
			array(
				'label'       => __( 'Body Padding', 'tk-fields' ),
				'type'        => Controls_Manager::DIMENSIONS,
				'size_units'  => array( 'px' ),
				'default'     => array(
					'unit'  => 'px',
					'top'   => '20',
					'right' => '20',
					'bottom'=> '20',
					'left'  => '20',
				),
				'selectors'   => array(
					'{{WRAPPER}} .tk-post-card__body' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
				'description' => __( 'Inner spacing of the card body. The image stays full-bleed at the top.', 'tk-fields' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'tk_post_listing_style_title',
			array(
				'label' => __( 'Title', 'tk-fields' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'        => 'title_typography',
				'label'       => __( 'Typography', 'tk-fields' ),
				'selector'    => '{{WRAPPER}} .tk-post-card__title',
				'description' => __( 'Card title type. Weight 600 is the designed default — the control ships pre-set to it.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'title_color',
			array(
				'label'       => __( 'Color', 'tk-fields' ),
				'type'        => Controls_Manager::COLOR,
				'selectors'   => array(
					'{{WRAPPER}} .tk-post-card__title, {{WRAPPER}} .tk-post-card__title a' => 'color: {{VALUE}};',
				),
				'description' => __( 'Title color. Empty inherits the theme text color.', 'tk-fields' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'tk_post_listing_style_meta',
			array(
				'label' => __( 'Meta', 'tk-fields' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'        => 'meta_typography',
				'label'       => __( 'Typography', 'tk-fields' ),
				'selector'    => '{{WRAPPER}} .tk-post-card__meta',
				'description' => __( 'The small date · terms row. Micro-type keeps it quiet next to the title.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'meta_color',
			array(
				'label'       => __( 'Color', 'tk-fields' ),
				'type'        => Controls_Manager::COLOR,
				'selectors'   => array(
					'{{WRAPPER}} .tk-post-card__meta' => 'color: {{VALUE}}; opacity: 1;',
				),
				'description' => __( 'Meta row color. Empty keeps the default muted treatment.', 'tk-fields' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'tk_post_listing_style_excerpt',
			array(
				'label' => __( 'Excerpt & Fields', 'tk-fields' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'        => 'excerpt_typography',
				'label'       => __( 'Excerpt Typography', 'tk-fields' ),
				'selector'    => '{{WRAPPER}} .tk-post-card__excerpt',
				'description' => __( 'Excerpt type. The three-line clamp is fixed so cards stay even.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'excerpt_color',
			array(
				'label'       => __( 'Excerpt Color', 'tk-fields' ),
				'type'        => Controls_Manager::COLOR,
				'selectors'   => array(
					'{{WRAPPER}} .tk-post-card__excerpt' => 'color: {{VALUE}};',
				),
				'description' => __( 'Excerpt color. Empty inherits the theme text color.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'field_label_color',
			array(
				'label'       => __( 'Field Label Color', 'tk-fields' ),
				'type'        => Controls_Manager::COLOR,
				'selectors'   => array(
					'{{WRAPPER}} .tk-post-card__field-label' => 'color: {{VALUE}}; opacity: 1;',
				),
				'description' => __( 'Color of the custom field row labels (e.g. “Price”).', 'tk-fields' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'tk_post_listing_style_more',
			array(
				'label' => __( 'Read More', 'tk-fields' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'        => 'more_typography',
				'label'       => __( 'Typography', 'tk-fields' ),
				'selector'    => '{{WRAPPER}} .tk-post-card__more',
				'description' => __( 'Read-more link type.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'more_color',
			array(
				'label'       => __( 'Color', 'tk-fields' ),
				'type'        => Controls_Manager::COLOR,
				'selectors'   => array(
					'{{WRAPPER}} .tk-post-card__more' => 'color: {{VALUE}};',
				),
				'description' => __( 'Read-more link color. Empty uses the TK accent blue.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'more_hover_color',
			array(
				'label'       => __( 'Hover Color', 'tk-fields' ),
				'type'        => Controls_Manager::COLOR,
				'selectors'   => array(
					'{{WRAPPER}} .tk-post-card__more:hover' => 'color: {{VALUE}};',
				),
				'description' => __( 'Read-more link color on hover.', 'tk-fields' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render the listing — delegates to the shared renderer (v0.16.2),
	 * the same engine the Gutenberg tk/post-listing block uses.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();
		Post_Listing_Renderer::render( $settings, $this->current_page() );
	}

	/**
	 * Build the WP_Query from the widget settings.
	 *
	 * @param array<string, mixed> $settings
	 */
	protected function build_query( array $settings ): \WP_Query {
		return Post_Listing_Renderer::build_query( $settings, $this->current_page(), Elementor_Context::resolve_post_id() );
	}

	/**
	 * Open the listing wrapper with layout class + card ratio var.
	 *
	 * @param array<string, mixed> $settings
	 */
	protected function render_open( array $settings ): void {
		Post_Listing_Renderer::render_open( $settings );
	}

	/**
	 * Render one card.
	 */
	protected function render_card( \WP_Post $post, array $settings ): void {
		Post_Listing_Renderer::render_card( $post, $settings );
	}

	/**
	 * Render the micro-type meta row: date · terms.
	 *
	 * @param array<string, mixed> $settings
	 */
	protected function render_meta( \WP_Post $post, array $settings ): void {
		Post_Listing_Renderer::render_meta( $post, $settings );
	}

	/**
	 * Render the TK field rows for one card. Every value resolves through
	 * Fields::get() — the formatted Fields API, never postmeta.
	 *
	 * @param array<string, mixed> $settings
	 */
	protected function render_card_fields( \WP_Post $post, array $settings ): void {
		Post_Listing_Renderer::render_card_fields( $post, $settings );
	}

	/**
	 * Format one resolved field value as display HTML, mirroring the
	 * typed Elementor dynamic tags (class-text-tag.php etc.). All output
	 * is escaped or save-sanitized HTML.
	 *
	 * @param array<string, mixed> $def  Registry field definition.
	 * @param mixed                $value Formatted value from Fields::get().
	 */
	protected function format_field_value( array $def, mixed $value ): string {
		return Post_Listing_Renderer::format_field_value( $def, $value );
	}

	/**
	 * Render the designed empty state.
	 */
	protected function render_empty( string $message ): void {
		Post_Listing_Renderer::render_empty( $message );
	}

	/**
	 * Render numbered prev/next pagination under the listing.
	 *
	 * @param array<string, mixed> $settings
	 */
	protected function render_pagination( \WP_Query $query, array $settings ): void {
		Post_Listing_Renderer::render_pagination( $query, $settings, $this->current_page() );
	}

	/**
	 * Current page number for pagination. Always 1 inside the Elementor
	 * editor preview (live pagination only makes sense on the frontend).
	 */
	protected function current_page(): int {
		if ( $this->is_editor() ) {
			return 1;
		}

		$paged = get_query_var( 'paged' );
		if ( ! $paged ) {
			$paged = get_query_var( 'page' );
		}

		return max( 1, absint( $paged ) );
	}

	/**
	 * Whether we're inside the Elementor editor.
	 */
	protected function is_editor(): bool {
		return class_exists( '\Elementor\Plugin' )
			&& isset( \Elementor\Plugin::$instance->editor )
			&& \Elementor\Plugin::$instance->editor->is_edit_mode();
	}

	/**
	 * Public post types (incl. TK Content Types) as select options.
	 *
	 * @return array<string, string>
	 */
	protected function post_type_options(): array {
		$types   = get_post_types( array( 'public' => true ), 'objects' );
		$options = array();
		foreach ( $types as $type ) {
			$options[ $type->name ] = $type->labels->singular_name . ' (' . $type->name . ')';
		}
		return $options;
	}

	/**
	 * All registered TK fields as select options for the fields repeater.
	 *
	 * @return array<string, string>
	 */
	protected function field_options(): array {
		$options = array();
		foreach ( Field_Registry::instance()->all() as $name => $field ) {
			$type = (string) ( $field['type'] ?? '' );
			if ( in_array( $type, array( 'message', 'separator', 'tab' ), true ) ) {
				continue; // Layout-only: stores nothing.
			}
			$options[ $name ] = sprintf(
				/* translators: 1: field label, 2: field name, 3: field type. */
				__( '%1$s (%2$s) — %3$s', 'tk-fields' ),
				$field['label'] ?? $name,
				$name,
				$type
			);
		}

		if ( empty( $options ) ) {
			$options = array( '' => __( 'No TK fields yet — add a field group first', 'tk-fields' ) );
		}

		return $options;
	}
}
