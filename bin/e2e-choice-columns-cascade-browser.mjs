// Real-Chromium proof for the checkbox-columns responsive cascade.
//
// `assets/css/opf-frontend.css` declares the choice grid once (base rule) and
// overrides it at `@media (max-width: 720px)` and `@media (max-width: 420px)`
// with `--opf-checkbox-columns-tablet` / `--opf-checkbox-columns-mobile`
// (`includes/Service/Renderer.php` emits all three variables for a `columns:4`
// field as 4/2/1). A base rule placed after those media queries wins at equal
// specificity, so every viewport renders the configured desktop count.
//
// This harness loads the fixture product, records the computed
// `display` / `grid-template-columns` / gaps of the OPF checkbox wrapper at
// desktop, tablet and mobile widths, resolves which rule actually wins in the
// cascade, and (when WAPF Extended is active beside OPF) records the WAPF-native
// wrapper to show its own grid is unchanged.
//
// Usage:
//   OPF_CASCADE_URL=http://127.0.0.1:8090/product/<slug>/ \
//   OPF_CASCADE_EXPECT=4,2,1 \
//   OPF_CASCADE_LABEL=after-opf-only \
//   OPF_CASCADE_OUT=<repo>/docs/compatibility/choicecascade-proof-20261005/after-opf-only.json \
//   node bin/e2e-choice-columns-cascade-browser.mjs

import { createRequire } from 'node:module';
import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname } from 'node:path';

const requireFromPlugin = createRequire(
	process.env.OPF_PLAYWRIGHT_PACKAGE || '/home/followersya-5hqi7/followersya.com/node_modules/playwright/package.json'
);
const { chromium } = requireFromPlugin( 'playwright' );

