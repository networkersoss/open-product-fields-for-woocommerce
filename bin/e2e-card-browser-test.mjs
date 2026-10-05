import { createRequire } from 'node:module';
const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const groupId = process.env.OPF_CARD_GROUP_ID;
if (!groupId) throw new Error('OPF_CARD_GROUP_ID is required');

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
let failures = 0;
const check = (name, ok) => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}`);
	if (!ok) failures++;
};

await page.goto(`${base}/product/opf-e2e-card-product/`, { waitUntil: 'domcontentloaded' });
const finish = page.locator('[data-opf-field="finish"]');
const radios = finish.locator('input[type="radio"]');
const group = page.getByRole('radiogroup', { name: 'Choose a finish' });
await group.waitFor({ state: 'visible' });
check('field has an accessible radiogroup name and two native radio choices', await radios.count() === 2 && await group.getAttribute('aria-labelledby') !== null);
const cardImage = finish.locator('img.opf-card__image');
await cardImage.waitFor({ state: 'visible' });
await page.waitForFunction(() => {
	const image = document.querySelector('[data-opf-field="finish"] img.opf-card__image');
	return image && image.complete && image.naturalWidth > 0;
});
check('optional card image loads and sanitized description is present', await cardImage.count() === 1 && await finish.getByText('Soft woven finish').isVisible());
check('choice pricing hints include the configured add-on amount', (await finish.locator('[data-opf-choice-hint="velvet"]').innerText()).includes('8'));

const note = page.locator('[data-opf-field="dedication"]');
check('conditional field starts hidden before card selection', !(await note.isVisible()));
const linen = finish.locator('input[value="linen"]');
await linen.focus();
await linen.press('Space');
check('Space selects the focused card with native radio keyboard behavior', await linen.isChecked());
await linen.press('ArrowRight');
const velvet = finish.locator('input[value="velvet"]');
check('arrow key advances native radio selection to the next card', await velvet.isChecked());
check('selection reveals the matching conditional field', await note.isVisible());

const horizontalDesktop = await finish.locator('.opf-card').evaluateAll((cards) => cards.map((card) => {
	const rect = card.getBoundingClientRect();
	return { x: rect.x, y: rect.y, width: rect.width };
}));
check('horizontal cards display side by side on desktop', horizontalDesktop.length === 2 && horizontalDesktop[0].x < horizontalDesktop[1].x && Math.abs(horizontalDesktop[0].y - horizontalDesktop[1].y) < 2);

const vertical = page.locator('[data-opf-field="packaging"]');
const verticalBoxes = await vertical.locator('.opf-card').evaluateAll((cards) => cards.map((card) => {
	const rect = card.getBoundingClientRect();
	return { x: rect.x, y: rect.y };
}));
check('vertical cards stack on desktop', verticalBoxes.length === 2 && Math.abs(verticalBoxes[0].x - verticalBoxes[1].x) < 2 && verticalBoxes[0].y < verticalBoxes[1].y);

await page.setViewportSize({ width: 390, height: 844 });
const horizontalMobile = await finish.locator('.opf-card').evaluateAll((cards) => cards.map((card) => {
	const rect = card.getBoundingClientRect();
	return { x: rect.x, y: rect.y, right: rect.right };
}));
check('horizontal cards responsively stack at mobile width', horizontalMobile.length === 2 && Math.abs(horizontalMobile[0].x - horizontalMobile[1].x) < 2 && horizontalMobile[0].y < horizontalMobile[1].y);
check('browser card flow has no uncaught page errors', errors.length === 0);
if (errors.length) console.log(errors.join('\n'));

await browser.close();
process.exit(failures ? 1 : 0);
