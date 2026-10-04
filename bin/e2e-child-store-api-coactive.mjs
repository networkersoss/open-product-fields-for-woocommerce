// Store API checkout coexistence proof; run only against a loopback clone.
import fs from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire( process.env.OPF_PLAYWRIGHT_ROOT + '/index.js' );
const { chromium } = require( 'playwright' );
const base = process.env.OPF_CHILD_STORE_API_BASE_URL || 'http://127.0.0.1:8423';
if ( ! /^http:\/\/127\.0\.0\.1:\d+$/.test( base ) ) throw new Error( 'Loopback clone required.' );
const dir = process.env.OPF_CHILD_STORE_API_ARTIFACTS || '/tmp/opf-child-store-api-order-evidence';
const state = JSON.parse( fs.readFileSync( dir + '/state.json', 'utf8' ) );
const checks = [];
const check = ( label, pass ) => {
	checks.push( { label, pass: !! pass } );
	console.log( `${ pass ? 'ok' : 'FAIL' } ${ label }` );
	if ( ! pass ) throw new Error( label );
};
const money = ( value, minor ) => Number( value ) / ( 10 ** minor );
const browser = await chromium.launch( { headless: true } );
try {
	const context = await browser.newContext();
	const cartUrl = base + '/?rest_route=/wc/store/v1/cart';
	const initial = await context.request.get( cartUrl );
	check( 'empty guest Store API cart available with WAPF active', initial.ok() && ( await initial.json() ).items.length === 0 );
	const payload = { [ state.group ]: {
		fixed_card: [ String( state.alpha ) ],
		none_card: [ String( state.beta ) ],
		fixed_qty: { [ state.beta ]: 3 },
		none_qty: { [ state.alpha ]: 4 },
	} };
	const add = await context.request.post( base + '/?rest_route=/wc/store/v1/cart/add-item', {
		headers: { Nonce: initial.headers().nonce },
		data: { id: state.parent, quantity: 2, opf_fields: payload },
	} );
	check( 'Store API add-item succeeds with OPF and WAPF both active', add.ok() && add.status() === 201 );
	let cart = await add.json();
	check( 'Store API cart has parent plus four native child lines', cart.items.length === 5 );
	const children = cart.items.filter( item => item.extensions?.opf?.childItem );
	check( 'Store API exposes all four OPF child markers', children.length === 4 );
	check( 'Store API priced and free child lines total USD 92', money( cart.totals.total_price, cart.totals.currency_minor_unit ) === 92 );
	const address = { first_name: 'OPF', last_name: 'WAPF Coactive', country: 'US', address_1: '1 Test Street', city: 'Testville', state: 'CA', postcode: '90210', phone: '5551234567', email: 'opf-wapf-child@example.invalid' };
	const checkout = await context.request.post( base + '/?rest_route=/wc/store/v1/checkout', {
		headers: { Nonce: add.headers().nonce },
		data: { billing_address: address, shipping_address: address, payment_method: 'bacs', payment_data: [] },
	} );
	const result = await checkout.json();
	check( 'real Store API checkout succeeds with both plugins active: ' + JSON.stringify( { status: checkout.status(), result } ), checkout.ok() && result.order_id > 0 && result.payment_result?.payment_status === 'success' );
	check( 'Store API response has order-received URL', typeof result.payment_result?.redirect_url === 'string' && result.payment_result.redirect_url.includes( 'order-received' ) );
	const orderId = Number( result.order_id );
	fs.writeFileSync( dir + '/orders.json', JSON.stringify( { 'store-api': orderId }, null, 2 ) );
	const owned = fs.existsSync( dir + '/owned-orders.json' ) ? JSON.parse( fs.readFileSync( dir + '/owned-orders.json', 'utf8' ) ) : [];
	if ( ! owned.includes( orderId ) ) owned.push( orderId );
	fs.writeFileSync( dir + '/owned-orders.json', JSON.stringify( owned, null, 2 ) );
	fs.writeFileSync( dir + '/store-api-checks.json', JSON.stringify( { base, active_plugins: [ 'open-product-fields-for-woocommerce', 'advanced-product-fields-for-woocommerce-extended 3.1.5' ], order_id: orderId, redirect_url: result.payment_result.redirect_url, checks }, null, 2 ) );
	await context.close();
} finally {
	await browser.close();
}
