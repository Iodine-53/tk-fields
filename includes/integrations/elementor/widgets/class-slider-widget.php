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
 * Elementor "TK Slider" widget.
 *
 * A content slider built with the native Elementor Widget_Base API: each
 * slide pairs an image with an optional title, text, and call-to-action
 * button over a legibility scrim. Transitions (slide / fade / slide+fade)
 * are CSS-driven; a small frontend script (assets/elementor/js/tk-slider.js)
 * handles arrows, dots, autoplay with pause-on-hover, a progress bar, and
 * touch swiping. It also re-initializes on Elementor editor re-renders.
 *
 * Styling consumes the shared design tokens (assets/shared/tk-widgets.css,
 * handle `tk-widgets`) plus this widget's own stylesheet
 * (assets/elementor/css/tk-slider.css).
 *
 * @package TK\Fields\Integrations\Elementor\Widgets
 */

declare(strict_types=1);

namespace TK\Fields\Integrations\Elementor\Widgets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Slider widget.
 */
final class Slider_Widget extends \Elementor\Widget_Base {

	/**
	 * Register this widget's stylesheet and script handles.
	 *
	 * @param array $data Widget data.
	 * @param array|null $args Widget arguments.
	 */
	public function __construct( $data = array(), $args = null ) {
		parent::__construct( $data, $args );

		wp_register_style(
			'tk-widget-slider',
			plugins_url( 'assets/elementor/css/tk-slider.css', TK_FIELDS_FILE ),
			array(),
			TK_FIELDS_VERSION
		);

		wp_register_script(
			'tk-widget-slider',
			plugins_url( 'assets/elementor/js/tk-slider.js', TK_FIELDS_FILE ),
			array(),
			TK_FIELDS_VERSION,
			true
		);
	}

	/**
	 * @return string
	 */
	public function get_name(): string {
		return 'tk-slider';
	}

	/**
	 * @return string
	 */
	public function get_title(): string {
		return __( 'TK Slider', 'tk-fields' );
	}

	/**
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-slider-device';
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
		return array( 'tk', 'slider', 'carousel', 'slides', 'slideshow', 'banner', 'hero' );
	}

	/**
	 * @return array<int, string>
	 */
	public function get_style_depends(): array {
		return array( 'tk-widgets', 'tk-widget-slider' );
	}

	/**
	 * @return array<int, string>
	 */
	public function get_script_depends(): array {
		return array( 'tk-widget-slider' );
	}

	/**
	 * Register the widget's controls.
	 */
	protected function register_controls(): void {
		$this->register_content_controls();
		$this->register_style_controls();
	}

