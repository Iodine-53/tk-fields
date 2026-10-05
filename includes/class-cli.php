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
 * WP-CLI commands for TK Fields.
 *
 * Registers `wp tk-fields export-context` when WP-CLI is present. The
 * registration is guarded by defined('WP_CLI') so normal web/admin/CRON
 * requests never touch the WP_CLI class.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CLI {

	/**
	 * Register WP-CLI commands. No-op outside WP-CLI.
	 */
	public static function init(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'tk-fields', self::class );
		// Explicit dashed alias: this wp-cli build registers method names
		// verbatim (export_context), so add the documented export-context
		// form pointing at the same handler.
		\WP_CLI::add_command( 'tk-fields export-context', array( self::class, 'export_context' ) );
	}

	/**
	 * Export the AI context schema (definitions only — never field values).
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. One of json, markdown.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - markdown
	 * ---
	 *
	 * [--post-type=<type>]
	 * : Only include field groups that apply to this post type. Guards the
	 *   token window on large sites.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tk-fields export-context
	 *     wp tk-fields export-context --format=markdown
	 *     wp tk-fields export-context --post-type=post > context.json
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Associative args.
	 */
	public function export_context( array $args, array $assoc_args ): void {
		// Capability gate: when WP-CLI runs with a logged-in user (--user),
		// enforce the same capability as the admin download. Without a user
		// (plain shell invocation) the shell itself is the trust boundary —
		// whoever can run wp-cli on the server already has full access.
		if ( 0 !== get_current_user_id() && ! Context_Export::user_can_export() ) {
			\WP_CLI::error( __( 'You do not have permission to export the AI context.', 'tk-fields' ) );
		}

		$format = $assoc_args['format'] ?? 'json';
		if ( ! in_array( $format, array( 'json', 'markdown' ), true ) ) {
			\WP_CLI::error( __( 'Invalid --format. Use json or markdown.', 'tk-fields' ) );
		}

		$post_type = isset( $assoc_args['post-type'] ) ? (string) $assoc_args['post-type'] : null;
		if ( null !== $post_type && ! post_type_exists( $post_type ) ) {
			/* translators: %s: post type slug */
			\WP_CLI::error( sprintf( __( 'Unknown post type "%1$s".', 'tk-fields' ), $post_type ) );
		}

		$context = Context_Export::generate( $post_type );

		if ( 'markdown' === $format ) {
			\WP_CLI::line( Context_Export::to_markdown( $context ) );
			return;
		}

		$json = wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			\WP_CLI::error( __( 'Could not encode the AI context as JSON.', 'tk-fields' ) );
		}

		// JSON goes to STDOUT so it can be piped to a file.
		\WP_CLI::line( $json );
	}
}
