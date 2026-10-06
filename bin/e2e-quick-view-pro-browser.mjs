// Live Chromium proof for the OPF / WAPF-Extended comparison inside the Barn2
// Quick View Pro modal (ledger row WAPF-COMPAT-QUICK-VIEW-PRO).
//
// Runs the same sequence against one fixture product per engine (see
// bin/e2e-quick-view-pro-fixture.php for the field data and image rules):
//
//   a) the field group renders inside the modal,
//   b) a priced choice changes the modal's own totals node,
//   c) the image rules swap the modal gallery image,
//   d) add-to-cart from the modal submits the field payload and the cart line
//      carries the field values,
//   e) JS console errors, each tagged with the engine under test.
//
// Scenario A (OPF_QV_SCENARIO=a, default) is the realistic third-party
// touchpoint: the quick-view trigger lives on a page that renders no OPF/WAPF
// fields at all. Scenario B (b) opens the same modal from a page that already
// renders the engine's fields inline, so the engine's frontend bundle is
// loaded and has already initialised once.
//
// Usage:
//   OPF_QV_STATE=docs/compatibility/quick-view-pro-20261006/fixture-state.json \
//   OPF_QV_ENGINE=opf OPF_QV_SCENARIO=a \
//   NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules \
//     node bin/e2e-quick-view-pro-browser.mjs
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire( process.cwd() + '/index.php' );
const { chromium } = require( 'playwright' );

const statePath = process.env.OPF_QV_STATE || 'docs/compatibility/quick-view-pro-20261006/fixture-state.json';
const engine = ( process.env.OPF_QV_ENGINE || 'opf' ).toLowerCase();
const scenario = ( process.env.OPF_QV_SCENARIO || 'a' ).toLowerCase();
const outDir = process.env.OPF_QV_ARTIFACTS || path.dirname( statePath );

const state = JSON.parse( fs.readFileSync( statePath, 'utf8' ) );
const product = state.products[ engine ];
if ( ! product ) throw new Error( `Unknown engine ${ engine }` );

const base = state.base_url;
if ( ! /^http:\/\/127\.0\.0\.1:\d+$/.test( base ) ) {
	throw new Error( `Loopback clone required, got ${ base }` );
}
const pageUrl = 'b' === scenario ? state.page_inline_url : state.page_url;
const productId = product.product;

const checks = [];
const check = ( label, pass, detail = '' ) => {
	checks.push( { label, pass: !! pass, detail: String( detail ) } );
	console.log( `${ pass ? 'ok  ' : 'FAIL' } ${ label }${ detail ? '  (' + detail + ')' : '' }` );
};

const fileOf = ( url ) => ( ! url ? url : String( url ).split( '/' ).pop() );

const money = ( text ) => {
	const match = String( text || '' ).replace( /\u00a0/g, ' ' ).match( /-?\d[\d.,]*/ );
	return match ? Number( match[0].replace( /,(?=\d{3}\b)/g, '' ) ) : NaN;
};

const modalRoot = `.jquery-modal #quick-view-${ productId }`;

const readActiveImage = ( page ) => page.evaluate( ( root ) => {
	const scope = document.querySelector( root ) || document;
	const gallery = scope.querySelector( '.woocommerce-product-gallery' ) || scope;
	for ( const marker of [ '.flex-active-slide', '.slick-current', '.swiper-slide-active', '.woocommerce-product-gallery__image:not(.clone)' ] ) {
		const el = gallery.querySelector( marker );
		const img = el && ( el.matches && el.matches( 'img' ) ? el : el.querySelector( 'img' ) );
		if ( img ) return img.getAttribute( 'data-large_image' ) || img.getAttribute( 'src' ) || 'NONE';
	}
	const main = gallery.querySelector( 'img' );
	return main ? ( main.getAttribute( 'data-large_image' ) || main.getAttribute( 'src' ) || 'NONE' ) : 'NONE';
}, modalRoot );

const browser = await chromium.launch();
const result = {
	engine,
	scenario,
	versions: state.versions || {},
	product_id: productId,
	page_url: pageUrl,
	checks: [],
	console_messages: [],
	page_errors: [],
	request_failures: [],
	add_to_cart_request: null,
	add_to_cart_response: null,
	non_get_requests: [],
	cart: null,
	totals_trace: [],
	image_trace: [],
	globals: null,
};

