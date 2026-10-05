/*
 * This file is part of TK Fields, free software licensed under the
 * GNU General Public License v2 or later. See LICENSE in the plugin root.
 */

/**
 * TK Accordion widget (Elementor) — frontend behavior.
 *
 * Toggling only: the open/close animation is pure CSS (grid-template-rows).
 * Re-runs for Elementor editor re-renders via the element_ready hook.
 * Each accordion initializes once per element (guarded by data attribute).
 */
(function () {
	'use strict';

	function toggleItem( item, accordion, allowMultiple ) {
		var isOpen = item.classList.contains( 'is-open' );

		if ( ! allowMultiple && ! isOpen ) {
			var openItems = accordion.querySelectorAll( '.tk-el-accordion-item.is-open' );
			for ( var i = 0; i < openItems.length; i++ ) {
				setItem( openItems[ i ], false );
			}
		}

		setItem( item, ! isOpen );
	}

	function setItem( item, open ) {
		item.classList.toggle( 'is-open', open );
		var trigger = item.querySelector( '.tk-el-accordion-trigger' );
		if ( trigger ) {
			trigger.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}
	}

	function initAccordion( accordion ) {
		if ( accordion.hasAttribute( 'data-tk-init' ) ) {
			return;
		}
		accordion.setAttribute( 'data-tk-init', '1' );

		var allowMultiple = accordion.getAttribute( 'data-allow-multiple' ) === '1';

		accordion.addEventListener( 'click', function ( event ) {
			var trigger = event.target.closest( '.tk-el-accordion-trigger' );
			if ( ! trigger || ! accordion.contains( trigger ) ) {
				return;
			}
			var item = trigger.closest( '.tk-el-accordion-item' );
			if ( item ) {
				toggleItem( item, accordion, allowMultiple );
			}
		} );
	}

	function initAll( root ) {
		var accordions = ( root || document ).querySelectorAll( '.tk-el-accordion' );
		for ( var i = 0; i < accordions.length; i++ ) {
			initAccordion( accordions[ i ] );
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
			'frontend/element_ready/tk-accordion.default',
			function ( $scope ) {
				initAll( $scope[ 0 ] || document );
			}
		);
	}
})();
