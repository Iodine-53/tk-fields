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
 * Elementor "TK Countdown" widget.
 *
 * A countdown timer to a target date/time, built with the native Elementor
 * Widget_Base API. Digits use tabular numerals so they never jitter; units
 * can be shown as cards or inline, with colon/dot/no separators. A small
 * frontend script (assets/elementor/js/tk-countdown.js) ticks every second,
 * swaps in the expiry message at zero, and re-initializes on Elementor
 * editor re-renders.
 *
 * Styling consumes the shared design tokens (assets/shared/tk-widgets.css,
 * handle `tk-widgets`) plus this widget's own stylesheet
 * (assets/elementor/css/tk-countdown.css).
 *
 * @package TK\Fields\Integrations\Elementor\Widgets
 */

declare(strict_types=1);

namespace TK\Fields\Integrations\Elementor\Widgets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Countdown widget.
 */
final class Countdown_Widget extends \Elementor\Widget_Base {

	/**
	 * Register this widget's stylesheet and script handles.
	 *
	 * @param array $data Widget data.
	 * @param array|null $args Widget arguments.
	 */
	public function __construct( $data = array(), $args = null ) {
		parent::__construct( $data, $args );

		wp_register_style(
			'tk-widget-countdown',
			plugins_url( 'assets/elementor/css/tk-countdown.css', TK_FIELDS_FILE ),
			array(),
			TK_FIELDS_VERSION
		);

		wp_register_script(
			'tk-widget-countdown',
			plugins_url( 'assets/elementor/js/tk-countdown.js', TK_FIELDS_FILE ),
			array(),
			TK_FIELDS_VERSION,
			true
		);
	}

	/**
	 * @return string
	 */
	public function get_name(): string {
		return 'tk-countdown';
	}

	/**
	 * @return string
	 */
	public function get_title(): string {
		return __( 'TK Countdown', 'tk-fields' );
	}

	/**
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-countdown';
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
		return array( 'tk', 'countdown', 'timer', 'launch', 'coming soon', 'event', 'sale' );
	}

	/**
	 * @return array<int, string>
	 */
	public function get_style_depends(): array {
		return array( 'tk-widgets', 'tk-widget-countdown' );
	}

	/**
	 * @return array<int, string>
	 */
	public function get_script_depends(): array {
		return array( 'tk-widget-countdown' );
	}

	/**
	 * Register the widget's controls.
	 */
	protected function register_controls(): void {
		$this->register_content_controls();
		$this->register_style_controls();
	}

