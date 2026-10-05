/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * tk/slider frontend — Interactivity API store.
 *
 * Slides toggle via the .is-active class (CSS cross-animates slide/fade at
 * 420ms); the root carries data-transition and data-aspect-ratio.
 *
 * Autoplay: a per-instance interval advances the slide and restarts the
 * thin progress bar. It pauses on hover (when pauseOnHover is set), while
 * the tab is hidden, and is disabled entirely under prefers-reduced-motion.
 */
import { store, getContext, getElement } from "@wordpress/interactivity";

const prefersReduced =
	typeof window !== "undefined" &&
	window.matchMedia &&
	window.matchMedia( "(prefers-reduced-motion: reduce)" ).matches;

/** Root .tk-slider element for the current directive scope. */
function sliderRoot() {
	const scope = getElement();
	const node = scope && scope.ref ? scope.ref : null;
	return node && node.closest ? node.closest( ".tk-slider" ) : null;
}

function progressFill( root ) {
	return root ? root.querySelector( ".tk-slider-progress-fill" ) : null;
}

/** Restart the progress-bar animation from zero (no-op when absent). */
function restartProgress( root ) {
	const fill = progressFill( root );
	if ( ! fill ) {
		return;
	}
	fill.style.animation = "none";
	void fill.offsetWidth; // Force reflow so the animation restarts.
	fill.style.animation = "";
}

function stopAutoplay( c, root ) {
	if ( c._t ) {
		clearInterval( c._t );
		c._t = null;
	}
	if ( root ) {
		root.classList.add( "is-paused" );
	}
}

function startAutoplay( c, root ) {
	stopAutoplay( c, root );
	if ( ! c.autoplay || c.total < 2 || prefersReduced ) {
		return;
	}
	if ( root ) {
		root.classList.remove( "is-paused" );
	}
	c._t = setInterval( () => {
		c.currentSlide = ( c.currentSlide + 1 ) % c.total;
		restartProgress( root );
	}, c.interval );
}

function advance( c, root, next ) {
	c.currentSlide = next;
	restartProgress( root );
}

store( "tk/slider", {
	state: {
		get isActive() {
			const c = getContext();
			return c.index === c.currentSlide;
		},
		get isActiveDot() {
			const c = getContext();
			return c.index === c.currentSlide;
		},
	},
	actions: {
		next() {
			const c = getContext();
			advance( c, sliderRoot(), ( c.currentSlide + 1 ) % c.total );
		},
		prev() {
			const c = getContext();
			advance( c, sliderRoot(), ( c.currentSlide - 1 + c.total ) % c.total );
		},
		goTo() {
			const c = getContext();
			advance( c, sliderRoot(), c.index );
		},
	},
	callbacks: {
		init() {
			const c = getContext();
			const root = getElement().ref;
			restartProgress( root );
			startAutoplay( c, root );

			if ( c.pauseOnHover && root ) {
				root.addEventListener( "mouseenter", () => stopAutoplay( c, root ) );
				root.addEventListener( "mouseleave", () => startAutoplay( c, root ) );
			}

			// Don't burn cycles (or surprise the visitor) in a hidden tab.
			document.addEventListener( "visibilitychange", () => {
				if ( document.hidden ) {
					stopAutoplay( c, root );
				} else if ( c.autoplay ) {
					startAutoplay( c, root );
				}
			} );
		},
	},
} );
