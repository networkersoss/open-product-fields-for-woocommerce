// Live Chromium proof for the OPF / WAPF-Extended Astra (theme 4.13.11) +
// Astra Pro addon (4.13.10) compatibility run.
//
// Runs the same sequence against the engine product prepared by
// bin/e2e-astra-compat-fixture.php, once on the Astra product page and once in
// the Astra Pro quick-view modal (`#ast-quick-view-modal`, opened from the shop
// card trigger):
//
//   1. the field group renders (inputs present, labels readable)
//   2. a priced choice updates the totals block
//   3. the image-change rule swaps the main image (and restores it)
//   4. the same three checks inside the quick-view modal
//   5. add-to-cart carries the field values to the cart
//   6. every console error/warning is recorded and attributed to Astra, Astra
//      Pro or the engine by the source URL
//
// The engine is chosen with OPF_ASTRA_ENGINE (opf|wapf) and the other plugin is
// deactivated by the caller; the driver never flips plugins itself.
//
// Usage:
//   OPF_ASTRA_ENGINE=opf \
//   OPF_ASTRA_STATE=docs/compatibility/astra-20261006/fixture-state.json \
//   OPF_ASTRA_ARTIFACTS=docs/compatibility/astra-20261006 \
//   NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules \
//     node bin/e2e-astra-compat-browser.mjs
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire( process.cwd() + '/index.php' );
const { chromium } = require( 'playwright' );

const statePath   = process.env.OPF_ASTRA_STATE || 'docs/compatibility/astra-20261006/fixture-state.json';
const artifactDir = process.env.OPF_ASTRA_ARTIFACTS || path.dirname( statePath );
const engine      = process.env.OPF_ASTRA_ENGINE || 'opf';
if ( ! [ 'opf', 'wapf', 'control' ].includes( engine ) ) {
	throw new Error( `OPF_ASTRA_ENGINE must be opf, wapf or control, got ${ engine }` );
}

const state = JSON.parse( fs.readFileSync( statePath, 'utf8' ) );
const base  = state.base_url || 'http://127.0.0.1:8511';
if ( ! /^http:\/\/127\.0\.0\.1:\d+$/.test( base ) ) {
	throw new Error( `Loopback clone required, got ${ base }` );
}
const fixture = state.products[ engine ] || state.products.opf;
if ( ! fixture ) {
	throw new Error( `No fixture product for engine ${ engine }` );
}
fs.mkdirSync( artifactDir, { recursive: true } );

/** Engine-specific DOM contract (OPF compat markup keeps the legacy classes). */
const SEL_ENGINE = 'control' === engine ? 'opf' : engine;
const SEL = {
	opf: {
		wrapper: '.opf-fields',
		group: '[data-opf-group]',
		finish: '[data-opf-field="finish"] select',
		edge: '[data-opf-field="edge"] select',
		totals: '.opf-product-totals',
		optionsTotal: '.opf-product-totals .opf-options-total',
		grandTotal: '.opf-product-totals .opf-grand-total',
		fieldCount: '[data-opf-field]',
		readyFlag: 'data-opf-initialized',
		globalName: 'OPF_FIELDS',
	},
	wapf: {
		wrapper: '.wapf-wrapper',
		group: '.wapf-field-group',
		finish: 'select[name="wapf[field_finish]"]',
		edge: 'select[name="wapf[field_edge]"]',
		totals: '.wapf-product-totals',
		optionsTotal: '.wapf-product-totals .wapf-options-total',
		grandTotal: '.wapf-product-totals .wapf-grand-total',
		fieldCount: '.wapf-field-container.wapf-field-select',
		readyFlag: null,
		globalName: 'wapf_config',
	},
}[ SEL_ENGINE ];

const fileOf = ( url ) => ( ! url ? url : url.split( '/' ).pop() );

/**
 * Self-contained page-side snapshot (Playwright serializes only this function,
 * so nothing outside `args` may be referenced).
 */
