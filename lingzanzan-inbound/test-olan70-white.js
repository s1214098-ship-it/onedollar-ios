'use strict';

var fs = require('fs');
var path = require('path');
var vm = require('vm');

var src = fs.readFileSync(path.join(__dirname, 'inventory-freight-entry-20260810.js'), 'utf8');
var html = fs.readFileSync(path.join(__dirname, 'admin-inventory-entry.html'), 'utf8');

function mustContain(needle, label) {
  if (src.indexOf(needle) === -1) throw new Error('missing ' + (label || needle));
}

mustContain('LZ_OLAN70_WHITE_20260924', 'olan70 white marker');
mustContain('function inboundColorSearchKeys', 'color search keys');
mustContain('function inboundQueryHitsSku', 'combo query helper');
mustContain('never hide stock 0', 'stock-0 comment');
mustContain('search card shows catalog barcode OLAN70P35592', 'stored barcode comment');
mustContain('LZ_WH_STOCK_20260924', 'wh-stock still present');
mustContain('skuBarcodeAliasKeys', 'scan aliases');
if (html.indexOf('20260924-olan70-white-1') === -1) throw new Error('html cache bust missing');

src = src.replace(/\}\)\(\);\s*$/,
  'globalThis.__getState = function(){ return state; };\n' +
  'globalThis.__standaloneSkuMatches = standaloneSkuMatches;\n' +
  'globalThis.__family = receiptColorFamilyKey;\n' +
  'globalThis.__inboundSuggestColor = inboundSuggestColor;\n' +
  '})();'
);

function fakeEl() {
  var el = {
    value: 'TW',
    hidden: false,
    innerHTML: '',
    textContent: '',
    classList: { add: function () {}, remove: function () {}, contains: function () { return false; }, toggle: function () {} },
    style: {},
    dataset: {},
    querySelector: function () { return fakeEl(); },
    querySelectorAll: function () { return []; },
    getAttribute: function () { return ''; },
    setAttribute: function () {},
    addEventListener: function () {},
    removeEventListener: function () {},
    appendChild: function () { return el; },
    closest: function () { return null; },
    matches: function () { return false; },
    focus: function () {},
    click: function () {}
  };
  return el;
}

var documentMock = {
  querySelector: function (sel) {
    if (sel === '[data-purchase-receipt-warehouse]') return { value: 'TW' };
    return fakeEl();
  },
  querySelectorAll: function () { return []; },
  addEventListener: function () {},
  createElement: function () { return fakeEl(); },
  body: fakeEl(),
  documentElement: fakeEl()
};

var context = {
  console: console,
  Date: Date,
  Math: Math,
  Number: Number,
  String: String,
  Array: Array,
  Object: Object,
  JSON: JSON,
  parseInt: parseInt,
  parseFloat: parseFloat,
  isNaN: isNaN,
  setTimeout: function () { return 0; },
  clearTimeout: function () {},
  addEventListener: function () {},
  removeEventListener: function () {},
  fetch: function () { return Promise.resolve({ ok: true, json: function () { return Promise.resolve({}); } }); },
  localStorage: { getItem: function () { return '{}'; }, setItem: function () {} },
  document: documentMock,
  navigator: { userAgent: 'node' },
  location: { href: 'http://localhost/admin-inventory-entry.html' },
  MutationObserver: function () { this.observe = function () {}; }
};
context.window = context;
context.globalThis = context;
context.self = context;

vm.createContext(context);
vm.runInContext(src, context);

var product = {
  id: 'p-olan70',
  code: 'OLAN70',
  productLine: 'OLAN70',
  title: 'STANLEY海外原版不銹鋼',
  category: '水壺/保溫杯',
  colors: [
    { name: '藍色(BIRU)', color: '藍色(BIRU)', colorName: '藍色(BIRU)', code: '906' },
    { name: '粉紅色', color: '粉紅色', colorName: '粉紅色', code: '98' },
    { name: '白色(PUTI)', color: '白色(PUTI)', colorName: '白色(PUTI)', code: '92' },
    { name: '深藍色', color: '深藍色', colorName: '深藍色', code: '922' }
  ]
};

