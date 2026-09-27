'use strict';

function dismissStack(nodes) {
  var drop = {
    'data-freight-received-print-ready': 1,
    'data-inventory-barcode-print-overlay': 1,
    'data-inventory-label-purpose-picker': 1,
    'data-freight-quantity-confirm': 1
  };
  return nodes.filter(function (node) {
    return !Object.keys(drop).some(function (key) { return node.attrs && node.attrs[key] !== undefined; });
  });
}

var failed = 0;
function assert(name, actual, expected) {
  if (JSON.stringify(actual) !== JSON.stringify(expected)) {
    failed += 1;
    console.error('FAIL', name, actual, expected);
  } else {
    console.log('ok', name);
  }
}

assert('drops overlay stack, keeps form', dismissStack([
  { attrs: { 'data-freight-received-print-ready': '' } },
  { attrs: { 'data-inventory-label-purpose-picker': '' } },
  { attrs: { 'data-freight-item-form': '' } },
  { attrs: { 'data-freight-qty': '3' } }
]), [
  { attrs: { 'data-freight-item-form': '' } },
  { attrs: { 'data-freight-qty': '3' } }
]);

function plusLabel(visible) {
  return visible.indexOf('加一件') === -1 && visible.indexOf('＋') !== -1;
}
assert('plus is glyph not 加一件', plusLabel('＋'), true);
assert('rejects 加一件 XS', plusLabel('加一件 XS'), false);

if (failed) process.exit(1);
console.log('all passed');
