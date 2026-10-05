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
 * Storage adapter contract.
 *
 * Adapters know WHERE a field value lives (postmeta today, custom tables in a
 * later phase). No integration above the Fields service may know this.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Storage_Adapter {

	/**
	 * Read a raw stored value.
	 *
	 * @param int    $object_id   Object the value belongs to.
	 * @param string $object_type Object type ('post', 'term', 'user', 'option' ...).
	 * @param string $key         Field name / storage key.
	 * @return mixed The stored value, or Unset_Value::get() when nothing is stored.
	 */
	public function get( int $object_id, string $object_type, string $key ): mixed;

	/**
	 * Write a raw (already sanitized) value.
	 *
	 * @return bool True on success.
	 */
	public function set( int $object_id, string $object_type, string $key, mixed $value ): bool;

	/**
	 * Delete a stored value.
	 *
	 * @return bool True on success (including when nothing was stored).
	 */
	public function delete( int $object_id, string $object_type, string $key ): bool;
}
