// Live Chromium proof for the OPF vs WAPF-Extended 3.1.5 theme integrations
// (ledger rows WAPF-COMPAT-FLATSOME / WAPF-COMPAT-WOODMART).
//
// Runs the same sequence on a disposable theme clone (see
// bin/e2e-theme-fw-fixture.php) against one engine at a time:
//
//   product page   fields render, priced choices move the totals block, an
//                  image-change rule swaps the theme gallery image, add-to-cart
//                  carries the field value into the cart line, console errors
//   quick view     the same four behaviours inside the theme's AJAX quick view
//                  (Flatsome `.product-quick-view-container`, Woodmart
//                  `.product-quick-view`) — this is exactly what WAPF's
//                  class-flatsome.php / class-woodmart.php adapters target.
//
// Usage:
//   OPF_TFW_STATE=docs/compatibility/themes-fw-20261006/fixture-state-flatsome.json \
//   OPF_TFW_ENGINE=wapf \
//   NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules \
//     node bin/e2e-theme-fw-browser.mjs
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire( process.cwd() + '/index.php' );
const { chromium } = require( 'playwright' );

const statePath = process.env.OPF_TFW_STATE || 'docs/compatibility/themes-fw-20261006/fixture-state-flatsome.json';
const engine = process.env.OPF_TFW_ENGINE || 'opf';
const artifactDir = process.env.OPF_TFW_ARTIFACTS || path.dirname( statePath );
const phase = process.env.OPF_TFW_PHASE || 'all';
const state = JSON.parse( fs.readFileSync( statePath, 'utf8' ) );
const base = state.base_url;
if ( ! /^http:\/\/127\.0\.0\.1:\d+$/.test( base ) ) {
	throw new Error( `Loopback clone required, got ${ base }` );
}
const productKey = engine === 'opf' ? 'opf-theme-fields' : 'wapf-theme-fields';
const product = state.products[ productKey ];
if ( ! product || ! product.url ) {
	throw new Error( `Fixture state has no ${ productKey } entry (was that engine active during prepare?)` );
}
const theme = state.theme;
const quickViewButton = theme === 'woodmart'
	? `.open-quick-view[data-id="${ product.product }"]`
	: `.quick-view[data-prod="${ product.product }"]`;
const modalRoot = theme === 'woodmart' ? '.mfp-content .product-quick-view' : '.mfp-content .product-quick-view-container';
const cartUrl = `${ base }/cart/`;
const ruleA = 'opf-tfw-rule-a.png';

const checks = [];
const check = ( label, pass, detail = '' ) => {
	checks.push( { label, pass: !! pass, detail: String( detail ) } );
	console.log( `${ pass ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' (' + detail + ')' : '' }` );
};
const fileOf = ( url ) => ( ! url ? url : String( url ).split( '/' ).pop().split( '?' )[ 0 ] );
const money = ( text ) => {
	const match = String( text || '' ).replace( /[^0-9.,-]/g, '' ).replace( /,(?=\d{3}\b)/g, '' );
	return match === '' ? null : Number( match.replace( ',', '.' ) );
};

// The image the shopper actually sees: the gallery image with the largest
// visible area inside the gallery root. Works for FlexSlider, Flatsome's
// Flickity slider, Woodmart's Swiper carousel and stacked galleries.
const visibleImage = ( page, rootSelector ) => page.evaluate( ( selector ) => {
	const root = document.querySelector( selector );
	if ( ! root ) return { src: null, area: 0, imgs: [] };
	const rootRect = root.getBoundingClientRect();
	let best = null;
	let bestArea = 0;
	const imgs = [];
	root.querySelectorAll( 'img' ).forEach( ( img ) => {
		const rect = img.getBoundingClientRect();
		const width = Math.min( rect.right, rootRect.right ) - Math.max( rect.left, rootRect.left );
		const height = Math.min( rect.bottom, rootRect.bottom ) - Math.max( rect.top, rootRect.top );
		const area = Math.max( 0, width ) * Math.max( 0, height );
		imgs.push( { src: img.getAttribute( 'src' ), area: Math.round( area ) } );
		if ( area > bestArea ) {
			bestArea = area;
			best = img.getAttribute( 'src' ) || img.currentSrc || '';
		}
	} );
	return { src: best, area: Math.round( bestArea ), imgs };
}, rootSelector );

