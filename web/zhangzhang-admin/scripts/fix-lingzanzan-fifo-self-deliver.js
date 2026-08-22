#!/usr/bin/env node
"use strict";

/**
 * 自己送／自取不再進「正式出貨核對」卡收款。
 * 送出正式訂單時直接完成出貨；FIFO 第 4 步改「完成自己送」＋仍可開啟出貨單。
 * 不改收款、不把出貨鈕改成寄庫、不 stamp 全部 admin HTML。
 */

const fs = require("fs");
const path = require("path");
const {
  fifoSelfDeliverJsHasHelper,
  fifoSelfDeliverConfirmCompletes,
  fifoSelfDeliverBoardHasCompleteButton,
  fifoSelfDeliverSkipsPaymentCheck,
} = require("./lz-fifo-self-deliver");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const STAMP = "20260822-self-deliver-1";

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

function stampHtml(dir) {
  const names = ["admin-freight.html", "admin-reserved-shipping.html", "admin-order-tracking.html"];
  let n = 0;
  names.forEach(function (name) {
    const file = path.join(dir, name);
    if (!fs.existsSync(file)) return;
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

const HELPER_OLD = `  function adminShippingCarrierTrackingOptional(carrier) {
    var value = String(carrier || '').trim();
    return /印尼業務自行出貨|自取|不需物流/i.test(value);
  }`;

const HELPER_NEW = `  function adminShippingCarrierTrackingOptional(carrier) {
    var value = String(carrier || '').trim();
    return /印尼業務自行出貨|自取|不需物流/i.test(value);
  }

  function adminOrderIsSelfDeliver(order, carrier) {
    var carrierText = String(carrier || (order && order.shippingCarrier) || '').trim();
    if (/印尼業務自行出貨/i.test(carrierText)) return false;
    var deliveryType = String(
      (order && order.deliveryType) ||
      (order && order.customer && order.customer.deliveryType) ||
      ''
    ).toLowerCase();
    if (deliveryType === 'self') return true;
    var blob = [
      carrierText,
      deliveryType,
      order && order.customer && order.customer.delivery
    ].map(function (value) { return String(value || ''); }).join(' ');
    return /自取|自己送|面交/.test(blob) || /不需物流/.test(carrierText);
  }`;

const CHECK_OLD = `      if (!trackingNo && trackingOptional) checks.push(adminTrackingOptionalLabel(carrier, order) || '不建物流單號');
      checks.push('核對收款方式');`;

const CHECK_NEW = `      if (!trackingNo && trackingOptional) checks.push(adminTrackingOptionalLabel(carrier, order) || '不建物流單號');
      if (!adminOrderIsSelfDeliver(order, carrier)) checks.push('核對收款方式');`;

const ACTIONS_OLD = `      var actionsHtml = (isMerged ? '<button type="button" class="primary-button" data-combined-shipment-card="' + escapeHtml(mergedGroupId) + '">開啟合併出貨單</button>' : '')
        + '<button type="button" class="' + (isMerged ? 'ghost-button' : 'primary-button') + '" data-freight-fifo-order="' + escapeHtml(order.id || '') + '">核對物流與收款</button>'`;

const ACTIONS_NEW = `      var selfDeliver = adminOrderIsSelfDeliver(order, carrier);
      var actionsHtml = (isMerged ? '<button type="button" class="primary-button" data-combined-shipment-card="' + escapeHtml(mergedGroupId) + '">開啟合併出貨單</button>' : '')
        + (selfDeliver && !isMerged
          ? '<button type="button" class="primary-button" data-freight-fifo-self-deliver-complete="' + escapeHtml(order.id || '') + '">完成自己送</button><button type="button" class="ghost-button" data-freight-fifo-order="' + escapeHtml(order.id || '') + '">開啟出貨單</button>'
          : '<button type="button" class="' + (isMerged ? 'ghost-button' : 'primary-button') + '" data-freight-fifo-order="' + escapeHtml(order.id || '') + '">核對物流與收款</button>')`;

const LABEL_OLD = `    var formalSubmitLabel = hasFormalOrder
      ? '儲存正式訂單'
      : (taiwanStockAllocationReady ? '扣台灣現貨並建立正式出貨單' : '送出正式訂單');`;

const LABEL_NEW = `    var formalSubmitLabel = adminOrderIsSelfDeliver(order, (order && order.shippingCarrier) || (row && row.shippingCarrier))
      ? '完成自己送／自取'
      : hasFormalOrder
      ? '儲存正式訂單'
      : (taiwanStockAllocationReady ? '扣台灣現貨並建立正式出貨單' : '送出正式訂單');`;

const ISIN_OLD = `    var isInTransit = reservation.isHold ? false : (alreadyInTransit || handoffConfirmed);`;

const ISIN_NEW = `    var isInTransit = reservation.isHold ? false : (alreadyInTransit || handoffConfirmed);
    var isSelfDeliver = reservation.isHold ? false : adminOrderIsSelfDeliver(currentOrder, carrier);`;

const SAVING_OLD = `      : (isInTransit ? '正在建立正式出貨單並設定配送中…' : '正在儲存準備出貨資料…');`;

const SAVING_NEW = `      : (isSelfDeliver ? '正在完成自己送／自取…' : (isInTransit ? '正在建立正式出貨單並設定配送中…' : '正在儲存準備出貨資料…'));`;

const STATUS_OLD = `        status: isInTransit ? 'shipped' : 'accepted',
        deliveryState: isInTransit ? 'in_transit' : 'pending',`;

const STATUS_NEW = `        status: isSelfDeliver ? 'delivered' : (isInTransit ? 'shipped' : 'accepted'),
        deliveryState: isSelfDeliver ? 'delivered' : (isInTransit ? 'in_transit' : 'pending'),
        trackingOptional: isSelfDeliver ? true : undefined,
        trackingOptionalReason: isSelfDeliver ? '自己送／自取不需物流單號' : undefined,`;

const NOTE_OLD = `          : isInTransit
          ? '管理者依物流驗收入庫 FIFO 順序完成出貨，已進入配送中'
          : (trackingNo ? '物流單號已建立，尚未交寄，維持準備出貨' : '管理者已建立正式出貨單，物流單號可日後補登'`;

const NOTE_NEW = `          : isSelfDeliver
          ? '管理者自己送／自取，完成出貨。不需物流單號，不需再正式出貨核對。'
          : isInTransit
          ? '管理者依物流驗收入庫 FIFO 順序完成出貨，已進入配送中'
          : (trackingNo ? '物流單號已建立，尚未交寄，維持準備出貨' : '管理者已建立正式出貨單，物流單號可日後補登'`;

const CLICK_OLD = `      var orderButton = event.target.closest ? event.target.closest('[data-freight-fifo-order]') : null;
      var customerOrdersOpen = event.target.closest ? event.target.closest('[data-freight-fifo-customer-orders-open]') : null;`;

const CLICK_NEW = `      var selfDeliverComplete = event.target.closest ? event.target.closest('[data-freight-fifo-self-deliver-complete]') : null;
      var orderButton = event.target.closest ? event.target.closest('[data-freight-fifo-order]') : null;
      var customerOrdersOpen = event.target.closest ? event.target.closest('[data-freight-fifo-customer-orders-open]') : null;`;

const CLICK_DO_OLD = `      if (orderButton) {
        event.preventDefault();
        event.stopPropagation();
        openFreightFifoShipmentSafe('', orderButton.getAttribute('data-freight-fifo-order') || '', orderButton);
      }`;

const CLICK_DO_NEW = `      if (selfDeliverComplete) {
        event.preventDefault();
        event.stopPropagation();
        completeFreightFifoSelfDeliver(selfDeliverComplete.getAttribute('data-freight-fifo-self-deliver-complete') || '', selfDeliverComplete);
        return;
      }
      if (orderButton) {
        event.preventDefault();
        event.stopPropagation();
        openFreightFifoShipmentSafe('', orderButton.getAttribute('data-freight-fifo-order') || '', orderButton);
      }`;

const COMPLETE_OLD = `  function applyFreightFifoCompleteOrder(order, progressNote) {`;

const COMPLETE_NEW = `  function completeFreightFifoSelfDeliver(orderId, button) {
    var order = (state.orders || []).find(function (row) { return String(row && row.id || '') === String(orderId || ''); });
    if (!order) { toast('找不到這張出貨單'); return; }
    if (!adminOrderIsSelfDeliver(order, order.shippingCarrier)) { toast('這張不是自己送／自取'); return; }
    if (button) button.disabled = true;
    applyFreightFifoCompleteOrder(Object.assign({}, order, {
      shippingCarrier: order.shippingCarrier || '自取／不需物流',
      trackingNo: ''
    }), '管理者自己送／自取，完成出貨。不需物流單號，不需再正式出貨核對。').then(function () {
      return refreshOrdersAndInquiries();
    }).then(function () {
      renderFreightCustomerShipmentQueue();
      toast('自己送已完成，不必再核對物流');
    }).catch(function (error) {
      if (button) button.disabled = false;
      toast(error && error.message ? error.message : '完成自己送失敗');
    });
  }

  function applyFreightFifoCompleteOrder(order, progressNote) {`;

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

backup(ADMIN_JS, "self-deliver");
let src = fs.readFileSync(ADMIN_JS, "utf8");
src = replaceOnce(src, HELPER_OLD, HELPER_NEW, "adminOrderIsSelfDeliver helper");
src = replaceOnce(src, CHECK_OLD, CHECK_NEW, "skip 核對收款方式 for self-deliver");
src = replaceOnce(src, ACTIONS_OLD, ACTIONS_NEW, "board 完成自己送 button");
src = replaceOnce(src, LABEL_OLD, LABEL_NEW, "modal submit label");
src = replaceOnce(src, ISIN_OLD, ISIN_NEW, "confirm isSelfDeliver flag");
src = replaceOnce(src, SAVING_OLD, SAVING_NEW, "confirm saving text");
src = replaceOnce(src, STATUS_OLD, STATUS_NEW, "confirm completes self-deliver");
src = replaceOnce(src, NOTE_OLD, NOTE_NEW, "confirm progress note");
src = replaceOnce(src, CLICK_OLD, CLICK_NEW, "click var selfDeliverComplete");
src = replaceOnce(src, CLICK_DO_OLD, CLICK_DO_NEW, "click completeFreightFifoSelfDeliver");
src = replaceOnce(src, COMPLETE_OLD, COMPLETE_NEW, "completeFreightFifoSelfDeliver fn");
fs.writeFileSync(ADMIN_JS, src);

if (!fifoSelfDeliverJsHasHelper(src)) throw new Error("helper missing after patch");
if (!fifoSelfDeliverConfirmCompletes(src)) throw new Error("confirm complete missing after patch");
if (!fifoSelfDeliverBoardHasCompleteButton(src)) throw new Error("board button missing after patch");
if (!fifoSelfDeliverSkipsPaymentCheck(src)) throw new Error("payment check skip missing after patch");
if (src.indexOf("function completeFreightFifoSelfDeliver(") === -1) throw new Error("complete fn missing");

stampHtml(ROOT);
console.log("LINGZANZAN fifo self-deliver ok", STAMP);
