/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * tk/parallax frontend — vanilla JS, no imports, no WP runtime.
 *
 * rAF-throttled scroll/resize handler. For each .tk-parallax the background
 * layer (.tk-parallax-bg) is translated by `rect.top * speed` px, so the
 * background drifts slower than the page scroll. An IntersectionObserver
 * gates the work: offscreen sections are skipped entirely, and the rAF loop
 * only runs while at least one section is visible.
 *
 * Degradations (static cover background, no motion):
 * - prefers-reduced-motion: the script bails out immediately.
 * - disable-on-mobile (data-disable-mobile="1", default): below 768px the
 *   background is not translated.
 */
( function() {
	'use strict';

	var reduceMotion =
		window.matchMedia &&
		window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	if ( reduceMotion ) {
		return;
	}

	function isNarrowViewport() {
		return (
			window.matchMedia &&
			window.matchMedia( '(max-width: 768px)' ).matches
		);
	}

	var items = [];
	var ticking = false;

	function collect() {
		items = [];
		var sections = document.querySelectorAll( '.tk-parallax' );
		for ( var i = 0; i < sections.length; i++ ) {
			var bg = sections[ i ].querySelector( '.tk-parallax-bg' );
			if ( ! bg ) {
				continue;
			}
			var speed = parseFloat( bg.getAttribute( 'data-speed' ) );
			if ( isNaN( speed ) ) {
				speed = 0.35;
			}
			speed = Math.max( 0, Math.min( 1, speed ) );
			items.push( {
				section: sections[ i ],
				bg: bg,
				speed: speed,
				disableMobile: bg.getAttribute( 'data-disable-mobile' ) === '1',
				visible: false
			} );
		}
	}

	function update() {
		ticking = false;
		var viewportHeight = window.innerHeight || document.documentElement.clientHeight;
		var narrow = isNarrowViewport();
		for ( var i = 0; i < items.length; i++ ) {
			var item = items[ i ];
			if ( item.disableMobile && narrow ) {
				// Static cover background on mobile.
				item.bg.style.transform = '';
				continue;
			}
			// IntersectionObserver gate: no work for offscreen sections.
			if ( ! item.visible ) {
				continue;
			}
			var rect = item.section.getBoundingClientRect();
			// Extra guard while the observer settles.
			if ( rect.bottom < 0 || rect.top > viewportHeight ) {
				continue;
			}
			var y = Math.round( rect.top * item.speed );
			item.bg.style.transform = 'translate3d(0,' + y + 'px,0)';
		}
	}

	function requestTick() {
		if ( ! ticking ) {
			ticking = true;
			window.requestAnimationFrame( update );
		}
	}

	function watchVisibility() {
		if ( ! ( 'IntersectionObserver' in window ) ) {
			// Fallback: treat everything as visible; update() still skips
			// fully-offscreen sections via getBoundingClientRect().
			for ( var i = 0; i < items.length; i++ ) {
				items[ i ].visible = true;
			}
			return;
		}
		var observer = new IntersectionObserver( function( entries ) {
			for ( var k = 0; k < entries.length; k++ ) {
				for ( var j = 0; j < items.length; j++ ) {
					if ( items[ j ].section === entries[ k ].target ) {
						items[ j ].visible = entries[ k ].isIntersecting;
					}
				}
			}
			requestTick();
		}, { rootMargin: '20% 0px' } );
		for ( var m = 0; m < items.length; m++ ) {
			observer.observe( items[ m ].section );
		}
	}

	function init() {
		collect();
		watchVisibility();
		update();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	window.addEventListener( 'scroll', requestTick, { passive: true } );
	window.addEventListener( 'resize', requestTick );
} )();
