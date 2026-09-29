import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');

const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const expectedTitle = process.env.OPF_GROUP_SEARCH_TITLE || 'OPF E2E Child Products Group';
const passwordFile = process.env.OPF_E2E_PASSWORD_FILE;
if (!passwordFile) throw new Error('OPF_E2E_PASSWORD_FILE is required');

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
let failures = 0;
const check = (name, ok) => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}`);
	if (!ok) failures++;
};

await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
await page.locator('#user_login').fill('admin');
await page.locator('#user_pass').fill(readFileSync(passwordFile, 'utf8').trim());
await page.locator('#wp-submit').click();
await page.waitForURL('**/wp-admin/**');

const listUrl = new URL('/wp-admin/edit.php', base);
listUrl.searchParams.set('post_type', 'opf_field_group');
listUrl.searchParams.set('s', expectedTitle);
await page.goto(listUrl.toString(), { waitUntil: 'domcontentloaded' });
const rows = page.locator('#the-list tr');
check('admin field-group search returns the title-matched group', await rows.count() === 1 && (await rows.first().locator('.row-title').innerText()).trim() === expectedTitle);

listUrl.searchParams.set('s', 'OPF no matching group title 9d2e6401');
await page.goto(listUrl.toString(), { waitUntil: 'domcontentloaded' });
check('admin field-group title search excludes non-matching groups', await page.locator('#the-list .row-title').count() === 0 && await page.locator('#the-list .no-items').count() === 1);

await browser.close();
if (failures) process.exit(1);
