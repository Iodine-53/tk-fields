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
 * Elementor "TK Accordion" widget.
 *
 * Collapsible content panels built with the native Elementor Widget_Base
 * API. Each item pairs a text title with a rich-text (WYSIWYG) panel.
 * Panels open and close with a smooth grid-rows height animation — no JS
 * measuring, no layout jump. Triggers are real <button> elements with
 * aria-expanded, so keyboard users get native behavior.
 *
 * A small frontend script (assets/elementor/js/tk-accordion.js) handles
 * toggling, allow-multiple, and the Elementor editor re-render hook.
 * Styling consumes the shared design tokens (assets/shared/tk-widgets.css,
 * handle `tk-widgets`) plus this widget's own stylesheet
 * (assets/elementor/css/tk-accordion.css).
 *
 * @package TK\Fields\Integrations\Elementor\Widgets
 */

declare(strict_types=1);

namespace TK\Fields\Integrations\Elementor\Widgets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Accordion widget.
 */
final class Accordion_Widget extends \Elementor\Widget_Base {

	/**
	 * Register this widget's stylesheet and script handles.
	 *
	 * Elementor enqueues get_style_depends() / get_script_depends() handles
	 * in both the editor preview and the frontend.
	 *
	 * @param array $data Widget data.
	 * @param array|null $args Widget arguments.
	 */
	public function __construct( $data = array(), $args = null ) {
		parent::__construct( $data, $args );

		wp_register_style(
			'tk-widget-accordion',
			plugins_url( 'assets/elementor/css/tk-accordion.css', TK_FIELDS_FILE ),
			array(),
			TK_FIELDS_VERSION
		);

		wp_register_script(
			'tk-widget-accordion',
			plugins_url( 'assets/elementor/js/tk-accordion.js', TK_FIELDS_FILE ),
			array(),
			TK_FIELDS_VERSION,
			true
		);
	}

	/**
	 * @return string
	 */
	public function get_name(): string {
		return 'tk-accordion';
	}

	/**
	 * @return string
	 */
	public function get_title(): string {
		return __( 'TK Accordion', 'tk-fields' );
	}

	/**
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-accordion';
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
		return array( 'tk', 'accordion', 'collapse', 'toggle', 'faq', 'panels' );
	}

	/**
	 * @return array<int, string>
	 */
	public function get_style_depends(): array {
		return array( 'tk-widgets', 'tk-widget-accordion' );
	}

	/**
	 * @return array<int, string>
	 */
	public function get_script_depends(): array {
		return array( 'tk-widget-accordion' );
	}

	/**
	 * Register the widget's controls.
	 */
	protected function register_controls(): void {
		$this->register_content_controls();
		$this->register_style_controls();
	}

