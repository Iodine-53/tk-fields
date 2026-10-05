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
 * Postmeta storage adapter.
 *
 * One meta row per field, ZERO pointer duplication: unlike ACF we do NOT
 * write `_fieldname` shadow rows. `metadata_exists()` is the source of
 * truth for unset-vs-empty.
 *
 * Phase 1 supports the 'post' object type. Terms, users and options pages
 * get their own adapters in a later phase.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Storage_Postmeta implements Storage_Adapter {

	public function get( int $object_id, string $object_type, string $key ): mixed {
		if ( 'post' !== $object_type ) {
			return Unset_Value::get();
		}

		if ( ! metadata_exists( 'post', $object_id, $key ) ) {
			return Unset_Value::get();
		}

		return get_post_meta( $object_id, $key, true );
	}

	public function set( int $object_id, string $object_type, string $key, mixed $value ): bool {
		if ( 'post' !== $object_type ) {
			return false;
		}

		return (bool) update_post_meta( $object_id, $key, $value );
	}

	public function delete( int $object_id, string $object_type, string $key ): bool {
		if ( 'post' !== $object_type ) {
			return false;
		}

		// True even when nothing was stored: the end state ("no value") is achieved.
		delete_post_meta( $object_id, $key );

		return true;
	}
}
