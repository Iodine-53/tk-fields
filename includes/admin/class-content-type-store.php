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
 * Content-type (custom post type) persistence + runtime registration.
 *
 * Definitions are stored as a hidden CPT (`tk-content-type`, no core UI —
 * we render our own) with the definition JSON in a single post meta key
 * (`_tk_fields_content_type`); the post title is the plural label and the
 * post status is the definition status. Mirrors Group_Store's shape, with
 * two deliberate differences (see the v0.15.0 consultation notes):
 *
 * - NO save_post backfill: a stub content-type definition is an invalid
 *   entity (a CPT without a valid slug is meaningless), so reads fail
 *   closed instead of fabricating one. create() is the only writer.
 * - NO "free revisions" claim: WordPress does not revision post meta, so
 *   definition history is not kept. JSON export/import is the portability
 *   story.
 *
 * Runtime registration reads an autoloaded option snapshot
 * (`tk_fields_content_types`, slug => sanitized args) — NOT a per-request
 * get_posts() — so plain frontend pageloads cost zero extra queries and
 * need no persistent object cache. The snapshot is rebuilt whenever a
 * definition changes (see rebuild_snapshot()).
 *
 * Rewrite rules are flushed ONLY when definitions change, via a deferred
 * flag consumed on `init` priority 999 (fires in admin, REST, WP-CLI and
 * cron alike — `admin_init` would miss non-admin saves). The flush is
 * idempotent, so a flag surviving a failed request self-heals.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Content_Type_Store {

	public const CPT             = 'tk-content-type';
	public const META_KEY        = '_tk_fields_content_type';
	public const SNAPSHOT_OPTION = 'tk_fields_content_types';
	public const FLUSH_FLAG      = 'tk_fields_flush_rewrites';

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
	 * Slugs that would break core query/permalink behavior as a CPT
	 * query_var or rewrite slug. Core post types + Codex reserved terms
	 * (restricted to entries matching our slug charset) + WP 6.5+ font
	 * types + our own internal types.
	 *
	 * @var string[]
	 */
	private const RESERVED_SLUGS = array(
		// Core post types.
		'post', 'page', 'attachment', 'revision', 'nav_menu_item',
		'custom_css', 'customize_changeset', 'oembed_cache', 'user_request',
		'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles',
		'wp_navigation', 'wp_font_family', 'wp_font_face',
		// Codex reserved terms (query_var collisions).
		'action', 'author', 'order', 'type', 'name', 'feed', 'search',
		'embed', 'theme', 'category', 'tag', 'any', 'post_type',
		'comments', 'error', 'paged', 'page_id', 'post_id',
		// Our own internal types.
		'tk-field-group', 'tk-content-type',
	);

	/**
	 * Well-known third-party slugs: not blocked (that would be overreach —
	 * the plugin may never be installed), but surfaced as a soft warning at
	 * save time.
	 *
	 * @var string[]
	 */
	private const KNOWN_PLUGIN_SLUGS = array(
		'product', 'shop_order', 'shop_coupon', 'product_variation',
		'elementor_library', 'event', 'portfolio', 'testimonial',
		'team', 'service', 'project', 'listing', 'property',
	);

	/**
	 * Core /wp/v2/ rest_base values a content type must not claim.
	 *
	 * @var string[]
	 */
	private const RESERVED_REST_BASES = array(
		'posts', 'pages', 'media', 'types', 'statuses', 'taxonomies',
		'categories', 'tags', 'users', 'comments', 'search', 'settings',
		'block-types', 'blocks', 'templates', 'template-parts',
		'global-styles', 'patterns', 'menus', 'menu-locations',
		'widgets', 'widget-types', 'sidebars', 'themes', 'plugins',
		'block-renderer', 'url-details', 'block-directory',
	);

	/**
	 * Legacy Flaticon UIcons glyph name => Dashicons successor slug.
	 * Content types created before the UIcons removal store menu_icon as
	 * 'fi-sr-*' (or the 'tkf-uicon fi-sr-*' pair the old picker stored);
	 * this map translates those stored values to core Dashicons classes
	 * at registration and save time, so old definitions keep their icons
	 * with no data migration.
	 *
	 * @var array<string,string>
	 */
	private const FI_DASHICON_MAP = array(
		'boxes' => 'archive',
		'browser' => 'welcome-widgets-menus',
		'calculator' => 'calculator',
		'calendar' => 'calendar',
		'calendar-clock' => 'calendar-alt',
		'caret-down' => 'arrow-down-alt2',
		'checkbox' => 'yes-alt',
		'clock' => 'clock',
		'copy' => 'clipboard',
		'document' => 'admin-post',
		'envelope' => 'email',
		'file' => 'media-document',
		'folder-open' => 'category',
		'gallery' => 'format-gallery',
		'globe' => 'admin-site',
		'grid' => 'grid-view',
		'inbox' => 'editor-table',
		'layers' => 'layout',
		'link' => 'admin-links',
		'link-alt' => 'admin-page',
		'lock' => 'lock',
		'map-marker' => 'location-alt',
		'minus' => 'minus',
		'palette' => 'art',
		'paragraph' => 'editor-paragraph',
		'pen-nib' => 'edit-large',
		'picture' => 'format-image',
		'play' => 'format-video',
		'radio-button' => 'list-view',
		'settings-sliders' => 'admin-settings',
		'share' => 'share',
		'square-info' => 'info-outline',
		'star' => 'star-filled',
		'tags' => 'tag',
		'text-size' => 'editor-textcolor',
		'user' => 'admin-users',
	);

	/**
	 * Resolve a stored menu_icon value to a Dashicons class, or ''.
	 * Accepts 'dashicons-archive', the 'dashicons dashicons-archive'
	 * pair the editor stores, and legacy 'fi-sr-boxes' / 'tkf-uicon
	 * fi-sr-boxes' values (translated via FI_DASHICON_MAP).
	 *
	 * @param string $menu_icon
	 */
	public function resolve_menu_icon( string $menu_icon ): string {
		if ( preg_match( '/dashicons-([a-z0-9-]+)/', $menu_icon, $m ) ) {
			return 'dashicons-' . $m[1];
		}
		if ( preg_match( '/fi-sr-([a-z-]+)/', $menu_icon, $m ) && isset( self::FI_DASHICON_MAP[ $m[1] ] ) ) {
			return 'dashicons-' . self::FI_DASHICON_MAP[ $m[1] ];
		}
		return '';
	}

	/**
	 * Allowed `supports` values (WP_Post_Type::$supports vocabulary).
	 *
	 * @var string[]
	 */
	public const SUPPORTS = array(
		'title', 'editor', 'author', 'thumbnail', 'excerpt', 'trackbacks',
		'custom-fields', 'comments', 'revisions', 'page-attributes',
		'post-formats',
	);

	/**
	 * Collected runtime registration failures, surfaced as an admin notice.
	 *
	 * @var string[]
	 */
	private static array $registration_failures = array();

	/**
	 * Register hooks. Called from the main plugin file.
	 */
	public function init(): void {
		// Internal CPT first (init:5), user types at the default priority
		// (init:10), taxonomy attachment after third-party taxonomies have
		// had their chance to register (init:20), deferred rewrite flush
		// last (init:999 — runs in admin, REST, CLI and cron).
		add_action( 'init', array( $this, 'register_post_type' ), 5 );
		add_action( 'init', array( $this, 'register_all' ), 10 );
		add_action( 'init', array( $this, 'attach_taxonomies' ), 20 );
		add_action( 'init', array( $this, 'consume_flush_flag' ), 999 );

		// Snapshot safety net for external writers (wp-cli / direct
		// wp_update_post). Our own create()/update()/trash()/delete() also
		// rebuild explicitly after writing meta.
		add_action( 'save_post_' . self::CPT, array( $this, 'maybe_rebuild_snapshot' ), 20, 1 );
		add_action( 'trashed_post', array( $this, 'maybe_rebuild_snapshot_for' ), 10, 1 );
		add_action( 'untrashed_post', array( $this, 'maybe_rebuild_snapshot_for' ), 10, 1 );
		add_action( 'deleted_post', array( $this, 'maybe_rebuild_snapshot_for' ), 10, 1 );

		// register_post_type() is global state: after switch_to_blog() the
		// previous blog's types stay registered and the new blog's never
		// register. Re-run. (Rewrite rules for the switched blog are not
		// regenerated mid-request — documented limitation.)
		add_action( 'switch_blog', array( $this, 'register_all' ), 10, 0 );

		add_action( 'admin_notices', array( $this, 'registration_failure_notice' ) );

	}

	/**
	 * Register the content-type storage post type. Non-public, no core UI,
	 * no REST — every capability mapped to the custom `manage_tk_fields`
 * primitive (granted to administrators on init) so only admins
	 * can touch definitions (closes the XML-RPC custom-fields injection
	 * path that default `post` caps would leave open to Authors).
	 */
	public function register_post_type(): void {
		register_post_type(
			self::CPT,
			array(
				'label'               => __( 'Content Types', 'tk-fields' ),
				'labels'              => array(
					'name'          => __( 'Content Types', 'tk-fields' ),
					'singular_name' => __( 'Content Type', 'tk-fields' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'can_export'          => true,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'capabilities'        => array(
					'edit_post'          => 'manage_tk_fields',
					'read_post'          => 'manage_tk_fields',
					'delete_post'        => 'manage_tk_fields',
					'edit_posts'         => 'manage_tk_fields',
					'edit_others_posts'  => 'manage_tk_fields',
					'publish_posts'      => 'manage_tk_fields',
					'read_private_posts' => 'manage_tk_fields',
					'create_posts'       => 'manage_tk_fields',
				),
			)
		);
	}

	// ------------------------------------------------------------------
	// Runtime registration
	// ------------------------------------------------------------------

	/**
	 * Register every stored user content type. Reads the autoloaded
	 * snapshot only. Defensive: re-validates each definition (meta can be
	 * written directly, bypassing sanitize_definition()) and surfaces
	 * failures instead of silently dropping types.
	 */
	public function register_all(): void {
		$snapshot = get_option( self::SNAPSHOT_OPTION, array() );
		if ( ! is_array( $snapshot ) ) {
			return;
		}

		// Deterministic order (by definition post ID) so menu_position
		// behavior is stable across requests.
		uasort(
			$snapshot,
			static function ( $a, $b ) {
				return ( (int) ( $a['_id'] ?? 0 ) ) <=> ( (int) ( $b['_id'] ?? 0 ) );
			}
		);

		foreach ( $snapshot as $slug => $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}
			// Skip if something else already claimed the slug (a theme or
			// a later-activated plugin wins at runtime — the save-time
			// collision check already warned about this).
			if ( post_type_exists( $slug ) ) {
				continue;
			}
			$args = $this->registration_args( $def );
			if ( is_wp_error( $args ) ) {
				self::$registration_failures[] = $slug . ': ' . $args->get_error_message();
				continue;
			}
			$result = register_post_type( $slug, $args );
			if ( is_wp_error( $result ) || ! post_type_exists( $slug ) ) {
				self::$registration_failures[] = $slug . ': ' . ( is_wp_error( $result ) ? $result->get_error_message() : __( 'registration failed.', 'tk-fields' ) );
			}
		}
	}

	/**
	 * Attach taxonomies to our registered types. Runs at init:20 because
	 * the `taxonomies` registration arg silently no-ops for taxonomies that
	 * are not registered yet, and third-party taxonomies commonly register
	 * at the same priority 10 we register at — a race we would lose
	 * nondeterministically.
	 */
	public function attach_taxonomies(): void {
		$snapshot = get_option( self::SNAPSHOT_OPTION, array() );
		if ( ! is_array( $snapshot ) ) {
			return;
		}
		foreach ( $snapshot as $slug => $def ) {
			if ( ! is_array( $def ) || ! post_type_exists( $slug ) ) {
				continue;
			}
			foreach ( (array) ( $def['taxonomies'] ?? array() ) as $taxonomy ) {
				if ( is_string( $taxonomy ) && '' !== $taxonomy && taxonomy_exists( $taxonomy ) ) {
					register_taxonomy_for_object_type( $taxonomy, $slug );
				}
			}
		}
	}

	/**
	 * Build register_post_type() args from a sanitized definition.
	 * Fail-closed: WP_Error when the definition cannot be registered.
	 *
	 * @param array $def Sanitized definition.
	 * @return array|\WP_Error
	 */
	private function registration_args( array $def ): array|\WP_Error {
		$slug = $def['slug'] ?? '';
		if ( ! is_string( $slug ) || '' === $slug || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,19}$/', $slug ) ) {
			return new \WP_Error( 'tk_fields_ct_bad_slug', __( 'Invalid slug in stored definition.', 'tk-fields' ) );
		}

		$singular = (string) ( $def['singular'] ?? '' );
		$plural   = (string) ( $def['plural'] ?? '' );
		if ( '' === $singular || '' === $plural ) {
			return new \WP_Error( 'tk_fields_ct_bad_labels', __( 'Singular and plural labels are required.', 'tk-fields' ) );
		}

		$labels = $this->generate_labels( $singular, $plural, $def['labels_override'] ?? array() );

		$public             = ! empty( $def['public'] );
		$publicly_queryable = ! empty( $def['publicly_queryable'] );
		$show_ui            = ! empty( $def['show_ui'] );
		$show_in_menu       = ! empty( $def['show_in_menu'] );
		$show_in_rest       = ! empty( $def['show_in_rest'] );
		$hierarchical       = ! empty( $def['hierarchical'] );

		$has_archive = ! empty( $def['has_archive'] ) && $publicly_queryable
			? (string) ( $def['archive_slug'] ?? '' ) ?: true
			: false;

		$rewrite_slug = (string) ( $def['rewrite_slug'] ?? '' );
		$with_front  = array_key_exists( 'with_front', $def ) ? (bool) $def['with_front'] : true;
		if ( array_key_exists( 'rewrite', $def ) && ! $def['rewrite'] ) {
			$rewrite = false;
		} else {
			$rewrite = '' !== $rewrite_slug
				? array( 'slug' => $rewrite_slug, 'with_front' => $with_front )
				: true;
		}

		// menu_icon is a core Dashicons class (translated from legacy
		// 'fi-sr-*' values by resolve_menu_icon()); core renders
		// dashicons classes natively, so no dynamic CSS is needed.
		$menu_icon = $this->resolve_menu_icon( (string) ( $def['menu_icon'] ?? '' ) );
		if ( '' === $menu_icon ) {
			$menu_icon = 'dashicons-admin-post';
		}

		$supports = array_values( array_intersect( (array) ( $def['supports'] ?? array() ), self::SUPPORTS ) );
		if ( array() === $supports ) {
			$supports = array( 'title', 'editor' );
		}

		return array(
			'label'              => $plural,
			'labels'             => $labels,
			'description'        => (string) ( $def['description'] ?? '' ),
			'public'             => $public,
			'publicly_queryable' => $publicly_queryable,
			'show_ui'            => $show_ui,
			'show_in_menu'       => $show_in_menu,
			'menu_position'      => $this->sanitize_menu_position( $def['menu_position'] ?? null ),
			'menu_icon'          => $menu_icon,
			'show_in_admin_bar'  => $public,
			'show_in_nav_menus'  => $public,
			'show_in_rest'       => $show_in_rest,
			'rest_base'          => (string) ( $def['rest_base'] ?? $slug ),
			'hierarchical'       => $hierarchical,
			'supports'           => $supports,
			'has_archive'        => $has_archive,
			'rewrite'            => $rewrite,
			'query_var'          => true,
			'can_export'         => true,
			'exclude_from_search'=> ! $public,
			'capability_type'    => in_array( $def['capability_type'] ?? 'post', array( 'post', 'page' ), true ) ? $def['capability_type'] : 'post',
			'map_meta_cap'       => true,
			'delete_with_user'   => ! empty( $def['delete_with_user'] ),
		);
	}

	/**
	 * @param mixed $v
	 */
	private function sanitize_menu_position( mixed $v ): ?int {
		if ( null === $v || '' === $v ) {
			return null;
		}
		$pos = (int) $v;
		if ( $pos < 1 || $pos > 99 ) {
			return null;
		}
		return $pos;
	}

	/**
	 * Surface runtime registration failures as an admin notice.
	 */
	public function registration_failure_notice(): void {
		if ( array() === self::$registration_failures || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p>
				<?php
				printf(
					/* translators: %s: comma-separated list of "slug: reason" */
					esc_html__( 'TK Fields could not register these content types: %s. Their content is safe but unreachable until the conflict is resolved.', 'tk-fields' ),
					esc_html( implode( '; ', self::$registration_failures ) )
				);
				?>
			</p>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Rewrite flushing (deferred flag)
	// ------------------------------------------------------------------

	/**
	 * Mark rewrite rules for flushing. Called by create()/update()/trash()/
	 * delete()/migrate_slug() — never on plain pageloads.
	 */
	private function flag_flush(): void {
		update_option( self::FLUSH_FLAG, 1, false ); // No autoload.
	}

	/**
	 * Consume the flush flag on init:999. Fires in every context (admin,
	 * REST, WP-CLI, cron) — unlike admin_init, which would leave
	 * REST/CLI-saved definitions unflushed and their permalinks 404ing.
	 * Idempotent: a flag surviving a failed request self-heals on the next
	 * init.
	 */
	public function consume_flush_flag(): void {
		if ( ! get_option( self::FLUSH_FLAG, false ) ) {
			return;
		}
		delete_option( self::FLUSH_FLAG );
		flush_rewrite_rules();
	}

	// ------------------------------------------------------------------
	// Snapshot (autoloaded registration cache)
	// ------------------------------------------------------------------

	/**
	 * Rebuild the autoloaded snapshot from all valid published definitions.
	 * Called explicitly by the store's write methods and as a safety net
	 * for external writers (wp-cli / direct wp_update_post).
	 */
	public function rebuild_snapshot(): void {
		if ( ! post_type_exists( self::CPT ) ) {
			return;
		}
		$posts = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		$map = array();
		foreach ( $posts as $post ) {
			$def = $this->read_definition( $post );
			if ( null === $def ) {
				continue; // Fail closed: invalid definitions never register.
			}
			$def['_id']        = $post->ID;
			$map[ $def['slug'] ] = $def;
		}
		update_option( self::SNAPSHOT_OPTION, $map, true ); // Autoload.
	}

	/**
	 * Safety-net wrapper for save_post: skips autosaves/revisions.
	 */
	public function maybe_rebuild_snapshot( int $post_id ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$this->rebuild_snapshot();
	}

	/**
	 * Safety-net wrapper for the generic trash/untrash/delete hooks.
	 */
	public function maybe_rebuild_snapshot_for( int $post_id ): void {
		$post = get_post( $post_id );
		if ( $post instanceof \WP_Post && self::CPT === $post->post_type ) {
			$this->rebuild_snapshot();
		}
	}

	// ------------------------------------------------------------------
	// Validation
	// ------------------------------------------------------------------

	/**
	 * Validate a slug outside of a full definition payload (used by the
	 * live-validation REST endpoint and the editor UI).
	 *
	 * @param string   $slug      Candidate slug.
	 * @param int|null $ignore_id Definition post ID to exclude from the
	 *                            uniqueness check (editing flow).
	 * @return array{valid: bool, errors: string[], warnings: string[]}
	 */
	public function validate_slug( string $slug, ?int $ignore_id = null ): array {
		$errors   = array();
		$warnings = array();

		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,19}$/', $slug ) ) {
			$errors[] = __( 'Slug must start with a lowercase letter and contain only lowercase letters, digits and underscores (max 20 characters).', 'tk-fields' );
			return array( 'valid' => false, 'errors' => $errors, 'warnings' => $warnings );
		}

		if ( in_array( $slug, self::RESERVED_SLUGS, true ) ) {
			/* translators: %s: content type slug */
			$errors[] = sprintf( __( '"%1$s" is reserved by WordPress and cannot be used.', 'tk-fields' ), $slug );
		}

		if ( str_starts_with( $slug, 'wp_' ) ) {
			$errors[] = __( 'Slugs starting with "wp_" are reserved for WordPress core.', 'tk-fields' );
		}

		if ( post_type_exists( $slug ) ) {
			// On update, the type's own registration is expected — only a
			// *different* owner of the slug is a conflict.
			$own = false;
			if ( null !== $ignore_id ) {
				foreach ( $this->all() as $existing ) {
					if ( (int) $existing['id'] === (int) $ignore_id && $existing['slug'] === $slug ) {
						$own = true;
						break;
					}
				}
			}
			if ( ! $own ) {
				/* translators: %s: content type slug */
				$errors[] = sprintf( __( 'A post type named "%1$s" is already registered (by the theme or another plugin).', 'tk-fields' ), $slug );
			}
		}

		// Our own definitions (excluding the one being edited).
		foreach ( $this->all() as $existing ) {
			if ( (int) $existing['id'] !== (int) $ignore_id && $existing['slug'] === $slug ) {
				/* translators: %s: content type slug */
				$errors[] = sprintf( __( 'A content type with the slug "%1$s" already exists.', 'tk-fields' ), $slug );
				break;
			}
		}

		// Page-slug collision: a page with the same slug shadows the CPT
		// rewrite rules (standard core behavior).
		$page = get_page_by_path( $slug );
		if ( $page instanceof \WP_Post ) {
			/* translators: %s: content type slug */
			$errors[] = sprintf( __( 'A page with the slug "%1$s" already exists — it would shadow this content type\'s URLs.', 'tk-fields' ), $slug );
		}

		if ( in_array( $slug, self::KNOWN_PLUGIN_SLUGS, true ) ) {
			/* translators: %s: content type slug */
			$warnings[] = sprintf( __( '"%1$s" is used by popular plugins (e.g. WooCommerce). If such a plugin is activated later, its post type will win and this content type will stop registering.', 'tk-fields' ), $slug );
		}

		return array(
			'valid'    => array() === $errors,
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Sanitize + validate a full definition payload. Fail closed: returns
	 * WP_Error and nothing should be written when invalid.
	 *
	 * @param array    $payload     Raw payload.
	 * @param int|null $existing_id Definition being updated (for slug checks).
	 * @return array|WP_Error Sanitized definition (without id), or WP_Error.
	 */
	public function sanitize_definition( array $payload, ?int $existing_id = null ): array|\WP_Error {
		$slug = isset( $payload['slug'] ) ? sanitize_key( (string) $payload['slug'] ) : '';
		$check = $this->validate_slug( $slug, $existing_id );
		if ( ! $check['valid'] ) {
			return new \WP_Error( 'tk_fields_ct_invalid_slug', $check['errors'][0], array( 'status' => 400 ) );
		}

		$singular = isset( $payload['singular'] ) ? sanitize_text_field( (string) $payload['singular'] ) : '';
		$plural   = isset( $payload['plural'] ) ? sanitize_text_field( (string) $payload['plural'] ) : '';
		if ( '' === $singular || '' === $plural ) {
			return new \WP_Error( 'tk_fields_ct_invalid_labels', __( 'Singular and plural labels are required.', 'tk-fields' ), array( 'status' => 400 ) );
		}

		// rest_base: charset + core-route blocklist + uniqueness. The RAW
		// value is validated before sanitize_key(): sanitizing first would
		// silently turn "wp/v2" into "wpv2" instead of rejecting it.
		$rest_base_raw = isset( $payload['rest_base'] ) ? (string) $payload['rest_base'] : '';
		$rest_base     = '' !== $rest_base_raw ? sanitize_key( $rest_base_raw ) : $slug;
		if ( '' !== $rest_base_raw && 1 !== preg_match( '/^[a-z][a-z0-9_\-]{0,39}$/', $rest_base_raw ) ) {
			return new \WP_Error( 'tk_fields_ct_invalid_rest_base', __( 'REST base must start with a lowercase letter (letters, digits, dashes, underscores; max 40).', 'tk-fields' ), array( 'status' => 400 ) );
		}
		if ( in_array( $rest_base, self::RESERVED_REST_BASES, true ) ) {
			/* translators: %s: REST base slug */
			return new \WP_Error( 'tk_fields_ct_invalid_rest_base', sprintf( __( '"%1$s" collides with a WordPress core REST route.', 'tk-fields' ), $rest_base ), array( 'status' => 400 ) );
		}
		foreach ( $this->all() as $existing ) {
			if ( (int) $existing['id'] !== (int) $existing_id && ( $existing['rest_base'] ?? $existing['slug'] ) === $rest_base ) {
				/* translators: %s: REST base slug */
				return new \WP_Error( 'tk_fields_ct_invalid_rest_base', sprintf( __( 'Another content type already uses the REST base "%1$s".', 'tk-fields' ), $rest_base ), array( 'status' => 400 ) );
			}
		}

		// Rewrite slugs: URL-segment charset, no slashes.
		$rewrite_slug = isset( $payload['rewrite_slug'] ) && '' !== (string) $payload['rewrite_slug']
			? (string) $payload['rewrite_slug']
			: $slug;
		$rewrite_slug = trim( $rewrite_slug, '/' );
		if ( 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $rewrite_slug ) ) {
			return new \WP_Error( 'tk_fields_ct_invalid_rewrite', __( 'Rewrite slug must be lowercase letters, digits and single dashes only (no slashes).', 'tk-fields' ), array( 'status' => 400 ) );
		}

		$has_archive  = ! empty( $payload['has_archive'] );
		$archive_slug = '';
		if ( $has_archive ) {
			$archive_slug = isset( $payload['archive_slug'] ) && '' !== (string) $payload['archive_slug']
				? trim( (string) $payload['archive_slug'], '/' )
				: $rewrite_slug;
			if ( 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $archive_slug ) ) {
				return new \WP_Error( 'tk_fields_ct_invalid_archive', __( 'Archive slug must be lowercase letters, digits and single dashes only.', 'tk-fields' ), array( 'status' => 400 ) );
			}
		}

		$supports = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $payload['supports'] ?? array( 'title', 'editor' ) ) ), self::SUPPORTS ) );
		if ( array() === $supports ) {
			$supports = array( 'title', 'editor' );
		}

		$hierarchical = ! empty( $payload['hierarchical'] );
		if ( $hierarchical && ! in_array( 'page-attributes', $supports, true ) ) {
			return new \WP_Error(
				'tk_fields_ct_hierarchical_needs_attributes',
				__( 'Hierarchical content types need the "Page attributes" support enabled (it renders the parent picker).', 'tk-fields' ),
				array( 'status' => 400 )
			);
		}

		// Taxonomies: keep only registered ones; unknown ones are dropped
		// (import reports them as warnings — see import()).
		$taxonomies = array_values(
			array_filter(
				array_map( 'sanitize_key', (array) ( $payload['taxonomies'] ?? array() ) ),
				static function ( $tax ) {
					return '' !== $tax && taxonomy_exists( $tax );
				}
			)
		);

		$capability_type = ( $payload['capability_type'] ?? 'post' ) === 'page' ? 'page' : 'post';

		$labels_override = array();
		if ( isset( $payload['labels_override'] ) && is_array( $payload['labels_override'] ) ) {
			foreach ( $payload['labels_override'] as $key => $value ) {
				$key = sanitize_key( (string) $key );
				if ( '' !== $key && is_string( $value ) && '' !== trim( $value ) && array_key_exists( $key, self::label_keys() ) ) {
					$labels_override[ $key ] = sanitize_text_field( $value );
				}
			}
		}

		$menu_icon = isset( $payload['menu_icon'] ) ? sanitize_text_field( (string) $payload['menu_icon'] ) : '';
		// Accepted: a Dashicons class ('dashicons dashicons-archive', as the
		// editor stores it), or a legacy 'fi-sr-*' value which is migrated
		// to its Dashicons successor. Anything else is dropped to '' so
		// the type falls back to the default icon.
		$resolved  = $this->resolve_menu_icon( $menu_icon );
		$menu_icon = '' !== $resolved ? 'dashicons ' . $resolved : '';

		$menu_position = isset( $payload['menu_position'] ) && '' !== $payload['menu_position'] ? absint( $payload['menu_position'] ) : null;
		if ( null !== $menu_position && ( $menu_position < 1 || $menu_position > 99 ) ) {
			$menu_position = null;
		}

		return array(
			'slug'               => $slug,
			'singular'           => $singular,
			'plural'             => $plural,
			'description'        => isset( $payload['description'] ) ? sanitize_textarea_field( (string) $payload['description'] ) : '',
			'public'             => ! empty( $payload['public'] ),
			// Core semantics: these default to `public` when not set
			// explicitly (matches register_post_type()).
			'publicly_queryable' => array_key_exists( 'publicly_queryable', $payload ) ? ! empty( $payload['publicly_queryable'] ) : ! empty( $payload['public'] ),
			'show_ui'            => array_key_exists( 'show_ui', $payload ) ? ! empty( $payload['show_ui'] ) : ! empty( $payload['public'] ),
			// Core semantics: show_in_menu defaults to show_ui.
			'show_in_menu'       => array_key_exists( 'show_in_menu', $payload )
				? ! empty( $payload['show_in_menu'] )
				: ( array_key_exists( 'show_ui', $payload ) ? ! empty( $payload['show_ui'] ) : ! empty( $payload['public'] ) ),
			'menu_position'      => $menu_position,
			'menu_icon'          => $menu_icon,
			'show_in_rest'       => ! empty( $payload['show_in_rest'] ),
			'rest_base'          => $rest_base,
			'has_archive'        => $has_archive,
			'archive_slug'       => $archive_slug,
			'rewrite_slug'       => $rewrite_slug,
			'rewrite'           => ! array_key_exists( 'rewrite', $payload ) || ! empty( $payload['rewrite'] ),
			'with_front'        => ! array_key_exists( 'with_front', $payload ) || ! empty( $payload['with_front'] ),
			'hierarchical'       => $hierarchical,
			'supports'           => $supports,
			'taxonomies'         => $taxonomies,
			'capability_type'    => $capability_type,
			'delete_with_user'   => ! empty( $payload['delete_with_user'] ),
			'labels_override'    => $labels_override,
		);
	}

	/**
	 * Label keys we generate (and accept overrides for).
	 *
	 * @return array<string, string> key => sprintf pattern (%1$s singular, %2$s plural, %3$s lowercase plural).
	 */
	private static function label_keys(): array {
		return array(
			'name'                  => '%2$s',
			'singular_name'         => '%1$s',
			'menu_name'             => '%2$s',
			'name_admin_bar'        => '%1$s',
			'archives'              => '%1$s Archives',
			'attributes'            => '%1$s Attributes',
			'parent_item_colon'     => 'Parent %1$s:',
			'all_items'             => 'All %2$s',
			'add_new_item'          => 'Add New %1$s',
			'add_new'               => 'Add New',
			'new_item'              => 'New %1$s',
			'edit_item'             => 'Edit %1$s',
			'view_item'             => 'View %1$s',
			'view_items'            => 'View %2$s',
			'search_items'          => 'Search %2$s',
			'not_found'             => 'No %3$s found.',
			'not_found_in_trash'    => 'No %3$s found in Trash.',
			'parent_item'           => 'Parent %1$s',
			'featured_image'        => '%1$s Featured Image',
			'set_featured_image'    => 'Set %3$s featured image',
			'remove_featured_image' => 'Remove %3$s featured image',
			'use_featured_image'    => 'Use as %3$s featured image',
			'insert_into_item'      => 'Insert into %3$s',
			'uploaded_to_this_item' => 'Uploaded to this %3$s',
			'items_list'            => '%2$s list',
			'items_list_navigation' => '%2$s list navigation',
			'filter_items_list'     => 'Filter %3$s list',
		);
	}

	/**
	 * Generate the full WP label set from singular/plural, applying
	 * overrides. User-entered labels are data: never wrapped in __().
	 *
	 * @param string $singular
	 * @param string $plural
	 * @param array  $overrides key => label.
	 */
	public function generate_labels( string $singular, string $plural, array $overrides = array() ): array {
		$lower_plural = strtolower( $plural );
		$labels       = array();
		foreach ( self::label_keys() as $key => $pattern ) {
			$labels[ $key ] = sprintf( $pattern, $singular, $plural, $lower_plural );
		}
		foreach ( $overrides as $key => $value ) {
			if ( isset( $labels[ $key ] ) && is_string( $value ) && '' !== trim( $value ) ) {
				$labels[ $key ] = $value;
			}
		}
		return $labels;
	}

	// ------------------------------------------------------------------
	// CRUD
	// ------------------------------------------------------------------

	/**
	 * Create a content type from a raw payload.
	 *
	 * @param array $payload Raw definition payload.
	 * @return array|WP_Error Full definition (id included), or WP_Error.
	 */
	public function create( array $payload ): array|\WP_Error {
		$clean = $this->sanitize_definition( $payload );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_title'  => $clean['plural'],
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$json = wp_json_encode( $clean );
		if ( false === $json ) {
			wp_delete_post( $post_id, true );
			return new \WP_Error( 'tk_fields_ct_encode_failed', __( 'Could not encode the content type definition.', 'tk-fields' ), array( 'status' => 500 ) );
		}
		update_post_meta( $post_id, self::META_KEY, $json );

		$this->rebuild_snapshot();
		$this->flag_flush();
		// Same-request consistency (tests, wp-cli): the new type is usable
		// immediately, not just on the next request.
		$this->register_all();
		$this->attach_taxonomies();

		return $this->get( $post_id );
	}

	/**
	 * Replace a content type's definition. The slug is immutable once the
	 * type has posts — use migrate_slug() for renames.
	 *
	 * @param int   $id      Definition post ID.
	 * @param array $payload Raw definition payload.
	 * @return array|WP_Error
	 */
	public function update( int $id, array $payload ): array|\WP_Error {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || self::CPT !== $post->post_type ) {
			return new \WP_Error( 'tk_fields_ct_not_found', __( 'Content type not found.', 'tk-fields' ), array( 'status' => 404 ) );
		}

		$current = $this->read_definition( $post );
		if ( null === $current ) {
			return new \WP_Error( 'tk_fields_ct_invalid', __( 'The stored definition is invalid.', 'tk-fields' ), array( 'status' => 500 ) );
		}

		// Slug lock: renaming with existing posts orphans them (post_type
		// is a DB key). The UI disables the field; the API enforces it.
		$new_slug = isset( $payload['slug'] ) ? sanitize_key( (string) $payload['slug'] ) : '';
		if ( '' !== $new_slug && $new_slug !== $current['slug'] && $this->post_count( $current['slug'] ) > 0 ) {
			return new \WP_Error(
				'tk_fields_ct_slug_locked',
				__( 'The slug cannot be changed while this content type has posts. Use the slug migration action instead.', 'tk-fields' ),
				array( 'status' => 400 )
			);
		}

		$clean = $this->sanitize_definition( $payload, $id );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$updated = wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => $clean['plural'],
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$json = wp_json_encode( $clean );
		if ( false === $json ) {
			return new \WP_Error( 'tk_fields_ct_encode_failed', __( 'Could not encode the content type definition.', 'tk-fields' ), array( 'status' => 500 ) );
		}
		update_post_meta( $id, self::META_KEY, $json );

		$this->rebuild_snapshot();
		$this->flag_flush();
		// Re-apply changed args in the current request (slug cannot change
		// here - that path is migrate_slug()).
		unregister_post_type( $clean['slug'] );
		$this->register_all();
		$this->attach_taxonomies();

		return $this->get( $id );
	}

	/**
	 * Migrate a content type to a new slug: rewrites the post_type DB key
	 * on every post of the old type, then updates the definition.
	 * Postmeta/terms/parents key by post ID and are unaffected; GUIDs go
	 * stale (harmless — core never uses them for routing).
	 *
	 * @param int    $id       Definition post ID.
	 * @param string $new_slug New slug (fully validated).
	 * @return array|WP_Error Updated definition, or WP_Error.
	 */
	public function migrate_slug( int $id, string $new_slug ): array|\WP_Error {
		global $wpdb;

		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || self::CPT !== $post->post_type ) {
			return new \WP_Error( 'tk_fields_ct_not_found', __( 'Content type not found.', 'tk-fields' ), array( 'status' => 404 ) );
		}

		$current = $this->read_definition( $post );
		if ( null === $current ) {
			return new \WP_Error( 'tk_fields_ct_invalid', __( 'The stored definition is invalid.', 'tk-fields' ), array( 'status' => 500 ) );
		}

		$old_slug = $current['slug'];
		$new_slug = sanitize_key( $new_slug );
		if ( $new_slug === $old_slug ) {
			return $this->get( $id );
		}

		$check = $this->validate_slug( $new_slug, $id );
		if ( ! $check['valid'] ) {
			return new \WP_Error( 'tk_fields_ct_invalid_slug', $check['errors'][0], array( 'status' => 400 ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- post_type is a DB key; no API exists for bulk re-keying.
		$moved_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $old_slug ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- post_type is a DB key; no API exists for bulk re-keying.
		$migrated = $wpdb->update( $wpdb->posts, array( 'post_type' => $new_slug ), array( 'post_type' => $old_slug ) );
		if ( false === $migrated ) {
			return new \WP_Error( 'tk_fields_ct_migrate_failed', __( 'Could not migrate posts to the new slug.', 'tk-fields' ), array( 'status' => 500 ) );
		}
		// The bulk UPDATE bypasses the object cache — clear it for the
		// moved posts so reads in this request see the new type.
		if ( is_array( $moved_ids ) ) {
			foreach ( $moved_ids as $moved_id ) {
				clean_post_cache( (int) $moved_id );
			}
		}

		$current['slug'] = $new_slug;
		if ( ( $current['rest_base'] ?? '' ) === $old_slug ) {
			$current['rest_base'] = $new_slug;
		}
		if ( ( $current['rewrite_slug'] ?? '' ) === $old_slug ) {
			$current['rewrite_slug'] = $new_slug;
		}

		$json = wp_json_encode( $current );
		if ( false === $json ) {
			return new \WP_Error( 'tk_fields_ct_encode_failed', __( 'Could not encode the content type definition.', 'tk-fields' ), array( 'status' => 500 ) );
		}
		update_post_meta( $id, self::META_KEY, $json );

		$this->rebuild_snapshot();
		$this->flag_flush();
		// Drop the old slug's registration and pick up the new one now.
		unregister_post_type( $old_slug );
		$this->register_all();
		$this->attach_taxonomies();

		return $this->get( $id );
	}

	/**
	 * Trash a content type definition (never hard-deletes via this path).
	 * Trashed = unregistered (snapshot only includes publish).
	 */
	public function trash( int $id ): bool|\WP_Error {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || self::CPT !== $post->post_type ) {
			return new \WP_Error( 'tk_fields_ct_not_found', __( 'Content type not found.', 'tk-fields' ), array( 'status' => 404 ) );
		}
		$def  = $this->read_definition( $post );
		$slug = is_array( $def ) ? (string) ( $def['slug'] ?? '' ) : '';

		if ( 'trash' !== $post->post_status ) {
			if ( ! wp_trash_post( $id ) ) {
				return new \WP_Error( 'tk_fields_ct_trash_failed', __( 'Could not trash the content type.', 'tk-fields' ), array( 'status' => 500 ) );
			}
		}

		$this->rebuild_snapshot();
		$this->flag_flush();
		if ( '' !== $slug ) {
			unregister_post_type( $slug );
		}

		return true;
	}

	/**
	 * Permanently delete a definition. User content posts are NOT touched
	 * (they stay in the DB and revive if a type with the same slug is
	 * re-created).
	 */
	public function delete( int $id ): bool|\WP_Error {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || self::CPT !== $post->post_type ) {
			return new \WP_Error( 'tk_fields_ct_not_found', __( 'Content type not found.', 'tk-fields' ), array( 'status' => 404 ) );
		}
		$def  = $this->read_definition( $post );
		$slug = is_array( $def ) ? (string) ( $def['slug'] ?? '' ) : '';

		$deleted = wp_delete_post( $id, true );
		if ( ! $deleted ) {
			return new \WP_Error( 'tk_fields_ct_delete_failed', __( 'Could not delete the content type.', 'tk-fields' ), array( 'status' => 500 ) );
		}

		$this->rebuild_snapshot();
		$this->flag_flush();
		if ( '' !== $slug ) {
			unregister_post_type( $slug );
		}

		return true;
	}

	/**
	 * Get one content type by definition ID.
	 */
	public function get( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || self::CPT !== $post->post_type || 'trash' === $post->post_status ) {
			return null;
		}

		$def = $this->read_definition( $post );
		if ( null === $def ) {
			return null;
		}

		return array_merge(
			array(
				'id'         => $post->ID,
				'post_count' => $this->post_count( $def['slug'] ),
				'modified'   => get_post_modified_time( 'c', false, $post ),
			),
			$def
		);
	}

	/**
	 * All non-trashed content types, full definitions, ordered by ID.
	 *
	 * @return array<int, array>
	 */
	public function all(): array {
		if ( ! post_type_exists( self::CPT ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		$types = array();
		foreach ( $posts as $post ) {
			$def = $this->read_definition( $post );
			if ( null === $def ) {
				continue; // Fail closed.
			}
			$types[] = array_merge(
				array(
					'id'         => $post->ID,
					'post_count' => $this->post_count( $def['slug'] ),
					'modified'   => get_post_modified_time( 'c', false, $post ),
				),
				$def
			);
		}

		return $types;
	}

	/**
	 * Count posts of a user content type (any status except trash/auto-draft).
	 */
	public function post_count( string $slug ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- wp_count_posts() requires a registered type; direct count works pre-registration.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft')",
				$slug
			)
		);
		return (int) $count;
	}

	/**
	 * Read + re-validate the stored JSON definition. Fail closed: null for
	 * missing, malformed, or invalid definitions.
	 */
	private function read_definition( \WP_Post $post ): ?array {
		$raw = get_post_meta( $post->ID, self::META_KEY, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$def = json_decode( $raw, true );
		if ( ! is_array( $def ) ) {
			return null;
		}
		// Re-validate the slug shape defensively (meta can be written
		// directly, bypassing sanitize_definition()).
		$slug = $def['slug'] ?? '';
		if ( ! is_string( $slug ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,19}$/', $slug ) ) {
			return null;
		}
		// Backfill defaults so older definitions keep working.
		$defaults = array(
			'singular'           => '',
			'plural'             => '',
			'description'        => '',
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'menu_position'      => null,
			'menu_icon'          => '',
			'show_in_rest'       => true,
			'rest_base'          => $slug,
			'has_archive'        => false,
			'archive_slug'       => '',
			'rewrite_slug'       => $slug,
			'hierarchical'       => false,
			'supports'           => array( 'title', 'editor' ),
			'taxonomies'         => array(),
			'capability_type'    => 'post',
			'delete_with_user'   => false,
			'labels_override'    => array(),
		);
		return array_merge( $defaults, $def );
	}

	// ------------------------------------------------------------------
	// JSON export / import
	// ------------------------------------------------------------------

	/**
	 * Export definitions as a portable JSON document.
	 *
	 * @param int[]|null $ids Export only these definition IDs; null = all.
	 */
	public function export( ?array $ids = null ): array {
		$types = array();
		foreach ( $this->all() as $type ) {
			if ( null !== $ids && ! in_array( (int) $type['id'], array_map( 'intval', $ids ), true ) ) {
				continue;
			}
			$def = $type;
			unset( $def['id'], $def['post_count'], $def['modified'] );
			$types[] = $def;
		}

		return array(
			'format'        => 'tk-content-types',
			'version'       => 1,
			'exported_at'   => gmdate( 'c' ),
			'plugin'        => 'tk-fields',
			'content_types' => $types,
		);
	}

	/**
	 * Import a JSON document. Every item is fully validated; conflicting
	 * slugs are SKIPPED (never renamed, never overwritten) and reported.
	 * Unknown taxonomies/supports produce per-item warnings, not failures.
	 *
	 * @param array $doc Decoded JSON document.
	 * @return array{created: array, skipped: array, warnings: array}|WP_Error
	 */
	public function import( array $doc ): array|\WP_Error {
		if ( ( $doc['format'] ?? '' ) !== 'tk-content-types' ) {
			return new \WP_Error( 'tk_fields_ct_import_format', __( 'Not a TK Fields content-types export (missing format marker).', 'tk-fields' ), array( 'status' => 400 ) );
		}
		$version = (int) ( $doc['version'] ?? 0 );
		if ( $version < 1 || $version > 1 ) {
			return new \WP_Error( 'tk_fields_ct_import_version', __( 'Unsupported content-types export version.', 'tk-fields' ), array( 'status' => 400 ) );
		}
		$items = $doc['content_types'] ?? null;
		if ( ! is_array( $items ) ) {
			return new \WP_Error( 'tk_fields_ct_import_shape', __( 'The export contains no content_types array.', 'tk-fields' ), array( 'status' => 400 ) );
		}
		if ( count( $items ) > 100 ) {
			return new \WP_Error( 'tk_fields_ct_import_too_many', __( 'Import is limited to 100 content types per file.', 'tk-fields' ), array( 'status' => 400 ) );
		}

		$created  = array();
		$skipped  = array();
		$warnings = array();

		foreach ( $items as $i => $item ) {
			$label = is_array( $item ) ? (string) ( $item['plural'] ?? $item['slug'] ?? '#' . $i ) : '#' . $i;
			if ( ! is_array( $item ) ) {
				$skipped[] = array( 'label' => $label, 'reason' => __( 'Item is not an object.', 'tk-fields' ) );
				continue;
			}

			// Pre-check slug conflicts before full validation so the
			// report names the real cause.
			$slug = isset( $item['slug'] ) ? sanitize_key( (string) $item['slug'] ) : '';
			$conflict = false;
			foreach ( $this->all() as $existing ) {
				if ( $existing['slug'] === $slug ) {
					$conflict = true;
					break;
				}
			}
			if ( $conflict || ( '' !== $slug && post_type_exists( $slug ) ) ) {
				$skipped[] = array( 'label' => $label, 'slug' => $slug, 'reason' => __( 'Slug already exists — skipped, nothing was overwritten.', 'tk-fields' ) );
				continue;
			}

			// Warn-and-drop unknown taxonomies / supports before validation.
			foreach ( (array) ( $item['taxonomies'] ?? array() ) as $tax ) {
				if ( is_string( $tax ) && '' !== $tax && ! taxonomy_exists( $tax ) ) {
					/* translators: 1: content type label, 2: taxonomy slug */
					$warnings[] = sprintf( __( '"%1$s": taxonomy "%2$s" is not registered on this site — imported without it.', 'tk-fields' ), $label, $tax );
				}
			}
			foreach ( (array) ( $item['supports'] ?? array() ) as $support ) {
				if ( is_string( $support ) && ! in_array( $support, self::SUPPORTS, true ) ) {
					/* translators: 1: content type label, 2: unsupported feature name */
					$warnings[] = sprintf( __( '"%1$s": unknown support "%2$s" — dropped.', 'tk-fields' ), $label, $support );
				}
			}

			$result = $this->create( $item );
			if ( is_wp_error( $result ) ) {
				$skipped[] = array( 'label' => $label, 'slug' => $slug, 'reason' => $result->get_error_message() );
				continue;
			}
			$created[] = array( 'id' => $result['id'], 'slug' => $result['slug'], 'plural' => $result['plural'] );
		}

		return array(
			'created'  => $created,
			'skipped'  => $skipped,
			'warnings' => $warnings,
		);
	}
}