	/**
	 * Content tab: slides and behavior.
	 */
	private function register_content_controls(): void {
		$this->start_controls_section(
			'tk_slider_content',
			array(
				'label' => __( 'Content', 'tk-fields' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$repeater = new \Elementor\Repeater();

		$repeater->add_control(
			'image',
			array(
				'label'       => __( 'Image', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::MEDIA,
				'description' => __( 'The slide background image. Landscape images work best.', 'tk-fields' ),
				'dynamic'     => array( 'active' => true ),
			)
		);

		$repeater->add_control(
			'title',
			array(
				'label'       => __( 'Title', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Slide title', 'tk-fields' ),
				'description' => __( 'Headline shown over the image. Leave empty to hide it.', 'tk-fields' ),
				'dynamic'     => array( 'active' => true ),
			)
		);

		$repeater->add_control(
			'text',
			array(
				'label'       => __( 'Text', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'rows'        => 3,
				'description' => __( 'Short supporting copy under the title. Leave empty to hide it.', 'tk-fields' ),
				'dynamic'     => array( 'active' => true ),
			)
		);

		$repeater->add_control(
			'button_text',
			array(
				'label'       => __( 'Button Text', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'description' => __( 'Call-to-action label. The button only appears when this has text.', 'tk-fields' ),
				'dynamic'     => array( 'active' => true ),
			)
		);

		$repeater->add_control(
			'button_url',
			array(
				'label'       => __( 'Button Link', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::URL,
				'placeholder' => __( 'https://example.com', 'tk-fields' ),
				'description' => __( 'Where the button points. Supports "open in new tab" and nofollow.', 'tk-fields' ),
				'dynamic'     => array( 'active' => true ),
			)
		);

		$this->add_control(
			'slides',
			array(
				'label'       => __( 'Slides', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => array(
					array(
						'title' => __( 'Designed out of the box', 'tk-fields' ),
						'text'  => __( 'Drop the slider in and it already looks premium — generous type, soft motion, legible captions.', 'tk-fields' ),
					),
					array(
						'title' => __( 'Tuned for every screen', 'tk-fields' ),
						'text'  => __( 'Arrows stay visible on touch devices; hover reveals them on desktop.', 'tk-fields' ),
					),
					array(
						'title' => __( 'Motion with manners', 'tk-fields' ),
						'text'  => __( 'Autoplay pauses on hover and everything stops for reduced-motion users.', 'tk-fields' ),
					),
				),
				'title_field' => '{{{ title }}}',
				'description' => __( 'Each row is one slide. Add at least two for the arrows and dots to appear.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'transition',
			array(
				'label'       => __( 'Transition', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'slide',
				'options'     => array(
					'slide'      => __( 'Slide', 'tk-fields' ),
					'fade'       => __( 'Fade', 'tk-fields' ),
					'slide-fade' => __( 'Slide + Fade', 'tk-fields' ),
				),
				'description' => __( 'How one slide gives way to the next.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'autoplay',
			array(
				'label'        => __( 'Autoplay', 'tk-fields' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'tk-fields' ),
				'label_off'    => __( 'No', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Advance slides automatically. Pauses while the tab is hidden.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'autoplay_delay',
			array(
				'label'       => __( 'Autoplay Delay', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => 5000,
				'min'         => 1000,
				'max'         => 20000,
				'step'        => 500,
				'condition'   => array( 'autoplay' => 'yes' ),
				'description' => __( 'Milliseconds each slide stays on screen before advancing.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'pause_on_hover',
			array(
				'label'        => __( 'Pause on Hover', 'tk-fields' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'tk-fields' ),
				'label_off'    => __( 'No', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'condition'    => array( 'autoplay' => 'yes' ),
				'description'  => __( 'Hold the slide while the pointer is over the slider, and show the progress bar.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'show_arrows',
			array(
				'label'        => __( 'Show Arrows', 'tk-fields' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'tk-fields' ),
				'label_off'    => __( 'No', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Previous/next buttons. Hidden automatically when there is only one slide.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'show_dots',
			array(
				'label'        => __( 'Show Dots', 'tk-fields' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'tk-fields' ),
				'label_off'    => __( 'No', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Clickable dot navigation. The active dot stretches into a pill.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'aspect_ratio',
			array(
				'label'       => __( 'Aspect Ratio', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => '16 / 9',
				'options'     => array(
					'16 / 9' => __( '16:9 — widescreen', 'tk-fields' ),
					'3 / 2'  => __( '3:2 — classic photo', 'tk-fields' ),
					'4 / 3'  => __( '4:3 — boxy', 'tk-fields' ),
					'21 / 9' => __( '21:9 — ultrawide', 'tk-fields' ),
					'1 / 1'  => __( '1:1 — square', 'tk-fields' ),
				),
				'description' => __( 'The slider keeps this shape at every width instead of using fixed heights.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-slider' => 'aspect-ratio: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'image_fit',
			array(
				'label'       => __( 'Image Fit', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'cover',
				'options'     => array(
					'cover'   => __( 'Cover — fill the slide', 'tk-fields' ),
					'contain' => __( 'Contain — show the whole image', 'tk-fields' ),
				),
				'description' => __( 'Cover crops to fill the slide; contain letterboxes the full image.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-slide img' => 'object-fit: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: arrows, dots, captions, motion.
	 */
	private function register_style_controls(): void {
		$this->start_controls_section(
			'tk_slider_style_arrows',
			array(
				'label' => __( 'Arrows', 'tk-fields' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'arrow_color',
			array(
				'label'       => __( 'Arrow Color', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Color of the chevron glyph inside the arrow button.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-slider-arrow' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'arrow_background',
			array(
				'label'       => __( 'Arrow Background', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Background of the circular arrow button. Empty uses frosted white.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-slider-arrow' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'arrow_size',
			array(
				'label'       => __( 'Arrow Size', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 28,
						'max'  => 72,
						'step' => 1,
					),
				),
				'description' => __( 'Diameter of the circular arrow buttons.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-slider-arrow' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'tk_slider_style_dots',
			array(
				'label' => __( 'Dots & Progress', 'tk-fields' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'dot_color',
			array(
				'label'       => __( 'Dot Color', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Color of inactive dots.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-slider-dot' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'dot_active_color',
			array(
				'label'       => __( 'Active Dot Color', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Color of the stretched active dot.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-slider-dot.is-active' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'progress_color',
			array(
				'label'       => __( 'Progress Bar Color', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Thin bar at the top showing autoplay progress. Only visible with autoplay on.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-slider-progress span' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'tk_slider_style_caption',
			array(
				'label' => __( 'Caption', 'tk-fields' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'caption_title_typography',
				'label'    => __( 'Title Typography', 'tk-fields' ),
				'selector' => '{{WRAPPER}} .tk-el-slide-title',
			)
		);

		$this->add_control(
			'caption_title_color',
			array(
				'label'       => __( 'Title Color', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Headline color over the image. Empty keeps white for scrim legibility.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-slide-title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'caption_text_typography',
				'label'    => __( 'Text Typography', 'tk-fields' ),
				'selector' => '{{WRAPPER}} .tk-el-slide-text',
			)
		);

		$this->add_control(
			'caption_text_color',
			array(
				'label'       => __( 'Text Color', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Supporting copy color. Empty keeps soft white.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-slide-text' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'caption_padding',
			array(
				'label'       => __( 'Caption Padding', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units'  => array( 'px', 'em', 'rem' ),
				'description' => __( 'Space around the caption block at the bottom of each slide.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-slide-caption' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'overlay_opacity',
			array(
				'label'       => __( 'Overlay Opacity', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => array( '%' ),
				'default'     => array(
					'size' => 30,
					'unit' => '%',
				),
				'range'       => array(
					'%' => array(
						'min'  => 0,
						'max'  => 100,
						'step' => 1,
					),
				),
				'description' => __( 'Dark tint over the whole image so captions stay readable. 0 disables it.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-slider' => '--tk-el-slider-overlay: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'slide_border_radius',
			array(
				'label'       => __( 'Slider Border Radius', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units'  => array( 'px', '%' ),
				'description' => __( 'Rounded corners for the whole slider frame.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-slider' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'tk_slider_style_button',
			array(
				'label' => __( 'Button', 'tk-fields' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'button_background',
			array(
				'label'       => __( 'Button Background', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Background of the slide call-to-action button. Empty uses the TK accent blue.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-slide-button' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_color',
			array(
				'label'       => __( 'Button Text Color', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Label color of the slide button. Empty keeps white.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-slide-button' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_background_hover',
			array(
				'label'       => __( 'Button Background (Hover)', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Button background while hovered.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-slide-button:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'label'    => __( 'Button Typography', 'tk-fields' ),
				'selector' => '{{WRAPPER}} .tk-el-slide-button',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'tk_slider_style_motion',
			array(
				'label' => __( 'Motion', 'tk-fields' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'duration',
			array(
				'label'       => __( 'Transition Duration', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => array( 'ms' ),
				'default'     => array(
					'size' => 420,
					'unit' => 'ms',
				),
				'range'       => array(
					'ms' => array(
						'min'  => 100,
						'max'  => 1500,
						'step' => 10,
					),
				),
				'description' => __( 'How long each slide transition takes. 420ms is the TK default.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-slider' => '--tk-el-slider-dur: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'easing',
			array(
				'label'              => __( 'Transition Easing', 'tk-fields' ),
				'type'               => \Elementor\Controls_Manager::SELECT,
				'default'            => 'smooth',
				'options'            => array(
					'smooth' => __( 'Smooth', 'tk-fields' ),
					'snappy' => __( 'Snappy', 'tk-fields' ),
					'gentle' => __( 'Gentle', 'tk-fields' ),
				),
				'description'        => __( 'The motion curve of the transition: Smooth glides out, Snappy is decisive, Gentle is the soft standard ease.', 'tk-fields' ),
				'selectors'          => array(
					'{{WRAPPER}} .tk-el-slider' => '--tk-el-slider-ease: {{VALUE}};',
				),
				'selectors_dictionary' => array(
					'smooth' => 'cubic-bezier(0.22, 1, 0.36, 1)',
					'snappy' => 'cubic-bezier(0.3, 0.7, 0.4, 1)',
					'gentle' => 'cubic-bezier(0.4, 0, 0.2, 1)',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render the slider.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();

		$slides = $settings['slides'] ?? array();
		if ( empty( $slides ) || ! is_array( $slides ) ) {
			return;
		}

		$transition = (string) ( $settings['transition'] ?? 'slide' );
		if ( ! in_array( $transition, array( 'slide', 'fade', 'slide-fade' ), true ) ) {
			$transition = 'slide';
		}

		$autoplay    = 'yes' === ( $settings['autoplay'] ?? 'yes' );
		$delay       = absint( $settings['autoplay_delay'] ?? 5000 );
		$delay       = max( 1000, min( 20000, $delay ) );
		$pause_hover = 'yes' === ( $settings['pause_on_hover'] ?? 'yes' );
		$show_arrows = 'yes' === ( $settings['show_arrows'] ?? 'yes' );
		$show_dots   = 'yes' === ( $settings['show_dots'] ?? 'yes' );

		$total = count( $slides );

		$this->add_render_attribute(
			'wrapper',
			array(
				'class'             => array(
					'tk-el-slider',
					'tk-el-slider--' . $transition,
				),
				'data-autoplay'     => $autoplay ? '1' : null,
				'data-delay'        => (string) $delay,
				'data-pause-hover'  => $pause_hover ? '1' : '0',
				'role'              => 'region',
				'aria-roledescription' => 'carousel',
				'aria-label'        => __( 'Image slider', 'tk-fields' ),
			)
		);

		// get_render_attribute_string() escapes attribute values via esc_attr() inside Elementor core.
		echo '<div ' . $this->get_render_attribute_string( 'wrapper' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="tk-el-slides">';

		foreach ( $slides as $index => $slide ) {
			$image = $slide['image'] ?? array();
			$image_id  = isset( $image['id'] ) ? absint( $image['id'] ) : 0;
			$image_url = isset( $image['url'] ) ? (string) $image['url'] : '';

			$title       = isset( $slide['title'] ) ? (string) $slide['title'] : '';
			$text        = isset( $slide['text'] ) ? (string) $slide['text'] : '';
			$button_text = isset( $slide['button_text'] ) ? (string) $slide['button_text'] : '';
			$button_url  = $slide['button_url'] ?? array();
			$link        = isset( $button_url['url'] ) ? (string) $button_url['url'] : '';
			$link_target = ! empty( $button_url['is_external'] ) ? ' target="_blank"' : '';
			$link_rel    = ! empty( $button_url['nofollow'] ) ? ' rel="nofollow"' : '';

			/* translators: 1: slide number, 2: total number of slides */
			echo '<div class="tk-el-slide' . ( 0 === $index ? ' is-active' : '' ) . '" role="group" aria-roledescription="slide" aria-label="' . esc_attr( sprintf( __( 'Slide %1$d of %2$d', 'tk-fields' ), $index + 1, $total ) ) . '">';

			if ( $image_id ) {
				echo wp_get_attachment_image(
					$image_id,
					'large',
					false,
					array(
						'class'   => 'tk-el-slide-image',
						'alt'     => '' !== $title ? $title : __( 'Slider image', 'tk-fields' ),
					)
				);
			} elseif ( '' !== $image_url ) {
				echo '<img class="tk-el-slide-image" src="' . esc_url( $image_url ) . '" alt="' . esc_attr( '' !== $title ? $title : __( 'Slider image', 'tk-fields' ) ) . '"' . ' />';
			}

			echo '<div class="tk-el-slider-overlay" aria-hidden="true"></div>';

			if ( '' !== $title || '' !== $text || '' !== $button_text ) {
				echo '<div class="tk-el-slide-caption">';
				if ( '' !== $title ) {
					echo '<h3 class="tk-el-slide-title">' . esc_html( $title ) . '</h3>';
				}
				if ( '' !== $text ) {
					echo '<p class="tk-el-slide-text">' . esc_html( $text ) . '</p>';
				}
				if ( '' !== $button_text ) {
					// $link_target / $link_rel are static fragments built above: ' target="_blank"' / ' rel="nofollow"' / ''.
					echo '<a class="tk-el-slide-button" href="' . esc_url( $link ) . '"' . $link_target . $link_rel . '>' . esc_html( $button_text ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				echo '</div>';
			}

			echo '</div>';
		}

		echo '</div>';

		if ( $show_arrows && $total > 1 ) {
			echo '<button type="button" class="tk-el-slider-arrow tk-el-slider-prev" aria-label="' . esc_attr__( 'Previous slide', 'tk-fields' ) . '"><svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg></button>';
			echo '<button type="button" class="tk-el-slider-arrow tk-el-slider-next" aria-label="' . esc_attr__( 'Next slide', 'tk-fields' ) . '"><svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></button>';
		}

		if ( $show_dots && $total > 1 ) {
			echo '<div class="tk-el-slider-dots" role="tablist" aria-label="' . esc_attr__( 'Choose slide', 'tk-fields' ) . '">';
			for ( $i = 0; $i < $total; $i++ ) {
				/* translators: %d: slide number */
				echo '<button type="button" class="tk-el-slider-dot' . ( 0 === $i ? ' is-active' : '' ) . '" data-index="' . esc_attr( (string) $i ) . '" aria-label="' . esc_attr( sprintf( __( 'Go to slide %1$d', 'tk-fields' ), $i + 1 ) ) . '"></button>';
			}
			echo '</div>';
		}

		if ( $autoplay && $total > 1 ) {
			echo '<div class="tk-el-slider-progress" aria-hidden="true"><span></span></div>';
		}

		echo '</div>';
	}
}
