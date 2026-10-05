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
 * Admin shell for the field-group builder.
 *
 * Top-level "TK Fields" menu: the "Field Groups" screen renders
 * <div id="tk-fields-admin-root"> for the React app; the "Help" screen is
 * server-rendered (it links into the React help tab). The built bundle is
 * enqueued ONLY on our own screens.
 *
 * The React bundle itself (assets/admin/build/index.js|css|index.asset.php)
 * is produced by the React worker — this file treats those paths as an
 * output contract and degrades gracefully when they don't exist yet.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin {

	/**
	 * @var self|null
	 */
	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Register hooks. Called from the main plugin file.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_tk_fields_export_context', array( $this, 'handle_export_context' ) );
	}

	/**
	 * Top-level menu + submenus.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'TK Fields', 'tk-fields' ),
			__( 'TK Fields', 'tk-fields' ),
			'manage_options',
			'tk-fields',
			array( $this, 'render_app' ),
			'dashicons-admin-generic',
			80
		);

		// First submenu duplicates the top-level screen (React app).
		add_submenu_page(
			'tk-fields',
			__( 'Field Groups', 'tk-fields' ),
			__( 'Field Groups', 'tk-fields' ),
			'manage_options',
			'tk-fields',
			array( $this, 'render_app' )
		);

		add_submenu_page(
			'tk-fields',
			__( 'TK Fields Help', 'tk-fields' ),
			__( 'Help', 'tk-fields' ),
			'manage_options',
			'tk-fields-help',
			array( $this, 'render_help' )
		);

		// v0.15.0: Content Types (custom post type builder). Renders the
		// same React bundle with data-tkf-app="content-types" so index.js
		// mounts the CPT app instead of the field-group app.
		add_submenu_page(
			'tk-fields',
			__( 'Content Types', 'tk-fields' ),
			__( 'Content Types', 'tk-fields' ),
			'manage_options',
			'tk-fields-content-types',
			array( $this, 'render_content_types' )
		);
	}

	/**
	 * Render the Content Types screen (React mount point).
	 *
	 * v0.15.0: the data-tkf-app attribute tells the bundle's index.js to
	 * mount the Content Types app instead of the field-group builder. The
	 * same #tk-fields-admin-root id is reused so all tkf- styles apply
	 * unchanged.
	 */
	public function render_content_types(): void {
		$built = file_exists( TK_FIELDS_DIR . 'assets/admin/build/index.js' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Content Types', 'tk-fields' ); ?></h1>
			<div id="tk-fields-admin-root" data-tkf-app="content-types">
				<?php if ( ! $built ) : ?>
					<div class="notice notice-warning inline">
						<p><?php esc_html_e( 'The Content Types UI has not been built yet. The REST API is available in the meantime.', 'tk-fields' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Hook suffixes of our screens.
	 *
	 * @var string[]
	 */
	private const SCREENS = array( 'toplevel_page_tk-fields', 'tk-fields_page_tk-fields-help', 'tk-fields_page_tk-fields-content-types' );

	/**
	 * Enqueue the React bundle — only on our screens, only when built.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, self::SCREENS, true ) ) {
			return;
		}

		// The builder UI only lives on the Field Groups screen.
		if ( 'tk-fields_page_tk-fields-help' === $hook_suffix ) {
			return;
		}

		$asset_file = TK_FIELDS_DIR . 'assets/admin/build/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			// React worker hasn't produced the bundle yet — the screen will
			// show a fallback notice (see render_app()).
			return;
		}

		$asset = include $asset_file;
		if ( ! is_array( $asset ) ) {
			return;
		}

		$deps    = $asset['dependencies'] ?? array();
		$version = $asset['version'] ?? TK_FIELDS_VERSION;

		wp_enqueue_style(
			'tk-fields-admin',
			TK_FIELDS_URL . 'assets/admin/build/index.css',
			array( 'wp-components' ),
			$version
		);

		// Field-type picker and UI icons are WordPress core Dashicons
		// (always available in wp-admin) — no icon font to enqueue.

		// Classic (non-React) layouts editor for the flexible_content
		// builder UI: defines window.TKFLayoutsEditor, consumed by the
		// patched "layouts" control in the bundle. Registered as a bundle
		// dependency so it always loads first.
		wp_register_script(
			'tk-fields-layouts-editor',
			TK_FIELDS_URL . 'assets/admin/layouts-editor.js',
			array( 'wp-element', 'wp-components', 'wp-i18n' ),
			$version,
			true
		);
		wp_set_script_translations( 'tk-fields-layouts-editor', 'tk-fields' );

		// Classic (non-React) sub-fields editor for the group builder UI:
		// defines window.TKFSubFieldsEditor, consumed by the patched
		// "subfields" control in the bundle. Registered as a bundle
		// dependency so it always loads first.
		wp_register_script(
			'tk-fields-subfields-editor',
			TK_FIELDS_URL . 'assets/admin/subfields-editor.js',
			array( 'wp-element', 'wp-components', 'wp-i18n' ),
			$version,
			true
		);
		wp_set_script_translations( 'tk-fields-subfields-editor', 'tk-fields' );

		wp_enqueue_script(
			'tk-fields-admin',
			TK_FIELDS_URL . 'assets/admin/build/index.js',
			array_merge( $deps, array( 'tk-fields-layouts-editor', 'tk-fields-subfields-editor' ) ),
			$version,
			true
		);

		wp_set_script_translations( 'tk-fields-admin', 'tk-fields' );

		// Inline config BEFORE the bundle: REST root, nonce, admin URL.
		wp_add_inline_script(
			'tk-fields-admin',
			'window.TK_FIELDS_ADMIN = ' . wp_json_encode(
				array(
					'root'     => esc_url_raw( rest_url( 'tk/v1' ) ),
					'wpRoot'   => esc_url_raw( rest_url( 'wp/v2' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'adminUrl' => esc_url_raw( admin_url() ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Render the Field Groups screen (React mount point).
	 */
	public function render_app(): void {
		$built = file_exists( TK_FIELDS_DIR . 'assets/admin/build/index.js' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Field Groups', 'tk-fields' ); ?></h1>
			<?php if ( Context_Export::user_can_export() ) : ?>
				<p>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tk_fields_export_context' ), 'tk_fields_export_context' ) ); ?>" class="button">
						<?php esc_html_e( 'Export AI context (JSON)', 'tk-fields' ); ?>
					</a>
					<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'format', 'markdown', admin_url( 'admin-post.php?action=tk_fields_export_context' ) ), 'tk_fields_export_context' ) ); ?>" class="button">
						<?php esc_html_e( 'Export AI context (Markdown)', 'tk-fields' ); ?>
					</a>
				</p>
			<?php endif; ?>
			<div id="tk-fields-admin-root">
				<?php if ( ! $built ) : ?>
					<div class="notice notice-warning inline">
						<p><?php esc_html_e( 'The field-group builder UI has not been built yet. The REST API is available in the meantime.', 'tk-fields' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the server-side Help screen.
	 *
	 * Content lives in the React help tab; this page just points there so
	 * the menu has a Help entry even before the bundle exists.
	 */
	public function render_help(): void {
		$app_url = admin_url( 'admin.php?page=tk-fields' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'TK Fields Help', 'tk-fields' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: Field Groups admin URL */
					esc_html__( 'The full help content lives in the help tab of the %s screen.', 'tk-fields' ),
					'<a href="' . esc_url( $app_url ) . '">' . esc_html__( 'Field Groups', 'tk-fields' ) . '</a>'
				);
				?>
			</p>
			<h2><?php esc_html_e( 'Quick reference', 'tk-fields' ); ?></h2>
			<ul>
				<li><?php esc_html_e( 'Read a field: tk_get_field( $name, $post_id ).', 'tk-fields' ); ?></li>
				<li><?php esc_html_e( 'Write a field: tk_update_field( $name, $value, $post_id ).', 'tk-fields' ); ?></li>
				<li><?php esc_html_e( 'REST namespace: tk/v1 (requires manage_options).', 'tk-fields' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Handle the "Export AI context" download (admin-post, nonce-checked).
	 *
	 * Regenerates the schema on demand — nothing is written to the
	 * filesystem, and no field values are ever read. Capability-gated via
	 * the `tk_fields_context_export_capability` filter (default
	 * edit_theme_options).
	 */
	public function handle_export_context(): void {
		if ( ! Context_Export::user_can_export() ) {
			wp_die(
				esc_html__( 'You do not have permission to export the AI context.', 'tk-fields' ),
				esc_html__( 'Forbidden', 'tk-fields' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( 'tk_fields_export_context' );

		$format = ( isset( $_GET['format'] ) && 'markdown' === $_GET['format'] ) ? 'markdown' : 'json';

		// Schema only — generate() never touches field values.
		$context = Context_Export::generate();

		nocache_headers();

		$stamp = gmdate( 'Ymd-His' );
		if ( 'markdown' === $format ) {
			header( 'Content-Type: text/markdown; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="tk-fields-ai-context-' . $stamp . '.md"' );
			echo Context_Export::to_markdown( $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="tk-fields-ai-context-' . $stamp . '.json"' );
			echo wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		exit;
	}
}
