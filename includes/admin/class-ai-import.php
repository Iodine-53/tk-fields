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
 * AI-assisted field group import (v0.17.0; full-fidelity schema v0.19.0).
 *
 * Closes the AI round trip: "Export AI Context" takes schemas OUT to an
 * AI; this class brings AI-generated group definitions back IN.
 *
 * Flow: the admin copies a template + prompt (generated from the same
 * field-shape definition that validates imports, so the two can never
 * drift), pastes it into an AI, pastes the AI's JSON back, gets a
 * preview, and confirms. Untrusted input is treated as hostile: JSON
 * only (never unserialize), strict schema validation, aggressive
 * sanitization, sane size/count limits, capability-gated REST routes,
 * and the final write goes through Group_Store::create() — the same
 * hardened path as the manual builder.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AI_Import {

	/**
	 * Template schema version. Bump when the template shape changes.
	 */
	public const TEMPLATE_VERSION = '3';

	/**
	 * Hard limits for import payloads.
	 */
	public const MAX_BODY_BYTES = 262144; // 256 KB of JSON.
	public const MAX_FIELDS     = 200;    // Total, counting sub-fields and layout fields.
	public const MAX_SUB_DEPTH  = 3;      // Group/layout nesting depth.
	public const MAX_TITLE_LEN  = 200;
	public const MAX_LABEL_LEN  = 200;
	public const MAX_INSTR_LEN  = 2000;
	public const MAX_CHOICES    = 100;
	public const MAX_LOCATION   = 20;
	public const MAX_LOGIC      = 25;     // Conditional-logic rules per field.

	/**
	 * Canonical importable field keys → one-line LLM-facing description.
	 *
	 * SINGLE SOURCE OF TRUTH for what a field may carry in an AI import:
	 * - template() builds the copy-paste "fields[]" schema from these strings;
	 * - prompt() renders the same strings into the plain-text prompt;
	 * - validate() treats any field key NOT listed here as unknown: it is
	 *   dropped and surfaced as a warning, never silently.
	 *
	 * The sanitizers live in validate_field() (they need control flow), but
	 * the KEY LIST cannot drift: adding a setting means adding a key here
	 * and sanitizing it there.
	 */
	private const FIELD_SHAPE = array(
		// Identity & basics (every field).
		'key'               => 'REQUIRED string — unique field key like "f_k7x2qd" (f_ + letters/digits). Conditional logic points at this.',
		'name'              => 'REQUIRED string — machine name, lowercase letters/digits/underscores only, e.g. "job_title". Unique within the group.',
		'label'             => 'REQUIRED string — human label shown in the editor, e.g. "Job Title".',
		'type'              => 'REQUIRED string — one of valid_field_types.',
		'required'          => 'boolean, optional — must be filled in. Default false. Ignored for message/separator/tab.',
		'instructions'      => 'string, optional — help text shown under the field.',
		'default'           => 'string, optional — default value when the field is empty. Basic HTML allowed for wysiwyg.',
		'placeholder'       => 'string, optional — hint text inside empty text inputs (text, textarea, email, url, number).',
		'choices'           => 'REQUIRED for select/radio/button_group — object of value => label pairs, e.g. {"dev": "Developer"}. Never put choices on checkbox: checkbox is a single true/false toggle. For a set of tickable options use select with multiple: true.',
		'multiple'          => 'boolean, optional — allow several values (select, relationship, post_object, taxonomy, user). Default false.',
		'message'           => 'string, optional — static text shown in wp-admin. Only for type "message". Basic HTML allowed.',
		// Numbers.
		'min'               => 'number, optional — minimum value (number, range); minimum row count (repeater).',
		'max'               => 'number, optional — maximum value (number, range); maximum row count (repeater).',
		'step'              => 'number, optional — step increment (number, range).',
		'maxlength'         => 'integer, optional — maximum character count (text, textarea).',
		// Conditional logic (top-level fields only).
		'conditional_logic' => 'array, optional — show the field only when ALL rules hold. Each rule: {"field": "f_otherkey", "operator": "==", "value": "…"}. Operators: ==, !=, >, <, >=, <=, empty, !empty. A blank operator is treated as "==". "field" must be the key of another TOP-LEVEL field in this group. Do NOT nest rules ACF-style ([[{…}]]); use a flat array. Not available inside group sub-fields or layout fields.',
		// Relational (post_object, page_link, relationship, taxonomy, user).
		'post_types'        => 'array of strings, optional — restrict to these post type slugs (post_object, relationship).',
		'allow_external'    => 'boolean, optional — also allow external URLs (post_object, relationship). Default false.',
		'taxonomy'          => 'string, optional — taxonomy slug (taxonomy field). REQUIRED when save_terms or load_terms is true.',
		'field_type'        => 'string, optional — "autocomplete" (default) or "checkbox" (taxonomy field).',
		'save_terms'        => 'boolean, optional — save chosen terms to the post (taxonomy field; needs taxonomy). Default false.',
		'load_terms'        => 'boolean, optional — load chosen terms from the post (taxonomy field; needs taxonomy). Default false.',
		'roles'             => 'array of strings, optional — user role slugs to restrict to (user field). Empty = all roles.',
		'filter_taxonomy'   => 'string, optional — taxonomy slug to filter selectable items by.',
		'filter_term'       => 'string, optional — term slug to filter selectable items by.',
		// Media (image, file, gallery).
		'mime_types'        => 'string, optional — allowed MIME types, comma-separated, e.g. "image/jpeg,image/png" (image, file). Default "image".',
		'image_size'        => 'string, optional — "thumbnail", "medium", "large" (default) or "full" (image).',
		// WYSIWYG.
		'toolbar'           => 'string, optional — editor toolbar: "basic", "full" (default) or "none" (wysiwyg).',
		'allow_unfiltered'  => 'boolean, optional — allow unfiltered HTML in this field (wysiwyg). Default false.',
		// Map.
		'enable_search'     => 'boolean, optional — show an address search box (map). Default false.',
		'default_lat'       => 'number, optional — default map latitude (map).',
		'default_lng'       => 'number, optional — default map longitude (map).',
		'default_zoom'      => 'integer, optional — default map zoom level (map).',
		// Containers.
		'sub_fields'        => 'array, optional — inline sub-fields for type "group" or "repeater". Each is a full field definition (same keys as fields[]). Sub-field names must be unique within the group/repeater. Inside groups, nested group/clone/flexible_content and layout-only types are not allowed. Inside repeaters, repeaters may nest one level (depth 2: a repeater inside a repeater); clone, flexible_content and layout-only types are hard errors there. Conditional logic inside sub-fields is not supported.',
		'layouts'           => 'array, optional — layouts for type "flexible_content". Each: {"key": "hero", "label": "Hero", "min": 0, "max": 1, "fields": [field definitions]}. Layout keys: lowercase letters, digits, underscores. Layout fields follow the same field shape but without conditional_logic and without nested flexible_content/clone.',
		'button_label'      => 'string, optional — text of the "Add Row" button (repeater) or the "Add Layout" button (flexible_content).',
		'layout'            => 'string, optional — repeater row presentation: "list" (default) or "grid".',
		'collapsed'         => 'string, optional — repeater only: the NAME of one of its sub-fields, shown as the row summary when a row is collapsed. Must match a sub-field name in the same repeater.',
		'clone'             => 'integer, optional — ID of an EXISTING field group to mirror (type "clone" only). The source group must exist when you import.',
	);

	/**
	 * Group-level keys the importer accepts. Anything else is dropped with
	 * a warning (same no-silent-loss rule as field keys).
	 */
	private const GROUP_KEYS = array( 'title', 'fields', 'location', 'location_match', 'exclude_from_ai' );

	/**
	 * Conditional-logic operators. Mirrors Group_Store::OPERATORS — the
	 * template docs name the same list in prose.
	 */
	private const OPERATORS = array( '==', '!=', '>', '<', '>=', '<=', 'empty', '!empty' );

	/**
	 * Register hooks. Called from the main plugin file.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * The closed list of field types the importer accepts.
	 *
	 * Single source of truth: Field_Registry::types(). The template's
	 * valid_field_types list and this validator read the same registry,
	 * so they cannot drift apart when types are added.
	 *
	 * @return string[]
	 */
	public static function valid_types(): array {
		return Field_Registry::types();
	}

	/**
	 * Build the copy-paste template: schema + valid types + one complete
	 * example group. Generated fresh on every request from the live
	 * registry and the canonical FIELD_SHAPE — never a stale hardcoded copy.
	 *
	 * @return array Template document.
	 */
	public static function template(): array {
		$types = self::valid_types();

		return array(
			'$schema'           => 'tk-fields.ai-import/' . self::TEMPLATE_VERSION,
			'how_to_use'        => 'Copy the PROMPT below into your AI assistant (ChatGPT, Claude, Gemini). Describe the field group you want in plain language. The AI will reply with raw JSON — paste that JSON into the TK Fields AI Import dialog to preview and import it.',
			'output_rules'      => array(
				'Output ONLY raw JSON. No markdown fences, no ```json blocks, no explanations, no commentary before or after.',
				'The JSON must be a single object matching group_schema exactly.',
				'Every field "type" must be one of valid_field_types — never invent a type.',
				'Every field needs a unique "key" like "f_a1b2c3" (f_ followed by letters/digits) and a "name" of lowercase letters, digits and underscores only.',
				'For select/radio/button_group, "choices" is required: an object mapping value => label.',
				'Optional settings (default, placeholder, conditional_logic, min/max/step/maxlength, sub_fields, layouts, post_types, …) are documented under fields[] — use ONLY keys listed there. Unknown keys are dropped with a warning.',
				'conditional_logic points at another field by its "key", and the referenced field must be a top-level field in the same group.',
				'Group "sub_fields" and layout "fields" are nested field definitions with the same shape as fields[]; their "name" must be unique within that group/layout.',
			),
			'group_schema'      => array(
				'title'          => 'string, required, 1-200 chars — the group title shown in wp-admin',
				'fields'         => 'array, required, 1-200 items — the field definitions (sub-fields and layout fields count toward the 200)',
				'location'       => 'array, optional — location rules; each rule is {param, operator, value}. param: post_type|page_template|taxonomy|post. operator: ==|!=|>|<|>=|<=|empty|!empty. Example: {"param":"post_type","operator":"==","value":"post"}',
				'location_match' => 'string, optional — "all" (default) or "any"',
				'exclude_from_ai' => 'boolean, optional — hide this group from AI context export. Default false.',
				'fields[]'       => self::FIELD_SHAPE,
			),
			'valid_field_types' => $types,
			'example'           => self::example_group(),
		);
	}

	/**
	 * The worked example embedded in the template: exercises the headline
	 * new settings (conditional_logic, sub_fields, relational config) so
	 * the AI imitates them.
	 *
	 * @return array Example group.
	 */
	private static function example_group(): array {
		return array(
			'title'          => 'Team Member',
			'location'       => array(
				array(
					'param'    => 'post_type',
					'operator' => '==',
					'value'    => 'post',
				),
			),
			'location_match' => 'all',
			'fields'         => array(
				array(
					'key'         => 'f_tm01ab',
					'name'        => 'full_name',
					'label'       => 'Full Name',
					'type'        => 'text',
					'required'    => true,
					'placeholder' => 'Jane Doe',
				),
				array(
					'key'          => 'f_tm02cd',
					'name'         => 'role',
					'label'        => 'Role',
					'type'         => 'select',
					'instructions' => 'Pick the team role.',
					'choices'      => array(
						'dev'    => 'Developer',
						'design' => 'Designer',
						'pm'     => 'Project Manager',
					),
				),
				array(
					'key'               => 'f_tm03ef',
					'name'              => 'github_url',
					'label'             => 'GitHub URL',
					'type'              => 'url',
					'conditional_logic' => array(
						array(
							'field'    => 'f_tm02cd',
							'operator' => '==',
							'value'    => 'dev',
						),
					),
				),
				array(
					'key'        => 'f_tm04gh',
					'name'       => 'address',
					'label'      => 'Address',
					'type'       => 'group',
					'sub_fields' => array(
						array(
							'key'   => 'f_tm04a1',
							'name'  => 'street',
							'label' => 'Street',
							'type'  => 'text',
						),
						array(
							'key'   => 'f_tm04a2',
							'name'  => 'city',
							'label' => 'City',
							'type'  => 'text',
						),
					),
				),
				array(
					'key'        => 'f_tm05ij',
					'name'       => 'projects',
					'label'      => 'Projects',
					'type'       => 'relationship',
					'post_types' => array( 'post', 'page' ),
					'multiple'   => true,
				),
			),
		);
	}

	/**
	 * The plain-text prompt the admin pastes into their AI assistant.
	 *
	 * Rendered from the template (output rules, FIELD_SHAPE lines, type
	 * list, worked example) — the prompt can never drift from what the
	 * validator accepts.
	 */
	public static function prompt(): string {
		$tpl   = self::template();
		$lines = array();

		$lines[] = 'You generate TK Fields field-group definitions as JSON.';
		$lines[] = '';
		$lines[] = 'HARD OUTPUT RULES (follow exactly):';
		foreach ( $tpl['output_rules'] as $rule ) {
			$lines[] = '- ' . $rule;
		}
		$lines[] = '';
		$lines[] = 'GROUP SCHEMA:';
		$lines[] = '  title: string, required (1-200 chars)';
		$lines[] = '  fields: array, required (1-200 items; sub-fields and layout fields count too). Each field:';
		foreach ( self::FIELD_SHAPE as $fkey => $desc ) {
			$lines[] = '    ' . $fkey . ': ' . $desc;
		}
		$lines[] = '  location: optional array of {"param": "post_type|page_template|taxonomy|post", "operator": "==|!=", "value": "..."}';
		$lines[] = '  location_match: "all" or "any" (optional, default "all")';
		$lines[] = '  exclude_from_ai: true/false (optional, default false)';
		$lines[] = '';
		$lines[] = 'CONDITIONAL LOGIC MINI-EXAMPLE (github_url appears only when role is "dev"):';
		$lines[] = '  {"key": "f_g1", "name": "role", "label": "Role", "type": "select", "choices": {"dev": "Developer", "pm": "PM"}}';
		$lines[] = '  {"key": "f_g2", "name": "github_url", "label": "GitHub URL", "type": "url", "conditional_logic": [{"field": "f_g1", "operator": "==", "value": "dev"}]}';
		$lines[] = '';
		$lines[] = 'GROUP-WITH-SUB-FIELDS MINI-EXAMPLE:';
		$lines[] = '  {"key": "f_a1", "name": "address", "label": "Address", "type": "group", "sub_fields": [{"key": "f_a2", "name": "street", "label": "Street", "type": "text"}, {"key": "f_a3", "name": "city", "label": "City", "type": "text"}]}';
		$lines[] = '';
		$lines[] = 'REPEATER-WITH-SUB-FIELDS MINI-EXAMPLE (rows repeat; a repeater may nest one level deep, never two):';
		$lines[] = '  {"key": "f_m1", "name": "course_modules", "label": "Course Modules", "type": "repeater", "min": 1, "max": 10, "button_label": "Add New Module", "layout": "list", "collapsed": "module_title", "sub_fields": [{"key": "f_m2", "name": "module_title", "label": "Module Title", "type": "text", "required": true}, {"key": "f_m3", "name": "lessons", "label": "Lessons", "type": "repeater", "sub_fields": [{"key": "f_m4", "name": "lesson_title", "label": "Lesson Title", "type": "text", "required": true}, {"key": "f_m5", "name": "video_url", "label": "Video URL", "type": "url"}]}]}';
		$lines[] = '';
		$lines[] = 'CHECKBOX vs MULTI-SELECT (common mistake — do not mix them up):';
		$lines[] = '  "checkbox" is ONE true/false toggle and takes NO choices: {"key": "f_t1", "name": "featured", "label": "Featured", "type": "checkbox"}';
		$lines[] = '  For a set of tickable options use "select" with multiple: true: {"key": "f_t2", "name": "interests", "label": "Interests", "type": "select", "multiple": true, "choices": {"sports": "Sports", "music": "Music"}}';
		$lines[] = '';
		$lines[] = 'VALID FIELD TYPES (use only these):';
		$lines[] = '  ' . implode( ', ', $tpl['valid_field_types'] );
		$lines[] = '';
		$lines[] = 'FULL EXAMPLE (imitate this shape exactly):';
		$lines[] = wp_json_encode( $tpl['example'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$lines[] = '';
		$lines[] = 'Now wait for the user to describe the field group they want, then reply with ONLY the raw JSON object.';

		return implode( "\n", $lines );
	}

	/**
	 * Decode + strictly validate pasted AI JSON.
	 *
	 * Fail-closed: anything that is not a well-formed group object comes
	 * back as WP_Error with a human-readable reason. Labels and other
	 * free text are sanitized here (script tags and markup are stripped,
	 * not rejected — the safe handling), while structural problems
	 * (unknown field type, bad key format, missing required data) are
	 * hard errors. Unknown keys (field or group level) are dropped but
	 * always reported as warnings — nothing vanishes silently. The final
	 * write still goes through Group_Store::create(), which re-validates
	 * everything.
	 *
	 * @param string $json Raw pasted text.
	 * @return array|WP_Error ['payload' => array, 'warnings' => string[]] or WP_Error.
	 */
	public static function validate( string $json ): array|\WP_Error {
		if ( strlen( $json ) > self::MAX_BODY_BYTES ) {
			return new \WP_Error(
				'tk_fields_ai_import_too_large',
				sprintf(
					/* translators: %d: max kilobytes */
					__( 'The pasted JSON is too large (max %1$d KB).', 'tk-fields' ),
					(int) ( self::MAX_BODY_BYTES / 1024 )
				),
				array( 'status' => 400 )
			);
		}

		// Defensive: strip markdown fences even though the prompt forbids
		// them — AIs wrap output anyway. Recorded as a warning, not an
		// error, because the intent is unambiguous.
		$warnings = array();
		$trimmed  = trim( $json );
		if ( str_starts_with( $trimmed, '```' ) ) {
			$warnings[] = __( 'Markdown code fences were stripped from the pasted text. The prompt asks the AI not to use them.', 'tk-fields' );
			$trimmed    = (string) preg_replace( '/^```[a-zA-Z]*\s*/', '', $trimmed );
			$trimmed    = (string) preg_replace( '/\s*```$/', '', $trimmed );
		}

		$data = json_decode( $trimmed, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'tk_fields_ai_import_bad_json',
				__( 'That is not valid JSON. Paste the raw JSON object the AI returned — no explanations or commentary around it.', 'tk-fields' ),
				array( 'status' => 400 )
			);
		}

		// Unknown group-level keys: dropped, never silently.
		foreach ( array_keys( $data ) as $gkey ) {
			if ( ! in_array( $gkey, self::GROUP_KEYS, true ) ) {
				$warnings[] = sprintf(
					/* translators: %s: unknown setting name */
					__( 'Unrecognized group setting "%1$s" was dropped.', 'tk-fields' ),
					$gkey
				);
			}
		}

		// Title.
		$title = isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '';
		if ( '' === $title ) {
			return self::err( __( 'The JSON needs a "title" for the field group.', 'tk-fields' ) );
		}
		if ( mb_strlen( $title ) > self::MAX_TITLE_LEN ) {
			return self::err( __( 'The group title is too long (max 200 characters).', 'tk-fields' ) );
		}

		// Fields.
		$fields = $data['fields'] ?? null;
		if ( ! is_array( $fields ) || array() === $fields ) {
			return self::err( __( 'The JSON needs a "fields" array with at least one field.', 'tk-fields' ) );
		}
		if ( count( $fields ) > self::MAX_FIELDS ) {
			return self::err(
				sprintf(
					/* translators: %d: max field count */
					__( 'Too many top-level fields (max %1$d per import). Split the group and import in parts.', 'tk-fields' ),
					self::MAX_FIELDS
				)
			);
		}

		$valid_types  = self::valid_types();
		$clean_fields = array();
		$seen_keys    = array();
		$seen_names   = array();
		$field_count  = 0; // Total incl. sub-fields and layout fields.
		$logic_refs   = array();

		foreach ( $fields as $i => $field ) {
			/* translators: %d: field number */
			$at    = sprintf( __( 'Field %1$d', 'tk-fields' ), $i + 1 );
			$clean = self::validate_field( $field, $at, 0, $valid_types, $seen_keys, $seen_names, $field_count, $warnings, $logic_refs );
			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
			$clean_fields[] = $clean;
		}

		if ( $field_count > self::MAX_FIELDS ) {
			return self::err(
				sprintf(
					/* translators: %d: max field count */
					__( 'Too many fields in total (max %1$d, counting sub-fields and layout fields). Split the group and import in parts.', 'tk-fields' ),
					self::MAX_FIELDS
				)
			);
		}

		// Conditional-logic references: every rule must point at a known
		// top-level field key. (Sub-field references are impossible: logic
		// is stripped inside sub-fields with a warning.)
		foreach ( $logic_refs as $ref ) {
			if ( ! isset( $seen_keys[ $ref['field'] ] ) ) {
				return self::err(
					sprintf(
						/* translators: 1: field position, 2: unknown key */
						__( '%1$s: conditional logic references unknown field key "%2$s". Conditions may only point at other top-level fields in this group.', 'tk-fields' ),
						$ref['at'],
						$ref['field']
					)
				);
			}
		}

		// Location rules: light structural check; Group_Store::create()
		// does the strict semantic validation (known post types, etc.)
		// at confirm time.
		$location = array();
		if ( isset( $data['location'] ) ) {
			if ( ! is_array( $data['location'] ) ) {
				return self::err( __( '"location" must be an array of rules.', 'tk-fields' ) );
			}
			if ( count( $data['location'] ) > self::MAX_LOCATION ) {
				return self::err( __( 'Too many location rules (max 20).', 'tk-fields' ) );
			}
			$allowed_params = array( 'post_type', 'page_template', 'taxonomy', 'post' );
			$allowed_ops    = array( '==', '!=', '>', '<', '>=', '<=', 'empty', '!empty' );
			foreach ( $data['location'] as $ri => $rule ) {
				if ( ! is_array( $rule ) ) {
					/* translators: %d: rule number */
					return self::err( sprintf( __( 'Location rule %1$d must be an object.', 'tk-fields' ), $ri + 1 ) );
				}
				// Nested rule groups pass through structurally.
				if ( isset( $rule['rules'] ) && is_array( $rule['rules'] ) ) {
					$location[] = array( 'rules' => $rule['rules'] );
					continue;
				}
				$param = (string) ( $rule['param'] ?? '' );
				$op    = (string) ( $rule['operator'] ?? '' );
				// Forgiving: a blank location operator means "==" — the same
				// default Group_Store applies when the key is absent entirely.
				if ( '' === $op ) {
					$op         = '==';
					/* translators: %d: rule number */
					$warnings[] = sprintf( __( 'Location rule %1$d had an empty operator — treated as "==".', 'tk-fields' ), $ri + 1 );
				}
				if ( ! in_array( $param, $allowed_params, true ) ) {
					return self::err(
						sprintf(
							/* translators: %s: bad param */
							__( 'Location rule %1$d: unknown param "%2$s". Valid: %3$s.', 'tk-fields' ),
							$ri + 1,
							$param,
							implode( ', ', $allowed_params )
						)
					);
				}
				if ( ! in_array( $op, $allowed_ops, true ) ) {
					/* translators: 1: rule number, 2: operator */
					return self::err( sprintf( __( 'Location rule %1$d: unknown operator "%2$s".', 'tk-fields' ), $ri + 1, $op ) );
				}
				$location[] = array(
					'param'    => $param,
					'operator' => $op,
					'value'    => is_scalar( $rule['value'] ?? '' ) ? (string) ( $rule['value'] ?? '' ) : '',
				);
			}
		}

		$location_match = ( isset( $data['location_match'] ) && 'any' === $data['location_match'] ) ? 'any' : 'all';

		$payload = array(
			'title'          => $title,
			'location'       => $location,
			'location_match' => $location_match,
			'fields'         => $clean_fields,
		);
		if ( ! empty( $data['exclude_from_ai'] ) ) {
			$payload['exclude_from_ai'] = true;
		}

		// Conflict signal: same title already exists — surfaced in the
		// preview so the user picks create vs rename.
		if ( self::title_exists( $title ) ) {
			$warnings[] = sprintf(
				/* translators: %s: group title */
				__( 'A field group titled "%1$s" already exists. You can still import (two groups may share a title) or rename it on the preview screen.', 'tk-fields' ),
				$title
			);
		}

		return array(
			'payload'  => $payload,
			'warnings' => $warnings,
		);
	}

	/**
	 * Validate + sanitize one field definition (top-level or nested).
	 *
	 * Mirrors Group_Store::sanitize_field() so the preview payload survives
	 * Group_Store::create()'s re-validation unchanged: every key we carry
	 * is one the group store accepts, sanitized to the same rules.
	 *
	 * @param mixed    $field       Raw field array.
	 * @param string   $at          Human position label for errors/warnings.
	 * @param int      $depth       Nesting depth (0 = top level).
	 * @param string[] $valid_types Closed type list.
	 * @param array    $seen_keys   Field keys seen at this level (by ref).
	 * @param array    $seen_names  Field names seen at this level (by ref).
	 * @param int      $count       Total field counter incl. nested (by ref).
	 * @param string[] $warnings    Warning list (by ref).
	 * @param array    $logic_refs  Conditional-logic references to check (by ref).
	 * @param int      $rep_depth   Repeater nesting depth: how many repeater
	 *                              ancestors enclose this field (0 = none).
	 *                              Groups do not add repeater depth; the depth
	 *                              threads through them, never resets.
	 * @return array|WP_Error Clean field, or WP_Error (fail closed).
	 */
	private static function validate_field( $field, string $at, int $depth, array $valid_types, array &$seen_keys, array &$seen_names, int &$count, array &$warnings, array &$logic_refs, int $rep_depth = 0 ): array|\WP_Error {
		if ( ! is_array( $field ) ) {
			/* translators: %s: field position, e.g. 'Field 3' */
			return self::err( sprintf( __( '%1$s must be an object.', 'tk-fields' ), $at ) );
		}
		if ( $depth > self::MAX_SUB_DEPTH ) {
			/* translators: 1: field position, e.g. 'Field 3', 2: maximum nesting depth */
			return self::err( sprintf( __( '%1$s: nesting too deep (max %2$d levels).', 'tk-fields' ), $at, self::MAX_SUB_DEPTH ) );
		}

		// Unknown keys: dropped, never silently.
		foreach ( array_keys( $field ) as $fkey ) {
			if ( ! array_key_exists( $fkey, self::FIELD_SHAPE ) ) {
				$warnings[] = sprintf(
					/* translators: 1: field position, 2: unknown setting */
					__( '%1$s: unrecognized setting "%2$s" was dropped.', 'tk-fields' ),
					$at,
					$fkey
				);
			}
		}

		// Key: required format; auto-generate (with warning) when the
		// AI omitted or mangled it — keys are internal, names are the
		// API surface that must be exact.
		$key = isset( $field['key'] ) ? (string) $field['key'] : '';
		if ( ! preg_match( '/^f_[A-Za-z0-9_]{1,64}$/', $key ) ) {
			$key        = 'f_' . strtolower( (string) wp_generate_password( 12, false ) );
			$warnings[] = sprintf(
				/* translators: %s: field position */
				__( '%1$s had a missing or invalid "key" — one was generated automatically. Field names are unchanged.', 'tk-fields' ),
				$at
			);
		}
		if ( isset( $seen_keys[ $key ] ) ) {
			/* translators: %s: field key */
			return self::err( sprintf( __( 'Duplicate field key "%1$s". Keys must be unique.', 'tk-fields' ), $key ) );
		}
		$seen_keys[ $key ] = true;

		// Name: the API surface — strict, no guessing.
		$name = isset( $field['name'] ) ? (string) $field['name'] : '';
		if ( ! preg_match( '/^[a-z0-9_]{1,64}$/', $name ) ) {
			return self::err(
				sprintf(
					/* translators: %s: field position */
					__( '%1$s has an invalid "name" — use lowercase letters, digits and underscores only (e.g. "job_title").', 'tk-fields' ),
					$at
				)
			);
		}
		if ( isset( $seen_names[ $name ] ) ) {
			/* translators: %s: field name */
			return self::err( sprintf( __( 'Duplicate field name "%1$s". Names must be unique within a group.', 'tk-fields' ), $name ) );
		}
		$seen_names[ $name ] = true;

		// Label: sanitized — markup/script is stripped, not rejected.
		$label = isset( $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : '';
		if ( '' === $label ) {
			/* translators: %s: field position, e.g. 'Field 3' */
			return self::err( sprintf( __( '%1$s needs a "label".', 'tk-fields' ), $at ) );
		}
		if ( mb_strlen( $label ) > self::MAX_LABEL_LEN ) {
			/* translators: %s: field position, e.g. 'Field 3' */
			return self::err( sprintf( __( '%1$s label is too long (max 200 characters).', 'tk-fields' ), $at ) );
		}

		// Type: closed list — unknown types are hard errors.
		$type = isset( $field['type'] ) ? (string) $field['type'] : '';
		if ( ! in_array( $type, $valid_types, true ) ) {
			return self::err(
				sprintf(
					/* translators: 1: unknown field type, 2: field position */
					__( 'Unknown field type "%1$s" (%2$s). Valid types: %3$s.', 'tk-fields' ),
					$type,
					$at,
					implode( ', ', $valid_types )
				)
			);
		}

		++$count;

		// Repeater nesting cap (contract Q2): two levels are allowed
		// (modules → lessons); a third nested repeater is a hard error —
		// never a silent drop.
		if ( 'repeater' === $type && $rep_depth >= 2 ) {
			/* translators: %s: field position, e.g. 'Field 3' */
			return self::err( sprintf( __( '%1$s: repeaters nest at most 2 levels deep — this would be level 3.', 'tk-fields' ), $at ) );
		}

		$stores = Field_Registry::stores( $type );

		$clean = array(
			'key'      => $key,
			'name'     => $name,
			'label'    => $label,
			'type'     => $type,
			// Layout-only types carry no value, so "required" is meaningless
			// — always false, mirroring Group_Store.
			'required' => $stores ? ! empty( $field['required'] ) : false,
		);

		// Instructions.
		if ( isset( $field['instructions'] ) ) {
			$instr = sanitize_textarea_field( (string) $field['instructions'] );
			if ( mb_strlen( $instr ) > self::MAX_INSTR_LEN ) {
				/* translators: %s: field position, e.g. 'Field 3' */
				return self::err( sprintf( __( '%1$s instructions are too long (max 2000 characters).', 'tk-fields' ), $at ) );
			}
			if ( '' !== $instr ) {
				$clean['instructions'] = $instr;
			}
		}

		// Default value. Wysiwyg defaults are HTML: wp_kses_post (or raw
		// under the allow_unfiltered opt-in) — mirrors Group_Store.
		if ( isset( $field['default'] ) ) {
			$raw = (string) $field['default'];
			if ( 'wysiwyg' === $type ) {
				$def = ! empty( $field['allow_unfiltered'] ) ? $raw : wp_kses_post( $raw );
			} else {
				$def = sanitize_text_field( $raw );
			}
			if ( '' !== $def ) {
				$clean['default'] = $def;
			}
		}

		// Placeholder.
		if ( isset( $field['placeholder'] ) ) {
			$ph = sanitize_text_field( (string) $field['placeholder'] );
			if ( '' !== $ph ) {
				$clean['placeholder'] = $ph;
			}
		}

		// Message text (message type only).
		if ( isset( $field['message'] ) ) {
			if ( 'message' === $type ) {
				$msg = wp_kses_post( (string) $field['message'] );
				if ( '' !== $msg ) {
					$clean['message'] = $msg;
				}
			} else {
				/* translators: %s: field position, e.g. 'Field 3' */
				$warnings[] = sprintf( __( '%1$s: "message" only applies to message-type fields — dropped.', 'tk-fields' ), $at );
			}
		}

		// Conditional logic (top-level fields only — the group store
		// forces it off inside groups/layouts in v1).
		if ( ! empty( $field['conditional_logic'] ) ) {
			if ( $depth > 0 ) {
				/* translators: %s: field position, e.g. 'Field 3' */
				$warnings[] = sprintf( __( '%1$s: conditional logic inside sub-fields is not supported — it was removed.', 'tk-fields' ), $at );
			} else {
				$logic = $field['conditional_logic'];
				if ( ! is_array( $logic ) ) {
					/* translators: %s: field position, e.g. 'Field 3' */
					return self::err( sprintf( __( '%1$s: "conditional_logic" must be an array of rules.', 'tk-fields' ), $at ) );
				}
				// Forgiving: ACF-style nested rule groups [[{…}]] collapse to a
				// flat AND list when there is exactly one group. Multiple groups
				// are genuine OR logic, which TK Fields cannot express — that
				// stays a hard error instead of silently changing meaning.
				if ( array() !== $logic ) {
					$first = reset( $logic );
					if ( is_array( $first ) && ! isset( $first['field'] ) ) {
						if ( count( $logic ) > 1 ) {
							/* translators: %s: field position, e.g. 'Field 3' */
							return self::err( sprintf( __( '%1$s: conditional_logic uses OR rule groups, which are not supported — express the rules as one flat array (every rule must hold).', 'tk-fields' ), $at ) );
						}
						$group = reset( $logic );
						if ( ! is_array( $group ) ) {
							/* translators: %s: field position, e.g. 'Field 3' */
							return self::err( sprintf( __( '%1$s: "conditional_logic" must be an array of rules.', 'tk-fields' ), $at ) );
						}
						$logic        = array_values( $group );
						/* translators: %s: field position, e.g. 'Field 3' */
						$warnings[]   = sprintf( __( '%1$s: conditional_logic was nested ACF-style ([[{…}]]) — flattened to a single rule list.', 'tk-fields' ), $at );
					}
				}
				if ( count( $logic ) > self::MAX_LOGIC ) {
					/* translators: 1: field position, e.g. 'Field 3', 2: maximum number of conditional rules */
					return self::err( sprintf( __( '%1$s: too many conditional rules (max %2$d).', 'tk-fields' ), $at, self::MAX_LOGIC ) );
				}
				$clean_logic = array();
				foreach ( $logic as $li => $cond ) {
					if ( ! is_array( $cond ) ) {
						/* translators: 1: field position, e.g. 'Field 3', 2: rule number */
						return self::err( sprintf( __( '%1$s: conditional rule %2$d must be an object.', 'tk-fields' ), $at, $li + 1 ) );
					}
					$cfield = isset( $cond['field'] ) ? (string) $cond['field'] : '';
					if ( ! preg_match( '/^f_[A-Za-z0-9_]{1,64}$/', $cfield ) ) {
						/* translators: 1: field position, e.g. 'Field 3', 2: rule number */
						return self::err( sprintf( __( '%1$s: conditional rule %2$d needs a valid field key (f_…).', 'tk-fields' ), $at, $li + 1 ) );
					}
					$coperator = (string) ( $cond['operator'] ?? '' );
					// Forgiving: a blank operator means "==" — the same default
					// Group_Store applies when the key is absent entirely.
					if ( '' === $coperator ) {
						$coperator  = '==';
						/* translators: 1: field position, e.g. 'Field 3', 2: rule number */
						$warnings[] = sprintf( __( '%1$s: conditional rule %2$d had an empty operator — treated as "==".', 'tk-fields' ), $at, $li + 1 );
					}
					if ( ! in_array( $coperator, self::OPERATORS, true ) ) {
						/* translators: 1: field position, e.g. 'Field 3', 2: rule number, 3: operator */
						return self::err( sprintf( __( '%1$s: conditional rule %2$d has an unknown operator "%3$s".', 'tk-fields' ), $at, $li + 1, $coperator ) );
					}
					$clean_logic[] = array(
						'field'    => $cfield,
						'operator' => $coperator,
						'value'    => isset( $cond['value'] ) ? sanitize_text_field( (string) $cond['value'] ) : '',
					);
					$logic_refs[]  = array( 'at' => $at, 'field' => $cfield );
				}
				if ( array() !== $clean_logic ) {
					$clean['conditional_logic'] = $clean_logic;
				}
			}
		}

		// Numeric bounds (min/max/step) and maxlength. On a repeater, min/max
		// are ROW counts (whole numbers ≥ 0, enforced below); step and
		// maxlength are per-value settings that do not apply — dropped with
		// a warning so the preview stays faithful to what is stored.
		$is_repeater  = 'repeater' === $type;
		$numeric_keys = $is_repeater ? array( 'min', 'max' ) : array( 'min', 'max', 'step' );
		if ( $is_repeater ) {
			if ( isset( $field['step'] ) ) {
				/* translators: %s: field position, e.g. 'Field 3' */
				$warnings[] = sprintf( __( '%1$s: "step" does not apply to repeaters — dropped.', 'tk-fields' ), $at );
			}
			if ( isset( $field['maxlength'] ) ) {
				/* translators: %s: field position, e.g. 'Field 3' */
				$warnings[] = sprintf( __( '%1$s: "maxlength" does not apply to repeaters — dropped.', 'tk-fields' ), $at );
			}
		}
		foreach ( $numeric_keys as $numkey ) {
			if ( isset( $field[ $numkey ] ) && '' !== $field[ $numkey ] && null !== $field[ $numkey ] ) {
				if ( ! is_numeric( $field[ $numkey ] ) ) {
					/* translators: 1: field position, e.g. 'Field 3', 2: setting key */
					return self::err( sprintf( __( '%1$s: "%2$s" must be a number.', 'tk-fields' ), $at, $numkey ) );
				}
				$num             = $field[ $numkey ] + 0;
				$clean[ $numkey ] = is_float( $num ) ? $num : (int) $num;
			}
		}
		if ( ! $is_repeater && isset( $field['maxlength'] ) && '' !== $field['maxlength'] && null !== $field['maxlength'] ) {
			if ( ! is_numeric( $field['maxlength'] ) ) {
				/* translators: %s: field position, e.g. 'Field 3' */
				return self::err( sprintf( __( '%1$s: "maxlength" must be a number.', 'tk-fields' ), $at ) );
			}
			$ml = absint( $field['maxlength'] );
			if ( $ml > 0 ) {
				$clean['maxlength'] = $ml;
			}
		}

		// Choices: required for the choice types, sanitized pairs.
		if ( in_array( $type, array( 'select', 'radio', 'button_group' ), true ) ) {
			$choices = $field['choices'] ?? null;
			if ( ! is_array( $choices ) || array() === $choices ) {
				/* translators: 1: field position, e.g. 'Field 3', 2: field type */
				return self::err( sprintf( __( '%1$s: type "%2$s" requires a "choices" object of value => label pairs.', 'tk-fields' ), $at, $type ) );
			}
			if ( count( $choices ) > self::MAX_CHOICES ) {
				/* translators: %s: field position, e.g. 'Field 3' */
				return self::err( sprintf( __( '%1$s: too many choices (max 100).', 'tk-fields' ), $at ) );
			}
			$clean_choices = array();
			foreach ( $choices as $ck => $cv ) {
				$ck = sanitize_key( (string) $ck );
				$cv = sanitize_text_field( (string) $cv );
				if ( '' === $ck || '' === $cv ) {
					continue;
				}
				$clean_choices[ $ck ] = $cv;
			}
			if ( array() === $clean_choices ) {
				/* translators: %s: field position, e.g. 'Field 3' */
				return self::err( sprintf( __( '%1$s: "choices" contained no usable value => label pairs.', 'tk-fields' ), $at ) );
			}
			$clean['choices'] = $clean_choices;
		}

		// Multiple selection (select + relational multi-select).
		if ( ! empty( $field['multiple'] ) ) {
			$clean['multiple'] = true;
		}

		// Relational settings — mirrors Group_Store::sanitize_field().
		if ( isset( $field['post_types'] ) ) {
			$pts = array_values( array_filter( array_map( 'sanitize_key', (array) $field['post_types'] ) ) );
			if ( array() !== $pts ) {
				$clean['post_types'] = $pts;
			}
		}
		if ( ! empty( $field['allow_external'] ) ) {
			$clean['allow_external'] = true;
		}
		if ( isset( $field['taxonomy'] ) ) {
			$tax = sanitize_key( (string) $field['taxonomy'] );
			if ( '' !== $tax ) {
				$clean['taxonomy'] = $tax;
			}
		}
		$ftype = (string) ( $field['field_type'] ?? '' );
		if ( in_array( $ftype, array( 'checkbox', 'autocomplete' ), true ) ) {
			$clean['field_type'] = $ftype;
		}
		$save_terms = ! empty( $field['save_terms'] );
		$load_terms = ! empty( $field['load_terms'] );
		if ( ( $save_terms || $load_terms ) && empty( $clean['taxonomy'] ) ) {
			/* translators: %s: field position, e.g. 'Field 3' */
			return self::err( sprintf( __( '%1$s: save_terms/load_terms need a "taxonomy" selected.', 'tk-fields' ), $at ) );
		}
		if ( $save_terms ) {
			$clean['save_terms'] = true;
		}
		if ( $load_terms ) {
			$clean['load_terms'] = true;
		}
		if ( isset( $field['roles'] ) ) {
			$roles = array_values( array_filter( array_map( 'sanitize_key', (array) $field['roles'] ) ) );
			// Intersect with real role slugs — mirrors Group_Store.
			$roles = array_values( array_intersect( $roles, array_keys( wp_roles()->get_names() ) ) );
			if ( array() !== $roles ) {
				$clean['roles'] = $roles;
			}
		}
		if ( isset( $field['filter_taxonomy'] ) ) {
			$ftx = sanitize_key( (string) $field['filter_taxonomy'] );
			if ( '' !== $ftx ) {
				$clean['filter_taxonomy'] = $ftx;
			}
		}
		if ( isset( $field['filter_term'] ) ) {
			$ftm = sanitize_text_field( (string) $field['filter_term'] );
			if ( '' !== $ftm ) {
				$clean['filter_term'] = $ftm;
			}
		}
		if ( isset( $field['mime_types'] ) ) {
			$mm = sanitize_text_field( (string) $field['mime_types'] );
			if ( '' !== $mm ) {
				$clean['mime_types'] = $mm;
			}
		}
		$isz = (string) ( $field['image_size'] ?? '' );
		if ( in_array( $isz, array( 'thumbnail', 'medium', 'large', 'full' ), true ) ) {
			$clean['image_size'] = $isz;
		}

		// WYSIWYG settings.
		$toolbar = (string) ( $field['toolbar'] ?? '' );
		if ( in_array( $toolbar, Field_Registry::WYSIWYG_TOOLBAR_PRESETS, true ) ) {
			$clean['toolbar'] = $toolbar;
		}
		if ( ! empty( $field['allow_unfiltered'] ) ) {
			$clean['allow_unfiltered'] = true;
		}

		// Map settings.
		if ( ! empty( $field['enable_search'] ) ) {
			$clean['enable_search'] = true;
		}
		foreach ( array( 'default_lat', 'default_lng' ) as $latkey ) {
			if ( isset( $field[ $latkey ] ) && '' !== $field[ $latkey ] && null !== $field[ $latkey ] ) {
				if ( ! is_numeric( $field[ $latkey ] ) ) {
					/* translators: 1: field position, e.g. 'Field 3', 2: setting key */
					return self::err( sprintf( __( '%1$s: "%2$s" must be a number.', 'tk-fields' ), $at, $latkey ) );
				}
				$nlat               = $field[ $latkey ] + 0;
				$clean[ $latkey ] = is_float( $nlat ) ? $nlat : (int) $nlat;
			}
		}
		if ( isset( $field['default_zoom'] ) && '' !== $field['default_zoom'] && null !== $field['default_zoom'] ) {
			if ( ! is_numeric( $field['default_zoom'] ) ) {
				/* translators: %s: field position, e.g. 'Field 3' */
				return self::err( sprintf( __( '%1$s: "default_zoom" must be a number.', 'tk-fields' ), $at ) );
			}
			$clean['default_zoom'] = (int) $field['default_zoom'];
		}

		// Group sub-fields — mirrors Group_Store::sanitize_group_sub_fields().
		if ( 'group' === $type ) {
			$result = self::validate_sub_fields( $field['sub_fields'] ?? array(), $at, 'tk_group:' . $name . ':', $depth, $valid_types, $count, $warnings, $logic_refs, 'group', $rep_depth );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( array() !== $result ) {
				$clean['sub_fields'] = $result;
			}
		} elseif ( 'repeater' === $type ) {
			// Repeater sub-fields — recursive through depth 2 (contract Q7);
			// clone/flexible_content/layout-only sub-fields are hard errors
			// (contract Q3: never silently dropped). Zero keys are dropped
			// on the way through: validate_sub_fields() carries every known
			// key and validate_field() sanitizes each sub-field in full.
			$result = self::validate_sub_fields( $field['sub_fields'] ?? array(), $at, 'tk_repeater:' . $name . ':', $depth, $valid_types, $count, $warnings, $logic_refs, 'repeater', $rep_depth + 1 );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( array() !== $result ) {
				$clean['sub_fields'] = $result;
			}

			// min/max are row counts: whole numbers of 0 or more, min ≤ max.
			foreach ( array( 'min', 'max' ) as $rowkey ) {
				if ( isset( $clean[ $rowkey ] ) && ( ! is_int( $clean[ $rowkey ] ) || $clean[ $rowkey ] < 0 ) ) {
					/* translators: 1: field position, e.g. 'Field 3', 2: setting key */
					return self::err( sprintf( __( '%1$s: "%2$s" on a repeater is a row count — use a whole number of 0 or more.', 'tk-fields' ), $at, $rowkey ) );
				}
			}
			if ( isset( $clean['min'], $clean['max'] ) && $clean['min'] > $clean['max'] ) {
				/* translators: %s: field position, e.g. 'Field 3' */
				return self::err( sprintf( __( '%1$s: "min" rows cannot exceed "max" rows.', 'tk-fields' ), $at ) );
			}

			// Button label (plain string).
			if ( isset( $field['button_label'] ) ) {
				$bl = sanitize_text_field( (string) $field['button_label'] );
				if ( '' !== $bl ) {
					$clean['button_label'] = $bl;
				}
			}

			// Layout: list|grid, default list.
			$layout = (string) ( $field['layout'] ?? '' );
			if ( '' !== $layout && ! in_array( $layout, array( 'list', 'grid' ), true ) ) {
				/* translators: %s: field position, e.g. 'Field 3' */
				return self::err( sprintf( __( '%1$s: "layout" must be "list" or "grid".', 'tk-fields' ), $at ) );
			}
			$clean['layout'] = '' !== $layout ? $layout : 'list';

			// Collapsed: must name an actual sub-field of THIS repeater — a
			// dangling reference is a hard error, never persisted.
			if ( isset( $field['collapsed'] ) ) {
				$collapsed = sanitize_key( (string) $field['collapsed'] );
				if ( '' !== $collapsed ) {
					$sub_names = array();
					foreach ( $clean['sub_fields'] ?? array() as $sf ) {
						$sub_names[] = $sf['name'];
					}
					if ( ! in_array( $collapsed, $sub_names, true ) ) {
						/* translators: 1: field position, e.g. 'Field 3', 2: collapsed field name */
						return self::err( sprintf( __( '%1$s: "collapsed" names "%2$s", which is not a sub-field of this repeater.', 'tk-fields' ), $at, $collapsed ) );
					}
					$clean['collapsed'] = $collapsed;
				}
			}
		} elseif ( isset( $field['sub_fields'] ) ) {
			/* translators: %s: field position, e.g. 'Field 3' */
			$warnings[] = sprintf( __( '%1$s: "sub_fields" only applies to group and repeater fields — dropped.', 'tk-fields' ), $at );
		}

		// Flexible-content layouts — mirrors Group_Store::sanitize_layouts().
		if ( 'flexible_content' === $type ) {
			$layouts = $field['layouts'] ?? array();
			if ( ! is_array( $layouts ) ) {
				/* translators: %s: field position, e.g. 'Field 3' */
				return self::err( sprintf( __( '%1$s: "layouts" must be an array.', 'tk-fields' ), $at ) );
			}
			$clean_layouts    = array();
			$seen_layout_keys = array();
			foreach ( $layouts as $li => $layout ) {
				/* translators: 1: field position, e.g. 'Field 3', 2: layout number */
				$layout_at = sprintf( __( '%1$s › layout %2$d', 'tk-fields' ), $at, $li + 1 );
				if ( ! is_array( $layout ) ) {
					/* translators: %s: layout position, e.g. 'Field 3 › layout 1' */
					return self::err( sprintf( __( '%1$s must be an object.', 'tk-fields' ), $layout_at ) );
				}
				$lkey = isset( $layout['key'] ) ? (string) $layout['key'] : '';
				if ( ! preg_match( '/^[a-z0-9_]+$/', $lkey ) ) {
					/* translators: %s: layout position, e.g. 'Field 3 › layout 1' */
					return self::err( sprintf( __( '%1$s needs a "key" (lowercase letters, digits, underscores).', 'tk-fields' ), $layout_at ) );
				}
				if ( isset( $seen_layout_keys[ $lkey ] ) ) {
					/* translators: 1: field position, e.g. 'Field 3', 2: layout key */
					return self::err( sprintf( __( '%1$s: duplicate layout key "%2$s".', 'tk-fields' ), $at, $lkey ) );
				}
				$seen_layout_keys[ $lkey ] = true;

				$llabel = isset( $layout['label'] ) ? sanitize_text_field( (string) $layout['label'] ) : '';
				if ( '' === $llabel ) {
					/* translators: %s: layout position, e.g. 'Field 3 › layout 1' */
					return self::err( sprintf( __( '%1$s needs a "label".', 'tk-fields' ), $layout_at ) );
				}

				$lfields = $layout['fields'] ?? array();
				if ( ! is_array( $lfields ) || array() === $lfields ) {
					/* translators: %s: layout position, e.g. 'Field 3 › layout 1' */
					return self::err( sprintf( __( '%1$s needs at least one sub-field in "fields".', 'tk-fields' ), $layout_at ) );
				}
				foreach ( $lfields as $lf ) {
					if ( ! is_array( $lf ) ) {
						continue;
					}
					$lsub_type = (string) ( $lf['type'] ?? 'text' );
					if ( in_array( $lsub_type, array( 'flexible_content', 'clone' ), true ) ) {
						/* translators: %s: layout position, e.g. 'Field 3 › layout 1' */
						return self::err( sprintf( __( '%1$s: nested flexible_content/clone sub-fields are not supported.', 'tk-fields' ), $layout_at ) );
					}
					if ( ! Field_Registry::stores( $lsub_type ) ) {
						/* translators: %s: layout position, e.g. 'Field 3 › layout 1' */
						return self::err( sprintf( __( '%1$s: layout-only types cannot be layout fields.', 'tk-fields' ), $layout_at ) );
					}
				}

				$result = self::validate_sub_fields( $lfields, $layout_at, 'tk_layout:' . $lkey . ':', $depth, $valid_types, $count, $warnings, $logic_refs, 'layout', $rep_depth );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				$clean_layout = array(
					'key'    => $lkey,
					'label'  => $llabel,
					'fields' => $result,
				);
				foreach ( array( 'min', 'max' ) as $lmkey ) {
					if ( isset( $layout[ $lmkey ] ) && '' !== $layout[ $lmkey ] && null !== $layout[ $lmkey ] ) {
						if ( ! is_numeric( $layout[ $lmkey ] ) ) {
							/* translators: 1: layout position, e.g. 'Field 3 › layout 1', 2: setting key */
							return self::err( sprintf( __( '%1$s: "%2$s" must be a number.', 'tk-fields' ), $layout_at, $lmkey ) );
						}
						$lnum                   = $layout[ $lmkey ] + 0;
						$clean_layout[ $lmkey ] = is_float( $lnum ) ? $lnum : (int) $lnum;
					}
				}
				$clean_layouts[] = $clean_layout;
			}
			if ( array() !== $clean_layouts ) {
				$clean['layouts'] = $clean_layouts;
			}
			if ( isset( $field['button_label'] ) ) {
				$bl = sanitize_text_field( (string) $field['button_label'] );
				if ( '' !== $bl ) {
					$clean['button_label'] = $bl;
				}
			}
		} else {
			if ( isset( $field['layouts'] ) ) {
				/* translators: %s: field position, e.g. 'Field 3' */
				$warnings[] = sprintf( __( '%1$s: "layouts" only applies to flexible_content fields — dropped.', 'tk-fields' ), $at );
			}
			if ( isset( $field['button_label'] ) && 'repeater' !== $type ) {
				/* translators: %s: field position, e.g. 'Field 3' */
				$warnings[] = sprintf( __( '%1$s: "button_label" only applies to flexible_content and repeater fields — dropped.', 'tk-fields' ), $at );
			}
		}

		// layout / collapsed are repeater-only settings.
		if ( 'repeater' !== $type ) {
			if ( isset( $field['layout'] ) ) {
				/* translators: %s: field position, e.g. 'Field 3' */
				$warnings[] = sprintf( __( '%1$s: "layout" only applies to repeater fields — dropped.', 'tk-fields' ), $at );
			}
			if ( isset( $field['collapsed'] ) ) {
				/* translators: %s: field position, e.g. 'Field 3' */
				$warnings[] = sprintf( __( '%1$s: "collapsed" only applies to repeater fields — dropped.', 'tk-fields' ), $at );
			}
		}

		// Clone source — mirrors Group_Store (numeric ID, must exist).
		if ( 'clone' === $type ) {
			$clone_id = absint( $field['clone'] ?? 0 );
			if ( $clone_id <= 0 ) {
				/* translators: %s: field position, e.g. 'Field 3' */
				return self::err( sprintf( __( '%1$s: type "clone" needs a "clone" source group ID.', 'tk-fields' ), $at ) );
			}
			if ( null === Group_Store::instance()->get( $clone_id ) ) {
				/* translators: 1: field position, e.g. 'Field 3', 2: clone source group ID */
				return self::err( sprintf( __( '%1$s: clone source group #%2$d does not exist.', 'tk-fields' ), $at, $clone_id ) );
			}
			$clean['clone'] = $clone_id;
		} elseif ( isset( $field['clone'] ) ) {
			/* translators: %s: field position, e.g. 'Field 3' */
			$warnings[] = sprintf( __( '%1$s: "clone" only applies to clone-type fields — dropped.', 'tk-fields' ), $at );
		}

		return $clean;
	}

	/**
	 * Validate a set of nested sub-fields (group "sub_fields" or layout
	 * "fields"). Mirrors the group store's nested loops: names valid and
	 * unique within the set, nested containers rejected, conditional
	 * logic stripped (v1), missing keys filled deterministically so they
	 * are stable across re-saves — exactly the keys Group_Store::create()
	 * would generate, so the preview matches the write.
	 *
	 * @param mixed    $subs        Raw sub-field array.
	 * @param string   $at          Human position label.
	 * @param string   $key_seed    Deterministic key seed prefix.
	 * @param int      $depth       Current nesting depth.
	 * @param string[] $valid_types Closed type list.
	 * @param int      $count       Total field counter (by ref).
	 * @param string[] $warnings    Warning list (by ref).
	 * @param array    $logic_refs  Logic references (by ref).
	 * @param string   $context     'group', 'layout' or 'repeater' — drives
	 *                              the exclusion rules below.
	 * @param int      $rep_depth   Repeater nesting depth for these sub-fields
	 *                              (threaded through groups, never reset).
	 * @return array|WP_Error Clean sub-fields, or WP_Error (fail closed).
	 */
	private static function validate_sub_fields( $subs, string $at, string $key_seed, int $depth, array $valid_types, int &$count, array &$warnings, array &$logic_refs, string $context, int $rep_depth ): array|\WP_Error {
		if ( ! is_array( $subs ) ) {
			/* translators: %s: field position, e.g. 'Field 3' */
			return self::err( sprintf( __( '%1$s: sub-fields must be an array.', 'tk-fields' ), $at ) );
		}

		$clean       = array();
		$seen_keys   = array();
		$seen_names  = array();

		foreach ( $subs as $j => $sub ) {
			/* translators: 1: field position, e.g. 'Field 3', 2: sub-field number */
			$sub_at = sprintf( __( '%1$s › sub-field %2$d', 'tk-fields' ), $at, $j + 1 );
			if ( ! is_array( $sub ) ) {
				/* translators: %s: sub-field position */
				return self::err( sprintf( __( '%1$s must be an object.', 'tk-fields' ), $sub_at ) );
			}

			$sub_type = (string) ( $sub['type'] ?? 'text' );
			if ( 'group' === $context ) {
				if ( Field_Registry::is_container( $sub_type ) || in_array( $sub_type, array( 'clone', 'flexible_content' ), true ) ) {
					/* translators: %s: field position, e.g. 'Field 3' */
					return self::err( sprintf( __( '%1$s: nested group/clone/flexible sub-fields are not supported.', 'tk-fields' ), $at ) );
				}
			} elseif ( 'repeater' === $context ) {
				// Contract Q3: clone and flexible_content are excluded
				// explicitly (never via the depth counter); layout-only
				// types carry no value. Groups and nested repeaters
				// (depth-capped in validate_field()) are allowed.
				if ( in_array( $sub_type, array( 'clone', 'flexible_content' ), true ) ) {
					/* translators: 1: field position, e.g. 'Field 3', 2: sub-field type */
					return self::err( sprintf( __( '%1$s: "%2$s" cannot be a repeater sub-field.', 'tk-fields' ), $at, $sub_type ) );
				}
			} elseif ( in_array( $sub_type, array( 'flexible_content', 'clone' ), true ) ) {
				/* translators: %s: field position, e.g. 'Field 3' */
				return self::err( sprintf( __( '%1$s: nested flexible_content/clone sub-fields are not supported.', 'tk-fields' ), $at ) );
			}
			// Layout-only types carry no value and can never be sub-fields.
			// (Groups may nest inside repeaters — they add no repeater
			// depth; in group/layout context the checks above already
			// rejected nested groups.)
			$group_allowed = 'repeater' === $context && 'group' === $sub_type;
			if ( ! $group_allowed && ! Field_Registry::stores( $sub_type ) ) {
				/* translators: %s: field position, e.g. 'Field 3' */
				return self::err( sprintf( __( '%1$s: layout-only types cannot be sub-fields.', 'tk-fields' ), $at ) );
			}

			// Name validity checked here (needed for the deterministic key);
			// validate_field() re-checks it against the same rules.
			$sub_name = isset( $sub['name'] ) ? (string) $sub['name'] : '';
			if ( ! preg_match( '/^[a-z0-9_]+$/', $sub_name ) ) {
				/* translators: %s: sub-field position */
				return self::err( sprintf( __( '%1$s needs a valid "name" (lowercase letters, digits, underscores).', 'tk-fields' ), $sub_at ) );
			}

			// Deterministic key when missing/invalid — same algorithm as
			// Group_Store, so the write reproduces this preview exactly.
			if ( empty( $sub['key'] ) || ! is_string( $sub['key'] ) || ! preg_match( '/^f_[A-Za-z0-9_]{1,64}$/', $sub['key'] ) ) {
				$sub['key'] = 'f_' . substr( md5( $key_seed . $sub_name ), 0, 20 );
				/* translators: %s: sub-field position */
				$warnings[] = sprintf( __( '%1$s had a missing or invalid "key" — one was generated automatically.', 'tk-fields' ), $sub_at );
			}

			$clean_sub = self::validate_field( $sub, $sub_at, $depth + 1, $valid_types, $seen_keys, $seen_names, $count, $warnings, $logic_refs, $rep_depth );
			if ( is_wp_error( $clean_sub ) ) {
				return $clean_sub;
			}
			$clean[] = $clean_sub;
		}

		return $clean;
	}

	/**
	 * Build the human-readable preview for a validated payload.
	 *
	 * Each field lists the settings it carries beyond the basics, so the
	 * user sees the import's fidelity before confirming.
	 *
	 * @param array $payload Output of validate()['payload'].
	 * @return array Preview structure for the modal.
	 */
	public static function preview( array $payload ): array {
		$fields = array();
		foreach ( $payload['fields'] as $f ) {
			$fields[] = array(
				'name'     => $f['name'],
				'label'    => $f['label'],
				'type'     => $f['type'],
				'required' => ! empty( $f['required'] ),
				'settings' => self::describe_settings( $f ),
			);
		}

		$locations = array();
		foreach ( $payload['location'] as $rule ) {
			if ( isset( $rule['rules'] ) ) {
				$locations[] = __( '(rule group)', 'tk-fields' );
				continue;
			}
			$locations[] = sprintf( '%s %s %s', $rule['param'], $rule['operator'], $rule['value'] );
		}

		return array(
			'title'          => $payload['title'],
			'title_exists'   => self::title_exists( $payload['title'] ),
			'field_count'    => count( $fields ),
			'fields'         => $fields,
			'locations'      => $locations,
			'location_match' => $payload['location_match'],
		);
	}

	/**
	 * Shorten a preview string to a readable length.
	 *
	 * Uses only mb_strlen/mb_substr (universally polyfilled) — avoids
	 * mb_strimwidth, which is missing from some mbstring polyfills.
	 */
	private static function trim_text( string $text, int $max = 28 ): string {
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		return mb_substr( $text, 0, $max - 1 ) . '…';
	}

	/**
	 * Human-readable list of the non-basic settings a field carries.
	 *
	 * @param array $f Clean field from validate_field().
	 * @return string[] Setting descriptions.
	 */
	private static function describe_settings( array $f ): array {
		$s = array();

		if ( isset( $f['default'] ) ) {
			/* translators: %s: field default value */
			$s[] = sprintf( __( 'default: %1$s', 'tk-fields' ), self::trim_text( (string) $f['default'] ) );
		}
		if ( isset( $f['placeholder'] ) ) {
			/* translators: %s: field placeholder text */
			$s[] = sprintf( __( 'placeholder: %1$s', 'tk-fields' ), self::trim_text( (string) $f['placeholder'] ) );
		}
		if ( isset( $f['message'] ) ) {
			$s[] = __( 'message text', 'tk-fields' );
		}
		if ( ! empty( $f['choices'] ) ) {
			$s[] = sprintf(
				/* translators: %d: choice count */
				__( '%1$d choices', 'tk-fields' ),
				count( $f['choices'] )
			);
		}
		if ( ! empty( $f['multiple'] ) ) {
			$s[] = __( 'multiple', 'tk-fields' );
		}
		if ( ! empty( $f['conditional_logic'] ) ) {
			$s[] = sprintf(
				/* translators: %d: rule count */
				__( 'conditional (%1$d rule%2$s)', 'tk-fields' ),
				count( $f['conditional_logic'] ),
				1 === count( $f['conditional_logic'] ) ? '' : 's'
			);
		}
		foreach ( array( 'min', 'max', 'step' ) as $numkey ) {
			if ( isset( $f[ $numkey ] ) ) {
				$s[] = $numkey . ': ' . $f[ $numkey ];
			}
		}
		if ( isset( $f['maxlength'] ) ) {
			$s[] = 'maxlength: ' . $f['maxlength'];
		}
		if ( ! empty( $f['post_types'] ) ) {
			$s[] = __( 'post types: ', 'tk-fields' ) . implode( ', ', $f['post_types'] );
		}
		if ( ! empty( $f['allow_external'] ) ) {
			$s[] = __( 'external URLs', 'tk-fields' );
		}
		if ( ! empty( $f['taxonomy'] ) ) {
			$s[] = 'taxonomy: ' . $f['taxonomy'];
		}
		if ( ! empty( $f['field_type'] ) ) {
			$s[] = 'field type: ' . $f['field_type'];
		}
		if ( ! empty( $f['save_terms'] ) ) {
			$s[] = __( 'save terms', 'tk-fields' );
		}
		if ( ! empty( $f['load_terms'] ) ) {
			$s[] = __( 'load terms', 'tk-fields' );
		}
		if ( ! empty( $f['roles'] ) ) {
			$s[] = __( 'roles: ', 'tk-fields' ) . implode( ', ', $f['roles'] );
		}
		if ( ! empty( $f['filter_taxonomy'] ) ) {
			$s[] = 'filter: ' . $f['filter_taxonomy'] . ( ! empty( $f['filter_term'] ) ? '/' . $f['filter_term'] : '' );
		}
		if ( ! empty( $f['mime_types'] ) ) {
			$s[] = 'MIME: ' . $f['mime_types'];
		}
		if ( ! empty( $f['image_size'] ) ) {
			$s[] = 'size: ' . $f['image_size'];
		}
		if ( ! empty( $f['toolbar'] ) ) {
			$s[] = 'toolbar: ' . $f['toolbar'];
		}
		if ( ! empty( $f['allow_unfiltered'] ) ) {
			$s[] = __( 'unfiltered HTML', 'tk-fields' );
		}
		if ( ! empty( $f['enable_search'] ) ) {
			$s[] = __( 'map search', 'tk-fields' );
		}
		if ( isset( $f['default_lat'] ) || isset( $f['default_lng'] ) || isset( $f['default_zoom'] ) ) {
			$bits = array();
			if ( isset( $f['default_lat'] ) ) {
				$bits[] = 'lat ' . $f['default_lat'];
			}
			if ( isset( $f['default_lng'] ) ) {
				$bits[] = 'lng ' . $f['default_lng'];
			}
			if ( isset( $f['default_zoom'] ) ) {
				$bits[] = 'zoom ' . $f['default_zoom'];
			}
			$s[] = __( 'map: ', 'tk-fields' ) . implode( ', ', $bits );
		}
		if ( ! empty( $f['sub_fields'] ) ) {
			$s[] = sprintf(
				/* translators: %d: sub-field count */
				__( 'sub-fields: %1$d', 'tk-fields' ),
				count( $f['sub_fields'] )
			);
		}
		if ( ! empty( $f['layouts'] ) ) {
			$s[] = sprintf(
				/* translators: %d: layout count */
				__( 'layouts: %1$d', 'tk-fields' ),
				count( $f['layouts'] )
			);
		}
		if ( ! empty( $f['button_label'] ) ) {
			/* translators: %s: field button label */
			$s[] = sprintf( __( 'button: %1$s', 'tk-fields' ), $f['button_label'] );
		}
		if ( isset( $f['layout'] ) ) {
			$s[] = 'layout: ' . $f['layout'];
		}
		if ( ! empty( $f['collapsed'] ) ) {
			/* translators: %s: collapsed field name */
			$s[] = sprintf( __( 'row summary: %1$s', 'tk-fields' ), $f['collapsed'] );
		}
		if ( ! empty( $f['clone'] ) ) {
			$s[] = sprintf(
				/* translators: %d: group ID */
				__( 'clone of group #%1$d', 'tk-fields' ),
				$f['clone']
			);
		}

		return $s;
	}

	/**
	 * Whether a field group with this title already exists.
	 */
	public static function title_exists( string $title ): bool {
		foreach ( Group_Store::instance()->all() as $group ) {
			if ( 0 === strcasecmp( (string) ( $group['title'] ?? '' ), $title ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Create the group. The payload MUST have passed validate() first;
	 * Group_Store::create() re-validates everything as the final gate.
	 *
	 * @param array  $payload  Validated payload.
	 * @param string $conflict 'create' or 'rename'.
	 * @return array|WP_Error Created group array or WP_Error.
	 */
	public static function create( array $payload, string $conflict = 'create' ): array|\WP_Error {
		if ( 'rename' === $conflict && self::title_exists( $payload['title'] ) ) {
			$base = $payload['title'];
			$i    = 2;
			while ( self::title_exists( $base . ' (' . $i . ')' ) ) {
				++$i;
			}
			$payload['title'] = $base . ' (' . $i . ')';
		}

		return Group_Store::instance()->create( $payload );
	}

	/**
	 * Shorthand WP_Error with 400 status.
	 */
	private static function err( string $message ): \WP_Error {
		return new \WP_Error( 'tk_fields_ai_import_invalid', $message, array( 'status' => 400 ) );
	}

	// ------------------------------------------------------------------
	// REST routes.
	// ------------------------------------------------------------------

	/**
	 * Register the AI import routes. Same capability bar as the rest of
	 * the tk/v1 namespace (manage_options): Authors get 403, logged-out
	 * get 401 — the exact surface pentested in v0.15.1.
	 */
	public static function register_routes(): void {
		register_rest_route(
			'tk/v1',
			'/ai-import/template',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'rest_template' ),
					'permission_callback' => array( self::class, 'permissions_check' ),
				),
			)
		);
		register_rest_route(
			'tk/v1',
			'/ai-import/validate',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'rest_validate' ),
					'permission_callback' => array( self::class, 'permissions_check' ),
				),
			)
		);
		register_rest_route(
			'tk/v1',
			'/ai-import/confirm',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'rest_confirm' ),
					'permission_callback' => array( self::class, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * Permission check: mirrors the tk/v1 namespace bar.
	 */
	public static function permissions_check(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /ai-import/template — the copy-paste template + prompt.
	 */
	public static function rest_template(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'template' => self::template(),
				'prompt'   => self::prompt(),
			),
			200
		);
	}

	/**
	 * POST /ai-import/validate — dry run: decode, validate, preview.
	 * Body: { "json": "<pasted text>" }.
	 */
	public static function rest_validate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$json = $request->get_param( 'json' );
		if ( ! is_string( $json ) || '' === trim( $json ) ) {
			return self::err( __( 'Paste the JSON the AI returned first.', 'tk-fields' ) );
		}

		$result = self::validate( $json );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			array(
				'ok'       => true,
				'preview'  => self::preview( $result['payload'] ),
				'warnings' => $result['warnings'],
				// Echo the validated payload back so confirm() does not
				// need to re-parse untrusted text. It is re-validated
				// server-side anyway before the write.
				'payload'  => $result['payload'],
			),
			200
		);
	}

	/**
	 * POST /ai-import/confirm — create the group.
	 * Body: { "payload": {...validated...}, "conflict": "create"|"rename" }.
	 */
	public static function rest_confirm( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$payload = $request->get_param( 'payload' );
		if ( ! is_array( $payload ) ) {
			return self::err( __( 'Nothing to import — validate the JSON first.', 'tk-fields' ) );
		}

		// Re-validate the echoed payload: never trust the client, even
		// though it came from our own validate step moments ago.
		$recheck = self::validate( (string) wp_json_encode( $payload ) );
		if ( is_wp_error( $recheck ) ) {
			return $recheck;
		}

		$conflict = $request->get_param( 'conflict' );
		$conflict = 'rename' === $conflict ? 'rename' : 'create';

		$created = self::create( $recheck['payload'], $conflict );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		return new \WP_REST_Response(
			array(
				'ok'    => true,
				'group' => array(
					'id'    => $created['id'] ?? 0,
					'title' => $created['title'] ?? '',
				),
			),
			201
		);
	}
}
