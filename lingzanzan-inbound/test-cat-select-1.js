'use strict';

var fs = require('fs');
var path = require('path');
var vm = require('vm');

var src = fs.readFileSync(path.join(__dirname, 'inventory-freight-entry-20260810.js'), 'utf8');
var html = fs.readFileSync(path.join(__dirname, 'admin-inventory-entry.html'), 'utf8');
var css = fs.readFileSync(path.join(__dirname, 'inventory-freight-entry.css'), 'utf8');

function mustContain(hay, needle, label) {
  if (hay.indexOf(needle) === -1) throw new Error('missing ' + (label || needle));
}

mustContain(html, '20260924-cat-select-2', 'html cache bust');
mustContain(html, '<select data-purchase-receipt-category>', 'header category select');
mustContain(html, 'LZ_CAT_SELECT_20260924', 'html select marker');
if (/<input[^>]*data-purchase-receipt-category/.test(html)) throw new Error('header category is still a typing input');
if (html.indexOf('輸入分類後選擇') !== -1) throw new Error('old type-to-choose placeholder still present');
if (html.indexOf('data-purchase-receipt-category-suggest') !== -1) throw new Error('category typeahead host should be gone');

mustContain(src, 'LZ_CAT_SELECT_20260924', 'js cat-select marker');
mustContain(src, 'LZ_CONFIRM_FILL_20260924', 'confirm-fill still present');
mustContain(src, 'function liveCategoryForLine', 'manual-category guard');
mustContain(src, 'function suggestedCategoryForNewLine', 'first-add / confirm-fill suggest');
mustContain(src, 'function standaloneCategoryOptionsHtml', 'category option builder');
mustContain(src, '<select data-standalone-line-category>', 'line category select');
mustContain(src, 'categoryManual', 'keep user dropdown choice');
mustContain(src, 'window.print();', 'print-qr-9 window.print');
mustContain(src, 'LZ_PRINT_QR_20260924_9', 'print qr9');
mustContain(src, 'LZ_RECV_SCAN_20260924_2', 'recv-scan-2');
mustContain(src, 'LZ_THUMB_HOVER_20260924_2', 'thumb-hover-2');
mustContain(src, 'LZ_WH_STOCK_20260924', 'four-warehouse search');
mustContain(src, 'P at END', 'barcode fmt P-at-end');
mustContain(src, "base + color + size + 'P' + costPart", 'P at end compose');
mustContain(src, 'state.standaloneLines.forEach(function (line) { completeStandaloneReceiptLine(line); });', 'confirm fills before empty check');
mustContain(src, '正式入庫前，每個品項都必須補齊產品分類、產品編號、公司條碼、顏色與尺寸。', 'red-bar empty check still after fill');
if (src.indexOf('<label>產品分類<input') !== -1) throw new Error('line category is still a typing input');
mustContain(css, 'LZ_CAT_SELECT_20260924', 'css cat-select marker');

src = src.replace(/\}\)\(\);\s*$/,
  'globalThis.__getState = function(){ return state; };\n' +
  'globalThis.__liveCategoryForLine = liveCategoryForLine;\n' +
  'globalThis.__suggestedCategoryForNewLine = suggestedCategoryForNewLine;\n' +
  'globalThis.__standaloneCategoryOptionsHtml = standaloneCategoryOptionsHtml;\n' +
  'globalThis.__completeStandaloneReceiptLine = completeStandaloneReceiptLine;\n' +
  '})();'
);

function fakeEl() {
  var el = {
    value: '',
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

var state = context.__getState();
state.products = [
  { id: 'p-olan70', code: 'OLAN70', productLine: 'OLAN70', title: 'STANLEY海外原版不銹鋼', category: '水壺/保溫杯' },
  { id: 'p-ja1', code: 'JA001', productLine: 'JA001', title: '外套樣品', category: '外套' },
  { id: 'p-bow', code: 'BOW001', productLine: 'BOW001', title: '餐碗', category: '餐碗類' }
];
state.categories = ['外套', '水壺/保溫杯', '餐碗類', '短袖上衣'];
state.skus = [
  { id: 'OLAN70P35592', productId: 'p-olan70', productCode: 'OLAN70', companyBarcode: 'OLAN70P35592', barcode: 'OLAN70P35592', colorName: '白色', color: '白色', colorCode: '92', size: 'NO SIZE', sizeName: 'NO SIZE' }
];

var htmlOptions = context.__standaloneCategoryOptionsHtml('水壺/保溫杯');
if (htmlOptions.indexOf('<option value="">請選擇產品分類</option>') === -1) throw new Error('placeholder option missing');
if (htmlOptions.indexOf('value="水壺/保溫杯" selected') === -1) throw new Error('known category not selected');
if (htmlOptions.indexOf('value="外套"') === -1) throw new Error('vocab 外套 missing');
if (htmlOptions.indexOf('value="餐碗類"') === -1) throw new Error('vocab 餐碗類 missing');
if (htmlOptions.indexOf('室溫水壺') !== -1) throw new Error('invented category leaked into options');

var unknownHtml = context.__standaloneCategoryOptionsHtml('舊分類X');
if (unknownHtml.indexOf('value="舊分類X" selected') === -1) throw new Error('unknown current value must stay selected');
if (unknownHtml.indexOf('value="外套"') === -1) throw new Error('known list must remain with unknown current');

var filled = context.__liveCategoryForLine({ productCode: 'OLAN70', category: '', productId: 'p-olan70' }, state.products[0]);
if (filled !== '水壺/保溫杯') throw new Error('first-add / empty confirm-fill should take product master, got ' + filled);

var olanWrong = context.__liveCategoryForLine({ productCode: 'OLAN70', category: '外套', productId: 'p-olan70' }, { category: '外套' });
if (olanWrong !== '水壺/保溫杯') throw new Error('confirm-fill OLAN 外套 → 水壺/保溫杯 broken, got ' + olanWrong);

var kept = context.__liveCategoryForLine({ productCode: 'OLAN70', category: '外套', productId: 'p-olan70', categoryManual: true }, state.products[0]);
if (kept !== '外套') throw new Error('user dropdown choice was overwritten');

var completed = context.__completeStandaloneReceiptLine({
  productId: 'p-olan70',
  productCode: 'OLAN70',
  skuId: 'OLAN70P35592',
  category: '',
  barcode: '',
  color: '',
  size: ''
});
if (completed.category !== '水壺/保溫杯') throw new Error('confirm-fill did not fill empty category select, got ' + completed.category);
if (!completed.category || !completed.productCode || !completed.barcode || !completed.color || !completed.size) {
  throw new Error('filled select/line should count as filled for confirm validation');
}

var userPick = context.__completeStandaloneReceiptLine({
  productId: 'p-olan70',
  productCode: 'OLAN70',
  skuId: 'OLAN70P35592',
  category: '餐碗類',
  categoryManual: true,
  barcode: 'OLAN7092P355',
  color: '白色',
  size: 'NO SIZE'
});
if (userPick.category !== '餐碗類') throw new Error('already-edited dropdown was overwritten on confirm, got ' + userPick.category);

console.log('cat-select-1 ok');
