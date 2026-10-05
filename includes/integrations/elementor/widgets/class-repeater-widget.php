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
 * Elementor "TK Repeater" widget.
 *
 * Loops over the rows of a tk/repeater block (stored natively in
 * post_content) and renders each row's actual InnerBlocks through
 * render_block(), reusing the Gutenberg render pipeline — so tk/field-value
 * blocks inside each row render exactly as they do in the block editor.
 * This is the loop-template behavior.
 *
 * Values are resolved through \TK\Fields\Fields — no postmeta reads.
 *
 * @package TK\Fields\Integrations\Elementor\Widgets
 */

declare(strict_types=1);

namespace TK\Fields\Integrations\Elementor\Widgets;

use TK\Fields\Fields;
use TK\Fields\Integrations\Elementor\Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repeater loop widget.
 */
final class Repeater_Widget extends \Elementor\Widget_Base {

	/**
	 * @return string
	 */
	public function get_name(): string {
		return 'tk-repeater';
	}

	/**
	 * @return string
	 */
	public function get_title(): string {
		return __( 'TK Repeater', 'tk-fields' );
	}

	/**
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-loop';
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
		return array( 'tk', 'fields', 'repeater', 'rows', 'loop', 'fields loop' );
	}

	/**
	 * Register the widget's content controls.
	 */
	protected function register_controls(): void {
		$this->start_controls_section(
			'tk_repeater_content',
			array(
				'label' => __( 'Content', 'tk-fields' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'field_name',
			array(
				'label'       => __( 'Repeater Field Name', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'description' => __(
					'The field name of the repeater, as set in the block sidebar ("Repeater Settings" > "Field name") when the repeater is selected in the block editor, e.g. "team".',
					'tk-fields'
				),
				'placeholder' => __( 'team', 'tk-fields' ),
				'dynamic'     => array( 'active' => false ),
			)
		);

		$this->add_control(
			'layout',
			array(
				'label'   => __( 'Layout', 'tk-fields' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'list',
				'options' => array(
					'list' => __( 'List', 'tk-fields' ),
					'grid' => __( 'Grid', 'tk-fields' ),
				),
			)
		);

		$this->add_control(
			'columns',
			array(
				'label'     => __( 'Columns', 'tk-fields' ),
				'type'      => \Elementor\Controls_Manager::NUMBER,
				'default'   => 3,
				'min'       => 1,
				'max'       => 6,
				'step'      => 1,
				'condition' => array( 'layout' => 'grid' ),
			)
		);

		$this->add_control(
			'empty_text',
			array(
				'label'       => __( 'Empty Text', 'tk-fields' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'description' => __(
					'Optional message shown when the repeater has no rows. Leave empty to render nothing.',
					'tk-fields'
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render the repeater rows.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();

		// Sanitized as a key: matches how field names are stored on blocks.
		$field_name = sanitize_key( (string) ( $settings['field_name'] ?? '' ) );
		if ( '' === $field_name ) {
			return;
		}

		$post_id = Context::resolve_post_id();

		$rows = Fields::rows( $field_name, $post_id );

		if ( empty( $rows ) ) {
			$empty_text = (string) ( $settings['empty_text'] ?? '' );
			if ( '' !== $empty_text ) {
				echo '<p class="tk-repeater-empty">' . esc_html( $empty_text ) . '</p>';
			}
			return;
		}

		$layout = (string) ( $settings['layout'] ?? 'list' );
		if ( ! in_array( $layout, array( 'list', 'grid' ), true ) ) {
			$layout = 'list';
		}

		$this->add_render_attribute(
			'wrapper',
			'class',
			array( 'tk-repeater', 'tk-repeater--' . $layout )
		);

		if ( 'grid' === $layout ) {
			$columns = absint( $settings['columns'] ?? 3 );
			$columns = max( 1, min( 6, $columns ) );
			// Column count is a CSS custom property: theme-agnostic, and the
			// theme decides the actual grid rules.
			$this->add_render_attribute(
				'wrapper',
				'style',
				'--tk-repeater-columns: ' . $columns . ';'
			);
		}

		// get_render_attribute_string() escapes attribute values via esc_attr() inside Elementor core.
		echo '<div ' . $this->get_render_attribute_string( 'wrapper' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		foreach ( $rows as $row ) {
			$inner_blocks = $row->get_inner_blocks();
			if ( empty( $inner_blocks ) ) {
				continue;
			}

			echo '<div class="tk-repeater-row" data-row-id="' . esc_attr( $row->get_id() ) . '">';

			foreach ( $inner_blocks as $inner_block ) {
				// render_block() output is already escaped by the block
				// renderers (e.g. blocks/field-value/render.php); each block
				// renders itself from its own attributes, independent of the
				// global $post.
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo render_block( $inner_block );
			}

			echo '</div>';
		}

		echo '</div>';
	}
}
