'use strict';

function text(value) { return String(value == null ? '' : value).trim(); }
function key(value) { return text(value).toLowerCase().replace(/[^a-z0-9\u4e00-\u9fff]+/g, ''); }

function receivedParseConcatBarcode(raw, line, sku, product) {
  var code = String(raw || '').trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
  var skuColor = text((sku && (sku.colorCode || sku.colorNo)) || '');
  var costNum = Math.max(0, Math.round(Number((line && (line.unitCostTwd || line.cost || line.barcodeCostTwd)) || (sku && (sku.currentCostTwd || sku.cost)) || 0)));
  var productCode = text((line && line.productCode) || (product && (product.code || product.productLine)) || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
  var v3 = code.match(/^([A-Z0-9]+)C([A-Z0-9]+)S([A-Z0-9]+)P(\d+)$/);
  if (v3) return { base: v3[1], colorCode: v3[2], sizeCode: v3[3], cost: v3[4] };
  var v2 = code.match(/^([A-Z0-9]+)P(\d+)C([A-Z0-9]+)S([A-Z0-9]+)$/);
  if (v2) return { base: v2[1], colorCode: v2[3], sizeCode: v2[4], cost: v2[2] };
  var mid = code.match(/^([A-Z]+\d*)P(\d+)$/);
  if (!mid) return null;
  var base = mid[1];
  var digits = mid[2];
  var colorCode = '';
  var cost = '';
  if (skuColor && digits.length > skuColor.length && digits.slice(-skuColor.length) === skuColor) {
    colorCode = skuColor;
    cost = digits.slice(0, -skuColor.length);
  } else if (costNum > 0 && digits.indexOf(String(costNum)) === 0 && digits.length > String(costNum).length) {
    cost = String(costNum);
    colorCode = digits.slice(String(costNum).length);
  } else if (digits.length >= 5) {
    colorCode = digits.slice(-3);
    cost = digits.slice(0, -3);
  } else {
    return null;
  }
  if (!cost || !colorCode) return null;
  return { base: productCode || base, colorCode: colorCode, sizeCode: '', cost: cost.replace(/^0+(?=\d)/, '') };
}

function receivedPrintBarcode(raw, line, sku, product) {
  var parsed = receivedParseConcatBarcode(raw, line, sku, product);
  if (!parsed) {
    var already = String(raw || '').trim().toUpperCase();
    return already;
  }
  var sizeCode = text(parsed.sizeCode);
  if (sizeCode === '00' || sizeCode === 'NOSIZE' || sizeCode === 'NO-SIZE') sizeCode = '';
  return String(parsed.base || '').toUpperCase() + String(parsed.colorCode || '') + sizeCode + 'P' + String(parsed.cost || '');
}

function uniqueBarcodeKeys(values) {
  var seen = {};
  var out = [];
  (values || []).forEach(function (value) {
    var identity = key(value);
    if (!identity || seen[identity]) return;
    seen[identity] = true;
    out.push(identity);
  });
  return out;
}

function inboundPrintAliasesFromStored(raw) {
  var compact = text(raw).replace(/\s+/g, '').toUpperCase();
  if (!compact) return [];
  var aliases = [compact];
  var parsed = receivedParseConcatBarcode(compact, {}, {}, {});
  if (parsed && parsed.base && parsed.cost && parsed.colorCode) {
    aliases.push(String(parsed.base).toUpperCase() + String(parsed.colorCode || '') + String(parsed.sizeCode || '') + 'P' + String(parsed.cost));
  }
  return aliases;
}

function inboundStoredAliasesFromPrint(raw) {
  var compact = text(raw).replace(/\s+/g, '').toUpperCase();
  if (!compact) return [];
  var aliases = [compact];
  var match = compact.match(/^([A-Z]+)(\d+)P(\d+)$/);
  if (!match) return aliases;
  var letters = match[1];
  var midDigits = match[2];
  var cost = match[3];
  [4, 3, 2].forEach(function (colorLen) {
    if (midDigits.length > colorLen) {
      aliases.push(letters + midDigits.slice(0, -colorLen) + 'P' + cost + midDigits.slice(-colorLen));
    }
  });
  return aliases;
}

function inboundBarcodeSearchKeys(value) {
  var compact = text(value).replace(/\s+/g, '');
  return uniqueBarcodeKeys([compact].concat(inboundPrintAliasesFromStored(compact), inboundStoredAliasesFromPrint(compact)));
}

function looksLikeBarcodeQuery(value) {
  var compact = text(value).replace(/\s+/g, '');
  if (compact.length < 8) return false;
  if (/[\u4e00-\u9fff]/.test(compact)) return false;
  return /^[A-Za-z0-9\-_]+$/.test(compact) && /[A-Za-z]/.test(compact) && /\d/.test(compact);
}

var stored = 'OLAN75P315904';
var printed = 'OLAN75904P315';
var printFromStored = receivedPrintBarcode(stored, {}, {}, {});
if (printFromStored !== printed) throw new Error('print from stored expected ' + printed + ' got ' + printFromStored);

var searchPrint = inboundBarcodeSearchKeys(printed);
var searchStored = inboundBarcodeSearchKeys(stored);
if (searchPrint.indexOf(key(stored)) === -1) throw new Error('print query missing stored alias: ' + searchPrint.join(','));
if (searchStored.indexOf(key(printed)) === -1) throw new Error('stored query missing print alias: ' + searchStored.join(','));

if (!looksLikeBarcodeQuery(printed)) throw new Error('print code should look like barcode');
if (!looksLikeBarcodeQuery('  OLAN75904P315  ')) throw new Error('spaced print code should look like barcode');
if (looksLikeBarcodeQuery('卡其外套')) throw new Error('Chinese title should stay fuzzy');
if (looksLikeBarcodeQuery('OLAN75')) throw new Error('short prefix should stay fuzzy');

console.log('ok', {
  printFromStored: printFromStored,
  searchPrint: searchPrint,
  searchStored: searchStored
});