// Which engine globals the page actually loaded, and what the theme gallery
// root offers a swap engine (FlexSlider API, Flickity, Swiper, thumb strip).
const galleryProbe = ( page, rootSelector ) => page.evaluate( ( selector ) => {
	const root = document.querySelector( selector );
	const wrapper = ( root || document ).querySelector( '.woocommerce-product-gallery__wrapper' );
	const jq = window.jQuery;
	return {
		root: selector,
		root_found: !! root,
		root_class: root ? root.className : null,
		has_images_class: !! ( root && root.classList.contains( 'images' ) ),
		has_woo_gallery_class: !! ( root && root.classList.contains( 'woocommerce-product-gallery' ) ),
		has_wp_post_image: !! ( root && root.querySelector( 'img.wp-post-image' ) ),
		has_flexslider_data: !! ( jq && wrapper && jq( wrapper ).data( 'flexslider' ) ),
		has_flickity_data: !! ( jq && wrapper && jq( wrapper ).data( 'flickity' ) ),
		has_swiper: !! ( wrapper && wrapper.swiper ),
		has_flex_control_nav: !! ( root && root.querySelector( '.flex-control-nav a' ) ),
		engine_globals: {
			OPF_FIELDS: typeof window.OPF_FIELDS,
			OPF_IMAGE_RULES: typeof window.OPF_IMAGE_RULES,
			opf_config: typeof window.opf_config,
			wapf_config: typeof window.wapf_config,
			WAPF: typeof window.WAPF,
		},
		// The module/script elements actually loaded in this document. The inline
		// globals above can arrive with the AJAX quick-view fragment; only a real
		// script element means the engine's frontend module can run.
		engine_scripts: Array.from( document.querySelectorAll( 'script[src]' ) )
			.map( ( script ) => script.getAttribute( 'src' ) )
			.filter( ( src ) => /opf-frontend|wapf-frontend|advanced-product-fields.*frontend/.test( src ) ),
	};
}, rootSelector );

const errorRecorder = ( page, bucket ) => {
	page.on( 'pageerror', ( error ) => bucket.push( { kind: 'pageerror', text: error.message, stack: String( error.stack || '' ).split( '\n' ).slice( 0, 3 ).join( ' | ' ) } ) );
	page.on( 'console', ( message ) => {
		if ( message.type() !== 'error' ) return;
		bucket.push( { kind: 'console.error', text: message.text().slice( 0, 500 ) } );
	} );
};

const attribute = ( errors ) => errors.map( ( entry ) => {
	const text = entry.text.toLowerCase();
	const owner = text.includes( 'woodmart' ) || text.includes( 'wd-' ) ? 'theme:woodmart'
		: text.includes( 'flatsome' ) || text.includes( 'flickity' ) ? 'theme:flatsome'
			: text.includes( 'opf' ) ? 'engine:opf'
				: text.includes( 'wapf' ) ? 'engine:wapf'
					: 'unattributed';
	return { ...entry, owner };
} );

const results = { theme, engine, base, product: product.product, product_url: product.url, phases: {} };

