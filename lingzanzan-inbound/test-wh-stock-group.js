'use strict';

var fs = require('fs');
var path = require('path');
var src = fs.readFileSync(path.join(__dirname, 'inventory-freight-entry-20260810.js'), 'utf8');

function mustContain(needle, label) {
  if (src.indexOf(needle) === -1) throw new Error('missing ' + (label || needle));
}

mustContain('LZ_WH_STOCK_20260924', 'wh-stock marker');
mustContain('function receiptProductLineCode', 'product line helper');
mustContain('function attachReceiptWarehouseGroup', 'warehouse group helper');
mustContain('function receiptSkuForInbound', 'inbound sku picker');
mustContain('purchase-receipt-suggest-whs', 'four-warehouse markup');
mustContain('中國倉', 'CN stock label');
mustContain('台灣倉', 'TW stock label');
mustContain('印尼倉', 'ID stock label');
mustContain('預購倉', 'PREORDER stock label');
mustContain('LZ_THUMB_HOVER_20260924', 'hover');
mustContain('skuBarcodeAliasKeys', 'scan aliases');
mustContain('printReceivedBarcodeSheet', 'print sheet');
mustContain('window.print();', 'print call');
mustContain('LZ_PRINT_QR_20260924_9', 'print qr9');
if (src.indexOf('product.id || product.code || product.productLine') !== -1 && src.indexOf('receiptProductGroupKey') === -1) {
  throw new Error('old product.id grouping still present without group key helper');
}

var html = fs.readFileSync(path.join(__dirname, 'admin-inventory-entry.html'), 'utf8');
if (html.indexOf('20260924-wh-stock-1') === -1) throw new Error('html cache bust missing');
if (html.indexOf('中國／台灣／印尼／預購倉現有庫存') === -1) throw new Error('html hint missing');

var css = fs.readFileSync(path.join(__dirname, 'inventory-freight-entry.css'), 'utf8');
if (css.indexOf('.purchase-receipt-suggest-whs') === -1) throw new Error('css warehouse cluster missing');

function text(value) { return String(value == null ? '' : value).trim(); }
function key(value) { return text(value).toLowerCase().replace(/[^a-z0-9\u4e00-\u9fff]+/g, ''); }

var products = [
  { id: 'p-bow001', code: 'BOW001', productLine: 'BOW001', title: '新款蠟筆小新陶瓷可愛', category: '餐碗類', mainImage: './assets/brand-logo.jpg', colors: [{ name: '白色', colorName: '白色(PUTI)', code: '', image: '' }] },
  { id: 'p-bow001p17992', code: 'BOW001P17992', productLine: 'BOW001', title: '新款蠟筆小新陶瓷可愛', category: '餐碗類', mainImage: './data/image-assets/medium/img-e51347414bf5634f2ded85abb59e19ce7a9a9fd4.jpg', colors: [{ name: '白色', colorName: '白色(PUTI)', code: '92', image: './data/image-assets/medium/img-56d9f8731416cbc1acd0f983481928fe2cfde5c4.jpg' }] }
];
var state = { products: products, skus: [], freight: { items: [] } };

function productById(id) {
  return products.find(function (row) { return text(row.id) === text(id); }) || null;
}
function productByCode(code) {
  var wanted = key(code);
  if (!wanted) return null;
  return products.find(function (row) { return [row.id, row.code, row.productLine, row.title].some(function (value) { return key(value) === wanted; }); }) || null;
}
function productCodeForSku(sku) {
  var product = productById(sku && sku.productId);
  return text(product && (product.code || product.productLine || product.title)) || text(sku && sku.productId);
}
function firstProductImage(product, sku) {
  product = product || {};
  sku = sku || {};
  var colors = Array.isArray(product.colors) ? product.colors : [];
  for (var i = 0; i < colors.length; i += 1) {
    var img = colors[i] && (colors[i].image || colors[i].colorImage);
    if (img) return img;
  }
  return text(sku.colorImage || product.mainImage);
}
function receiptChineseColor(value) {
  return text(value).replace(/[（(][^）)]*[A-Za-z][^）)]*[）)]?/g, ' ').replace(/[A-Za-z][A-Za-z\s_\-/]*/g, ' ').replace(/[（()）]/g, ' ').replace(/\s+/g, ' ').trim();
}
function receiptColorFamilyKey(sku, product) {
  sku = sku || {};
  var raw = text(sku.colorName || sku.color);
  var zh = receiptChineseColor(raw);
  var code = text(sku.colorCode || sku.colorNo);
  if (code === '92' || (/白/.test(raw + zh))) return 'c:92';
  return 'c:' + (code || key(zh || raw) || 'nocolor');
}
function looksLikeBarcodeQuery(value) {
  var compact = text(value).replace(/\s+/g, '');
  if (compact.length < 8) return false;
  return /^[A-Za-z0-9\-_]+$/.test(compact) && /[A-Za-z]/.test(compact) && /\d/.test(compact);
}
function receivedParseConcatBarcode(raw) {
  var code = String(raw || '').trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
  var mid = code.match(/^([A-Z]+\d*)P(\d+)$/);
  if (!mid) return null;
  return { base: mid[1], cost: mid[2], colorCode: '', sizeCode: '' };
}