const readState = ( { scopeSel, selectors } ) => {
	const scope = scopeSel ? document.querySelector( scopeSel ) : document;
	if ( ! scope ) {
		return { present: false };
	}
	const gallery = scope.querySelector( '.woocommerce-product-gallery' ) || scope.querySelector( '.ast-qv-image-slider' ) || scope.querySelector( '.images' ) || scope;
	const pick = ( el ) => {
		const img = el && ( el.matches && el.matches( 'img' ) ? el : el.querySelector( 'img' ) );
		return img ? ( img.getAttribute( 'data-large_image' ) || img.getAttribute( 'src' ) || '' ) : null;
	};
	// Astra Pro renders a thumbnail strip (`.ast-single-product-thumbnails`)
	// whose slides also carry `flex-active-slide` and which is NOT updated by
	// programmatic slider navigation; the visible main image lives in
	// WooCommerce's `.woocommerce-product-gallery__wrapper` (or, in the quick
	// view modal, in Astra's single-slide `.ast-qv-image-slider`).
	const slider = gallery.querySelector( '.woocommerce-product-gallery__wrapper' ) || gallery;
	const activeSlide = slider.querySelector( '.flex-active-slide, .slick-current, .swiper-slide-active' )
		|| gallery.querySelector( '.flex-active-slide, .slick-current, .swiper-slide-active' );
	const postImage = pick( gallery.querySelector( 'img.wp-post-image' ) ) || pick( scope.querySelector( 'img.wp-post-image' ) );
	const images = {
		activeSlide: pick( activeSlide ),
		postImage,
		firstImg: pick( gallery.querySelector( 'img' ) ),
		visible: pick( activeSlide ) || postImage || pick( gallery.querySelector( 'img' ) ),
	};

	const wrapper = scope.querySelector( selectors.wrapper );
	const group = scope.querySelector( selectors.group );
	const finish = scope.querySelector( selectors.finish );
	const edge = scope.querySelector( selectors.edge );
	const totals = scope.querySelector( selectors.totals );
	const optionsTotal = scope.querySelector( selectors.optionsTotal );
	const grandTotal = scope.querySelector( selectors.grandTotal );
	const labels = Array.from( scope.querySelectorAll( `${ selectors.group } label` ) ).map( ( l ) => l.textContent.trim() );

	return {
		present: true,
		fieldCount: scope.querySelectorAll( selectors.fieldCount ).length,
		labels,
		choiceTexts: finish ? Array.from( finish.options ).map( ( o ) => o.textContent.trim() ) : [],
		hasWrapper: !! wrapper,
		hasGroup: !! group,
		hasFinish: !! finish,
		hasEdge: !! edge,
		startedFlag: selectors.readyFlag && group ? group.hasAttribute( selectors.readyFlag ) : null,
		totalsText: totals ? totals.textContent.replace( /\s+/g, ' ' ).trim() : null,
		totalsVisible: totals ? window.getComputedStyle( totals ).display !== 'none' : null,
		optionsTotal: optionsTotal ? optionsTotal.textContent.trim() : null,
		grandTotal: grandTotal ? grandTotal.textContent.trim() : null,
		engineGlobal: typeof window[ selectors.globalName ],
		opfFieldsGlobal: typeof window.OPF_FIELDS,
		wapfClass: typeof window.WAPF,
		images,
	};
};

const snapshot = ( page, scopeSel = '' ) => page.evaluate( readState, { scopeSel, selectors: SEL } );

const checks = [];
const check = ( label, pass, detail = '' ) => {
	checks.push( { label, pass: !! pass, detail } );
	console.log( `${ pass ? 'ok  ' : 'FAIL' } ${ label }${ detail ? ' (' + detail + ')' : '' }` );
};