const url = process.env.OPF_CASCADE_URL || 'http://127.0.0.1:8090/product/opf-wapf-coexistcss-product/';
const label = process.env.OPF_CASCADE_LABEL || 'state';
const out = process.env.OPF_CASCADE_OUT || '';
const expected = ( process.env.OPF_CASCADE_EXPECT || '4,2,1' ).split( ',' ).map( Number );
const screenshotDir = process.env.OPF_CASCADE_SCREENSHOTS || '';
if ( ! /^http:\/\/127\.0\.0\.1:\d+\//.test( url ) ) throw new Error( 'Loopback clone required.' );
if ( 3 !== expected.length || expected.some( ( n ) => ! Number.isInteger( n ) || n < 1 ) ) {
	throw new Error( 'OPF_CASCADE_EXPECT must be desktop,tablet,mobile column counts.' );
}

const checks = [];
const check = ( name, pass, detail = '' ) => {
	checks.push( { name, pass: !! pass, detail } );
	console.log( `${ pass ? 'ok' : 'FAIL' } ${ name }${ detail ? ' — ' + detail : '' }` );
};
const report = () => {
	const failures = checks.filter( ( c ) => ! c.pass ).length;
	console.log( `${ checks.length } checks, ${ failures } failures (${ label })` );
	if ( failures ) process.exitCode = 1;
};

// Cascade winner = highest specificity among the applicable rules, then source
// order. OPF's scoped rule (0,2,0) must beat WAPF's later one-class rule (0,1,0).
const specificityOf = ( selector ) => selector.split( ',' ).reduce( ( best, part ) => {
	const ids = ( part.match( /#[\w-]+/g ) || [] ).length;
	const classes = ( part.match( /\.[\w-]+/g ) || [] ).length
		+ ( part.match( /\[[^\]]*\]/g ) || [] ).length
		+ ( part.match( /(?<!:):(?!:)[\w-]+/g ) || [] ).length;
	const elements = ( part.replace( /[#.][\w-]+|\[[^\]]*\]|::?[\w-]+/g, '' ).match( /[a-zA-Z][\w-]*/g ) || [] ).length
		+ ( part.match( /::[\w-]+/g ) || [] ).length;
	const score = ids * 10000 + classes * 100 + elements;
	return score > best.score ? { score, ids, classes, elements } : best;
}, { score: 0, ids: 0, classes: 0, elements: 0 } );

const measure = ( page, selector ) => page.locator( selector ).first().evaluate( ( node ) => {
	const style = getComputedStyle( node );
	const tracks = style.gridTemplateColumns.split( ' ' ).filter( Boolean );
	return {
		matched: true,
		display: style.display,
		gridTemplateColumns: style.gridTemplateColumns,
		columnCount: tracks.length,
		rowGap: style.rowGap,
		columnGap: style.columnGap,
		classes: node.className,
		inlineStyle: node.getAttribute( 'style' ),
	};
} ).catch( () => ( { matched: false } ) );

// The rule that actually wins for the measured element: every matching
// `grid-template-columns` declaration in cascade order plus whether its media
// condition currently applies. The last applicable entry is the winner.
const winningRules = ( page, selector ) => page.evaluate( ( target ) => {
	const node = document.querySelector( target );
	if ( ! node ) return [];
	const hits = [];
	const visit = ( rules, media ) => {
		for ( const rule of Array.from( rules ) ) {
			if ( rule.type === CSSRule.MEDIA_RULE ) {
				visit( rule.cssRules, rule.media.mediaText );
				continue;
			}
			if ( rule.type !== CSSRule.STYLE_RULE ) continue;
			const value = rule.style.getPropertyValue( 'grid-template-columns' );
			if ( ! value ) continue;
			let matches = false;
			try { matches = node.matches( rule.selectorText ); } catch { matches = false; }
			if ( ! matches ) continue;
			hits.push( {
				sheet: ( rule.parentStyleSheet && rule.parentStyleSheet.href ) || '',
				selector: rule.selectorText,
				media: media || null,
				value,
				applies: ! media || window.matchMedia( media ).matches,
			} );
		}
	};
	for ( const sheet of Array.from( document.styleSheets ) ) {
		let rules;
		try { rules = sheet.cssRules; } catch { continue; }
		if ( rules ) visit( rules, null );
	}
	return hits;
}, selector );

const browser = await chromium.launch( { headless: true } );
try {
	const page = await browser.newPage( { viewport: { width: 1280, height: 900 } } );
	const response = await page.goto( url, { waitUntil: 'load' } );
	check( 'fixture product page returns HTTP 200', response && 200 === response.status(), String( response && response.status() ) );

	const result = {
		label,
		url,
		generatedAt: new Date().toISOString(),
		stylesheets: await page.evaluate( () => Array.from( document.querySelectorAll( 'link[rel="stylesheet"]' ) )
			.map( ( link ) => link.href )
			.filter( ( href ) => /opf-frontend|advanced-product-fields/.test( href ) ) ),
		opfCheckbox: {},
		wapfCheckbox: {},
		opfRadio: {},
	};

	const wrapper = page.locator( '.opf-swatch-wrapper.opf-checkboxes--columns' );
	check( 'OPF checkbox wrapper is present', 1 === await wrapper.count() );
	const inlineStyle = ( await wrapper.first().getAttribute( 'style' ) ) || '';
	check( 'wrapper publishes the configured 4/2/1 variables', /--opf-checkbox-columns:4/.test( inlineStyle ) && /--opf-checkbox-columns-tablet:2/.test( inlineStyle ) && /--opf-checkbox-columns-mobile:1/.test( inlineStyle ), inlineStyle );

	const viewports = [ [ 1280, 'desktop' ], [ 720, 'tablet-edge-720' ], [ 600, 'tablet' ], [ 420, 'mobile-edge-420' ], [ 400, 'mobile' ] ];
	for ( const [ width, name ] of viewports ) {
		await page.setViewportSize( { width, height: 900 } );
		const measured = await measure( page, '.opf-swatch-wrapper.opf-checkboxes--columns' );
		const hits = await winningRules( page, '.opf-swatch-wrapper.opf-checkboxes--columns' );
		const applicable = hits.filter( ( hit ) => hit.applies ).map( ( hit ) => ( { ...hit, specificity: specificityOf( hit.selector ) } ) );
		const winner = applicable.reduce( ( best, hit ) => ( ! best || hit.specificity.score >= best.specificity.score ? hit : best ), null );
		result.opfCheckbox[ name ] = { width, ...measured, winner, cascade: hits };
		if ( screenshotDir ) {
			mkdirSync( screenshotDir, { recursive: true } );
			await page.screenshot( { path: `${ screenshotDir }/${ label }-${ name }.png`, fullPage: false } );
		}
	}

	const expectedColumns = { desktop: expected[0], 'tablet-edge-720': expected[1], tablet: expected[1], 'mobile-edge-420': expected[2], mobile: expected[2] };
	for ( const [ name, columns ] of Object.entries( expectedColumns ) ) {
		const measured = result.opfCheckbox[ name ];
		check( `${ name } (${ measured.width }px) renders ${ columns } column(s)`, columns === measured.columnCount, `${ measured.columnCount } — ${ measured.gridTemplateColumns }` );
	}

	const winnerVariable = {
		desktop: '--opf-checkbox-columns,',
		'tablet-edge-720': '--opf-checkbox-columns-tablet,',
		tablet: '--opf-checkbox-columns-tablet,',
		'mobile-edge-420': '--opf-checkbox-columns-mobile,',
		mobile: '--opf-checkbox-columns-mobile,',
	};
	for ( const [ name, variable ] of Object.entries( winnerVariable ) ) {
		const winner = result.opfCheckbox[ name ].winner;
		check(
			`${ name } (${ result.opfCheckbox[ name ].width }px) is decided by ${ variable.replace( ',', '' ) }`,
			!! winner && winner.value.includes( variable ),
			winner ? `${ winner.selector } ${ winner.media || '(base)' } → ${ winner.value }` : 'no matching rule'
		);
	}

	check( 'desktop row-gap is unchanged at 4px', '4px' === result.opfCheckbox.desktop.rowGap, result.opfCheckbox.desktop.rowGap );
	check( 'desktop column-gap is unchanged at 12px', '12px' === result.opfCheckbox.desktop.columnGap, result.opfCheckbox.desktop.columnGap );

	// WAPF parity when both plugins are active: WAPF's own wrapper keeps its
	// shipped single-column inline-grid and its own winning rule.
	await page.setViewportSize( { width: 1280, height: 900 } );
	const wapf = await measure( page, '.wapf-checkboxes:not(.opf-swatch-wrapper)' );
	result.wapfCheckbox = wapf;
	if ( wapf.matched ) {
		const wapfHits = await winningRules( page, '.wapf-checkboxes:not(.opf-swatch-wrapper)' );
		const applicable = wapfHits.filter( ( hit ) => hit.applies ).map( ( hit ) => ( { ...hit, specificity: specificityOf( hit.selector ) } ) );
		result.wapfCheckbox.cascade = wapfHits;
		result.wapfCheckbox.winner = applicable.reduce( ( best, hit ) => ( ! best || hit.specificity.score >= best.specificity.score ? hit : best ), null );
		check( 'WAPF-native wrapper keeps its shipped inline-grid', 'inline-grid' === wapf.display, wapf.display );
		check( 'WAPF-native wrapper keeps one column', 1 === wapf.columnCount, String( wapf.columnCount ) );
		check( 'WAPF-native wrapper is still decided by its own .wapf-checkboxes rule', !! result.wapfCheckbox.winner && /\.wapf-checkboxes/.test( result.wapfCheckbox.winner.selector ), result.wapfCheckbox.winner ? result.wapfCheckbox.winner.selector : 'none' );
	}

	result.opfRadio = await measure( page, '.opf-swatch-wrapper.wapf-radios' );

	if ( out ) {
		mkdirSync( dirname( out ), { recursive: true } );
		writeFileSync( out, JSON.stringify( result, null, 2 ) + '\n' );
		console.log( `artifact ${ out }` );
	}
	report();
} finally {
	await browser.close();
}
