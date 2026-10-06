// Live Chromium proof for the OPF / WAPF-Extended image-change variation
// lifecycle (ledger row WAPF-INTERACTION-IMAGE-CHANGE).
//
// Runs the same sequence against four disposable variable products — one per
// engine (OPF, WAPF 3.1.5) x swap mode (rules, last) — whose variations each
// carry their own gallery image (see bin/e2e-image-change-variable-fixture.php):
//
//   a) a matching rule swaps the main image
//   b) an unmatched state resolves to the selected variation image
//   c) with no variation selected it resolves to the original image
//   d) a still-matching rule survives a variation switch (WAPF re-evaluates
//      its rules on every variation change)
//   e) clearing the rule while a variation is selected restores that
//      variation's image
//   f) deselect-all restores the original image, link and thumbnail
//   g) `rules` and `last` disagree when two rules match (mode distinction)
//
// Usage:
//   OPF_ICV_STATE=docs/compatibility/image-change-variable-20261006/fixture-state.json \
//   OPF_ICV_VARIANT=plain \
//   NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules \
//     node bin/e2e-image-change-variable-browser.mjs
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire( process.cwd() + '/index.php' );
const { chromium } = require( 'playwright' );

const statePath = process.env.OPF_ICV_STATE || 'docs/compatibility/image-change-variable-20261006/fixture-state.json';
const variant = process.env.OPF_ICV_VARIANT || 'plain';
const artifactDir = process.env.OPF_ICV_ARTIFACTS || path.dirname( statePath );
const state = JSON.parse( fs.readFileSync( statePath, 'utf8' ) );
const base = state.base_url || 'http://127.0.0.1:8410';
if ( ! /^http:\/\/127\.0\.0\.1:\d+$/.test( base ) ) {
	throw new Error( `Loopback clone required, got ${ base }` );
}
const only = process.env.OPF_ICV_ONLY ? process.env.OPF_ICV_ONLY.split( ',' ) : Object.keys( state.products );

const fileOf = ( url ) => ( ! url || url === 'NONE' ? url : url.split( '/' ).pop().replace( 'opf-icv-', '' ) );
const expectFile = ( key ) => `opf-icv-${ key.replace( 'vsmall', 'v-small' ).replace( 'vlarge', 'v-large' ).replace( 'rulea', 'rule-a' ).replace( 'ruleb', 'rule-b' ) }.png`;

const checks = [];
const check = ( label, pass, detail = '' ) => {
	checks.push( { label, pass: !! pass, detail } );
	console.log( `${ pass ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' (' + detail + ')' : '' }` );
};

// The image the shopper actually sees. Prefers an explicit "current slide"
// marker (flexslider, Slick, Swiper) so a third-party gallery plugin that
// replaces the WooCommerce flexslider still reads correctly, then falls back to
// the plain main image.
const activeImage = ( page ) => page.evaluate( () => {
	const root = document.querySelector( '.woocommerce-product-gallery' ) || document.querySelector( '.images' ) || document;
	for ( const marker of [ '.flex-active-slide', '.wpgs-for .slick-current', '.swiper-slide-active', '.slick-current', '.woocommerce-product-gallery__image:not(.clone)' ] ) {
		const el = root.querySelector( marker );
		const img = el && ( el.matches && el.matches( 'img' ) ? el : el.querySelector( 'img' ) );
		if ( img ) return ( img.getAttribute( 'data-large_image' ) || img.getAttribute( 'src' ) || '' );
	}
	const main = root.querySelector( 'img' );
	return main ? ( main.getAttribute( 'data-large_image' ) || main.getAttribute( 'src' ) || '' ) : 'NONE';
} );

