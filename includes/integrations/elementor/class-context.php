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
 * Elementor context helpers.
 *
 * Resolves "which post are we rendering for?" the Elementor way: through the
 * document manager, never blind $GLOBALS['post']. This keeps tags and widgets
 * correct inside Loop Grids, archive templates, and other custom queries
 * where the global post is not the item being rendered.
 *
 * @package TK\Fields\Integrations\Elementor
 */

declare(strict_types=1);

namespace TK\Fields\Integrations\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Context {

	/**
	 * Resolve the post ID for the current Elementor rendering context.
	 *
	 * @return int Post ID, or 0 when none can be determined.
	 */
	public static function resolve_post_id(): int {
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$elementor = \Elementor\Plugin::$instance;

			if ( isset( $elementor->documents ) ) {
				$document = $elementor->documents->get_current();
				if ( $document ) {
					$main_id = $document->get_main_id();
					if ( $main_id ) {
						return absint( $main_id );
					}
				}
			}
		}

		return absint( get_the_ID() );
	}
}
