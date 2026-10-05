/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * TK Countdown widget (Elementor) — frontend behavior.
 *
 * Ticks every second, re-deriving the remaining time from the target string
 * in the visitor's own browser timezone (the render-time prefill only covers
 * the first paint). At zero the units swap for the expiry message, or the
 * widget hides itself when data-hide-expired is set.
 * Re-initializes on Elementor editor re-renders via the element_ready hook.
 */
(function () {
	'use strict';

	function pad( n ) {
		return String( n ).padStart( 2, '0' );
	}

	function values( targetMs ) {
		var s = Math.max( 0, Math.floor( ( targetMs - Date.now() ) / 1000 ) );
		return {
			days: pad( Math.floor( s / 86400 ) ),
			hours: pad( Math.floor( ( s % 86400 ) / 3600 ) ),
			minutes: pad( Math.floor( ( s % 3600 ) / 60 ) ),
			seconds: pad( s % 60 ),
			remaining: s,
		};
	}

	function expire( widget ) {
		var hideExpired = widget.getAttribute( 'data-hide-expired' ) === '1';
		if ( hideExpired ) {
			widget.style.display = 'none';
			return;
		}
		var units = widget.querySelectorAll( '.tk-el-countdown-unit, .tk-el-countdown-sep' );
		for ( var i = 0; i < units.length; i++ ) {
			units[ i ].style.display = 'none';
		}
		var msg = widget.querySelector( '.tk-el-countdown-expired' );
		if ( msg ) {
			msg.hidden = false;
		}
	}

	function initCountdown( widget ) {
		if ( widget.hasAttribute( 'data-tk-init' ) ) {
			return;
		}
		widget.setAttribute( 'data-tk-init', '1' );

		var targetStr = widget.getAttribute( 'data-target' ) || '';
		var targetMs = new Date( targetStr ).getTime();
		if ( isNaN( targetMs ) ) {
			// Unparseable target: expire immediately rather than showing NaN.
			expire( widget );
			return;
		}

		var fields = {};
		var valueEls = widget.querySelectorAll( '.tk-el-countdown-value' );
		for ( var i = 0; i < valueEls.length; i++ ) {
			var unit = valueEls[ i ].getAttribute( 'data-unit' );
			if ( unit ) {
				fields[ unit ] = valueEls[ i ];
			}
		}

		var tick = function () {
			var v = values( targetMs );
			for ( var unit in fields ) {
				if ( Object.prototype.hasOwnProperty.call( fields, unit ) && v[ unit ] !== undefined ) {
					fields[ unit ].textContent = v[ unit ];
				}
			}
			if ( v.remaining === 0 ) {
				window.clearInterval( timer );
				expire( widget );
			}
		};

		tick();
		var timer = window.setInterval( tick, 1000 );
	}

	function initAll( root ) {
		var widgets = ( root || document ).querySelectorAll( '.tk-el-countdown' );
		for ( var i = 0; i < widgets.length; i++ ) {
			initCountdown( widgets[ i ] );
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
			'frontend/element_ready/tk-countdown.default',
			function ( $scope ) {
				initAll( $scope[ 0 ] || document );
			}
		);
	}
})();
