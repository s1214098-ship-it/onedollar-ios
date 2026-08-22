#!/usr/bin/env node
"use strict";

/**
 * 一個月內同樣出貨提醒：不只物流集運。
 * 預購打單、直播打單、現貨／錫正打單送出貨單，以及預購轉正式出貨，都要先彈出來。
 *
 * Cache-bust: admin.js ?v=20260820-similar-ship-all-1
 * HTML stamp is latin1 (byte-preserving).
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const STAMP = "20260820-similar-ship-all-1";
const MARKER = "similarShipHold: true";

function backup(file, tag) {
  const dir = path.join(ROOT, "data", "audit");
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(
    dir,
    path.basename(file) + "." + tag + "-" + new Date().toISOString().replace(/[:.]/g, "-")
  );
  fs.copyFileSync(file, dest);
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

function stampHtml(dir) {
  const names = [
    "admin-orders.html",
    "admin-live.html",
    "admin-preorders.html",
    "admin-freight.html",
    "admin-reserved-shipping.html",
    "admin-order-tracking.html",
    "admin-preorder-waiting.html",
    "admin-preorder-demand.html",
    "admin.html",
  ];
  let n = 0;
  names.forEach((name) => {
    const file = path.join(dir, name);
    if (!fs.existsSync(file)) {
      console.log("skip missing", name);
      return;
    }
    const html = fs.readFileSync(file, "latin1");
    if (html.indexOf("admin.js") === -1) return;
    const next = html.replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP);
    if (next === html) return;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n += 1;
    console.log("stamped", name);
  });
  console.log("html stamped", n);
}

const SOURCE_OLD = `  function freightFifoSimilarSourceRow(id) {
    id = String(id || '');
    if (!id) return null;`;

const SOURCE_NEW = `  function freightFifoSimilarSourceRow(id) {
    if (id && typeof id === 'object') return id;
    id = String(id || '');
    if (!id) return null;`;

const SHIP_OLD = `    if (!confirmCustomerDualAddressChoice(form)) return Promise.resolve();
    if (!name) name = purposeInfo.customerName;
    if (!phone) phone = purposeInfo.customerPhone;
    var delivery = form.querySelector('[data-admin-shipment-delivery]');`;

const SHIP_NEW = `    if (!confirmCustomerDualAddressChoice(form)) return Promise.resolve();
    if (!name) name = purposeInfo.customerName;
    if (!phone) phone = purposeInfo.customerPhone;
    if (!nonSalesPurpose && freightFifoGuardSimilarShip({
      customer: { name: name, phone: phone },
      customerName: name,
      customerPhone: phone,
      items: adminShipmentItems
    }, form.querySelector('[type="submit"]'), function () {
      submitAdminShipmentOrder(form);
    })) return Promise.resolve();
    var delivery = form.querySelector('[data-admin-shipment-delivery]');`;

const LIVE_OLD = `      if (!customer.name || !customer.phone) throw new Error('客戶姓名與電話必填');
      if (adminExistingOrderAppendContext && adminExistingOrderAppendContext.id) {`;

const LIVE_NEW = `      if (!customer.name || !customer.phone) throw new Error('客戶姓名與電話必填');
      if (!isDraft && freightFifoGuardSimilarShip({
        id: adminExistingOrderAppendContext && adminExistingOrderAppendContext.id || '',
        customer: customer,
        customerName: customer.name,
        customerPhone: customer.phone,
        items: items
      }, document.querySelector('[data-admin-live-submit]'), function () {
        submitAdminLiveOrder(isDraft);
      })) return Promise.reject({ similarShipHold: true });
      if (adminExistingOrderAppendContext && adminExistingOrderAppendContext.id) {`;

const LIVE_CATCH_OLD = `    }).catch(function (error) {
      toast(error.message || '直播訂單建立失敗');
    });
  }

  function bindLiveOrders() {`;

const LIVE_CATCH_NEW = `    }).catch(function (error) {
      if (error && error.similarShipHold) return;
      toast(error.message || '直播訂單建立失敗');
    });
  }

  function bindLiveOrders() {`;

const PREORDER_OLD = `    var shipping = readCustomerShippingAddressFields(form);
    if (manualPreorderEditId) {`;

const PREORDER_NEW = `    var shipping = readCustomerShippingAddressFields(form);
    var similarPhone = String((shipping && shipping.phone) || (form.querySelector('[name="phone"], [name="customerPhone"], [data-manual-customer-phone]') || {}).value || '').trim();
    if (freightFifoGuardSimilarShip({
      id: manualPreorderEditId || '',
      customer: { name: customerName, phone: similarPhone },
      customerName: customerName,
      customerPhone: similarPhone,
      items: manualPreorderItems
    }, form.querySelector('.manual-preorder-submit'), function () {
      submitManualPreorder(form);
    })) return Promise.resolve();
    if (manualPreorderEditId) {`;

const CONVERT_OLD = `        if (!window.confirm(confirmText)) return;
        convertPreorderButton.disabled = true;`;

const CONVERT_NEW = `        if (freightFifoGuardSimilarShip(preorderId, convertPreorderButton, function () {
          convertPreorderButton.click();
        })) return;
        if (!window.confirm(confirmText)) return;
        convertPreorderButton.disabled = true;`;

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(ADMIN_JS, "similar-ship-all"));
let js = fs.readFileSync(ADMIN_JS, "utf8");
if (js.indexOf("function freightFifoGuardSimilarShip(") === -1) {
  throw new Error("similar-ship helpers missing; run fix-lingzanzan-similar-ship-month.js first");
}
if (js.indexOf(MARKER) !== -1 && js.indexOf("submitAdminShipmentOrder(form);") !== -1 && js.indexOf("freightFifoGuardSimilarShip(preorderId, convertPreorderButton") !== -1) {
  console.log("js already patched");
} else {
  js = replaceOnce(js, SOURCE_OLD, SOURCE_NEW, "similar source accepts draft");
  js = replaceOnce(js, SHIP_OLD, SHIP_NEW, "現貨／錫正打單");
  js = replaceOnce(js, LIVE_OLD, LIVE_NEW, "直播打單");
  js = replaceOnce(js, LIVE_CATCH_OLD, LIVE_CATCH_NEW, "直播 hold 不誤報失敗");
  js = replaceOnce(js, PREORDER_OLD, PREORDER_NEW, "預購打單");
  js = replaceOnce(js, CONVERT_OLD, CONVERT_NEW, "預購轉正式出貨");
}
if (js.indexOf(MARKER) === -1) throw new Error("live hold marker missing");
if (js.indexOf("submitAdminShipmentOrder(form);") === -1) throw new Error("shipment hook missing");
if (js.indexOf("freightFifoGuardSimilarShip(preorderId, convertPreorderButton") === -1) {
  throw new Error("convert hook missing");
}
fs.writeFileSync(ADMIN_JS, js, "utf8");
console.log("js written", ADMIN_JS, "len", js.length);
stampHtml(ROOT);
console.log("LINGZANZAN similar-ship all entry ok", STAMP);
