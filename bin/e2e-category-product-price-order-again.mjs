import fs from 'node:fs';
import { createRequire } from 'node:module';
const require = createRequire( process.env.OPF_PLAYWRIGHT_ROOT + '/index.js' );
const { chromium } = require( 'playwright' );
const dir = '/tmp/opf-category-price-evidence';
const state = JSON.parse( fs.readFileSync( dir + '/state.json', 'utf8' ) );
const orders = JSON.parse( fs.readFileSync( dir + '/orders.json', 'utf8' ) );
const checks = [];
const errors = [];
const check = ( label, pass ) => { checks.push( { label, pass: !! pass } ); if ( ! pass ) throw new Error( label ); };
const base = 'http://127.0.0.1:8423';
const browser = await chromium.launch();
try {
	const context = await browser.newContext();
	const page = await context.newPage();
	page.on( 'pageerror', e => errors.push( e.message ) );
	await page.goto( base + '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'parity-admin' );
	await page.locator( '#user_pass' ).fill( fs.readFileSync( '/tmp/opf-category-price-admin-password', 'utf8' ) );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /wp-admin/ );
	for ( const [ transport, id ] of Object.entries( orders ) ) {
		await page.goto( base + '/?page_id=' + state.account + '&view-order=' + id );
		const reorder = page.getByRole( 'link', { name: 'Order again', exact: true } );
		check( transport + ' completed owned order exposes real Order again link', await reorder.count() === 1 );
		await reorder.click();
		await page.waitForLoadState( 'load' );
		const response = await context.request.get( base + '/?rest_route=/wc/store/v1/cart' );
		const cart = await response.json();
		check( transport + ' actual Woo order-again reconstructs five lines', cart.items.length === 5 );
		check( transport + ' actual Woo order-again preserves total 92', Number( cart.totals.total_price ) / 10 ** cart.totals.currency_minor_unit === 92 );
		const children = cart.items.filter( item => item.extensions?.opf?.childItem );
		check( transport + ' actual Woo order-again preserves four children and two free prices', children.length === 4 && children.filter( item => Number( item.totals.line_total ) === 0 ).length === 2 );
		await page.screenshot( { path: dir + '/order-again-' + transport + '.png', fullPage: true } );
	}
	check( 'actual order-again has no browser runtime errors', errors.length === 0 );
} finally {
	fs.writeFileSync( dir + '/order-again-browser-checks.json', JSON.stringify( { checks, errors }, null, 2 ) );
	await browser.close();
}
console.log( checks.length + ' actual order-again browser checks passed.' );
