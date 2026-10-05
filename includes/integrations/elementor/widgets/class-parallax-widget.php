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
 * Elementor "TK Parallax" widget.
 *
 * A full-bleed content band with a slower-drifting background, built with
 * the native Elementor Widget_Base API. The background layer is translated
 * by a frontend script (assets/elementor/js/tk-parallax.js) that combines
 * rAF with an IntersectionObserver gate — no work happens off-screen, and
 * under prefers-reduced-motion (or the disable-on-mobile setting) the band
 * degrades to a static cover background.
 *
 * Always pair the background with an overlay preset (none / bottom /
 * full) so foreground text stays legible — that is the difference between
 * an effect and a designed section.
 *
 * Styling consumes the shared design tokens (assets/shared/tk-widgets.css,
 * handle `tk-widgets`) plus this widget's own stylesheet
 * (assets/elementor/css/tk-parallax.css).
 *
 * @package TK\Fields\Integrations\Elementor\Widgets
 */

declare(strict_types=1);

namespace TK\Fields\Integrations\Elementor\Widgets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parallax band widget.
 */
final class Parallax_Widget extends \Elementor\Widget_Base {

	/**
	 * Register this widget's stylesheet and script handles.
	 *
	 * @param array $data Widget data.
	 * @param array|null $args Widget arguments.
	 */
	public function __construct( $data = array(), $args = null ) {
		parent::__construct( $data, $args );

		wp_register_style(
			'tk-widget-parallax',
			plugins_url( 'assets/elementor/css/tk-parallax.css', TK_FIELDS_FILE ),
			array(),
			TK_FIELDS_VERSION
		);

		wp_register_script(
			'tk-widget-parallax',
			plugins_url( 'assets/elementor/js/tk-parallax.js', TK_FIELDS_FILE ),
			array(),
			TK_FIELDS_VERSION,
			true
		);
	}

	/**
	 * @return string
	 */
	public function get_name(): string {
		return 'tk-parallax';
	}

	/**
	 * @return string
	 */
	public function get_title(): string {
		return __( 'TK Parallax', 'tk-fields' );
	}

	/**
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-image-rollover';
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
		return array( 'tk', 'parallax', 'background', 'band', 'hero', 'scroll effect' );
	}

	/**
	 * @return array<int, string>
	 */
	public function get_style_depends(): array {
		return array( 'tk-widgets', 'tk-widget-parallax' );
	}

	/**
	 * @return array<int, string>
	 */
	public function get_script_depends(): array {
		return array( 'tk-widget-parallax' );
	}

	/**
	 * Register the widget's controls.
	 */
	protected function register_controls(): void {
		$this->register_content_controls();
		$this->register_style_controls();
	}

