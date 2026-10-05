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
 * Elementor integration module.
 *
 * Lazy-loaded: only required when Elementor is actually present (see the
 * `elementor/loaded` hook in tk-fields.php). Registers the `tk-fields`
 * dynamic-tags group + typed tag classes, and the TK Repeater widget.
 *
 * Every tag and widget resolves values through \TK\Fields\Fields — no
 * integration may read postmeta directly.
 *
 * @package TK\Fields\Integrations\Elementor
 */

declare(strict_types=1);

namespace TK\Fields\Integrations\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Module {

	public const GROUP = 'tk-fields';

	public static function init(): void {
		add_action( 'elementor/dynamic_tags/register', array( self::class, 'register_tags' ) );
		add_action( 'elementor/widgets/register', array( self::class, 'register_widgets' ) );
		add_action( 'elementor/elements/categories_registered', array( self::class, 'register_category' ) );
	}

	/**
	 * Register the tk-fields dynamic-tags group and the typed tag classes.
	 *
	 * @param \Elementor\Core\DynamicTags\Manager $manager
	 */
	public static function register_tags( $manager ): void {
		$manager->register_group(
			self::GROUP,
			array( 'title' => __( 'TK Fields', 'tk-fields' ) )
		);

		$manager->register( new Tags\Text_Tag() );
		$manager->register( new Tags\Number_Tag() );
		$manager->register( new Tags\URL_Tag() );
		$manager->register( new Tags\Image_Tag() );
		$manager->register( new Tags\Date_Tag() );
		$manager->register( new Tags\True_False_Tag() );

		// Gallery tag ships only when a gallery-type field exists in the
		// registry (the gallery field type ships in Batch A). Registering it
		// against zero fields would show an empty dropdown.
		if ( self::registry_has_type( 'gallery' ) ) {
			$manager->register( new Tags\Gallery_Tag() );
		}

		// Group tag ships only when a group-type field exists (v0.11.0).
		if ( self::registry_has_type( 'group' ) ) {
			$manager->register( new Tags\Group_Tag() );
		}

		// Repeater tag ships only when a repeater-type field exists. The
		// TK Repeater widget stays the render primitive — this tag only
		// exposes the typed row values for dynamic consumption.
		if ( self::registry_has_type( 'repeater' ) ) {
			$manager->register( new Tags\Repeater_Tag() );
		}
	}

	/**
	 * Register TK Fields Elementor widgets.
	 *
	 * Auto-discovers widgets/class-*-widget.php. Each file must declare a
	 * class in this module's Widgets namespace whose name is the StudlyCase
	 * of the file slug plus "_Widget" (e.g. class-accordion-widget.php →
	 * Widgets\\Accordion_Widget).
	 *
	 * The widget class files are required here — not at plugin load — because
	 * Elementor\\Widget_Base only becomes available once the widgets manager
	 * initializes (on init), which is after `elementor/loaded` fires. This
	 * is Elementor's own documented addon pattern.
	 *
	 * @param \\Elementor\\Widgets_Manager $widgets_manager
	 */
	public static function register_widgets( $widgets_manager ): void {
		foreach ( glob( __DIR__ . '/widgets/class-*-widget.php' ) as $file ) {
			$slug  = basename( $file, '.php' ); // class-accordion-widget
			$slug  = preg_replace( '/^class-|-widget$/', '', (string) $slug ); // accordion
			$class = __NAMESPACE__ . '\\Widgets\\' . str_replace( ' ', '_', ucwords( str_replace( '-', ' ', $slug ) ) ) . '_Widget';

			require_once $file;

			if ( class_exists( $class ) ) {
				$widgets_manager->register( new $class() );
			}
		}
	}

	/**
	 * Register the "TK Fields" widget category in the Elementor panel.
	 *
	 * @param \Elementor\Elements_Manager $elements_manager
	 */
	public static function register_category( $elements_manager ): void {
		$elements_manager->add_category(
			self::GROUP,
			array(
				'title' => __( 'TK Fields', 'tk-fields' ),
				'icon'  => 'fa fa-plug',
			)
		);
	}

	/**
	 * Whether the field registry currently holds any field of a given type.
	 */
	private static function registry_has_type( string $type ): bool {
		foreach ( \TK\Fields\Field_Registry::instance()->all() as $field ) {
			if ( ( $field['type'] ?? '' ) === $type ) {
				return true;
			}
		}

		return false;
	}
}
