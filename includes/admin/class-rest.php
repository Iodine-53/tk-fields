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
 * REST API for the field-group builder (namespace `tk/v1`).
 *
 * Standard WP cookie auth + X-WP-Nonce; every route requires
 * `manage_options`. No custom authentication.
 *
 * Routes:
 *   GET    /groups            -> group summaries
 *   POST   /groups            -> create (201)
 *   GET    /groups/<id>       -> full group
 *   PUT    /groups/<id>       -> update
 *   DELETE /groups/<id>       -> trash
 *   GET    /field-types       -> per-type UI setting descriptors for the React app
 *   GET    /relationship-search -> paginated post search for the relationship picker
 *   GET    /user-search         -> paginated user search for the user picker
 *
 * The two *-search routes use edit_posts (not manage_options): the block
 * editor needs them while editing a post.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rest {

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
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Every route in this namespace requires manage_options. Logged-out
	 * requests get 401, logged-in-but-underprivileged requests get 403 —
	 * handled by core from this false.
	 */
	public function permissions_check(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * The picker search routes need the lighter edit_posts cap: the block
	 * editor calls them while editing a post, where authors (not admins)
	 * must be able to pick related posts and users.
	 */
	public function search_permissions_check(): bool {
		return current_user_can( 'edit_posts' );
	}

	public function register_routes(): void {
		register_rest_route(
			'tk/v1',
			'/groups',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_groups' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_group' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			'tk/v1',
			'/groups/(?P<id>\d+)',
			array(
				'args' => array(
					'id' => array(
						'description' => __( 'Field group ID.', 'tk-fields' ),
						'type'        => 'integer',
						'required'    => true,
					),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_group' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_group' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_group' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		// v0.15.0: Content Types (custom post type builder).
		register_rest_route(
			'tk/v1',
			'/content-types',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_content_types' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_content_type' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			'tk/v1',
			'/content-types/(?P<id>\d+)',
			array(
				'args' => array(
					'id' => array(
						'description' => __( 'Content type ID.', 'tk-fields' ),
						'type'        => 'integer',
						'required'    => true,
					),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_content_type' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_content_type' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_content_type' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			'tk/v1',
			'/content-types/validate-slug',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'validate_content_type_slug' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			'tk/v1',
			'/content-types/(?P<id>\d+)/migrate-slug',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'migrate_content_type_slug' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			'tk/v1',
			'/content-types/export',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'export_content_types' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			'tk/v1',
			'/content-types/import',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'import_content_types' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			'tk/v1',
			'/content-types/taxonomies',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_content_type_taxonomies' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			'tk/v1',
			'/field-types',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'field_types' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			'tk/v1',
			'/relationship-search',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'relationship_search' ),
					'permission_callback' => array( $this, 'search_permissions_check' ),
				),
			)
		);

		register_rest_route(
			'tk/v1',
			'/user-search',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'user_search' ),
					'permission_callback' => array( $this, 'search_permissions_check' ),
				),
			)
		);

		// The block editor resolves flexible_content layouts (and clone
		// source labels) by field name while editing — authors need this,
		// so it uses the lighter edit_posts cap like the picker searches.
		register_rest_route(
			'tk/v1',
			'/field-def/(?P<name>[a-z0-9_]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'field_def' ),
					'permission_callback' => array( $this, 'search_permissions_check' ),
					'args'                => array(
						'name' => array(
							'description' => __( 'Field name.', 'tk-fields' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);
	}

	/**
	 * GET /groups
	 *
	 * @param \WP_REST_Request $request
	 */
	public function list_groups( \WP_REST_Request $request ): \WP_REST_Response {
		$groups = array();
		foreach ( Group_Store::instance()->all() as $group ) {
			$groups[] = $this->summarize( $group );
		}

		return rest_ensure_response( array( 'groups' => $groups ) );
	}

	/**
	 * POST /groups
	 *
	 * @param \WP_REST_Request $request
	 */
	public function create_group( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$payload = $this->request_payload( $request );
		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'tk_fields_bad_json', __( 'Request body must be a JSON object.', 'tk-fields' ), array( 'status' => 400 ) );
		}

		$group = Group_Store::instance()->create( $payload );
		if ( is_wp_error( $group ) ) {
			return $group;
		}

		$response = rest_ensure_response( array( 'group' => $group ) );
		$response->set_status( 201 );
		$response->header( 'Location', rest_url( sprintf( 'tk/v1/groups/%d', $group['id'] ) ) );

		return $response;
	}

	/**
	 * GET /groups/<id>
	 *
	 * @param \WP_REST_Request $request
	 */
	public function get_group( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$group = Group_Store::instance()->get( (int) $request['id'] );
		if ( null === $group ) {
			return new \WP_Error( 'tk_fields_group_not_found', __( 'Field group not found.', 'tk-fields' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( array( 'group' => $group ) );
	}

	/**
	 * PUT /groups/<id>
	 *
	 * @param \WP_REST_Request $request
	 */
	public function update_group( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$payload = $this->request_payload( $request );
		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'tk_fields_bad_json', __( 'Request body must be a JSON object.', 'tk-fields' ), array( 'status' => 400 ) );
		}

		$group = Group_Store::instance()->update( (int) $request['id'], $payload );
		if ( is_wp_error( $group ) ) {
			return $group;
		}

		return rest_ensure_response( array( 'group' => $group ) );
	}

	/**
	 * DELETE /groups/<id> — trash, never hard-delete.
	 *
	 * @param \WP_REST_Request $request
	 */
	public function delete_group( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = Group_Store::instance()->trash( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	// ------------------------------------------------------------------
	// v0.15.0: Content Types (custom post type builder).
	// ------------------------------------------------------------------

	/**
	 * GET /content-types — summaries for the list table.
	 *
	 * @param \WP_REST_Request $request
	 */
	public function list_content_types( \WP_REST_Request $request ): \WP_REST_Response {
		$types = array();
		foreach ( Content_Type_Store::instance()->all() as $type ) {
			$types[] = $this->summarize_content_type( $type );
		}

		return rest_ensure_response( array( 'content_types' => $types ) );
	}

	/**
	 * POST /content-types
	 *
	 * @param \WP_REST_Request $request
	 */
	public function create_content_type( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$payload = $this->request_payload( $request );
		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'tk_fields_bad_json', __( 'Request body must be a JSON object.', 'tk-fields' ), array( 'status' => 400 ) );
		}

		$type = Content_Type_Store::instance()->create( $payload );
		if ( is_wp_error( $type ) ) {
			return $type;
		}

		$response = rest_ensure_response( array( 'content_type' => $type ) );
		$response->set_status( 201 );
		$response->header( 'Location', rest_url( sprintf( 'tk/v1/content-types/%d', $type['id'] ) ) );

		return $response;
	}

	/**
	 * GET /content-types/<id>
	 *
	 * @param \WP_REST_Request $request
	 */
	public function get_content_type( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$type = Content_Type_Store::instance()->get( (int) $request['id'] );
		if ( null === $type ) {
			return new \WP_Error( 'tk_fields_ct_not_found', __( 'Content type not found.', 'tk-fields' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( array( 'content_type' => $type ) );
	}

	/**
	 * PUT /content-types/<id>
	 *
	 * @param \WP_REST_Request $request
	 */
	public function update_content_type( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$payload = $this->request_payload( $request );
		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'tk_fields_bad_json', __( 'Request body must be a JSON object.', 'tk-fields' ), array( 'status' => 400 ) );
		}

		$type = Content_Type_Store::instance()->update( (int) $request['id'], $payload );
		if ( is_wp_error( $type ) ) {
			return $type;
		}

		return rest_ensure_response( array( 'content_type' => $type ) );
	}

	/**
	 * DELETE /content-types/<id> — trash, never hard-delete.
	 *
	 * @param \WP_REST_Request $request
	 */
	public function delete_content_type( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = Content_Type_Store::instance()->trash( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * POST /content-types/validate-slug — live slug validation for the editor.
	 *
	 * Body: { slug: string, id?: number }
	 *
	 * @param \WP_REST_Request $request
	 */
	public function validate_content_type_slug( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$payload = $this->request_payload( $request );
		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'tk_fields_bad_json', __( 'Request body must be a JSON object.', 'tk-fields' ), array( 'status' => 400 ) );
		}

		$slug = isset( $payload['slug'] ) ? sanitize_key( (string) $payload['slug'] ) : '';
		$id   = isset( $payload['id'] ) ? absint( $payload['id'] ) : null;

		return rest_ensure_response( Content_Type_Store::instance()->validate_slug( $slug, $id ?: null ) );
	}

	/**
	 * POST /content-types/<id>/migrate-slug — bulk re-key posts to a new slug.
	 *
	 * Body: { slug: string }
	 *
	 * @param \WP_REST_Request $request
	 */
	public function migrate_content_type_slug( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$payload = $this->request_payload( $request );
		if ( ! is_array( $payload ) || ! isset( $payload['slug'] ) ) {
			return new \WP_Error( 'tk_fields_bad_json', __( 'Request body must be a JSON object with a "slug".', 'tk-fields' ), array( 'status' => 400 ) );
		}

		$type = Content_Type_Store::instance()->migrate_slug( (int) $request['id'], (string) $payload['slug'] );
		if ( is_wp_error( $type ) ) {
			return $type;
		}

		return rest_ensure_response( array( 'content_type' => $type ) );
	}

	/**
	 * GET /content-types/export[?ids=1,2] — portable JSON document.
	 *
	 * @param \WP_REST_Request $request
	 */
	public function export_content_types( \WP_REST_Request $request ): \WP_REST_Response {
		$ids = $request->get_param( 'ids' );
		$ids = null === $ids ? null : $this->parse_id_list( $ids );
		if ( is_array( $ids ) && array() === $ids ) {
			$ids = array();
		}

		return rest_ensure_response( Content_Type_Store::instance()->export( $ids ) );
	}

	/**
	 * POST /content-types/import — validate + import a JSON document.
	 *
	 * @param \WP_REST_Request $request
	 */
	public function import_content_types( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$payload = $this->request_payload( $request );
		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'tk_fields_bad_json', __( 'Request body must be a JSON object.', 'tk-fields' ), array( 'status' => 400 ) );
		}

		$result = Content_Type_Store::instance()->import( $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * GET /content-types/taxonomies — public taxonomies a type may attach to.
	 *
	 * @param \WP_REST_Request $request
	 */
	public function list_content_type_taxonomies( \WP_REST_Request $request ): \WP_REST_Response {
		$taxonomies = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			$taxonomies[] = array(
				'slug'         => $tax->name,
				'label'        => $tax->label,
				'hierarchical' => $tax->hierarchical,
			);
		}

		return rest_ensure_response( array( 'taxonomies' => $taxonomies ) );
	}

	/**
	 * One content-type summary for the list table.
	 */
	private function summarize_content_type( array $type ): array {
		return array(
			'id'             => $type['id'],
			'slug'           => $type['slug'],
			'singular'       => $type['singular'],
			'plural'         => $type['plural'],
			'post_count'     => $type['post_count'],
			'modified'       => $type['modified'],
			'public'         => ! empty( $type['public'] ),
			'show_in_rest'   => ! empty( $type['show_in_rest'] ),
			'has_archive'    => ! empty( $type['has_archive'] ),
			'hierarchical'   => ! empty( $type['hierarchical'] ),
			'taxonomies'     => $type['taxonomies'] ?? array(),
			'registered'     => post_type_exists( $type['slug'] ),
		);
	}

	/**
	 * GET /relationship-search — paginated post search for the relationship
	 * (and post-object) picker.
	 *
	 * Params: search (string), post_types (array or comma string; default
	 * public post types), filter_taxonomy, filter_term (slug or ID),
	 * page=1, per_page=20 (capped at 20), include (comma IDs — hydrates
	 * already-selected items, ordered post__in).
	 *
	 * Published-only in v1: the picker selects public content, not drafts.
	 *
	 * @param \WP_REST_Request $request
	 */
	public function relationship_search( \WP_REST_Request $request ): \WP_REST_Response {
		$post_types = $this->parse_list_param( $request->get_param( 'post_types' ) );
		if ( array() === $post_types ) {
			$post_types = array_values( get_post_types( array( 'public' => true ) ) );
		}

		$page     = max( 1, absint( $request->get_param( 'page' ) ?? 1 ) );
		$per_page = min( 20, max( 1, absint( $request->get_param( 'per_page' ) ?? 20 ) ) );

		$include = $this->parse_id_list( $request->get_param( 'include' ) );
		if ( null !== $include && array() === $include ) {
			return rest_ensure_response(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);
		}

		$args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
		);

		$search = (string) ( $request->get_param( 'search' ) ?? '' );
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		if ( null !== $include ) {
			$args['post__in'] = $include;
			$args['orderby']  = 'post__in';
		}

		$filter_taxonomy = (string) ( $request->get_param( 'filter_taxonomy' ) ?? '' );
		$filter_term     = (string) ( $request->get_param( 'filter_term' ) ?? '' );
		if ( '' !== $filter_taxonomy && '' !== $filter_term && taxonomy_exists( $filter_taxonomy ) ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => $filter_taxonomy,
					'field'    => ctype_digit( $filter_term ) ? 'term_id' : 'slug',
					'terms'    => ctype_digit( $filter_term ) ? (int) $filter_term : $filter_term,
				),
			);
		}

		$query = new \WP_Query( $args );

		$items = array();
		foreach ( $query->posts as $post ) {
			$title   = get_the_title( $post );
			$items[] = array(
				'id'        => $post->ID,
				'title'     => '' !== $title ? $title : '(no title)',
				'post_type' => $post->post_type,
				'url'       => get_permalink( $post ),
			);
		}

		return rest_ensure_response(
			array(
				'items'       => $items,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
			)
		);
	}

	/**
	 * GET /user-search — paginated user search for the user picker.
	 *
	 * Params: search (string, wildcarded both sides), roles (array or comma
	 * string), page=1, per_page=20 (capped at 20), include (comma IDs —
	 * hydrates already-selected users).
	 *
	 * @param \WP_REST_Request $request
	 */
	public function user_search( \WP_REST_Request $request ): \WP_REST_Response {
		$roles    = $this->parse_list_param( $request->get_param( 'roles' ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ?? 1 ) );
		$per_page = min( 20, max( 1, absint( $request->get_param( 'per_page' ) ?? 20 ) ) );

		$include = $this->parse_id_list( $request->get_param( 'include' ) );
		if ( null !== $include && array() === $include ) {
			return rest_ensure_response(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				)
			);
		}

		$args = array(
			'number' => $per_page,
			'paged'  => $page,
		);

		$search = (string) ( $request->get_param( 'search' ) ?? '' );
		if ( '' !== $search ) {
			$args['search'] = '*' . $search . '*';
		}

		if ( array() !== $roles ) {
			$args['role__in'] = $roles;
		}

		if ( null !== $include ) {
			$args['include'] = $include;
		}

		$query = new \WP_User_Query( $args );

		$items = array();
		foreach ( $query->get_results() as $user ) {
			$items[] = array(
				'id'           => $user->ID,
				'display_name' => $user->display_name,
				'avatar_url'   => get_avatar_url( $user->ID ),
			);
		}

		$total = (int) $query->get_total();

		return rest_ensure_response(
			array(
				'items'       => $items,
				'total'       => $total,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
	}

	/**
	 * GET /field-def/<name> — the resolved field definition for one field.
	 *
	 * The block editor calls this when an author types a field name into a
	 * tk/flexible-content block: the response carries the layouts (with
	 * sub-field definitions) the editor needs to offer Add-layout buttons
	 * and build the layout template blocks. edit_posts cap, like the
	 * picker search routes.
	 *
	 * @param \WP_REST_Request $request
	 */
	public function field_def( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$field = Field_Registry::instance()->get( (string) $request['name'] );
		if ( null === $field ) {
			return new \WP_Error(
				'tk_fields_unknown_field',
				__( 'Unknown field.', 'tk-fields' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( array( 'field' => $field ) );
	}

	/**
	 * Parse a slug-list param: an array as-is, or a comma-separated string.
	 * Returns unique sanitized slugs, order preserved.
	 *
	 * @param mixed $raw Raw param value.
	 * @return string[]
	 */
	private function parse_list_param( mixed $raw ): array {
		if ( is_array( $raw ) ) {
			$list = $raw;
		} elseif ( is_string( $raw ) && '' !== $raw ) {
			$list = explode( ',', $raw );
		} else {
			return array();
		}

		$clean = array();
		foreach ( $list as $item ) {
			$slug = is_scalar( $item ) ? sanitize_key( trim( (string) $item ) ) : '';
			if ( '' !== $slug && ! in_array( $slug, $clean, true ) ) {
				$clean[] = $slug;
			}
		}

		return $clean;
	}

	/**
	 * Parse an ID-list param (the `include` params). Null when the param is
	 * absent; otherwise the unique positive IDs, order preserved.
	 *
	 * @param mixed $raw Raw param value.
	 * @return int[]|null
	 */
	private function parse_id_list( mixed $raw ): ?array {
		if ( null === $raw || '' === $raw ) {
			return null;
		}

		$list = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$ids  = array();
		foreach ( $list as $item ) {
			$id = absint( $item );
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * GET /field-types — one entry per registry type. The `settings` array
	 * drives the React UI generically: the client never hardcodes field types.
	 *
	 * Each setting: { key, label, tooltip, control, options?, placeholder? }
	 * where control is one of: text | textarea | number | toggle | select |
	 * choices | multicheck. The multicheck control renders a checkbox list and
	 * stores a string[]; the select control is single-value only.
	 *
	 * v0.12.0: each type also carries { category, icon, aliases,
	 * block_editor_only } for the searchable, tabbed type picker.
	 */
	/**
	 * v0.12.0: picker metadata for the type picker (search, category tabs,
	 * icon tiles). Categories: basic | content | choice | relational |
	 * advanced | layout. Icons are WordPress core Dashicons slugs
	 * (dashicons-*), always available in wp-admin with no bundled font.
	 * Aliases feed the picker's search index. block_editor_only marks
	 * types with no classic-editor rendering (see CLASSIC-EDITOR-NOTES.md).
	 *
	 * v0.14.0: Dashicons replaced with UIcons across the picker.
	 * Later: UIcons removed again (non-GPL-compatible license); Dashicons
	 * restored as the single icon source.
	 *
	 * @return array<string,array{category:string,icon:string,aliases:string[],block_editor_only?:bool}>
	 */
	private static function type_picker_meta(): array {
		return array(
			// -- Basic --
			'text'           => array( 'category' => 'basic', 'icon' => 'editor-textcolor', 'aliases' => array( 'single line', 'short text', 'string' ) ),
			'textarea'       => array( 'category' => 'basic', 'icon' => 'editor-paragraph', 'aliases' => array( 'multi-line', 'multiline', 'long text', 'paragraph' ) ),
			'number'         => array( 'category' => 'basic', 'icon' => 'calculator', 'aliases' => array( 'integer', 'float', 'numeric' ) ),
			'email'          => array( 'category' => 'basic', 'icon' => 'email', 'aliases' => array( 'e-mail', 'mail' ) ),
			'url'            => array( 'category' => 'basic', 'icon' => 'admin-site', 'aliases' => array( 'website', 'web address' ) ),
			'password'       => array( 'category' => 'basic', 'icon' => 'lock', 'aliases' => array( 'secret', 'pass' ) ),
			'range'          => array( 'category' => 'basic', 'icon' => 'admin-settings', 'aliases' => array( 'slider' ) ),
			'checkbox'       => array( 'category' => 'basic', 'icon' => 'yes-alt', 'aliases' => array( 'true false', 'true/false', 'toggle', 'boolean' ) ),
			'date'           => array( 'category' => 'basic', 'icon' => 'calendar', 'aliases' => array( 'calendar', 'day' ) ),
			'datetime'       => array( 'category' => 'basic', 'icon' => 'calendar-alt', 'aliases' => array( 'date time', 'date/time' ) ),
			'time'           => array( 'category' => 'basic', 'icon' => 'clock', 'aliases' => array( 'hour' ) ),
			// -- Content --
			'wysiwyg'        => array( 'category' => 'content', 'icon' => 'edit-large', 'aliases' => array( 'rich text', 'richtext', 'editor', 'visual editor' ) ),
			'image'          => array( 'category' => 'content', 'icon' => 'format-image', 'aliases' => array( 'picture', 'photo', 'img' ) ),
			'file'           => array( 'category' => 'content', 'icon' => 'media-document', 'aliases' => array( 'upload', 'attachment', 'document', 'pdf' ) ),
			'gallery'        => array( 'category' => 'content', 'icon' => 'format-gallery', 'aliases' => array( 'images', 'photos', 'media gallery' ) ),
			'oembed'         => array( 'category' => 'content', 'icon' => 'format-video', 'aliases' => array( 'embed', 'video embed', 'youtube' ) ),
			'link'           => array( 'category' => 'content', 'icon' => 'admin-links', 'aliases' => array( 'url link' ) ),
			'icon'           => array( 'category' => 'content', 'icon' => 'star-filled', 'aliases' => array( 'dashicon', 'symbol' ) ),
			'map'            => array( 'category' => 'content', 'icon' => 'location-alt', 'aliases' => array( 'address', 'geocoder', 'coordinates', 'lat lng', 'latitude' ) ),
			'color'          => array( 'category' => 'content', 'icon' => 'art', 'aliases' => array( 'colour', 'hex' ) ),
			// -- Choice --
			'select'         => array( 'category' => 'choice', 'icon' => 'arrow-down-alt2', 'aliases' => array( 'dropdown', 'drop down', 'choose' ) ),
			'radio'          => array( 'category' => 'choice', 'icon' => 'list-view', 'aliases' => array( 'radio buttons', 'multiple choice', 'choose one' ) ),
			'button_group'   => array( 'category' => 'choice', 'icon' => 'grid-view', 'aliases' => array( 'segmented', 'segmented control', 'pills' ) ),
			// -- Relational --
			'post_object'    => array( 'category' => 'relational', 'icon' => 'admin-post', 'aliases' => array( 'post picker', 'post select', 'single post', 'featured' ) ),
			'page_link'      => array( 'category' => 'relational', 'icon' => 'admin-page', 'aliases' => array( 'page picker', 'internal link' ) ),
			'taxonomy'       => array( 'category' => 'relational', 'icon' => 'tag', 'aliases' => array( 'category picker', 'tags', 'terms', 'term' ) ),
			'user'           => array( 'category' => 'relational', 'icon' => 'admin-users', 'aliases' => array( 'author picker', 'member', 'people' ) ),
			'relationship'   => array( 'category' => 'relational', 'icon' => 'share', 'aliases' => array( 'post picker', 'related posts', 'related', 'posts picker', 'multiple posts' ), 'block_editor_only' => true ),
			// -- Advanced --
			'clone'          => array( 'category' => 'advanced', 'icon' => 'clipboard', 'aliases' => array( 'reuse', 'copy fields', 'duplicate group' ), 'block_editor_only' => true ),
			// -- Layout --
			'group'          => array( 'category' => 'layout', 'icon' => 'archive', 'aliases' => array( 'fieldset', 'sub fields', 'subfields', 'field group', 'nested' ) ),
			'flexible_content' => array( 'category' => 'layout', 'icon' => 'layout', 'aliases' => array( 'flexible', 'layouts', 'page builder', 'sections' ), 'block_editor_only' => true ),
			'repeater'       => array( 'category' => 'layout', 'icon' => 'editor-table', 'aliases' => array( 'repeater', 'repeatable', 'rows' ), 'block_editor_only' => true ),
			'message'        => array( 'category' => 'layout', 'icon' => 'info-outline', 'aliases' => array( 'notice', 'info text', 'instructions block' ) ),
			'separator'      => array( 'category' => 'layout', 'icon' => 'minus', 'aliases' => array( 'divider', 'horizontal rule', 'hr' ) ),
			'tab'            => array( 'category' => 'layout', 'icon' => 'welcome-widgets-menus', 'aliases' => array( 'tabs', 'tabbed' ) ),
		);
	}

	public function field_types(): \WP_REST_Response {
		$types = array();

		$text_settings = array(
			$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
			$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'hero_price' ),
			$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
			$this->setting( 'placeholder', __( 'Placeholder', 'tk-fields' ), __( 'Dimmed hint inside an empty input.', 'tk-fields' ), 'text' ),
			$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'A value must be entered.', 'tk-fields' ), 'toggle' ),
			$this->setting( 'default', __( 'Default value', 'tk-fields' ), __( 'Returned when nothing is stored (still "unset").', 'tk-fields' ), 'text' ),
			$this->setting( 'maxlength', __( 'Character limit', 'tk-fields' ), __( 'Maximum characters allowed.', 'tk-fields' ), 'number' ),
		);

		foreach ( Field_Registry::types() as $type ) {
			switch ( $type ) {
				case 'text':
					$types['text'] = array(
						'label'       => __( 'Text', 'tk-fields' ),
						'description' => __( 'Single-line text input.', 'tk-fields' ),
						'settings'    => $text_settings,
					);
					break;

				case 'textarea':
					$types['textarea'] = array(
						'label'       => __( 'Text Area', 'tk-fields' ),
						'description' => __( 'Multi-line text input.', 'tk-fields' ),
						'settings'    => $text_settings,
					);
					break;

				case 'number':
					$types['number'] = array(
						'label'       => __( 'Number', 'tk-fields' ),
						'description' => __( 'Numeric input, stored as int or float.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'hero_price' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'placeholder', __( 'Placeholder', 'tk-fields' ), __( 'Dimmed hint inside an empty input.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'A value must be entered.', 'tk-fields' ), 'toggle' ),
							$this->setting( 'default', __( 'Default value', 'tk-fields' ), __( 'Returned when nothing is stored (still "unset").', 'tk-fields' ), 'number' ),
							$this->setting( 'min', __( 'Minimum', 'tk-fields' ), __( 'Smallest allowed value.', 'tk-fields' ), 'number' ),
							$this->setting( 'max', __( 'Maximum', 'tk-fields' ), __( 'Largest allowed value.', 'tk-fields' ), 'number' ),
							$this->setting( 'step', __( 'Step', 'tk-fields' ), __( 'Increment between allowed values.', 'tk-fields' ), 'number' ),
						),
					);
					break;

				case 'email':
					$types['email'] = array(
						'label'       => __( 'Email', 'tk-fields' ),
						'description' => __( 'Email address, validated on save.', 'tk-fields' ),
						'settings'    => $this->without_keys( $text_settings, array( 'maxlength' ) ),
					);
					break;

				case 'url':
					$types['url'] = array(
						'label'       => __( 'URL', 'tk-fields' ),
						'description' => __( 'URL, validated on save.', 'tk-fields' ),
						'settings'    => $this->without_keys( $text_settings, array( 'maxlength' ) ),
					);
					break;

				case 'checkbox':
					$types['checkbox'] = array(
						'label'       => __( 'True / False', 'tk-fields' ),
						'description' => __( 'Single checkbox, stored as 1 or 0 and read back as bool. Deliberately single-value only — for multi-value selection use Select.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown next to the checkbox.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'show_banner' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'The box must be checked.', 'tk-fields' ), 'toggle' ),
							$this->setting( 'default', __( 'Checked by default', 'tk-fields' ), __( 'The box starts checked.', 'tk-fields' ), 'toggle' ),
						),
					);
					break;

				case 'select':
					$types['select'] = array(
						'label'       => __( 'Select', 'tk-fields' ),
						'description' => __( 'Dropdown of predefined choices.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'plan_tier' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'A choice must be picked.', 'tk-fields' ), 'toggle' ),
							$this->setting( 'choices', __( 'Choices', 'tk-fields' ), __( 'Value => label pairs; at least one required.', 'tk-fields' ), 'choices' ),
							$this->setting( 'default', __( 'Default choice', 'tk-fields' ), __( 'Value of the pre-selected choice.', 'tk-fields' ), 'text' ),
						),
					);
					break;

				case 'date':
					$types['date'] = array(
						'label'       => __( 'Date', 'tk-fields' ),
						'description' => __( 'Date picker, stored as YYYYMMDD.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'launch_date' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'A date must be picked.', 'tk-fields' ), 'toggle' ),
							$this->setting( 'default', __( 'Default value', 'tk-fields' ), __( 'Returned when nothing is stored (still "unset").', 'tk-fields' ), 'text', null, 'YYYYMMDD' ),
						),
					);
					break;

				case 'image':
					$types['image'] = array(
						'label'       => __( 'Image', 'tk-fields' ),
						'description' => __( 'Media library image, stored as attachment ID.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'hero_image' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'An image must be chosen.', 'tk-fields' ), 'toggle' ),
						),
					);
					break;

				case 'password':
					$types['password'] = array(
						'label'       => __( 'Password', 'tk-fields' ),
						'description' => __( 'Masked text input. Stored as plain text like any field — it is masked, not encrypted — and is always excluded from AI context export.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'api_secret' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'placeholder', __( 'Placeholder', 'tk-fields' ), __( 'Dimmed hint inside an empty input.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'A value must be entered.', 'tk-fields' ), 'toggle' ),
							$this->setting( 'maxlength', __( 'Character limit', 'tk-fields' ), __( 'Maximum characters allowed.', 'tk-fields' ), 'number' ),
						),
					);
					break;

				case 'message':
					$types['message'] = array(
						'label'       => __( 'Message', 'tk-fields' ),
						'description' => __( 'Static admin-only text. Renders in the field list; stores no value.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the message in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'notice_message' ),
							$this->setting( 'message', __( 'Message', 'tk-fields' ), __( 'The static text shown to editors. Basic HTML is allowed.', 'tk-fields' ), 'textarea' ),
						),
					);
					break;

				case 'separator':
					$types['separator'] = array(
						'label'       => __( 'Separator', 'tk-fields' ),
						'description' => __( 'Visual divider between fields. Pure UI; stores no value.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Optional label shown above the divider.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'section_divider' ),
						),
					);
					break;

				case 'tab':
					$types['tab'] = array(
						'label'       => __( 'Tab', 'tk-fields' ),
						'description' => __( 'Starts a new tab group: fields after this tab belong to it until the next tab. Stores no value.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'The tab title shown in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'details_tab' ),
						),
					);
					break;

				case 'range':
					$types['range'] = array(
						'label'       => __( 'Range', 'tk-fields' ),
						'description' => __( 'Number with a slider UI. Out-of-bounds values are clamped to min/max; values snap to the step increment.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'volume_level' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'A value must be entered.', 'tk-fields' ), 'toggle' ),
							$this->setting( 'default', __( 'Default value', 'tk-fields' ), __( 'Returned when nothing is stored (still "unset").', 'tk-fields' ), 'number' ),
							$this->setting( 'min', __( 'Minimum', 'tk-fields' ), __( 'Smallest allowed value; lower values are clamped up to this.', 'tk-fields' ), 'number' ),
							$this->setting( 'max', __( 'Maximum', 'tk-fields' ), __( 'Largest allowed value; higher values are clamped down to this.', 'tk-fields' ), 'number' ),
							$this->setting( 'step', __( 'Step', 'tk-fields' ), __( 'Slider increment; values snap to the nearest step from the minimum.', 'tk-fields' ), 'number' ),
						),
					);
					break;

				case 'link':
					$types['link'] = array(
						'label'       => __( 'Link', 'tk-fields' ),
						'description' => __( 'URL with optional title and target. Stored as a single array row: {url, title, target}.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'cta_link' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'A URL must be entered.', 'tk-fields' ), 'toggle' ),
						),
					);
					break;

				case 'radio':
					$types['radio'] = array(
						'label'       => __( 'Radio', 'tk-fields' ),
						'description' => __( 'Single choice from predefined options, rendered as radio buttons. Same scalar storage as select.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'plan_tier' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'A choice must be picked.', 'tk-fields' ), 'toggle' ),
							$this->setting( 'choices', __( 'Choices', 'tk-fields' ), __( 'Value => label pairs; at least one required.', 'tk-fields' ), 'choices' ),
							$this->setting( 'default', __( 'Default choice', 'tk-fields' ), __( 'Value of the pre-selected choice.', 'tk-fields' ), 'text' ),
						),
					);
					break;

				case 'button_group':
					$types['button_group'] = array(
						'label'       => __( 'Button Group', 'tk-fields' ),
						'description' => __( 'Single choice rendered as a segmented button control. Visual variant of radio — identical storage and validation.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'plan_tier' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'A choice must be picked.', 'tk-fields' ), 'toggle' ),
							$this->setting( 'choices', __( 'Choices', 'tk-fields' ), __( 'Value => label pairs; at least one required.', 'tk-fields' ), 'choices' ),
							$this->setting( 'default', __( 'Default choice', 'tk-fields' ), __( 'Value of the pre-selected choice.', 'tk-fields' ), 'text' ),
						),
					);
					break;

				case 'color':
					$types['color'] = array(
						'label'       => __( 'Color', 'tk-fields' ),
						'description' => __( 'Hex color picker. Validated and normalized to lowercase (#rgb, #rrggbb or #rrggbbaa).', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'brand_color' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'A color must be picked.', 'tk-fields' ), 'toggle' ),
							$this->setting( 'default', __( 'Default color', 'tk-fields' ), __( 'Hex value, e.g. #ff0000.', 'tk-fields' ), 'text', null, '#000000' ),
						),
					);
					break;

				case 'datetime':
					$types['datetime'] = array(
						'label'       => __( 'Date / Time', 'tk-fields' ),
						'description' => __( 'Date and time picker. Stored as "YYYY-MM-DD HH:MM:SS" (ACF parity).', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'event_start' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'A date and time must be picked.', 'tk-fields' ), 'toggle' ),
						),
					);
					break;

				case 'time':
					$types['time'] = array(
						'label'       => __( 'Time', 'tk-fields' ),
						'description' => __( 'Time-of-day picker. Stored as "HH:MM:SS" (24-hour).', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'opens_at' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'A time must be picked.', 'tk-fields' ), 'toggle' ),
						),
					);
					break;

				case 'oembed':
					$types['oembed'] = array(
						'label'       => __( 'oEmbed', 'tk-fields' ),
						'description' => __( 'URL of embeddable content (YouTube, X, Spotify…). Stores the URL string; the embed HTML is rendered through core’s cached oEmbed path and the cache is warmed when the field is saved.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'promo_video' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'placeholder', __( 'Placeholder', 'tk-fields' ), __( 'Dimmed hint inside an empty input.', 'tk-fields' ), 'text', null, 'https://…' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'A URL must be entered.', 'tk-fields' ), 'toggle' ),
						),
					);
					break;

				case 'icon':
					$types['icon'] = array(
						'label'       => __( 'Icon', 'tk-fields' ),
						'description' => __( 'Icon picker from the WordPress Dashicons set (built into core — no extra library). Stored as the icon slug, e.g. "admin-post".', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'feature_icon' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'An icon must be picked.', 'tk-fields' ), 'toggle' ),
							$this->setting( 'default', __( 'Default icon', 'tk-fields' ), __( 'Dashicons slug, e.g. star-filled.', 'tk-fields' ), 'text' ),
						),
					);
					break;

				case 'file':
					$types['file'] = array(
						'label'       => __( 'File', 'tk-fields' ),
						'description' => __( 'Single file from the media library (any type). Stored as attachment ID; formatted reads return the file URL.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'brochure_pdf' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'A file must be chosen.', 'tk-fields' ), 'toggle' ),
						),
					);
					break;

				case 'post_object':
					$types['post_object'] = array(
						'label'       => __( 'Post Object', 'tk-fields' ),
						'description' => __( 'Single post reference, stored as a post ID. For multiple posts use Relationship.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'featured_post' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'A post must be picked.', 'tk-fields' ), 'toggle' ),
							$this->setting( 'default', __( 'Default post ID', 'tk-fields' ), __( 'Post ID returned when nothing is stored (still "unset").', 'tk-fields' ), 'number' ),
							$this->setting( 'post_types', __( 'Post types', 'tk-fields' ), __( 'Limit selection to these post types. Leave empty to allow all public types.', 'tk-fields' ), 'multicheck', $this->post_type_options() ),
						),
					);
					break;

				case 'page_link':
					$types['page_link'] = array(
						'label'       => __( 'Page Link', 'tk-fields' ),
						'description' => __( 'Link to a post or (optionally) any URL. Stored with an explicit kind so permalinks self-heal.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'read_more_link' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'A link must be entered.', 'tk-fields' ), 'toggle' ),
							$this->setting( 'allow_external', __( 'Allow external URLs', 'tk-fields' ), __( 'Allow arbitrary external URLs as well as post links.', 'tk-fields' ), 'toggle' ),
						),
					);
					break;

				case 'taxonomy':
					$types['taxonomy'] = array(
						'label'       => __( 'Taxonomy', 'tk-fields' ),
						'description' => __( 'Term picker. Stores term IDs.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'article_topics' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'At least one term must be picked.', 'tk-fields' ), 'toggle' ),
							$this->setting( 'taxonomy', __( 'Taxonomy', 'tk-fields' ), __( 'Which taxonomy the picker draws terms from.', 'tk-fields' ), 'select', $this->taxonomy_options() ),
							$this->setting(
								'field_type',
								__( 'Picker type', 'tk-fields' ),
								__( 'Checkbox list suits hierarchical taxonomies; autocomplete suits flat or large ones.', 'tk-fields' ),
								'select',
								array(
									'checkbox'     => __( 'Checkbox list (hierarchical)', 'tk-fields' ),
									'autocomplete' => __( 'Autocomplete (flat/large)', 'tk-fields' ),
								)
							),
							$this->setting( 'save_terms', __( 'Save terms', 'tk-fields' ), __( 'WARNING: attaches the selected terms to the post with wp_set_post_terms when the field is saved, overwriting terms set elsewhere.', 'tk-fields' ), 'toggle' ),
							$this->setting( 'load_terms', __( 'Load terms', 'tk-fields' ), __( 'WARNING: when no value is stored, the field reads the post\'s current terms. Can overwrite values set by import scripts or other plugins.', 'tk-fields' ), 'toggle' ),
						),
					);
					break;

				case 'user':
					$types['user'] = array(
						'label'       => __( 'User', 'tk-fields' ),
						'description' => __( 'User reference, stored as a user ID.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'reviewer' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'A user must be picked.', 'tk-fields' ), 'toggle' ),
							$this->setting( 'roles', __( 'Roles', 'tk-fields' ), __( 'Limit selection to users with these roles. Leave empty to allow all roles.', 'tk-fields' ), 'multicheck', $this->role_options() ),
							$this->setting( 'multiple', __( 'Multiple', 'tk-fields' ), __( 'Allow selecting multiple users. Stored as an ordered ID array.', 'tk-fields' ), 'toggle' ),
						),
					);
					break;

				case 'relationship':
					$types['relationship'] = array(
						'label'       => __( 'Relationship', 'tk-fields' ),
						'description' => __( 'Ordered post picker with search. Stores an ordered post-ID array.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'related_posts' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'At least one post must be picked.', 'tk-fields' ), 'toggle' ),
							$this->setting( 'post_types', __( 'Post types', 'tk-fields' ), __( 'Limit selection to these post types. Leave empty to allow all public types.', 'tk-fields' ), 'multicheck', $this->post_type_options() ),
							$this->setting( 'filter_taxonomy', __( 'Filter by taxonomy', 'tk-fields' ), __( 'Only offer posts in a term of this taxonomy.', 'tk-fields' ), 'select', array( '' => __( 'No filter', 'tk-fields' ) ) + $this->taxonomy_options() ),
							$this->setting( 'filter_term', __( 'Filter term', 'tk-fields' ), __( 'Term slug to filter by, e.g. news', 'tk-fields' ), 'text' ),
							$this->setting( 'min', __( 'Minimum', 'tk-fields' ), __( 'Fewest posts that may be selected.', 'tk-fields' ), 'number' ),
							$this->setting( 'max', __( 'Maximum', 'tk-fields' ), __( 'Most posts that may be selected.', 'tk-fields' ), 'number' ),
						),
					);
					break;

				case 'gallery':
					$types['gallery'] = array(
						'label'       => __( 'Gallery', 'tk-fields' ),
						'description' => __( 'Ordered image/file collection via the media library frame. Stores attachment IDs.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'photo_gallery' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'At least one file must be chosen.', 'tk-fields' ), 'toggle' ),
							$this->setting( 'mime_types', __( 'Mime types', 'tk-fields' ), __( 'Comma-separated, e.g. image or image,video', 'tk-fields' ), 'text', null, 'image' ),
							$this->setting( 'min', __( 'Minimum', 'tk-fields' ), __( 'Fewest items that may be selected.', 'tk-fields' ), 'number' ),
							$this->setting( 'max', __( 'Maximum', 'tk-fields' ), __( 'Most items that may be selected.', 'tk-fields' ), 'number' ),
							$this->setting(
								'image_size',
								__( 'Image size', 'tk-fields' ),
								__( 'Image size returned by formatted reads.', 'tk-fields' ),
								'select',
								array(
									'thumbnail' => __( 'Thumbnail', 'tk-fields' ),
									'medium'    => __( 'Medium', 'tk-fields' ),
									'large'     => __( 'Large', 'tk-fields' ),
									'full'      => __( 'Full', 'tk-fields' ),
								)
							),
						),
					);
					break;

				case 'wysiwyg':
					$types['wysiwyg'] = array(
						'label'       => __( 'WYSIWYG', 'tk-fields' ),
						'description' => __( 'Rich-text editor (WordPress visual editor). Stores HTML; every save is filtered through wp_kses_post for all roles unless raw HTML is explicitly allowed for the field.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'article_body' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'Content must be entered.', 'tk-fields' ), 'toggle' ),
							$this->setting( 'default', __( 'Default value', 'tk-fields' ), __( 'HTML shown when nothing is stored (still "unset").', 'tk-fields' ), 'textarea' ),
							$this->setting(
								'toolbar',
								__( 'Toolbar', 'tk-fields' ),
								__( 'Editor toolbar preset. Full is pinned to the WordPress default toolbar set; Basic is a reduced single row; None is a plain textarea.', 'tk-fields' ),
								'select',
								array(
									'basic' => __( 'Basic', 'tk-fields' ),
									'full'  => __( 'Full', 'tk-fields' ),
									'none'  => __( 'None (plain textarea)', 'tk-fields' ),
								)
							),
							$this->setting( 'allow_unfiltered', __( 'Allow unfiltered HTML', 'tk-fields' ), __( 'WARNING: stores raw, unfiltered HTML on every save. You are accepting stored-XSS responsibility — enable only for fields edited by trusted administrators.', 'tk-fields' ), 'toggle' ),
						),
					);
					break;

				case 'map':
					$types['map'] = array(
						'label'       => __( 'Map', 'tk-fields' ),
						'description' => __( 'Map location picker with address search. Stores one row: {lat, lng, zoom, address} — the address is kept denormalized and never re-geocoded at render time.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'store_location' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'placeholder', __( 'Placeholder', 'tk-fields' ), __( 'Dimmed hint inside an empty address input.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'A location must be entered.', 'tk-fields' ), 'toggle' ),
							$this->setting( 'enable_search', __( 'Enable address search', 'tk-fields' ), __( 'OPT-IN: shows an address search box that queries the Photon geocoder (photon.komoot.io) from the admin’s browser, debounced. Off by default — with it off the field still offers click-to-set on the map plus latitude/longitude/address inputs. Photon is swappable via the tk_fields_geocoder_url filter.', 'tk-fields' ), 'toggle' ),
							$this->setting( 'default_lat', __( 'Default latitude', 'tk-fields' ), __( 'Where the editor map centers when the field has no value yet.', 'tk-fields' ), 'number' ),
							$this->setting( 'default_lng', __( 'Default longitude', 'tk-fields' ), __( 'Where the editor map centers when the field has no value yet.', 'tk-fields' ), 'number' ),
							$this->setting( 'default_zoom', __( 'Default zoom', 'tk-fields' ), __( 'Initial map zoom level for the editor canvas (0–18).', 'tk-fields' ), 'number' ),
						),
					);
					break;

				case 'flexible_content':
					$types['flexible_content'] = array(
						'label'       => __( 'Flexible Content', 'tk-fields' ),
						'description' => __( 'Repeatable layouts: editors add rows, each row using one of the defined layouts. On posts the rows live as blocks in post content; on terms, users and options pages they are stored as serialized block markup in one meta row.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'page_sections' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'At least one layout row must be added.', 'tk-fields' ), 'toggle' ),
							$this->setting( 'button_label', __( 'Add button label', 'tk-fields' ), __( 'Custom label for the add-layout button (supports {layout}, e.g. "Add {layout} block"). Leave empty for the default.', 'tk-fields' ), 'text' ),
							$this->setting( 'layouts', __( 'Layouts', 'tk-fields' ), __( 'Each layout is a named set of sub-fields: editors pick a layout when adding a row, then fill its fields. Per-layout minimum/maximum row counts are enforced on save.', 'tk-fields' ), 'layouts' ),
						),
					);
					break;

				case 'clone':
					$types['clone'] = array(
						'label'       => __( 'Clone', 'tk-fields' ),
						'description' => __( 'Reuse every field from another field group. Children are namespaced automatically as clone_key.child_key — there is no prefix setting, so renaming can never orphan data. The clone itself stores nothing.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor. It prefixes each cloned child label.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores. It namespaces the cloned children (name.child).', 'tk-fields' ), 'text', null, 'address_block' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'When set, overrides every cloned child\u2019s instructions. Leave empty to keep the source instructions.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'Additive: makes every cloned child required, in addition to any source-level required flags.', 'tk-fields' ), 'toggle' ),
							$this->setting( 'clone', __( 'Source group', 'tk-fields' ), __( 'The field group to clone. All of its value-storing fields are copied under this field\u2019s namespace. Referenced by group ID.', 'tk-fields' ), 'select', $this->group_options() ),
						),
					);
					break;

				case 'group':
					$types['group'] = array(
						'label'       => __( 'Group', 'tk-fields' ),
						'description' => __( 'A fieldset of sub-fields shown and edited as one unit. The group itself stores nothing — each sub-field is stored under groupname_subname (ACF-compatible). Sub-fields are validated with their own rules.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown as the fieldset legend in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores. It namespaces the sub-fields (groupname_subname) — renaming it orphans existing values.', 'tk-fields' ), 'text', null, 'contact_details' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the fieldset legend.', 'tk-fields' ), 'text' ),
							$this->setting( 'sub_fields', __( 'Sub-fields', 'tk-fields' ), __( 'Fields inside this group. Each is stored as groupname_subname and validated individually. Nested groups, clones and flexible content are not supported inside a group in v1.', 'tk-fields' ), 'subfields' ),
						),
					);
					break;

				case 'repeater':
					$types['repeater'] = array(
						'label'       => __( 'Repeater', 'tk-fields' ),
						'description' => __( 'Repeatable rows of sub-fields. Editors add, reorder and remove rows; each row holds the same set of sub-fields. Rows live as blocks in post content. Requires the block editor.', 'tk-fields' ),
						'settings'    => array(
							$this->setting( 'label', __( 'Label', 'tk-fields' ), __( 'Shown above the field in the editor.', 'tk-fields' ), 'text' ),
							$this->setting( 'name', __( 'Name', 'tk-fields' ), __( 'Machine name: lowercase letters, digits, underscores.', 'tk-fields' ), 'text', null, 'course_modules' ),
							$this->setting( 'instructions', __( 'Instructions', 'tk-fields' ), __( 'Helper text shown under the label.', 'tk-fields' ), 'text' ),
							$this->setting( 'required', __( 'Required', 'tk-fields' ), __( 'At least one row must be added.', 'tk-fields' ), 'toggle' ),
							$this->setting( 'min', __( 'Minimum rows', 'tk-fields' ), __( 'Fewest rows the editor may leave. Save is blocked below this.', 'tk-fields' ), 'number' ),
							$this->setting( 'max', __( 'Maximum rows', 'tk-fields' ), __( 'Most rows allowed. The add-row button disables at this count.', 'tk-fields' ), 'number' ),
							$this->setting( 'button_label', __( 'Add-row button label', 'tk-fields' ), __( 'Text of the "add row" button. Leave empty for the default "Add Row".', 'tk-fields' ), 'text' ),
							$this->setting(
								'layout',
								__( 'Row layout', 'tk-fields' ),
								__( 'How rows are arranged in the editor: stacked list or grid.', 'tk-fields' ),
								'select',
								array(
									'list' => __( 'List', 'tk-fields' ),
									'grid' => __( 'Grid', 'tk-fields' ),
								)
							),
							// Options are NOT static: the builder derives them
							// live from the field's current sub_fields
							// (name => label); empty = first field, derived
							// presentation state, never stored.
							$this->setting( 'collapsed', __( 'Row summary field', 'tk-fields' ), __( 'Which sub-field\'s value is shown as the collapsed row summary. Summaries are derived live, never stored.', 'tk-fields' ), 'select' ),
							$this->setting( 'sub_fields', __( 'Sub-fields', 'tk-fields' ), __( 'The sub-fields inside every row. Each row holds the same set of sub-fields; add, reorder and remove them here.', 'tk-fields' ), 'subfields' ),
						),
					);
					break;
			}
		}

		// v0.12.0: picker metadata (category, icon, aliases, block-editor-only
		// flag) merged here — the 34 case blocks above stay untouched.
		$picker_meta = self::type_picker_meta();
		foreach ( $types as $type => $def ) {
			$m = $picker_meta[ $type ] ?? array();
			$types[ $type ]['category']          = $m['category'] ?? 'basic';
			$types[ $type ]['icon']              = $m['icon'] ?? 'star-filled';
			$types[ $type ]['aliases']           = $m['aliases'] ?? array();
			$types[ $type ]['block_editor_only'] = ! empty( $m['block_editor_only'] );
		}

		return rest_ensure_response( array( 'types' => $types ) );
	}

	/**
	 * Request payload: JSON body first, form-encoded body as fallback.
	 *
	 * @param \WP_REST_Request $request
	 */
	private function request_payload( \WP_REST_Request $request ): mixed {
		$json = $request->get_json_params();
		if ( is_array( $json ) && array() !== $json ) {
			return $json;
		}

		$body = $request->get_body_params();
		return is_array( $body ) && array() !== $body ? $body : null;
	}

	/**
	 * Build one setting descriptor.
	 *
	 * @param string      $key
	 * @param string      $label
	 * @param string      $tooltip
	 * @param string      $control One of text|textarea|number|toggle|select|choices|multicheck|layouts.
	 * @param array|null  $options For "select"/"multicheck" controls: value => label pairs.
	 * @param string|null $placeholder
	 */
	private function setting( string $key, string $label, string $tooltip, string $control, ?array $options = null, ?string $placeholder = null ): array {
		$setting = array(
			'key'     => $key,
			'label'   => $label,
			'tooltip' => $tooltip,
			'control' => $control,
		);
		if ( null !== $options ) {
			$setting['options'] = $options;
		}
		if ( null !== $placeholder ) {
			$setting['placeholder'] = $placeholder;
		}

		return $setting;
	}

	/**
	 * value => label pairs for every public post type.
	 *
	 * @return array<string,string>
	 */
	private function post_type_options(): array {
		$options = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $slug => $post_type ) {
			$options[ $slug ] = $post_type->label;
		}
		return $options;
	}

	/**
	 * value => label pairs for every public taxonomy.
	 *
	 * @return array<string,string>
	 */
	private function taxonomy_options(): array {
		$options = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $slug => $taxonomy ) {
			$options[ $slug ] = $taxonomy->label;
		}
		return $options;
	}

	/**
	 * value => label pairs for every registered role.
	 *
	 * @return array<string,string>
	 */
	private function role_options(): array {
		return wp_roles()->get_names();
	}

	/**
	 * value => label pairs for every field group: the clone source picker.
	 * Keys are group IDs (the clone setting stores the numeric group ID).
	 *
	 * @return array<string,string>
	 */
	private function group_options(): array {
		$options = array( '' => __( 'Select a group…', 'tk-fields' ) );
		foreach ( Group_Store::instance()->all() as $group ) {
			$id            = (int) ( $group['id'] ?? 0 );
			$title         = isset( $group['title'] ) ? (string) $group['title'] : '';
			/* translators: %d: group ID */
			$options[ (string) $id ] = '' !== $title ? sprintf( '%s (#%d)', $title, $id ) : sprintf( __( 'Group #%1$d', 'tk-fields' ), $id );
		}

		return $options;
	}

	/**
	 * Filter a settings list down by removing keys.
	 *
	 * @param array    $settings
	 * @param string[] $remove Setting keys to drop.
	 */
	private function without_keys( array $settings, array $remove ): array {
		return array_values(
			array_filter(
				$settings,
				static fn( array $s ): bool => ! in_array( $s['key'], $remove, true )
			)
		);
	}

	/**
	 * Group summary for the list endpoint.
	 *
	 * @param array $group Full group array.
	 */
	private function summarize( array $group ): array {
		return array(
			'id'          => $group['id'],
			'title'       => $group['title'],
			'status'      => $group['status'],
			'modified'    => $group['modified'],
			'field_count' => count( $group['fields'] ?? array() ),
		);
	}
}
