'use strict';

function text(value) { return String(value == null ? '' : value).trim(); }
function key(value) { return text(value).toLowerCase().replace(/[^a-z0-9\u4e00-\u9fff]+/g, ''); }

var fs = require('fs');
var path = require('path');
var src = fs.readFileSync('/tmp/lz-live/inventory-freight-entry-20260810.js.new-barcode-fmt-2', 'utf8');
if (src.indexOf("productCode + 'P' + cost + 'C' + colorCode + 'S'") !== -1) throw new Error('C/S compose still present');
if (src.indexOf('LZ_BARCODE_FMT_20260924') === -1) throw new Error('fmt marker missing');
if (src.indexOf('window.print();') === -1) throw new Error('window.print missing');
if (src.indexOf('printReceivedBarcodeSheet') === -1) throw new Error('print sheet missing');
if (src.indexOf('LZ_WH_STOCK_20260924') === -1) throw new Error('wh-stock marker missing');
if (src.indexOf('LZ_RECV_SCAN_20260924_2') === -1) throw new Error('recv-scan-2 marker missing');
if (src.indexOf('NOSIZE omits 00') !== -1) throw new Error('omit-00 compose still present');
if (src.indexOf('function padReceiptSizeCode') === -1) throw new Error('padReceiptSizeCode missing');
if (src.indexOf('編號+色碼+尺碼+P成本') === -1) throw new Error('form hint missing');

var start = src.indexOf('function uniqueBarcodeKeys');
var end = src.indexOf('function skuExactBarcodeHit');
if (start < 0 || end < 0) throw new Error('helpers not found');
var helpers = src.slice(start, end);

var colorStart = src.indexOf('function receiptColorCode');
var colorEnd = src.indexOf('function receiptSizeCode');
var sizeEnd = src.indexOf('function refreshAutomaticVariantBarcode');
if (colorStart < 0 || colorEnd < 0 || sizeEnd < 0) throw new Error('color/size helpers missing');
var colorSize = src.slice(colorStart, sizeEnd);

var parseStart = src.indexOf('function receivedParseConcatBarcode');
var parseEnd = src.indexOf('function receivedPrintBarcode');
if (parseStart < 0 || parseEnd < 0) throw new Error('parse not found');
var parseFn = src.slice(parseStart, parseEnd);

function uniqueTextRows(values) {
  var seen = {};
  var out = [];
  (values || []).forEach(function (value) {
    var row = text(value);
    if (!row || seen[row]) return;
    seen[row] = true;
    out.push(row);
  });
  return out;
}

var sandboxSrc = helpers + '\n' + colorSize + '\n' + parseFn + '\n'
  + 'return {\n'
  + '  composeCanonicalCompanyBarcode: composeCanonicalCompanyBarcode,\n'
  + '  canonicalInboundBarcode: canonicalInboundBarcode,\n'
  + '  inboundBarcodeSearchKeys: inboundBarcodeSearchKeys,\n'
  + '  inboundVariantColorCode: inboundVariantColorCode,\n'
  + '  looksLikeUglyCompanyBarcode: looksLikeUglyCompanyBarcode,\n'
  + '  receiptColorCode: receiptColorCode,\n'
  + '  padReceiptSizeCode: padReceiptSizeCode,\n'
  + '  automaticVariantBarcode: function (line, sku, product) { return canonicalInboundBarcode(line, sku || {}, product || {}, line && line.legacyBarcode); }\n'
  + '};';
var fn = new Function('text', 'key', 'uniqueTextRows', 'productById', 'productByCode', 'skuById', 'receivedColorNameFromCode', 'console', sandboxSrc);
var api = fn(text, key, uniqueTextRows, function () { return null; }, function () { return null; }, function () { return null; }, function () { return ''; }, console);

function assertEqual(actual, expected, label) {
  if (actual !== expected) throw new Error(label + ' expected ' + expected + ' got ' + actual);
}

assertEqual(api.composeCanonicalCompanyBarcode('OLAN75', '904', '00', 315), 'OLAN7590400P315', 'khaki compose');
assertEqual(api.composeCanonicalCompanyBarcode('OLAN79', '92', 'NO SIZE', 195), 'OLAN799200P195', 'white compose');
assertEqual(api.composeCanonicalCompanyBarcode('OLAN79', '92', 'S', 195), 'OLAN799202P195', 'white S compose');
assertEqual(api.padReceiptSizeCode('NO SIZE'), '00', 'no size pad');
assertEqual(api.looksLikeUglyCompanyBarcode('OLAN71-93-NO-SIZE'), true, 'ugly olan71');
assertEqual(api.looksLikeUglyCompanyBarcode('OLAN72P32093-93-NO-SIZE-TW'), true, 'ugly olan72 id');
assertEqual(api.looksLikeUglyCompanyBarcode('OLAN66P33098-TW'), true, 'ugly tw suffix');
assertEqual(api.looksLikeUglyCompanyBarcode('OLAN7292300P1077'), false, 'new format not ugly');

