import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const requirePlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requirePlugin('playwright');
const here = path.dirname(fileURLToPath(import.meta.url));
const source = fs.readFileSync(path.join(here, '../assets/js/opf-builder.js'), 'utf8');
const browser = await chromium.launch();
const page = await browser.newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));

await page.setContent('<!doctype html><html><body><input id="title" value="Image quantity zoom"><div id="opf-builder-app"></div></body></html>');
await page.evaluate(() => {
	const mount = document.getElementById('opf-builder-app');
	mount.dataset.postId = '44';
	mount.dataset.nonce = 'fixture';
	mount.dataset.rest = '/wp-json/opf/v1/groups';
	mount.dataset.previewRest = '/wp-json/opf/v1/preview';
	mount.dataset.model = JSON.stringify({ fields: [ {
		id: 'prints', label: 'Prints', type: 'image_quantity', image_zoom: false,
		choices: [ { slug: 'oak', label: 'Oak', image_id: 481, image: 'https://example.test/oak.jpg', quantity: { default: 0, min: 0, max: 4 }, pricing: { type: 'fixed', amount: 2, formula: '' } } ],
	} ], rule_groups: [] });
	window.__opfSavedPayloads = [];
	window.fetch = async (_url, options) => {
		window.__opfSavedPayloads.push(JSON.parse(options.body));
		return { json: async () => ({ id: 44 }) };
	};
});
await page.addScriptTag({ content: source });
const zoom = page.locator('[data-opf-image-setting="image_zoom"]');
const visible = await zoom.count() === 1 && await zoom.isVisible();
await zoom.check();
await page.getByRole('button', { name: 'Save' }).click();
await page.waitForFunction(() => window.__opfSavedPayloads.length === 1);
const result = await page.evaluate(() => window.__opfSavedPayloads[0].data.fields[0]);
const ok = visible
	&& result.type === 'image_quantity'
	&& result.image_zoom === true
	&& result.choices[0].pricing.type === 'fixed'
	&& result.choices[0].pricing.amount === 2
	&& result.choices[0].quantity.max === 4
	&& errors.length === 0;
console.log(`${ok ? 'ok' : 'FAIL'} image quantity zoom builder toggle persists without changing price or quantity settings`);
if (!ok) console.log(JSON.stringify({ visible, result, errors }));
await browser.close();
process.exit(ok ? 0 : 1);