const browser = await chromium.launch();
try {
	for ( const key of only ) {
		const product = state.products[ key ];
		const context = await browser.newContext( { viewport: { width: 1280, height: 1000 } } );
		const page = await context.newPage();
		page.setDefaultTimeout( 20000 );
		const errors = [];
		page.on( 'pageerror', ( e ) => errors.push( `${ e.message } @ ${ String( e.stack || '' ).split( '\n' )[ 1 ]?.trim() || '?' }` ) );

		await page.goto( product.url, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( product.selector );
		await page.waitForTimeout( 1200 );

		const tag = `${ product.engine }/${ product.mode }`;
		const select = async ( selector, value ) => { await page.selectOption( selector, value ); await page.waitForTimeout( 350 ); };
		const waitImage = async ( expectedKey, label ) => {
			const expected = expectFile( expectedKey );
			const deadline = Date.now() + 6000;
			let src = '';
			while ( Date.now() < deadline ) {
				src = await activeImage( page );
				if ( src.endsWith( expected ) ) break;
				await page.waitForTimeout( 120 );
			}
			check( `${ tag }: ${ label }`, src.endsWith( expected ), fileOf( src ) );
		};
		const resetVariations = async () => {
			const reset = page.locator( '.reset_variations' );
			if ( await reset.count() ) await reset.first().click( { force: true } );
			else await page.selectOption( 'select[name="attribute_size"]', { value: '' } );
			await page.waitForTimeout( 900 );
		};

		// (a) initial + matching rule (both modes: finish=gold -> rule-a).
		await waitImage( 'main', 'initial state shows the original image' );
		await select( product.selector, 'gold' );
		await waitImage( 'rulea', 'matching rule swaps the main image to rule-a' );

		// (b) unmatched state while a variation is selected -> its own image.
		await select( product.selector, 'none' );
		await select( 'select[name="attribute_size"]', 'small' );
		await waitImage( 'vsmall', 'selecting a variation shows its own image' );

		// (e): a rule still wins, and clearing it restores the variation image.
		await select( product.selector, 'gold' );
		await waitImage( 'rulea', 'rule wins over the selected variation image' );
		await select( product.selector, 'none' );
		await waitImage( 'vsmall', 'clearing the rule restores the selected variation image' );

		// (c): no variation selected + no rule -> original.
		await resetVariations();
		await waitImage( 'main', 'no variation + no matching rule shows the original image' );

		// (d): a matching rule survives a variation switch.
		await select( product.edge, 'xl' );
		await waitImage( 'ruleb', 'edge=xl shows rule-b' );
		await select( 'select[name="attribute_size"]', 'small' );
		await waitImage( 'ruleb', 'rule-b survives a variation switch (WAPF re-evaluates rules)' );
		await select( 'select[name="attribute_size"]', 'large' );
		await waitImage( 'ruleb', 'rule-b still wins after the next variation switch' );

		// (g): rule vs last distinction with two matching rules.
		await select( product.selector, 'gold' );
		// finish changed last: `last` picks rule-a; `rules` picks the later rule-b.
		await waitImage( product.mode === 'last' ? 'rulea' : 'ruleb', `two matching rules resolve per ${ product.mode } mode` );

		// (f): clear every field, then deselect all -> original image/link/thumb.
		await select( product.selector, 'none' );
		await select( product.edge, 'none' );
		await resetVariations();
		await page.waitForTimeout( 600 );
		await waitImage( 'main', 'deselect-all restores the original image' );
		const restored = await page.evaluate( () => {
			const root = document.querySelector( '.woocommerce-product-gallery' ) || document.querySelector( '.images' );
			if ( ! root ) return null;
			const main = root.querySelector( '.wp-post-image' );
			const link = main && main.closest ? main.closest( 'a' ) : null;
			const thumb = root.querySelector( '.flex-control-nav li:first-child img, .flex-control-thumbs li:first-child img, .wpgs-nav .thumbnail_image img, .thumbnail_image img' );
			return {
				src: main ? ( main.getAttribute( 'data-large_image' ) || main.getAttribute( 'src' ) ) : null,
				href: link ? link.getAttribute( 'href' ) : null,
				thumb: thumb ? ( thumb.getAttribute( 'src' ) || '' ) : null,
			};
		} );
		const original = state.images.main;
		check( `${ tag }: deselect-all restores the original image link`, !! restored && String( restored.href ) === original, restored ? fileOf( restored.href ) : 'no gallery' );
		check( `${ tag }: deselect-all restores the original thumbnail`, !! restored && ( ! restored.thumb || restored.thumb.endsWith( 'opf-icv-main.png' ) ), restored ? fileOf( restored.thumb ) : 'no gallery' );
		check( `${ tag }: no uncaught browser errors`, errors.length === 0, errors.join( ' | ' ) );

		await page.screenshot( { path: path.join( artifactDir, `browser-${ variant }-${ key }.png` ), fullPage: true } );
		await context.close();
	}
} finally {
	await browser.close();
}

const failed = checks.filter( ( c ) => ! c.pass );
fs.writeFileSync(
	path.join( artifactDir, `browser-${ variant }.json` ),
	JSON.stringify( { utc: new Date().toISOString(), variant, base, checkCount: checks.length, failed: failed.length, checks }, null, 2 )
);
console.log( `\n${ checks.length - failed.length }/${ checks.length } checks passed (variant=${ variant })` );
if ( failed.length ) {
	throw new Error( `${ failed.length } check(s) failed:\n - ${ failed.map( ( c ) => c.label ).join( '\n - ' ) }` );
}