	/**
	 * Content tab: target, units, layout, expiry.
	 */
	private function register_content_controls(): void {
		$this->start_controls_section(
			'tk_countdown_content',
			array(
				'label' => __( 'Content', 'tk-fields' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'target',
			array(
				'label'       => __( 'Target Date & Time', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::DATE_TIME,
				'description' => __( 'Honest timezone note: the countdown is computed in each visitor\'s own browser time. Someone in Lagos and someone in New York both count down to this same clock time in their own timezone — it is NOT pinned to the server clock.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'layout',
			array(
				'label'       => __( 'Layout', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'cards',
				'options'     => array(
					'cards'  => __( 'Cards', 'tk-fields' ),
					'inline' => __( 'Inline', 'tk-fields' ),
				),
				'description' => __( 'Cards: each unit sits in its own rounded tile. Inline: digits flow in one line.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'separator',
			array(
				'label'       => __( 'Separator', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'colon',
				'options'     => array(
					'colon' => __( 'Colon (:)', 'tk-fields' ),
					'dot'   => __( 'Dot (·)', 'tk-fields' ),
					'none'  => __( 'None', 'tk-fields' ),
				),
				'condition'   => array( 'layout' => 'inline' ),
				'description' => __( 'The mark between units in the inline layout.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'show_days',
			array(
				'label'        => __( 'Show Days', 'tk-fields' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'tk-fields' ),
				'label_off'    => __( 'No', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Include the days unit. Turn off for short countdowns (hours and below).', 'tk-fields' ),
			)
		);

		$this->add_control(
			'show_hours',
			array(
				'label'        => __( 'Show Hours', 'tk-fields' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'tk-fields' ),
				'label_off'    => __( 'No', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Include the hours unit.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'show_minutes',
			array(
				'label'        => __( 'Show Minutes', 'tk-fields' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'tk-fields' ),
				'label_off'    => __( 'No', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Include the minutes unit.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'show_seconds',
			array(
				'label'        => __( 'Show Seconds', 'tk-fields' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'tk-fields' ),
				'label_off'    => __( 'No', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Include the ticking seconds unit. Turn off for a calmer look.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'show_labels',
			array(
				'label'        => __( 'Show Labels', 'tk-fields' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'tk-fields' ),
				'label_off'    => __( 'No', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Small "Days / Hours / Minutes / Seconds" captions under each digit.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'expiry_message',
			array(
				'label'       => __( 'Expiry Message', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'rows'        => 2,
				'default'     => __( 'The countdown has ended.', 'tk-fields' ),
				'description' => __( 'Shown in place of the timer once the target passes. Leave empty to show nothing.', 'tk-fields' ),
			)
		);

		$this->add_control(
			'hide_on_expiry',
			array(
				'label'        => __( 'Hide Timer When Expired', 'tk-fields' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'tk-fields' ),
				'label_off'    => __( 'No', 'tk-fields' ),
				'return_value' => 'yes',
				'default'      => '',
				'description'  => __( 'Remove the widget from the page entirely once expired, instead of showing the expiry message.', 'tk-fields' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: digits, labels, cards, alignment.
	 */
	private function register_style_controls(): void {
		$this->start_controls_section(
			'tk_countdown_style_digits',
			array(
				'label' => __( 'Digits', 'tk-fields' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'           => 'value_typography',
				'label'          => __( 'Digits Typography', 'tk-fields' ),
				'selector'       => '{{WRAPPER}} .tk-el-countdown-value',
				'fields_options' => array(
					'font_size'   => array(
						'description' => __( '2xl semibold digits are the designed default.', 'tk-fields' ),
					),
					'font_weight' => array(
						'description' => __( 'Semibold (600) keeps the figures crisp.', 'tk-fields' ),
					),
				),
			)
		);

		$this->add_control(
			'value_color',
			array(
				'label'       => __( 'Digits Color', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Color of the numbers. Empty inherits the theme text color.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-countdown-value' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'label_typography',
				'label'    => __( 'Labels Typography', 'tk-fields' ),
				'selector' => '{{WRAPPER}} .tk-el-countdown-label',
			)
		);

		$this->add_control(
			'label_color',
			array(
				'label'       => __( 'Labels Color', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Color of the "Days / Hours" captions. Empty uses a muted tone.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-countdown-label' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'separator_color',
			array(
				'label'       => __( 'Separator Color', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Color of the colon or dot between units (inline layout).', 'tk-fields' ),
				'condition'   => array( 'layout' => 'inline' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-countdown-sep' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'tk_countdown_style_cards',
			array(
				'label'     => __( 'Cards', 'tk-fields' ),
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => array( 'layout' => 'cards' ),
			)
		);

		$this->add_control(
			'card_background',
			array(
				'label'       => __( 'Card Background', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Background of each unit card. Empty uses a soft theme-adaptive tint.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-countdown-unit' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'        => 'card_border',
				'label'       => __( 'Card Border', 'tk-fields' ),
				'description' => __( 'Border around each unit card.', 'tk-fields' ),
				'selector'    => '{{WRAPPER}} .tk-el-countdown-unit',
			)
		);

		$this->add_control(
			'card_border_radius',
			array(
				'label'       => __( 'Card Border Radius', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units'  => array( 'px', '%' ),
				'description' => __( 'Rounded corners per card. 10px is the designed default.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-countdown-unit' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'card_padding',
			array(
				'label'       => __( 'Card Padding', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units'  => array( 'px', 'em', 'rem' ),
				'description' => __( 'Space inside each unit card.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-countdown-unit' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'tk_countdown_style_layout',
			array(
				'label' => __( 'Spacing', 'tk-fields' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'alignment',
			array(
				'label'       => __( 'Alignment', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::CHOOSE,
				'default'     => 'center',
				'options'     => array(
					'left'   => array(
						'title' => __( 'Left', 'tk-fields' ),
						'icon'  => 'eicon-h-align-left',
					),
					'center' => array(
						'title' => __( 'Center', 'tk-fields' ),
						'icon'  => 'eicon-h-align-center',
					),
					'right'  => array(
						'title' => __( 'Right', 'tk-fields' ),
						'icon'  => 'eicon-h-align-right',
					),
				),
				'description' => __( 'Horizontal alignment of the whole countdown.', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-countdown' => 'justify-content: {{VALUE}};',
				),
				'selectors_dictionary' => array(
					'left'   => 'flex-start',
					'center' => 'center',
					'right'  => 'flex-end',
				),
			)
		);

		$this->add_control(
			'gap',
			array(
				'label'       => __( 'Gap Between Units', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 64,
						'step' => 1,
					),
				),
				'description' => __( 'Space between the unit cards (or inline units).', 'tk-fields' ),
				'selectors'   => array(
					'{{WRAPPER}} .tk-el-countdown' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render the countdown.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();

		$target = trim( (string) ( $settings['target'] ?? '' ) );

		$layout = (string) ( $settings['layout'] ?? 'cards' );
		if ( ! in_array( $layout, array( 'cards', 'inline' ), true ) ) {
			$layout = 'cards';
		}

		$separator = (string) ( $settings['separator'] ?? 'colon' );
		if ( ! in_array( $separator, array( 'colon', 'dot', 'none' ), true ) ) {
			$separator = 'colon';
		}

		$units = array(
			'days'    => array(
				'show'  => 'yes' === ( $settings['show_days'] ?? 'yes' ),
				'label' => __( 'Days', 'tk-fields' ),
			),
			'hours'   => array(
				'show'  => 'yes' === ( $settings['show_hours'] ?? 'yes' ),
				'label' => __( 'Hours', 'tk-fields' ),
			),
			'minutes' => array(
				'show'  => 'yes' === ( $settings['show_minutes'] ?? 'yes' ),
				'label' => __( 'Minutes', 'tk-fields' ),
			),
			'seconds' => array(
				'show'  => 'yes' === ( $settings['show_seconds'] ?? 'yes' ),
				'label' => __( 'Seconds', 'tk-fields' ),
			),
		);

		$units = array_filter(
			$units,
			function ( $unit ) {
				return $unit['show'];
			}
		);

		if ( empty( $units ) ) {
			return;
		}

		$show_labels   = 'yes' === ( $settings['show_labels'] ?? 'yes' );
		$expiry_msg    = (string) ( $settings['expiry_message'] ?? '' );
		$hide_on_expiry = 'yes' === ( $settings['hide_on_expiry'] ?? '' );

		// Server-side prefill for the first paint; the JS re-derives from
		// the target string every second in the visitor's timezone.
		$target_ts = '' !== $target ? strtotime( $target ) : false;
		$remaining = ( false !== $target_ts ) ? max( 0, $target_ts - current_time( 'timestamp' ) ) : 0;

		$this->add_render_attribute(
			'wrapper',
			array(
				'class'             => array(
					'tk-el-countdown',
					'tk-el-countdown--' . $layout,
					'tk-el-countdown--sep-' . $separator,
				),
				'data-target'       => $target,
				'data-hide-expired' => $hide_on_expiry ? '1' : '0',
				'role'              => 'timer',
				'aria-label'        => __( 'Countdown timer', 'tk-fields' ),
			)
		);

		// get_render_attribute_string() escapes attribute values via esc_attr() inside Elementor core.
		echo '<div ' . $this->get_render_attribute_string( 'wrapper' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$first = true;
		foreach ( $units as $key => $unit ) {
			if ( 'inline' === $layout && ! $first && 'none' !== $separator ) {
				$sep = 'colon' === $separator ? ':' : '·';
				echo '<span class="tk-el-countdown-sep" aria-hidden="true">' . esc_html( $sep ) . '</span>';
			}
			$first = false;

			echo '<div class="tk-el-countdown-unit">';
			echo '<span class="tk-el-countdown-value" data-unit="' . esc_attr( $key ) . '">' . esc_html( $this->prefill_value( $key, $remaining ) ) . '</span>';
			if ( $show_labels ) {
				echo '<span class="tk-el-countdown-label">' . esc_html( $unit['label'] ) . '</span>';
			}
			echo '</div>';
		}

		if ( '' !== $expiry_msg ) {
			echo '<p class="tk-el-countdown-expired" hidden>' . esc_html( $expiry_msg ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Zero-padded first-paint value for a unit, derived from seconds remaining.
	 *
	 * @param string $unit    One of days, hours, minutes, seconds.
	 * @param int    $seconds Seconds remaining.
	 * @return string
	 */
	private function prefill_value( string $unit, int $seconds ): string {
		switch ( $unit ) {
			case 'days':
				$value = (int) floor( $seconds / 86400 );
				break;
			case 'hours':
				$value = (int) floor( ( $seconds % 86400 ) / 3600 );
				break;
			case 'minutes':
				$value = (int) floor( ( $seconds % 3600 ) / 60 );
				break;
			default:
				$value = $seconds % 60;
		}
		return str_pad( (string) $value, 2, '0', STR_PAD_LEFT );
	}
}
