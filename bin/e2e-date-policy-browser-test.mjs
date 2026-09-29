import { createRequire } from 'node:module';
const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const browser = await chromium.launch();
const page = await browser.newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
let failures = 0;
const check = (name, ok) => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}`);
	if (!ok) failures++;
};
const shiftDate = (iso, days) => {
	const date = new Date(`${iso}T00:00:00Z`);
	date.setUTCDate(date.getUTCDate() + days);
	return date.toISOString().slice(0, 10);
};

await page.goto(`${base}/product/e2e-matched-product/`, { waitUntil: 'domcontentloaded' });
const field = page.locator('[data-opf-field="restricted_date"]');
await field.waitFor({ state: 'visible' });
const input = field.locator('input[type="date"]');
const today = await input.evaluate((node) => {
	const epoch = Number(node.dataset.opfDateSiteEpoch) * 1000;
	const timezone = node.dataset.opfDateTimezone || 'UTC';
	const parts = new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date(epoch));
	const value = (type) => (parts.find((part) => part.type === type) || {}).value || '';
	return `${value('year')}-${value('month')}-${value('day')}`;
});
const past = shiftDate(today, -1);
const future = shiftDate(today, 1);
const toggle = field.locator('.opf-date-picker__toggle');
await toggle.click();
check('calendar visibly disables yesterday and tomorrow but keeps today selectable',
	await field.locator(`button[data-opf-date="${past}"]`).isDisabled() &&
	await field.locator(`button[data-opf-date="${today}"]`).isEnabled() &&
	await field.locator(`button[data-opf-date="${future}"]`).isDisabled());
await input.fill(past);
check('typed forged past date is blocked by browser validation', !(await input.evaluate((node) => node.checkValidity())));
await input.fill(future);
check('typed forged future date is blocked by browser validation', !(await input.evaluate((node) => node.checkValidity())));
await input.fill(today);
check('typed site-local today remains valid', await input.evaluate((node) => node.checkValidity()));
check('date policy browser path has no runtime errors', errors.length === 0);
if (errors.length) console.log(errors.join('\n'));
await browser.close();
process.exit(failures ? 1 : 0);
