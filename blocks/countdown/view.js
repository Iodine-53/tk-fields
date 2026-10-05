/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * tk/countdown frontend — Interactivity API store.
 *
 * Per-instance unit values live in context as padded strings; the markup
 * binds via data-wp-text="context.days" etc. Server render.php pre-fills them
 * so the first paint is correct; this module re-derives them from the target
 * string every second. (An earlier version used state getters calling
 * getContext() — those evaluate outside directive context and throw.)
 */
import { store, getContext } from "@wordpress/interactivity";

const pad = ( n ) => String( n ).padStart( 2, "0" );

store( "tk/countdown", {
	callbacks: {
		start() {
			// Capture the context ONCE while the directive scope is active.
			// getContext() inside setInterval would run with no active scope
			// and throw — the captured proxy stays reactive, so later
			// mutations still trigger re-renders.
			const c = getContext();
			const tick = () => {
				const targetMs = new Date( c.target ).getTime();
				// Unparseable target: stop at expired rather than showing NaN.
				if ( Number.isNaN( targetMs ) ) {
					c.expired = true;
					if ( c._t ) {
						clearInterval( c._t );
					}
					return;
				}
				const s = Math.max( 0, Math.floor( ( targetMs - Date.now() ) / 1000 ) );
				c.days = pad( Math.floor( s / 86400 ) );
				c.hours = pad( Math.floor( ( s % 86400 ) / 3600 ) );
				c.minutes = pad( Math.floor( ( s % 3600 ) / 60 ) );
				c.seconds = pad( s % 60 );
				if ( s === 0 ) {
					c.expired = true;
					clearInterval( c._t );
				}
			};
			tick();
			c._t = setInterval( tick, 1000 );
		},
	},
} );