const browser = await chromium.launch();
try {
	if ( phase === 'all' || phase === 'product' ) {
		const context = await browser.newContext( { viewport: { width: 1440, height: 1100 } } );
		const page = await context.newPage();
		page.setDefaultTimeout( 30000 );
		const errors = [];
		errorRecorder( page, errors );
		const phaseResult = { checks: [], errors: [], cart_text: '', notices: [] };

		await page.goto( product.url, { waitUntil: 'domcontentloaded' } );
		await page.waitForTimeout( 2500 );
		await page.screenshot( { path: path.join( artifactDir, `product-${ theme }-${ engine }.png` ), fullPage: false } );

		// 1. fields render
		const fieldCount = await page.locator( product.finish ).count();
		check( `product/${ engine }: field group renders on the ${ theme } product page`, fieldCount >= 1, `${ fieldCount } select(s)` );
		const choiceText = await page.locator( product.finish ).first().innerText().catch( () => '' );
		check( `product/${ engine }: priced choices are rendered in the control`, /Gold/.test( choiceText ), choiceText.replace( /\s+/g, ' ' ).slice( 0, 120 ) );

		// 2. pricing on selection (variation first, so a base price exists)
		await page.selectOption( 'select[name="attribute_size"]', 'small' ).catch( () => {} );
		await page.waitForTimeout( 1200 );
		const before = await page.locator( product.grand ).first().innerText().catch( () => '' );
		await page.selectOption( product.finish, 'gold' ).catch( () => {} );
		await page.waitForTimeout( 1200 );
		const optionsTotal = money( await page.locator( product.options ).first().innerText().catch( () => '' ) );
		const grandTotal = money( await page.locator( product.grand ).first().innerText().catch( () => '' ) );
		check( `product/${ engine }: selecting the +10.00 choice moves the options total to 10`, optionsTotal === 10, `options=${ optionsTotal } (before grand=${ before.replace( /\s+/g, ' ' ) })` );
		check( `product/${ engine }: grand total = variation 100 + option 10`, grandTotal === 110, `grand=${ grandTotal }` );

		// 3. image-change rule on the theme gallery
		const gallery = await visibleImage( page, '.woocommerce-product-gallery' );
		check( `product/${ engine }: finish=gold swaps the visible ${ theme } gallery image to rule-a`, fileOf( gallery.src ) === ruleA, `${ fileOf( gallery.src ) } (area ${ gallery.area })` );
		await page.screenshot( { path: path.join( artifactDir, `product-${ theme }-${ engine }-rule.png` ), fullPage: false } );

		// 4. add to cart carries the field value
		const atc = page.locator( 'form.cart .single_add_to_cart_button' ).first();
		if ( await atc.count() ) {
			await atc.click( { force: true } ).catch( async () => { await atc.dispatchEvent( 'click' ).catch( () => {} ); } );
			await page.waitForTimeout( 4000 );
		}
		phaseResult.notices = await page.locator( '.woocommerce-message, .woocommerce-error, .woocommerce-info' ).allInnerTexts().catch( () => [] );
		await page.goto( cartUrl, { waitUntil: 'domcontentloaded' } );
		await page.waitForTimeout( 1500 );
		phaseResult.cart_text = ( await page.locator( '.woocommerce-cart-form, .cart-empty' ).first().innerText().catch( () => '' ) ).replace( /\s+/g, ' ' ).slice( 0, 600 );
		check( `product/${ engine }: the ${ theme } cart line carries the field value (Finish: Gold)`, /Finish/.test( phaseResult.cart_text ) && /Gold/.test( phaseResult.cart_text ), phaseResult.cart_text.slice( 0, 220 ) );

		await page.goto( product.url, { waitUntil: 'domcontentloaded' } );
		await page.waitForTimeout( 2000 );
		phaseResult.gallery = await galleryProbe( page, '.woocommerce-product-gallery' );

		phaseResult.errors = attribute( errors );
		check( `product/${ engine }: no uncaught browser errors on the ${ theme } product page`, phaseResult.errors.length === 0, JSON.stringify( phaseResult.errors ).slice( 0, 400 ) );
		phaseResult.checks = checks.slice();
		results.phases.product = phaseResult;
		await context.close();
	}

	if ( phase === 'all' || phase === 'quickview' ) {
		checks.length = 0;
		const context = await browser.newContext( { viewport: { width: 1440, height: 1100 } } );
		const page = await context.newPage();
		page.setDefaultTimeout( 30000 );
		const errors = [];
		errorRecorder( page, errors );
		const phaseResult = { checks: [], errors: [], cart_text: '', notices: [], modal: modalRoot };

		await page.goto( `${ base }/shop/`, { waitUntil: 'domcontentloaded' } );
		await page.waitForTimeout( 3000 );
		const buttonCount = await page.locator( quickViewButton ).count();
		check( `quickview/${ engine }: the ${ theme } loop renders a quick view button for the fixture product`, buttonCount >= 1, `${ buttonCount } button(s) for ${ quickViewButton }` );
		if ( buttonCount >= 1 ) {
			// Flatsome/Woodmart both park the button behind a hover layer, so the
			// theme's delegated handler is triggered with a real bubbling click.
			await page.locator( quickViewButton ).first().dispatchEvent( 'click' );
			await page.waitForSelector( modalRoot, { timeout: 20000 } ).catch( () => {} );
			await page.waitForTimeout( 3000 );
			await page.screenshot( { path: path.join( artifactDir, `quickview-${ theme }-${ engine }.png` ), fullPage: false } );

			const modalFields = await page.locator( `${ modalRoot } ${ product.finish }` ).count();
			check( `quickview/${ engine }: fields render inside the ${ theme } quick view`, modalFields >= 1, `${ modalFields } select(s) in ${ modalRoot }` );

			const modalGalleryRoot = await page.locator( `${ modalRoot } .woocommerce-product-gallery` ).count()
				? `${ modalRoot } .woocommerce-product-gallery`
				: modalRoot;
			await page.selectOption( `${ modalRoot } select[name="attribute_size"]`, 'small' ).catch( () => {} );
			await page.waitForTimeout( 1200 );
			await page.selectOption( `${ modalRoot } ${ product.finish }`, 'gold' ).catch( () => {} );
			await page.waitForTimeout( 1500 );

			const modalOptions = money( await page.locator( `${ modalRoot } ${ product.options }` ).first().innerText().catch( () => '' ) );
			const modalGrand = money( await page.locator( `${ modalRoot } ${ product.grand }` ).first().innerText().catch( () => '' ) );
			check( `quickview/${ engine }: priced choice moves the quick-view options total to 10`, modalOptions === 10, `options=${ modalOptions }` );
			check( `quickview/${ engine }: quick-view grand total = 100 + 10`, modalGrand === 110, `grand=${ modalGrand }` );

			const modalGallery = await visibleImage( page, modalGalleryRoot );
			check( `quickview/${ engine }: finish=gold swaps the ${ theme } quick-view gallery image to rule-a`, fileOf( modalGallery.src ) === ruleA, `${ fileOf( modalGallery.src ) } via ${ modalGalleryRoot }` );
			phaseResult.gallery = await galleryProbe( page, modalGalleryRoot );

			const modalAtc = page.locator( `${ modalRoot } .single_add_to_cart_button` ).first();
			if ( await modalAtc.count() ) {
				await modalAtc.click( { force: true } ).catch( async () => { await modalAtc.dispatchEvent( 'click' ).catch( () => {} ); } );
				await page.waitForTimeout( 4000 );
			}
			phaseResult.notices = await page.locator( '.woocommerce-message, .woocommerce-error, .woocommerce-info' ).allInnerTexts().catch( () => [] );
			await page.goto( cartUrl, { waitUntil: 'domcontentloaded' } );
			await page.waitForTimeout( 1500 );
			phaseResult.cart_text = ( await page.locator( '.woocommerce-cart-form, .cart-empty' ).first().innerText().catch( () => '' ) ).replace( /\s+/g, ' ' ).slice( 0, 600 );
			check( `quickview/${ engine }: add-to-cart from the ${ theme } quick view carries the field value into the cart line`, /Finish/.test( phaseResult.cart_text ) && /Gold/.test( phaseResult.cart_text ), phaseResult.cart_text.slice( 0, 220 ) );
		}

		phaseResult.errors = attribute( errors );
		check( `quickview/${ engine }: no uncaught browser errors in the ${ theme } quick view`, phaseResult.errors.length === 0, JSON.stringify( phaseResult.errors ).slice( 0, 400 ) );
		phaseResult.checks = checks.slice();
		results.phases.quickview = phaseResult;
		await context.close();
	}
} finally {
	await browser.close();
}

if ( ! fs.existsSync( artifactDir ) ) fs.mkdirSync( artifactDir, { recursive: true } );
const outFile = path.join( artifactDir, `browser-${ theme }-${ engine }.json` );
fs.writeFileSync( outFile, JSON.stringify( results, null, 2 ) );
const passed = Object.values( results.phases ).reduce( ( total, phaseResult ) => total + phaseResult.checks.filter( ( entry ) => entry.pass ).length, 0 );
const total = Object.values( results.phases ).reduce( ( total, phaseResult ) => total + phaseResult.checks.length, 0 );
console.log( `${ passed }/${ total } checks passed (theme=${ theme }, engine=${ engine }) -> ${ outFile }` );
