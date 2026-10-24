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

console.log('vendor-blank ok');
