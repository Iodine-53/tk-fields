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
 * User metadata storage adapter — Round 3 Hard tier, flexible_content / clone batch.
 *
 * Lets Fields::get()/update() address user-scoped fields (e.g. a clone
 * field on an options-like "profile extras" group), mirroring
 * Storage_Postmeta's zero-pointer contract.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * get_/update_/delete_user_meta wrapper behind the Storage_Adapter interface.
 */
final class Storage_Usermeta implements Storage_Adapter {

	/**
	 * {@inheritDoc}
	 */
	public function get( int $id, string $object_type, string $key ): mixed {
		if ( ! metadata_exists( 'user', $id, $key ) ) {
			return Unset_Value::get();
		}

		return get_user_meta( $id, $key, true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function set( int $id, string $object_type, string $key, mixed $value ): bool {
		return (bool) update_user_meta( $id, $key, $value );
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( int $id, string $object_type, string $key ): bool {
		return delete_user_meta( $id, $key );
	}
}
