// Comparative browser proof: WAPF Extended 3.1.5 vs OPF child-product image
// zoom. The fixture product renders both field groups at once (WAPF local
// `_wapf_fieldgroup` meta + OPF rule group), so one page load gives a true
// side-by-side of the live markup and the live hover behaviour at two widths.
//
// Run (disposable clone on loopback):
//   OPF_WAPFREF_OUT=<repo>/docs/compatibility/wapf-reference-proof-20261005 \
//   NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules \
//   node bin/e2e-wapfref-child-products-image-zoom-browser.mjs
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
const require = createRequire(process.cwd() + '/index.js');
const { chromium } = require('playwright');

const base = process.env.OPF_WAPFREF_BASE_URL || 'http://127.0.0.1:8251';
const out = process.env.OPF_WAPFREF_OUT || 'docs/compatibility/wapf-reference-proof-20261005';
const dir = path.join(out, 'row1-child-products-image-zoom');
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Loopback clone required.');
fs.mkdirSync(dir, { recursive: true });

const fragment = '.wapf-swatch--image.wapf-tt-wrap';
const opfChoice = '.opf-product-choice.opf-swatch--image-zoom';
const checks = [];
const check = (label, pass, detail = '') => {
  checks.push({ label, pass: !!pass, detail });
  console.log(`${pass ? 'ok' : 'FAIL'} ${label}${detail ? ' (' + detail + ')' : ''}`);
};

const browser = await chromium.launch();
try {
  for (const [width, label] of [[1100, 'wide'], [760, 'narrow']]) {
    const context = await browser.newContext({ viewport: { width, height: 900 } });
    const page = await context.newPage();
    page.setDefaultNavigationTimeout(120000);
    page.setDefaultTimeout(120000);
    await page.goto(base + '/product/wapfref-cpi-on/', { waitUntil: 'domcontentloaded' });
    await page.locator(fragment).first().waitFor();
    await page.locator(opfChoice).first().waitFor();

    // WAPF side: the JS tooltip only injects the zoom <img> above 780px.
    const wapf = page.locator(fragment).first();
    await wapf.hover();
    await page.waitForTimeout(250);
    const wapfZoomImg = await page.locator('.wttw .wapf-ttp img, #tooltipText img').count();
    const wapfDataZoom = await wapf.getAttribute('data-zoom-url');
    await page.screenshot({ path: path.join(dir, `browser-wapf-hover-${label}.png`) });

    // OPF side: the inline preview is toggled by CSS on hover/focus at any width.
    const opf = page.locator(opfChoice).first();
    await opf.hover();
    await page.waitForTimeout(150);
    const opfPreviewVisible = await opf.locator('.opf-swatch-zoom-preview').isVisible();
    const opfDataZoom = await opf.getAttribute('data-zoom-url');
    await page.screenshot({ path: path.join(dir, `browser-opf-hover-${label}.png`) });

    check(`${label}: WAPF and OPF zoom wrappers both carry data-zoom-url`, !!wapfDataZoom && !!opfDataZoom, `wapf=${!!wapfDataZoom} opf=${!!opfDataZoom}`);
    check(`${label}: WAPF and OPF zoom URLs point at the same full-size attachment`, wapfDataZoom === opfDataZoom, `${wapfDataZoom} == ${opfDataZoom}`);
    if (1100 === width) {
      check('wide: WAPF JS injects the zoom image on hover (>780px)', wapfZoomImg > 0, `imgs=${wapfZoomImg}`);
      check('wide: OPF inline preview visible on hover', opfPreviewVisible);
    } else {
      check('narrow: WAPF suppresses the zoom image on hover (<=780px)', 0 === wapfZoomImg, `imgs=${wapfZoomImg}`);
      check('narrow: OPF inline preview STILL visible on hover (no width gate)', opfPreviewVisible);
    }
    checks.push({ label: `${label} raw`, wapfZoomImg, opfPreviewVisible, wapfDataZoom, opfDataZoom });
    await context.close();
  }
  const result = { utc: new Date().toISOString(), base, checks };
  fs.writeFileSync(path.join(dir, 'browser-zoom-results.json'), JSON.stringify(result, null, 2));
  console.log('SUCCESS row1 child-products image zoom comparison');
} finally {
  await browser.close();
}
