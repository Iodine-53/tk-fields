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
 * Options storage adapter — Round 3 Hard tier, flexible_content / clone batch.
 *
 * Lets Fields::get()/update() address site-wide option fields by passing
 * the string 'option' as the object (Fields::resolve_object() maps it to
 * type 'option', id 0). One option row per field name.
 *
 * Zero-pointer contract: get_option() with a sentinel default is the
 * arbiter, so a stored false/''/0 reads back as itself, not as "unset".
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * get_/update_/delete_option wrapper behind the Storage_Adapter interface.
 */
final class Storage_Options implements Storage_Adapter {

	/**
	 * {@inheritDoc}
	 */
	public function get( int $id, string $object_type, string $key ): mixed {
		$sentinel = new \stdClass();
		$value    = get_option( $key, $sentinel );

		if ( $sentinel === $value ) {
			return Unset_Value::get();
		}

		return $value;
	}

	/**
	 * {@inheritDoc}
	 */
	public function set( int $id, string $object_type, string $key, mixed $value ): bool {
		$sentinel = new \stdClass();

		if ( $sentinel === get_option( $key, $sentinel ) ) {
			return add_option( $key, $value, '', false );
		}

		return update_option( $key, $value );
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( int $id, string $object_type, string $key ): bool {
		return delete_option( $key );
	}
}
