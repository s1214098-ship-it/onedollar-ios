var fs = require('fs');
var path = require('path');
var assert = require('assert');
var root = __dirname;

function read(name) {
  return fs.readFileSync(path.join(root, name), 'utf8');
}

var adminJs = read('admin.js.new-cat-vendor-1');
var inboundJs = read('inventory-freight-entry.js.new-cat-vendor-1');
var inboundHtml = read('admin-inventory-entry.html.new-cat-vendor-1');
var inboundCss = read('inventory-freight-entry.css.new-cat-vendor-1');
var adminCss = read('admin.css.new-cat-vendor-1');

assert.ok(adminJs.indexOf('LZ_CAT_VENDOR_PICK_20260928') !== -1, 'admin.js marker');
assert.ok(adminJs.indexOf('data-catalog-category-pick-mode="vendor"') !== -1, 'vendor tab');
assert.ok(adminJs.indexOf('分類／廠商找產品') !== -1, 'picker title');
assert.ok(adminJs.indexOf('function catalogPickerVendorGroups') !== -1, 'vendor groups');
assert.ok(adminJs.indexOf('function catalogCategoryPickerApplySelected') !== -1, 'apply selected');
assert.ok(adminJs.indexOf('adminShipmentBrowseIds') !== -1, 'shipment browse ids');
assert.ok(adminJs.indexOf('adminPreorderBrowseIds') !== -1, 'preorder browse ids');
assert.ok(adminJs.indexOf('freightVendorQueryMatches') !== -1, 'shipment vendor match');
assert.ok(inboundJs.indexOf('LZ_CAT_VENDOR_PICK_20260928') !== -1, 'inbound marker');
assert.ok(inboundJs.indexOf('function renderInboundCatalogBrowse') !== -1, 'inbound browse');
assert.ok(inboundJs.indexOf('data-purchase-receipt-browse-vendor') !== -1, 'inbound vendor select wiring');
assert.ok(inboundJs.indexOf('加入勾選產品') !== -1, 'inbound add checked products');
assert.ok(inboundHtml.indexOf('依分類找產品') !== -1, 'html category label');
assert.ok(inboundHtml.indexOf('依廠商找產品') !== -1, 'html vendor label');
assert.ok(inboundHtml.indexOf('data-purchase-receipt-browse-vendor') !== -1, 'html vendor select');
assert.ok(inboundCss.indexOf('LZ_CAT_VENDOR_PICK_20260928') !== -1, 'inbound css marker');
assert.ok(adminCss.indexOf('catalog-category-picker-modes') !== -1, 'admin css modes');

function fold(value) {
  return String(value || '').trim().toLowerCase().replace(/拚/g, '拼').replace(/[^a-z0-9\u4e00-\u9fff]+/g, '');
}

function productsForCategory(products, category) {
  var wanted = fold(category);
  return products.filter(function (product) { return fold(product.category) === wanted; });
}

function productsForVendor(products, vendor, index) {
  var wanted = fold(vendor);
  return products.filter(function (product) {
    return (index[product.id] || []).some(function (name) {
      var folded = fold(name);
      return folded === wanted || folded.indexOf(wanted) !== -1 || wanted.indexOf(folded) !== -1;
    });
  });
}

var catalog = [
  { id: 'p1', code: 'SET001', category: '套裝', title: '夏日套裝' },
  { id: 'p2', code: 'SET002', category: '套裝', title: '冬日套裝' },
  { id: 'p3', code: 'JA001', category: '外套', title: '飛行外套' },
  { id: 'p4', code: 'SET003', category: '套裝', title: '拼直專屬套裝' }
];
var vendorIndex = {
  p1: ['拼直'],
  p2: ['拼張'],
  p3: ['拼直'],
  p4: ['拼直', '拼多多']
};

var suits = productsForCategory(catalog, '套裝');
assert.strictEqual(suits.length, 3, 'category 套裝 has 3 products');
assert.deepStrictEqual(suits.map(function (row) { return row.code; }), ['SET001', 'SET002', 'SET003']);

var pingZhi = productsForVendor(catalog, '拼直', vendorIndex);
assert.strictEqual(pingZhi.length, 3, 'vendor 拼直 has 3 products');
assert.ok(pingZhi.every(function (row) { return ['SET001', 'JA001', 'SET003'].indexOf(row.code) !== -1; }));

var intersection = suits.filter(function (product) {
  return pingZhi.some(function (row) { return row.id === product.id; });
});
assert.deepStrictEqual(intersection.map(function (row) { return row.code; }).sort(), ['SET001', 'SET003']);

console.log('test-cat-vendor-1 ok');
