'use strict';

function text(value) { return String(value == null ? '' : value).trim(); }
function key(value) { return text(value).toLowerCase().replace(/[^a-z0-9\u4e00-\u9fff]+/g, ''); }

function receiptColorCode(value) {
  var raw = text(value).toUpperCase().replace(/[\s()（）+＋\-_]/g, '');
  var aliases = {
    '黑色': '91', '黑': '91', 'HITEM': '91',
    '白色': '92', '白': '92', 'PUTI': '92', 'PUTIH': '92',
    '卡其色': '904', 'DRIL': '904'
  };
  if (aliases[raw]) return aliases[raw];
  for (var name in aliases) {
    if (raw.indexOf(name) !== -1) return aliases[name];
  }
  return '';
}

function receiptSizeCode(value) {
  var raw = text(value).toUpperCase().replace(/[\s_]/g, '');
  if (!raw || raw === 'NOSIZE' || raw === 'NO-SIZE') return '00';
  var map = { XS: '1', S: '2', M: '3', L: '4', XL: '5', '2XL': '6', XXL: '6', '3XL': '7', XXXL: '7', '4XL': '8' };
  return map[raw] || '00';
}

var fs = require('fs');
var path = require('path');
var src = fs.readFileSync(path.join(__dirname, 'inventory-freight-entry-20260810.js'), 'utf8');
var start = src.indexOf('function uniqueBarcodeKeys');
var end = src.indexOf('function skuBarcodeAliasKeys');
if (start < 0 || end < 0) throw new Error('helpers not found');
var helpers = src.slice(start, end);
var parseStart = src.indexOf('function receivedParseConcatBarcode');
var parseEnd = src.indexOf('function receivedBarcodePrintPayloads');
if (parseStart < 0 || parseEnd < 0) throw new Error('parse/print not found');
var parsePrint = src.slice(parseStart, parseEnd);
var sandbox = { text: text, key: key, receiptColorCode: receiptColorCode, receiptSizeCode: receiptSizeCode, console: console };
var fn = new Function('text', 'key', 'receiptColorCode', 'receiptSizeCode', helpers + '\n' + parsePrint + '\nreturn { composeCanonicalCompanyBarcode: composeCanonicalCompanyBarcode, canonicalInboundBarcode: canonicalInboundBarcode, inboundBarcodeSearchKeys: inboundBarcodeSearchKeys, receivedParseConcatBarcode: receivedParseConcatBarcode, receivedPrintBarcode: receivedPrintBarcode, padReceiptSizeCode: padReceiptSizeCode };');
var api = fn(text, key, receiptColorCode, receiptSizeCode);

function assertEqual(actual, expected, label) {
  if (actual !== expected) throw new Error(label + ' expected ' + expected + ' got ' + actual);
}

assertEqual(api.composeCanonicalCompanyBarcode('OLAN75', '904', '00', 315), 'OLAN7590400P315', 'khaki compose');
assertEqual(api.composeCanonicalCompanyBarcode('OLAN79', '92', 'NO SIZE', 195), 'OLAN799200P195', 'white compose');
assertEqual(api.composeCanonicalCompanyBarcode('OLAN79', '92', 'S', 195), 'OLAN799202P195', 'white S compose');

var khakiOld = 'OLAN75P315904';
var whiteOld = 'OLAN79P19592';
var khakiLine = { productCode: 'OLAN75', color: '卡其色', size: 'NO SIZE', unitCostTwd: 315 };
var whiteLine = { productCode: 'OLAN79', color: '白色(PUTI)', size: 'NO SIZE', unitCostTwd: 195 };
var khakiSku = { colorCode: '904', colorName: '卡其色', size: 'NO SIZE', cost: 315, companyBarcode: khakiOld };
var whiteSku = { colorCode: '92', colorName: '白色(PUTI)', size: 'NO SIZE', cost: 195, companyBarcode: whiteOld };
var khakiProduct = { code: 'OLAN75' };
var whiteProduct = { code: 'OLAN79' };

assertEqual(api.canonicalInboundBarcode(khakiLine, khakiSku, khakiProduct, khakiOld), 'OLAN7590400P315', 'khaki field from stored mid-P');
assertEqual(api.canonicalInboundBarcode(whiteLine, whiteSku, whiteProduct, whiteOld), 'OLAN799200P195', 'white field from stored mid-P');
assertEqual(api.receivedPrintBarcode(khakiOld, khakiLine, khakiSku, khakiProduct), 'OLAN7590400P315', 'khaki print');
assertEqual(api.receivedPrintBarcode(whiteOld, whiteLine, whiteSku, whiteProduct), 'OLAN799200P195', 'white print');
assertEqual(api.canonicalInboundBarcode(khakiLine, khakiSku, khakiProduct, 'OLAN75P315C904S00'), 'OLAN7590400P315', 'khaki from C/S');
assertEqual(api.canonicalInboundBarcode(khakiLine, khakiSku, khakiProduct, 'OLAN75904P315'), 'OLAN7590400P315', 'khaki from print-without-00');

var khakiKeys = api.inboundBarcodeSearchKeys('OLAN7590400P315');
var whiteKeys = api.inboundBarcodeSearchKeys('OLAN799200P195');
if (khakiKeys.indexOf(key(khakiOld)) === -1) throw new Error('new khaki missing old alias ' + khakiKeys.join(','));
if (whiteKeys.indexOf(key(whiteOld)) === -1) throw new Error('new white missing old alias ' + whiteKeys.join(','));
if (api.inboundBarcodeSearchKeys(khakiOld).indexOf(key('OLAN7590400P315')) === -1) throw new Error('old khaki missing new alias');
if (api.inboundBarcodeSearchKeys(whiteOld).indexOf(key('OLAN799200P195')) === -1) throw new Error('old white missing new alias');
if (src.indexOf("productCode + 'P' + cost + 'C' + colorCode + 'S'") !== -1) throw new Error('C/S compose still present');
if (src.indexOf('window.print();') === -1) throw new Error('window.print missing');
if (src.indexOf('LZ_BARCODE_FMT_20260924') === -1) throw new Error('fmt marker missing');
if (src.indexOf('printReceivedBarcodeSheet') === -1) throw new Error('print sheet missing');

console.log('ok', {
  khaki: 'OLAN7590400P315',
  white: 'OLAN799200P195',
  khakiAliases: khakiKeys,
  whiteAliases: whiteKeys
});
