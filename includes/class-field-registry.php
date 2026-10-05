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
 * Field definition registry.
 *
 * Holds the logical field definitions (name, label, type, constraints) that
 * every integration consumes. Physical storage details never leak out of here:
 * callers get schema + validated values, never meta keys or table names.
 *
 * Phase 1 ships a filterable seed registry. The visual field-group builder
 * (phase 4) will persist real field groups and replace the seed data.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Field_Registry {

	/**
	 * Field types supported.
	 *
	 * Phase 6 adds the remaining Easy tier: password, message, separator,
	 * tab, range, link. (The Easy tier's "true/false" is the existing
	 * `checkbox` type: single toggle, '1'/'0' storage, bool typed read.)
	 *
	 * Phase 7 adds the Medium tier: radio, button_group, color, datetime,
	 * time, oembed, icon, file. Note: there is deliberately NO multi-value
	 * checkbox type — `checkbox` stays true/false (ACF-style multi-select
	 * is covered by select with multiple=true).
	 *
	 * Round 3 Batch A adds the relational Hard tier: post_object, page_link,
	 * taxonomy, user, relationship, gallery.
	 *
	 * Round 3 Batch B adds the content Hard tier: wysiwyg, then map (Photon
	 * geocoding, stored denormalized as {lat,lng,zoom,address}).
	 *
	 * v0.11.0 adds the group container type: a fieldset wrapper with inline
	 * sub-fields, closing the last ACF-free parity gap. The group key itself
	 * stores nothing; each sub-field is stored under the ACF-compatible
	 * namespaced key {group}_{sub}.
	 *
	 * The repeater type adds ordered, repeatable row sets with inline
	 * sub-fields (nestable to 2 levels). Rows live as tk/repeater-row child
	 * blocks of the field's tk/field-repeater wrapper in post_content (posts)
	 * or as serialized block markup in one meta/option row (terms, users,
	 * options) — see Fields::get_repeater()/update_repeater(). The repeater
	 * key itself stores nothing directly; unlike group it is NOT a
	 * zero-row container (stores('repeater') is true).
	 *
	 * @var string[]
	 */
	private const TYPES = array(
		'text',
		'textarea',
		'number',
		'email',
		'url',
		'checkbox',
		'select',
		'date',
		'image',
		'password',
		'message',
		'separator',
		'tab',
		'range',
		'link',
		'radio',
		'button_group',
		'color',
		'datetime',
		'time',
		'oembed',
		'icon',
		'file',
		'post_object',
		'page_link',
		'taxonomy',
		'user',
		'relationship',
		'gallery',
		'wysiwyg',
		'map',
		'flexible_content',
		'clone',
		'group',
		'repeater',
	);

	/**
	 * Pinned WYSIWYG toolbar presets.
	 *
	 * The `full` preset is an EXPLICIT copy of core wp_editor()'s default
	 * TinyMCE toolbars (WP 7.1, desktop, non-DFW context), hardcoded here so
	 * a core upgrade can never reshuffle the field's toolbar silently. The
	 * classic renderer passes these to wp_editor() via the 'tinymce'
	 * setting, which bypasses the mce_buttons* filters entirely. The
	 * `basic` preset is a curated single-row subset; `none` means no
	 * editor chrome at all (plain textarea) and needs no list.
	 *
	 * @var array<string, array<string, string[]>>
	 */
	public const WYSIWYG_TOOLBARS = array(
		'full'  => array(
			'toolbar1' => array( 'formatselect', 'bold', 'italic', 'bullist', 'numlist', 'blockquote', 'alignleft', 'aligncenter', 'alignright', 'link', 'wp_more', 'spellchecker', 'fullscreen', 'wp_adv' ),
			'toolbar2' => array( 'strikethrough', 'hr', 'forecolor', 'pastetext', 'removeformat', 'charmap', 'outdent', 'indent', 'undo', 'redo', 'wp_help' ),
		),
		'basic' => array(
			'toolbar1' => array( 'bold', 'italic', 'bullist', 'numlist', 'blockquote', 'alignleft', 'aligncenter', 'alignright', 'link', 'unlink', 'undo', 'redo' ),
			'toolbar2' => array(),
		),
	);

	/**
	 * Valid values for the wysiwyg `toolbar` setting.
	 *
	 * @var string[]
	 */
	public const WYSIWYG_TOOLBAR_PRESETS = array( 'basic', 'full', 'none' );

	/**
	 * Layout-only types: pure UI, no value, no storage. Fields::update()
	 * refuses to persist them and Fields::get() always reports them unset.
	 *
	 * NOTE: `group` is deliberately NOT here. A group has valued children —
	 * it is a container, not a layout type. stores('group') is false (the
	 * group key itself persists zero rows) but is_container('group') is true.
	 *
	 * @var string[]
	 */
	private const LAYOUT_TYPES = array(
		'message',
		'separator',
		'tab',
	);

	/**
	 * Whether a field type is a container: the field key itself stores
	 * nothing, but it has valued children addressed under a namespace.
	 */
	public static function is_container( string $type ): bool {
		return 'group' === $type;
	}

	/**
	 * All supported field type slugs. Exposed so the group store, REST
	 * controller and builder UI validate against the same list.
	 *
	 * @return string[]
	 */
	public static function types(): array {
		return self::TYPES;
	}

	/**
	 * Whether a field type stores a value.
	 *
	 * Layout-only types (message, separator, tab) are pure UI: they never
	 * touch storage, so the Fields service skips persistence for them and
	 * integrations must not expect a value.
	 *
	 * @param string $type Field type slug.
	 */
	public static function stores( string $type ): bool {
		// Group is a container: the group key itself stores nothing (zero
		// meta rows), but its children are valued — see is_container().
		return 'group' !== $type && ! in_array( $type, self::LAYOUT_TYPES, true );
	}

	/**
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * @var array<string, array> Field definitions keyed by field name.
	 */
	private array $fields = array();

	/**
	 * @var array<string, array> Persisted group field definitions, keyed by name.
	 *                          Kept separate from the seed so persisted groups
	 *                          take precedence on name collision (see get()).
	 */
	private array $group_fields = array();

	/**
	 * @var bool
	 */
	private bool $seeded = false;

	/**
	 * @var bool
	 */
	private bool $groups_loaded = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Ensure the (filterable) seed registry is loaded.
	 */
	private function seed(): void {
		if ( $this->seeded ) {
			return;
		}

		$this->seeded = true;

		/**
		 * Filter the seed field definitions used before the field-group
		 * builder UI exists. Each definition:
		 *
		 *   name     (string, required) Machine-readable key, lowercase, no spaces.
		 *   label    (string, required) Human-readable label.
		 *   type     (string, required) One of the supported types.
		 *   required (bool)             Whether a value must be present.
		 *   default  (mixed)            Default returned when unset (still "unset").
		 *   choices  (array)            For select: value => label pairs.
		 *
		 * @param array $fields Seed field definitions.
		 */
		$seed = apply_filters(
			'tk_fields_seed_registry',
			array(
				array(
					'name'  => 'site_tagline',
					'label' => 'Site Tagline',
					'type'  => 'text',
				),
				array(
					'name'  => 'hero_price',
					'label' => 'Hero Price',
					'type'  => 'number',
				),
				array(
					'name'  => 'show_banner',
					'label' => 'Show Banner',
					'type'  => 'checkbox',
				),
				array(
					'name'  => 'contact_email',
					'label' => 'Contact Email',
					'type'  => 'email',
				),
				array(
					'name'  => 'launch_date',
					'label' => 'Launch Date',
					'type'  => 'date',
				),
				array(
					'name'    => 'plan_tier',
					'label'   => 'Plan Tier',
					'type'    => 'select',
					'choices' => array(
						'basic'      => 'Basic',
						'pro'        => 'Professional',
						'enterprise' => 'Enterprise',
					),
				),
				array(
					'name'  => 'hero_image',
					'label' => 'Hero Image',
					'type'  => 'image',
				),
			)
		);

		foreach ( $seed as $field ) {
			$this->register( $field );
		}
	}

	/**
	 * Register a field definition. Returns false when the definition is invalid.
	 *
	 * @param array $field Field definition array.
	 */
	public function register( array $field ): bool {
		if ( empty( $field['name'] ) || ! is_string( $field['name'] ) ) {
			return false;
		}

		if ( ! preg_match( '/^[a-z0-9_]+$/', $field['name'] ) ) {
			return false;
		}

		$type = $field['type'] ?? 'text';
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return false;
		}

		if ( 'select' === $type && ( empty( $field['choices'] ) || ! is_array( $field['choices'] ) ) ) {
			return false;
		}

		$this->fields[ $field['name'] ] = array(
			'name'     => $field['name'],
			'label'    => isset( $field['label'] ) && is_string( $field['label'] ) ? $field['label'] : $field['name'],
			'type'     => $type,
			'required' => ! empty( $field['required'] ),
			'default'  => $field['default'] ?? null,
			'choices'  => 'select' === $type ? $field['choices'] : array(),
			// Range bounds; null when unset. Carried so sanitize() can clamp.
			'min'      => self::nullable_number( $field['min'] ?? null ),
			'max'      => self::nullable_number( $field['max'] ?? null ),
			'step'     => self::nullable_number( $field['step'] ?? null ),
			// Message content for the message layout type (HTML allowed,
			// kses-filtered at the group-store boundary).
			'message'  => 'message' === $type && isset( $field['message'] ) ? (string) $field['message'] : '',
			// Admin help text. Carried (not just UI chrome) because
			// resolve_clone_children() lets a clone's instructions
			// override its children's — the override must see it.
			'instructions' => isset( $field['instructions'] ) && is_string( $field['instructions'] ) ? $field['instructions'] : '',
		) + self::relational_settings( $field )
			+ self::content_settings( $field )
			+ self::map_settings( $field )
			+ self::flexible_clone_settings( $field )
			+ self::repeater_settings( $field )
			+ self::group_settings( $field );

		// A newly registered clone expands immediately (seed() routes
		// through here too), so get() sees its children without waiting
		// for a group reload.
		if ( 'clone' === $type ) {
			$this->expand_clone_fields();
		}

		// Same for a newly registered group: its children must be
		// addressable immediately.
		if ( 'group' === $type ) {
			$this->expand_group_fields();
		}

		return true;
	}

	/**
	 * Carry the Round 3 Batch A relational settings on a field definition,
	 * with their defaults. Shared by register() and load_group_fields() so
	 * seed, test, and persisted definitions always expose the same keys —
	 * the builder UI depends on these exact names.
	 *
	 *   post_object:  post_types (string[]) — picker filter; single-value only.
	 *   page_link:    allow_external (bool) — whether external URLs validate.
	 *   taxonomy:     taxonomy (slug), field_type ('checkbox'|'autocomplete'),
	 *                 save_terms / load_terms (bool, both default OFF).
	 *   user:         roles (string[]), multiple (bool, off = single).
	 *   relationship: post_types (string[]), filter_taxonomy / filter_term;
	 *                 min/max (carried above as nullable numbers).
	 *   gallery:      mime_types (string), image_size
	 *                 ('thumbnail'|'medium'|'large'|'full'); min/max above.
	 *
	 * @param array $field Raw field definition.
	 * @return array<string, mixed>
	 */
	private static function relational_settings( array $field ): array {
		return array(
			'post_types'      => array_values( array_filter( array_map( 'sanitize_key', (array) ( $field['post_types'] ?? array() ) ) ) ),
			'allow_external'  => ! empty( $field['allow_external'] ),
			'taxonomy'        => isset( $field['taxonomy'] ) ? sanitize_key( (string) $field['taxonomy'] ) : '',
			'field_type'      => in_array( $field['field_type'] ?? '', array( 'checkbox', 'autocomplete' ), true ) ? $field['field_type'] : 'autocomplete',
			'save_terms'      => ! empty( $field['save_terms'] ),
			'load_terms'      => ! empty( $field['load_terms'] ),
			'roles'           => array_values( array_filter( array_map( 'sanitize_key', (array) ( $field['roles'] ?? array() ) ) ) ),
			'multiple'        => ! empty( $field['multiple'] ),
			'filter_taxonomy' => isset( $field['filter_taxonomy'] ) ? sanitize_key( (string) $field['filter_taxonomy'] ) : '',
			'filter_term'     => isset( $field['filter_term'] ) ? sanitize_text_field( (string) $field['filter_term'] ) : '',
			'mime_types'      => isset( $field['mime_types'] ) && '' !== (string) $field['mime_types'] ? sanitize_text_field( (string) $field['mime_types'] ) : 'image',
			'image_size'      => in_array( $field['image_size'] ?? '', array( 'thumbnail', 'medium', 'large', 'full' ), true ) ? $field['image_size'] : 'large',
		);
	}

	/**
	 * Carry the Round 3 Batch B content settings on a field definition,
	 * with their defaults. Same carry contract as relational_settings():
	 * shared by register() and load_group_fields() so seed, test, and
	 * persisted definitions always expose the same keys — the builder UI
	 * depends on these exact names.
	 *
	 *   wysiwyg: toolbar ('basic'|'full'|'none', default 'full'),
	 *            allow_unfiltered (bool, default false — OFF; enabling it
	 *            means the admin accepts stored-XSS responsibility).
	 *
	 * @param array $field Raw field definition.
	 * @return array<string, mixed>
	 */
	private static function content_settings( array $field ): array {
		$toolbar = $field['toolbar'] ?? 'full';
		if ( ! in_array( $toolbar, self::WYSIWYG_TOOLBAR_PRESETS, true ) ) {
			$toolbar = 'full';
		}

		return array(
			'toolbar'          => $toolbar,
			'allow_unfiltered' => ! empty( $field['allow_unfiltered'] ),
		);
	}

	/**
	 * Carry the Round 3 Batch B map settings on a field definition, with
	 * their defaults. Same carry contract as relational_settings() and
	 * content_settings(): shared by register() and load_group_fields()
	 * so seed, test, and persisted definitions always expose the same
	 * keys — the builder UI depends on these exact names.
	 *
	 *   enable_search (bool, default OFF — opt-in Photon address search
	 *       in the editor; the geocoder must stay swappable, never
	 *       hardcoded — see TK\Fields\Map::geocoder_search_url()).
	 *   default_lat / default_lng (float|null) — the block-editor map
	 *       canvas centers here when the field has no value yet.
	 *   default_zoom (int|null) — initial zoom for the editor canvas.
	 *
	 * @param array $field Raw field definition.
	 * @return array<string, mixed>
	 */
	private static function map_settings( array $field ): array {
		return array(
			'enable_search' => ! empty( $field['enable_search'] ),
			'default_lat'   => self::nullable_float( $field['default_lat'] ?? null ),
			'default_lng'   => self::nullable_float( $field['default_lng'] ?? null ),
			'default_zoom'  => isset( $field['default_zoom'] ) && '' !== $field['default_zoom'] && is_numeric( $field['default_zoom'] )
				? (int) $field['default_zoom']
				: null,
		);
	}

	/**
	 * Coerce a coordinate-ish input to float|null.
	 *
	 * @param mixed $v Raw value.
	 */
	private static function nullable_float( mixed $v ): ?float {
		if ( null === $v || '' === $v || ! is_numeric( $v ) ) {
			return null;
		}

		return (float) $v;
	}

	/**
	 * Carry the Round 3 Batch B flexible_content / clone settings on a
	 * field definition, with their defaults. Same carry contract as
	 * relational_settings(): shared by register() and load_group_fields()
	 * so seed, test, and persisted definitions always expose the same
	 * keys — the builder UI and the block editor depend on these exact
	 * names.
	 *
	 *   flexible_content: layouts (normalized list of
	 *       {key, label, min, max, fields[]} — see normalize_layouts()),
	 *       button_label (string).
	 *   clone: clone (int) — the source GROUP ID. v1 references groups by
	 *       numeric ID only.
	 *
	 * @param array $field Raw field definition.
	 * @return array<string, mixed>
	 */
	private static function flexible_clone_settings( array $field ): array {
		return array(
			'layouts'      => self::normalize_layouts( $field['layouts'] ?? array() ),
			'button_label' => isset( $field['button_label'] ) ? (string) $field['button_label'] : '',
			'clone'        => absint( $field['clone'] ?? 0 ),
		);
	}

	/**
	 * Carry a group field's inline sub-field definitions.
	 *
	 * Only well-formed sub-field arrays survive here; the group store
	 * validates strictly at save time (sanitize_group_sub_fields) and
	 * resolve_group_children() guards again at read time.
	 */
	private static function group_settings( array $field ): array {
		$sub_fields = $field['sub_fields'] ?? array();
		if ( ! is_array( $sub_fields ) ) {
			$sub_fields = array();
		}
		$clean = array();
		foreach ( $sub_fields as $sub ) {
			if ( is_array( $sub ) && isset( $sub['name'] ) && is_string( $sub['name'] ) && '' !== $sub['name'] ) {
				$clean[] = $sub;
			}
		}
		return array( 'sub_fields' => $clean );
	}

	/**
	 * Carry a repeater field's settings on a field definition, with their
	 * defaults. Same carry contract as relational_settings(): shared by
	 * register() and load_group_fields() so seed, test, and persisted
	 * definitions always expose the same keys — the builder UI and the
	 * block editor depend on these exact names. Returns an empty array for
	 * non-repeater types (so it never disturbs group definitions).
	 *
	 *   layout ('list'|'grid', default 'list') — the row presentation.
	 *   collapsed (string, default '') — the sub-field name driving the
	 *       row summary; presentation state only, derived by the editor.
	 *   sub_fields (normalized list) — inline sub-field definitions,
	 *       recursively normalized (nested repeaters to depth 2).
	 *   button_label — carried by flexible_clone_settings() (shared key).
	 *   min/max — carried generically; for repeaters they are ROW counts.
	 *
	 * @param array $field Raw field definition.
	 * @return array<string, mixed>
	 */
	private static function repeater_settings( array $field ): array {
		if ( 'repeater' !== ( $field['type'] ?? '' ) ) {
			return array();
		}

		$layout = $field['layout'] ?? 'list';
		if ( ! in_array( $layout, array( 'list', 'grid' ), true ) ) {
			$layout = 'list';
		}

		return array(
			'layout'     => $layout,
			'collapsed'  => isset( $field['collapsed'] ) ? sanitize_key( (string) $field['collapsed'] ) : '',
			'sub_fields' => self::normalize_repeater_subs( $field['sub_fields'] ?? array(), 1 ),
		);
	}

	/**
	 * Normalize a repeater's inline sub-field definitions (LENIENT pass).
	 *
	 * Mirrors normalize_layouts()' sub-field loop: malformed entries are
	 * dropped, never fatal. The STRICT pass with WP_Error diagnostics lives
	 * in Group_Store::sanitize_repeater_sub_fields() at the persistence
	 * boundary. $depth is the current repeater nesting level (1 = the
	 * top-level repeater's children); nested repeaters recurse with
	 * $depth + 1 and are dropped past the 2-level cap.
	 *
	 * Each sub-field definition carries the full type-specific settings via
	 * relational_settings()/content_settings()/map_settings(), so
	 * sanitize()/validate()/format() treat them exactly like top-level
	 * fields of the same type. Nested repeater subs carry their own
	 * layout/collapsed plus recursively normalized sub_fields; group subs
	 * carry their recursively normalized sub_fields (groups do not add
	 * repeater depth, but a repeater inside a group still counts toward
	 * the cap — the depth is threaded through, never reset).
	 *
	 * @param mixed $subs  Raw sub_fields value.
	 * @param int   $depth Current repeater nesting level.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_repeater_subs( mixed $subs, int $depth ): array {
		if ( ! is_array( $subs ) ) {
			return array();
		}

		$out  = array();
		$seen = array();

		foreach ( $subs as $sub ) {
			if ( ! is_array( $sub ) ) {
				continue;
			}
			$name = isset( $sub['name'] ) ? (string) $sub['name'] : '';
			if ( ! preg_match( '/^[a-z0-9_]+$/', $name ) || isset( $seen[ $name ] ) ) {
				continue;
			}
			$type = (string) ( $sub['type'] ?? 'text' );
			if ( ! in_array( $type, self::TYPES, true ) ) {
				continue;
			}
			// Lenient mirrors of the group store's hard errors: clone and
			// flexible_content are excluded explicitly (never via the depth
			// counter); layout-only types carry no value; groups are
			// allowed; nested repeaters are dropped past the depth cap.
			if ( 'clone' === $type || 'flexible_content' === $type ) {
				continue;
			}
			if ( 'group' !== $type && ! self::stores( $type ) ) {
				continue;
			}
			if ( 'repeater' === $type && $depth >= 2 ) {
				continue;
			}
			$seen[ $name ] = true;

			$def = array(
				'name'         => $name,
				'label'        => isset( $sub['label'] ) && is_string( $sub['label'] ) && '' !== $sub['label'] ? $sub['label'] : $name,
				'type'         => $type,
				'required'     => ! empty( $sub['required'] ),
				'default'      => $sub['default'] ?? null,
				'choices'      => in_array( $type, array( 'select', 'radio', 'button_group' ), true ) && ! empty( $sub['choices'] ) && is_array( $sub['choices'] ) ? $sub['choices'] : array(),
				'min'          => self::nullable_number( $sub['min'] ?? null ),
				'max'          => self::nullable_number( $sub['max'] ?? null ),
				'step'         => self::nullable_number( $sub['step'] ?? null ),
				'instructions' => isset( $sub['instructions'] ) ? (string) $sub['instructions'] : '',
			) + self::relational_settings( $sub )
				+ self::content_settings( $sub )
				+ self::map_settings( $sub );

			if ( 'repeater' === $type ) {
				$sub_layout = $sub['layout'] ?? 'list';
				$def['layout']     = in_array( $sub_layout, array( 'list', 'grid' ), true ) ? $sub_layout : 'list';
				$def['collapsed']  = isset( $sub['collapsed'] ) ? sanitize_key( (string) $sub['collapsed'] ) : '';
				$def['sub_fields'] = self::normalize_repeater_subs( $sub['sub_fields'] ?? array(), $depth + 1 );
			} elseif ( 'group' === $type ) {
				$def['sub_fields'] = self::normalize_repeater_subs( $sub['sub_fields'] ?? array(), $depth );
			}

			// Carry a valid key when the caller supplied one (persistence
			// assigns deterministic keys at save time otherwise).
			if ( isset( $sub['key'] ) && is_string( $sub['key'] ) && preg_match( '/^f_[A-Za-z0-9_]{1,64}$/', $sub['key'] ) ) {
				$def['key'] = $sub['key'];
			}

			$out[] = $def;
		}

		return $out;
	}

	/**
	 * Resolve a repeater field's inline sub-fields into child definitions.
	 *
	 * Unlike group (whose children are namespaced into first-class registry
	 * fields), repeater children live INSIDE row scope: they are keyed by
	 * SHORT sub-field name and consumed by the row pipeline
	 * (sanitize_repeater()/validate_repeater()/format_repeater() and the
	 * block row parser). No name rewriting happens here.
	 *
	 * Read-time fail-closed guards mirror the group store's save-time
	 * rules: clone/flexible_content and layout-only sub-fields are skipped;
	 * nested repeaters and groups are allowed (the depth cap is enforced at
	 * save time by Group_Store::sanitize_repeater_sub_fields()).
	 *
	 * @param array $field Repeater field definition.
	 * @return array<string, array> Child definitions keyed by short sub-field name.
	 */
	public function resolve_repeater_children( array $field ): array {
		$children   = array();
		$sub_fields = $field['sub_fields'] ?? array();
		if ( ! is_array( $sub_fields ) ) {
			return $children;
		}

		foreach ( $sub_fields as $sub ) {
			if ( ! is_array( $sub ) ) {
				continue;
			}
			$stype = (string) ( $sub['type'] ?? 'text' );
			if ( 'clone' === $stype
				|| 'flexible_content' === $stype
				|| ( 'group' !== $stype && ! self::stores( $stype ) )
				|| ! in_array( $stype, self::TYPES, true )
			) {
				continue;
			}
			$sname = (string) ( $sub['name'] ?? '' );
			if ( ! preg_match( '/^[a-z0-9_]+$/', $sname ) || isset( $children[ $sname ] ) ) {
				continue;
			}

			$children[ $sname ] = $sub;
		}

		return $children;
	}

	/**
	 * Normalize a raw layouts list into the canonical shape:
	 * [ {key, label, min, max, fields:[{name,label,type,required,...}]} ].
	 *
	 * This is the LENIENT pass used by register() (filterable seeds,
	 * tests): malformed entries are dropped, never fatal. The STRICT pass
	 * with WP_Error diagnostics lives in Group_Store::sanitize_layouts()
	 * at the persistence boundary.
	 *
	 * Layout sub-field defs carry the full type-specific settings via
	 * relational_settings() (choices, taxonomy, post_types, ...) plus
	 * min/max/step and instructions, so sanitize()/validate()/format()
	 * treat them exactly like top-level fields of the same type.
	 *
	 * @param mixed $layouts Raw layouts value.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_layouts( mixed $layouts ): array {
		if ( ! is_array( $layouts ) ) {
			return array();
		}

		$out  = array();
		$seen = array();

		foreach ( $layouts as $layout ) {
			if ( ! is_array( $layout ) ) {
				continue;
			}
			$key = isset( $layout['key'] ) ? (string) $layout['key'] : '';
			if ( ! preg_match( '/^[a-z0-9_]+$/', $key ) || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$fields      = array();
			$seen_names  = array();
			foreach ( (array) ( $layout['fields'] ?? array() ) as $sub ) {
				if ( ! is_array( $sub ) ) {
					continue;
				}
				$name = isset( $sub['name'] ) ? (string) $sub['name'] : '';
				if ( ! preg_match( '/^[a-z0-9_]+$/', $name ) || isset( $seen_names[ $name ] ) ) {
					continue;
				}
				$type = (string) ( $sub['type'] ?? 'text' );
				if ( ! in_array( $type, self::TYPES, true ) ) {
					continue;
				}
				$seen_names[ $name ] = true;

				$fields[] = array(
					'name'         => $name,
					'label'        => isset( $sub['label'] ) && is_string( $sub['label'] ) && '' !== $sub['label'] ? $sub['label'] : $name,
					'type'         => $type,
					'required'     => ! empty( $sub['required'] ),
					'default'      => $sub['default'] ?? null,
					'choices'      => in_array( $type, array( 'select', 'radio', 'button_group' ), true ) && ! empty( $sub['choices'] ) && is_array( $sub['choices'] ) ? $sub['choices'] : array(),
					'min'          => self::nullable_number( $sub['min'] ?? null ),
					'max'          => self::nullable_number( $sub['max'] ?? null ),
					'step'         => self::nullable_number( $sub['step'] ?? null ),
					'instructions' => isset( $sub['instructions'] ) ? (string) $sub['instructions'] : '',
				) + self::relational_settings( $sub )
					+ self::content_settings( $sub )
					+ self::map_settings( $sub );
			}

			$out[] = array(
				'key'    => $key,
				'label'  => isset( $layout['label'] ) && is_string( $layout['label'] ) && '' !== $layout['label'] ? $layout['label'] : $key,
				'min'    => self::nullable_number( $layout['min'] ?? null ),
				'max'    => self::nullable_number( $layout['max'] ?? null ),
				'fields' => $fields,
			);
		}

		return $out;
	}

	/**
	 * The layouts of a flexible field definition, indexed by layout key.
	 * Delegates to Flexible_Content::index_layouts() (tolerates keyed or
	 * list shapes).
	 *
	 * @param array $field Field definition.
	 * @return array<string, array<string, mixed>>
	 */
	public static function flexible_layouts( array $field ): array {
		return Flexible_Content::index_layouts( $field['layouts'] ?? array() );
	}

	/**
	 * Resolve a clone field's children into full field definitions.
	 *
	 * Each child is keyed by its SHORT source name and carries:
	 * - name: "clone_key.child_key" (auto-namespace; no prefix setting, so
	 *   renames can never orphan data),
	 * - label: the clone's own label PREFIXING each child label,
	 * - required: additive override (clone-site required OR source required),
	 * - instructions: the clone-site override wins when non-empty,
	 * - key, type, and every sanitizer/validator setting: STRICTLY
	 *   inherited from the source field — override attempts on these are
	 *   ignored by construction (no channel exists for them).
	 *
	 * v1 rules: numeric source group ID only; nested clones are skipped
	 * (cycle guard — a clone inside the source group is never expanded);
	 * layout-only types contribute nothing (they store no value).
	 *
	 * @param array $field Clone field definition (needs name, label,
	 *                     required, instructions, clone).
	 * @return array<string, array> Child definitions keyed by short name.
	 */
	public function resolve_clone_children( array $field ): array {
		$source_id = absint( $field['clone'] ?? 0 );
		if ( $source_id <= 0 ) {
			return array();
		}

		$group = Group_Store::instance()->get( $source_id );
		if ( null === $group ) {
			return array();
		}

		$clone_name  = (string) ( $field['name'] ?? '' );
		$clone_label = isset( $field['label'] ) ? (string) $field['label'] : '';
		$prefix      = '' !== $clone_name ? $clone_name . '.' : '';
		$children    = array();

		foreach ( $group['fields'] ?? array() as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}
			$stype = (string) ( $source['type'] ?? 'text' );
			// v1: no nested clones (cycle guard), no layout-only types
			// (nothing to clone), unknown types skipped.
			if ( 'clone' === $stype || ! self::stores( $stype ) || ! in_array( $stype, self::TYPES, true ) ) {
				continue;
			}
			$sname = (string) ( $source['name'] ?? '' );
			if ( ! preg_match( '/^[a-z0-9_]+$/', $sname ) || isset( $children[ $sname ] ) ) {
				continue;
			}

			// Inherit EVERYTHING from the source (type, key, all sanitizer /
			// validator settings); only the four overridable facets change.
			$child               = $source;
			$child['name']       = $prefix . $sname;
			$source_label        = isset( $source['label'] ) && is_string( $source['label'] ) && '' !== $source['label'] ? $source['label'] : $sname;
			$child['label']      = '' !== $clone_label ? $clone_label . ' ' . $source_label : $source_label;
			$child['required']   = ! empty( $field['required'] ) || ! empty( $source['required'] );
			$clone_instructions  = isset( $field['instructions'] ) ? (string) $field['instructions'] : '';
			if ( '' !== $clone_instructions ) {
				$child['instructions'] = $clone_instructions;
			}

			$children[ $sname ] = $child;
		}

		return $children;
	}

	/**
	 * Resolve a group field's inline sub-fields into child definitions.
	 *
	 * Unlike clone (which copies fields from another group), a group's
	 * sub-fields are defined inline on the field itself. Each child is
	 * namespaced with the ACF-compatible underscore convention:
	 * `{group}_{sub}` (e.g. group `contact` + sub `email` -> meta key
	 * `contact_email`), so an ACF group migrated to TK Fields keeps its
	 * meta keys byte-for-byte.
	 *
	 * v1 limits (fail closed, mirroring the layout sanitizer): no nested
	 * containers (group/clone/flexible_content), no layout-only types, and
	 * unknown types are skipped. The group store enforces the same rules at
	 * save time; this is the read-time guard for hand-built definitions.
	 *
	 * @param array $field Group field definition.
	 * @return array<string, array> Children keyed by SHORT sub-field name.
	 */
	public function resolve_group_children( array $field ): array {
		$group_name = (string) ( $field['name'] ?? '' );
		$children   = array();
		if ( '' === $group_name ) {
			return $children;
		}

		$sub_fields = $field['sub_fields'] ?? array();
		if ( ! is_array( $sub_fields ) ) {
			return $children;
		}

		foreach ( $sub_fields as $sub ) {
			if ( ! is_array( $sub ) ) {
				continue;
			}
			$stype = (string) ( $sub['type'] ?? 'text' );
			if ( self::is_container( $stype )
				|| 'clone' === $stype
				|| 'flexible_content' === $stype
				|| ! self::stores( $stype )
				|| ! in_array( $stype, self::TYPES, true )
			) {
				continue;
			}
			$sname = (string) ( $sub['name'] ?? '' );
			if ( ! preg_match( '/^[a-z0-9_]+$/', $sname ) || isset( $children[ $sname ] ) ) {
				continue;
			}

			// Inherit everything from the inline definition; only the name
			// is rewritten into the group namespace. required/instructions
			// stay exactly as the admin set them on the sub-field.
			$child                    = $sub;
			$child['name']            = $group_name . '_' . $sname;
			$child['_tk_group_child'] = true; // Expansion marker (see below).

			$children[ $sname ] = $child;
		}

		return $children;
	}

	/**
	 * Build wp_editor() settings for a wysiwyg field definition.
	 *
	 * The `full` preset is served from the pinned WYSIWYG_TOOLBARS list via
	 * the 'tinymce' setting — core's mce_buttons* filters are bypassed, so
	 * a core upgrade can never reshuffle the field's toolbar silently.
	 * `basic` uses the pinned single-row preset. `none` disables the visual
	 * editor and quicktags both: a plain textarea.
	 *
	 * @param array $field Field definition (toolbar/allow_unfiltered carried).
	 * @return array wp_editor() $settings.
	 */
	public static function wysiwyg_editor_settings( array $field ): array {
		$toolbar = $field['toolbar'] ?? 'full';
		if ( ! in_array( $toolbar, self::WYSIWYG_TOOLBAR_PRESETS, true ) ) {
			$toolbar = 'full';
		}

		if ( 'none' === $toolbar ) {
			return array(
				'tinymce'       => false,
				'quicktags'     => false,
				'media_buttons' => false,
			);
		}

		$preset = self::WYSIWYG_TOOLBARS[ $toolbar ];

		return array(
			'tinymce'       => array(
				'toolbar1' => implode( ',', $preset['toolbar1'] ),
				'toolbar2' => implode( ',', $preset['toolbar2'] ),
			),
			'quicktags'     => true,
			'media_buttons' => true,
		);
	}

	/**
	 * Coerce a min/max/step bound to int|float|null.
	 *
	 * @param mixed $v Raw bound.
	 */
	private static function nullable_number( mixed $v ): int|float|null {
		if ( null === $v || '' === $v || ! is_numeric( $v ) ) {
			return null;
		}
		$num = $v + 0;

		return is_float( $num ) ? $num : (int) $num;
	}

	/**
	 * Get a single field definition, or null when unknown.
	 *
	 * Persisted group fields take precedence over the phase-1 seed on name
	 * collision; the seed remains as fallback demo content.
	 */
	public function get( string $name ): ?array {
		$this->seed();
		$this->load_group_fields();

		return $this->group_fields[ $name ] ?? $this->fields[ $name ] ?? null;
	}

	/**
	 * All registered field definitions, keyed by name.
	 *
	 * Persisted group fields take precedence over the phase-1 seed on name
	 * collision; the seed remains as fallback demo content.
	 *
	 * @return array<string, array>
	 */
	public function all(): array {
		$this->seed();
		$this->load_group_fields();

		return $this->group_fields + $this->fields;
	}

	/**
	 * Load persisted group field definitions from the group store.
	 *
	 * On name collision between groups, the higher-ID (later) group wins —
	 * deterministic, and `get()` still prefers any group field over the seed.
	 */
	private function load_group_fields(): void {
		if ( $this->groups_loaded ) {
			return;
		}
		$this->groups_loaded = true;

		foreach ( Group_Store::instance()->all() as $group ) {
			foreach ( $group['fields'] ?? array() as $field ) {
				$name = $field['name'] ?? '';
				if ( ! is_string( $name ) || ! preg_match( '/^[a-z0-9_]+$/', $name ) ) {
					continue;
				}
				$type = $field['type'] ?? 'text';
				if ( ! in_array( $type, self::TYPES, true ) ) {
					continue;
				}
				// Choice-based types need their vocabulary to validate against.
				if ( in_array( $type, array( 'select', 'radio', 'button_group' ), true ) && ( empty( $field['choices'] ) || ! is_array( $field['choices'] ) ) ) {
					continue;
				}

				$this->group_fields[ $name ] = array(
					'name'     => $name,
					'label'    => isset( $field['label'] ) && is_string( $field['label'] ) ? $field['label'] : $name,
					'type'     => $type,
					'required' => ! empty( $field['required'] ),
					'default'  => $field['default'] ?? null,
					'choices'  => in_array( $type, array( 'select', 'radio', 'button_group' ), true ) ? $field['choices'] : array(),
					'min'      => self::nullable_number( $field['min'] ?? null ),
					'max'      => self::nullable_number( $field['max'] ?? null ),
					'step'     => self::nullable_number( $field['step'] ?? null ),
					'message'  => 'message' === $type && isset( $field['message'] ) ? (string) $field['message'] : '',
					// Admin help text — see register(): clone children
					// inherit/override instructions through this key.
					'instructions' => isset( $field['instructions'] ) && is_string( $field['instructions'] ) ? $field['instructions'] : '',
				) + self::relational_settings( $field )
					+ self::content_settings( $field )
					+ self::map_settings( $field )
					+ self::flexible_clone_settings( $field )
					+ self::repeater_settings( $field )
					+ self::group_settings( $field );
			}
		}

		$this->expand_clone_fields();
		$this->expand_group_fields();
	}

	/**
	 * Clone names already expanded, per definition map ('group_fields' /
	 * 'fields'). Expansion is incremental: register() can add a clone def
	 * at any time, and reset_group_cache() drops the group-side entries so
	 * the next load re-expands against the fresh group definitions.
	 *
	 * @var array<string, array<string, bool>>
	 */
	private array $expanded_clones = array();

	/**
	 * Tracks which group fields have already had their children expanded
	 * into the field maps (mirrors $expanded_clones).
	 *
	 * @var array<string, array<string, bool>>
	 */
	private array $expanded_groups = array();

	/**
	 * Expand clone fields into their resolved child definitions.
	 *
	 * After all groups are loaded (so a clone can reference a group with a
	 * higher ID), every clone field gains one entry per resolved child,
	 * keyed by the namespaced child name ("clone_key.child_key"), in both
	 * the persisted-group map and the seed map. Fields::get()/update() then
	 * address children like any other field. A clone whose source group is
	 * missing or trashed contributes no children (fail closed — the parent
	 * itself still resolves so its own get()/update() can report unset /
	 * refuse cleanly).
	 */
	private function expand_clone_fields(): void {
		foreach ( array( 'group_fields', 'fields' ) as $map ) {
			foreach ( $this->$map as $name => $def ) {
				if ( 'clone' !== ( $def['type'] ?? '' ) ) {
					continue;
				}
				if ( str_contains( (string) $name, '.' ) ) {
					continue; // Already an expanded child.
				}
				if ( isset( $this->expanded_clones[ $map ][ $name ] ) ) {
					continue;
				}
				foreach ( $this->resolve_clone_children( $def ) as $child ) {
					$this->{$map}[ $child['name'] ] = $child;
				}
				$this->expanded_clones[ $map ][ $name ] = true;
			}
		}
	}

	/**
	 * Expand group fields into their resolved child definitions.
	 *
	 * Mirrors expand_clone_fields(): after all groups are loaded, every
	 * group field gains one entry per resolved child, keyed by the
	 * namespaced child name ("{group}_{sub}"), in both the persisted-group
	 * map and the seed map. Fields::get()/update() then address children
	 * like any other field. Expanded children carry the `_tk_group_child`
	 * marker so they are never re-expanded themselves.
	 */
	private function expand_group_fields(): void {
		foreach ( array( 'group_fields', 'fields' ) as $map ) {
			foreach ( $this->$map as $name => $def ) {
				if ( 'group' !== ( $def['type'] ?? '' ) ) {
					continue;
				}
				if ( ! empty( $def['_tk_group_child'] ) ) {
					continue; // Already an expanded child.
				}
				if ( isset( $this->expanded_groups[ $map ][ $name ] ) ) {
					continue;
				}
				foreach ( $this->resolve_group_children( $def ) as $child ) {
					$this->{$map}[ $child['name'] ] = $child;
				}
				$this->expanded_groups[ $map ][ $name ] = true;
			}
		}
	}
	/**
	 * Drop the persisted-group cache so the next get()/all() re-reads the
	 * store. Hooked to group save/delete. (The Fields service memoizes
	 * per-request reads; its own memo is keyed by field name and object, and
	 * the registry hands it the fresh definition after this reset.)
	 */
	public function reset_group_cache(): void {
		$this->groups_loaded = false;
		$this->group_fields  = array();
		unset( $this->expanded_clones['group_fields'] );
		unset( $this->expanded_groups['group_fields'] );
	}

	/**
	 * Get one persisted field group by ID, or null when missing.
	 *
	 * @param int $id Group post ID.
	 */
	public function get_group( int $id ): ?array {
		return Group_Store::instance()->get( $id );
	}

	/**
	 * All persisted field groups (full definitions, ordered by ID ascending).
	 *
	 * @return array<int, array>
	 */
	public function all_groups(): array {
		return Group_Store::instance()->all();
	}

	/**
	 * Persisted groups whose location rules match a post.
	 *
	 * Rule params: post_type | page_template | taxonomy ("taxonomy|term_slug")
	 * | post (post ID), each with == / !=. `location_match: all` is AND across
	 * rules, `any` is OR. A group with zero rules applies nowhere.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array> Matching groups, full definitions.
	 */
	public function groups_for_object( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		$matched = array();
		foreach ( $this->all_groups() as $group ) {
			$items = $group['location'] ?? array();
			if ( empty( $items ) ) {
				continue; // Zero rules: applies nowhere.
			}

			// v0.12.0: location items are flat rules OR rule groups
			// ({rules:[...]}). AND within a group; flat rules and groups are
			// combined per location_match; a zero-rule group matches nothing.
			$use_any = 'any' === ( $group['location_match'] ?? 'all' );
			$result  = ! $use_any; // AND starts true, OR starts false.
			foreach ( $items as $item ) {
				if ( is_array( $item ) && isset( $item['rules'] ) && is_array( $item['rules'] ) ) {
					$hit = null;
					foreach ( $item['rules'] as $sub ) {
						$sub_hit = $this->match_location_rule( $sub, $post );
						$hit     = null === $hit ? $sub_hit : ( $hit && $sub_hit );
					}
					$hit = $hit ?? false;
				} else {
					$hit = $this->match_location_rule( is_array( $item ) ? $item : array(), $post );
				}
				$result = $use_any ? ( $result || $hit ) : ( $result && $hit );
			}

			if ( $result ) {
				$matched[] = $group;
			}
		}

		return $matched;
	}

	/**
	 * Evaluate one location rule against a post.
	 *
	 * @param array    $rule Rule array (param/operator/value).
	 * @param \WP_Post $post Post to test.
	 */
	private function match_location_rule( array $rule, \WP_Post $post ): bool {
		$param = $rule['param'] ?? '';
		$value = (string) ( $rule['value'] ?? '' );

		switch ( $param ) {
			case 'post_type':
				$actual = (string) get_post_type( $post );
				break;
			case 'page_template':
				$actual = get_page_template_slug( $post );
				$actual = is_string( $actual ) && '' !== $actual ? $actual : 'default';
				break;
			case 'post':
				$actual = (string) $post->ID;
				break;
			case 'taxonomy':
				if ( ! preg_match( '/^([a-z0-9_]+)\|([A-Za-z0-9_\-]+)$/', $value, $m ) ) {
					return false;
				}
				$actual = has_term( $m[2], $m[1], $post ) ? '1' : '0';
				$value  = '1';
				break;
			default:
				return false;
		}

		$equal = $actual === $value;

		return '!=' === ( $rule['operator'] ?? '==' ) ? ! $equal : $equal;
	}

	/**
	 * Sanitize a raw value into its canonical stored form.
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Raw input value.
	 */
	public function sanitize( array $field, mixed $value ): mixed {
		switch ( $field['type'] ) {
			case 'text':
			case 'password':
				return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
			case 'textarea':
				return is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
			case 'number':
				return $this->canonical_number( $value );
			case 'range':
				$num = $this->canonical_number( $value );
				if ( ! is_int( $num ) && ! is_float( $num ) ) {
					return $value; // Let validate() reject it.
				}
				return $this->clamp_to_bounds( $field, $num );
			case 'email':
				return is_scalar( $value ) ? sanitize_email( (string) $value ) : '';
			case 'url':
				return is_scalar( $value ) ? esc_url_raw( (string) $value ) : '';
			case 'link':
				return $this->sanitize_link( $value );
			case 'checkbox':
				return $value ? 1 : 0;
			case 'select':
				return is_scalar( $value ) ? (string) $value : '';
			case 'radio':
			case 'button_group':
				// Same storage shape as a single select: one scalar choice
				// value. Shared code path on purpose — button_group is a
				// visual variant of radio, not a new storage type.
				return is_scalar( $value ) ? (string) $value : '';
			case 'color':
				return is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
			case 'datetime':
				return $this->canonical_datetime( $value );
			case 'time':
				return $this->canonical_time( $value );
			case 'oembed':
				return is_scalar( $value ) ? esc_url_raw( (string) $value ) : '';
			case 'icon':
				// Dashicons-only in v1 (core set, no bundling). Slug pattern
				// validated below; custom icon sets are a future extension.
				return is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
			case 'file':
				return absint( $value );
			case 'date':
				return is_scalar( $value ) ? preg_replace( '/[^0-9]/', '', (string) $value ) : '';
			case 'image':
				return absint( $value );
			case 'post_object':
				// Strictly single-value (multiple = Relationship's job):
				// a post ID, or 0 when empty.
				return absint( $value );
			case 'page_link':
				return $this->sanitize_page_link( $value );
			case 'taxonomy':
			case 'relationship':
			case 'gallery':
				// Ordered, unique ID lists (taxonomy term IDs, post IDs,
				// attachment IDs). Order is preserved — Relationship's
				// selected list and Gallery's image order are user data.
				return $this->sanitize_id_array( $value );
			case 'user':
				// Single (default) reads an int; multiple=true reads an ID list.
				return ! empty( $field['multiple'] ) ? $this->sanitize_id_array( $value ) : absint( $value );
			case 'map':
				return $this->sanitize_map( $value );
			case 'flexible_content':
				// Rows are canonicalized to {layout, id, fields}. Sub-fields
				// of a KNOWN layout sanitize through the registry exactly
				// like top-level fields of the same type; rows whose layout
				// key is not defined are kept verbatim (passthrough) so
				// validate() — not sanitize() — owns the rejection. That
				// split keeps the editor's in-progress rows loadable while
				// save stays strict.
				return $this->sanitize_flexible( $field, $value );
			case 'repeater':
				// Rows are canonicalized to {id, fields}. Sub-fields sanitize
				// through the registry exactly like top-level fields of the
				// same type (nested repeaters and group leaves recurse).
				// Row order is preserved — order is presentation; the
				// persisted row id is the row's identity.
				return $this->sanitize_repeater( $field, $value );
			case 'wysiwyg':
				// Sanitized on EVERY save, ALL roles (wp_kses_post) — never
				// "on output", so no future render path can forget and leak
				// stored XSS. The per-field allow_unfiltered opt-out (default
				// OFF) stores raw HTML: the admin accepts stored-XSS
				// responsibility for that field.
				if ( ! is_scalar( $value ) ) {
					return '';
				}
				$html = (string) $value;
				return empty( $field['allow_unfiltered'] ) ? wp_kses_post( $html ) : $html;
			case 'message':
			case 'separator':
			case 'tab':
				// Layout-only types store nothing; Fields::update() refuses
				// writes before sanitize() is ever reached. Returned as-is
				// so a direct sanitize() call is a harmless no-op.
				return $value;
			default:
				return $value;
		}
	}

	/**
	 * Canonicalize a numeric input to int|float, mirroring the number type.
	 * Non-numeric input is returned untouched so validate() can reject it.
	 *
	 * @param mixed $value Raw input value.
	 */
	private function canonical_number( mixed $value ): mixed {
		if ( ! is_numeric( $value ) ) {
			return $value;
		}
		$num = $value + 0;

		return is_int( $num ) || ( is_float( $num ) && floor( $num ) === $num ) ? (int) $num : (float) $num;
	}

	/**
	 * Enforce a range field's min/max/step bounds.
	 *
	 * Out-of-bounds values are CLAMPED (not rejected): a slider can only
	 * produce in-bounds values, so clamping keeps stored data consistent
	 * with what the UI could have produced. Step snaps to the nearest
	 * step increment measured from min (or 0 when min is unset).
	 *
	 * @param array    $field Field definition (min/max/step or null).
	 * @param int|float $num  Canonicalized number.
	 */
	private function clamp_to_bounds( array $field, int|float $num ): int|float {
		$min = $field['min'] ?? null;
		$max = $field['max'] ?? null;

		if ( is_int( $min ) || is_float( $min ) ) {
			$num = max( $min, $num );
		}
		if ( is_int( $max ) || is_float( $max ) ) {
			$num = min( $max, $num );
		}

		$step = $field['step'] ?? null;
		if ( ( is_int( $step ) || is_float( $step ) ) && $step > 0 ) {
			$base = ( is_int( $min ) || is_float( $min ) ) ? $min : 0;
			$num  = $base + round( ( $num - $base ) / $step ) * $step;
			// Shed float dust from the snap (e.g. 0.30000000000000004).
			$num = round( $num, 10 );
			$num = ( is_float( $num ) && floor( $num ) === $num ) ? (int) $num : ( is_int( $num ) ? $num : (float) $num );
		}

		return $num;
	}

	/**
	 * Parse a datetime input into canonical 'Y-m-d H:i:s' (ACF parity).
	 *
	 * Accepts the canonical form plus the shapes native HTML date/time
	 * inputs produce ('Y-m-d\TH:i', 'Y-m-d H:i'). Strict: overflows like
	 * month 13 or Feb 30 are rejected via getLastErrors(), not rolled
	 * over. Unparseable input is returned untouched so validate() can
	 * reject it (mirrors the number/range pattern).
	 *
	 * @param mixed $value Raw input value.
	 */
	private function canonical_datetime( mixed $value ): mixed {
		if ( ! is_scalar( $value ) ) {
			return $value;
		}
		$raw = trim( (string) $value );
		if ( '' === $raw ) {
			return '';
		}
		foreach ( array( 'Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i' ) as $format ) {
			// '!' resets all fields so no current-time leaks into the parse.
			$dt = \DateTime::createFromFormat( '!' . $format, $raw );
			if ( $dt instanceof \DateTime ) {
				$errors = \DateTime::getLastErrors();
				if ( empty( $errors['warnings'] ) && empty( $errors['errors'] ) ) {
					return $dt->format( 'Y-m-d H:i:s' );
				}
			}
		}

		return $value; // Let validate() reject it.
	}

	/**
	 * Parse a time input into canonical 'H:i:s'.
	 *
	 * Accepts 'H:i:s' and the 'HH:MM' shape native time inputs produce.
	 * Strict on ranges (23:59:59 max) via getLastErrors().
	 *
	 * @param mixed $value Raw input value.
	 */
	private function canonical_time( mixed $value ): mixed {
		if ( ! is_scalar( $value ) ) {
			return $value;
		}
		$raw = trim( (string) $value );
		if ( '' === $raw ) {
			return '';
		}
		foreach ( array( 'H:i:s', 'H:i' ) as $format ) {
			$dt = \DateTime::createFromFormat( '!' . $format, $raw );
			if ( $dt instanceof \DateTime ) {
				$errors = \DateTime::getLastErrors();
				if ( empty( $errors['warnings'] ) && empty( $errors['errors'] ) ) {
					return $dt->format( 'H:i:s' );
				}
			}
		}

		return $value; // Let validate() reject it.
	}

	/**
	 * Sanitize a link value into its canonical array shape.
	 *
	 * Accepts the full array shape {url, title, target} or a bare URL
	 * string (treated as url-only). Always returns either '' (empty) or a
	 * complete array — never a partial shape.
	 *
	 * @param mixed $value Raw input value.
	 */
	private function sanitize_link( mixed $value ): mixed {
		if ( '' === $value || null === $value ) {
			return '';
		}
		if ( is_scalar( $value ) ) {
			$value = array( 'url' => (string) $value );
		}
		if ( ! is_array( $value ) ) {
			return '';
		}

		$url = isset( $value['url'] ) && is_scalar( $value['url'] )
			? esc_url_raw( (string) $value['url'] )
			: '';
		if ( '' === $url ) {
			// A link without a usable URL is not a link: collapse to empty
			// so the "sanitization destroyed input" check in Fields::update()
			// treats a garbage URL as invalid rather than empty.
			return '';
		}

		$title = isset( $value['title'] ) && is_scalar( $value['title'] )
			? sanitize_text_field( (string) $value['title'] )
			: '';

		// Target whitelist: _blank or _self only, defaulting to _self (ACF parity).
		$target = isset( $value['target'] ) ? (string) $value['target'] : '_self';
		$target = in_array( $target, array( '_blank', '_self' ), true ) ? $target : '_self';

		return array(
			'url'    => $url,
			'title'  => $title,
			'target' => $target,
		);
	}

	/**
	 * Sanitize a page-link value into its canonical discriminated shape.
	 *
	 * One postmeta row, JSON-encoded, with an explicit kind discriminant —
	 * never stringly-typed "is this a number or a URL" parsing:
	 *
	 *   {kind: 'post', id}            — internal post/page/CPT
	 *   {kind: 'term', id, taxonomy}  — taxonomy term archive
	 *   {kind: 'url', value}          — arbitrary URL (external or archive)
	 *
	 * Accepts a bare post ID (int/numeric) or a bare URL string for
	 * convenience, plus the full discriminated array. Always returns either
	 * '' (empty) or a COMPLETE shape — never a partial one (e.g. a post
	 * kind without a positive id collapses to '').
	 *
	 * @param mixed $value Raw input value.
	 */
	private function sanitize_page_link( mixed $value ): mixed {
		if ( '' === $value || null === $value ) {
			return '';
		}

		// Bare post ID: a convenience shorthand for {kind: 'post', id}.
		if ( is_numeric( $value ) ) {
			$id = absint( $value );
			return $id > 0 ? array( 'kind' => 'post', 'id' => $id ) : '';
		}

		// Bare URL string: shorthand for {kind: 'url', value}.
		if ( is_scalar( $value ) ) {
			$url = self::sanitize_page_link_url( (string) $value );
			return '' === $url ? '' : array( 'kind' => 'url', 'value' => $url );
		}

		if ( ! is_array( $value ) ) {
			return '';
		}

		$kind = $value['kind'] ?? '';
		switch ( $kind ) {
			case 'post':
				$id = absint( $value['id'] ?? 0 );
				return $id > 0 ? array( 'kind' => 'post', 'id' => $id ) : '';
			case 'term':
				$id       = absint( $value['id'] ?? 0 );
				$taxonomy = isset( $value['taxonomy'] ) && is_scalar( $value['taxonomy'] )
					? sanitize_key( (string) $value['taxonomy'] )
					: '';
				return ( $id > 0 && '' !== $taxonomy )
					? array( 'kind' => 'term', 'id' => $id, 'taxonomy' => $taxonomy )
					: '';
			case 'url':
				$url = isset( $value['value'] ) && is_scalar( $value['value'] )
					? self::sanitize_page_link_url( (string) $value['value'] )
					: '';
				return '' === $url ? '' : array( 'kind' => 'url', 'value' => $url );
			default:
				// Unknown discriminant: reject, never store a partial shape.
				return '';
		}
	}

	/**
	 * Sanitize one URL for the page_link url kind.
	 *
	 * The input must already carry a scheme BEFORE esc_url_raw() runs:
	 * esc_url_raw() helpfully prepends http:// to scheme-less input, which
	 * would launder garbage ('not a url') into a valid-looking URL. Only
	 * absolute URLs are acceptable here — relative links belong to the
	 * post/term kinds (validate() enforces the scheme as a backstop).
	 *
	 * @param string $raw Raw URL string.
	 * @return string Sanitized URL, or '' when unusable.
	 */
	private static function sanitize_page_link_url( string $raw ): string {
		if ( '' === $raw || ! wp_parse_url( $raw, PHP_URL_SCHEME ) ) {
			return '';
		}

		return esc_url_raw( $raw );
	}

	/**
	 * Sanitize an ordered ID list (taxonomy terms, relationship posts,
	 * gallery attachments, multi-user values).
	 *
	 * A scalar int coerces to a single-element list; non-array, non-numeric
	 * input yields an empty array. IDs are uniquified with first-occurrence
	 * order preserved — list order is user data (selection order, image
	 * order), never sorting fodder.
	 *
	 * @param mixed $value Raw input value.
	 * @return int[]
	 */
	private function sanitize_id_array( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			if ( is_numeric( $value ) && absint( $value ) > 0 ) {
				return array( absint( $value ) );
			}
			return array();
		}

		$ids = array();
		foreach ( $value as $v ) {
			$id = absint( $v );
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Sanitize a map value into its canonical shape:
	 *
	 *   { lat: float|null, lng: float|null, zoom: int|null, address: string }
	 *
	 * One postmeta row (the storage adapter serializes the array). The
	 * address is stored DENORMALIZED: it is whatever the editor typed or
	 * the geocoder returned at save time, and is NEVER reverse-geocoded
	 * or silently overwritten afterwards — render paths must never call
	 * out to a geocoder. Lat/lng are clamped to the valid ranges
	 * (-90..90 / -180..180); address is stripped of tags.
	 *
	 * Empty input ('', null, non-array, or all components empty) collapses
	 * to '' so the required/empty check in validate() treats it as empty.
	 * Otherwise a COMPLETE shape is always returned — never partial.
	 *
	 * @param mixed $value Raw input value.
	 * @return array|string The canonical array, or '' when empty.
	 */
	private function sanitize_map( mixed $value ): array|string {
		if ( '' === $value || null === $value || ! is_array( $value ) ) {
			return '';
		}

		$lat = isset( $value['lat'] ) && is_numeric( $value['lat'] )
			? max( -90.0, min( 90.0, (float) $value['lat'] ) )
			: null;
		$lng = isset( $value['lng'] ) && is_numeric( $value['lng'] )
			? max( -180.0, min( 180.0, (float) $value['lng'] ) )
			: null;
		$zoom = isset( $value['zoom'] ) && is_numeric( $value['zoom'] )
			? (int) round( (float) $value['zoom'] )
			: null;
		$address = isset( $value['address'] ) && is_scalar( $value['address'] )
			? sanitize_text_field( (string) $value['address'] )
			: '';

		if ( null === $lat && null === $lng && '' === $address ) {
			// Nothing of value — not even coordinates. Zoom alone is not
			// a location.
			return '';
		}

		return array(
			'lat'     => $lat,
			'lng'     => $lng,
			'zoom'    => $zoom,
			'address' => $address,
		);
	}

	/**
	 * Sanitize a flexible content value into canonical rows:
	 * [ ['layout' => key, 'id' => row id, 'fields' => [name => value]] ].
	 *
	 * Rows whose layout key is defined have their sub-fields sanitized
	 * through the registry (same rules as top-level fields of that type);
	 * unknown-layout rows are kept verbatim — validate() owns rejection.
	 * Row IDs are preserved when present, else generated (wp_generate_uuid4).
	 *
	 * @param array $field Flexible field definition.
	 * @param mixed $value Raw rows value.
	 * @return array<int, array<string, mixed>>
	 */
	private function sanitize_flexible( array $field, mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$layouts = self::flexible_layouts( $field );
		$rows    = array();

		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$layout_key = isset( $row['layout'] ) ? (string) $row['layout'] : '';
			$layout     = $layouts[ $layout_key ] ?? null;
			$raw_fields = isset( $row['fields'] ) && is_array( $row['fields'] ) ? $row['fields'] : array();
			$id         = isset( $row['id'] ) && is_string( $row['id'] ) && '' !== $row['id']
				? $row['id']
				: wp_generate_uuid4();

			$clean_fields = array();
			if ( null !== $layout ) {
				foreach ( (array) ( $layout['fields'] ?? array() ) as $sub ) {
					if ( ! is_array( $sub ) ) {
						continue;
					}
					$sname = (string) ( $sub['name'] ?? '' );
					if ( '' === $sname ) {
						continue;
					}
					if ( array_key_exists( $sname, $raw_fields ) ) {
						$clean_fields[ $sname ] = $this->sanitize( $sub, $raw_fields[ $sname ] );
					}
				}
			} else {
				// Unknown layout: passthrough. validate() rejects the row.
				foreach ( $raw_fields as $k => $v ) {
					if ( is_string( $k ) ) {
						$clean_fields[ $k ] = $v;
					}
				}
			}

			$rows[] = array(
				'layout' => $layout_key,
				'id'     => $id,
				'fields' => $clean_fields,
			);
		}

		return $rows;
	}

	/**
	 * Validate sanitized flexible rows against the field definition.
	 *
	 * Locked Round 3 semantics, enforced at validate/save (not just UI):
	 * - every row's layout key MUST be a defined layout (unknown → false);
	 * - per-layout min/max row counts (nullable; enforced when set);
	 * - every sub-field validates against its own definition (including
	 *   required sub-fields — a missing required sub-field fails the row).
	 *
	 * @param array $field Flexible field definition.
	 * @param mixed $value Sanitized rows.
	 */
	private function validate_flexible( array $field, mixed $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}

		$layouts = self::flexible_layouts( $field );
		$counts  = array();

		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				return false;
			}
			$layout_key = (string) ( $row['layout'] ?? '' );
			$layout     = $layouts[ $layout_key ] ?? null;
			if ( null === $layout ) {
				return false; // Unknown layout: rejected at save.
			}
			$counts[ $layout_key ] = ( $counts[ $layout_key ] ?? 0 ) + 1;

			$row_fields = isset( $row['fields'] ) && is_array( $row['fields'] ) ? $row['fields'] : array();
			foreach ( (array) ( $layout['fields'] ?? array() ) as $sub ) {
				if ( ! is_array( $sub ) ) {
					continue;
				}
				$sname = (string) ( $sub['name'] ?? '' );
				if ( '' === $sname ) {
					continue;
				}
				$sub_value = array_key_exists( $sname, $row_fields ) ? $row_fields[ $sname ] : null;
				if ( ! $this->validate( $sub, $sub_value ) ) {
					return false;
				}
			}
		}

		foreach ( $layouts as $key => $layout ) {
			$count = $counts[ $key ] ?? 0;
			$min   = $layout['min'] ?? null;
			$max   = $layout['max'] ?? null;
			if ( null !== $min && $count < $min ) {
				return false;
			}
			if ( null !== $max && $count > $max ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Canonicalize a repeater value into ordered rows of {id, fields}.
	 *
	 * Two input shapes are accepted: the canonical row {id, fields} and a
	 * bare assoc row ({name => value, ...}); the latter is for programmatic
	 * updates where row ids are not known. Rows keep their ids when given
	 * and receive a UUID when not — order is presentation, the persisted id
	 * is identity. Sub-fields sanitize through the registry exactly like
	 * top-level fields of the same type (nested repeaters recurse through
	 * sanitize(); group sub-fields sanitize leaf by leaf). Sub-fields not
	 * defined on the field are dropped.
	 *
	 * @param array $field Repeater field definition.
	 * @param mixed $value Raw input value.
	 */
	private function sanitize_repeater( array $field, mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$children = $this->resolve_repeater_children( $field );
		$rows     = array();

		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id         = isset( $row['id'] ) && is_string( $row['id'] ) && '' !== $row['id']
				? $row['id']
				: wp_generate_uuid4();
			$raw_fields = isset( $row['fields'] ) && is_array( $row['fields'] )
				? $row['fields']
				: $this->repeater_bare_row( $row );

			$clean_fields = array();
			foreach ( $children as $sname => $sub ) {
				if ( ! array_key_exists( $sname, $raw_fields ) ) {
					continue;
				}
				$clean_fields[ $sname ] = $this->sanitize_repeater_sub( $sub, $raw_fields[ $sname ] );
			}

			$rows[] = array(
				'id'     => $id,
				'fields' => $clean_fields,
			);
		}

		return $rows;
	}

	/**
	 * Extract the sub-field values from a bare (non-canonical) row.
	 *
	 * 'id' and 'fields' are reserved keys at row level, so a sub-field
	 * named 'id' or 'fields' cannot survive this shape — it is dropped here
	 * rather than silently misread as the row's identity or value map.
	 *
	 * @param array $row Row without a 'fields' key.
	 */
	private function repeater_bare_row( array $row ): array {
		$out = array();
		foreach ( $row as $k => $v ) {
			if ( 'id' === $k || 'fields' === $k ) {
				continue;
			}
			$out[ $k ] = $v;
		}
		return $out;
	}

	/**
	 * Sanitize one repeater sub-field value (recursing into containers).
	 *
	 * Nested repeaters dispatch through sanitize() → sanitize_repeater();
	 * group sub-fields have no sanitize() case (they are never first-class
	 * fields), so their leaves sanitize individually — the same leaf-level
	 * treatment Fields::update_group() gives top-level group children.
	 *
	 * @param array $sub   Sub-field definition.
	 * @param mixed $value Raw sub-field value.
	 */
	private function sanitize_repeater_sub( array $sub, mixed $value ): mixed {
		if ( 'group' === $sub['type'] ) {
			return $this->sanitize_repeater_group( $sub, $value );
		}
		return $this->sanitize( $sub, $value );
	}

	/**
	 * Sanitize a group sub-field inside a repeater row.
	 *
	 * The group value is an assoc keyed by SHORT leaf names (never the
	 * {group}_{sub} namespaced keys — namespacing only applies to
	 * first-class group children with their own meta rows). Each leaf
	 * sanitizes exactly like a top-level field of its type.
	 *
	 * @param array $sub   Group sub-field definition.
	 * @param mixed $value Raw group value.
	 */
	private function sanitize_repeater_group( array $sub, mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) ( $sub['sub_fields'] ?? array() ) as $leaf ) {
			if ( ! is_array( $leaf ) ) {
				continue;
			}
			$lname = (string) ( $leaf['name'] ?? '' );
			if ( '' === $lname || 'group' === ( $leaf['type'] ?? '' ) ) {
				continue; // Groups cannot nest; the store rejects them.
			}
			if ( array_key_exists( $lname, $value ) ) {
				$out[ $lname ] = $this->sanitize( $leaf, $value[ $lname ] );
			}
		}
		return $out;
	}

	/**
	 * Validate sanitized repeater rows against the field definition.
	 *
	 * Locked repeater semantics, enforced at validate/save (not just UI):
	 * - every sub-field validates against its own definition (a missing
	 *   required sub-field fails the row — group leaves are checked leaf
	 *   by leaf, nested repeaters recurse);
	 * - the repeater-level min/max are ROW counts, enforced when set
	 *   (mirrors the flexible split: sanitize() never clamps or drops
	 *   rows, validate() owns the rejection).
	 *
	 * @param array $field Repeater field definition.
	 * @param mixed $value Sanitized rows.
	 */
	private function validate_repeater( array $field, mixed $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}

		$children = $this->resolve_repeater_children( $field );

		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				return false;
			}
			$row_fields = isset( $row['fields'] ) && is_array( $row['fields'] )
				? $row['fields']
				: $this->repeater_bare_row( $row );
			foreach ( $children as $sname => $sub ) {
				$sub_value = array_key_exists( $sname, $row_fields ) ? $row_fields[ $sname ] : null;
				if ( ! $this->validate_repeater_sub( $sub, $sub_value ) ) {
					return false;
				}
			}
		}

		$count = count( $value );
		$min   = self::nullable_number( $field['min'] ?? null );
		$max   = self::nullable_number( $field['max'] ?? null );
		if ( null !== $min && $count < $min ) {
			return false;
		}
		if ( null !== $max && $count > $max ) {
			return false;
		}

		return true;
	}

	/**
	 * Validate one repeater sub-field value (recursing into containers).
	 *
	 * @param array $sub   Sub-field definition.
	 * @param mixed $value Sanitized sub-field value (null when absent).
	 */
	private function validate_repeater_sub( array $sub, mixed $value ): bool {
		if ( 'group' === $sub['type'] ) {
			return $this->validate_repeater_group( $sub, $value );
		}
		return $this->validate( $sub, $value );
	}

	/**
	 * Validate a group sub-field inside a repeater row, leaf by leaf.
	 *
	 * A missing (null) group value validates each leaf against null —
	 * exactly what validate() does for a missing required sub-field, so
	 * required group leaves fail a row whose group is absent.
	 *
	 * @param array $sub   Group sub-field definition.
	 * @param mixed $value Sanitized group value.
	 */
	private function validate_repeater_group( array $sub, mixed $value ): bool {
		$assoc = is_array( $value ) ? $value : array();
		foreach ( (array) ( $sub['sub_fields'] ?? array() ) as $leaf ) {
			if ( ! is_array( $leaf ) ) {
				continue;
			}
			$lname = (string) ( $leaf['name'] ?? '' );
			if ( '' === $lname || 'group' === ( $leaf['type'] ?? '' ) ) {
				continue;
			}
			$leaf_value = array_key_exists( $lname, $assoc ) ? $assoc[ $lname ] : null;
			if ( ! $this->validate( $leaf, $leaf_value ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Validate a sanitized value against the field definition.
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Sanitized value.
	 */
	public function validate( array $field, mixed $value ): bool {
		// Empty-but-set values are only a problem when the field is
		// required. The array() arm is safe: only the ID-list relational
		// types produce arrays, and page_link never yields an empty array
		// (its empty state is ''), so this cannot mask a destroyed link.
		$is_empty = Unset_Value::is_unset( $value ) || '' === $value || null === $value || array() === $value;
		if ( $is_empty ) {
			return empty( $field['required'] );
		}

		switch ( $field['type'] ) {
			case 'number':
			case 'range':
				return is_int( $value ) || is_float( $value );
			case 'email':
				return (bool) is_email( (string) $value );
			case 'url':
				return (bool) wp_parse_url( (string) $value, PHP_URL_SCHEME );
			case 'link':
				return is_array( $value )
					&& isset( $value['url'] )
					&& (bool) wp_parse_url( (string) $value['url'], PHP_URL_SCHEME );
			case 'select':
				return array_key_exists( (string) $value, $field['choices'] );
			case 'radio':
			case 'button_group':
				// Same validation as select: the value must be one of the
				// defined choices. Shared on purpose (see sanitize()).
				return array_key_exists( (string) $value, $field['choices'] ?? array() );
			case 'color':
				return (bool) preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/', (string) $value );
			case 'datetime':
				if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', (string) $value, $m ) ) {
					return false;
				}
				if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
					return false;
				}
				return (int) $m[4] <= 23 && (int) $m[5] <= 59 && (int) $m[6] <= 59;
			case 'time':
				if ( ! preg_match( '/^(\d{2}):(\d{2}):(\d{2})$/', (string) $value, $m ) ) {
					return false;
				}
				return (int) $m[1] <= 23 && (int) $m[2] <= 59 && (int) $m[3] <= 59;
			case 'oembed':
				return (bool) wp_parse_url( (string) $value, PHP_URL_SCHEME );
			case 'icon':
				return (bool) preg_match( '/^[a-z0-9-]+$/', (string) $value );
			case 'file':
				return $value > 0 && 'attachment' === get_post_type( $value );
			case 'date':
				return (bool) preg_match( '/^\d{8}$/', (string) $value );
			case 'image':
				return $value > 0 && 'attachment' === get_post_type( $value );
			case 'post_object':
				// Existence only: the post_types setting is a picker filter
				// (UI concern), never enforced here.
				if ( ! $value ) {
					return ! $field['required'];
				}
				return null !== get_post( $value );
			case 'page_link':
				return $this->validate_page_link( $field, $value );
			case 'taxonomy':
				if ( ! is_array( $value ) ) {
					return false;
				}
				$taxonomy = $field['taxonomy'] ?? '';
				foreach ( $value as $id ) {
					$term = get_term( absint( $id ) );
					if ( ! $term instanceof \WP_Term ) {
						return false;
					}
					// When the field is bound to one taxonomy, every term
					// must belong to it.
					if ( '' !== $taxonomy && $term->taxonomy !== $taxonomy ) {
						return false;
					}
				}
				return true;
			case 'user':
				if ( ! empty( $field['multiple'] ) ) {
					if ( ! is_array( $value ) ) {
						return false;
					}
					foreach ( $value as $id ) {
						if ( ! $this->validate_user_id( $field, absint( $id ) ) ) {
							return false;
						}
					}
					return true;
				}
				if ( ! $value ) {
					return ! $field['required'];
				}
				return $this->validate_user_id( $field, absint( $value ) );
			case 'wysiwyg':
				// sanitize() always normalizes to a string ('' for non-scalar
				// input); the kses/raw decision lives there, keyed on
				// allow_unfiltered. A non-empty input fully destroyed by
				// kses is rejected by Fields::update()'s emptied-input check.
				return is_string( $value );
			case 'map':
				return $this->validate_map( $field, $value );
			case 'flexible_content':
				// Per-row layout membership + per-layout min/max + per
				// sub-field validation, enforced at save (not just UI).
				return $this->validate_flexible( $field, $value );
			case 'repeater':
				// Per-sub-field validation + the repeater-level min/max ROW
				// counts, enforced at save (not just UI). A value violation
				// never clamps — sanitize() preserves row data untouched.
				return $this->validate_repeater( $field, $value );
			case 'relationship':
				if ( ! is_array( $value ) ) {
					return false;
				}
				foreach ( $value as $id ) {
					if ( null === get_post( absint( $id ) ) ) {
						return false;
					}
				}
				return $this->validate_id_count( $field, count( $value ) );
			case 'gallery':
				if ( ! is_array( $value ) ) {
					return false;
				}
				foreach ( $value as $id ) {
					if ( 'attachment' !== get_post_type( absint( $id ) ) ) {
						return false;
					}
				}
				return $this->validate_id_count( $field, count( $value ) );
			case 'message':
			case 'separator':
			case 'tab':
				// Layout-only types carry no value; nothing to validate.
				return true;
			default:
				return true;
		}
	}

	/**
	 * Validate a sanitized page-link value against the field definition.
	 *
	 * kind 'post': the post must exist. kind 'term': the term must exist.
	 * kind 'url': must carry a scheme AND the field must allow external
	 * URLs — internal-only fields reject bare URL kinds outright.
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Sanitized value.
	 */
	private function validate_page_link( array $field, mixed $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}

		switch ( $value['kind'] ?? '' ) {
			case 'post':
				return null !== get_post( absint( $value['id'] ?? 0 ) );
			case 'term':
				return get_term( absint( $value['id'] ?? 0 ) ) instanceof \WP_Term;
			case 'url':
				return (bool) wp_parse_url( (string) ( $value['value'] ?? '' ), PHP_URL_SCHEME )
					&& ! empty( $field['allow_external'] );
			default:
				return false;
		}
	}

	/**
	 * Validate a sanitized map value against the field definition.
	 *
	 * sanitize() guarantees the canonical shape (floats/nulls/string), so
	 * in practice this is a backstop: lat/lng/zoom must be numeric when
	 * present, and address must be a string. The empty states ('', null)
	 * never reach here — validate()'s required/empty check handles them.
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Sanitized value.
	 */
	private function validate_map( array $field, mixed $value ): bool {
		unset( $field );
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( array( 'lat', 'lng', 'zoom' ) as $key ) {
			if ( array_key_exists( $key, $value ) && null !== $value[ $key ] && ! is_numeric( $value[ $key ] ) ) {
				return false;
			}
		}
		return ! array_key_exists( 'address', $value ) || is_string( $value['address'] );
	}

	/**
	 * Validate one user ID: the user must exist, and when the field
	 * restricts roles the user must hold at least one of them.
	 *
	 * @param array $field Field definition.
	 * @param int   $id    User ID.
	 */
	private function validate_user_id( array $field, int $id ): bool {
		$user = get_userdata( $id );
		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		$roles = $field['roles'] ?? array();

		return empty( $roles ) || (bool) array_intersect( $user->roles, $roles );
	}

	/**
	 * Enforce relationship/gallery min/max selection counts. Null bounds
	 * are unset (no limit on that side).
	 *
	 * @param array $field Field definition (min/max or null).
	 * @param int   $count Number of selected IDs.
	 */
	private function validate_id_count( array $field, int $count ): bool {
		$min = $field['min'] ?? null;
		$max = $field['max'] ?? null;

		return ( null === $min || $count >= $min )
			&& ( null === $max || $count <= $max );
	}

	/**
	 * Render oEmbed HTML for a URL through WP_Embed::shortcode().
	 *
	 * Deliberately NOT wp_oembed_get(): that function performs the
	 * provider HTTP fetch on every call and never reads or writes any
	 * cache. The shortcode path uses core's _oembed_* postmeta cache
	 * (TTL via the oembed_ttl filter; failures cached as a plain link),
	 * so repeat renders are free and a cold fetch only ever happens in
	 * a save/admin context (see Fields::update()'s cache warming).
	 *
	 * @param string   $url     The URL to embed.
	 * @param int|null $post_id Post ID to scope the cache to, or null.
	 * @return string Embed HTML, or a plain-link fallback when no provider matches.
	 */
	public static function oembed_html( string $url, ?int $post_id = null ): string {
		global $wp_embed;

		if ( ! $wp_embed instanceof \WP_Embed ) {
			// Should never happen ($wp_embed is set in wp-settings.php);
			// degrade to a plain link rather than fataling.
			return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $url ) );
		}

		$prev_post_id = $wp_embed->post_ID ?? null;
		if ( null !== $post_id ) {
			$wp_embed->post_ID = $post_id;
		}
		$html = $wp_embed->shortcode( array(), $url );
		$wp_embed->post_ID = $prev_post_id;

		// shortcode() always returns a string: embed HTML, or
		// maybe_make_link()'s plain-link fallback when unknown.
		return (string) $html;
	}

	/**
	 * Format a stored value for display (format=true in the API).
	 *
	 * @param array    $field   Field definition.
	 * @param mixed    $value   Stored value.
	 * @param int|null $post_id Post ID, used by the oembed type to scope
	 *                          core's _oembed_* cache. Null falls back to
	 *                          the global post / oembed_cache CPT path.
	 */
	/**
	 * Format sanitized flexible rows for the typed read.
	 *
	 * @param array    $field   Flexible field definition.
	 * @param mixed    $value   Sanitized rows.
	 * @param int|null $post_id Post context for sub-field formatters.
	 * @return array<int, array<string, mixed>>
	 */
	private function format_flexible( array $field, mixed $value, ?int $post_id = null ): array {
		$layouts = self::flexible_layouts( $field );
		$out     = array();

		foreach ( (array) $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$layout_key = (string) ( $row['layout'] ?? '' );
			$layout     = $layouts[ $layout_key ] ?? null;
			$fields     = array();

			foreach ( (array) ( $row['fields'] ?? array() ) as $name => $sub_value ) {
				if ( ! is_string( $name ) ) {
					continue;
				}
				$sub = $layout ? Flexible_Content::layout_subfield( $layout, $name ) : null;
				$fields[ $name ] = null !== $sub ? $this->format( $sub, $sub_value, $post_id ) : $sub_value;
			}

			$out[] = array(
				'layout'       => $layout_key,
				'id'           => (string) ( $row['id'] ?? '' ),
				'layout_label' => null !== $layout ? (string) ( $layout['label'] ?? $layout_key ) : '',
				'fields'       => $fields,
			);
		}

		return $out;
	}

	/**
	 * Format sanitized repeater rows into the public typed read shape.
	 *
	 * list<array<string, mixed>>: each row is its sub-field values keyed by
	 * SHORT sub-field name — no row ids, no block markup in the public
	 * value namespace. Each sub-field value is formatted exactly as that
	 * sub-field type's own format() (image sub-fields become the identical
	 * Image DTO, checkbox sub-fields become bools, nested repeaters
	 * recurse to the same flat shape). Values for unknown sub-fields
	 * (possible after a group edit removed the definition) pass through
	 * raw rather than vanishing.
	 *
	 * @param array   $field   Repeater field definition.
	 * @param mixed   $value   Sanitized rows.
	 * @param ?int    $post_id Post context for resolvers that need it.
	 */
	private function format_repeater( array $field, mixed $value, ?int $post_id = null ): array {
		$children = $this->resolve_repeater_children( $field );
		$out      = array();

		foreach ( (array) $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row_fields = isset( $row['fields'] ) && is_array( $row['fields'] )
				? $row['fields']
				: $this->repeater_bare_row( $row );

			$fields = array();
			foreach ( $row_fields as $name => $sub_value ) {
				if ( ! is_string( $name ) ) {
					continue;
				}
				$sub = $children[ $name ] ?? null;
				$fields[ $name ] = null !== $sub ? $this->format_repeater_sub( $sub, $sub_value, $post_id ) : $sub_value;
			}
			$out[] = $fields;
		}

		return $out;
	}

	/**
	 * Format one repeater sub-field value (recursing into containers).
	 *
	 * @param array $sub     Sub-field definition.
	 * @param mixed $value   Sanitized sub-field value.
	 * @param ?int  $post_id Post context for resolvers that need it.
	 */
	private function format_repeater_sub( array $sub, mixed $value, ?int $post_id = null ): mixed {
		if ( 'group' === $sub['type'] ) {
			// Group leaves are formatted individually and keyed on short
			// leaf names — the same leaf-level treatment format() gives
			// first-class group children via Fields::get_group().
			$assoc = is_array( $value ) ? $value : array();
			$out   = array();
			foreach ( (array) ( $sub['sub_fields'] ?? array() ) as $leaf ) {
				if ( ! is_array( $leaf ) ) {
					continue;
				}
				$lname = (string) ( $leaf['name'] ?? '' );
				if ( '' === $lname || 'group' === ( $leaf['type'] ?? '' ) ) {
					continue;
				}
				$leaf_value    = array_key_exists( $lname, $assoc ) ? $assoc[ $lname ] : null;
				$out[ $lname ] = $this->format( $leaf, $leaf_value, $post_id );
			}
			return $out;
		}
		return $this->format( $sub, $value, $post_id );
	}

	public function format( array $field, mixed $value, ?int $post_id = null ): mixed {
		if ( Unset_Value::is_unset( $value ) ) {
			return $value;
		}

		switch ( $field['type'] ) {
			case 'checkbox':
				return (bool) $value;
			case 'image':
				$id = absint( $value );
				if ( ! $id ) {
					return null;
				}
				return array(
					'id'  => $id,
					'url' => wp_get_attachment_url( $id ),
					'alt' => get_post_meta( $id, '_wp_attachment_image_alt', true ),
				);
			case 'date':
				$timestamp = strtotime( (string) $value );
				return false !== $timestamp ? date_i18n( get_option( 'date_format' ), $timestamp ) : $value;
			case 'link':
				// Typed read: the canonical array shape {url, title, target}.
				return $value;
			case 'oembed':
				// Raw get() returns the URL; formatted get() returns the
				// embed HTML via WP_Embed::shortcode(), so core's _oembed_*
				// postmeta cache (with its oembed_ttl TTL) serves repeat
				// renders — a visitor's page load never triggers the
				// provider HTTP fetch. See oembed_html().
				$url = (string) $value;
				if ( '' === $url ) {
					return $value;
				}
				return self::oembed_html( $url, $post_id );
			case 'icon':
				// Dashicons span. The slug is escaped at build time; the
				// frontend must have dashicons CSS (wp_enqueue_style(
				// 'dashicons' )) — core loads it in wp-admin automatically.
				$slug = (string) $value;
				if ( '' === $slug ) {
					return $value;
				}
				return sprintf( '<span class="dashicons dashicons-%s"></span>', esc_attr( $slug ) );
			case 'file':
				$id = absint( $value );
				if ( ! $id ) {
					return null;
				}
				return (string) wp_get_attachment_url( $id );
			case 'datetime':
				$timestamp = strtotime( (string) $value );
				if ( false === $timestamp ) {
					return $value;
				}
				return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
			case 'time':
				$timestamp = strtotime( (string) $value );
				if ( false === $timestamp ) {
					return $value;
				}
				return date_i18n( (string) get_option( 'time_format' ), $timestamp );
			case 'post_object':
				// Single-post DTO, same shape Relationship items use:
				// {id, title, url, post_type}. Null when the post is gone.
				$post = get_post( absint( $value ) );
				if ( ! $post instanceof \WP_Post ) {
					return null;
				}
				return self::post_dto( $post );
			case 'page_link':
				// Typed read (format=false) returns the discriminated array
				// untouched; the formatted read resolves it to a URL string.
				if ( ! is_array( $value ) ) {
					return $value;
				}
				return self::page_link_url( $value );
			case 'taxonomy':
				// DTOs, JSON-safe (never raw WP_Term objects).
				$items = array();
				foreach ( (array) $value as $id ) {
					$term = get_term( absint( $id ) );
					if ( ! $term instanceof \WP_Term ) {
						continue;
					}
					$link = get_term_link( $term );
					if ( is_wp_error( $link ) ) {
						continue;
					}
					$items[] = array(
						'id'       => $term->term_id,
						'name'     => $term->name,
						'slug'     => $term->slug,
						'taxonomy' => $term->taxonomy,
						'url'      => $link,
					);
				}
				return $items;
			case 'user':
				// Single (default): one DTO or null when the user is gone.
				// Multiple: array of DTOs, missing users filtered out.
				// email is gated on list_users — never leak addresses to
				// anonymous renders (trap register #6).
				if ( ! empty( $field['multiple'] ) ) {
					$items = array();
					foreach ( (array) $value as $id ) {
						$dto = self::user_dto( absint( $id ) );
						if ( null !== $dto ) {
							$items[] = $dto;
						}
					}
					return $items;
				}
				return self::user_dto( absint( $value ) );
			case 'relationship':
				// Ordered DTO list, same shape as post_object: {id, title,
				// url, post_type}. Order is the stored selection order.
				$items = array();
				foreach ( (array) $value as $id ) {
					$post = get_post( absint( $id ) );
					if ( $post instanceof \WP_Post ) {
						$items[] = self::post_dto( $post );
					}
				}
				return $items;
			case 'gallery':
				// Shape matches the image type EXACTLY ({id, url, alt}) so
				// templates need no gallery-specific glue. Never persist
				// URLs/alt alongside IDs — attachment properties go stale.
				$size  = $field['image_size'] ?? 'large';
				$items = array();
				foreach ( (array) $value as $id ) {
					$id = absint( $id );
					if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
						continue;
					}
					$url = wp_get_attachment_image_url( $id, $size );
					if ( ! $url ) {
						$url = wp_get_attachment_url( $id );
					}
					if ( ! $url ) {
						continue;
					}
					$items[] = array(
						'id'  => $id,
						'url' => $url,
						'alt' => get_post_meta( $id, '_wp_attachment_image_alt', true ),
					);
				}
				return $items;
			case 'wysiwyg':
				// The HTML string, already sanitized at save time (wp_kses_post
				// on every save, all roles) — or raw by explicit admin opt-in
				// via allow_unfiltered. Returned untouched: re-sanitizing here
				// would strip the raw embeds the opt-out deliberately keeps.
				return (string) $value;
			case 'map':
				// The canonical array shape {lat, lng, zoom, address} is the
				// typed read: fully denormalized, no resolution needed, and
				// NO external calls — zero live external dependency at
				// render is a hard rule (Round 3 map decision). The stored
				// address is returned exactly as saved; never reverse-
				// geocoded or silently overwritten here.
				return $value;
			case 'flexible_content':
				// Ordered rows: {layout, id, layout_label, fields} where each
				// sub-field value is formatted exactly as its type's own
				// formatted read (image sub-fields become DTOs, checkbox
				// sub-fields become bools, ...). Rows of an unknown layout
				// (possible after a group edit removed it) pass their raw
				// fields through rather than vanishing.
				return $this->format_flexible( $field, $value, $post_id );
			case 'repeater':
				// Ordered rows as list<array<string, mixed>>: each row is
				// its sub-field values keyed by SHORT sub-field name, each
				// formatted exactly as its type's own formatted read (image
				// sub-fields become the identical Image DTO, nested
				// repeaters recurse to the same flat shape). No row ids and
				// no block markup in the public value namespace.
				return $this->format_repeater( $field, $value, $post_id );
			case 'group':
				// Children are already formatted by Fields::get_group()
				// (each child read with $format), keyed by short sub-field
				// name. Nothing further to do.
				return $value;
			default:
				return $value;
		}
	}

	/**
	 * Build the single-post DTO shared by post_object and relationship
	 * items: {id, title, url, post_type}.
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function post_dto( \WP_Post $post ): array {
		return array(
			'id'        => $post->ID,
			'title'     => get_the_title( $post ),
			'url'       => get_permalink( $post ),
			'post_type' => $post->post_type,
		);
	}

	/**
	 * Resolve a sanitized page-link value to its URL string.
	 *
	 * post -> get_permalink(); term -> get_term_link() (WP_Error yields '');
	 * url -> the stored value.
	 *
	 * @param array $value Sanitized page-link array.
	 */
	private static function page_link_url( array $value ): string {
		switch ( $value['kind'] ?? '' ) {
			case 'post':
				$url = get_permalink( absint( $value['id'] ?? 0 ) );
				return is_string( $url ) ? $url : '';
			case 'term':
				$link = get_term_link( absint( $value['id'] ?? 0 ) );
				return is_wp_error( $link ) ? '' : (string) $link;
			case 'url':
				return (string) ( $value['value'] ?? '' );
			default:
				return '';
		}
	}

	/**
	 * Build the single-user DTO: {id, display_name, avatar_url,
	 * profile_url}, plus email ONLY when the current user may list users.
	 * Returns null when the user no longer exists.
	 *
	 * @param int $id User ID.
	 */
	private static function user_dto( int $id ): ?array {
		$user = get_userdata( $id );
		if ( ! $user instanceof \WP_User ) {
			return null;
		}

		$dto = array(
			'id'           => $user->ID,
			'display_name' => $user->display_name,
			'avatar_url'   => (string) get_avatar_url( $user->ID ),
			'profile_url'  => get_author_posts_url( $user->ID ),
		);

		// Capability-gated: email addresses must never leak to anonymous
		// front-end renders (Block Bindings, public REST, AI export).
		if ( current_user_can( 'list_users' ) ) {
			$dto['email'] = $user->user_email;
		}

		return $dto;
	}
}
