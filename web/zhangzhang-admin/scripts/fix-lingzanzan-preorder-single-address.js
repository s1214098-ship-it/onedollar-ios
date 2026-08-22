#!/usr/bin/env node
"use strict";

/**
 * 預購打單只記這次出貨地址：選超商就只存超商，不另建住家第二地址。
 * 不 stamp HTML。
 */

const fs = require("fs");
const path = require("path");
const {
  preorderAdminSavesSingleAddress,
  preorderHelperWatchesSingleField,
} = require("./lz-preorder-single-address");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const HELPER_JS = path.join(ROOT, "assets", "address-helper.js");

function backup(file, tag) {
  const dir = path.join(ROOT, "data", "audit");
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(
    dir,
    path.basename(file) + "." + tag + "-" + new Date().toISOString().replace(/[:.]/g, "-")
  );
  if (fs.existsSync(file)) fs.copyFileSync(file, dest);
  return dest;
}

function replaceOnce(src, oldStr, newStr, label) {
  if (src.indexOf(newStr) !== -1 && src.indexOf(oldStr) === -1) {
    console.log("already:", label);
    return src;
  }
  let from = oldStr;
  let to = newStr;
  let i = src.indexOf(from);
  if (i < 0) {
    from = oldStr.replace(/\n/g, "\r\n");
    to = newStr.replace(/\n/g, "\r\n");
    i = src.indexOf(from);
  }
  if (i < 0) throw new Error("missing snippet: " + label);
  if (src.indexOf(from, i + from.length) !== -1) throw new Error("not unique: " + label);
  console.log("patched:", label);
  return src.slice(0, i) + to + src.slice(i + from.length);
}

const FN_OLD = `      deliveryLabel: primary === 'store' ? '超商取貨' : '住家宅配'
    };
  }

  function readCustomerShippingAddressFields(root) {`;

const FN_NEW = `      deliveryLabel: primary === 'store' ? '超商取貨' : '住家宅配'
    };
  }

  function preorderKeepSingleShippingAddress(shipping, deliveryType) {
    shipping = shipping || {};
    var store = String(shipping.storeAddress || '').trim();
    var home = String(shipping.homeAddress || '').trim();
    var address = String(shipping.address || '').trim();
    var primary = String(shipping.addressPrimary || '').trim();
    var delivery = String(deliveryType || shipping.deliveryType || shipping.deliveryLabel || '').toLowerCase();
    var looksStore = function (text) {
      return /7\\s*-?\\s*11|711|全家|family|familymart|超商|店到店|minimarket/i.test(String(text || ''));
    };
    var storeNo = function (text) {
      var match = String(text || '').match(/(\\d{4,6})/);
      return match ? match[1] : '';
    };
    if (store && home && ((storeNo(store) && storeNo(store) === storeNo(home)) || looksStore(home))) {
      home = '';
      primary = 'store';
    }
    if (!primary) {
      if (/store|超商|7-11|全家/.test(delivery) || looksStore(store || address)) primary = 'store';
      else if (/home|住家|宅配|郵局|post|hct|ktj|大榮/.test(delivery)) primary = 'home';
      else primary = store && !home ? 'store' : 'home';
    }
    if (primary === 'store') {
      store = store || (looksStore(address) ? address : store);
      home = '';
      address = store || address;
    } else {
      home = home || (!looksStore(address) ? address : home);
      store = '';
      address = home || address;
    }
    return {
      storeAddress: store,
      homeAddress: home,
      address: address,
      addressPrimary: primary,
      deliveryType: primary === 'store' ? 'store' : 'home',
      deliveryLabel: primary === 'store' ? '超商取貨' : '住家宅配'
    };
  }

  function ensureManualPreorderSingleAddress() {
    var form = document.querySelector('[data-manual-preorder-form]');
    if (!form) return;
    var dual = form.querySelector('.customer-shipping-addresses');
    var delivery = String((form.querySelector('[data-manual-preorder-delivery], [name="deliveryType"]') || {}).value || '');
    var shipping = preorderKeepSingleShippingAddress(readCustomerShippingAddressFields(form), delivery);
    var visible = form.querySelector('input[data-manual-customer-address]:not([type="hidden"]), textarea[data-manual-customer-address]:not([type="hidden"])');
    if (dual && visible && !dual.contains(visible)) {
      visible.value = shipping.address;
      if (dual.parentNode) dual.parentNode.removeChild(dual);
      return;
    }
    if (!dual) return;
    var input = document.createElement('input');
    input.name = 'address';
    input.setAttribute('data-manual-customer-address', '');
    input.placeholder = '預購可稍後補；出貨前再確認地址或門市';
    input.value = shipping.address || '';
    var label = document.createElement('label');
    label.className = 'preorder-field preorder-field-address';
    label.appendChild(document.createTextNode('收件地址 / 超商資料'));
    label.appendChild(input);
    if (dual.parentNode) dual.parentNode.replaceChild(label, dual);
  }

  function readCustomerShippingAddressFields(root) {`;