try {
	const context = await browser.newContext( { viewport: { width: 1280, height: 1000 } } );
	const page = await context.newPage();
	page.setDefaultTimeout( 30000 );

	page.on( 'console', ( message ) => {
		if ( [ 'error', 'warning' ].includes( message.type() ) ) {
			result.console_messages.push( { type: message.type(), text: message.text(), location: message.location() } );
		}
	} );
	page.on( 'pageerror', ( error ) => {
		result.page_errors.push( { message: error.message, stack: String( error.stack || '' ).split( '\n' ).slice( 0, 3 ).join( ' | ' ) } );
	} );
	page.on( 'requestfailed', ( request ) => {
		result.request_failures.push( { url: request.url(), error: request.failure()?.errorText } );
	} );

	const cartRequests = [];
	page.on( 'request', ( request ) => {
		if ( request.url().includes( '/wc-quick-view-pro/v1/cart' ) ) {
			cartRequests.push( { url: request.url(), postData: request.postData() } );
		}
		if ( 'GET' !== request.method() && ! request.url().match( /\.(png|jpe?g|webp|css|js|woff2?)(\?|$)/ ) ) {
			result.non_get_requests.push( { method: request.method(), url: request.url(), postData: ( request.postData() || '' ).slice( 0, 400 ) } );
		}
	} );

	await page.goto( pageUrl, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( `a.wc-quick-view-button[data-product_id="${ productId }"]` );
	await page.waitForTimeout( 600 );

	result.globals = await page.evaluate( () => ( {
		opf_fields: typeof window.OPF_FIELDS,
		opf_image_rules: typeof window.OPF_IMAGE_RULES,
		opf_config: typeof window.opf_config,
		wapf_global: typeof window.WAPF,
		wapf_config: typeof window.wapf_config,
		has_opf_frontend_script: !!document.querySelector( 'script[src*="opf-frontend.js"], link[href*="opf-frontend.css"]' ),
		has_wapf_frontend_script: !!document.querySelector( 'script[src*="wapf/frontend.min.js"], script[src*="assets/js/frontend.min.js"]' ),
		fields_on_page_before_modal: document.querySelectorAll( '[data-opf-group], .wapf-field-group' ).length,
	} ) );
	check(
		'engine frontend bundle present on the trigger page',
		'opf' === engine ? result.globals.has_opf_frontend_script : result.globals.has_wapf_frontend_script,
		`opf=${ result.globals.has_opf_frontend_script } wapf=${ result.globals.has_wapf_frontend_script }`
	);

	// Open the modal through the plugin's own trigger (real `quick_view_pro:load`).
	await page.click( `a.wc-quick-view-button[data-product_id="${ productId }"]` );
	await page.waitForSelector( modalRoot, { state: 'attached' } );
	await page.waitForTimeout( 1500 );

	// 1. Fields render inside the modal.
	const fieldState = await page.evaluate( ( root ) => {
		const modal = document.querySelector( root );
		const scope = modal || document;
		const group = scope.querySelector( '[data-opf-group], .wapf-field-group' );
		return {
			group_count: scope.querySelectorAll( '[data-opf-group], .wapf-field-group' ).length,
			finish_count: scope.querySelectorAll( 'select[name*="finish"], select[name="wapf[field_finish]"]' ).length,
			edge_count: scope.querySelectorAll( 'select[name*="edge"], select[name="wapf[field_edge]"]' ).length,
			totals_count: scope.querySelectorAll( '.opf-product-totals, .wapf-product-totals' ).length,
			group_id: group ? ( group.getAttribute( 'data-opf-group' ) || group.getAttribute( 'data-group' ) ) : null,
			js_bound: group ? !!group.dataset.opfInitialized || !!window.WAPF : false,
		};
	}, modalRoot );
	check( 'modal renders the field group', fieldState.group_count >= 1, `groups=${ fieldState.group_count }` );
	check( 'modal renders both select fields', fieldState.finish_count >= 1 && fieldState.edge_count >= 1, `finish=${ fieldState.finish_count } edge=${ fieldState.edge_count }` );
	check( 'modal renders a totals node', fieldState.totals_count >= 1, `totals=${ fieldState.totals_count }` );

	const selectFinish = async ( value ) => {
		await page.selectOption( `${ modalRoot } ${ product.selector }`, value );
		await page.waitForTimeout( 700 );
	};
	const selectEdge = async ( value ) => {
		await page.selectOption( `${ modalRoot } ${ product.edge }`, value );
		await page.waitForTimeout( 700 );
	};
	const selectVariation = async ( value ) => {
		await page.selectOption( `${ modalRoot } select[name="attribute_size"]`, value );
		await page.waitForTimeout( 900 );
	};

	const totalsText = () => page.evaluate( ( root ) => {
		const modal = document.querySelector( root );
		const el = modal && modal.querySelector( '.opf-product-totals .opf-grand-total, .wapf-product-totals .wapf-grand-total' );
		const node = modal && modal.querySelector( '.opf-product-totals, .wapf-product-totals' );
		return {
			text: el ? el.textContent.trim() : '',
			options_text: ( modal && modal.querySelector( '.opf-options-total, .wapf-options-total' ) )?.textContent.trim() ?? '',
			display: node ? getComputedStyle( node ).display : 'missing',
			visibility: node ? getComputedStyle( node ).visibility : 'missing',
			inline_style: node ? node.getAttribute( 'style' ) : null,
			visible: node ? !!( node.offsetWidth || node.offsetHeight || node.getClientRects().length ) : false,
		};
	}, modalRoot );

	// 2. Priced choices drive the modal totals.
	await selectVariation( 'small' );
	const t0 = await totalsText();
	result.totals_trace.push( { step: 'initial (finish=none, edge=none, size=small)', ...t0 } );
	await selectFinish( 'gold' );
	const t1 = await totalsText();
	result.totals_trace.push( { step: 'finish=gold', ...t1 } );
	await selectFinish( 'silver' );
	const t2 = await totalsText();
	result.totals_trace.push( { step: 'finish=silver', ...t2 } );
	await selectFinish( 'none' );
	const t3 = await totalsText();
	result.totals_trace.push( { step: 'finish=none again', ...t3 } );

	const baseTotal = money( t0.text );
	const goldTotal = money( t1.text );
	const silverTotal = money( t2.text );
	check( 'totals node has a value', Number.isFinite( baseTotal ), `text="${ t0.text }"` );
	check(
		'priced choice raises the modal total by the choice price (+15)',
		Number.isFinite( goldTotal ) && Math.abs( goldTotal - ( baseTotal + state.prices.gold ) ) < 0.02,
		`${ baseTotal } -> ${ goldTotal }`
	);
	check(
		'a second priced choice prices independently (+5)',
		Number.isFinite( silverTotal ) && Math.abs( silverTotal - ( baseTotal + state.prices.silver ) ) < 0.02,
		`${ baseTotal } -> ${ silverTotal }`
	);
	check( 'clearing the choice restores the base total', Number.isFinite( money( t3.text ) ) && Math.abs( money( t3.text ) - baseTotal ) < 0.02, `${ goldTotal } -> ${ money( t3.text ) }` );

	// 3. Image rules swap the modal gallery image.
	const original = fileOf( await readActiveImage( page ) );
	await selectFinish( 'gold' );
	const afterGold = fileOf( await readActiveImage( page ) );
	result.image_trace.push( { step: 'finish=gold', image: afterGold } );
	await selectFinish( 'none' );
	await selectEdge( 'xl' );
	const afterEdge = fileOf( await readActiveImage( page ) );
	result.image_trace.push( { step: 'finish=none edge=xl', image: afterEdge } );
	await selectEdge( 'none' );
	await selectVariation( 'large' );
	const afterVariation = fileOf( await readActiveImage( page ) );
	result.image_trace.push( { step: 'size=large', image: afterVariation } );
	await selectVariation( 'small' );
	const restored = fileOf( await readActiveImage( page ) );
	result.image_trace.push( { step: 'size=small (restored)', image: restored } );

	check( 'rule finish=gold swaps the modal image to rule-a', 'opf-qv-rule-a.png' === afterGold, `${ original } -> ${ afterGold }` );
	check( 'rule edge=xl swaps the modal image to rule-b', 'opf-qv-rule-b.png' === afterEdge, `-> ${ afterEdge }` );
	check( 'variation image resolves when no rule matches', 'opf-qv-v-large.png' === afterVariation, `-> ${ afterVariation }` );

	await page.screenshot( { path: path.join( outDir, `quick-view-${ engine }-scenario-${ scenario }.png` ), fullPage: false } );

	// 4. Add to cart from the modal carries the field values (single-value select
	// plus a multi-value `name[]` checkbox, the shape the QV serializer rebuilds).
	await selectVariation( 'small' );
	await selectFinish( 'gold' );
	await page.check( `${ modalRoot } ${ product.gift }` );
	await page.waitForTimeout( 500 );
	await page.click( `${ modalRoot } .single_add_to_cart_button` );
	await page.waitForTimeout( 4000 );

	result.add_to_cart_request = cartRequests.length ? cartRequests[ cartRequests.length - 1 ] : null;
	result.url_after_add = page.url();
	result.modal_open_after_add = await page.evaluate( () => !!document.querySelector( '.jquery-modal:not([style*="display: none"])' ) ).catch( () => null );
	check( 'quick-view add-to-cart request carries the field payload', !!result.add_to_cart_request && /(?:^|&)opf(?:%5B|\[)/i.test( decodeURIComponent( result.add_to_cart_request.postData || '' ) ) || /(?:^|&)wapf(?:%5B|\[)/i.test( decodeURIComponent( result.add_to_cart_request?.postData || '' ) ), result.add_to_cart_request ? decodeURIComponent( result.add_to_cart_request.postData || '' ).slice( 0, 240 ) : 'no request captured' );

	await page.goto( `${ base }/cart/`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.wc-block-cart-items', { timeout: 30000 } ).catch( () => {} );
	await page.waitForTimeout( 2500 );
	result.cart = await page.evaluate( () => {
		const rows = Array.from( document.querySelectorAll( '.wc-block-cart-items__row' ) );
		return rows.map( ( row ) => ( {
			product: row.querySelector( '.wc-block-components-product-name' )?.textContent.trim() ?? '',
			details: Array.from( row.querySelectorAll( '.wc-block-components-product-details' ) ).map( ( li ) => li.textContent.trim() ),
			total: row.querySelector( '.wc-block-cart-item__total' )?.textContent.trim() ?? '',
		} ) );
	} );

	const line = result.cart.find( ( row ) => row.details.length );
	check( 'cart line carries the submitted field values', !!line, JSON.stringify( result.cart ) );
	if ( line ) {
		const expected = state.prices.base + state.prices.gold + state.prices.gift;
		check( 'cart line prices the chosen options (+15 select, +2 checkbox)', Math.abs( money( line.total.replace( /[^\d.,]/g, '' ) ) - expected ) < 0.02, `total="${ line.total }" expected=${ expected }` );
		check( 'cart line lists both the select and the checkbox value', line.details.some( ( d ) => /gift/i.test( d ) ) && line.details.some( ( d ) => /gold/i.test( d ) ), JSON.stringify( line.details ) );
	}

	await context.close();
} catch ( error ) {
	result.fatal = `${ error.message }\n${ String( error.stack || '' ).split( '\n' ).slice( 0, 4 ).join( '\n' ) }`;
	console.error( result.fatal );
}

await browser.close();

result.checks = checks;
result.passed = checks.filter( ( c ) => c.pass ).length;
result.failed = checks.filter( ( c ) => ! c.pass ).length;

fs.mkdirSync( outDir, { recursive: true } );
const outFile = path.join( outDir, `quick-view-${ engine }-scenario-${ scenario }-results.json` );
fs.writeFileSync( outFile, JSON.stringify( result, null, 2 ) );
console.log( `\n${ result.passed }/${ checks.length } checks passed — ${ outFile }` );
process.exit( result.failed ? 1 : 0 );
