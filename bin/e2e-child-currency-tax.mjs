// Real-currency-plugin storefront proof for linked child products.
//
// Drives the disposable loopback clone over real HTTP with CURCY (VillaTheme
// Multi Currency 2.4.3) active: currency cookie switch -> classic product page
// render -> classic add-to-cart -> cart -> classic checkout -> order, and the
// Blocks (Store API) add-to-cart + checkout path. Loopback clone only.
import fs from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire( ( process.env.OPF_PLAYWRIGHT_ROOT || '/home/followersya-5hqi7/followersya.com/node_modules' ) + '/index.js' );
const { chromium } = require( 'playwright' );

const base = process.env.OPF_CHILD_CUR_BASE_URL || 'http://127.0.0.1:8431';
if ( ! /^http:\/\/127\.0\.0\.1:\d+$/.test( base ) ) {
	throw new Error( 'Loopback clone required.' );
}
const dir = process.env.OPF_CHILD_CUR_ARTIFACT_DIR || '/tmp/opf-child-currency-evidence';
const scenario = process.env.OPF_CHILD_CUR_SCENARIO || 'eur-base-tax';
const state = JSON.parse( fs.readFileSync( dir + '/state.json', 'utf8' ) );
const gid = String( state.group );
const parentUrl = base + '/product/opf-currency-parent/';
const checkoutUrl = base + '/opf-currency-checkout/';
const productSlugs = [ 'child-alpha', 'child-beta', 'child-gamma' ];
const eur = scenario.startsWith( 'eur' );
const blocks = scenario.endsWith( '-blocks' );
const minor = ( amount, unit ) => Number( amount ) / 10 ** unit;

const checks = [];
const check = ( label, pass, extra ) => {
	checks.push( { label, pass: !! pass, extra: extra ?? null } );
	console.log( `${ pass ? 'ok' : 'FAIL' } ${ label }` );
	if ( ! pass ) throw new Error( label );
};

const form = ( pairs ) => {
	const body = new URLSearchParams();
	for ( const [ k, v ] of pairs ) body.append( k, v );
	return body.toString();
};
const postForm = ( req, url, pairs ) => req.post( url, {
	headers: { 'content-type': 'application/x-www-form-urlencoded' },
	data: form( pairs ),
} );

const cartSnapshot = ( cart ) => {
	const unit = cart.totals.currency_minor_unit;
	return {
		currency_code: cart.totals.currency_code,
		currency_symbol: cart.totals.currency_symbol,
		items: cart.items.map( ( i ) => ( {
			name: i.name,
			key: i.key,
			quantity: i.quantity,
			unit: minor( i.prices.price, unit ),
			line_total: minor( i.totals.line_total, unit ),
			line_tax: minor( i.totals.line_total_tax, unit ),
			child: !! i.extensions?.opf?.childItem,
		} ) ),
		totals: {
			total_items: minor( cart.totals.total_items, unit ),
			total_items_tax: minor( cart.totals.total_items_tax, unit ),
			total_tax: minor( cart.totals.total_tax, unit ),
			total_price: minor( cart.totals.total_price, unit ),
		},
	};
};
const fieldAddon = ( snapshot ) => snapshot.items.filter( ( i ) => i.child ).reduce( ( sum, i ) => sum + i.line_total, 0 );

