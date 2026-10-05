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
 * AI import + ACF importer: repeater field type tests — run from the lab root:
 *
 *   ./bin/wp --allow-root --path=www eval-file \
 *     wp-content/plugins/tk-fields/tests/test-ai-import-repeater.php
 *
 * Exits 0 when every assertion passes, 1 otherwise. Read-only: payloads go
 * through AI_Import::validate() and AI_Import::preview() only — no group is
 * ever written (Group_Store::create() for repeater is contract 3, owned by a
 * sibling workstream). ACF end-to-end conversion is untestable here (ACF is
 * not installed in the lab); the ACF importer is covered at the mapping
 * level via reflection.
 *
 * Contracts under test (consultations/repeater-field-synthesis.md, Q7/Q8):
 * - acceptance: the Course Curriculum JSON validates with ZERO dropped
 *   settings and ZERO warnings
 * - sub_fields recursive through depth 2, zero dropped keys on the way
 * - depth 3+ nesting = hard error (never silent drop)
 * - clone / flexible_content / layout-only types as repeater sub-fields =
 *   hard error (ChatGPT's trap: unsupported fields must never disappear)
 * - min/max validated as row counts on the repeater; button_label kept as
 *   string; layout restricted to list|grid; collapsed must name a real
 *   sub-field (hard error on a dangling reference)
 * - preview Settings column carries the repeater settings
 * - ACF importer: repeater → repeater (type map, layout + collapsed mapping)
 *
 * NOTE: no `declare(strict_types=1)` here — wp-cli's eval-file wraps this in
 * eval(), where a declare is not the first statement and fatals.
 */

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$failures = 0;

/**
 * @param bool   $cond
 * @param string $label
 */
$check = function ( bool $cond, string $label ) use ( &$failures ): void {
	if ( $cond ) {
		echo "PASS: {$label}\n";
	} else {
		$failures++;
		echo "FAIL: {$label}\n";
	}
};

/**
 * Sorted key list, for exact key-set assertions (zero dropped keys).
 *
 * @param array $arr
 * @return string[]
 */
$keyset = function ( array $arr ): array {
	$keys = array_keys( $arr );
	sort( $keys );
	return $keys;
};

// Prerequisite: the registry workstream (contract 1) must have landed —
// the validator's closed type list comes from the registry.
if ( ! in_array( 'repeater', AI_Import::valid_types(), true ) ) {
	echo "BLOCKED: 'repeater' is not in AI_Import::valid_types() — registry contract 1 has not landed; cannot test repeater import.\n";
	exit( 1 );
}
$check( true, "repeater is a valid AI-import type (registry contract 1)" );

/**
 * The acceptance group: Course Curriculum & Modules.
 *
 * @return array
 */
$acceptance_group = function (): array {
	return array(
		'title'    => 'Course Curriculum & Modules',
		'location' => array(
			array(
				'param'    => 'post_type',
				'operator' => '==',
				'value'    => 'post',
			),
		),
		'fields'   => array(
			array(
				'key'          => 'f_crse02rep',
				'name'         => 'course_modules',
				'label'        => 'Course Modules',
				'type'         => 'repeater',
				'min'          => 1,
				'max'          => 10,
				'button_label' => 'Add New Module',
				'sub_fields'   => array(
					array(
						'key'      => 'f_crse02a',
						'name'     => 'module_title',
						'label'    => 'Module Title',
						'type'     => 'text',
						'required' => true,
					),
					array(
						'key'   => 'f_crse02b',
						'name'  => 'estimated_hours',
						'label' => 'Estimated Hours',
						'type'  => 'number',
						'min'   => 1,
						'max'   => 24,
					),
					array(
						'key'        => 'f_crse02c',
						'name'       => 'lessons',
						'label'      => 'Lessons',
						'type'       => 'repeater',
						'sub_fields' => array(
							array(
								'key'      => 'f_crse02d',
								'name'     => 'lesson_title',
								'label'    => 'Lesson Title',
								'type'     => 'text',
								'required' => true,
							),
							array(
								'key'   => 'f_crse02e',
								'name'  => 'video_url',
								'label' => 'Video URL',
								'type'  => 'url',
							),
							array(
								'key'   => 'f_crse02f',
								'name'  => 'free_preview',
								'label' => 'Free Preview',
								'type'  => 'checkbox',
							),
						),
					),
				),
			),
		),
	);
};

/**
 * Validate a group array as pasted AI JSON.
 *
 * @param array $group
 * @return array|\WP_Error
 */
$validate_group = function ( array $group ) {
	return AI_Import::validate( wp_json_encode( $group ) );
};

// ------------------------------------------------------------------
// 1. Acceptance: Course Curriculum JSON — zero dropped, zero warnings.
// ------------------------------------------------------------------
$result = $validate_group( $acceptance_group() );
$check( ! is_wp_error( $result ), 'acceptance JSON validates' );
if ( is_wp_error( $result ) ) {
	echo '  validator error: ' . $result->get_error_message() . "\n";
	exit( 1 );
}
$check( array() === $result['warnings'], 'acceptance JSON: zero warnings' );
foreach ( $result['warnings'] as $w ) {
	echo "  unexpected warning: {$w}\n";
}

$payload = $result['payload'];
$f       = $payload['fields'][0];
$check( 'repeater' === $f['type'], 'acceptance: top field is a repeater' );
$check( 1 === ( $f['min'] ?? null ) && 10 === ( $f['max'] ?? null ), 'acceptance: min/max row counts carried (1/10)' );
$check( 'Add New Module' === ( $f['button_label'] ?? null ), 'acceptance: button_label carried' );
$check( 'list' === ( $f['layout'] ?? null ), 'acceptance: layout defaults to list' );
$check( 3 === count( $f['sub_fields'] ?? array() ), 'acceptance: 3 sub-fields carried' );

// Zero dropped keys on the repeater itself: exact key set.
$check(
	array( 'button_label', 'key', 'label', 'layout', 'max', 'min', 'name', 'required', 'sub_fields', 'type' ) === $keyset( $f ),
	'acceptance: zero dropped keys on the repeater (exact key set)'
);

$subs    = array();
foreach ( $f['sub_fields'] as $s ) {
	$subs[ $s['name'] ] = $s;
}
$check( true === ( $subs['module_title']['required'] ?? null ), 'acceptance: module_title required carried' );
$check(
	array( 'key', 'label', 'name', 'required', 'type' ) === $keyset( $subs['module_title'] ),
	'acceptance: zero dropped keys on module_title'
);
$check(
	1 === ( $subs['estimated_hours']['min'] ?? null ) && 24 === ( $subs['estimated_hours']['max'] ?? null ),
	'acceptance: estimated_hours min/max carried (1/24)'
);
$check(
	array( 'key', 'label', 'max', 'min', 'name', 'required', 'type' ) === $keyset( $subs['estimated_hours'] ),
	'acceptance: zero dropped keys on estimated_hours'
);

$lessons = $subs['lessons'];
$check( 'repeater' === ( $lessons['type'] ?? null ), 'acceptance: nested repeater type carried' );
$check(
	array( 'key', 'label', 'layout', 'name', 'required', 'sub_fields', 'type' ) === $keyset( $lessons ),
	'acceptance: zero dropped keys on the nested repeater'
);
$lsubs = array();
foreach ( $lessons['sub_fields'] as $s ) {
	$lsubs[ $s['name'] ] = $s;
}
$check( true === ( $lsubs['lesson_title']['required'] ?? null ), 'acceptance: lesson_title required carried' );
$check( 'url' === ( $lsubs['video_url']['type'] ?? null ), 'acceptance: video_url type carried' );
$check( 'checkbox' === ( $lsubs['free_preview']['type'] ?? null ), 'acceptance: free_preview type carried' );
$check(
	array( 'key', 'label', 'name', 'required', 'type' ) === $keyset( $lsubs['lesson_title'] ),
	'acceptance: zero dropped keys on lesson_title'
);

// Preview Settings column carries the repeater settings.
$preview   = AI_Import::preview( $payload );
$pf        = $preview['fields'][0];
$settings  = implode( ' | ', $pf['settings'] );
$check( str_contains( $settings, 'min: 1' ), 'preview settings: min: 1' );
$check( str_contains( $settings, 'max: 10' ), 'preview settings: max: 10' );
$check( str_contains( $settings, 'button: Add New Module' ), 'preview settings: button label' );
$check( str_contains( $settings, 'layout: list' ), 'preview settings: layout' );
$check( str_contains( $settings, 'sub-fields: 3' ), 'preview settings: sub-field count' );

// ------------------------------------------------------------------
// 2. Negative cases: every violation is a hard error, never a drop.
// ------------------------------------------------------------------

/**
 * Validate a single repeater field inside a throwaway group.
 *
 * @param array $repeater_field
 * @return array|\WP_Error
 */
$validate_repeater = function ( array $repeater_field ) use ( $validate_group ) {
	$g           = array(
		'title'    => 'Repeater Import Probe',
		'location' => array(),
		'fields'   => array( $repeater_field ),
	);
	return $validate_group( $g );
};

/**
 * Minimal repeater field with overrides and custom sub-fields.
 *
 * @param array      $overrides
 * @param array|null $subs
 * @return array
 */
$rep = function ( array $overrides = array(), ?array $subs = null ): array {
	$f = array(
		'key'        => 'f_probe1',
		'name'       => 'probe',
		'label'      => 'Probe',
		'type'       => 'repeater',
		'sub_fields' => null === $subs ? array(
			array(
				'key'   => 'f_probe2',
				'name'  => 'title',
				'label' => 'Title',
				'type'  => 'text',
			),
		) : $subs,
	);
	return array_merge( $f, $overrides );
};

// Depth 3 nesting → hard error.
$deep = $rep(
	array(),
	array(
		array(
			'key'        => 'f_deep1',
			'name'       => 'level2',
			'label'      => 'Level 2',
			'type'       => 'repeater',
			'sub_fields' => array(
				array(
					'key'        => 'f_deep2',
					'name'       => 'level3',
					'label'      => 'Level 3',
					'type'       => 'repeater',
					'sub_fields' => array(
						array(
							'key'   => 'f_deep3',
							'name'  => 'leaf',
							'label' => 'Leaf',
							'type'  => 'text',
						),
					),
				),
			),
		),
	)
);
$r = $validate_repeater( $deep );
$check( is_wp_error( $r ) && str_contains( $r->get_error_message(), '2 levels' ), 'depth-3 nesting is a hard error' );

// clone as a repeater sub-field → hard error.
$r = $validate_repeater(
	$rep(
		array(),
		array(
			array(
				'key'   => 'f_bad1',
				'name'  => 'bad_clone',
				'label' => 'Bad Clone',
				'type'  => 'clone',
				'clone' => 999999,
			),
		)
	)
);
$check( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'cannot be a repeater sub-field' ), 'clone as repeater sub-field is a hard error' );

