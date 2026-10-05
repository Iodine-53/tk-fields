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
 * Asset dependencies for the tk/countdown interactivity view module.
 *
 * @package TK\Fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'dependencies' => array( '@wordpress/interactivity' ),
	'version'      => '0.2.0',
);
