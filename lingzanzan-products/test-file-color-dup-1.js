#!/usr/bin/env node
var fs = require('fs');
var path = require('path');
var assert = require('assert');

var dir = __dirname;
var overlay = fs.readFileSync(path.join(dir, 'admin-product-forwarder-cost-9.js.new-file-dup-1'), 'utf8');
var paste = fs.readFileSync(path.join(dir, 'image-upload-paste.js.new-file-dup-1'), 'utf8');
var html = fs.readFileSync(path.join(dir, 'admin-products.html.new-file-dup-1'), 'utf8');

assert(overlay.indexOf('LZ_FILE_COLOR_DUP_20260928') !== -1, 'overlay marker');
assert(overlay.indexOf('function stripProductColorCodePrefix') !== -1, 'strip prefix helper');
assert(overlay.indexOf('function sameProductDraftImage') !== -1, 'image dedupe helper');
assert(overlay.indexOf('function dropUntouchedDefaultProductColor') !== -1, 'drop default black');
assert(overlay.indexOf("'淺藍色': '911'") !== -1, '淺藍色 alias 911');
assert(overlay.indexOf('normalized.indexOf(name) > -1') === -1, 'old substring lookup must be gone');
assert(overlay.indexOf('event.defaultPrevented') !== -1, 'paste respects defaultPrevented');
assert(paste.indexOf('LZ_FILE_COLOR_DUP_20260928') !== -1, 'paste marker');
assert(paste.indexOf("closest('.product-image-card, [data-product-paste-image]')") !== -1, 'paste skips product card');
assert(html.indexOf('admin-product-forwarder-cost-9.js?v=20260928-file-dup-1') !== -1, 'js cache bust');
assert(html.indexOf('image-upload-paste.js?v=20260928-file-dup-1') !== -1, 'paste cache bust');
assert((html.match(/data-no-auto-paste/g) || []).length >= 4, 'file inputs skip auto paste');

function stripProductColorCodePrefix(value) {
  return String(value || '').replace(/^(?:\d{2,4}|N\d{3})\s+/, '').trim();
}

function canonicalChineseColorName(value) {
  var text = String(value || '').trim();
  text = stripProductColorCodePrefix(text);
  return ({
    '淺藍': '淺藍色', '淺藍色': '淺藍色',
    '深藍': '深藍色', '深藍色': '深藍色'
  })[text] || text || '未設定';
}

var aliases = {
  '藍色': '96', '藍': '96',
  '深藍': '906', '深藍色': '906',
  '淺藍': '911', '淺藍色': '911'
};

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
  var storedCode = String(color.code || '');
  return { name: name, code: storedCode || inventoryCode || '' };
}

function dedupe(colors) {
  var seenName = {};
  var next = [];
  colors.forEach(function (item) {
    var ident = productColorIdentity(item);
    if (seenName[ident.name] != null) {
      var keep = next[seenName[ident.name]];
      if (!keep.image && item.image) keep.image = item.image;
      return;
    }
    seenName[ident.name] = next.length;
    next.push({ name: ident.name, code: ident.code, image: item.image || '' });
  });
  return next;
}

function uniqueImages(existing, incoming) {
  return incoming.filter(function (image) {
    return existing.indexOf(image) === -1;
  });
}

assert.strictEqual(canonicalChineseColorName('96 淺藍色'), '淺藍色');
assert.strictEqual(canonicalChineseColorName('933 深藍色'), '深藍色');
assert.strictEqual(canonicalChineseColorName('深藍'), '深藍色');
assert.strictEqual(legacyColorCodeFromName('淺藍色'), '911');
assert.strictEqual(legacyColorCodeFromName('淺藍'), '911');
assert.notStrictEqual(legacyColorCodeFromName('淺藍色'), '96');
assert.strictEqual(legacyColorCodeFromName('藍色'), '96');
assert.strictEqual(legacyColorCodeFromName('深藍色'), '906');
assert.strictEqual(productColorIdentity({ name: '淺藍色' }).name, '淺藍色');
assert.strictEqual(productColorIdentity({ name: '淺藍色' }).code, '911');
assert.strictEqual(productColorIdentity({ name: '96 淺藍色', code: '96' }).name, '淺藍色');

var merged = dedupe([
  { name: '淺藍色', code: '96', image: 'a' },
  { name: '深藍色', code: '933', image: 'b' },
  { name: '深藍', code: '933', image: '' },
  { name: '933 深藍色', code: '933', image: '' }
]);
assert.strictEqual(merged.length, 2, 'two filed colors stay two');
assert.strictEqual(merged[0].name, '淺藍色');
assert.strictEqual(merged[1].name, '深藍色');
assert.strictEqual(merged[1].image, 'b');

var photos = uniqueImages(['data:image/png;base64,AAA'], ['data:image/png;base64,AAA', 'data:image/png;base64,AAA']);
assert.strictEqual(photos.length, 0, 'same paste is not added again');

console.log('ok file-color-dup-1');
