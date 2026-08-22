'use strict';

const assert = require('assert');
const { recodeToken, recodeTree, countNeedle } = require('./lz-olan85-bow010.js');

assert.strictEqual(recodeToken('p-olan85'), 'p-olan85');
assert.strictEqual(recodeToken('OLAN85'), 'BOW010');
assert.strictEqual(recodeToken('OLAN85P36593'), 'BOW010P36593');
assert.strictEqual(recodeToken('OLAN85P36593-TW'), 'BOW010P36593-TW');
assert.strictEqual(recodeToken('BOW007P901907'), 'BOW007P901907');
assert.strictEqual(recodeToken('BOW009P31092'), 'BOW009P31092');

const tree = recodeTree({
  id: 'p-olan85',
  code: 'OLAN85',
  productLine: 'OLAN85',
  skuId: 'OLAN85P36593-TW',
  barcode: 'OLAN85P36593',
  items: [{ code: 'OLAN85', sku: 'OLAN85P36593', title: '迪士尼米奇嘉喜' }],
  other: { code: 'BOW007', sku: 'BOW007P901907' },
  nita: 'BYORDER-20260720-078596'
});

assert.strictEqual(tree.id, 'p-olan85');
assert.strictEqual(tree.code, 'BOW010');
assert.strictEqual(tree.skuId, 'BOW010P36593-TW');
assert.strictEqual(tree.barcode, 'BOW010P36593');
assert.strictEqual(tree.items[0].code, 'BOW010');
assert.strictEqual(tree.other.code, 'BOW007');
assert.strictEqual(tree.nita, 'BYORDER-20260720-078596');
assert.strictEqual(countNeedle(tree, 'OLAN85'), 0);
assert.strictEqual(countNeedle(tree, 'BOW010P36593-TW'), 1);

console.log(JSON.stringify({ ok: true, tests: 13 }));
