#!/usr/bin/env node
var fs = require('fs');
var path = require('path');
var assert = require('assert');

var dir = __dirname;
var overlay = fs.readFileSync(path.join(dir, 'admin-product-forwarder-cost-9.js.new-color-size-1'), 'utf8');
var html = fs.readFileSync(path.join(dir, 'admin-products.html.new-color-size-1'), 'utf8');
var css = fs.readFileSync(path.join(dir, 'admin-products-horizontal-9.css.new-color-size-1'), 'utf8');

assert(overlay.indexOf('LZ_FILE_COLOR_SIZE_20260928') !== -1, 'overlay marker');
assert(overlay.indexOf('function pendingProductColorName') !== -1, 'pending color from select/input/id');
assert(overlay.indexOf('function ensureProductDraftMatrixCombinations') !== -1, 'matrix combinations helper');
assert(overlay.indexOf('function applyProductSizeModuleFromSelect') !== -1, 'size module apply helper');
assert(overlay.indexOf("closest('[data-product-add-color]')") !== -1, 'add color uses closest');
assert(overlay.indexOf("closest('[data-apply-size-module]')") !== -1, 'apply size uses closest');
assert(overlay.indexOf('淺藍色永遠 911') !== -1, 'standard code comment');
assert(overlay.indexOf('function applySelectedProductColorMainImage') !== -1, 'click main image applies color photo');
assert(overlay.indexOf('data-product-draft-image') !== -1, 'draft image pick target');
assert(html.indexOf('admin-product-forwarder-cost-9.js?v=20260928-color-size-2') !== -1, 'js cache bust');
assert(html.indexOf('admin-products-horizontal-9.css?v=20260928-color-size-2') !== -1, 'css cache bust');
assert(html.indexOf('>新增顏色<') !== -1, 'add color button label');
assert(html.indexOf('顏色名稱') !== -1, 'chinese name label');
assert(html.indexOf('印尼文') !== -1, 'indonesian label');
assert(html.indexOf('6. 顏色尺碼矩陣') !== -1, 'matrix heading');
assert(css.indexOf('LZ_FILE_COLOR_SIZE_20260928') !== -1, 'css marker');

function stripProductColorCodePrefix(value) {
  return String(value || '').replace(/^(?:\d{2,4}|N\d{3})\s+/, '').trim();
}

function canonicalChineseColorName(value) {
  var text = String(value || '').trim();
  text = stripProductColorCodePrefix(text);
  var map = [
    [/深藍|navy|biru\s*tua/i, '深藍色'],
    [/淺藍|天藍|sky\s*blue|biru\s*muda/i, '淺藍色'],
    [/藍|\bbir\b|biru|blue/i, '藍色']
  ];
  if (/[A-Za-z]/.test(text)) {
    for (var i = 0; i < map.length; i += 1) if (map[i][0].test(text)) return map[i][1];
  }
  return ({
    '淺藍': '淺藍色', '淺藍色': '淺藍色',
    '深藍': '深藍色', '深藍色': '深藍色',
    '藍': '藍色', '藍色': '藍色'
  })[text] || text || '未設定';
}

var aliases = {
  '藍色': '96', '藍': '96', 'BIRU': '96',
  '深藍': '906', '深藍色': '906', 'BIRUTUA': '906',
  '淺藍': '911', '淺藍色': '911', 'BIRUMUDA': '911'
};
var namesByCode = { '96': '藍色', '906': '深藍色', '911': '淺藍色' };

function legacyColorCodeFromName(value) {
  var normalized = String(value || '').trim().toUpperCase().replace(/[\s()（）+＋\-_]/g, '');
  if (aliases[normalized]) return aliases[normalized];
  var bestCode = '';
  var bestLen = 0;
  Object.keys(aliases).forEach(function (alias) {
    if (normalized === alias || normalized.indexOf(alias) === 0) {
      if (alias.length > bestLen) {
        bestLen = alias.length;
        bestCode = aliases[alias];
      }
    }
  });
  return bestCode || '';
}

function productColorIdentity(color) {
  color = color && typeof color === 'object' ? color : { name: color };
  var rawName = stripProductColorCodePrefix(color.name || '');
  var name = canonicalChineseColorName(rawName);
  var inventoryCode = legacyColorCodeFromName(name);
  var inventoryName = canonicalChineseColorName(namesByCode[inventoryCode] || '');
  var storedCode = String(color.code || '');
  var storedName = canonicalChineseColorName(namesByCode[storedCode] || '');
  var finalName = name || inventoryName || rawName;
  var finalCode = inventoryCode || storedCode || '';
  if (storedCode) {
    if (!storedName || storedName === '未設定') {
      finalCode = (inventoryCode && inventoryName === finalName) ? inventoryCode : storedCode;
    } else if (storedName === finalName) {
      finalCode = storedCode;
    } else {
      finalCode = inventoryCode || storedCode;
    }
  }
  return { name: finalName, code: finalCode };
}

assert.strictEqual(canonicalChineseColorName('BIRU MUDA'), '淺藍色');
assert.strictEqual(canonicalChineseColorName('96 淺藍色'), '淺藍色');
assert.strictEqual(legacyColorCodeFromName('淺藍色'), '911');
assert.strictEqual(legacyColorCodeFromName('BIRU MUDA'), '911');
assert.strictEqual(productColorIdentity({ name: '淺藍色', code: '96' }).code, '911');
assert.strictEqual(productColorIdentity({ name: '淺藍色', code: '96' }).name, '淺藍色');
assert.strictEqual(productColorIdentity({ name: '深藍色', code: '933' }).code, '906');
assert.strictEqual(productColorIdentity({ name: '藍色', code: '96' }).code, '96');

function dedupe(colors) {
  var seenName = {};
  var next = [];
  colors.forEach(function (item) {
    var ident = productColorIdentity(item);
    if (!ident.name || ident.name === '未設定') return;
    if (seenName[ident.name] != null) {
      next[seenName[ident.name]].code = ident.code || next[seenName[ident.name]].code;
      return;
    }
    seenName[ident.name] = next.length;
    next.push({ name: ident.name, code: ident.code });
  });
  return next;
}

var merged = dedupe([
  { name: '96 淺藍色', code: '96' },
  { name: '淺藍色', code: '96' },
  { name: '933 深藍色', code: '933' }
]);
assert.strictEqual(merged.length, 2);
assert.deepStrictEqual(merged[0], { name: '淺藍色', code: '911' });
assert.deepStrictEqual(merged[1], { name: '深藍色', code: '906' });

function ensureMatrix(colors, sizes, stocks) {
  stocks = stocks || {};
  colors.forEach(function (color) {
    sizes.forEach(function (size) {
      var key = color.name + '||' + size;
      if (stocks[key] == null) stocks[key] = 0;
    });
  });
  return stocks;
}

var stocks = ensureMatrix([{ name: '淺藍色' }], ['S', 'M', 'L', 'XL', '2XL'], {});
assert.strictEqual(Object.keys(stocks).length, 5);
assert.strictEqual(stocks['淺藍色||S'], 0);
assert.strictEqual(stocks['淺藍色||2XL'], 0);

console.log('test-file-color-size-1 ok');
