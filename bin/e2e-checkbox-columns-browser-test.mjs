#!/usr/bin/env node
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const requireFromPlugin = createRequire(
	process.env.OPF_PLAYWRIGHT_PACKAGE || '/home/followersya-5hqi7/followersya.com/node_modules/playwright/package.json'
);
const { chromium } = requireFromPlugin( 'playwright' );
const here = dirname( fileURLToPath( import.meta.url ) );
const css = readFileSync( resolve( here, '../assets/css/opf-frontend.css' ), 'utf8' );
const browser = await chromium.launch( { headless: true } );

try {
	const page = await browser.newPage( { viewport: { width: 1280, height: 800 } } );
	await page.setContent( `<!doctype html><html><head><style>${ css }</style></head><body>
		<div class="opf-swatch-wrapper opf-checkboxes--columns" style="--opf-checkbox-columns:3">
			<div><label><input type="checkbox" id="wrap" name="extras[]" value="wrap"><span>Gift wrap</span></label></div>
			<div><label><input type="checkbox" id="rush" name="extras[]" value="rush"><span>Rush order</span></label></div>
			<div><label><input type="checkbox" id="engrave" name="extras[]" value="engrave"><span>Engraving</span></label></div>
		</div></body></html>` );

	const columnsAt = async ( width ) => {
		await page.setViewportSize( { width, height: 800 } );
		return page.locator( '.opf-checkboxes--columns' ).evaluate( ( node ) => getComputedStyle( node ).gridTemplateColumns.split( ' ' ).length );
	};
	const observed = [ await columnsAt( 1280 ), await columnsAt( 600 ), await columnsAt( 400 ) ];
	if ( JSON.stringify( observed ) !== JSON.stringify( [ 3, 3, 3 ] ) ) {
		throw new Error( `Configured column count mismatch: ${ observed.join( '/' ) }` );
	}

	const labeled = await page.getByLabel( 'Gift wrap' ).count();
	if ( labeled !== 1 ) throw new Error( `Expected one label-associated checkbox, found ${ labeled }` );
	await page.getByLabel( 'Gift wrap' ).focus();
	await page.keyboard.press( 'Space' );
	if ( ! await page.getByLabel( 'Gift wrap' ).isChecked() ) throw new Error( 'Space did not toggle the native checkbox.' );
	console.log( 'ok OPF checkbox grid: configured columns retained across viewports; labels and keyboard toggling work (not a WAPF comparison)' );
} finally {
	await browser.close();
}
