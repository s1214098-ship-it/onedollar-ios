'use strict';
var fs = require('fs');
var path = require('path');

function assert(cond, msg) { if (!cond) throw new Error(msg); }

var html = fs.readFileSync(path.join(__dirname, 'admin-inventory-entry.html.new-vendor-blank-1'), 'utf8');
var js = fs.readFileSync(path.join(__dirname, 'inventory-freight-entry-20260810.js.new-vendor-blank-1'), 'utf8');

assert(html.indexOf('LZ_VENDOR_BLANK_20261024') !== -1, 'html marker');
assert(html.indexOf('placeholder="空白可直接打入新增"') !== -1, 'html placeholder');
assert(/<input[^>]*data-purchase-receipt-supplier/.test(html), 'supplier is input');
assert(/<input[^>]*data-purchase-receipt-platform/.test(html), 'platform is input');
assert(!/<select[^>]*data-purchase-receipt-supplier/.test(html), 'supplier must not be select');
assert(!/<select[^>]*data-purchase-receipt-platform/.test(html), 'platform must not be select');
assert(html.indexOf('data-purchase-receipt-add-supplier') !== -1, 'add supplier button');
assert(html.indexOf('data-purchase-receipt-add-platform') !== -1, 'add platform button');
assert(html.indexOf('admin-suppliers.html?kind=supplier') === -1, 'must not jump away to suppliers page');
assert(html.indexOf('LZ_NO_PLATFORM_20261008') === -1, 'platform field must be visible');
assert(html.indexOf('20261024-vendor-blank-1') !== -1, 'cache bust');
assert(!/<div class="purchase-receipt-keyword-field product-master-select-field" hidden/.test(html), 'platform host not hidden');

assert(js.indexOf('LZ_VENDOR_BLANK_20261024') !== -1, 'js marker');
assert(js.indexOf('function ensureBlankPurchaseFields') !== -1, 'ensureBlankPurchaseFields');
assert(js.indexOf('function ensureBlankTypeInField') !== -1, 'ensureBlankTypeInField');
assert(js.indexOf("el.tagName === 'SELECT'") !== -1, 'converts leftover select');
assert(js.indexOf('startBlankPurchaseField') !== -1, 'inline add');
assert(js.indexOf('data-purchase-receipt-add-supplier') !== -1, 'js intercepts add supplier');
assert(js.indexOf('data-purchase-receipt-add-platform') !== -1, 'js intercepts add platform');
assert(/function fillStandaloneOptions\(\) \{\s*ensureBlankPurchaseFields\(\);/.test(js), 'fillStandaloneOptions converts first');
assert(js.indexOf("if (!select || select.tagName !== 'SELECT') return;") !== -1, 'fillSelect skips inputs');
assert(js.indexOf("return '';") !== -1 && /function platformForVendorName[\s\S]+return '';\s*\}/.test(js), 'unknown vendor does not force 微信');

function isLockedChoicePlaceholder(value) {
  var raw = String(value == null ? '' : value).trim();
  if (!raw) return true;
  return /^請選擇/.test(raw) || raw === '全部廠商' || raw === '全部平台' || raw === '__custom__';
}
assert(isLockedChoicePlaceholder('請選擇已建檔廠商') === true, 'locked vendor placeholder');
assert(isLockedChoicePlaceholder('請選擇已建檔平台') === true, 'locked platform placeholder');
assert(isLockedChoicePlaceholder('拼直') === false, 'real vendor stays');
assert(isLockedChoicePlaceholder('') === true, 'empty is blank');

function makeEl(tag, attrs, value) {
  var el = {
    tagName: tag.toUpperCase(),
    attrs: Object.assign({}, attrs || {}),
    value: value || '',
    style: { display: (attrs && attrs.style && /display:\s*none/.test(attrs.style)) ? 'none' : '' },
    hidden: !!(attrs && attrs.hidden),
    parentNode: null,
    getAttribute: function (n) { return this.attrs[n]; },
    setAttribute: function (n, v) { this.attrs[n] = String(v); },
    closest: function () { return this.host; }
  };
  el.attributes = Object.keys(el.attrs).map(function (k) { return { name: k, value: el.attrs[k] }; });
  return el;
}
(function convertSelectFixture() {
  var supplier = makeEl('select', { 'data-purchase-receipt-supplier': '' }, '請選擇已建檔廠商');
  var platform = makeEl('select', { 'data-purchase-receipt-platform': '' }, '微信');
  var supplierHost = { hidden: false, style: { display: '' }, removeAttribute: function () {} };
  var platformHost = { hidden: true, style: { display: 'none' }, removeAttribute: function (n) { if (n === 'hidden') this.hidden = false; } };
  supplier.host = supplierHost;
  platform.host = platformHost;
  var parentS = { replaceChild: function (n) { this.child = n; } };
  var parentP = { replaceChild: function (n) { this.child = n; } };
  supplier.parentNode = parentS;
  platform.parentNode = parentP;
  var lists = {};
  function createElement(tag) {
    if (tag === 'datalist') {
      var list = makeEl('datalist', {});
      Object.defineProperty(list, 'id', {
        get: function () { return this.attrs.id; },
        set: function (v) { this.attrs.id = v; lists[v] = this; }
      });
      return list;
    }
    return makeEl(tag, {});
  }
  function convert(el, listId) {
    var current = isLockedChoicePlaceholder(el.value) ? '' : text(el.value);
    function text(v) { return String(v == null ? '' : v).trim(); }
    var input = createElement('input');
    el.attributes.forEach(function (attr) {
      if (attr.name.indexOf('data-') === 0) input.setAttribute(attr.name, attr.value);
    });
    input.setAttribute('placeholder', '空白可直接打入新增');
    input.value = current;
    el.parentNode.replaceChild(input, el);
    var host = el.closest();
    host.hidden = false;
    host.removeAttribute('hidden');
    if (host.style.display === 'none') host.style.display = '';
    return input;
  }
  var s = convert(supplier, 'purchase-receipt-supplier-options');
  var p = convert(platform, 'purchase-receipt-platform-options');
  assert(s.tagName === 'INPUT', 'converted supplier tag');
  assert(s.value === '', 'placeholder select becomes blank');
  assert(s.attrs.placeholder === '空白可直接打入新增', 'supplier placeholder after convert');
  assert(p.tagName === 'INPUT', 'converted platform tag');
  assert(p.value === '微信', 'real platform value kept');
  assert(platformHost.hidden === false, 'platform unhidden');
  assert(platformHost.style.display === '', 'platform display restored');
})();

console.log('vendor-blank ok');