// flexible_content as a repeater sub-field → hard error.
$r = $validate_repeater(
	$rep(
		array(),
		array(
			array(
				'key'   => 'f_bad2',
				'name'  => 'bad_flex',
				'label' => 'Bad Flex',
				'type'  => 'flexible_content',
			),
		)
	)
);
$check( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'cannot be a repeater sub-field' ), 'flexible_content as repeater sub-field is a hard error' );

// layout-only type (message) as a repeater sub-field → hard error.
$r = $validate_repeater(
	$rep(
		array(),
		array(
			array(
				'key'   => 'f_bad3',
				'name'  => 'bad_msg',
				'label' => 'Bad Message',
				'type'  => 'message',
			),
		)
	)
);
$check( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'layout-only' ), 'layout-only type as repeater sub-field is a hard error' );

// collapsed naming a nonexistent sub-field → hard error (no dangling ref).
$r = $validate_repeater( $rep( array( 'collapsed' => 'nope' ) ) );
$check( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'collapsed' ), 'collapsed naming an unknown sub-field is a hard error' );

// collapsed naming a real sub-field → kept.
$r = $validate_repeater( $rep( array( 'collapsed' => 'title' ) ) );
$check( ! is_wp_error( $r ) && 'title' === ( $r['payload']['fields'][0]['collapsed'] ?? null ), 'collapsed naming a real sub-field is kept' );

