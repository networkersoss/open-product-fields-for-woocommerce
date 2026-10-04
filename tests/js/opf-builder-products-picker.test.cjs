const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');

const builder = fs.readFileSync('assets/js/opf-builder.js', 'utf8');

test('manual products picker subscribes to SelectWoo jQuery change events', () => {
	assert.match(builder, /window\.jQuery\( search \)\.on\( 'change', updateProductChoices \)/);
});

test('manual products picker keeps a native change fallback without jQuery', () => {
	assert.match(builder, /search\.addEventListener\( 'change', updateProductChoices \)/);
});
