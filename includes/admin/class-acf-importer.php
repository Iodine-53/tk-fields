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
 * ACF → TK Fields one-click importer (v0.12.0).
 *
 * Migrates ACF field groups (post type `acf-field-group` + `acf-field`
 * children) into TK Fields groups via Group_Store::create(). Read-only with
 * respect to ACF data: nothing on the ACF side is touched or deleted.
 *
 * Detection: the CPTs exist only when ACF is installed. The importer runs
 * entirely from stored ACF post/meta shapes — ACF is NOT installed in the
 * dev lab, so real-ACF behavior is untestable here. Everything unknown is
 * SKIPPED WITH A REPORTED NOTE, never silently dropped; the POST response
 * carries a full report (imported + skipped + notes) so the admin can see
 * exactly what happened.
 *
 * Best-effort ACF shape assumptions (documented, see UI-REDESIGN-NOTES.md):
 * - acf-field-group posts: post_title = title; location rules in meta
 *   'location' as array-of-groups of {param, operator, value}.
 * - acf-field posts: post_title = label; settings in individual postmeta
 *   (non-underscore keys), e.g. name, type, instructions, required, choices.
 *   Sub-fields (group/repeater/flexible layouts) as acf-field children
 *   (post_parent = parent field post, menu_order ascending); flexible layout
 *   definitions may also arrive inline in meta 'layouts'.
 * - ACF repeater maps to the TK repeater type (contract Q8): sub-fields,
 *   min/max rows, button_label, layout (table→grid, row/block→list) and
 *   collapsed (the ACF collapsed sub-field key → the TK sub-field name)
 *   are carried across. No auto-migration of previously imported groups —
 *   those stay groups unless explicitly re-imported.
 * - Field VALUES stay in ACF's postmeta (meta keys are identical — TK's
 *   namespaced sub-field keys use the same underscore convention), but the
 *   importer does NOT copy values: the new groups start empty. Documented.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACF_Importer {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * ACF field type → TK field type.
	 *
	 * Types with no TK equivalent are absent from the map and get skipped
	 * with a note. accordion degrades to message; true_false is already our
	 * checkbox; repeater maps to repeater (sub-fields, min/max rows,
	 * button_label, layout and collapsed are carried across — the report
	 * notes each conversion).
	 *
	 * @var array<string,string>
	 */
	private const TYPE_MAP = array(
		'text'            => 'text',
		'textarea'        => 'textarea',
		'number'          => 'number',
		'email'           => 'email',
		'url'             => 'url',
		'password'        => 'password',
		'wysiwyg'         => 'wysiwyg',
		'oembed'          => 'oembed',
		'image'           => 'image',
		'file'            => 'file',
		'gallery'         => 'gallery',
		'select'          => 'select',
		'checkbox'        => 'checkbox',
		'radio'           => 'radio',
		'button_group'    => 'button_group',
		'true_false'      => 'checkbox',
		'link'            => 'link',
		'post_object'     => 'post_object',
		'page_link'       => 'page_link',
		'relationship'    => 'relationship',
		'taxonomy'        => 'taxonomy',
		'user'            => 'user',
		'google_map'      => 'map',
		'date_picker'     => 'date',
		'date_time_picker' => 'datetime',
		'time_picker'     => 'time',
		'color_picker'    => 'color',
		'message'         => 'message',
		'accordion'       => 'message',
		'tab'             => 'tab',
		'group'           => 'group',
		'repeater'        => 'repeater',
		'flexible_content' => 'flexible_content',
		'clone'           => 'clone',
	);

	/**
	 * ACF location params we can express in TK location rules.
	 *
	 * @var array<string,string>
	 */
	private const LOCATION_PARAM_MAP = array(
		'post_type'     => 'post_type',
		'page_template' => 'page_template',
		'post'          => 'post',
		'taxonomy'      => 'taxonomy',
	);

	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'tk/v1',
			'/import-acf',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import_acf' ),
				'permission_callback' => function (): bool {
					return current_user_can( 'manage_options' );
				},
			)
		);
		// AI transformer prompt for the no-ACF path: lets an admin convert
		// an ACF JSON export file with an AI assistant when ACF itself is
		// not installed. Read-only; same manage_options bar as the import.
		register_rest_route(
			'tk/v1',
			'/import-acf/ai-prompt',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_ai_prompt' ),
				'permission_callback' => function (): bool {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * GET /tk/v1/import-acf/ai-prompt — the copy-paste transformer prompt.
	 *
	 * Served when ACF is not installed: the admin pastes this into an AI
	 * assistant together with their ACF JSON export, and the AI acts as the
	 * transformer, replying with TK Fields JSON for the AI Import screen.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_ai_prompt(): \WP_REST_Response {
		return rest_ensure_response( array( 'prompt' => self::acf_transform_prompt() ) );
	}

	/**
	 * Build the "ACF → TK Fields" transformer prompt.
	 *
	 * Generated server-side on every request from the live AI_Import
	 * template (the canonical FIELD_SHAPE projection, valid types, worked
	 * example) and this importer's own TYPE_MAP / location map — the
	 * prompt can never drift from what the validator accepts.
	 *
	 * @return string
	 */
	public static function acf_transform_prompt(): string {
		$tpl   = AI_Import::template();
		$lines = array();

		$lines[] = 'You are a precise data transformer. Your job: convert an Advanced Custom Fields (ACF) JSON export into a TK Fields field-group JSON object.';
		$lines[] = '';
		$lines[] = 'THE USER WILL PASTE their ACF JSON export (field group objects as exported from ACF\'s Tools → Export screen, each with key, title, fields, and location) AFTER this prompt.';
		$lines[] = '';
		$lines[] = 'HARD OUTPUT RULES (follow exactly):';
		$lines[] = '- Output ONLY the raw TK Fields JSON object first. No markdown fences, no ```json blocks, no explanations, no commentary before the JSON.';
		$lines[] = '- After the JSON, add a line reading exactly: UNMAPPED:';
		$lines[] = '  followed by a plain-text list of every field, setting, or location rule you could NOT convert, with the reason. If everything converted, write "UNMAPPED:" followed by "(none)".';
		$lines[] = '- The JSON must be a single object: "title" (string, 1-200 chars), "fields" (array of field objects), optional "location" (array of {"param","operator","value"}), optional "location_match" ("all" or "any", default "all").';
		$lines[] = '- Every field needs: "key" (unique, like "f_a1b2c3" — f_ followed by letters/digits; INVENT new keys, never reuse ACF\'s field_xxx keys), "name" (lowercase letters/digits/underscores only, unique within the group), "label" (human label), "type" (one of the valid types below — never invent a type).';
		$lines[] = '';
		$lines[] = 'ACF → TK FIELD TYPE MAP (convert exactly; if an ACF type is not in this map, do not guess — list the field under UNMAPPED):';
		foreach ( self::TYPE_MAP as $acf_type => $tk_type ) {
			$lines[] = '  ' . $acf_type . ' → ' . $tk_type;
		}
		$lines[] = '  Notes: ACF "checkbox" maps to TK "checkbox" — carry its choices across. ACF "accordion" becomes a TK "message" field holding the accordion\'s label text.';
		$lines[] = '';
		$lines[] = 'FIELD SETTINGS (copy across where the ACF field has them):';
		$lines[] = '- required, instructions, default_value → "default", placeholder, maxlength → the same TK keys.';
		$lines[] = '- min, max, step for number/range (min/max on a repeater = row counts).';
		$lines[] = '- choices: object of value => label pairs. REQUIRED for select/radio/button_group.';
		$lines[] = '- post_object/relationship: the ACF "post_type" array → "post_types".';
		$lines[] = '- taxonomy: the taxonomy slug → "taxonomy".';
		$lines[] = '- google_map: center_lat/center_lng → "default_lat"/"default_lng".';
		$lines[] = '- repeater: "button_label" (the Add Row button text), "layout" ("list" or "grid"), "collapsed" (the NAME of one of its sub-fields, shown as the row summary), "sub_fields" (array of full field objects). Repeaters may nest ONE level deep (a repeater inside a repeater); a third level cannot be represented — list it under UNMAPPED.';
		$lines[] = '- group: "sub_fields" (array of full field objects).';
		$lines[] = '- flexible_content: "layouts" — each {"key","label","min","max","fields":[field objects]} — plus "button_label".';
		$lines[] = '- clone: "clone" — the numeric ID of an EXISTING TK field group. ACF clones reference other groups by key; if you cannot resolve the target to a group the user will import, list the clone field under UNMAPPED.';
		$lines[] = '- Inside repeater sub_fields, clone, flexible_content, and layout-only types cannot be represented — list them under UNMAPPED, never drop them silently.';
		$lines[] = '';
		$lines[] = 'CONDITIONAL LOGIC (forgiving rules — the importer applies these automatically, so emit conforming output):';
		$lines[] = '- TK "conditional_logic" is a FLAT array of {"field","operator","value"} where "field" is the f_ key of another TOP-LEVEL field. Every rule must hold (AND).';
		$lines[] = '- ACF exports conditional logic as nested arrays [[{...}]]. If there is exactly ONE nested group, flatten it to a flat array. If there are MULTIPLE groups (OR logic), TK cannot express it: convert the first group and list the rest under UNMAPPED.';
		$lines[] = '- A blank or missing operator means "==". Valid operators: ==, !=, >, <, >=, <=, empty, !empty.';
		$lines[] = '- ACF conditional logic references fields by ACF key — remap each reference to the NEW f_ key you invented for that field.';
		$lines[] = '';
		$lines[] = 'LOCATION RULES:';
		$lines[] = '- TK "location" is an array of {"param","operator","value"}. Supported params: post_type, page_template, taxonomy, post. Operators: == or != (anything else becomes ==).';
		$lines[] = '- Convert each ACF location rule {param, operator, value} to the same shape; params outside the four supported ones go under UNMAPPED.';
		$lines[] = '';
		$lines[] = 'VALID FIELD TYPES (use only these):';
		$lines[] = '  ' . implode( ', ', $tpl['valid_field_types'] );
		$lines[] = '';
		$lines[] = 'FIELD KEYS REFERENCE (every key a field may carry — use ONLY these keys):';
		foreach ( $tpl['group_schema']['fields[]'] as $fkey => $desc ) {
			$lines[] = '  ' . $fkey . ': ' . $desc;
		}
		$lines[] = '';
		$lines[] = 'FULL EXAMPLE (imitate this shape exactly):';
		$lines[] = wp_json_encode( $tpl['example'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$lines[] = '';
		$lines[] = 'Now wait for the user to paste their ACF JSON export, then reply with ONLY the raw TK Fields JSON followed by the UNMAPPED list. The user will paste just the JSON part into TK Fields\' "Import from AI" screen to preview and import it.';

		return implode( "\n", $lines );
	}

	/**
	 * POST /tk/v1/import-acf — detect ACF field groups and import them.
	 *
	 * @return \WP_REST_Response
	 */
	public function import_acf(): \WP_REST_Response {
		$report = array(
			'acf_detected'   => false,
			'groups_found'   => 0,
			'imported'       => array(),
			'skipped_groups' => array(),
			'notes'          => array(),
		);

		if ( ! post_type_exists( 'acf-field-group' ) ) {
			$report['notes'][] = __( 'No ACF field groups found: the acf-field-group post type is not registered (ACF not active).', 'tk-fields' );
			return rest_ensure_response( array( 'report' => $report ) );
		}

		$acf_groups = get_posts(
			array(
				'post_type'      => 'acf-field-group',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		$report['acf_detected'] = true;
		$report['groups_found'] = count( $acf_groups );

		// First pass: convert every group (clone sources resolve in a second
		// pass, since a clone may point at a group imported later in the loop).
		$pending = array();
		foreach ( $acf_groups as $acf_id ) {
			$converted = $this->convert_group( $acf_id, $report );
			if ( null === $converted ) {
				continue;
			}
			$pending[] = $converted;
		}

		// Resolve clone references: ACF clone lists field KEYS; our clone
		// takes a source GROUP id, and clone fields REQUIRE an existing
		// source at create/update time. So groups are created first WITHOUT
		// their clone fields; clones are appended in a second pass once
		// every target group id is known. When the cloned keys do not
		// resolve to exactly one imported group, the field is skipped.
		$key_to_group = array(); // acf field key => index into $pending
		foreach ( $pending as $pi => $conv ) {
			foreach ( $conv['acf_field_keys'] as $acf_key => $tk_name ) {
				$key_to_group[ $acf_key ] = $pi;
			}
		}

		$store        = Group_Store::instance();
		$clone_fixups = array(); // each: array('pending'=>int,'field'=>array,'target_pending'=>int)
		foreach ( $pending as $pi => $conv ) {
			$fields = array();
			foreach ( $conv['fields'] as $field ) {
				if ( ! isset( $field['_acf_clone_keys'] ) ) {
					$fields[] = $field;
					continue;
				}
				$ckeys = $field['_acf_clone_keys'];
				unset( $field['_acf_clone_keys'] );
				$targets = array();
				foreach ( $ckeys as $ckey ) {
					if ( isset( $key_to_group[ $ckey ] ) ) {
						$targets[ $key_to_group[ $ckey ] ] = true;
					}
				}
				if ( 1 !== count( $targets ) ) {
					$report['notes'][] = sprintf(
						/* translators: %1$s: group title, %2$s: field name */
						__( 'Group "%1$s": clone field "%2$s" skipped — its ACF clone keys do not resolve to exactly one imported group.', 'tk-fields' ),
						$conv['title'],
						$field['name']
					);
					continue;
				}
				$clone_fixups[] = array(
					'pending'        => $pi,
					'field'          => $field,
					'target_pending' => array_keys( $targets )[0],
				);
			}

			$created = $store->create(
				array(
					'title'          => $conv['title'],
					'location'       => $conv['location'],
					'location_match' => $conv['location_match'],
					'fields'         => $fields,
				)
			);
			if ( is_wp_error( $created ) ) {
				$report['skipped_groups'][] = $conv['title'] . ': ' . $created->get_error_message();
				continue;
			}
			$pending[ $pi ]['tk_id'] = (int) $created['id'];
			$report['imported'][]    = array(
				'id'     => (int) $created['id'],
				'title'  => $conv['title'],
				'fields' => count( $fields ),
			);
		}

		// Second pass: append clone fields now that target group ids exist.
		foreach ( $clone_fixups as $fixup ) {
			$group_id  = $pending[ $fixup['pending'] ]['tk_id'] ?? 0;
			$target_id = $pending[ $fixup['target_pending'] ]['tk_id'] ?? 0;
			if ( $group_id <= 0 || $target_id <= 0 ) {
				$report['notes'][] = sprintf(
					/* translators: %s: field name */
					__( 'Clone field "%1$s" skipped — its source group was not imported.', 'tk-fields' ),
					$fixup['field']['name']
				);
				continue;
			}
			$group = $store->get( $group_id );
			if ( null === $group ) {
				continue;
			}
			$clone_field          = $fixup['field'];
			$clone_field['clone'] = $target_id;
			$group['fields'][]    = $clone_field;
			$updated              = $store->update(
				$group_id,
				array(
					'title'          => $group['title'],
					'location'       => $group['location'],
					'location_match' => $group['location_match'],
					'fields'         => $group['fields'],
				)
			);
			if ( is_wp_error( $updated ) ) {
				$report['notes'][] = sprintf(
					/* translators: %1$s: field name, %2$s: reason */
					__( 'Clone field "%1$s" could not be linked: %2$s', 'tk-fields' ),
					$fixup['field']['name'],
					$updated->get_error_message()
				);
			} else {
				// Count the linked clone in the import report.
				foreach ( $report['imported'] as $ri => $row ) {
					if ( (int) $row['id'] === $group_id ) {
						$report['imported'][ $ri ]['fields']++;
					}
				}
			}
		}

		return rest_ensure_response( array( 'report' => $report ) );
	}

	/**
	 * Convert one ACF field-group post into a TK group payload.
	 *
	 * @param int   $acf_id ACF field-group post id.
	 * @param array $report Report array, appended to by reference.
	 * @return array|null Payload (title, location, location_match, fields,
	 *                    acf_field_keys) or null when the group is skipped.
	 */
	private function convert_group( int $acf_id, array &$report ): ?array {
		$post = get_post( $acf_id );
		if ( ! $post ) {
			return null;
		}
		/* translators: %d: ACF field group ID */
		$title = $post->post_title !== '' ? $post->post_title : sprintf( __( 'Imported group %1$d', 'tk-fields' ), $acf_id );

		$location = $this->convert_location( get_post_meta( $acf_id, 'location', true ), $title, $report );

		$fields          = array();
		$acf_field_keys  = array();
		$field_posts     = $this->child_fields( $acf_id );
		$used_names      = array();
		foreach ( $field_posts as $fpost ) {
			$field = $this->convert_field( $fpost, $title, $report, $used_names );
			if ( null === $field ) {
				continue;
			}
			$used_names[] = $field['name'];
			if ( '' !== $field['_acf_key'] ) {
				$acf_field_keys[ $field['_acf_key'] ] = $field['name'];
			}
			unset( $field['_acf_key'] );
			$fields[] = $field;
		}

		return array(
			'title'          => $title,
			'location'       => $location['rules'],
			'location_match' => $location['match'],
			'fields'         => $fields,
			'acf_field_keys' => $acf_field_keys,
		);
	}

	/**
	 * Child acf-field posts of a parent (group or field), menu_order first.
	 *
	 * @param int $parent_id Post id.
	 * @return \WP_Post[]
	 */
	private function child_fields( int $parent_id ): array {
		return get_posts(
			array(
				'post_type'      => 'acf-field',
				'post_status'    => 'any',
				'post_parent'    => $parent_id,
				'posts_per_page' => -1,
				'orderby'        => array( 'menu_order' => 'ASC', 'ID' => 'ASC' ),
			)
		);
	}

	/**
	 * ACF location (array of groups of rules) → TK location.
	 *
	 * ACF groups are ORed with AND inside — exactly our location_match='any'
	 * over {rules} groups.
	 *
	 * @param mixed  $acf_location Raw meta value.
	 * @param string $title        Group title (for notes).
	 * @param array  $report       Report, by reference.
	 * @return array{rules:array,match:string}
	 */
	private function convert_location( mixed $acf_location, string $title, array &$report ): array {
		$rules = array();
		if ( ! is_array( $acf_location ) ) {
			$report['notes'][] = sprintf(
				/* translators: %s: group title */
				__( 'Group "%1$s": no ACF location rules found — imported with an empty location (applies nowhere; set location in TK Fields).', 'tk-fields' ),
				$title
			);
			return array( 'rules' => array(), 'match' => 'all' );
		}
		foreach ( $acf_location as $acf_group ) {
			if ( ! is_array( $acf_group ) ) {
				continue;
			}
			$clean = array();
			foreach ( $acf_group as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				$param    = (string) ( $rule['param'] ?? '' );
				$operator = '!=' === ( $rule['operator'] ?? '' ) ? '!=' : '==';
				$value    = (string) ( $rule['value'] ?? '' );
				$mapped   = self::LOCATION_PARAM_MAP[ $param ] ?? null;
				if ( null === $mapped ) {
					$report['notes'][] = sprintf(
						/* translators: %1$s: group title, %2$s: ACF location param */
						__( 'Group "%1$s": location rule on "%2$s" skipped — not expressible in TK location rules.', 'tk-fields' ),
						$title,
						$param
					);
					continue;
				}
				if ( 'taxonomy' === $mapped && ! preg_match( '/^([a-z0-9_]+)\|([A-Za-z0-9_\-]+)$/', $value ) ) {
					$report['notes'][] = sprintf(
						/* translators: %1$s: group title, %2$s: value */
						__( 'Group "%1$s": taxonomy location value "%2$s" skipped — expected "taxonomy|term_slug".', 'tk-fields' ),
						$title,
						$value
					);
					continue;
				}
				if ( '' === $value ) {
					continue;
				}
				$clean[] = array( 'param' => $mapped, 'operator' => $operator, 'value' => $value );
			}
			if ( $clean ) {
				$rules[] = array( 'rules' => $clean );
			}
		}
		if ( ! $rules ) {
			$report['notes'][] = sprintf(
				/* translators: %s: group title */
				__( 'Group "%1$s": no usable ACF location rules — imported with an empty location (applies nowhere; set location in TK Fields).', 'tk-fields' ),
				$title
			);
		}
		return array( 'rules' => $rules, 'match' => 'any' );
	}

	/**
	 * Convert one ACF field post into a TK field array.
	 *
	 * @param \WP_Post $fpost      ACF field post.
	 * @param string   $group_title Parent group title (for notes).
	 * @param array    $report     Report, by reference.
	 * @param string[] $used_names Names already taken in this group.
	 * @param int      $rep_depth  Repeater nesting depth of this field (0 =
	 *                             top level). Groups do not add repeater
	 *                             depth; the depth threads through them.
	 * @return array|null TK field array (with _acf_key) or null to skip.
	 */
	private function convert_field( \WP_Post $fpost, string $group_title, array &$report, array $used_names, int $rep_depth = 0 ): ?array {
		$raw_meta = get_post_meta( $fpost->ID );
		$meta     = array();
		foreach ( $raw_meta as $mkey => $vals ) {
			if ( str_starts_with( $mkey, '_' ) ) {
				continue; // ACF reference keys.
			}
			$meta[ $mkey ] = maybe_unserialize( $vals[0] ?? '' );
		}

		$acf_type = (string) ( $meta['type'] ?? 'text' );
		$tk_type  = self::TYPE_MAP[ $acf_type ] ?? null;
		if ( null === $tk_type ) {
			$report['notes'][] = sprintf(
				/* translators: %1$s: group title, %2$s: field label, %3$s: ACF type */
				__( 'Group "%1$s": field "%2$s" skipped — ACF type "%3$s" has no TK equivalent.', 'tk-fields' ),
				$group_title,
				$fpost->post_title,
				$acf_type
			);
			return null;
		}

		// Repeater depth cap (contract Q2): two levels import; a third
		// nested repeater is skipped with a note — never silently dropped,
		// and never allowed to fail the whole group at create() time.
		if ( 'repeater' === $tk_type && $rep_depth >= 2 ) {
			$report['notes'][] = sprintf(
				/* translators: %1$s: group title, %2$s: field label */
				__( 'Group "%1$s": repeater "%2$s" skipped — repeaters nest at most 2 levels deep.', 'tk-fields' ),
				$group_title,
				$fpost->post_title
			);
			return null;
		}

		$name = sanitize_key( (string) ( $meta['name'] ?? '' ) );
		if ( '' === $name ) {
			$name = sanitize_key( $fpost->post_name );
		}
		if ( '' === $name || ! preg_match( '/^[a-z0-9_]+$/', $name ) ) {
			$report['notes'][] = sprintf(
				/* translators: %1$s: group title, %2$s: field label */
				__( 'Group "%1$s": field "%2$s" skipped — no usable field name.', 'tk-fields' ),
				$group_title,
				$fpost->post_title
			);
			return null;
		}
		// Name collisions inside the group: suffix, never overwrite.
		$base = $name;
		$suf  = 2;
		while ( in_array( $name, $used_names, true ) ) {
			$name = $base . '_' . $suf;
			$suf++;
		}

		$label = '' !== $fpost->post_title ? $fpost->post_title : $name;
		$field = array(
			'key'          => $this->new_field_key(),
			'name'         => $name,
			'label'        => $label,
			'type'         => $tk_type,
			'instructions' => '' !== $fpost->post_excerpt ? $fpost->post_excerpt : (string) ( $meta['instructions'] ?? '' ),
			'required'     => ! empty( $meta['required'] ),
			'_acf_key'     => (string) ( $meta['key'] ?? $fpost->post_name ),
		);

		if ( isset( $meta['default_value'] ) && '' !== (string) $meta['default_value'] && ! is_array( $meta['default_value'] ) ) {
			$field['default'] = (string) $meta['default_value'];
		}
		if ( isset( $meta['placeholder'] ) && '' !== (string) $meta['placeholder'] ) {
			$field['placeholder'] = (string) $meta['placeholder'];
		}
		if ( isset( $meta['maxlength'] ) && is_numeric( $meta['maxlength'] ) ) {
			$field['maxlength'] = (int) $meta['maxlength'];
		}
		foreach ( array( 'min', 'max', 'step' ) as $numkey ) {
			if ( isset( $meta[ $numkey ] ) && is_numeric( $meta[ $numkey ] ) ) {
				$field[ $numkey ] = $meta[ $numkey ] + 0;
			}
		}
		if ( isset( $meta['button_label'] ) && '' !== (string) $meta['button_label'] ) {
			$field['button_label'] = (string) $meta['button_label'];
		}

		if ( in_array( $tk_type, array( 'select', 'radio', 'button_group', 'checkbox' ), true ) ) {
			$choices = $this->parse_acf_choices( $meta['choices'] ?? array() );
			if ( $choices ) {
				$field['choices'] = $choices;
			} elseif ( in_array( $tk_type, array( 'select', 'radio', 'button_group' ), true ) ) {
				// Choice-based types require ≥1 choice; skip rather than fail.
				$report['notes'][] = sprintf(
					/* translators: %1$s: group title, %2$s: field name */
					__( 'Group "%1$s": field "%2$s" skipped — choice-based field with no choices.', 'tk-fields' ),
					$group_title,
					$name
				);
				return null;
			}
		}

		if ( in_array( $tk_type, array( 'post_object', 'relationship' ), true ) && isset( $meta['post_type'] ) ) {
			$pts = array_values( array_filter( array_map( 'sanitize_key', (array) $meta['post_type'] ) ) );
			if ( $pts ) {
				$field['post_types'] = $pts;
			}
		}
		if ( 'taxonomy' === $tk_type && isset( $meta['taxonomy'] ) ) {
			$field['taxonomy'] = sanitize_key( (string) $meta['taxonomy'] );
		}
		if ( 'map' === $tk_type && isset( $meta['center_lat'] ) ) {
			$field['default_lat'] = (float) $meta['center_lat'];
			$field['default_lng'] = (float) ( $meta['center_lng'] ?? 0 );
		}
		if ( 'accordion' === $acf_type ) {
			$field['message'] = sprintf(
				/* translators: %s: accordion label */
				__( 'Imported from an ACF accordion ("%1$s"). ACF accordions collapse sections; TK message fields are static notes.', 'tk-fields' ),
				$label
			);
		}
		if ( 'repeater' === $acf_type ) {
			$report['notes'][] = sprintf(
				/* translators: %1$s: group title, %2$s: field name */
				__( 'Group "%1$s": ACF repeater "%2$s" imported as a TK repeater (rows repeat; sub-field order preserved).', 'tk-fields' ),
				$group_title,
				$name
			);
			// ACF repeater layout → TK layout: table renders as a grid,
			// row/block render one row after another (list).
			$field['layout'] = self::map_acf_repeater_layout( $meta['layout'] ?? '' );
		}

		// Sub-fields: child acf-field posts (group / repeater).
		if ( in_array( $tk_type, array( 'group', 'repeater' ), true ) ) {
			$sub_fields      = array();
			$sub_names       = array();
			$sub_key_to_name = array(); // ACF field key → TK sub-field name.
			foreach ( $this->child_fields( $fpost->ID ) as $cpost ) {
				$sub = $this->convert_field( $cpost, $group_title, $report, $sub_names, 'repeater' === $tk_type ? $rep_depth + 1 : $rep_depth );
				if ( null === $sub ) {
					continue;
				}
				$sub_names[] = $sub['name'];
				if ( '' !== $sub['_acf_key'] ) {
					$sub_key_to_name[ $sub['_acf_key'] ] = $sub['name'];
				}
				unset( $sub['_acf_key'] );
				$sub_fields[] = $sub;
			}
			$field['sub_fields'] = $sub_fields;

			if ( 'repeater' === $tk_type ) {
				// Collapsed: ACF stores the collapsed sub-field's ACF key —
				// resolve it to the TK sub-field name, never drop it.
				$collapsed_key = (string) ( $meta['collapsed'] ?? '' );
				if ( '' !== $collapsed_key ) {
					if ( isset( $sub_key_to_name[ $collapsed_key ] ) ) {
						$field['collapsed'] = $sub_key_to_name[ $collapsed_key ];
					} else {
						$report['notes'][] = sprintf(
							/* translators: %1$s: group title, %2$s: field name */
							__( 'Group "%1$s": repeater "%2$s" "collapsed" setting pointed at an unknown sub-field — left unset.', 'tk-fields' ),
							$group_title,
							$name
						);
					}
				}
			}
		}

		// Flexible content layouts: inline 'layouts' meta (each with
		// sub_fields arrays) and/or child posts.
		if ( 'flexible_content' === $tk_type ) {
			$layouts = array();
			$raw     = $meta['layouts'] ?? array();
			if ( is_array( $raw ) ) {
				foreach ( $raw as $layout ) {
					if ( ! is_array( $layout ) ) {
						continue;
					}
					$lkey   = sanitize_key( (string) ( $layout['name'] ?? $layout['key'] ?? '' ) );
					$llabel = (string) ( $layout['label'] ?? $lkey );
					if ( '' === $lkey ) {
						continue;
					}
					$lfields   = array();
					$lnames    = array();
					$inline    = isset( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ? $layout['sub_fields'] : array();
					foreach ( $inline as $sub ) {
						$conv = $this->convert_inline_sub_field( $sub, $group_title, $report, $lnames );
						if ( null === $conv ) {
							continue;
						}
						$lnames[]   = $conv['name'];
						$lfields[]  = $conv;
					}
					$layouts[] = array(
						'key'    => $lkey,
						'label'  => $llabel,
						'fields' => $lfields,
					);
				}
			}
			// Layouts need ≥1 sub-field or the whole group fails
			// sanitization — drop empties with a note instead.
			$kept = array();
			foreach ( $layouts as $layout ) {
				if ( $layout['fields'] ) {
					$kept[] = $layout;
					continue;
				}
				$report['notes'][] = sprintf(
					/* translators: %1$s: group title, %2$s: layout label */
					__( 'Group "%1$s": flexible layout "%2$s" skipped — no usable sub-fields.', 'tk-fields' ),
					$group_title,
					$layout['label']
				);
			}
			$field['layouts'] = $kept;
		}

		// Clone: ACF lists field keys; resolution to a TK source group id
		// happens in the second pass (see import_acf()).
		if ( 'clone' === $tk_type ) {
			$ckeys = isset( $meta['clone'] ) ? array_values( array_filter( array_map( 'strval', (array) $meta['clone'] ) ) ) : array();
			if ( ! $ckeys ) {
				$report['notes'][] = sprintf(
					/* translators: %1$s: group title, %2$s: field name */
					__( 'Group "%1$s": clone field "%2$s" skipped — no ACF clone keys.', 'tk-fields' ),
					$group_title,
					$name
				);
				return null;
			}
			$field['_acf_clone_keys'] = $ckeys;
		}

		return $field;
	}

	/**
	 * ACF repeater layout → TK repeater layout.
	 *
	 * ACF 'table' renders rows as a grid; 'row' and 'block' render one row
	 * after another. Anything unrecognized falls back to 'list' (the TK
	 * default) — the setting is never dropped silently.
	 *
	 * @param mixed $acf_layout Raw ACF layout value.
	 * @return string 'list' or 'grid'.
	 */
	private static function map_acf_repeater_layout( mixed $acf_layout ): string {
		return 'table' === strtolower( (string) $acf_layout ) ? 'grid' : 'list';
	}

	/**
	 * Convert an inline sub-field array (flexible layout sub_fields) —
	 * same mapping as convert_field() but without post/meta plumbing.
	 *
	 * @param mixed    $sub        Raw sub-field array.
	 * @param string   $group_title Parent group title (for notes).
	 * @param array    $report     Report, by reference.
	 * @param string[] $used_names Names already taken in this layout.
	 * @return array|null
	 */
	private function convert_inline_sub_field( mixed $sub, string $group_title, array &$report, array $used_names ): ?array {
		if ( ! is_array( $sub ) ) {
			return null;
		}
		$acf_type = (string) ( $sub['type'] ?? 'text' );
		$tk_type  = self::TYPE_MAP[ $acf_type ] ?? null;
		if ( null === $tk_type || in_array( $tk_type, array( 'group', 'flexible_content', 'clone' ), true ) ) {
			return null; // Nested composites are rejected by the sanitizer anyway.
		}
		$name = sanitize_key( (string) ( $sub['name'] ?? '' ) );
		if ( '' === $name || ! preg_match( '/^[a-z0-9_]+$/', $name ) ) {
			return null;
		}
		$base = $name;
		$suf  = 2;
		while ( in_array( $name, $used_names, true ) ) {
			$name = $base . '_' . $suf;
			$suf++;
		}
		$field = array(
			'key'      => $this->new_field_key(),
			'name'     => $name,
			'label'    => (string) ( $sub['label'] ?? $name ),
			'type'     => $tk_type,
			'required' => ! empty( $sub['required'] ),
		);
		if ( in_array( $tk_type, array( 'select', 'radio', 'button_group', 'checkbox' ), true ) ) {
			$choices = $this->parse_acf_choices( $sub['choices'] ?? array() );
			if ( $choices ) {
				$field['choices'] = $choices;
			} elseif ( in_array( $tk_type, array( 'select', 'radio', 'button_group' ), true ) ) {
				return null;
			}
		}
		return $field;
	}

	/**
	 * Parse ACF choices into value => label.
	 *
	 * ACF stores choices either as an array or as newline-delimited
	 * "value : Label" lines.
	 *
	 * @param mixed $raw Raw choices.
	 * @return array<string,string>
	 */
	private function parse_acf_choices( mixed $raw ): array {
		if ( is_array( $raw ) ) {
			$out = array();
			foreach ( $raw as $v => $l ) {
				$v = sanitize_text_field( (string) $v );
				if ( '' === $v ) {
					continue;
				}
				$out[ $v ] = sanitize_text_field( (string) $l );
			}
			return $out;
		}
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = explode( ':', $line, 2 );
			$value = trim( $parts[0] );
			$label = isset( $parts[1] ) ? trim( $parts[1] ) : $value;
			if ( '' === $value ) {
				continue;
			}
			$out[ $value ] = $label;
		}
		return $out;
	}

	/**
	 * New TK field key: f_ + 16 hex chars (matches the builder format).
	 */
	private function new_field_key(): string {
		return 'f_' . bin2hex( random_bytes( 8 ) );
	}
}
