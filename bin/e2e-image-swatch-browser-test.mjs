import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const requirePlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requirePlugin('playwright');
const here = path.dirname(fileURLToPath(import.meta.url));
const css = fs.readFileSync(path.join(here, '../assets/css/opf-frontend.css'), 'utf8');
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1200, height: 900 } });
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));

await page.setContent('<!doctype html><html><body><div class="opf-image-swatch-wrapper" data-grid-layout="flexible" style="--opf-image-swatch-cols:4;--opf-image-swatch-cols-tablet:2;--opf-image-swatch-cols-mobile:1;width:400px"><div class="opf-swatch opf-swatch--image-zoom"><label><img class="opf-swatch-image" src="https://example.test/oak-medium.jpg" width="260" height="200" alt="Oak"><img class="opf-swatch-zoom-preview" src="https://example.test/oak-full.jpg" width="260" height="200" alt="" aria-hidden="true"><span>Oak</span><input type="radio" aria-label="Oak"></label></div></div><div class="opf-image-quantity"><label class="opf-image-quantity__choice"><span class="opf-image-quantity__img opf-swatch--image-zoom wapf-tt-wrap" data-zoom-url="https://example.test/oak-full.jpg"><img class="opf-swatch-image" src="https://example.test/oak-medium.jpg" width="260" height="200" alt="Oak"><img class="opf-swatch-zoom-preview" src="https://example.test/oak-full.jpg" width="260" height="200" alt="" aria-hidden="true"></span><span>Oak</span><input type="number" aria-label="Oak quantity"></label></div></body></html>');
await page.addStyleTag({ content: css });
const columnsAt = async (width) => {
	await page.setViewportSize({ width, height: 900 });
	return page.locator('.opf-image-swatch-wrapper').evaluate((node) => getComputedStyle(node).gridTemplateColumns.split(' ').length);
};
const desktop = await columnsAt(1200);
const tablet = await columnsAt(850);
const mobile = await columnsAt(700);
const swatchWrap = page.locator('.opf-image-swatch-wrapper .opf-swatch--image-zoom');
await swatchWrap.hover();
const hoverZoom = await swatchWrap.locator('.opf-swatch-zoom-preview').isVisible();
const hoverState = await swatchWrap.evaluate((node) => ({ hovered: node.matches(':hover'), display: getComputedStyle(node.querySelector('.opf-swatch-zoom-preview')).display }));
await swatchWrap.evaluate((node) => node.querySelector('input').focus());
const focusZoom = await swatchWrap.locator('.opf-swatch-zoom-preview').isVisible();
const focusState = await swatchWrap.evaluate((node) => ({ focused: node.matches(':focus-within'), display: getComputedStyle(node.querySelector('.opf-swatch-zoom-preview')).display }));
const quantityWrap = page.locator('.opf-image-quantity__img[data-zoom-url]');
await quantityWrap.hover();
const quantityHoverZoom = await quantityWrap.locator('.opf-swatch-zoom-preview').isVisible();
await page.setViewportSize({ width: 360, height: 800 });
const quantityPreviewWidth = await quantityWrap.locator('.opf-swatch-zoom-preview').evaluate((node) => node.getBoundingClientRect().width);
await quantityWrap.evaluate((node) => node.closest('label').querySelector('input').focus());
const quantityFocusZoom = await quantityWrap.locator('.opf-swatch-zoom-preview').isVisible();
const quantityAccessibility = await quantityWrap.evaluate((node) => ({ alt: node.querySelector('.opf-swatch-image').getAttribute('alt'), previewHidden: node.querySelector('.opf-swatch-zoom-preview').getAttribute('aria-hidden') }));
await page.emulateMedia({ reducedMotion: 'reduce' });
const reducedTransition = await swatchWrap.locator('.opf-swatch-image').evaluate((node) => getComputedStyle(node).transitionDuration);
const ok = desktop === 4 && tablet === 2 && mobile === 1
	&& hoverZoom && focusZoom
	&& quantityHoverZoom && quantityFocusZoom
	&& quantityPreviewWidth <= 252
	&& quantityAccessibility.alt === 'Oak' && quantityAccessibility.previewHidden === 'true'
	&& reducedTransition === '0s' && errors.length === 0;
console.log(`${ok ? 'ok' : 'FAIL'} image quantity zoom hover/focus, image swatch responsive columns, accessibility, and reduced motion`);
if (!ok) console.log(JSON.stringify({ desktop, tablet, mobile, hoverZoom, hoverState, focusZoom, focusState, quantityHoverZoom, quantityFocusZoom, quantityPreviewWidth, quantityAccessibility, reducedTransition, errors }));
await browser.close();
process.exit(ok ? 0 : 1);
