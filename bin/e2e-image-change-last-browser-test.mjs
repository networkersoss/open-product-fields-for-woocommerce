// Isolated Chromium storefront proof for an imported WAPF 3.1.5 `last` gallery
// configuration. Serves a minimal WooCommerce gallery fixture and the real OPF
// frontend module from this checkout; never targets WordPress or a clone.
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const here = path.dirname( fileURLToPath( import.meta.url ) );
const require = createRequire( process.cwd() + '/index.php' );
const { chromium } = require( 'playwright' );
const jsPath = path.resolve( here, '../assets/js/opf-frontend.js' );
const port = Number( process.env.OPF_IMAGE_CHANGE_PORT || 8319 );
const images = [
	{ image_id: '801', src: 'https://fixture.invalid/material.jpg', full_src: 'https://fixture.invalid/material-full.jpg', thumb_src: 'https://fixture.invalid/material-thumb.jpg', srcset: '', sizes: '', alt: 'Material', title: 'Material', caption: '' },
	{ image_id: '802', src: 'https://fixture.invalid/finish.jpg', full_src: 'https://fixture.invalid/finish-full.jpg', thumb_src: 'https://fixture.invalid/finish-thumb.jpg', srcset: '', sizes: '', alt: 'Finish', title: 'Finish', caption: '' },
];
const imported = {
	images,
	rules: [
		{ image: '801', values: [ { field: 'material', value: 'solid' } ] },
		{ image: '802', values: [ { field: 'finish', value: 'brushed' } ] },
	],
};
const esc = ( value ) => JSON.stringify( value ).replaceAll( '&', '&amp;' ).replaceAll( '"', '&quot;' );
const html = `<!doctype html><html><head><meta charset="utf-8"><title>OPF image change isolated storefront</title></head><body>
<main class="product type-product"><div class="woocommerce-product-gallery"><div class="woocommerce-product-gallery__wrapper"><div><a href="https://fixture.invalid/original-full.jpg" data-large_image="https://fixture.invalid/original-full.jpg"><img class="wp-post-image" src="https://fixture.invalid/original.jpg" srcset="https://fixture.invalid/original.jpg 600w" sizes="600px" width="600" height="600" alt="Original" title="Original" data-large_image="https://fixture.invalid/original-full.jpg" data-large_image_width="1200" data-large_image_height="1200"></a></div></div><ol class="flex-control-thumbs"><li><img src="https://fixture.invalid/original-thumb.jpg" alt="Original thumb"></li></ol></div>
<form class="cart"><section data-opf-group="imported-last" data-opf-st="last" data-opf-gi="${esc( imported )}">
<fieldset data-opf-field="material"><legend>Material</legend><label><input class="opf-input" type="radio" name="material" value="solid" data-field-id="material" checked>Solid</label><label><input class="opf-input" type="radio" name="material" value="matte" data-field-id="material">Matte</label></fieldset>
<fieldset data-opf-field="finish"><legend>Finish</legend><label><input class="opf-input" type="radio" name="finish" value="brushed" data-field-id="finish">Brushed</label><label><input class="opf-input" type="radio" name="finish" value="polished" data-field-id="finish">Polished</label></fieldset>
</section></form></main><script>window.OPF_FIELDS={"imported-last":{"material":{"type":"radio"},"finish":{"type":"radio"}}};</script></body></html>`;
const server = http.createServer( ( req, res ) => {
	if ( req.url === '/' ) { res.writeHead( 200, { 'content-type': 'text/html; charset=utf-8' } ); res.end( html ); return; }
	if ( req.url === '/opf-frontend.js' ) { res.writeHead( 200, { 'content-type': 'text/javascript; charset=utf-8' } ); res.end( fs.readFileSync( jsPath ) ); return; }
	res.writeHead( 404 ); res.end();
} );
await new Promise( ( resolve ) => server.listen( port, '127.0.0.1', resolve ) );
const browser = await chromium.launch( { headless: true } );
const checks = [];
const check = ( label, pass ) => {
	checks.push( { label, pass: !! pass } );
	console.log( `${ pass ? 'ok' : 'FAIL' } ${ label }` );
	if ( ! pass ) throw new Error( label );
};
try {
	const page = await browser.newPage();
	const errors = [];
	page.on( 'pageerror', ( error ) => errors.push( error.message ) );
	await page.goto( `http://127.0.0.1:${ port }/`, { waitUntil: 'domcontentloaded' } );
	await page.addScriptTag( { url: `http://127.0.0.1:${ port }/opf-frontend.js` } );
	const image = page.locator( '.woocommerce-product-gallery .wp-post-image' );
	const original = 'https://fixture.invalid/original.jpg';
	check( 'fixture carries imported WAPF last mode and two source-shaped rules', await page.locator( '[data-opf-st="last"][data-opf-gi]' ).count() === 1 );
	await page.waitForFunction( () => document.querySelector( '.wp-post-image' )?.getAttribute( 'src' )?.includes( 'material.jpg' ) );
	check( 'initial first-field match displays the material image', ( await image.getAttribute( 'src' ) ).includes( 'material.jpg' ) );
	await page.locator( 'input[name="finish"][value="brushed"]' ).check();
	await page.waitForFunction( () => document.querySelector( '.wp-post-image' )?.getAttribute( 'src' )?.includes( 'finish.jpg' ) );
	check( 'latest changed finish field takes precedence over prior material match', ( await image.getAttribute( 'src' ) ).includes( 'finish.jpg' ) );
	await page.locator( 'input[name="material"][value="matte"]' ).check();
	await page.waitForFunction( ( src ) => document.querySelector( '.wp-post-image' )?.getAttribute( 'src' ) === src, original, { timeout: 4000 } );
	check( 'unmatched latest field restores original main image', await image.getAttribute( 'src' ) === original );
	check( 'restore also returns image link and thumbnail to original URLs', await page.locator( '.wp-post-image' ).getAttribute( 'href' ) === null && await page.locator( '.woocommerce-product-gallery a' ).getAttribute( 'href' ) === 'https://fixture.invalid/original-full.jpg' && await page.locator( '.flex-control-thumbs img' ).getAttribute( 'src' ) === 'https://fixture.invalid/original-thumb.jpg' );
	await page.locator( 'input[name="material"][value="solid"]' ).check();
	await page.waitForFunction( () => document.querySelector( '.wp-post-image' )?.getAttribute( 'src' )?.includes( 'material.jpg' ) );
	check( 'later material change replaces prior finish image', ( await image.getAttribute( 'src' ) ).includes( 'material.jpg' ) );
	check( 'no uncaught browser errors', errors.length === 0 );
	console.log( JSON.stringify( { checks, errors, fixture: 'local HTTP + Chromium + real OPF frontend module' }, null, 2 ) );
} finally {
	await browser.close();
	await new Promise( ( resolve, reject ) => server.close( ( error ) => error ? reject( error ) : resolve() ) );
}
