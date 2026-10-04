// Exercise WooCommerce's real account Order Again link on a coactive clone.
import fs from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire( process.env.OPF_PLAYWRIGHT_ROOT + '/index.js' );
const { chromium } = require( 'playwright' );
const base = process.env.OPF_CHILD_STORE_API_BASE_URL || 'http://127.0.0.1:8423';
if ( ! /^http:\/\/127\.0\.0\.1:\d+$/.test( base ) ) throw new Error( 'Loopback clone required.' );
const dir = process.env.OPF_CHILD_STORE_API_ARTIFACTS || '/tmp/opf-child-store-api-order-evidence';
const state = JSON.parse( fs.readFileSync( dir + '/state.json', 'utf8' ) );
const orderId = Number( JSON.parse( fs.readFileSync( dir + '/orders.json', 'utf8' ) )['store-api'] );
const passwordFile = process.env.OPF_CHILD_STORE_API_PASSWORD_FILE || '/tmp/opf-child-store-api-order-wp-admin-password';
const checks = [];
const check = ( label, pass ) => {
	checks.push( { label, pass: !! pass } );
	console.log( `${ pass ? 'ok' : 'FAIL' } ${ label }` );
	if ( ! pass ) throw new Error( label );
};
const browser = await chromium.launch( { headless: true } );
try {
	const context = await browser.newContext();
	const page = await context.newPage();
	await page.goto( base + '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'parity-admin' );
	await page.locator( '#user_pass' ).fill( fs.readFileSync( passwordFile, 'utf8' ) );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /wp-admin/ );
	await page.goto( base + '/?page_id=' + state.account + '&view-order=' + orderId );
	const action = page.getByRole( 'link', { name: 'Order again', exact: true } );
	check( 'completed Store API order exposes Woo Order Again action', await action.count() === 1 );
	await action.click();
	await page.waitForLoadState( 'load' );
	const response = await context.request.get( base + '/?rest_route=/wc/store/v1/cart' );
	const cart = await response.json();
	const children = cart.items.filter( item => item.extensions?.opf?.childItem );
	check( 'actual Order Again rebuilds parent and four child lines', cart.items.length === 5 && children.length === 4 );
	check( 'actual Order Again preserves USD 92 including free children', Number( cart.totals.total_price ) / 10 ** cart.totals.currency_minor_unit === 92 && children.filter( item => Number( item.totals.line_total ) === 0 ).length === 2 );
	const parent = cart.items.find( item => ! item.extensions?.opf?.childItem );
	const removed = parent && await context.request.post( base + '/?rest_route=/wc/store/v1/cart/remove-item', { headers: { Nonce: response.headers().nonce }, data: { key: parent.key } } );
	const empty = await ( await context.request.get( base + '/?rest_route=/wc/store/v1/cart' ) ).json();
	check( 'parent removal clears the restored child lines', !! removed?.ok() && empty.items.length === 0 );
	fs.writeFileSync( dir + '/order-again-checks.json', JSON.stringify( { base, order_id: orderId, checks }, null, 2 ) );
	await context.close();
} finally {
	await browser.close();
}