	/**
	 * Content tab: the items and their behavior.
	 */
	private function register_content_controls(): void {
		$this->start_controls_section(
			'tk_accordion_content',
			array(
				'label' => __( 'Content', 'tk-fields' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$repeater = new \Elementor\Repeater();

		$repeater->add_control(
			'title',
			array(
				'label'       => __( 'Title', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Accordion item', 'tk-fields' ),
				'description' => __( 'The clickable heading shown on the closed panel.', 'tk-fields' ),
				'dynamic'     => array( 'active' => true ),
			)
		);

		$repeater->add_control(
			'content',
			array(
				'label'       => __( 'Content', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::WYSIWYG,
				'default'     => __( 'Write the content revealed when this item opens. Text, links, lists and shortcodes all work here.', 'tk-fields' ),
				'description' => __( 'Rich text shown inside the expanded panel. Shortcodes run here too.', 'tk-fields' ),
				'dynamic'     => array( 'active' => true ),
			)
		);

		$this->add_control(
			'items',
			array(
				'label'       => __( 'Accordion Items', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => array(
					array(
						'title'   => __( 'What is TK Fields?', 'tk-fields' ),
						'content' => __( 'TK Fields is a native WordPress plugin for custom fields and interactive content widgets — no page-builder lock-in required.', 'tk-fields' ),
					),
					array(
						'title'   => __( 'Do I need to configure anything?', 'tk-fields' ),
						'content' => __( 'No. The accordion looks designed out of the box; every style below is optional refinement.', 'tk-fields' ),
					),
					array(
						'title'   => __( 'Is it accessible?', 'tk-fields' ),
						'content' => __( 'Yes. Triggers are real buttons with aria-expanded, so screen readers and keyboards work natively.', 'tk-fields' ),
					),
				),
				'title_field' => '{{{ title }}}',
				'description' => __( 'Each row is one collapsible panel: a title plus the content revealed beneath it.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'style_variant',
			array(
				'label'       => __( 'Style Variant', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'card',
				'options'     => array(
					'card'    => __( 'Card', 'tk-fields' ),
					'minimal' => __( 'Minimal', 'tk-fields' ),
					'filled'  => __( 'Filled', 'tk-fields' ),
				),
				'description' => __( 'Card: separate bordered cards with gaps. Minimal: plain dividers, no boxes. Filled: soft tinted panels.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'allow_multiple',
			array(
				'label'        => __( 'Allow Multiple Open', 'tk-fields' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'tk-fields' ),
				'label_off'    => __( 'No', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'When off, opening one item closes the others (classic FAQ behavior).', 'tk-fields' ),
			)
		);

		$this->add_control(
			'open_first',
			array(
				'label'        => __( 'Open First Item', 'tk-fields' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'tk-fields' ),
				'label_off'    => __( 'No', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => '',
				'description'  => __( 'Start the page with the first item already expanded.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'icon_position',
			array(
				'label'       => __( 'Icon Position', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::CHOOSE,
				'default'     => 'right',
				'options'     => array(
					'left'  => array(
						'title' => __( 'Left', 'tk-fields' ),
						'icon'  => 'eicon-h-align-left',
					),
					'right' => array(
						'title' => __( 'Right', 'tk-fields' ),
						'icon'  => 'eicon-h-align-right',
					),
				),
				'description' => __( 'Which side of the title the chevron sits on.', 'tk-fields' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: colors, typography, spacing, motion.
	 */
	private function register_style_controls(): void {
		$this->start_controls_section(
			'tk_accordion_style_items',
			array(
				'label' => __( 'Items', 'tk-fields' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'item_background',
			array(
				'label'       => __( 'Item Background', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Background of each accordion item. Leave empty to use the theme surface.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-accordion-item' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'item_background_open',
			array(
				'label'       => __( 'Open Item Background', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Background of the item while its panel is expanded.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-accordion-item.is-open' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'        => 'item_border',
				'label'       => __( 'Item Border', 'tk-fields' ),
				'description' => __( 'Border around each item. Set to "None" for a borderless look.', 'tk-fields' ),
				'selector'    => '{{WRAPPER}} .tk-el-accordion-item',
			)
		);

		$this->add_control(
			'item_border_radius',
			array(
				'label'       => __( 'Item Border Radius', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units'  => array( 'px', '%' ),
				'description' => __( 'Rounded corners per item. Ignored by the Minimal variant.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-accordion-item' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'item_gap',
			array(
				'label'       => __( 'Gap Between Items', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 48,
						'step' => 1,
					),
				),
				'description' => __( 'Vertical space between items. Use 0 for touching cards.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-accordion' => '--tk-el-accordion-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'        => 'item_shadow_open',
				'label'       => __( 'Open Item Shadow', 'tk-fields' ),
				'description' => __( 'Shadow applied to an item while it is expanded, for a lifted feel.', 'tk-fields' ),
				'selector'    => '{{WRAPPER}} .tk-el-accordion-item.is-open',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'tk_accordion_style_trigger',
			array(
				'label' => __( 'Trigger', 'tk-fields' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'trigger_color',
			array(
				'label'       => __( 'Title Color', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Color of the item title text. Empty inherits the theme text color.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-accordion-trigger' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'trigger_color_hover',
			array(
				'label'       => __( 'Title Color (Hover)', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Title color when the pointer hovers the trigger row.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-accordion-trigger:hover' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'trigger_background_hover',
			array(
				'label'       => __( 'Trigger Background (Hover)', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Subtle wash behind the trigger on hover. Empty disables it.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-accordion-trigger:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'           => 'title_typography',
				'label'          => __( 'Title Typography', 'tk-fields' ),
				'selector'       => '{{WRAPPER}} .tk-el-accordion-title',
				'fields_options' => array(
					'font_weight' => array(
						'description' => __( 'Bold (600) reads best for accordion titles.', 'tk-fields' ),
					),
				),
			)
		);

		$this->add_control(
			'trigger_padding',
			array(
				'label'       => __( 'Trigger Padding', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units'  => array( 'px', 'em', 'rem' ),
				'description' => __( 'Space inside the clickable title row.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-accordion-trigger' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'icon_color',
			array(
				'label'       => __( 'Icon Color', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Color of the chevron. Empty follows the title color.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-accordion-icon' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'icon_size',
			array(
				'label'       => __( 'Icon Size', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 10,
						'max'  => 40,
						'step' => 1,
					),
				),
				'description' => __( 'Size of the chevron icon.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-accordion-icon' => 'font-size: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'tk_accordion_style_panel',
			array(
				'label' => __( 'Panel', 'tk-fields' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'content_color',
			array(
				'label'       => __( 'Content Color', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Text color inside the expanded panel. Empty inherits the theme.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-accordion-panel-inner' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'content_typography',
				'label'    => __( 'Content Typography', 'tk-fields' ),
				'selector' => '{{WRAPPER}} .tk-el-accordion-panel-inner',
			)
		);

		$this->add_control(
			'panel_padding',
			array(
				'label'       => __( 'Panel Padding', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units'  => array( 'px', 'em', 'rem' ),
				'description' => __( 'Space inside the expanded panel.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-accordion-panel-inner' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'tk_accordion_style_motion',
			array(
				'label' => __( 'Motion', 'tk-fields' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'duration',
			array(
				'label'       => __( 'Animation Duration', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => array( 'ms' ),
				'default'     => array(
					'size' => 280,
					'unit' => 'ms',
				),
				'range'       => array(
					'ms' => array(
						'min'  => 100,
						'max'  => 1000,
						'step' => 10,
					),
				),
				'description' => __( 'How long the open/close animation takes. 280ms is the TK default.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-accordion' => '--tk-el-accordion-dur: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'easing',
			array(
				'label'       => __( 'Animation Easing', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'smooth',
				'options'     => array(
					'smooth' => __( 'Smooth', 'tk-fields' ),
					'snappy' => __( 'Snappy', 'tk-fields' ),
					'gentle' => __( 'Gentle', 'tk-fields' ),
				),
				'description' => __( 'The motion curve of the open/close animation: Smooth glides out, Snappy is decisive, Gentle is the soft standard ease.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-accordion' => '--tk-el-accordion-ease: {{VALUE}};',
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
	 * Render the accordion.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();

		$items = $settings['items'] ?? array();
		if ( empty( $items ) || ! is_array( $items ) ) {
			return;
		}

		$variant = (string) ( $settings['style_variant'] ?? 'card' );
		if ( ! in_array( $variant, array( 'card', 'minimal', 'filled' ), true ) ) {
			$variant = 'card';
		}

		$icon_position = (string) ( $settings['icon_position'] ?? 'right' );
		if ( ! in_array( $icon_position, array( 'left', 'right' ), true ) ) {
			$icon_position = 'right';
		}

		$allow_multiple = 'yes' === ( $settings['allow_multiple'] ?? 'yes' );
		$open_first     = 'yes' === ( $settings['open_first'] ?? '' );

		$this->add_render_attribute(
			'wrapper',
			array(
				'class'               => array(
					'tk-el-accordion',
					'tk-el-accordion--' . $variant,
					'tk-el-accordion--icon-' . $icon_position,
				),
				'data-allow-multiple' => $allow_multiple ? '1' : '0',
			)
		);

		// get_render_attribute_string() escapes attribute values via esc_attr() inside Elementor core.
		echo '<div ' . $this->get_render_attribute_string( 'wrapper' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$uid = 'tkacc' . $this->get_id();

		foreach ( $items as $index => $item ) {
			$title   = isset( $item['title'] ) ? (string) $item['title'] : '';
			$content = isset( $item['content'] ) ? (string) $item['content'] : '';
			$is_open = $open_first && 0 === $index;

			$panel_id   = $uid . '-panel-' . $index;
			$button_id  = $uid . '-btn-' . $index;
			$item_class = 'tk-el-accordion-item' . ( $is_open ? ' is-open' : '' );

			echo '<div class="' . esc_attr( $item_class ) . '">';
			echo '<h3 class="tk-el-accordion-heading">';
			echo '<button type="button" id="' . esc_attr( $button_id ) . '" class="tk-el-accordion-trigger" aria-expanded="' . ( $is_open ? 'true' : 'false' ) . '" aria-controls="' . esc_attr( $panel_id ) . '">';
			echo '<span class="tk-el-accordion-title">' . esc_html( $title ) . '</span>';
			echo '<span class="tk-el-accordion-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></span>';
			echo '</button>';
			echo '</h3>';
			echo '<div id="' . esc_attr( $panel_id ) . '" class="tk-el-accordion-panel" role="region" aria-labelledby="' . esc_attr( $button_id ) . '">';
			echo '<div class="tk-el-accordion-panel-inner">';
			// WYSIWYG content: paragraphs + shortcodes, like the_content.
			echo do_shortcode( wpautop( $content ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';
			echo '</div>';
			echo '</div>';
		}

		echo '</div>';
	}
}
