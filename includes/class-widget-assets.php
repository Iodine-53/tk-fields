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
 * Shared widget assets — the TK Studio v1 design tokens.
 *
 * Registers the `tk-widgets` stylesheet (assets/shared/tk-widgets.css) and
 * enqueues it on the frontend. Gutenberg blocks and Elementor widgets declare
 * it as a style dependency so both builders render sibling output.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and enqueues the shared widget design tokens.
 */
final class Widget_Assets {

	/**
	 * Stylesheet handle for the shared tokens.
	 */
	public const HANDLE = 'tk-widgets';

	/**
	 * Wire up registration + frontend enqueue.
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_frontend' ) );
		// Block editor canvas: tokens must resolve in editor previews too.
		add_action( 'enqueue_block_assets', array( self::class, 'enqueue_frontend' ) );
	}

	/**
	 * Register the token stylesheet (no output yet).
	 */
	public static function register(): void {
		wp_register_style(
			self::HANDLE,
			plugins_url( 'assets/shared/tk-widgets.css', TK_FIELDS_FILE ),
			array(),
			TK_FIELDS_VERSION
		);
	}

	/**
	 * Enqueue tokens on the frontend so widget CSS custom properties resolve.
	 * The file only declares :root tokens — negligible cost.
	 */
	public static function enqueue_frontend(): void {
		if ( wp_style_is( self::HANDLE, 'registered' ) ) {
			wp_enqueue_style( self::HANDLE );
		}
	}
}
