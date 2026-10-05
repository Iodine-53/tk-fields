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
 * Canonical TK Fields function API.
 *
 * These global tk_* functions are the public PHP API and are always loaded.
 * The ACF-compatible aliases (get_field() etc.) live in the OPT-IN shim at
 * includes/compat/acf-shim.php and are never defined here.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

use TK\Fields\Fields;
use TK\Fields\Field_Registry;
use TK\Fields\Unset_Value;
use TK\Fields\Repeater_Loop;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get a field value.
 *
 * @param string            $selector Field name.
 * @param int|WP_Post|WP_Term|WP_User|string|null $post_id Object: post ID, WP_Post, WP_Term, WP_User, the string 'option' (site-wide fields), or null for the current post.
 * @param bool              $format   Apply the field type's display formatting.
 * @return mixed The value, or TK\Fields\Unset_Value::get() when nothing is stored.
 */
function tk_get_field( string $selector, int|WP_Post|WP_Term|WP_User|string|null $post_id = null, bool $format = true ): mixed {
	return Fields::get( $selector, $post_id, $format );
}

/**
 * Echo a field value with type-appropriate escaping.
 *
 * @param string           $selector Field name.
 * @param int|WP_Post|WP_Term|WP_User|string|null $post_id Object: post ID, WP_Post, WP_Term, WP_User, the string 'option' (site-wide fields), or null for the current post.
 */
function tk_the_field( string $selector, int|WP_Post|WP_Term|WP_User|string|null $post_id = null ): void {
	$value = Fields::get( $selector, $post_id, true );

	if ( Unset_Value::is_unset( $value ) || null === $value ) {
		return;
	}

	if ( is_array( $value ) ) {
		// e.g. formatted image field: print the URL when there is one.
		if ( isset( $value['url'] ) ) {
			echo esc_url( (string) $value['url'] );
			return;
		}
		echo esc_html( wp_json_encode( $value ) );
		return;
	}

	if ( is_bool( $value ) ) {
		echo $value ? '1' : '';
		return;
	}

	echo esc_html( (string) $value );
}

/**
 * Whether a repeater field has rows. Powers the loop idiom:
 *
 *   while ( tk_have_rows( 'team' ) ) {
 *       tk_the_row();
 *       echo tk_get_sub_field( 'name' );
 *   }
 *
 * First call for a selector starts the loop; later calls continue it and
 * return whether more rows remain (popping the loop when exhausted).
 *
 * Nested repeaters (to 2 levels) loop through the ACTIVE ROW's rows:
 * call tk_have_rows( 'lessons' ) inside the outer loop and it resolves
 * the current row's nested wrapper — each nested loop is scoped to its
 * row by the full path stack, so sibling rows can never collide.
 *
 * @param string           $selector Field name.
 * @param int|WP_Post|null $post_id  Post ID, WP_Post, or null for the current post.
 */
function tk_have_rows( string $selector, int|WP_Post|null $post_id = null ): bool {
	return Repeater_Loop::have_rows( $selector, $post_id );
}

/**
 * Advance the active repeater loop to the next row.
 */
function tk_the_row(): void {
	Repeater_Loop::the_row();
}

/**
 * Sub-field value from the current repeater row; null when there is no
 * active row or the sub-field is absent.
 *
 * Scalar sub-fields only: a nested repeater sub-field is traversed with
 * its own tk_have_rows() loop (see tk_have_rows()), not read here.
 *
 * @param string $name Sub-field name.
 * @return mixed|null
 */
function tk_get_sub_field( string $name ) {
	return Repeater_Loop::get_sub_field( $name );
}

/**
 * Echo the current row's sub-field value with type-appropriate escaping.
 *
 * @param string $name Sub-field name.
 */
function tk_the_sub_field( string $name ): void {
	$value = Repeater_Loop::get_sub_field( $name );

	if ( null === $value ) {
		return;
	}

	if ( is_bool( $value ) ) {
		echo $value ? '1' : '';
		return;
	}

	if ( is_array( $value ) || is_object( $value ) ) {
		echo esc_html( wp_json_encode( $value ) );
		return;
	}

	echo esc_html( (string) $value );
}

/**
 * Stable ID of the current repeater row, or null when there is no active row.
 */
function tk_get_row_id(): ?string {
	return Repeater_Loop::get_row_id();
}

/**
 * Update a field value. Sanitized + validated against the field definition;
 * returns false for unknown fields, invalid values, or missing objects.
 *
 * @param string           $selector Field name.
 * @param mixed            $value    Raw value.
 * @param int|WP_Post|WP_Term|WP_User|string|null $post_id Object: post ID, WP_Post, WP_Term, WP_User, the string 'option' (site-wide fields), or null for the current post.
 */
function tk_update_field( string $selector, mixed $value, int|WP_Post|WP_Term|WP_User|string|null $post_id = null ): bool {
	return Fields::update( $selector, $value, $post_id );
}

/**
 * Delete a field value.
 *
 * @param string           $selector Field name.
 * @param int|WP_Post|WP_Term|WP_User|string|null $post_id Object: post ID, WP_Post, WP_Term, WP_User, the string 'option' (site-wide fields), or null for the current post.
 */
function tk_delete_field( string $selector, int|WP_Post|WP_Term|WP_User|string|null $post_id = null ): bool {
	return Fields::delete( $selector, $post_id );
}

/**
 * All registered fields with values for a post. Unset fields are skipped.
 *
 * @param int|WP_Post|WP_Term|WP_User|string|null $post_id Object: post ID, WP_Post, WP_Term, WP_User, the string 'option' (site-wide fields), or null for the current post.
 * @return array<string, mixed> Field name => formatted value.
 */
function tk_get_fields( int|WP_Post|WP_Term|WP_User|string|null $post_id = null ): array {
	return Fields::get_fields( $post_id );
}

/**
 * Get a field's definition (schema), or null when unknown.
 *
 * @param string $selector Field name.
 */
function tk_get_field_object( string $selector ): ?array {
	return Field_Registry::instance()->get( $selector );
}

/**
 * Check whether an API return value means "no value stored".
 *
 * @param mixed $value Value returned by tk_get_field() / Fields::get().
 */
function tk_is_unset( mixed $value ): bool {
	return Unset_Value::is_unset( $value );
}