const consoleRows = [];
const attachConsole = ( page, phase ) => {
	page.on( 'pageerror', ( error ) => {
		consoleRows.push( { phase, kind: 'pageerror', text: error.message, source: String( error.stack || '' ).split( '\n' ).slice( 0, 3 ).join( ' | ' ) } );
	} );
	page.on( 'console', ( message ) => {
		if ( ! [ 'error', 'warning' ].includes( message.type() ) ) return;
		const location = message.location();
		consoleRows.push( { phase, kind: message.type(), text: message.text(), source: location.url ? `${ location.url }:${ location.lineNumber }` : '' } );
	} );
	page.on( 'requestfailed', ( request ) => {
		consoleRows.push( { phase, kind: 'requestfailed', text: `${ ( request.failure() || {} ).errorText || '' } ${ request.url() }`, source: request.url() } );
	} );
};

/** Attribute a console row to Astra Pro, Astra or the engine by its URL. */
const attribute = ( row ) => {
	const source = row.source || '';
	if ( /astra-addon/.test( source ) ) return 'astra-pro';
	if ( /themes\/astra/.test( source ) ) return 'astra-theme';
	if ( /advanced-product-fields/.test( source ) ) return 'wapf';
	if ( /open-product-fields/.test( source ) ) return 'opf';
	if ( /flexslider/.test( source ) ) return 'woocommerce-flexslider';
	if ( /woocommerce/.test( source ) ) return 'woocommerce';
	if ( /wp-includes/.test( source ) ) return 'wordpress-core';
	if ( /\/wp-content\/uploads\//.test( source ) ) return 'media';
	if ( /astra/i.test( row.text ) ) return 'astra?(text)';
	return 'other';
};

/** The storefront cart is the WooCommerce Cart block: read it through the Store API. */
const storeCartUrl = ( state.store_api && state.store_api.cart ) || `${ base }/wp-json/wc/store/v1/cart`;
const readStoreCart = ( page ) => page.evaluate( async ( url ) => {
	const nonce = ( window.wcSettings || {} ).storeApiNonce || '';
	const response = await fetch( url, { headers: { 'Content-Type': 'application/json', Nonce: nonce } } );
	if ( ! response.ok ) return { ok: false, status: response.status };
	const cart = await response.json();
	return {
		ok: true,
		total: cart.totals && cart.totals.total_price,
		currency: cart.totals && cart.totals.currency_code,
		items: ( cart.items || [] ).map( ( item ) => ( {
			name: item.name,
			quantity: item.quantity,
			item_data: item.item_data,
			variation: ( item.variation || [] ).map( ( v ) => ( { attribute: v.attribute, value: v.value } ) ),
		} ) ),
	};
}, storeCartUrl );

/** Empty the cart through the Store API (each phase also gets a fresh session). */
const emptyCart = async ( page ) => {
	await page.goto( `${ base }/cart/`, { waitUntil: 'domcontentloaded' } );
	await page.evaluate( async ( url ) => {
		const nonce = ( window.wcSettings || {} ).storeApiNonce || '';
		const headers = { 'Content-Type': 'application/json', Nonce: nonce };
		const response = await fetch( url, { headers } );
		if ( ! response.ok ) return;
		const cart = await response.json();
		for ( const item of cart.items || [] ) {
			await fetch( `${ url }/items/${ item.key }`, { method: 'DELETE', headers } );
		}
	}, storeCartUrl );
};

/** Cart page evidence: block-cart markup plus the Store API cart payload. */
const cartEvidence = async ( page ) => {
	const store_api = await readStoreCart( page );
	return {
		itemCount: ( store_api.items || [] ).length,
		domRows: await page.locator( '.wc-block-cart-items__row' ).count(),
		text: ( await page.locator( '.wc-block-cart-items, .woocommerce-cart-form' ).first().innerText().catch( () => '' ) ).replace( /\s+/g, ' ' ).trim().slice( 0, 900 ),
		html_has_gold: ( await page.content() ).includes( 'Gold' ),
		store_api,
	};
};

/** True when the Store API cart carries the `Finish: Gold` line item data. */
const cartCarriesGold = ( cart ) => {
	const items = ( cart.store_api || {} ).items || [];
	if ( 1 !== items.length ) return false;
	// OPF names the row `name`, WAPF 3.1.5 names it `key`.
	return ( items[ 0 ].item_data || [] ).some( ( row ) => /finish/i.test( row.name || row.key || '' ) && /gold/i.test( row.value || '' ) );
};

/** Set a select the way a shopper's change event reaches delegated handlers. */
const setSelect = ( page, selector, value ) => page.evaluate( ( { selector, value } ) => {
	const el = document.querySelector( selector );
	if ( ! el ) throw new Error( `select not found: ${ selector }` );
	el.value = value;
	el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
}, { selector, value } );

const selectOption = async ( page, selector, value ) => {
	await page.selectOption( selector, value );
	await page.waitForTimeout( 400 );
};

const waitImage = async ( page, expectedFile, scopeSel = '', timeout = 6000 ) => {
	const deadline = Date.now() + timeout;
	let images = null;
	while ( Date.now() < deadline ) {
		images = ( await snapshot( page, scopeSel ) ).images;
		if ( images.visible && images.visible.endsWith( expectedFile ) ) break;
		await page.waitForTimeout( 150 );
	}
	return images;
};

const expected = ( key ) => `opf-astra-${ { vsmall: 'v-small', vlarge: 'v-large', rulea: 'rule-a', ruleb: 'rule-b' }[ key ] || key }.png`;

const browser = await chromium.launch();
const result  = {
	engine,
	versions: state.versions,
	started_utc: new Date().toISOString(),
	base_url: base,
	checks,
	single: {},
	modal: {},
	quick_view_ajax: {},
};

try {
	if ( 'control' === engine ) {
		// Astra/Astra Pro baseline: no engine is active, so everything observed
		// here is theme behaviour (used to attribute console errors and the
		// quick-view slider auto-advance).
		const context = await browser.newContext( { viewport: { width: 1400, height: 1000 } } );
		const page    = await context.newPage();
		page.setDefaultTimeout( 20000 );
		attachConsole( page, 'control' );
		await page.goto( fixture.url, { waitUntil: 'domcontentloaded' } );
		await page.waitForTimeout( 1500 );
		result.control = { product_state: await snapshot( page ), timeline: [] };
		for ( let step = 0; step < 6; step++ ) {
			result.control.timeline.push( { t_ms: 1500 + step * 1500, images: ( await snapshot( page ) ).images } );
			await page.waitForTimeout( 1500 );
		}
		await page.goto( state.shop_url || `${ base }/shop/`, { waitUntil: 'domcontentloaded' } );
		await page.waitForTimeout( 1200 );
		await page.evaluate( ( productId ) => {
			const el = document.querySelector( `.ast-quick-view-trigger[data-product_id="${ productId }"], .ast-quick-view-text[data-product_id="${ productId }"], .ast-quick-view-button[data-product_id="${ productId }"]` );
			if ( el ) el.click();
		}, fixture.product );
		await page.waitForSelector( '#ast-quick-view-modal.open', { timeout: 15000 } );
		result.control.modal_timeline = [];
		for ( let step = 0; step < 8; step++ ) {
			result.control.modal_timeline.push( { t_ms: step * 1500, images: ( await snapshot( page, '#ast-quick-view-modal' ) ).images } );
			await page.waitForTimeout( 1500 );
		}
		await page.screenshot( { path: path.join( artifactDir, 'astra-control-modal.png' ) } );
		await context.close();
	} else {
	// ---------------------------------------------------------------- single page
	{
		const context = await browser.newContext( { viewport: { width: 1400, height: 1000 } } );
		const page    = await context.newPage();
		page.setDefaultTimeout( 20000 );
		attachConsole( page, 'single' );
		const tag = `${ engine }/single`;

		await page.goto( fixture.url, { waitUntil: 'domcontentloaded' } );
		await page.waitForTimeout( 1500 );

		const initial = await snapshot( page );
		result.single.initial = initial;
		check( `${ tag }: field group renders on the Astra product page`, initial.fieldCount >= 2 && initial.hasFinish && initial.hasEdge, `fields=${ initial.fieldCount } labels=${ JSON.stringify( initial.labels ) }` );
		check( `${ tag }: priced choices carry their price hints`, initial.choiceTexts.some( ( c ) => /10/.test( c ) && /\+/.test( c ) ), JSON.stringify( initial.choiceTexts ) );
		check( `${ tag }: totals block present`, !! initial.totalsText, initial.totalsText );
		await page.screenshot( { path: path.join( artifactDir, `astra-single-${ engine }-initial.png` ) } );

		// Image rules run with no variation selected (the original image state).
		let images = await waitImage( page, expected( 'main' ) );
		result.single.baseline = images;

		await selectOption( page, SEL.finish, 'gold' );
		images = await waitImage( page, expected( 'rulea' ) );
		check( `${ tag }: image rule finish=gold swaps the main image to rule-a`, !! images.visible && images.visible.endsWith( expected( 'rulea' ) ), fileOf( images.visible ) );
		result.single.ruleA = images;
		await page.screenshot( { path: path.join( artifactDir, `astra-single-${ engine }-rule-a.png` ) } );

		await selectOption( page, SEL.finish, 'none' );
		await selectOption( page, SEL.edge, 'xl' );
		images = await waitImage( page, expected( 'ruleb' ) );
		check( `${ tag }: image rule edge=xl swaps the main image to rule-b`, !! images.visible && images.visible.endsWith( expected( 'ruleb' ) ), fileOf( images.visible ) );

		await selectOption( page, SEL.edge, 'none' );
		images = await waitImage( page, expected( 'main' ) );
		check( `${ tag }: clearing the rules restores the original image`, !! images.visible && images.visible.endsWith( expected( 'main' ) ), fileOf( images.visible ) );

		// Pricing is measured with a variation selected: WAPF derives the product
		// total from the chosen variation (0.00 until one is picked), so this is
		// the only state where both engines are expected to agree.
		await selectOption( page, 'select[name="attribute_size"]', 'small' );
		result.single.with_variation = await snapshot( page );
		await selectOption( page, SEL.finish, 'gold' );
		const priced = await snapshot( page );
		result.single.priced = priced;
		check( `${ tag }: selecting a +10.00 choice updates the options total`, priced.optionsTotal === '$10.00', `options=${ priced.optionsTotal }` );
		check( `${ tag }: grand total follows (100.00 base + 10.00)`, priced.grandTotal === '$110.00', `grand=${ priced.grandTotal }` );

		await emptyCart( page );
		await page.goto( fixture.url, { waitUntil: 'domcontentloaded' } );
		await page.waitForTimeout( 1200 );
		await selectOption( page, 'select[name="attribute_size"]', 'small' );
		await selectOption( page, SEL.finish, 'gold' );
		await page.locator( 'form.cart .single_add_to_cart_button' ).first().click();
		await page.waitForLoadState( 'domcontentloaded' ).catch( () => {} );
		await page.waitForTimeout( 2000 );
		await page.goto( `${ base }/cart/`, { waitUntil: 'domcontentloaded' } );
		await page.waitForTimeout( 800 );
		const cart = await cartEvidence( page );
		result.single.cart = cart;
		await page.screenshot( { path: path.join( artifactDir, `astra-single-${ engine }-cart.png` ) } );
		check( `${ tag }: add-to-cart from the product page carries the field value`, cartCarriesGold( cart ), `items=${ cart.itemCount } item_data=${ JSON.stringify( ( ( cart.store_api.items || [] )[ 0 ] || {} ).item_data || null ) }` );

		result.single.console = consoleRows.filter( ( row ) => 'single' === row.phase ).map( ( row ) => ( { ...row, attributed_to: attribute( row ) } ) );
		await context.close();
	}

	// ------------------------------------------------------------ quick-view modal
	{
		const context = await browser.newContext( { viewport: { width: 1400, height: 1000 } } );
		const page    = await context.newPage();
		page.setDefaultTimeout( 20000 );
		attachConsole( page, 'modal' );

		await emptyCart( page );
		await page.goto( state.shop_url || `${ base }/shop/`, { waitUntil: 'domcontentloaded' } );
		await page.waitForTimeout( 1200 );
		const trigger = page.locator( `.ast-quick-view-trigger[data-product_id="${ fixture.product }"], .ast-quick-view-text[data-product_id="${ fixture.product }"], .ast-quick-view-button[data-product_id="${ fixture.product }"]` ).first();
		check( `${ engine }/modal: the Astra shop card exposes a quick-view trigger`, ( await trigger.count() ) > 0 );
		await page.screenshot( { path: path.join( artifactDir, `astra-shop-${ engine }.png` ) } );
		// The Astra shop card action is hover-revealed; fall back to an in-page
		// click (Astra binds a native click listener, so currentTarget is set).
		await page.locator( `li.product:has([data-product_id="${ fixture.product }"])` ).first().hover().catch( () => {} );
		await trigger.click( { timeout: 5000 } ).catch( async () => {
			await page.evaluate( ( id ) => {
				const el = document.querySelector( `.ast-quick-view-trigger[data-product_id="${ id }"], .ast-quick-view-text[data-product_id="${ id }"], .ast-quick-view-button[data-product_id="${ id }"]` );
				if ( el ) el.click();
			}, fixture.product );
		} );
		await page.waitForSelector( '#ast-quick-view-modal.open', { timeout: 15000 } );
		await page.waitForTimeout( 1500 );
		const tag = `${ engine }/modal`;

		const opened = await snapshot( page, '#ast-quick-view-modal' );
		result.modal.opened = opened;
		check( `${ tag }: quick-view modal opened with the product form`, opened.present && !! opened.hasFinish, `fields=${ opened.fieldCount }` );
		check( `${ tag }: field group renders inside the modal`, opened.fieldCount >= 2 && !! opened.hasEdge, `fields=${ opened.fieldCount } labels=${ JSON.stringify( opened.labels ) }` );
		check( `${ tag }: modal engine global present in the page`, 'undefined' !== opened.engineGlobal, `${ SEL.globalName }=${ opened.engineGlobal } window.OPF_FIELDS=${ opened.opfFieldsGlobal } window.WAPF=${ opened.wapfClass }` );
		if ( SEL.readyFlag ) {
			check( `${ tag }: engine marked the injected group as initialized`, !! opened.startedFlag, `${ SEL.readyFlag }=${ opened.startedFlag }` );
		}
		await page.screenshot( { path: path.join( artifactDir, `astra-modal-${ engine }-open.png` ) } );

		// The modal is driven through native change events (the Astra modal image is
		// a flexslider; pointer interaction with the summary can navigate it).
		// A variation is chosen first, then the priced field: this is the state the
		// quick-view adapter has to support.
		await setSelect( page, '#ast-quick-view-modal select[name="attribute_size"]', 'small' );
		await page.waitForTimeout( 600 );
		result.modal.with_variation = await snapshot( page, '#ast-quick-view-modal' );
		await setSelect( page, `#ast-quick-view-modal ${ SEL.finish }`, 'gold' );
		await page.waitForTimeout( 500 );
		const priced = await snapshot( page, '#ast-quick-view-modal' );
		result.modal.priced = priced;
		check( `${ tag }: priced choice updates the modal totals`, priced.optionsTotal === '$10.00' && priced.grandTotal === '$110.00', `options=${ priced.optionsTotal } grand=${ priced.grandTotal }` );

		const modalImages = await waitImage( page, expected( 'rulea' ), '#ast-quick-view-modal', 3000 );
		result.modal.ruleA = modalImages;
		check( `${ tag }: image rule swaps the modal image to rule-a`, !! modalImages.visible && modalImages.visible.endsWith( expected( 'rulea' ) ), fileOf( modalImages.visible ) );
		await page.screenshot( { path: path.join( artifactDir, `astra-modal-${ engine }-rule-a.png` ) } );

		// Astra Pro's quick-view slider advances to the first gallery image on its
		// own a few seconds after the modal opens (see the `control` run), so the
		// image is re-read after an idle wait to keep that theme behaviour visible
		// in the artifact instead of mistaking it for an engine result.
		await page.waitForTimeout( 6000 );
		result.modal.image_after_idle_6s = ( await snapshot( page, '#ast-quick-view-modal' ) ).images;

		await setSelect( page, '#ast-quick-view-modal select[name="attribute_size"]', 'small' );
		await page.waitForTimeout( 400 );
		await page.locator( '#ast-quick-view-modal form.cart .single_add_to_cart_button' ).first().click();
		await page.waitForLoadState( 'domcontentloaded' ).catch( () => {} );
		await page.waitForTimeout( 2500 );
		await page.goto( `${ base }/cart/`, { waitUntil: 'domcontentloaded' } );
		await page.waitForTimeout( 800 );
		const cart = await cartEvidence( page );
		result.modal.cart = cart;
		await page.screenshot( { path: path.join( artifactDir, `astra-modal-${ engine }-cart.png` ) } );
		check( `${ tag }: add-to-cart from the modal carries the field value`, cartCarriesGold( cart ), `items=${ cart.itemCount } item_data=${ JSON.stringify( ( ( cart.store_api.items || [] )[ 0 ] || {} ).item_data || null ) }` );

		// Raw AJAX evidence: the markup the modal receives, plus whether that
		// response carries an inline registry script the browser cannot execute
		// through innerHTML.
		result.quick_view_ajax = await page.evaluate( async ( { url, nonce, productId } ) => {
			const body = new URLSearchParams( { action: 'ast_load_product_quick_view', nonce, product_id: String( productId ) } );
			const response = await fetch( url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body } );
			const text = await response.text();
			const at = ( needle ) => text.indexOf( needle );
			const anchors = [ at( 'data-opf-fields' ), at( 'wapf-wrapper' ) ].filter( ( i ) => i >= 0 );
			const start = anchors.length ? Math.min( ...anchors ) : 0;
			return {
				status: response.status,
				length: text.length,
				has_opf_markup: -1 !== at( 'data-opf-group' ),
				has_wapf_markup: -1 !== at( 'wapf-field-group' ),
				has_inline_registry_script: -1 !== at( 'window.OPF_FIELDS' ),
				has_script_tag: -1 !== at( '<script' ),
				has_wapf_config: -1 !== at( 'wapf_config' ),
				excerpt: text.slice( Math.max( 0, start - 200 ), start + 700 ).replace( /\s+/g, ' ' ),
			};
		}, {
			url: `${ base }/wp-admin/admin-ajax.php`,
			nonce: await page.evaluate( () => ( window.astra || {} ).quick_view_nonce || '' ),
			productId: fixture.product,
		} );

		result.modal.console = consoleRows.filter( ( row ) => 'modal' === row.phase ).map( ( row ) => ( { ...row, attributed_to: attribute( row ) } ) );
		await context.close();
	}
	}
} finally {
	// Write even when a phase throws: a partial run is still evidence.
	result.finished_utc = new Date().toISOString();
	result.console_attribution = consoleRows.map( ( row ) => ( { ...row, attributed_to: attribute( row ) } ) );
	fs.writeFileSync( path.join( artifactDir, `astra-${ engine }-browser.json` ), JSON.stringify( result, null, 2 ) );
	await browser.close();
}

const failed = checks.filter( ( c ) => ! c.pass );
console.log( `\n${ checks.length - failed.length }/${ checks.length } checks passed for ${ engine }; ${ consoleRows.length } console rows recorded.` );
if ( failed.length ) {
	process.exitCode = 1;
}
