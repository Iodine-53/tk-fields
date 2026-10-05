/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * TK Parallax widget (Elementor) — frontend behavior.
 *
 * rAF-throttled scroll/resize updates, gated by an IntersectionObserver so
 * off-screen bands cost nothing. Each background layer (.tk-el-parallax-bg)
 * translates by `rect.top * speed` px, drifting slower than the scroll.
 *
 * Degrades to a static cover background when:
 *  - the visitor prefers reduced motion, or
 *  - the widget's disable-on-mobile setting is on and the viewport is a phone.
 *
 * Re-initializes on Elementor editor re-renders via the element_ready hook.
 */
(function () {
	'use strict';

	var MOBILE_BREAKPOINT = 768;

	var reduceMotion = Boolean(
		window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches
	);

	function isMobile() {
		return window.innerWidth < MOBILE_BREAKPOINT;
	}

	function isStatic( band ) {
		if ( reduceMotion ) {
			return true;
		}
		return band.getAttribute( 'data-disable-mobile' ) === '1' && isMobile();
	}

	var items = [];
	var visible = new Set();
	var ticking = false;
	var observer = null;

	function collect( root ) {
		var bands = ( root || document ).querySelectorAll( '.tk-el-parallax' );
		for ( var i = 0; i < bands.length; i++ ) {
			var band = bands[ i ];
			if ( band._tkParallax ) {
				continue;
			}
			var bg = band.querySelector( '.tk-el-parallax-bg' );
			if ( ! bg ) {
				continue;
			}
			var speed = parseFloat( bg.getAttribute( 'data-speed' ) );
			if ( isNaN( speed ) ) {
				speed = 0.35;
			}
			var item = { band: band, bg: bg, speed: speed };
			band._tkParallax = item;
			items.push( item );

			if ( isStatic( band ) ) {
				band.classList.add( 'tk-el-parallax--static' );
				continue;
			}
			if ( observer ) {
				observer.observe( band );
			}
		}
	}

	function update() {
		ticking = false;
		var viewportHeight = window.innerHeight || document.documentElement.clientHeight;
		visible.forEach( function ( item ) {
			var rect = item.band.getBoundingClientRect();
			if ( rect.bottom < 0 || rect.top > viewportHeight ) {
				return;
			}
			var y = Math.round( rect.top * item.speed );
			item.bg.style.transform = 'translate3d(0,' + y + 'px,0)';
		} );
	}

	function requestTick() {
		if ( ! ticking ) {
			ticking = true;
			window.requestAnimationFrame( update );
		}
	}

	function init() {
		if ( 'IntersectionObserver' in window ) {
			observer = new IntersectionObserver(
				function ( entries ) {
					for ( var i = 0; i < entries.length; i++ ) {
						var entry = entries[ i ];
						var item = entry.target._tkParallax;
						if ( ! item ) {
							continue;
						}
						if ( entry.isIntersecting ) {
							visible.add( item );
							requestTick();
						} else {
							visible.delete( item );
						}
					}
				},
				{ rootMargin: '20% 0px' }
			);
		} else {
			// No observer support: treat everything as visible.
			observer = null;
		}

		collect( document );

		if ( ! observer ) {
			for ( var i = 0; i < items.length; i++ ) {
				visible.add( items[ i ] );
			}
		}

		update();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	window.addEventListener( 'scroll', requestTick, { passive: true } );
	window.addEventListener( 'resize', function () {
		// Viewport crossed the mobile breakpoint: re-evaluate static bands.
		for ( var i = 0; i < items.length; i++ ) {
			var item = items[ i ];
			var shouldBeStatic = isStatic( item.band );
			var isStaticNow = item.band.classList.contains( 'tk-el-parallax--static' );
			if ( shouldBeStatic && ! isStaticNow ) {
				item.band.classList.add( 'tk-el-parallax--static' );
				if ( observer ) {
					observer.unobserve( item.band );
				}
				visible.delete( item );
			} else if ( ! shouldBeStatic && isStaticNow ) {
				item.band.classList.remove( 'tk-el-parallax--static' );
				if ( observer ) {
					observer.observe( item.band );
				} else {
					visible.add( item );
				}
				requestTick();
			}
		}
		requestTick();
	} );

	// Elementor editor: collect new bands when the widget re-renders.
	if ( window.elementorFrontend && window.elementorFrontend.hooks ) {
		window.elementorFrontend.hooks.addAction(
			'frontend/element_ready/tk-parallax.default',
			function ( $scope ) {
				collect( $scope[ 0 ] || document );
				requestTick();
			}
		);
	}
})();
