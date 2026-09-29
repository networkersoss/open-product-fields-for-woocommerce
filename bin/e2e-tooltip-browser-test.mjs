import { createRequire } from 'node:module';
const requirePlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requirePlugin('playwright');
const productUrl = process.env.OPF_TOOLTIP_URL;
if (!productUrl) throw new Error('OPF_TOOLTIP_URL is required');

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
let failures = 0;
const check = (name, ok) => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}`);
	if (!ok) failures++;
};

await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
const trigger = page.locator('[data-opf-field="engraving"] .opf-instruction-tooltip__trigger');
await trigger.waitFor({ state: 'visible' });
const describedBy = await trigger.getAttribute('aria-describedby');
const instruction = page.locator(`#${describedBy}`);
check('instruction content is connected to its trigger with aria-describedby', !!describedBy && await instruction.textContent() === 'Use up to 12 characters.');
check('instruction tooltip stays out of the inline field description', await page.locator('[data-opf-field="engraving"] .opf-field-description').count() === 0);

await trigger.focus();
const focusState = await instruction.evaluate((el) => ({ visibility: getComputedStyle(el).visibility, within: el.parentElement.matches(':focus-within'), active: document.activeElement.className }));
check('keyboard focus exposes tooltip content', focusState.visibility === 'visible' && focusState.within);
await page.keyboard.press('Enter');
check('keyboard activation opens the tooltip and updates aria-expanded', await trigger.getAttribute('aria-expanded') === 'true');
await page.keyboard.press('Escape');
check('Escape closes the tooltip and returns focus to its trigger', await trigger.getAttribute('aria-expanded') === 'false' && await trigger.evaluate((el) => document.activeElement === el));
check('no uncaught browser errors', errors.length === 0);
if (errors.length) console.log(errors.join('\n'));
await browser.close();
process.exit(failures ? 1 : 0);
