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
 * Classic-editor field renderer (meta boxes).
 *
 * Renders TK Fields inside the classic editor (the Classic Editor plugin)
 * as one meta box per field group. The block editor handles fields its own
 * way, so these meta boxes are skipped whenever the block editor is the
 * active editor for the post.
 *
 * Per-type input methods are named `render_field_{$type}()`; types without
 * one show a neutral note pointing at the block editor. v1 coverage (Round
 * 3 CQ2): wysiwyg (wp_editor() with the field's pinned toolbar preset),
 * post_object (post picker), page_link (discriminated post/term/url
 * builder), taxonomy (checkbox list, tree for hierarchical), user (select,
 * multi-select when multiple), gallery (wp.media frame), map REDUCED
 * (lat/lng/address inputs, no Leaflet canvas). Saves go through
 * Fields::update() (sanitize + validate per type) and are committed
 * immediately.
 *
 * Composite types (flexible_content, clone) are INTENTIONALLY not rendered
 * here in v1 — deferred to v1.1 per the Round 3 Hard-tier CQ2 decision.
 * Flexible rows live in block markup (the classic meta box has no block
 * parser to host them); clone fans out to namespaced children with no
 * scalar inputs of their own. Both fall through to the "block editor
 * only" note until the v1.1 classic adapter lands.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Classic_Renderer {

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
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue classic-editor assets (media frame + vanilla JS/CSS).
	 *
	 * Only on the post edit screens — the gallery field needs wp.media,
	 * and the page-link field needs its show/hide glue.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script(
			'tk-fields-classic',
			TK_FIELDS_URL . 'assets/classic/classic.js',
			array(),
			TK_FIELDS_VERSION,
			true
		);
		wp_enqueue_style(
			'tk-fields-classic',
			TK_FIELDS_URL . 'assets/classic/classic.css',
			array(),
			TK_FIELDS_VERSION
		);
	}

	/**
	 * Add one meta box per matching field group — classic editor only.
	 *
	 * @param string $post_type Current post type.
	 */
	public function add_meta_boxes( string $post_type ): void {
		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// The block editor has its own field UI: never double-render.
		if ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) {
			return;
		}

		foreach ( Field_Registry::instance()->groups_for_object( $post->ID ) as $group ) {
			if ( empty( $group['fields'] ) || ! is_array( $group['fields'] ) ) {
				continue;
			}
			add_meta_box(
				'tk-fields-group-' . (int) $group['id'],
				isset( $group['title'] ) ? (string) $group['title'] : __( 'Custom Fields', 'tk-fields' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'normal',
				'default',
				array( 'group' => $group )
			);
		}
	}

	/**
	 * Render the meta box for one field group.
	 *
	 * @param \WP_Post $post Post being edited.
	 * @param array    $box  Meta box array (callback args under 'args').
	 */
	public function render_meta_box( \WP_Post $post, array $box ): void {
		$group = $box['args']['group'] ?? array();
		$fields = $group['fields'] ?? array();
		if ( ! is_array( $fields ) ) {
			return;
		}

		wp_nonce_field( 'tk_fields_classic_save', 'tk_fields_classic_nonce' );

		echo '<div class="tk-fields-classic">';
		// v0.12.0: block-editor-only types are collected into ONE collapsed
		// accordion at the end of the meta box (discoverable, not a stack
		// of static notice boxes).
		$deferred = array();
		foreach ( $fields as $field ) {
			$name = $field['name'] ?? '';
			$type = $field['type'] ?? 'text';
			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}

			$label = isset( $field['label'] ) && is_string( $field['label'] ) ? $field['label'] : $name;

			// Group fields render their own fieldset (legend = label), so
			// they skip the generic label wrapper.
			if ( 'group' === (string) $type ) {
				$this->render_field_group( $field, $post->ID, $deferred );
				continue;
			}

			$method = 'render_field_' . (string) $type;
			if ( ! method_exists( $this, $method ) ) {
				$deferred[] = array(
					'label'   => $label,
					'name'    => $name,
					'type'    => (string) $type,
					'context' => '',
				);
				continue;
			}

			echo '<div class="tk-fields-classic__field tk-fields-classic__field--' . esc_attr( (string) $type ) . '">';
			echo '<p class="tk-fields-classic__label"><strong>' . esc_html( $label ) . '</strong></p>';

			$this->$method( $field, $post->ID );

			if ( ! empty( $field['instructions'] ) && is_string( $field['instructions'] ) ) {
				echo '<p class="description">' . esc_html( $field['instructions'] ) . '</p>';
			}
			echo '</div>';
		}
		$this->render_deferred_accordion( $deferred );
		echo '</div>';
	}

	/**
	 * One collapsed accordion listing every block-editor-only field in the
	 * meta box (v0.12.0: replaces the old stack of per-field notice boxes).
	 *
	 * @param array $deferred Entries: label, name, type, context.
	 */
	private function render_deferred_accordion( array $deferred ): void {
		if ( ! $deferred ) {
			return;
		}
		$count = count( $deferred );
		echo '<details class="tk-fields-classic__deferred">';
		echo '<summary class="tk-fields-classic__deferred-summary">';
		echo esc_html(
			sprintf(
				/* translators: %d: number of fields */
				_n(
					'%d field can only be edited in the block editor',
					'%d fields can only be edited in the block editor',
					$count,
					'tk-fields'
				),
				$count
			)
		);
		echo '</summary>';
		echo '<ul class="tk-fields-classic__deferred-list">';
		foreach ( $deferred as $d ) {
			$type_label = self::deferred_type_label( (string) $d['type'] );
			echo '<li><strong>' . esc_html( (string) $d['label'] ) . '</strong>';
			echo ' <span class="tk-fields-classic__deferred-type">' . esc_html( $type_label ) . '</span>';
			echo ' <code>' . esc_html( (string) $d['name'] ) . '</code>';
			if ( '' !== (string) $d['context'] ) {
				echo ' <span class="description">' . esc_html( (string) $d['context'] ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ul>';
		echo '</details>';
	}

	/**
	 * Human label for a deferred (block-editor-only) type slug.
	 */
	private static function deferred_type_label( string $type ): string {
		$labels = array(
			'relationship'     => __( 'Relationship', 'tk-fields' ),
			'flexible_content' => __( 'Flexible Content', 'tk-fields' ),
			'clone'            => __( 'Clone', 'tk-fields' ),
			'group'            => __( 'Group', 'tk-fields' ),
		);
		return $labels[ $type ] ?? $type;
	}

	/**
	 * Render a wysiwyg field with wp_editor().
	 *
	 * The toolbar preset comes from the pinned lists in Field_Registry
	 * (full = explicit copy of core's wp_editor() defaults), so a core
	 * upgrade can never reshuffle it silently. `none` is a plain textarea.
	 *
	 * @param array $field   Field definition.
	 * @param int   $post_id Post being edited.
	 */
	private function render_field_wysiwyg( array $field, int $post_id ): void {
		$value = Fields::get( $field['name'], $post_id, false );
		$value = Unset_Value::is_unset( $value ) ? '' : (string) $value;

		// Editor IDs must be unique per page; field names already are.
		$editor_id = 'tkw_' . $field['name'];

		$settings = Field_Registry::wysiwyg_editor_settings( $field );
		$settings['textarea_name'] = 'tk_fields[' . $field['name'] . ']';
		$settings['editor_height'] = 200;

		wp_editor( $value, $editor_id, $settings );
	}

	/**
	 * Scalar sub-field types the group fieldset renders with a generic
	 * input (no dedicated render_field_* method exists for these in v1 —
	 * the classic renderer only ships dedicated methods for the complex
	 * types). Scoped to groups: top-level fields of these types keep the
	 * existing "block editor only" behavior.
	 */
	private const GROUP_SCALAR_TYPES = array(
		'text',
		'textarea',
		'number',
		'range',
		'email',
		'url',
		'date',
		'datetime',
		'time',
		'color',
		'icon',
		'checkbox',
		'select',
		'radio',
		'button_group',
	);

	/**
	 * Render a group field: a <fieldset> whose sub-fields reuse the
	 * existing per-type render methods, falling back to a generic scalar
	 * input for the scalar types (which have no dedicated classic
	 * renderer in v1).
	 *
	 * Each child definition already carries its namespaced name
	 * ({group}_{sub}), so Fields::get() and the input names work
	 * transparently — the save handler accepts the namespaced keys via
	 * the registry expansion. Sub-field types with no classic rendering
	 * at all (relationship, file, link, oembed in v1) are skipped here,
	 * not dropped: their values are still editable in the block editor.
	 *
	 * @param array $field   Group field definition.
	 * @param int   $post_id Post being edited.
	 * @param array $deferred Collected block-editor-only entries (v0.12.0),
	 *                        by reference — rendered as one accordion.
	 */
	private function render_field_group( array $field, int $post_id, array &$deferred ): void {
		$children = Field_Registry::instance()->resolve_group_children( $field );
		$label    = isset( $field['label'] ) && is_string( $field['label'] ) ? $field['label'] : ( $field['name'] ?? '' );

		echo '<fieldset class="tk-fields-classic__group">';
		echo '<legend class="tk-fields-classic__group-legend"><strong>' . esc_html( (string) $label ) . '</strong></legend>';

		foreach ( $children as $short => $child ) {
			$ctype = (string) ( $child['type'] ?? 'text' );
			$child_label = isset( $child['label'] ) && is_string( $child['label'] ) && '' !== $child['label'] ? $child['label'] : $short;

			$method = 'render_field_' . $ctype;
			if ( method_exists( $this, $method ) ) {
				echo '<div class="tk-fields-classic__subfield tk-fields-classic__subfield--' . esc_attr( $ctype ) . '">';
				echo '<p class="tk-fields-classic__label">' . esc_html( $child_label ) . '</p>';
				$this->$method( $child, $post_id );
			} elseif ( in_array( $ctype, self::GROUP_SCALAR_TYPES, true ) ) {
				echo '<div class="tk-fields-classic__subfield tk-fields-classic__subfield--' . esc_attr( $ctype ) . '">';
				echo '<p class="tk-fields-classic__label">' . esc_html( $child_label ) . '</p>';
				$this->render_group_scalar( $child, $post_id );
			} else {
				// v0.12.0: collected into the single collapsed accordion.
				$deferred[] = array(
					'label'   => $child_label,
					'name'    => (string) ( $child['name'] ?? $short ),
					'type'    => $ctype,
					/* translators: %s: group label */
					'context' => sprintf( __( 'in group “%s”', 'tk-fields' ), (string) $label ),
				);
				continue;
			}

			if ( ! empty( $child['instructions'] ) && is_string( $child['instructions'] ) ) {
				echo '<p class="description">' . esc_html( $child['instructions'] ) . '</p>';
			}
			echo '</div>';
		}

		echo '</fieldset>';
	}

	/**
	 * Generic classic input for a scalar group sub-field.
	 *
	 * Covers the GROUP_SCALAR_TYPES list: plain inputs for text-like
	 * types, textarea, checkbox (with a hidden empty marker so unchecking
	 * clears the value), single select, and radio/button_group. Values
	 * are read through Fields::get() with the child's namespaced name,
	 * exactly as the dedicated renderers do.
	 *
	 * @param array $child   Namespaced child field definition.
	 * @param int   $post_id Post being edited.
	 */
	private function render_group_scalar( array $child, int $post_id ): void {
		$type  = (string) ( $child['type'] ?? 'text' );
		$name  = 'tk_fields[' . $child['name'] . ']';
		$value = Fields::get( $child['name'], $post_id, false );
		$value = Unset_Value::is_unset( $value ) ? '' : $value;

		switch ( $type ) {
			case 'textarea':
				echo '<textarea name="' . esc_attr( $name ) . '" rows="4" class="large-text">' . esc_textarea( (string) $value ) . '</textarea>';
				break;
			case 'checkbox':
				// Hidden empty marker first: an unchecked box submits
				// nothing, so without this clearing the value would be
				// impossible. The sanitizer maps '' to 0.
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="" />';
				echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( ! empty( $value ), true, false ) . ' /> ';
				echo esc_html__( 'Yes', 'tk-fields' ) . '</label>';
				break;
			case 'select':
				echo '<select name="' . esc_attr( $name ) . '">';
				echo '<option value="">' . esc_html__( '&mdash; Select &mdash;', 'tk-fields' ) . '</option>';
				foreach ( (array) ( $child['choices'] ?? array() ) as $opt_value => $opt_label ) {
					echo '<option value="' . esc_attr( (string) $opt_value ) . '"' . selected( (string) $value, (string) $opt_value, false ) . '>' . esc_html( (string) $opt_label ) . '</option>';
				}
				echo '</select>';
				break;
			case 'radio':
			case 'button_group':
				foreach ( (array) ( $child['choices'] ?? array() ) as $opt_value => $opt_label ) {
					echo '<label style="display:block;margin:0.2em 0;"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $opt_value ) . '"' . checked( (string) $value, (string) $opt_value, false ) . ' /> ';
					echo esc_html( (string) $opt_label ) . '</label>';
				}
				break;
			default:
				$input_type = array(
					'number'   => 'number',
					'range'    => 'range',
					'email'    => 'email',
					'url'      => 'url',
					'date'     => 'date',
					'datetime' => 'datetime-local',
					'time'     => 'time',
					'color'    => 'color',
				);
				$itype = $input_type[ $type ] ?? 'text';
				echo '<input type="' . esc_attr( $itype ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" class="regular-text" />';
				break;
		}
	}

	/**
	 * Render a map field: REDUCED UI in v1 (Round 3 CQ2) — address +
	 * lat/lng inputs only, no Leaflet canvas.
	 *
	 * @param array $field   Field definition.
	 * @param int   $post_id Post being edited.
	 */
	private function render_field_map( array $field, int $post_id ): void {
		$value = Fields::get( $field['name'], $post_id, false );
		$value = Unset_Value::is_unset( $value ) ? '' : $value;

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Map::classic_input_html() escapes everything it prints.
		echo Map::classic_input_html( $field, $value, 'tk_fields[' . $field['name'] . ']' );
	}

	/**
	 * Render a post_object field: single post picker.
	 *
	 * Strictly single-value (multiple = Relationship's job). The post_types
	 * setting is a picker filter only. The list is capped for sanity; the
	 * currently-selected post is always included even past the cap.
	 *
	 * @param array $field   Field definition.
	 * @param int   $post_id Post being edited.
	 */
	private function render_field_post_object( array $field, int $post_id ): void {
		$value = Fields::get( $field['name'], $post_id, false );
		$value = Unset_Value::is_unset( $value ) ? 0 : absint( $value );

		$post_types = array();
		if ( ! empty( $field['post_types'] ) && is_array( $field['post_types'] ) ) {
			foreach ( $field['post_types'] as $slug ) {
				$slug = sanitize_key( (string) $slug );
				if ( '' !== $slug && post_type_exists( $slug ) ) {
					$post_types[] = $slug;
				}
			}
		}
		if ( empty( $post_types ) ) {
			$post_types = array_values( get_post_types( array( 'public' => true ) ) );
		}

		$posts = get_posts(
			array(
				'post_type'        => $post_types,
				'posts_per_page'   => 200,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'post_status'      => 'any',
			)
		);
		$ids = array();
		foreach ( $posts as $p ) {
			$ids[] = $p->ID;
		}
		if ( $value > 0 && ! in_array( $value, $ids, true ) ) {
			$selected_post = get_post( $value );
			if ( $selected_post instanceof \WP_Post ) {
				array_unshift( $posts, $selected_post );
			}
		}

		$name = 'tk_fields[' . $field['name'] . ']';
		$req  = ! empty( $field['required'] ) ? ' required aria-required="true"' : '';
		echo '<select name="' . esc_attr( $name ) . '" class="regular-text"' . esc_attr( $req ) . '>';
		echo '<option value="0">' . esc_html__( '— Select —', 'tk-fields' ) . '</option>';
		foreach ( $posts as $p ) {
			$type_obj   = get_post_type_object( $p->post_type );
			$type_label = $type_obj instanceof \WP_Post_Type ? $type_obj->labels->singular_name : $p->post_type;
			printf(
				'<option value="%d"%s>%s (%s)</option>',
				(int) $p->ID,
				selected( $value, (int) $p->ID, false ),
				esc_html( get_the_title( $p ) ),
				esc_html( (string) $type_label )
			);
		}
		echo '</select>';
		if ( count( $posts ) >= 200 ) {
			echo '<p class="description">' . esc_html__( 'Showing the 200 most recent posts. Narrow the post-type filter or use the block editor picker for older content.', 'tk-fields' ) . '</p>';
		}
	}

	/**
	 * Render a page_link field: discriminated link builder.
	 *
	 * Three panes (post / term / url) toggled by the kind select; hidden
	 * panes are disabled by the vanilla JS so they never submit. The value
	 * posts as tk_fields[name][kind|id|taxonomy|value] and sanitize() keeps
	 * the explicit discriminant — never stringly-typed guessing.
	 *
	 * @param array $field   Field definition.
	 * @param int   $post_id Post being edited.
	 */
	private function render_field_page_link( array $field, int $post_id ): void {
		$value = Fields::get( $field['name'], $post_id, false );
		$value = ( ! Unset_Value::is_unset( $value ) && is_array( $value ) ) ? $value : array();

		$kind           = (string) ( $value['kind'] ?? '' );
		$current_id     = absint( $value['id'] ?? 0 );
		$current_tax    = isset( $value['taxonomy'] ) ? sanitize_key( (string) $value['taxonomy'] ) : '';
		$current_url    = (string) ( $value['value'] ?? '' );
		$allow_external = ! empty( $field['allow_external'] );
		$base           = 'tk_fields[' . $field['name'] . ']';
		$wrap_id        = 'tkf-pagelink-' . sanitize_key( $field['name'] );

		$posts = get_posts(
			array(
				'post_type'        => array_values( get_post_types( array( 'public' => true ) ) ),
				'posts_per_page'   => 200,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'post_status'      => 'publish',
			)
		);

		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );

		echo '<div class="tkf-pagelink" id="' . esc_attr( $wrap_id ) . '">';
		echo '<p><select name="' . esc_attr( $base ) . '[kind]" data-tkf-pagelink-kind>';
		echo '<option value="">' . esc_html__( '— No link —', 'tk-fields' ) . '</option>';
		foreach ( array( 'post' => __( 'Post', 'tk-fields' ), 'term' => __( 'Term', 'tk-fields' ) ) as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $kind, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		if ( $allow_external ) {
			echo '<option value="url"' . selected( $kind, 'url', false ) . '>' . esc_html__( 'URL', 'tk-fields' ) . '</option>';
		}
		echo '</select></p>';

		// Post pane.
		echo '<p data-tkf-pagelink-pane="post"' . ( 'post' !== $kind ? ' hidden' : '' ) . '>';
		echo '<select name="' . esc_attr( $base ) . '[id]" class="regular-text"' . ( 'post' !== $kind ? ' disabled' : '' ) . '>';
		echo '<option value="0">' . esc_html__( '— Select post —', 'tk-fields' ) . '</option>';
		foreach ( $posts as $p ) {
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $p->ID,
				selected( 'post' === $kind ? $current_id : 0, (int) $p->ID, false ),
				esc_html( get_the_title( $p ) )
			);
		}
		echo '</select></p>';

		// Term pane: taxonomy select + one term select per taxonomy.
		echo '<div data-tkf-pagelink-pane="term"' . ( 'term' !== $kind ? ' hidden' : '' ) . '>';
		echo '<p><select name="' . esc_attr( $base ) . '[taxonomy]" data-tkf-pagelink-taxonomy class="regular-text"' . ( 'term' !== $kind ? ' disabled' : '' ) . '>';
		echo '<option value="">' . esc_html__( '— Select taxonomy —', 'tk-fields' ) . '</option>';
		foreach ( $taxonomies as $tax_slug => $tax_obj ) {
			echo '<option value="' . esc_attr( $tax_slug ) . '"' . selected( $current_tax, $tax_slug, false ) . '>' . esc_html( $tax_obj->label ) . '</option>';
		}
		echo '</select></p>';
		foreach ( $taxonomies as $tax_slug => $tax_obj ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $tax_slug,
					'hide_empty' => false,
					'number'     => 200,
				)
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			$show = 'term' === $kind && $current_tax === $tax_slug;
			echo '<p data-tkf-pagelink-terms="' . esc_attr( $tax_slug ) . '"' . ( $show ? '' : ' hidden' ) . '>';
			echo '<select name="' . esc_attr( $base ) . '[id]" class="regular-text"' . ( $show ? '' : ' disabled' ) . '>';
			echo '<option value="0">' . esc_html__( '— Select term —', 'tk-fields' ) . '</option>';
			foreach ( $terms as $term ) {
				printf(
					'<option value="%d"%s>%s</option>',
					(int) $term->term_id,
					selected( $show ? $current_id : 0, (int) $term->term_id, false ),
					esc_html( $term->name )
				);
			}
			echo '</select></p>';
		}
		echo '</div>';

		// URL pane.
		if ( $allow_external ) {
			echo '<p data-tkf-pagelink-pane="url"' . ( 'url' !== $kind ? ' hidden' : '' ) . '>';
			echo '<input type="url" name="' . esc_attr( $base ) . '[value]" value="' . esc_attr( $current_url ) . '" class="regular-text code" placeholder="https://"' . ( 'url' !== $kind ? ' disabled' : '' ) . ' />';
			echo '</p>';
		}
		echo '</div>';
	}

	/**
	 * Render a taxonomy field: checkbox list (tree for hierarchical).
	 *
	 * Flat taxonomies get a flat list; hierarchical ones a nested tree —
	 * the same picker split as the block editor. Capped for sanity; IDs
	 * post as tk_fields[name][] (plus a hidden empty marker so clearing
	 * every box actually clears the value). save_terms/load_terms are
	 * honoured centrally by Fields::update()/Fields::get().
	 *
	 * @param array $field   Field definition.
	 * @param int   $post_id Post being edited.
	 */
	private function render_field_taxonomy( array $field, int $post_id ): void {
		$taxonomy = isset( $field['taxonomy'] ) ? sanitize_key( (string) $field['taxonomy'] ) : '';
		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			echo '<p class="description">' . esc_html__( 'No taxonomy selected for this field.', 'tk-fields' ) . '</p>';
			return;
		}

		$value    = Fields::get( $field['name'], $post_id, false );
		$selected = Unset_Value::is_unset( $value ) ? array() : array_map( 'absint', (array) $value );

		$name = 'tk_fields[' . $field['name'] . '][]';
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="" />';
		echo '<ul class="tkf-taxonomy-checklist">';
		$rendered = $this->taxonomy_checklist_items( $taxonomy, 0, $selected, $name, 0, 0 );
		echo '</ul>';
		if ( $rendered >= 200 ) {
			echo '<p class="description">' . esc_html__( 'Showing the first 200 terms. Use the block editor for larger taxonomies.', 'tk-fields' ) . '</p>';
		}
	}

	/**
	 * Recurse one taxonomy level into checkbox list items.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param int    $parent   Parent term ID.
	 * @param int[]  $selected Selected term IDs.
	 * @param string $name     Input name (with []).
	 * @param int    $depth    Current depth (indentation).
	 * @param int    $count    Terms rendered so far (cap).
	 * @return int Updated count.
	 */
	private function taxonomy_checklist_items( string $taxonomy, int $parent, array $selected, string $name, int $depth, int $count ): int {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $parent,
				'hide_empty' => false,
				'number'     => 200,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return $count;
		}
		foreach ( $terms as $term ) {
			if ( $count >= 200 ) {
				break;
			}
			$count++;
			$checked = in_array( (int) $term->term_id, $selected, true ) ? ' checked' : '';
			echo '<li' . ( $depth > 0 ? ' class="tkf-taxonomy-checklist__child"' : '' ) . '>';
			// $checked is a static fragment: ' checked' or ''.
			echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '" value="' . (int) $term->term_id . '"' . $checked . ' /> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo esc_html( $term->name ) . '</label>';
			if ( is_taxonomy_hierarchical( $taxonomy ) ) {
				echo '<ul>';
				$count = $this->taxonomy_checklist_items( $taxonomy, (int) $term->term_id, $selected, $name, $depth + 1, $count );
				echo '</ul>';
			}
			echo '</li>';
		}
		return $count;
	}

	/**
	 * Render a user field: single select, or multi-select when multiple.
	 *
	 * The roles setting filters the list. Multiple uses a multi-select
	 * (user is the one type where multiple is allowed — Relationship is
	 * post-to-post and cannot reference users).
	 *
	 * @param array $field   Field definition.
	 * @param int   $post_id Post being edited.
	 */
	private function render_field_user( array $field, int $post_id ): void {
		$value    = Fields::get( $field['name'], $post_id, false );
		$multiple = ! empty( $field['multiple'] );
		if ( $multiple ) {
			$value = Unset_Value::is_unset( $value ) ? array() : array_map( 'absint', (array) $value );
		} else {
			$value = Unset_Value::is_unset( $value ) ? 0 : absint( $value );
		}

		$roles = array();
		if ( ! empty( $field['roles'] ) && is_array( $field['roles'] ) ) {
			foreach ( $field['roles'] as $role ) {
				$role = sanitize_key( (string) $role );
				if ( '' !== $role ) {
					$roles[] = $role;
				}
			}
		}

		$args = array(
			'number'  => 200,
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'fields'  => array( 'ID', 'display_name', 'user_login' ),
		);
		if ( ! empty( $roles ) ) {
			$args['role__in'] = $roles;
		}
		$users = get_users( $args );

		// The current selection is always listed, even past the cap.
		$need_ids = $multiple ? $value : ( $value > 0 ? array( $value ) : array() );
		$seen     = array();
		foreach ( $users as $u ) {
			$seen[] = (int) $u->ID;
		}
		foreach ( $need_ids as $need_id ) {
			if ( $need_id > 0 && ! in_array( $need_id, $seen, true ) ) {
				$extra = get_userdata( $need_id );
				if ( $extra instanceof \WP_User ) {
					$users[] = $extra;
				}
			}
		}

		$name = 'tk_fields[' . $field['name'] . ']' . ( $multiple ? '[]' : '' );
		if ( $multiple ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="" />';
			echo '<select multiple size="8" name="' . esc_attr( $name ) . '" class="regular-text">';
		} else {
			echo '<select name="' . esc_attr( $name ) . '" class="regular-text">';
			echo '<option value="0">' . esc_html__( '— Select —', 'tk-fields' ) . '</option>';
		}
		foreach ( $users as $u ) {
			$uid     = (int) $u->ID;
			$is_sel  = $multiple ? in_array( $uid, $value, true ) : $uid === $value;
			$label   = $u->display_name ? $u->display_name . ' (' . $u->user_login . ')' : $u->user_login;
			printf(
				'<option value="%d"%s>%s</option>',
				// $uid is (int)-cast above and printed via %d — no raw output.
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$uid,
				// selected() with $echo=false returns the static ' selected="selected"' fragment or '' (WordPress core).
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				selected( $is_sel, true, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		if ( count( $users ) >= 200 ) {
			echo '<p class="description">' . esc_html__( 'Showing the first 200 users.', 'tk-fields' ) . '</p>';
		}
	}

	/**
	 * Render a gallery field: wp.media picker with thumbnail strip.
	 *
	 * The native media frame does the picking (Claude's Round-3 revision);
	 * selection is stored as an ordered attachment-ID list in the hidden
	 * input. min/max/mime are enforced server-side by validate(); the
	 * mime filter is also passed to the frame's library query.
	 *
	 * @param array $field   Field definition.
	 * @param int   $post_id Post being edited.
	 */
	private function render_field_gallery( array $field, int $post_id ): void {
		$value = Fields::get( $field['name'], $post_id, false );
		$ids   = Unset_Value::is_unset( $value ) ? array() : array_map( 'absint', (array) $value );
		$ids   = array_values( array_filter( $ids ) );

		$mime = '';
		if ( ! empty( $field['mime_types'] ) ) {
			$mime = is_array( $field['mime_types'] ) ? implode( ',', $field['mime_types'] ) : (string) $field['mime_types'];
		}

		$wrap_id = 'tkf-gallery-' . sanitize_key( $field['name'] );
		$input_name = 'tk_fields[' . $field['name'] . '][]';
		echo '<div class="tkf-gallery" id="' . esc_attr( $wrap_id ) . '"'
			. ' data-tkf-gallery'
			. ' data-mime="' . esc_attr( $mime ) . '"'
			. ' data-title="' . esc_attr__( 'Select images', 'tk-fields' ) . '">';

		// One hidden input per ID (plus an empty marker so clearing the
		// gallery actually clears the value) — the sanitizer expects an
		// ID list, not a comma-separated string.
		echo '<span data-tkf-gallery-inputs data-name="' . esc_attr( $input_name ) . '">';
		echo '<input type="hidden" name="' . esc_attr( $input_name ) . '" value="" />';
		foreach ( $ids as $id ) {
			echo '<input type="hidden" name="' . esc_attr( $input_name ) . '" value="' . (int) $id . '" />';
		}
		echo '</span>';

		echo '<ul class="tkf-gallery__thumbs" data-tkf-gallery-thumbs>';
		foreach ( $ids as $id ) {
			$img = wp_get_attachment_image( $id, 'thumbnail' );
			if ( '' !== $img ) {
				echo '<li data-tkf-gallery-thumb="' . (int) $id . '">' . $img . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image() escapes.
			}
		}
		echo '</ul>';

		echo '<p class="tkf-gallery__actions">';
		echo '<button type="button" class="button" data-tkf-gallery-select>' . esc_html__( 'Select images', 'tk-fields' ) . '</button> ';
		echo '<button type="button" class="button-link-delete" data-tkf-gallery-clear>' . esc_html__( 'Clear', 'tk-fields' ) . '</button>';
		echo '</p>';

		$min = absint( $field['min'] ?? 0 );
		$max = absint( $field['max'] ?? 0 );
		if ( $min > 0 || $max > 0 ) {
			echo '<p class="description">';
			if ( $min > 0 && $max > 0 ) {
				// $min/$max are absint()'d and printed via %d — no raw output.
				/* translators: %1$d: minimum images, %2$d: maximum images. */
				printf( esc_html__( 'Between %1$d and %2$d images required.', 'tk-fields' ), $min, $max ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( $min > 0 ) {
				// $min is absint()'d and printed via %d — no raw output.
				/* translators: %d: minimum images. */
				printf( esc_html__( 'At least %d image(s) required.', 'tk-fields' ), $min ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				// $max is absint()'d and printed via %d — no raw output.
				/* translators: %d: maximum images. */
				printf( esc_html__( 'At most %d image(s) allowed.', 'tk-fields' ), $max ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</p>';
		}
		echo '</div>';
	}

	/**
	 * Render a message layout field as an admin notice-style paragraph.
	 *
	 * @param array $field   Field definition.
	 * @param int   $post_id Post being edited.
	 */
	private function render_field_message( array $field, int $post_id ): void {
		unset( $post_id );
		$message = $field['message'] ?? '';
		if ( '' !== $message ) {
			echo '<div class="notice notice-info inline"><p>' . wp_kses_post( (string) $message ) . '</p></div>';
		}
	}

	/**
	 * Render a separator layout field as a horizontal rule.
	 *
	 * @param array $field   Field definition.
	 * @param int   $post_id Post being edited.
	 */
	private function render_field_separator( array $field, int $post_id ): void {
		unset( $field, $post_id );
		echo '<hr />';
	}

	/**
	 * Render a tab layout field as a section heading.
	 *
	 * @param array $field   Field definition.
	 * @param int   $post_id Post being edited.
	 */
	private function render_field_tab( array $field, int $post_id ): void {
		unset( $post_id );
		$label = isset( $field['label'] ) && is_string( $field['label'] ) ? $field['label'] : '';
		if ( '' !== $label ) {
			echo '<h3>' . esc_html( $label ) . '</h3>';
		}
	}

	/**
	 * Save the classic meta box values.
	 *
	 * Only fields the renderer knows how to render are accepted — unknown
	 * POST keys are ignored. Group sub-fields (namespaced {group}_{sub}
	 * keys) are accepted when their type is group-renderable: either a
	 * dedicated render_field_* method exists or the type is in
	 * GROUP_SCALAR_TYPES. Values go through Fields::update(), so the
	 * wysiwyg kses policy (wp_kses_post on every save, all roles, unless
	 * allow_unfiltered) applies here exactly as in the block editor.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function save_post( int $post_id, \WP_Post $post ): void {
		unset( $post );

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['tk_fields_classic_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( (string) $_POST['tk_fields_classic_nonce'] ) ), 'tk_fields_classic_save' )
		) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( empty( $_POST['tk_fields'] ) || ! is_array( $_POST['tk_fields'] ) ) {
			return;
		}

		$registry = Field_Registry::instance();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Values are unslashed here and sanitized per field type inside Fields::update() (Field_Registry::sanitize), which also rejects unknown field names and non-renderable types above.
		$data     = wp_unslash( $_POST['tk_fields'] );

		// Types whose classic inputs submit arrays (sub-inputs or ID lists).
		$array_types = array( 'map', 'page_link', 'taxonomy', 'gallery', 'user' );

		foreach ( $data as $name => $value ) {
			if ( ! is_string( $name ) || ! preg_match( '/^[a-z0-9_]+$/', $name ) ) {
				continue;
			}
			$field = $registry->get( $name );
			if ( null === $field ) {
				continue;
			}
			$ftype = (string) ( $field['type'] ?? 'text' );
			$renderable = method_exists( $this, 'render_field_' . $ftype )
				|| ( ! empty( $field['_tk_group_child'] ) && in_array( $ftype, self::GROUP_SCALAR_TYPES, true ) );
			if ( ! $renderable ) {
				continue;
			}
			if ( is_scalar( $value ) || ( is_array( $value ) && in_array( $field['type'], $array_types, true ) ) ) {
				Fields::update( $name, $value, $post_id );
			}
		}

		// Commit immediately — the shutdown flush may never run for a
		// redirecting save_post request.
		Fields::flush();
	}
}