// layout outside list|grid → hard error; grid → kept.
$r = $validate_repeater( $rep( array( 'layout' => 'table' ) ) );
$check( is_wp_error( $r ) && str_contains( $r->get_error_message(), '"layout"' ), 'layout "table" is a hard error' );
$r = $validate_repeater( $rep( array( 'layout' => 'grid' ) ) );
$check( ! is_wp_error( $r ) && 'grid' === ( $r['payload']['fields'][0]['layout'] ?? null ), 'layout "grid" is kept' );

// min/max are row counts: fractional / negative / min>max → hard errors.
$r = $validate_repeater( $rep( array( 'min' => 1.5 ) ) );
$check( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'row count' ), 'fractional min on repeater is a hard error' );
$r = $validate_repeater( $rep( array( 'min' => -1 ) ) );
$check( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'row count' ), 'negative min on repeater is a hard error' );
$r = $validate_repeater( $rep( array( 'min' => 5, 'max' => 2 ) ) );
$check( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'cannot exceed' ), 'min > max on repeater is a hard error' );

// group as a repeater sub-field is allowed (Q3 excludes only clone,
// flexible_content and layout-only types; groups add no repeater depth).
$r = $validate_repeater(
	$rep(
		array(),
		array(
			array(
				'key'        => 'f_grp1',
				'name'       => 'grp',
				'label'      => 'Grp',
				'type'       => 'group',
				'sub_fields' => array(
					array(
						'key'   => 'f_grp2',
						'name'  => 'inner',
						'label' => 'Inner',
						'type'  => 'text',
					),
				),
			),
		)
	)
);
$check( ! is_wp_error( $r ), 'group as repeater sub-field is allowed' );

