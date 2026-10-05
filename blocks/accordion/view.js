/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

import { store, getContext } from "@wordpress/interactivity";

store( "tk/accordion", {
	state: {
		get isOpen() {
			const c = getContext();
			return ( c.openIds || [] ).includes( c.id );
		},
	},
	actions: {
		toggle() {
			const c = getContext();
			const id = c.id;
			const ids = c.openIds || [];
			c.openIds = ids.includes( id )
				? ids.filter( ( x ) => x !== id )
				: c.allowMultiple
					? [ ...ids, id ]
					: [ id ];
		},
	},
} );