	/**
	 * Content tab: background, content, overlay, motion.
	 */
	private function register_content_controls(): void {
		$this->start_controls_section(
			'tk_parallax_content',
			array(
				'label' => __( 'Content', 'tk-fields' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'background',
			array(
				'label'       => __( 'Background Image', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::MEDIA,
				'description' => __( 'The image that drifts behind the content. Wide, high-resolution images look best.', 'tk-fields' ),
				'dynamic'     => array( 'active' => true ),
			)
		);

		$this->add_control(
			'content',
			array(
				'label'       => __( 'Content', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::WYSIWYG,
				'default'     => __( '<h2>A designed section, not an effect</h2><p>Pair every parallax background with an overlay so your words stay legible over any image.</p>', 'tk-fields' ),
				'description' => __( 'The foreground copy, centered over the drifting background. Shortcodes run here too.', 'tk-fields' ),
				'dynamic'     => array( 'active' => true ),
			)
		);

		$this->add_control(
			'speed',
			array(
				'label'       => __( 'Parallax Speed', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => array( '' ),
				'default'     => array(
					'size' => 0.35,
				),
				'range'       => array(
					'' => array(
						'min'  => 0,
						'max'  => 1,
						'step' => 0.05,
					),
				),
				'description' => __( 'How much the background drifts against the scroll: 0 is static, 1 matches the scroll speed. 0.35 is the TK default.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'overlay_preset',
			array(
				'label'       => __( 'Overlay Preset', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'bottom',
				'options'     => array(
					'none'   => __( 'None', 'tk-fields' ),
					'bottom' => __( 'Bottom gradient', 'tk-fields' ),
					'full'   => __( 'Full cover', 'tk-fields' ),
				),
				'description' => __( 'A dark scrim over the image so text stays legible: a gradient rising from the bottom, or a full cover.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'overlay_opacity',
			array(
				'label'       => __( 'Overlay Opacity', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => array( '%' ),
				'default'     => array(
					'size' => 55,
					'unit' => '%',
				),
				'range'       => array(
					'%' => array(
						'min'  => 0,
						'max'  => 100,
						'step' => 1,
					),
				),
				'condition'   => array( 'overlay_preset!' => 'none' ),
				'description' => __( 'Strength of the overlay. Raise it for busy images, lower it for dark ones.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-parallax' => '--tk-el-parallax-overlay-opacity: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'disable_on_mobile',
			array(
				'label'        => __( 'Disable on Mobile', 'tk-fields' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'tk-fields' ),
				'label_off'    => __( 'No', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'On phones the background becomes a static cover — kinder to batteries and shaky scroll.', 'tk-fields' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: sizing, content column, overlay color, type.
	 */
	private function register_style_controls(): void {
		$this->start_controls_section(
			'tk_parallax_style_band',
			array(
				'label' => __( 'Band', 'tk-fields' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'min_height',
			array(
				'label'       => __( 'Minimum Height', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => array( 'px', 'vh' ),
				'default'     => array(
					'size' => 420,
					'unit' => 'px',
				),
				'range'       => array(
					'px' => array(
						'min'  => 160,
						'max'  => 1000,
						'step' => 10,
					),
					'vh' => array(
						'min'  => 20,
						'max'  => 100,
						'step' => 1,
					),
				),
				'description' => __( 'How tall the band is at minimum. Content can still make it taller.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-parallax' => 'min-height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'vertical_align',
			array(
				'label'       => __( 'Content Vertical Position', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::CHOOSE,
				'default'     => 'center',
				'options'     => array(
					'top'    => array(
						'title' => __( 'Top', 'tk-fields' ),
						'icon'  => 'eicon-v-align-top',
					),
					'center' => array(
						'title' => __( 'Middle', 'tk-fields' ),
						'icon'  => 'eicon-v-align-middle',
					),
					'bottom' => array(
						'title' => __( 'Bottom', 'tk-fields' ),
						'icon'  => 'eicon-v-align-bottom',
					),
				),
				'description' => __( 'Where the content column sits inside the band.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-parallax' => 'align-items: {{VALUE}};',
				),
				'selectors_dictionary' => array(
					'top'    => 'flex-start',
					'center' => 'center',
					'bottom' => 'flex-end',
				),
			)
		);

		$this->add_control(
			'content_max_width',
			array(
				'label'       => __( 'Content Max Width', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => array( 'px', 'rem' ),
				'default'     => array(
					'size' => 42,
					'unit' => 'rem',
				),
				'range'       => array(
					'px'  => array(
						'min'  => 320,
						'max'  => 1400,
						'step' => 10,
					),
					'rem' => array(
						'min'  => 20,
						'max'  => 90,
						'step' => 1,
					),
				),
				'description' => __( 'Narrower columns read better over imagery. Full-width kills legibility.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-parallax-content' => 'max-width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'content_padding',
			array(
				'label'       => __( 'Content Padding', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units'  => array( 'px', 'em', 'rem' ),
				'description' => __( 'Breathing room around the content column.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-parallax-content' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'border_radius',
			array(
				'label'       => __( 'Band Border Radius', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units'  => array( 'px', '%' ),
				'description' => __( 'Rounded corners for the whole band. 0 keeps it edge-to-edge.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-parallax' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'tk_parallax_style_overlay',
			array(
				'label' => __( 'Overlay', 'tk-fields' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'overlay_color',
			array(
				'label'       => __( 'Overlay Color', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'default'     => '#000000',
				'description' => __( 'Tint of the overlay scrim. Black keeps text legible; brand colors add mood.', 'tk-fields' ),
				'condition'   => array( 'overlay_preset!' => 'none' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-parallax' => '--tk-el-parallax-overlay-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'tk_parallax_style_content',
			array(
				'label' => __( 'Content', 'tk-fields' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'content_color',
			array(
				'label'       => __( 'Text Color', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Foreground text color. Empty keeps white, which pairs with the dark overlay.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-parallax-content' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'content_typography',
				'label'    => __( 'Content Typography', 'tk-fields' ),
				'selector' => '{{WRAPPER}} .tk-el-parallax-content',
			)
		);

		$this->add_control(
			'text_align',
			array(
				'label'       => __( 'Text Alignment', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::CHOOSE,
				'default'     => 'center',
				'options'     => array(
					'left'   => array(
						'title' => __( 'Left', 'tk-fields' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center' => array(
						'title' => __( 'Center', 'tk-fields' ),
						'icon'  => 'eicon-text-align-center',
					),
					'right'  => array(
						'title' => __( 'Right', 'tk-fields' ),
						'icon'  => 'eicon-text-align-right',
					),
				),
				'description' => __( 'Horizontal alignment of the content column.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-parallax-content' => 'text-align: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render the parallax band.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();

		$background  = $settings['background'] ?? array();
		$image_id    = isset( $background['id'] ) ? absint( $background['id'] ) : 0;
		$image_url   = isset( $background['url'] ) ? (string) $background['url'] : '';
		$content     = (string) ( $settings['content'] ?? '' );

		$speed = isset( $settings['speed']['size'] ) ? (float) $settings['speed']['size'] : 0.35;
		$speed = max( 0.0, min( 1.0, $speed ) );

		$overlay = (string) ( $settings['overlay_preset'] ?? 'bottom' );
		if ( ! in_array( $overlay, array( 'none', 'bottom', 'full' ), true ) ) {
			$overlay = 'bottom';
		}

		$disable_mobile = 'yes' === ( $settings['disable_on_mobile'] ?? 'yes' );

		$this->add_render_attribute(
			'wrapper',
			array(
				'class'               => array(
					'tk-el-parallax',
					'tk-el-parallax--overlay-' . $overlay,
				),
				'data-disable-mobile' => $disable_mobile ? '1' : '0',
			)
		);

		// get_render_attribute_string() escapes attribute values via esc_attr() inside Elementor core.
		echo '<section ' . $this->get_render_attribute_string( 'wrapper' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( $image_id ) {
			$full = wp_get_attachment_image_url( $image_id, 'full' );
			if ( $full ) {
				$image_url = $full;
			}
		}

		if ( '' !== $image_url ) {
			echo '<div class="tk-el-parallax-bg" data-speed="' . esc_attr( (string) $speed ) . '" style="background-image: url(' . esc_url( $image_url ) . ');" aria-hidden="true"></div>';
		}

		echo '<div class="tk-el-parallax-overlay" aria-hidden="true"></div>';

		echo '<div class="tk-el-parallax-content">';
		// WYSIWYG content: paragraphs + shortcodes, like the_content.
		echo do_shortcode( wpautop( $content ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		echo '</section>';
	}
}
