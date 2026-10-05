// Live gallery-image-change comparison: WAPF Extended 3.1.5 vs OPF, on the
// real WooCommerce product gallery. Both groups carry two rules that BOTH match
// `finish=steel` in the fixed order [g1, g2]; the shipped matchers reverse the
// rule list, so the LAST rule (g2) must win. Observing g2 is the empirical
// confirmation that 3.1.5 reverses its rule list, and that OPF does the same.
//
//   OPF_WAPFREF_BASE_URL=http://127.0.0.1:8251 \
//   NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules \
//   node bin/e2e-wapfref-image-change-browser.mjs
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
const require = createRequire(process.cwd() + '/index.js');
const { chromium } = require('playwright');

const base = process.env.OPF_WAPFREF_BASE_URL || 'http://127.0.0.1:8251';
const out = process.env.OPF_WAPFREF_OUT || 'docs/compatibility/wapf-reference-proof-20261005';
const dir = path.join(out, 'row2-image-change');
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Loopback clone required.');
const state = JSON.parse(fs.readFileSync(path.join(dir, 'browser-state.json'), 'utf8'));

const checks = [];
const check = (label, pass, detail = '') => {
  checks.push({ label, pass: !!pass, detail });
  console.log(`${pass ? 'ok' : 'FAIL'} ${label}${detail ? ' (' + detail + ')' : ''}`);
};

// The live gallery image src after a selection (flexslider marks the live slide
// with .flex-active-slide; fall back to the first visible slide).
const activeImageSrc = async page => page.evaluate(() => {
  const active = document.querySelector('.woocommerce-product-gallery .flex-active-slide');
  const slides = Array.from(document.querySelectorAll('.woocommerce-product-gallery__image'));
  const chosen = active || slides.find(s => s.offsetParent !== null) || slides[0];
  const img = chosen && chosen.querySelector('img');
  return img ? (img.getAttribute('data-large_image') || img.getAttribute('src') || '') : '';
});

const browser = await chromium.launch();
try {
  for (const [slug, plugin, label] of [['wapfref-ic-wapf', 'wapf', 'WAPF 3.1.5'], ['wapfref-ic-opf', 'opf', 'OPF']]) {
    const context = await browser.newContext({ viewport: { width: 1200, height: 900 } });
    const page = await context.newPage();
    page.setDefaultNavigationTimeout(120000);
    page.setDefaultTimeout(120000);
    await page.goto(`${base}/product/${slug}/`, { waitUntil: 'domcontentloaded' });

    const ownGi = await page.evaluate(plugin => {
      const attr = plugin === 'wapf' ? 'data-wapf-gi' : 'data-opf-gi';
      const n = document.querySelector('[' + attr + ']');
      return n ? n.getAttribute(attr) : null;
    }, plugin);
    let parsed = null;
    try { parsed = ownGi ? JSON.parse(ownGi) : null; } catch { parsed = null; }
    const lastImage = parsed && parsed.rules ? parsed.rules[parsed.rules.length - 1].image : null;
    check(`${label}: page emits its own gallery rule payload`, !!parsed && Array.isArray(parsed.rules), `rules=${parsed ? parsed.rules.length : 'none'}`);
    check(`${label}: last rule targets image ${state.expected_last}`, String(lastImage) === String(state.expected_last), `last=${lastImage}`);

    const select = plugin === 'wapf'
      ? page.locator('select[name="wapf[field_finish]"]').first()
      : page.locator('[data-opf-field="finish"] select').first();
    await select.waitFor();
    await page.waitForTimeout(1500);
    await select.selectOption('steel');
    // Re-dispatch input+change so the group's debounced resolver runs after the
    // select value has settled (the first synthetic change can race the module
    // init). Harmless for WAPF, required for OPF in a headless load.
    for (let i = 0; i < 2; i++) {
      await page.evaluate(() => {
        const s = document.querySelector('select[name="wapf[field_finish]"], [data-opf-field="finish"] select');
        if (s) { s.dispatchEvent(new Event('input', { bubbles: true })); s.dispatchEvent(new Event('change', { bubbles: true })); }
      });
      await page.waitForTimeout(500);
    }
    await page.waitForTimeout(1600);

    const src = await activeImageSrc(page);
    const winner = src.includes('ic-g2') ? state.images.g2 : (src.includes('ic-g1') ? state.images.g1 : null);
    check(`${label}: selecting steel activates the LAST matching rule (rule list is reversed)`, String(winner) === String(state.expected_last), `src=${src}`);
    await page.screenshot({ path: path.join(dir, `browser-${plugin}-steel.png`), fullPage: true });
    await context.close();
  }
  const failed = checks.filter(c => !c.pass);
  fs.writeFileSync(path.join(dir, 'browser-image-change.json'), JSON.stringify({ utc: new Date().toISOString(), checks }, null, 2));
  if (failed.length) throw new Error(`${failed.length} check(s) failed: ${failed.map(c => c.label).join('; ')}`);
  console.log('SUCCESS row2 image-change comparison');
} finally {
  await browser.close();
}
