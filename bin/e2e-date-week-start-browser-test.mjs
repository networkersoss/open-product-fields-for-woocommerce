import { createRequire } from 'node:module';
const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');

const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const expectedStart = Number(process.env.OPF_EXPECTED_WEEK_START ?? 0);
if (![0, 1].includes(expectedStart)) throw new Error('OPF_EXPECTED_WEEK_START must be 0 (Sunday) or 1 (Monday)');
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
let failures = 0;
const check = (name, ok) => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}`);
	if (!ok) failures++;
};

await page.goto(`${base}/product/opf-e2e-date-week-start-product/`, { waitUntil: 'domcontentloaded' });
const field = page.locator('[data-opf-field="delivery_date"]');
const input = field.locator('input[type="date"]');
await input.waitFor({ state: 'visible' });
check('renderer publishes the configured WordPress week start', Number(await input.getAttribute('data-opf-week-start')) === expectedStart);
await input.fill('2026-09-30');
await field.locator('.opf-date-picker__toggle').click();
const grid = field.locator('.opf-date-picker__grid');
const labels = await grid.locator('[role="row"]').first().locator('[role="columnheader"]').allTextContents();
const sundayFirst = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
const mondayFirst = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
check('weekday headings follow Sunday or Monday site setting', JSON.stringify(labels) === JSON.stringify(expectedStart ? mondayFirst : sundayFirst));

const firstDate = new Date('2026-09-01T00:00:00Z').getUTCDay();
const firstDateOffset = (firstDate - expectedStart + 7) % 7;
const firstWeek = grid.locator('[role="row"]').nth(1);
check('the first date aligns under the configured first weekday', await firstWeek.locator('button[data-opf-date]').first().getAttribute('data-opf-date') === '2026-09-01' && (await firstWeek.locator('span').count()) === firstDateOffset);

await grid.locator('button[data-opf-date="2026-09-30"]').press('Home');
const homeDate = await grid.locator('button[tabindex="0"]').getAttribute('data-opf-date');
check('Home reaches the configured first weekday', homeDate === (expectedStart ? '2026-09-28' : '2026-09-27'));
await grid.locator('button[tabindex="0"]').press('End');
const endDate = await grid.locator('button[tabindex="0"]').getAttribute('data-opf-date');
check('End reaches the configured final weekday', endDate === (expectedStart ? '2026-10-04' : '2026-10-03'));
check('week-start browser flow has no uncaught JavaScript errors', errors.length === 0);
await browser.close();
if (failures) process.exit(1);
