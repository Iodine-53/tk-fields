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
 * ACF AI-transformer prompt test — run from the lab root:
 *
 *   ./bin/wp --allow-root --path=www eval-file \
 *     wp-content/plugins/tk-fields/tests/test-acf-ai-prompt.php
 *
 * Exits 0 when every assertion passes, 1 otherwise.
 *
 * Covers the no-ACF import path contract:
 * - The tk/v1/import-acf/ai-prompt route is registered (and the existing
 *   /import-acf POST route still is — no regression).
 * - Capability gating: manage_options (the admin-route bar used across the
 *   tk/v1 namespace) — denied without the cap.
 * - The prompt is generated server-side from the live AI_Import template:
 *   every FIELD_SHAPE key (via the template's public fields[] projection)
 *   appears in the prompt — it cannot drift from the validator.
 * - The prompt carries the ACF→TK type map, the forgiving rules
 *   (single-group [[]] flatten, blank operator ==), and the next step
 *   (paste the LLM's JSON into "Import from AI").
 * - rest_ai_prompt() returns a WP_REST_Response with a non-empty prompt.
 *
 * NOTE: no `declare(strict_types=1)` here — wp-cli's eval-file wraps this
 * in eval(), where a declare is not the first statement and fatals.
 */

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The plugin is active in the lab, so both classes are already loaded;
// require_once is belt-and-braces for standalone runs.
require_once dirname( __DIR__ ) . '/includes/admin/class-ai-import.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-acf-importer.php';

// eval-file runs with no current user; the permission probe needs one.
wp_set_current_user( 1 );

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

// ------------------------------------------------- route registration -----
ACF_Importer::instance()->register_routes();
$routes = rest_get_server()->get_routes();

$find_route = function ( string $needle ) use ( $routes ) {
	foreach ( $routes as $pattern => $endpoints ) {
		if ( false !== strpos( $pattern, $needle ) ) {
			return $endpoints;
		}
	}
	return null;
};

$ai_prompt_route = $find_route( 'import-acf/ai-prompt' );
$check( null !== $ai_prompt_route, 'tk/v1/import-acf/ai-prompt route is registered' );
$check( null !== $find_route( 'import-acf' ), 'existing tk/v1/import-acf POST route still registered (no regression)' );

// ------------------------------------------------------ capability gate ---
$perm = $ai_prompt_route[0]['permission_callback'] ?? null;
$check( is_callable( $perm ), 'ai-prompt route has a permission callback' );
$check( true === $perm(), 'ai-prompt permission passes for an admin (has manage_options)' );

$strip_manage_options = function ( array $allcaps ): array {
	unset( $allcaps['manage_options'] );
	$allcaps['manage_options'] = false;
	return $allcaps;
};
add_filter( 'user_has_cap', $strip_manage_options, 10, 1 );
$denied = $perm();
remove_filter( 'user_has_cap', $strip_manage_options, 10 );
$check( false === $denied, 'ai-prompt permission denies without manage_options' );

// ------------------------------------------------------- prompt content ---
$prompt = ACF_Importer::acf_transform_prompt();
$check( is_string( $prompt ) && '' !== $prompt, 'acf_transform_prompt() returns a non-empty string' );

// No drift: every FIELD_SHAPE key (via the template's public projection of
// the const) must appear in the prompt.
$shape_keys = array_keys( AI_Import::template()['group_schema']['fields[]'] );
$missing    = array();
foreach ( $shape_keys as $fkey ) {
	if ( false === strpos( $prompt, $fkey . ':' ) ) {
		$missing[] = $fkey;
	}
}
$check( array() === $missing, 'prompt embeds every FIELD_SHAPE key (no drift)' . ( $missing ? ': missing ' . implode( ', ', $missing ) : '' ) );

// Valid types list is embedded from the live registry.
$check( false !== strpos( $prompt, 'repeater' ) && false !== strpos( $prompt, 'flexible_content' ), 'prompt embeds the live valid field types' );

// ACF→TK type map lines.
$check( false !== strpos( $prompt, 'true_false → checkbox' ), 'prompt carries the ACF→TK type map (true_false → checkbox)' );
$check( false !== strpos( $prompt, 'google_map → map' ), 'prompt carries the ACF→TK type map (google_map → map)' );

// Forgiving rules the LLM must honor.
$check( false !== strpos( $prompt, 'flatten' ), 'prompt instructs single-group [[{…}]] flattening' );
$check( false !== strpos( $prompt, '"=="' ), 'prompt instructs blank operator means "=="' );

// Output contract: ONLY JSON, then the unmapped list.
$check( false !== strpos( $prompt, 'UNMAPPED:' ), 'prompt demands an UNMAPPED list after the JSON' );
$check( false !== strpos( $prompt, 'ONLY the raw TK Fields JSON' ), 'prompt demands ONLY raw JSON output' );

// Next step: the AI Import screen.
$check( false !== strpos( $prompt, 'Import from AI' ), 'prompt names the "Import from AI" next step' );

// ------------------------------------------------------ REST response -----
$response = ACF_Importer::instance()->rest_ai_prompt();
$check( $response instanceof \WP_REST_Response, 'rest_ai_prompt() returns a WP_REST_Response' );
$data = $response->get_data();
$check( isset( $data['prompt'] ) && $data['prompt'] === $prompt, 'REST response carries the generated prompt' );

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit( $failures ? 1 : 0 );
