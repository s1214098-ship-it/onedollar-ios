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
assert(overlay.indexOf('function applySelectedProductColorMainImage') !== -1, 'color picker applies color photo');
assert(overlay.indexOf('function dedupeProductDraftImages') !== -1, 'dedupe overlapping product images');
assert(overlay.indexOf('function clearPendingProductColorImage') !== -1, 'clear pending color image');
assert(overlay.indexOf('LZ_FILE_COLOR_NO_OVERLAP_20260928') !== -1, 'no overlap marker');
assert(overlay.indexOf('applySelectedProductColorMainImage(draftImageIndex)') === -1, 'thumbnail click does not apply color photo');
assert(overlay.indexOf('applySelectedProductColorMainImage(productDraft.mainImageIndex)') === -1, 'main radio does not apply color photo');
assert(overlay.indexOf('productDraft.images[productColorMainImageIndex]') === -1, 'add color does not inherit previous main image');
assert(overlay.indexOf('data-product-draft-image') !== -1, 'draft image pick target');
assert(html.indexOf('admin-product-forwarder-cost-9.js?v=20260928-platform-1') !== -1, 'js cache bust');
assert(html.indexOf('admin-products-horizontal-9.css?v=20260928-platform-1') !== -1, 'css cache bust');
assert(html.indexOf('data-product-logistics-platform-list') !== -1, 'platform datalist');
assert(html.indexOf('空白可直接打入新增') !== -1, 'platform blank placeholder');
assert(html.indexOf('<select data-product-logistics-platform>') === -1, 'platform is not a locked select');
assert(overlay.indexOf('function rememberPurchasePlatformName') !== -1, 'remember typed platform');
assert(overlay.indexOf('LZ_PLATFORM_BLANK_20260928') !== -1, 'platform blank marker');
assert(overlay.indexOf('rememberPurchasePlatformName(platform)') !== -1, 'adding logistics remembers typed platform');
assert(html.indexOf('>新增顏色<') !== -1, 'add color button label');
assert(html.indexOf('顏色名稱') !== -1, 'chinese name label');
assert(html.indexOf('印尼文') !== -1, 'indonesian label');
assert(html.indexOf('6. 顏色尺碼矩陣') !== -1, 'matrix heading');
assert(css.indexOf('LZ_FILE_COLOR_SIZE_20260928') !== -1, 'css marker');
assert(css.indexOf('LZ_PLATFORM_BLANK_20260928') !== -1, 'css platform blank marker');

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

function sameProductDraftImage(a, b) {
  a = String(a || '');
  b = String(b || '');
  if (!a || !b) return false;
  if (a === b) return true;
  function payload(value) {
    value = String(value || '');
    var comma = value.indexOf(',');
    if (/^data:image\//i.test(value) && comma > -1) return 'data:' + value.slice(comma + 1);
    var clean = value.replace(/[?#].*$/, '');
    try {
      if (/^https?:\/\//i.test(clean) || clean.indexOf('//') === 0 || clean.charAt(0) === '.' || clean.charAt(0) === '/') {
        return new URL(clean, 'https://www.lingzanzan.com/').pathname;
      }
    } catch (error) {}
    var slash = clean.lastIndexOf('/');
    return slash >= 0 ? clean.slice(slash) : clean;
  }
  var left = payload(a);
  var right = payload(b);
  return !!left && left === right;
}

function dedupeImages(images, mainIndex) {
  var mainSrc = images[mainIndex] || images[0] || '';
  var unique = [];
  images.forEach(function (image) {
    if (!image) return;
    if (unique.some(function (have) { return sameProductDraftImage(have, image); })) return;
    unique.push(image);
  });
  var nextMain = 0;
  unique.forEach(function (image, index) {
    if (sameProductDraftImage(image, mainSrc)) nextMain = index;
  });
  return { images: unique, mainImageIndex: unique.length ? nextMain : 0 };
}

assert.strictEqual(sameProductDraftImage('https://www.lingzanzan.com/uploads/jeans.jpg', './uploads/jeans.jpg?v=2'), true);
assert.strictEqual(sameProductDraftImage('data:image/jpeg;base64,AAA', 'data:image/png;base64,AAA'), true);
assert.strictEqual(sameProductDraftImage('/uploads/a.jpg', '/uploads/b.jpg'), false);
var jeansDup = dedupeImages([
  'https://www.lingzanzan.com/uploads/jeans.jpg',
  './uploads/jeans.jpg?cache=1'
], 0);
assert.strictEqual(jeansDup.images.length, 1);
assert.strictEqual(jeansDup.mainImageIndex, 0);

console.log('test-file-color-size-1 ok');
