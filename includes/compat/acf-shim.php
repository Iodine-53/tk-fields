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
 * OPT-IN ACF compatibility shim — "Migration Mode".
 *
 * Loaded ONLY when the site owner explicitly enables it, via the
 * tk_fields_acf_compat_enabled option or the TK_FIELDS_ACF_COMPAT constant,
 * AND only from the late plugins_loaded hook (priority 20) in tk-fields.php.
 *
 * Rules:
 * - Never redeclare: if ACF, Secure Custom Fields, or another plugin already
 *   defines these functions, bail LOUDLY with an admin notice instead.
 * - The shim bridges THEME TEMPLATE calls (get_field() etc.). It does not
 *   emulate ACF internals (acf_get_field_group(), acf_get_fields(), ...):
 *   third-party plugins sniffing for ACF are out of scope.
 * - have_rows() / the_row() / get_sub_field() / the_sub_field() map onto the
 *   block-traversal repeater loop (tk_have_rows() etc.).
 *
 * @package TK\Fields
 */

declare(strict_types=1);

use TK\Fields\Fields;
use TK\Fields\Unset_Value;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Collision guard: ACF / SCF / another shim got here first. Bail loudly.
if ( function_exists( 'acf' ) || class_exists( 'ACF' ) || function_exists( 'get_field' ) ) {
	add_action(
		'admin_notices',
		function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__(
					'TK Fields: Migration Mode was requested, but get_field() is already defined (ACF or another plugin is active). The compatibility shim was NOT loaded to avoid a fatal error. Deactivate the conflicting plugin or disable TK Fields Migration Mode.',
					'tk-fields'
				)
			);
		}
	);

	return;
}

if ( ! function_exists( 'get_field' ) ) {
	/**
	 * ACF-compatible alias of tk_get_field().
	 *
	 * Unset fields return false (ACF convention), not the TK sentinel.
	 */
	function get_field( $selector, $post_id = false, $format_value = true ) {
		$value = tk_get_field( (string) $selector, $post_id ?: null, (bool) $format_value );

		return Unset_Value::is_unset( $value ) ? false : $value;
	}
}

if ( ! function_exists( 'the_field' ) ) {
	function the_field( $selector, $post_id = false ) {
		tk_the_field( (string) $selector, $post_id ?: null );
	}
}

if ( ! function_exists( 'have_rows' ) ) {
	/**
	 * ACF-compatible alias of tk_have_rows().
	 */
	function have_rows( $selector, $post_id = false ) {
		return tk_have_rows( (string) $selector, $post_id ?: null );
	}
}

if ( ! function_exists( 'the_row' ) ) {
	function the_row() {
		tk_the_row();
	}
}

if ( ! function_exists( 'get_sub_field' ) ) {
	/**
	 * ACF-compatible alias of tk_get_sub_field(). Returns false (ACF
	 * convention) when no row is active or the sub-field is absent.
	 */
	function get_sub_field( $selector ) {
		$value = tk_get_sub_field( (string) $selector );

		return null === $value ? false : $value;
	}
}

if ( ! function_exists( 'the_sub_field' ) ) {
	function the_sub_field( $selector ) {
		tk_the_sub_field( (string) $selector );
	}
}

if ( ! function_exists( 'update_field' ) ) {
	function update_field( $selector, $value, $post_id = false ) {
		return tk_update_field( (string) $selector, $value, $post_id ?: null );
	}
}

if ( ! function_exists( 'delete_field' ) ) {
	function delete_field( $selector, $post_id = false ) {
		return tk_delete_field( (string) $selector, $post_id ?: null );
	}
}

if ( ! function_exists( 'get_fields' ) ) {
	function get_fields( $post_id = false ) {
		return tk_get_fields( $post_id ?: null );
	}
}

if ( ! function_exists( 'get_field_object' ) ) {
	function get_field_object( $selector, $post_id = false ) {
		return tk_get_field_object( (string) $selector );
	}
}
