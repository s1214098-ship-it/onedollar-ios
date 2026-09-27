'use strict';

function resolvedProductSkus(products, skus, productId, productCode, lineColor) {
  var product = products.find(function (row) {
    return (productId && String(row.id || '') === String(productId))
      || (productCode && String(row.code || '').toUpperCase() === String(productCode).toUpperCase());
  }) || null;
  var resolvedId = String((product && product.id) || productId || '').trim();
  if (!resolvedId) return [];
  return skus.filter(function (row) {
    return String(row.productId || '') === resolvedId;
  });
}

function stockColorKey(value) {
  var colorRaw = String(value || '').trim() || '未選顏色';
  var colorHead = colorRaw.split(/\s*\/\s*/)[0].replace(/[()（）].*$/, '').trim() || colorRaw;
  var map = { '咖啡色': '咖啡色', '咖色': '咖啡色', '棕色': '咖啡色', 'COKELAT': '咖啡色' };
  return map[colorHead] || map[colorHead.toUpperCase()] || colorHead;
}

function chipImage(info) {
  return info && info.isColorImage ? info.src : '';
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

var products = [{ id: 'p-ja335', code: 'JA335' }, { id: 'p-olan94', code: 'OLAN94' }];
var skus = [
  { id: 'olan-l', productId: 'p-olan94', color: '咖啡色', size: 'L', barcode: 'OLAN9490204P408' },
  { id: 'ja-l', productId: 'p-ja335', color: '咖色(cokelat)', size: 'L', barcode: 'JA335P4259024' }
];

assert('JA335 inbound does not take OLAN94 first SKU', resolvedProductSkus(products, skus, '', 'JA335').map(function (row) { return row.id; }), ['ja-l']);
assert('missing product does not scan whole catalog', resolvedProductSkus(products, skus, '', '').length, 0);
assert('咖色 and 咖啡色 / COKELAT share stock key', stockColorKey('咖色(cokelat)'), stockColorKey('咖啡色 / COKELAT'));
assert('chip ignores first product photo', chipImage({ src: './first.jpg', isColorImage: false }), '');
assert('chip keeps real color photo', chipImage({ src: './brown.jpg', isColorImage: true }), './brown.jpg');

if (failed) process.exit(1);
console.log('all passed');
