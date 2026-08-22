#!/usr/bin/env node
"use strict";

/**
 * 寄庫與出貨分開：加入寄庫名單才寄庫；黃色確認鈕維持出貨。
 * 同客人寄庫仍可合併。不 stamp HTML。
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const JS_MARKER = "data-freight-fifo-confirm') && reservation.isHold";

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

const LABEL_OLD = `      if (carrierHandoffInput && carrierHandoffInput.checked) {
        var holdDuringHandoff = freightFifoReservationPayload(modal, String(bundleDecision && bundleDecision.value || ''));
        if (!holdDuringHandoff.isHold) {
          formalSubmitButton.textContent = '儲存並轉配送中';
          return;
        }
      }
      var decision = String(bundleDecision && bundleDecision.value || '');
      var reservationNow = freightFifoReservationPayload(modal, decision);
      if (reservationNow.isHold) {
        formalSubmitButton.textContent = order || taiwanStockAllocationReady || Math.max(0, Number(modal.getAttribute('data-actual-received-qty') || 0)) > 0
          ? '轉正式並寄庫'
          : '列入寄庫名單';
        return;
      }
      var shipWarehouses = freightFifoSelectedShipWarehouses(modal, row);`;

const LABEL_NEW = `      if (carrierHandoffInput && carrierHandoffInput.checked) {
        formalSubmitButton.textContent = '儲存並轉配送中';
        return;
      }
      var decision = String(bundleDecision && bundleDecision.value || '');
      var shipWarehouses = freightFifoSelectedShipWarehouses(modal, row);`;

const CONFIRM_OLD = `    if (reservation.isHold) {
      dispatchDecision = 'wait_all';
      if (decisionField && decisionField.value !== 'ask_sales') decisionField.value = 'wait_all';
    }`;

const CONFIRM_NEW = `    if (button && button.hasAttribute('data-freight-fifo-confirm') && reservation.isHold) {
      reservation = Object.assign({}, reservation, { isHold: false, holdReason: '', holdReasonText: '', error: '' });
    }
    if (reservation.isHold) {
      dispatchDecision = 'wait_all';
      if (decisionField && decisionField.value !== 'ask_sales') decisionField.value = 'wait_all';
    }`;

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(ADMIN_JS, "keep-ship-with-hold"));
let js = fs.readFileSync(ADMIN_JS, "utf8");
if (js.indexOf(JS_MARKER) !== -1) {
  console.log("js already patched");
} else {
  js = replaceOnce(js, LABEL_OLD, LABEL_NEW, "confirm label stays 出貨");
  js = replaceOnce(js, CONFIRM_OLD, CONFIRM_NEW, "confirm click ships instead of 寄庫");
}
if (js.indexOf(JS_MARKER) === -1) throw new Error("confirm ignores hold missing");
if (js.indexOf("data-freight-fifo-hold-save") === -1) throw new Error("寄庫 button missing");
if (js.indexOf("data-freight-fifo-confirm") === -1) throw new Error("出貨 button missing");
if (js.indexOf("開啟出貨單（可加商品）") === -1) throw new Error("list 出貨 button missing");
fs.writeFileSync(ADMIN_JS, js, "utf8");
console.log("js written", ADMIN_JS, "len", js.length);
console.log("LINGZANZAN keep ship with 寄庫 ok");
console.log("no html stamp");
