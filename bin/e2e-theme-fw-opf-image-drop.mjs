// Isolates the OPF gallery defect the theme runs expose:
// `updateProductImage()` treats "the rule target is one of the gallery slides"
// as "navigate the gallery to it" and returns without painting when the theme's
// gallery cannot be navigated (no FlexSlider API, no `.flex-control-nav`).
//
// The falsification test removes the rule target's slide from the DOM: if the
// image then paints, the dropped-swap branch is confirmed.
//
// Usage (one engine active, OPF):
//   OPF_TFW_STATE=docs/compatibility/themes-fw-20261006/fixture-state-flatsome.json \
//   NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules \
//     node bin/e2e-theme-fw-opf-image-drop.mjs
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire( process.cwd() + '/index.php' );
const { chromium } = require( 'playwright' );

const statePath = process.env.OPF_TFW_STATE || 'docs/compatibility/themes-fw-20261006/fixture-state-flatsome.json';
const artifactDir = process.env.OPF_TFW_ARTIFACTS || path.dirname( statePath );
const state = JSON.parse( fs.readFileSync( statePath, 'utf8' ) );
const product = state.products[ 'opf-theme-fields' ];
if ( ! product || ! product.url ) {
	throw new Error( 'Fixture state has no opf-theme-fields entry.' );
}
const finish = product.finish;
const gallery = '.woocommerce-product-gallery';

const visibleImage = ( page ) => page.evaluate( ( selector ) => {
	const root = document.querySelector( selector );
	if ( ! root ) return null;
	const rootRect = root.getBoundingClientRect();
	let best = null;
	let bestArea = 0;
	root.querySelectorAll( 'img' ).forEach( ( img ) => {
		const rect = img.getBoundingClientRect();
		const width = Math.min( rect.right, rootRect.right ) - Math.max( rect.left, rootRect.left );
		const height = Math.min( rect.bottom, rootRect.bottom ) - Math.max( rect.top, rootRect.top );
		const area = Math.max( 0, width ) * Math.max( 0, height );
		if ( area > bestArea ) {
			bestArea = area;
			best = ( img.getAttribute( 'src' ) || '' ).split( '/' ).pop();
		}
	} );
	return best;
}, gallery );

const results = { theme: state.theme, engine: 'opf', product: product.product, steps: [] };
const browser = await chromium.launch();
try {
	const page = await browser.newPage( { viewport: { width: 1440, height: 1100 } } );
	page.setDefaultTimeout( 30000 );
	const errors = [];
	page.on( 'pageerror', ( error ) => errors.push( error.message ) );
	page.on( 'console', ( message ) => { if ( message.type() === 'error' ) errors.push( message.text() ); } );

	await page.goto( product.url, { waitUntil: 'domcontentloaded' } );
	await page.waitForTimeout( 2500 );
	results.rules_global = await page.evaluate( () => ( window.OPF_IMAGE_RULES ? Object.keys( window.OPF_IMAGE_RULES ).length : 0 ) );

	await page.selectOption( 'select[name="attribute_size"]', 'small' );
	await page.waitForTimeout( 1200 );
	results.steps.push( { step: 'variation selected', visible: await visibleImage( page ) } );

	await page.selectOption( finish, 'gold' );
	await page.waitForTimeout( 1500 );
	results.steps.push( { step: 'rule target still a gallery slide', visible: await visibleImage( page ) } );

	results.slides_removed = await page.evaluate( () => {
		let removed = 0;
		document.querySelectorAll( '.woocommerce-product-gallery__image' ).forEach( ( slide ) => {
			const img = slide.querySelector( 'img' );
			if ( img && ( img.getAttribute( 'src' ) || '' ).includes( 'rule-a' ) ) {
				slide.remove();
				removed++;
			}
		} );
		return removed;
	} );

	await page.selectOption( finish, 'none' );
	await page.waitForTimeout( 800 );
	await page.selectOption( finish, 'gold' );
	await page.waitForTimeout( 1500 );
	results.steps.push( { step: 'rule target removed from the gallery', visible: await visibleImage( page ) } );
	await page.screenshot( { path: path.join( artifactDir, `opf-image-drop-${ state.theme }.png` ) } );
	results.errors = errors;
} finally {
	await browser.close();
}

if ( ! fs.existsSync( artifactDir ) ) fs.mkdirSync( artifactDir, { recursive: true } );
const outFile = path.join( artifactDir, `opf-image-drop-${ state.theme }.json` );
fs.writeFileSync( outFile, JSON.stringify( results, null, 2 ) );
for ( const step of results.steps ) {
	console.log( `${ step.visible }  <- ${ step.step }` );
}
console.log( `-> ${ outFile }` );