var start = src.indexOf('function receiptProductLineCode');
var end = src.indexOf('function skuAlreadyCoversFreightItem');
if (start < 0 || end < 0) throw new Error('group helpers not found');
var helpers = src.slice(start, end);
var sandbox = { text: text, key: key, productById: productById, productByCode: productByCode, productCodeForSku: productCodeForSku, firstProductImage: firstProductImage, receiptColorFamilyKey: receiptColorFamilyKey, receiptWarehouseCode: null, receiptWantedWarehouse: function () { return 'CN'; }, looksLikeBarcodeQuery: looksLikeBarcodeQuery, receivedParseConcatBarcode: receivedParseConcatBarcode, preferredReceiptSku: null, state: state };
var fn = new Function(
  'text', 'key', 'productById', 'productByCode', 'productCodeForSku', 'firstProductImage', 'receiptColorFamilyKey', 'looksLikeBarcodeQuery', 'receivedParseConcatBarcode', 'state',
  src.slice(src.indexOf('function receiptWarehouseCode'), src.indexOf('function receiptWantedWarehouse')) +
  '\nfunction receiptWantedWarehouse() { return "CN"; }\n' +
  src.slice(src.indexOf('function preferredReceiptSku'), src.indexOf('function receiptProductLineCode')) +
  helpers +
  '\nreturn { receiptProductLineCode: receiptProductLineCode, receiptProductGroupKey: receiptProductGroupKey, collapseStandaloneSkuMatches: collapseStandaloneSkuMatches, receiptGroupCatalogImage: receiptGroupCatalogImage, receiptSkuForInbound: receiptSkuForInbound };'
);
var api = fn(text, key, productById, productByCode, productCodeForSku, firstProductImage, receiptColorFamilyKey, looksLikeBarcodeQuery, receivedParseConcatBarcode, state);

var preorder = {
  id: 'BOW001P17992', sku: 'BOW001P17992', productId: 'p-bow001', productCode: 'BOW001',
  companyBarcode: 'BOW001P17992', barcode: 'BOW001P17992', warehouse: '預購倉', warehouseName: '預購倉', warehouseCode: 'PREORDER',
  colorName: '白色(PUTI)', color: '白色(PUTI)', sizeName: 'NO SIZE', size: 'NO SIZE', stock: 0, colorImage: ''
};
var china = {
  id: 'BOW001P17992-92-NO-SIZE', sku: 'BOW001P17992-92-NO-SIZE', productId: 'p-bow001p17992',
  companyBarcode: 'BOW001P20992', barcode: 'BOW001P20992', warehouse: '中國倉', warehouseName: '中國倉', warehouseCode: 'CN',
  colorName: '白色(PUTI)', color: '白色(PUTI)', colorCode: '92', sizeName: 'NO SIZE', size: 'NO SIZE', stock: 3,
  colorImage: './uploads/generated/img-56d9f8731416cbc1acd0f983481928fe2cfde5c4.jpg'
};

if (api.receiptProductGroupKey(preorder) !== api.receiptProductGroupKey(china)) {
  throw new Error('BOW001 preorder/CN should share group key, got ' + api.receiptProductGroupKey(preorder) + ' vs ' + api.receiptProductGroupKey(china));
}

var collapsed = api.collapseStandaloneSkuMatches([preorder, china]);
if (collapsed.length !== 1) throw new Error('expected 1 collapsed row, got ' + collapsed.length);
var row = collapsed[0];
if (row.receiptGroupProductCode !== 'BOW001') throw new Error('canonical code should be BOW001, got ' + row.receiptGroupProductCode);
if (!row.receiptWarehouseStocks || Number(row.receiptWarehouseStocks.CN) !== 3) throw new Error('CN stock should be 3');
if (Number(row.receiptWarehouseStocks.PREORDER) !== 0) throw new Error('PREORDER stock should be 0');
if (Number(row.receiptWarehouseStocks.TW) !== 0 || Number(row.receiptWarehouseStocks.ID) !== 0) throw new Error('TW/ID should stay 0 without inventing SKUs');
var img = api.receiptGroupCatalogImage([preorder, china]);
if (!img || /brand-logo/i.test(img)) throw new Error('preorder should reuse China catalog photo, got ' + img);
if (row.receiptGroupBarcode !== 'BOW001P20992') throw new Error('canonical barcode should be CN company barcode, got ' + row.receiptGroupBarcode);

var inbound = api.receiptSkuForInbound(row);
if (text(inbound.id) !== text(china.id)) throw new Error('inbound to CN should use China SKU, got ' + inbound.id);

var otherColor = Object.assign({}, china, { id: 'bow-black', colorName: '黑色', color: '黑色', colorCode: '91' });
var twoColors = api.collapseStandaloneSkuMatches([preorder, china, otherColor]);
if (twoColors.length !== 2) throw new Error('black should stay a second row, got ' + twoColors.length);

console.log('ok', {
  groupKey: api.receiptProductGroupKey(preorder),
  code: row.receiptGroupProductCode,
  barcode: row.receiptGroupBarcode,
  stocks: row.receiptWarehouseStocks,
  image: img,
  inboundId: inbound.id
});
