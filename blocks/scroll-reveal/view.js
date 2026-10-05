/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * tk/scroll-reveal frontend — vanilla JS, no imports, no WP runtime.
 *
 * IntersectionObserver with a per-element threshold (from data-threshold).
 * On intersect the element gets .is-visible and is unobserved (one-shot).
 * Under prefers-reduced-motion (or no IntersectionObserver support) every
 * element is revealed immediately.
 */
( function() {
	'use strict';

	function reveal( element ) {
		element.classList.add( 'is-visible' );
	}

	function init() {
		var elements = document.querySelectorAll( '.tk-scroll-reveal' );
		if ( ! elements.length ) {
			return;
		}

		var reduceMotion =
			window.matchMedia &&
			window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

		if ( reduceMotion || ! ( 'IntersectionObserver' in window ) ) {
			for ( var i = 0; i < elements.length; i++ ) {
				reveal( elements[ i ] );
			}
			return;
		}

		for ( var j = 0; j < elements.length; j++ ) {
			( function( element ) {
				var threshold = parseFloat( element.getAttribute( 'data-threshold' ) );
				if ( isNaN( threshold ) ) {
					threshold = 0.2;
				}
				threshold = Math.min( 1, Math.max( 0, threshold ) );

				var observer = new IntersectionObserver( function( entries ) {
					for ( var k = 0; k < entries.length; k++ ) {
						if ( entries[ k ].isIntersecting ) {
							reveal( entries[ k ].target );
							observer.unobserve( entries[ k ].target );
						}
					}
				}, { threshold: threshold } );

				observer.observe( element );
			} )( elements[ j ] );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
