import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';

const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const title = process.env.OPF_SCHEDULED_GROUP_TITLE || 'OPF E2E Scheduled Group';
const username = process.env.OPF_E2E_USER;
const passwordFile = process.env.OPF_E2E_PASSWORD_FILE;
if (!username || !passwordFile) throw new Error('OPF_E2E_USER and OPF_E2E_PASSWORD_FILE are required');

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));

try {
	await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
	await page.locator('#user_login').fill(username);
	await page.locator('#user_pass').fill(readFileSync(passwordFile, 'utf8').trim());
	await page.locator('#wp-submit').click();
	await page.waitForURL('**/wp-admin/**');

	const list = new URL('/wp-admin/edit.php', base);
	list.searchParams.set('post_type', 'opf_field_group');
	list.searchParams.set('s', title);
	const response = await page.goto(list.toString(), { waitUntil: 'domcontentloaded' });
	assert.equal(response?.status(), 200, 'OPF field-group admin list loads');
	const row = page.locator('#the-list tr').filter({ hasText: title });
	assert.equal(await row.count(), 1, 'scheduled field group appears in the admin list');
	assert.match(await row.innerText(), /Scheduled/i, 'admin row identifies the post as scheduled');
	assert.equal(errors.length, 0, `admin list has no uncaught browser errors: ${errors.join('; ')}`);
	console.log(JSON.stringify({ passed: 4, title, rowText: (await row.innerText()).trim(), errors }));
} finally {
	await browser.close();
}