const COLLECT_OLD = `    var shipping = readCustomerShippingAddressFields(root);
    return {
      name: String(fields.name && fields.name.value || '').trim(),`;

const COLLECT_NEW = `    var shipping = readCustomerShippingAddressFields(root);
    if (root && root.matches && root.matches('[data-manual-preorder-form]')) {
      shipping = preorderKeepSingleShippingAddress(shipping, String(fields.delivery && fields.delivery.value || ''));
    }
    return {
      name: String(fields.name && fields.name.value || '').trim(),`;

const SUBMIT_OLD = `    var shipping = readCustomerShippingAddressFields(form);
    var similarPhone = String((shipping && shipping.phone) || (form.querySelector('[name="phone"], [name="customerPhone"], [data-manual-customer-phone]') || {}).value || '').trim();`;

const SUBMIT_NEW = `    var shipping = readCustomerShippingAddressFields(form);
    shipping = preorderKeepSingleShippingAddress(shipping, String((form.querySelector('[data-manual-preorder-delivery], [name="deliveryType"]') || {}).value || ''));
    if (form.elements && form.elements.address) form.elements.address.value = shipping.address || '';
    var similarPhone = String((shipping && shipping.phone) || (form.querySelector('[name="phone"], [name="customerPhone"], [data-manual-customer-phone]') || {}).value || '').trim();`;

const INSTALL_OLD = `  function installAdminOrderCustomerDraftLink() {
    if (!document.querySelector('[data-order-customer-save-bar]')) return;`;

const INSTALL_NEW = `  function installAdminOrderCustomerDraftLink() {
    if (!document.querySelector('[data-order-customer-save-bar]')) return;
    ensureManualPreorderSingleAddress();`;

const HELPER_OLD = `      : source === 'preorder'
        ? '[data-manual-customer-address], [data-manual-preorder-form] input[name="address"], [data-customer-store-address], [data-customer-home-address]'`;

const HELPER_NEW = `      : source === 'preorder'
        ? '[data-manual-preorder-form] [data-manual-customer-address], [data-manual-preorder-form] input[name="address"]'`;

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(ADMIN_JS, "preorder-single-address"));
let js = fs.readFileSync(ADMIN_JS, "utf8");
if (preorderAdminSavesSingleAddress(js)) {
  console.log("js already patched");
} else {
  js = replaceOnce(js, FN_OLD, FN_NEW, "preorder keep-single helpers");
  js = replaceOnce(js, COLLECT_OLD, COLLECT_NEW, "customer draft saves one address");
  js = replaceOnce(js, SUBMIT_OLD, SUBMIT_NEW, "preorder submit saves one address");
  js = replaceOnce(js, INSTALL_OLD, INSTALL_NEW, "collapse dual address on preorder form");
}
if (!preorderAdminSavesSingleAddress(js)) throw new Error("preorder single address missing");
fs.writeFileSync(ADMIN_JS, js, "utf8");
console.log("js written", ADMIN_JS, "len", js.length);

if (fs.existsSync(HELPER_JS)) {
  console.log("backup helper", backup(HELPER_JS, "preorder-single-address"));
  let helper = fs.readFileSync(HELPER_JS, "utf8");
  if (preorderHelperWatchesSingleField(helper)) {
    console.log("helper already patched");
  } else {
    helper = replaceOnce(helper, HELPER_OLD, HELPER_NEW, "helper watches only preorder address");
  }
  if (!preorderHelperWatchesSingleField(helper)) throw new Error("helper preorder selector missing");
  fs.writeFileSync(HELPER_JS, helper, "utf8");
  console.log("helper written", HELPER_JS, "len", helper.length);
}

console.log("LINGZANZAN preorder single address ok");
console.log("no html stamp");
