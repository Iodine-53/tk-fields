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
// View asset manifest for tk/accordion's Interactivity view module.
// Guarded so a direct request cannot dump the dependency manifest.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'dependencies' => array( '@wordpress/interactivity' ),
	'version'      => TK_FIELDS_VERSION,
);