const browser = await chromium.launch( { headless: true } );
const out = { scenario, base, eur, blocks, checks, product_page: null, cart: null, order: null, blocks_result: null };
try {
	const context = await browser.newContext();
	const req = context.request;
	// Real CURCY switch: GET ?wmc-currency=EUR writes the wmc_current_currency cookie.
	if ( eur ) {
		const switchRes = await req.get( base + '/?wmc-currency=EUR' );
		check( 'CURCY currency switch request served', switchRes.ok() );
	}

	// Classic product page render (real HTML, real CURCY conversion).
	const page = await context.newPage();
	const pageRes = await page.goto( parentUrl, { waitUntil: 'domcontentloaded' } );
	check( 'classic product page served', pageRes.ok() );
	await page.locator( '.opf-products--card .opf-card' ).first().waitFor( { timeout: 15000 } );
	const cards = await page.locator( '.opf-products--card .opf-card' ).evaluateAll( ( rows ) => rows.map( ( r ) => ( {
		name: r.querySelector( '.opf-card-title span' )?.textContent.trim(),
		price: r.querySelector( '.opf-card-price span' )?.textContent.trim(),
		slug: r.querySelector( 'input.opf-product-input' )?.getAttribute( 'value' ),
	} ) ) );
	const html = await page.content();
	const expectedSymbol = eur ? '&euro;' : '&#36;';
	out.product_page = {
		url: parentUrl,
		currency_symbol: eur ? '€' : '$',
		body_has_symbol: html.includes( expectedSymbol ),
		body_currency_code: html.includes( 'data-opf-currency="EUR"' ) ? 'EUR' : ( eur ? 'unmarked' : 'USD' ),
		parent_price: await page.locator( 'p.price .woocommerce-Price-amount' ).first().textContent().catch( () => null ),
		child_cards: cards,
	};
	check( `product page renders ${cards.length} linked child cards`, cards.length === 3, cards );
	check( `product page uses the ${ eur ? 'EUR' : 'USD'} symbol`, out.product_page.body_has_symbol );
	await page.screenshot( { path: dir + `/product-${ scenario }.png`, fullPage: true } );

	// Classic add-to-cart with the linked selection (classic flow only; the
	// Blocks scenario builds its own Store API cart below).
	if ( ! blocks ) {
		const addPairs = [ [ 'add-to-cart', String( state.parent ) ], [ 'quantity', '2' ] ];
		for ( const slug of productSlugs ) addPairs.push( [ `opf[${ gid }][linked_cards][]`, slug ] );
		const addRes = await postForm( req, parentUrl, addPairs );
		check( 'classic add-to-cart request accepted', addRes.ok() );

		const cartRes = await req.get( base + '/?rest_route=/wc/store/v1/cart' );
		const cart = await cartRes.json();
		out.cart = cartSnapshot( cart );
		out.cart.field_addon = fieldAddon( out.cart );
		check( 'cart holds parent + 3 native child lines', out.cart.items.length === 4, out.cart.items );
		check( 'child lines carry the OPF child marker', out.cart.items.filter( ( i ) => i.child ).length === 3 );
		check( `cart currency is ${ eur ? 'EUR' : 'USD' }`, out.cart.currency_code === ( eur ? 'EUR' : 'USD' ) );
		fs.writeFileSync( dir + `/cart-${ scenario }.json`, JSON.stringify( out.cart, null, 2 ) );
	}

	if ( ! blocks ) {
		// Classic checkout via wc-ajax (real checkout page nonce + session).
		const checkoutPageRes = await req.get( checkoutUrl );
		check( 'classic checkout page served', checkoutPageRes.ok() );
		const checkoutHtml = await checkoutPageRes.text();
		const nonce = checkoutHtml.match( /name="woocommerce-process-checkout-nonce" value="([^"]+)"/ )?.[ 1 ];
		check( 'classic checkout nonce present', !! nonce );
		const checkoutRes = await postForm( req, base + '/?wc-ajax=checkout', [
			[ 'woocommerce-process-checkout-nonce', nonce ],
			[ 'billing_first_name', 'Test' ], [ 'billing_last_name', 'Buyer' ],
			[ 'billing_country', 'US' ], [ 'billing_address_1', '1 Test Street' ],
			[ 'billing_city', 'Testville' ], [ 'billing_state', 'CA' ],
			[ 'billing_postcode', '90210' ], [ 'billing_phone', '5551234567' ],
			[ 'billing_email', 'child-cur@example.invalid' ],
			[ 'payment_method', 'bacs' ], [ 'terms', 'on' ],
		] );
		const body = await checkoutRes.json();
		out.order = { result: body.result, order_id: Number( body.order_id ) || 0, redirect: body.redirect || null, messages: body.messages || null };
		check( 'classic checkout succeeds', body.result === 'success', body );
		check( 'checkout created an order', out.order.order_id > 0 );
	} else {
		// Blocks (Store API) path: add-item + checkout with child selections.
		const initial = await req.get( base + '/?rest_route=/wc/store/v1/cart' );
		check( 'empty Store API cart available', initial.ok() );
		const nonce = initial.headers().nonce;
		const addBody = { id: state.parent, quantity: 2, opf_fields: { [ gid ]: { linked_cards: productSlugs } } };
		const storeAdd = await req.post( base + '/?rest_route=/wc/store/v1/cart/add-item', { headers: { Nonce: nonce }, data: addBody } );
		const storeAddJson = await storeAdd.json();
		out.blocks_result = { add_status: storeAdd.status(), add_ok: storeAdd.ok(), cart: null, checkout: null };
		check( 'Store API add-item accepted with OPF selection', storeAdd.ok() && storeAdd.status() === 201, storeAddJson );
		const storeCart = await storeAddJson;
		out.blocks_result.cart = cartSnapshot( storeCart );
		check( 'Store API cart holds parent + 3 child lines', out.blocks_result.cart.items.length === 4, out.blocks_result.cart.items );
		check( 'Store API child lines carry the OPF marker', out.blocks_result.cart.items.filter( ( i ) => i.child ).length === 3 );
		const address = { first_name: 'OPF', last_name: 'Child', country: 'US', address_1: '1 Test Street', city: 'Testville', state: 'CA', postcode: '90210', phone: '5551234567', email: 'opf-child-blocks@example.invalid' };
		const storeCheckout = await req.post( base + '/?rest_route=/wc/store/v1/checkout', { headers: { Nonce: storeAdd.headers().nonce }, data: { billing_address: address, shipping_address: address, payment_method: 'bacs', payment_data: [] } } );
		const storeResult = await storeCheckout.json();
		out.blocks_result.checkout = { status: storeCheckout.status(), ok: storeCheckout.ok(), order_id: Number( storeResult.order_id ) || 0, payment_status: storeResult.payment_result?.payment_status ?? null, body: storeResult };
		out.order = { result: storeCheckout.ok() && storeResult.order_id > 0 ? 'success' : 'failure', order_id: Number( storeResult.order_id ) || 0, redirect: storeResult.payment_result?.redirect_url ?? null };
		check( 'Block Store API checkout succeeds', storeCheckout.ok() && storeResult.order_id > 0, storeResult );
	}
	fs.writeFileSync( dir + `/scenario-${ scenario }.json`, JSON.stringify( out, null, 2 ) );
	if ( out.order?.order_id ) {
		const ownedPath = dir + '/owned-orders.json';
		const owned = fs.existsSync( ownedPath ) ? JSON.parse( fs.readFileSync( ownedPath, 'utf8' ) ) : [];
		if ( ! owned.includes( out.order.order_id ) ) owned.push( out.order.order_id );
		fs.writeFileSync( ownedPath, JSON.stringify( owned, null, 2 ) );
	}
	await context.close();
	console.log( `SUCCESS ${ scenario } order=${ out.order?.order_id }` );
} finally {
	await browser.close();
}
