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
 * Explicit "unset" sentinel.
 *
 * Distinguishes "field has no value stored" from a legitimate '', 0, false or
 * null value. Shared by the Fields service, the PHP API, Block Bindings and
 * every future integration so empty-vs-unset semantics are solved once.
 *
 * @package TK\Fields
 */

declare(strict_types=1);

namespace TK\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Unset_Value {

	/**
	 * @var self|null
	 */
	private static ?self $instance = null;

	private function __construct() {}

	/**
	 * The single sentinel instance. Compare with === or use is_unset().
	 */
	public static function get(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Check whether a value returned by the Fields service means "no value stored".
	 *
	 * @param mixed $value Value to check.
	 */
	public static function is_unset( mixed $value ): bool {
		return $value instanceof self;
	}

	/**
	 * Prevent cloning / unserializing so identity comparison stays reliable.
	 */
	private function __clone() {}

	public function __wakeup(): void {
		self::$instance = $this;
	}
}
