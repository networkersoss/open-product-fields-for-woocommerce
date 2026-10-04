const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const context = {
  window: { opf_config: {} }, opf_config: {}, console,
  document: { readyState: 'loading', addEventListener() {}, querySelector() { return null; }, querySelectorAll() { return []; } },
};
vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(__dirname, '../../assets/js/opf-frontend.js'), 'utf8') + '\nglobalThis.addon = choiceOrFieldAddon;', context);

for (const subtype of ['dropdown', 'radio', 'checkbox', 'image', 'card', 'vcard', 'card-qty', 'vcard-qty']) {
  for (const parentQty of [1, 2, 4]) {
    test(`${subtype}: live child catalog price, free/unavailable choices, parent quantity ${parentQty}`, () => {
      const qtySelector = subtype.endsWith('-qty');
      const def = { type: 'products', subtype, qty_selector: qtySelector, choices: [
        { slug: 'a', child_price_type: qtySelector ? 'nr' : 'fixed', child_price: 7 },
        { slug: 'b', child_price_type: qtySelector ? 'nr' : 'qt', child_price: 10 },
        { slug: 'free', child_price_type: 'none', child_price: 99 },
        { slug: 'disabled', disabled: true, child_price_type: 'qt', child_price: 99 },
      ] };
      const value = qtySelector ? { _opf_type: 'products', quantities: { a: 3, b: 2, free: 4, disabled: 8, stale: 5 } } : ['a', 'b', 'free', 'disabled', 'stale'];
      const expectedLine = qtySelector ? 41 : 7 + 10 * parentQty;
      assert.equal(context.addon(def, value, 20, parentQty, 0, '') * parentQty, expectedLine);
      assert.equal(context.addon(def, qtySelector ? { _opf_type: 'products', quantities: {} } : '', 20, parentQty, 0, ''), 0);
    });
  }
}
test('regression: selected category child charges make 40-dollar parent total 92 dollars', () => {
  const parentQty = 2;
  const fields = [
    [{ type: 'products', choices: [{ slug: 'alpha', child_price_type: 'qt', child_price: 8 }] }, ['alpha']],
    [{ type: 'products', choices: [{ slug: 'beta', child_price_type: 'none', child_price: 12 }] }, ['beta']],
    [{ type: 'products', qty_selector: true, choices: [{ slug: 'beta', child_price_type: 'nr', child_price: 12 }] }, { _opf_type: 'products', quantities: { beta: 3 } }],
    [{ type: 'products', qty_selector: true, choices: [{ slug: 'alpha', child_price_type: 'none', child_price: 8 }] }, { _opf_type: 'products', quantities: { alpha: 4 } }],
  ];
  const addons = fields.reduce((sum, [def, value]) => sum + context.addon(def, value, 20, parentQty, sum, ''), 0);
  assert.equal((20 + addons) * parentQty, 92);
});
