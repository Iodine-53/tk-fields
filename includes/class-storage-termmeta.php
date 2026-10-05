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
 * Term metadata storage adapter — Round 3 Hard tier, flexible_content / clone batch.
 *
 * Lets Fields::get()/update() address term-scoped fields (e.g. a flexible
 * content field attached to a category), mirroring Storage_Postmeta's
 * zero-pointer contract: "unset" is returned when the key does not exist,
 * never a guessed default.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * get_/update_/delete_term_meta wrapper behind the Storage_Adapter interface.
 */
final class Storage_Termmeta implements Storage_Adapter {

	/**
	 * {@inheritDoc}
	 */
	public function get( int $id, string $object_type, string $key ): mixed {
		// Zero-pointer: metadata_exists() is the arbiter, so an explicitly
		// stored '' or 0 reads back as itself, not as "unset".
		if ( ! metadata_exists( 'term', $id, $key ) ) {
			return Unset_Value::get();
		}

		return get_term_meta( $id, $key, true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function set( int $id, string $object_type, string $key, mixed $value ): bool {
		return (bool) update_term_meta( $id, $key, $value );
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( int $id, string $object_type, string $key ): bool {
		return delete_term_meta( $id, $key );
	}
}
