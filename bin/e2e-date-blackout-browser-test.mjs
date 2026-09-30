import { createRequire } from 'node:module';

const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');
const browser = await chromium.launch();
const page = await browser.newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
let failures = 0;
const check = (name, ok) => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}`);
	if (!ok) failures++;
};

await page.setContent(`<div data-opf-group="demo"><div data-opf-field="delivery_date">
	<label for="date-input">Delivery date</label>
	<input id="date-input" type="date" value="2026-06-15" data-opf-date-format="d/m/yy" data-opf-disabled-weekdays="[0]" data-opf-disabled-dates='["2026-06-17","2026-06-20 2026-06-22","06-24 2026-06-26","12-24 01-03"]' data-opf-date-cutoff="14:30" data-opf-date-site-epoch="1781535600" data-opf-date-timezone="UTC">
</div></div>`);
await page.addScriptTag({ path: 'assets/js/opf-frontend.js' });
await page.addStyleTag({ path: 'assets/css/opf-frontend.css' });
const field = page.locator('[data-opf-field="delivery_date"]');
const input = field.locator('input[type="date"]');
const toggle = field.locator('.opf-date-picker__toggle');
await toggle.click();
const calendar = field.getByRole('dialog', { name: 'Choose a date' });
check('accessible calendar opens', await calendar.isVisible());
await page.screenshot({ path: '/tmp/opf-date-blackout.png', fullPage: true });
check('disabled weekday is unavailable', await field.locator('[data-opf-date="2026-06-21"]').isDisabled());
check('disabled exact date is unavailable', await field.locator('[data-opf-date="2026-06-17"]').isDisabled());
check('date range endpoints are unavailable', await field.locator('[data-opf-date="2026-06-20"]').isDisabled() && await field.locator('[data-opf-date="2026-06-22"]').isDisabled());
check('mixed date range endpoints are unavailable', await field.locator('[data-opf-date="2026-06-24"]').isDisabled() && await field.locator('[data-opf-date="2026-06-26"]').isDisabled());
check('same-day cutoff disables today but keeps later dates', await field.locator('[data-opf-date="2026-06-15"]').isDisabled() && !(await field.locator('[data-opf-date="2026-06-16"]').isDisabled()));
check('valid date remains selectable', !(await field.locator('[data-opf-date="2026-06-23"]').isDisabled()));
check('calendar toggle uses configured display format', await toggle.textContent() === '15/6/26');
await field.locator('[data-opf-date="2026-06-23"]').click();
check('calendar selection updates submitted native date', await input.inputValue() === '2026-06-23');
check('calendar closes after selection', !(await calendar.isVisible()));
check('selected display is formatted while input stays canonical ISO', await toggle.textContent() === '23/6/26' && await field.locator('input[type="date"]').inputValue() === '2026-06-23');
await toggle.click();
await field.locator('[data-opf-date="2026-06-23"]').focus();
await page.keyboard.press('ArrowRight');
check('keyboard navigation skips disabled dates', await page.locator(':focus').getAttribute('data-opf-date') === '2026-06-27');
await page.keyboard.press('Escape');
check('Escape closes calendar and returns focus', !(await calendar.isVisible()) && await toggle.evaluate((node) => node === document.activeElement));
await input.fill('2026-06-17');
check('typing a disabled date sets native custom validity', await input.evaluate((node) => node.validity.customError));
await input.fill('2026-06-21');
check('typing a date inside a blackout range sets native custom validity', await input.evaluate((node) => node.validity.customError));
await input.fill('2026-06-25');
check('typing a date inside a mixed-format range sets native custom validity', await input.evaluate((node) => node.validity.customError));
await input.fill('2026-06-23');
check('typing an available date clears custom validity', !(await input.evaluate((node) => node.validity.customError)));
check('browser console has no page errors', errors.length === 0);
if (errors.length) console.error(errors.join('\n'));
await browser.close();
if (failures) process.exit(1);
