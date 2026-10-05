// Real-Chromium computed-style proof for the OPF/WAPF shared-class collision.
//
// WAPF Extended 3.1.5 ships
//   `.wapf-checkboxes,.wapf-radios{display:inline-grid;grid-template-columns:auto}`
// and OPF renders its checkbox group wrapper with the same `wapf-checkboxes`
// class. Both rules have one class of specificity, so WAPF's later stylesheet
// wins and collapses OPF's configured multi-column grid.
//
// This harness loads the fixture product (see
// bin/e2e-coexistcss-checkbox-columns.php) and records the computed
// `display`/`grid-template-columns` of the OPF-rendered wrappers and the
// WAPF-native wrappers, plus the stylesheet order and the grid rules that
// match. It writes the result as JSON so a before/after diff is mechanical.
//
// Usage:
//   OPF_COEXISTCSS_BASE=http://127.0.0.1:8261 \
//   OPF_COEXISTCSS_URL=http://127.0.0.1:8261/product/<slug>/ \
//   OPF_COEXISTCSS_OUT=<repo>/docs/compatibility/<dir>/before.json \
//   node bin/e2e-coexistcss-checkbox-columns-browser.mjs

import { createRequire } from 'node:module';
import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname } from 'node:path';

const requireFromPlugin = createRequire(
	process.env.OPF_PLAYWRIGHT_PACKAGE || '/home/followersya-5hqi7/followersya.com/node_modules/playwright/package.json'
);
const { chromium } = requireFromPlugin( 'playwright' );

const url = process.env.OPF_COEXISTCSS_URL || 'http://127.0.0.1:8261/product/opf-wapf-coexistcss-product/';
const out = process.env.OPF_COEXISTCSS_OUT || '';
if ( ! /^http:\/\/127\.0\.0\.1:\d+\//.test( url ) ) throw new Error( 'Loopback clone required.' );

const browser = await chromium.launch( { headless: true } );

const measure = ( page, selector ) => page.locator( selector ).first().evaluate( ( node ) => {
	const style = getComputedStyle( node );
	const tracks = style.gridTemplateColumns.split( ' ' ).filter( Boolean );
	return {
		matched: true,
		display: style.display,
		gridTemplateColumns: style.gridTemplateColumns,
		columnCount: tracks.length,
		classes: node.className,
	};
} ).catch( () => ( { matched: false } ) );

try {
	const page = await browser.newPage( { viewport: { width: 1280, height: 900 } } );
	const response = await page.goto( url, { waitUntil: 'load' } );
	if ( ! response || 200 !== response.status() ) throw new Error( `Fixture page returned ${ response && response.status() }` );

	const result = { url, generatedAt: new Date().toISOString(), stylesheets: [], opfCheckbox: {}, opfRadio: {}, wapfCheckbox: {}, wapfRadio: {}, winningRules: [] };

	result.stylesheets = await page.evaluate( () => Array.from( document.querySelectorAll( 'link[rel="stylesheet"]' ) )
		.map( ( link ) => link.href )
		.filter( ( href ) => /opf-frontend|advanced-product-fields/.test( href ) ) );

	const viewports = [ [ 1280, 'desktop' ], [ 600, 'tablet' ], [ 400, 'mobile' ] ];
	for ( const [ width, label ] of viewports ) {
		await page.setViewportSize( { width, height: 900 } );
		result.opfCheckbox[ label ] = await measure( page, '.opf-checkboxes--columns' );
	}

	await page.setViewportSize( { width: 1280, height: 900 } );
	result.opfRadio = await measure( page, '.opf-swatch-wrapper.wapf-radios' );
	result.wapfCheckbox = await measure( page, '.wapf-checkboxes:not(.opf-swatch-wrapper)' );
	result.wapfRadio = await measure( page, '.wapf-radios:not(.opf-swatch-wrapper)' );

	// Which grid rules actually match the OPF checkbox wrapper, in cascade order.
	result.winningRules = await page.evaluate( () => {
		const node = document.querySelector( '.opf-checkboxes--columns' );
		if ( ! node ) return [];
		const hits = [];
		for ( const sheet of Array.from( document.styleSheets ) ) {
			let rules;
			try { rules = sheet.cssRules; } catch { continue; }
			if ( ! rules ) continue;
			const walk = ( list, media ) => {
				for ( const rule of Array.from( list ) ) {
					if ( rule.selectorText && rule.style ) {
						if ( ! node.matches( rule.selectorText ) ) continue;
						const columns = rule.style.getPropertyValue( 'grid-template-columns' );
						const display = rule.style.getPropertyValue( 'display' );
						if ( ! columns && ! display ) continue;
						hits.push( {
							sheet: ( sheet.href || 'inline' ).split( '/' ).pop(),
							media: media || '',
							selector: rule.selectorText,
							display: display || '',
							gridTemplateColumns: columns || '',
						} );
					} else if ( rule.cssRules && rule.cssRules.length ) {
						walk( rule.cssRules, rule.conditionText || media );
					}
				}
			};
			walk( rules, '' );
		}
		return hits;
	} );

	const serialized = JSON.stringify( result, null, 2 );
	if ( out ) {
		mkdirSync( dirname( out ), { recursive: true } );
		writeFileSync( out, serialized + '\n', 'utf8' );
	}
	console.log( serialized );
} finally {
	await browser.close();
}
