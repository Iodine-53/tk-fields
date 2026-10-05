/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * TK Slider widget (Elementor) — frontend behavior.
 *
 * Arrows, dots, autoplay (with pause-on-hover and a progress bar), touch
 * swiping, and a visibility pause when the tab is hidden. Transition motion
 * is pure CSS; this script only toggles classes / the track transform.
 * Re-initializes on Elementor editor re-renders via the element_ready hook.
 */
(function () {
	'use strict';

	function goTo( slider, index ) {
		var state = slider._tkSlider;
		if ( ! state || state.total < 2 ) {
			return;
		}

		index = ( index + state.total ) % state.total;
		state.current = index;

		var isSlide = slider.classList.contains( 'tk-el-slider--slide' );

		if ( isSlide ) {
			state.track.style.transform = 'translateX(' + -index * 100 + '%)';
		}

		for ( var i = 0; i < state.slides.length; i++ ) {
			state.slides[ i ].classList.toggle( 'is-active', i === index );
		}
		for ( var d = 0; d < state.dots.length; d++ ) {
			state.dots[ d ].classList.toggle( 'is-active', d === index );
		}

		restartProgress( slider );
	}

	function restartProgress( slider ) {
		var state = slider._tkSlider;
		if ( ! state || ! state.progress || ! state.autoplay ) {
			return;
		}
		// Restart the CSS animation so it tracks the current slide.
		state.progress.classList.remove( 'is-running' );
		void state.progress.offsetWidth;
		state.progress.style.animationDuration = state.delay + 'ms';
		state.progress.classList.add( 'is-running' );
	}

	function startAutoplay( slider ) {
		var state = slider._tkSlider;
		if ( ! state || ! state.autoplay || state.timer || state.total < 2 ) {
			return;
		}
		state.timer = window.setInterval( function () {
			if ( state.hovering || document.hidden ) {
				return;
			}
			goTo( slider, state.current + 1 );
		}, state.delay );
		restartProgress( slider );
	}

	function stopAutoplay( slider ) {
		var state = slider._tkSlider;
		if ( ! state || ! state.timer ) {
			return;
		}
		window.clearInterval( state.timer );
		state.timer = null;
	}

	function initSlider( slider ) {
		if ( slider.hasAttribute( 'data-tk-init' ) ) {
			return;
		}
		slider.setAttribute( 'data-tk-init', '1' );

		var slides = slider.querySelectorAll( '.tk-el-slide' );
		var dots = slider.querySelectorAll( '.tk-el-slider-dot' );
		var total = slides.length;
		if ( total < 1 ) {
			return;
		}

		var autoplay = slider.getAttribute( 'data-autoplay' ) === '1';
		var delay = parseInt( slider.getAttribute( 'data-delay' ), 10 );
		if ( isNaN( delay ) || delay < 500 ) {
			delay = 5000;
		}

		var state = {
			slides: slides,
			dots: dots,
			total: total,
			current: 0,
			autoplay: autoplay,
			delay: delay,
			timer: null,
			hovering: false,
			track: slider.querySelector( '.tk-el-slides' ),
			progress: slider.querySelector( '.tk-el-slider-progress span' ),
		};
		slider._tkSlider = state;

		var prev = slider.querySelector( '.tk-el-slider-prev' );
		var next = slider.querySelector( '.tk-el-slider-next' );
		if ( prev ) {
			prev.addEventListener( 'click', function () {
				goTo( slider, state.current - 1 );
			} );
		}
		if ( next ) {
			next.addEventListener( 'click', function () {
				goTo( slider, state.current + 1 );
			} );
		}
		for ( var i = 0; i < dots.length; i++ ) {
			( function ( idx ) {
				dots[ idx ].addEventListener( 'click', function () {
					goTo( slider, idx );
				} );
			} )( i );
		}

		if ( autoplay && slider.getAttribute( 'data-pause-hover' ) === '1' ) {
			slider.addEventListener( 'mouseenter', function () {
				state.hovering = true;
				if ( state.progress ) {
					state.progress.classList.remove( 'is-running' );
				}
			} );
			slider.addEventListener( 'mouseleave', function () {
				state.hovering = false;
				restartProgress( slider );
			} );
		}

		// Touch swipe.
		var touchX = null;
		slider.addEventListener( 'pointerdown', function ( e ) {
			if ( e.pointerType === 'mouse' ) {
				return;
			}
			touchX = e.clientX;
		} );
		slider.addEventListener( 'pointerup', function ( e ) {
			if ( touchX === null || e.pointerType === 'mouse' ) {
				return;
			}
			var dx = e.clientX - touchX;
			touchX = null;
			if ( Math.abs( dx ) > 40 ) {
				goTo( slider, state.current + ( dx < 0 ? 1 : -1 ) );
			}
		} );

		// Reduced motion: no autoplay, manual controls still work.
		var reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		if ( autoplay && ! reduceMotion ) {
			startAutoplay( slider );
		}
	}

	function initAll( root ) {
		var sliders = ( root || document ).querySelectorAll( '.tk-el-slider' );
		for ( var i = 0; i < sliders.length; i++ ) {
			initSlider( sliders[ i ] );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			initAll( document );
		} );
	} else {
		initAll( document );
	}

	// Elementor editor: re-run when the widget re-renders in the canvas.
	if ( window.elementorFrontend && window.elementorFrontend.hooks ) {
		window.elementorFrontend.hooks.addAction(
			'frontend/element_ready/tk-slider.default',
			function ( $scope ) {
				initAll( $scope[ 0 ] || document );
			}
		);
	}
})();