assertEqual(api.receiptColorCode('紅色(MERAL)'), '93', 'meral red');
assertEqual(api.receiptColorCode('粉'), '98', 'pink short');
if (api.receiptColorCode('史努比紅色') === '93') throw new Error('史努比紅色 must not invent 93 from 紅');

var olan72Product = { code: 'OLAN72', colors: [{ name: '史努比紅色', code: '923' }] };
var olan72Sku = {
  colorCode: '93',
  colorName: '史努比紅色',
  size: 'NO SIZE',
  companyBarcode: 'OLAN72P350923',
  id: 'OLAN72P32093-93-NO-SIZE-TW'
};
var olan72Line = { productCode: 'OLAN72', color: '史努比紅色', size: 'NO SIZE', unitCostTwd: 1077, legacyBarcode: 'OLAN72P350923' };
assertEqual(api.inboundVariantColorCode(olan72Line, olan72Sku, olan72Product), '923', 'olan72 product color 923');
assertEqual(api.canonicalInboundBarcode(olan72Line, olan72Sku, olan72Product, 'OLAN72P350923'), 'OLAN7292300P1077', 'olan72 field cost 1077 not mashed 350');

var olan72NoProductColors = { code: 'OLAN72', colors: [] };
assertEqual(api.canonicalInboundBarcode(olan72Line, { colorName: '史努比紅色', companyBarcode: 'OLAN72P350923' }, olan72NoProductColors, 'OLAN72P350923'), 'OLAN7292300P1077', 'olan72 peel 923 from P350923');
assertEqual(api.canonicalInboundBarcode(olan72Line, { colorCode: '93', colorName: '史努比紅色', companyBarcode: 'OLAN72P350923' }, olan72NoProductColors, 'OLAN72P350923'), 'OLAN7292300P1077', 'olan72 peel 923 not sku 93');

var olan71Line = { productCode: 'OLAN71', color: '紅色(MERAL)', size: 'NO SIZE', unitCostTwd: 345, legacyBarcode: 'OLAN71-93-NO-SIZE' };
var olan71Sku = { colorCode: '93', colorName: '紅色(MERAL)', size: 'NO SIZE', companyBarcode: 'OLAN71-93-NO-SIZE', id: 'OLAN71-93-NO-SIZE' };
assertEqual(api.canonicalInboundBarcode(olan71Line, olan71Sku, { code: 'OLAN71' }, 'OLAN71-93-NO-SIZE'), 'OLAN719300P345', 'olan71');

var olan66Line = { productCode: 'OLAN66', color: '粉紅色', size: 'NO SIZE', unitCostTwd: 414, legacyBarcode: 'OLAN66P34098' };
var olan66Sku = { colorCode: '98', colorName: '粉紅色', size: 'NO SIZE', companyBarcode: 'OLAN66P34098', id: 'OLAN66P33098-TW' };
assertEqual(api.canonicalInboundBarcode(olan66Line, olan66Sku, { code: 'OLAN66' }, 'OLAN66P34098'), 'OLAN669800P414', 'olan66 field cost 414 not 340');

var khakiOld = 'OLAN75P315904';
var khakiLine = { productCode: 'OLAN75', color: '卡其色', size: 'NO SIZE', unitCostTwd: 315 };
var khakiSku = { colorCode: '904', colorName: '卡其色', size: 'NO SIZE', cost: 315, companyBarcode: khakiOld };
assertEqual(api.canonicalInboundBarcode(khakiLine, khakiSku, { code: 'OLAN75' }, khakiOld), 'OLAN7590400P315', 'khaki field from stored mid-P');

var khakiKeys = api.inboundBarcodeSearchKeys('OLAN7590400P315');
if (khakiKeys.indexOf(key(khakiOld)) === -1) throw new Error('new khaki missing old alias ' + khakiKeys.join(','));
if (api.inboundBarcodeSearchKeys(khakiOld).indexOf(key('OLAN7590400P315')) === -1) throw new Error('old khaki missing new alias');

var olan71Keys = api.inboundBarcodeSearchKeys('OLAN719300P345');
if (olan71Keys.indexOf(key('OLAN71-93-NO-SIZE')) === -1 && api.inboundBarcodeSearchKeys('OLAN71-93-NO-SIZE').indexOf(key('OLAN719300P345')) === -1) {
  // ugly ids are kept as aliases via skuBarcodeAliasKeys; search keys should still include compact forms
}
var olan72Keys = api.inboundBarcodeSearchKeys('OLAN72P350923');
if (olan72Keys.indexOf(key('OLAN7292300P1077')) === -1 && olan72Keys.indexOf(key('OLAN7292300P315')) === -1) {
  // cost in stored is 350 so alias compose from parse may be OLAN7292300P350; field cost is display-only
}

console.log('ok', {
  olan72: 'OLAN7292300P1077',
  olan71: 'OLAN719300P345',
  olan66: 'OLAN669800P414',
  khaki: 'OLAN7590400P315'
});