// step on a repeater: warned and dropped (per-value setting, not a row setting).
$r = $validate_repeater( $rep( array( 'step' => 2 ) ) );
$check(
	! is_wp_error( $r )
	&& 1 === count( $r['warnings'] )
	&& str_contains( $r['warnings'][0], '"step"' )
	&& ! isset( $r['payload']['fields'][0]['step'] ),
	'step on repeater is warned and dropped'
);

// button_label on a non-container type: warned, names BOTH owner types.
$g            = array(
	'title'    => 'Repeater Import Probe',
	'location' => array(),
	'fields'   => array(
		array(
			'key'          => 'f_btn1',
			'name'         => 'plain',
			'label'        => 'Plain',
			'type'         => 'text',
			'button_label' => 'Add',
		),
	),
);
$r = $validate_group( $g );
$check(
	! is_wp_error( $r )
	&& 1 === count( $r['warnings'] )
	&& str_contains( $r['warnings'][0], 'flexible_content and repeater' ),
	'button_label warning names flexible_content and repeater'
);

// ------------------------------------------------------------------
// 3. Template + prompt carry the repeater shape (FIELD_SHAPE lockstep).
// ------------------------------------------------------------------
$tpl = AI_Import::template();
$check(
	isset( $tpl['group_schema']['fields[]']['layout'], $tpl['group_schema']['fields[]']['collapsed'] ),
	'FIELD_SHAPE carries layout + collapsed (template)'
);
$check(
	str_contains( $tpl['group_schema']['fields[]']['sub_fields'], 'repeater' ),
	'FIELD_SHAPE sub_fields description names repeater'
);
$check(
	str_contains( $tpl['group_schema']['fields[]']['button_label'], 'repeater' ),
	'FIELD_SHAPE button_label description names repeater'
);
$check( in_array( 'repeater', $tpl['valid_field_types'], true ), 'template type list includes repeater' );
$check( str_contains( AI_Import::prompt(), 'REPEATER-WITH-SUB-FIELDS' ), 'prompt includes the repeater mini-example' );

// ------------------------------------------------------------------
// 4. ACF importer: repeater → repeater (mapping level).
// ------------------------------------------------------------------
$rc = new \ReflectionClass( ACF_Importer::class );
$tm = $rc->getConstant( 'TYPE_MAP' );
$check( 'repeater' === ( $tm['repeater'] ?? null ), 'ACF importer maps repeater → repeater' );

$map = $rc->getMethod( 'map_acf_repeater_layout' );
$map->setAccessible( true );
$check( 'grid' === $map->invoke( null, 'table' ), 'ACF layout table → grid' );
$check( 'list' === $map->invoke( null, 'row' ), 'ACF layout row → list' );
$check( 'list' === $map->invoke( null, 'block' ), 'ACF layout block → list' );
$check( 'list' === $map->invoke( null, '' ), 'ACF layout empty → list' );
$check( 'list' === $map->invoke( null, 'bogus' ), 'ACF layout unknown → list' );

echo $failures > 0 ? "\n{$failures} FAILURES\n" : "\nALL PASS\n";
exit( $failures > 0 ? 1 : 0 );
