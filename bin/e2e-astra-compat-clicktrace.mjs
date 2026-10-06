// Root-cause probe for the Astra Pro / WooCommerce gallery console error seen
// with OPF active on the Astra single product page:
//
//   TypeError: Cannot read properties of undefined (reading 'animating')
//   at HTMLDivElement.<anonymous> (woocommerce/assets/js/flexslider/jquery.flexslider.min.js)
//
// It instruments the page (before any script runs) to record
//   1. every click on an Astra thumbnail slide div, with the JS stack that
//      caused it, and
//   2. every attribute mutation observed by any MutationObserver,
// then loads the OPF fixture product and reports the page errors.
//
// Usage (engine either active or not — the comparison is the point):
//   OPF_ASTRA_STATE=docs/compatibility/astra-20261006/fixture-state.json \
//   OPF_ASTRA_ARTIFACTS=docs/compatibility/astra-20261006 \
//   NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules \
//     node bin/e2e-astra-compat-clicktrace.mjs
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire( process.cwd() + '/index.php' );
const { chromium } = require( 'playwright' );

const statePath   = process.env.OPF_ASTRA_STATE || 'docs/compatibility/astra-20261006/fixture-state.json';
const artifactDir = process.env.OPF_ASTRA_ARTIFACTS || path.dirname( statePath );
const label       = process.env.OPF_ASTRA_TRACE_LABEL || 'active-engine';
const state       = JSON.parse( fs.readFileSync( statePath, 'utf8' ) );
const base        = state.base_url || 'http://127.0.0.1:8511';
const fixture     = state.products.opf;
fs.mkdirSync( artifactDir, { recursive: true } );

const browser = await chromium.launch();
const page    = await browser.newPage();
const errors  = [];
page.on( 'pageerror', ( error ) => errors.push( error.message ) );

await page.addInitScript( () => {
	window.__astraTrace = { thumbClicks: [], mutations: [] };
	const record = ( target ) => {
		window.__astraTrace.thumbClicks.push( {
			className: String( target.className || '' ),
			stack: String( new Error().stack || '' ).split( '\n' ).slice( 1, 6 ).map( ( line ) => line.trim() ),
		} );
	};
	document.addEventListener( 'click', ( event ) => {
		const target = event.target;
		if ( target && /ast-woocommerce-product-gallery__image/.test( String( target.className || '' ) ) ) {
			record( target );
		}
	}, true );
	const Original = window.MutationObserver;
	window.MutationObserver = class extends Original {
		constructor( callback ) {
			super( ( changes, observer ) => {
				changes.forEach( ( change ) => window.__astraTrace.mutations.push( change.attributeName || change.type ) );
				return callback( changes, observer );
			} );
		}
	};
} );

await page.goto( fixture.url, { waitUntil: 'domcontentloaded' } );
await page.waitForTimeout( 4000 );
const trace = await page.evaluate( () => window.__astraTrace );
await browser.close();

const mutatedAttributes = Array.from( new Set( trace.mutations.filter( Boolean ) ) );
const result = {
	label,
	product_url: fixture.url,
	page_errors: errors,
	thumb_clicks: trace.thumbClicks,
	mutated_attributes: mutatedAttributes,
	mutation_types: Array.from( new Set( trace.mutations.map( ( name ) => ( name || 'childList' ) ) ) ),
	recorded_utc: new Date().toISOString(),
};
fs.writeFileSync( path.join( artifactDir, `clicktrace-astra-${ label }.json` ), JSON.stringify( result, null, 2 ) );
console.log( JSON.stringify( { label, page_errors: errors, thumb_clicks: trace.thumbClicks.length, first_click_source: ( trace.thumbClicks[ 0 ] || {} ).stack, mutated: mutatedAttributes }, null, 2 ) );
