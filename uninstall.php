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
 * TK Fields — uninstall cleanup.
 *
 * Removes plugin CONFIGURATION only:
 *  - the 4 plugin settings options,
 *  - `tk-content-type` definition posts,
 *  - `tk_fields_group` field-group definition posts (+ their postmeta,
 *    which cascades on force-delete),
 *  - the custom `manage_tk_fields` capability (removed from every role
 *    that still carries it).
 *
 * Deliberately LEFT in the database: field VALUES stored on posts, terms,
 * and users (site content — uninstalling must never destroy it), and
 * user content created in TK content types (it becomes accessible again
 * if a type with the same slug is re-created).
 *
 * @package TK\Fields
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'tk_fields_acf_compat_enabled' );
delete_option( 'tk_fields_abilities_enabled' );

// v0.18.0: uninstall runs with no current user (the plugin is already
// deactivated), so wp_delete_post()'s delete_post capability check would
// silently refuse every deletion. Grant the delete primitives for the
// duration of the cleanup via map_meta_cap.
add_filter( 'map_meta_cap', 'tk_fields_uninstall_allow_delete', 10, 4 );
/**
 * Map delete_post/delete_posts to the universal 'exist' primitive during
 * uninstall cleanup. Declared here (not autoloaded) because uninstall.php
 * runs standalone.
 *
 * @param string[] $caps    Primitive capabilities required.
 * @param string   $cap     Capability being checked.
 * @return string[]
 */
function tk_fields_uninstall_allow_delete( $caps, $cap ) {
	if ( in_array( $cap, array( 'delete_post', 'delete_posts' ), true ) ) {
		return array( 'exist' );
	}
	return $caps;
}

/**
 * Force-delete every post of an internal definition post type.
 * 'any' deliberately does NOT include trash — list statuses explicitly.
 *
 * @param string $post_type Internal post type slug.
 */
function tk_fields_uninstall_delete_definitions( $post_type ) {
	$ids = get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => array( 'publish', 'pending', 'draft', 'private', 'trash' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}
}

// v0.15.0: Content Types cleanup. Definition posts (+ their meta, which
// cascades on delete) are removed; USER CONTENT created in those post types
// is deliberately left in the database — it becomes accessible again if a
// type with the same slug is re-created. Rewrite rules are flushed so no
// stale CPT rules survive; the deferred-flush flag is dropped too.
tk_fields_uninstall_delete_definitions( 'tk-content-type' );
delete_option( 'tk_fields_content_types' );
delete_option( 'tk_fields_flush_rewrites' );

// v0.18.0: Field-group definitions are plugin configuration, not user
// content — remove them (postmeta cascades on force-delete).
tk_fields_uninstall_delete_definitions( 'tk-field-group' );

remove_filter( 'map_meta_cap', 'tk_fields_uninstall_allow_delete', 10 );

// v0.18.0: remove the custom capability from every role that carries it
// so no stale primitive survives the plugin.
foreach ( wp_roles()->roles as $role_slug => $role_data ) {
	if ( ! empty( $role_data['capabilities']['manage_tk_fields'] ) ) {
		$role = get_role( $role_slug );
		if ( $role ) {
			$role->remove_cap( 'manage_tk_fields' );
		}
	}
}

flush_rewrite_rules();
