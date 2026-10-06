/**
 * Quick-view adapters. Re-initialises OPF's frontend inside the modal markup a
 * theme or plugin injects after page load — the frontend module itself only
 * scans the document once, at DOM ready.
 *
 * Enqueued by OPF\Service\QuickView (with the frontend bundle) only on a request
 * that can open a quick view, so an unrelated page ships no bytes.
 *
 * Wired surfaces, each with the event the integration really fires:
 *  - Barn2 WooCommerce Quick View Pro — `quick_view_pro:load` (jQuery, the
 *    second handler argument is the modal element; also used by its
 *    [quick_view] shortcode).
 *  - Astra + Astra Pro — native `ast_quick_view_loader_stop` on document
 *    (dispatched by quick-view.js after the modal markup is in place).
 *  - Flatsome — `mfpOpen` (Magnific Popup triggers it on document).
 *  - Woodmart — `woodmart-quick-view-displayed` (jQuery trigger on body,
 *    bubbles to document).
 *
 * The frontend module is a deferred script module, so this classic footer
 * script usually runs first: a root seen before `window.OPF_FRONTEND` exists is
 * queued and flushed on the module's `opf:frontend-ready` event.
 */
( function () {
	'use strict';

	/** Modal roots seen before the frontend module published its API. */
	const pending = [];

	const reinit = ( root ) => {
		const api = window.OPF_FRONTEND;
		if ( api && typeof api.reinit === 'function' ) {
			api.reinit( root || document );
			return;
		}
		pending.push( root || document );
	};

	const flush = () => {
		const api = window.OPF_FRONTEND;
		if ( ! api || typeof api.reinit !== 'function' ) return;
		while ( pending.length ) api.reinit( pending.shift() );
	};

	/** First matching selector, or the document when the theme renamed its root. */
	const pick = ( selectors ) => {
		for ( let index = 0; index < selectors.length; index++ ) {
			const node = document.querySelector( selectors[ index ] );
			if ( node ) return node;
		}
		return document;
	};

	document.addEventListener( 'opf:frontend-ready', flush );

	const $ = window.jQuery;
	if ( $ ) {
		$( document ).on( 'quick_view_pro:load', ( _event, modal ) => {
			// Barn2 hands the modal element over; fall back to its own wrapper.
			reinit( modal && modal[ 0 ] ? modal[ 0 ] : pick( [ '.jquery-modal #quick-view', '.wc-quick-view-modal', '.jquery-modal .wc-quick-view-product' ] ) );
		} );
		$( document ).on( 'mfpOpen', () => {
			// Flatsome 3.20 renders `.product-lightbox`; older versions and WAPF
			// integrations use `.product-quick-view-container`.
			reinit( pick( [ '.product-lightbox', '.product-quick-view-container' ] ) );
		} );
		$( document ).on( 'woodmart-quick-view-displayed', () => {
			reinit( pick( [ '.product-quick-view' ] ) );
		} );
	}

	// Astra Pro dispatches a native document event (not a jQuery one).
	document.addEventListener( 'ast_quick_view_loader_stop', () => {
		reinit( pick( [ '#ast-quick-view-modal .product', '#ast-quick-view-modal' ] ) );
	} );
} )();