var skus = [
  { id: 'OLAN70P35592-TW', sku: 'OLAN70P35592-TW', productId: 'p-olan70', productCode: 'OLAN70', companyBarcode: 'OLAN70P35592', barcode: 'OLAN70P35592', color: '白色(PUTI)', colorName: '白色(PUTI)', colorCode: '92', size: 'NO SIZE', sizeName: 'NO SIZE', warehouse: '台灣倉', warehouseName: '台灣倉', warehouseCode: 'TW', stock: 0, status: 'active' },
  { id: 'OLAN70P35592', sku: 'OLAN70P35592', productId: 'p-olan70', productCode: 'OLAN70', companyBarcode: 'OLAN70P35592', barcode: 'OLAN70P35592', color: '白色(PUTI)', colorName: '白色(PUTI)', colorCode: '92', size: 'NO SIZE', sizeName: 'NO SIZE', warehouse: '中國倉', warehouseName: '中國倉', warehouseCode: 'CN', stock: 0, status: 'active' },
  { id: 'OLAN70P35598-TW', sku: 'OLAN70P35598-TW', productId: 'p-olan70', productCode: 'OLAN70', companyBarcode: 'OLAN70P35598', barcode: 'OLAN70P35598', color: '粉紅色', colorName: '粉紅色', colorCode: '98', size: 'NO SIZE', sizeName: 'NO SIZE', warehouse: '台灣倉', warehouseName: '台灣倉', warehouseCode: 'TW', stock: 0, status: 'active' },
  { id: 'OLAN70P355906-TW', sku: 'OLAN70P355906-TW', productId: 'p-olan70', productCode: 'OLAN70', companyBarcode: 'OLAN70P355906', barcode: 'OLAN70P355906', color: '藍色(BIRU)', colorName: '藍色(BIRU)', colorCode: '906', size: 'NO SIZE', sizeName: 'NO SIZE', warehouse: '台灣倉', warehouseName: '台灣倉', warehouseCode: 'TW', stock: 0, status: 'active' },
  { id: 'OLAN70P350906-TW', sku: 'OLAN70P350906-TW', productId: 'p-olan70', productCode: 'OLAN70', companyBarcode: 'OLAN70P350922', barcode: 'OLAN70P350922', color: '深藍色', colorName: '深藍色', colorCode: '922', size: 'NO SIZE', sizeName: 'NO SIZE', warehouse: '台灣倉', warehouseName: '台灣倉', warehouseCode: 'TW', stock: 0, status: 'active' }
];

context.__getState().products = [product];
context.__getState().skus = skus;
context.__getState().freight = { batches: [], items: [] };

function ids(query) {
  return context.__standaloneSkuMatches(query).map(function (sku) { return sku.id; });
}

var olan70 = ids('OLAN70');
if (olan70.indexOf('OLAN70P35592-TW') === -1) throw new Error('OLAN70 search missing TW white, got ' + olan70.join(','));
if (olan70.indexOf('OLAN70P35598-TW') === -1) throw new Error('OLAN70 search missing pink');
if (olan70.indexOf('OLAN70P355906-TW') === -1) throw new Error('OLAN70 search missing blue 906');
if (olan70.indexOf('OLAN70P350906-TW') === -1) throw new Error('OLAN70 search missing dark blue 922 (must not collapse into 藍)');

var combo = ids('OLAN70白色');
if (combo.indexOf('OLAN70P35592-TW') === -1) throw new Error('OLAN70白色 must find TW white, got ' + combo.join(','));
if (combo.indexOf('OLAN70P35598-TW') !== -1) throw new Error('OLAN70白色 must not list pink, got ' + combo.join(','));

var barcode = ids('OLAN70P35592');
if (barcode.indexOf('OLAN70P35592-TW') === -1) throw new Error('barcode OLAN70P35592 must find TW white');
if (barcode.length !== 1) throw new Error('barcode OLAN70P35592 should collapse to white only, got ' + barcode.join(','));

var puti = ids('OLAN70PUTI');
if (puti.indexOf('OLAN70P35592-TW') === -1) throw new Error('OLAN70PUTI must find white');

var whiteFamily = context.__family(skus[0], product);
if (whiteFamily !== 'c:92') throw new Error('white family should be c:92, got ' + whiteFamily);
var darkFamily = context.__family(skus[4], product);
if (darkFamily === 'c:906') throw new Error('深藍色 must not collapse into 藍色');
if (darkFamily !== 'c:922') throw new Error('深藍色 family should be c:922, got ' + darkFamily);

var color = context.__inboundSuggestColor(skus[0], product, skus[0].barcode);
if (color !== '白色') throw new Error('suggest color should be 白色, got ' + color);

if (src.indexOf('Number(sku.stock || 0) <= 0) return false') !== -1 && src.indexOf('standaloneSkuMatches') < src.indexOf('Number(sku.stock || 0) <= 0) return false')) {
  /* ignore other pages */
}

console.log('ok', { olan70: olan70, combo: combo, barcode: barcode, whiteFamily: whiteFamily, darkFamily: darkFamily, color: color });
