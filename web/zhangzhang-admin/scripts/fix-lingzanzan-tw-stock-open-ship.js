#!/usr/bin/env node
"use strict";

/**
 * 台灣現貨提醒轉正式後，不要只留「完成／關閉」。
 * 畫面上直接給「開啟出貨單／編輯／出貨」，並自動打開 FIFO 出貨單。
 * 不 stamp HTML。
 */

const fs = require("fs");
const path = require("path");
const { twStockNoticePatched } = require("./lz-tw-stock-open-ship");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");

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

const CONFIRM_LABEL_OLD = `data-preorder-tw-stock-confirm>確認配貨並轉現貨出貨</button>`;
const CONFIRM_LABEL_NEW = `data-preorder-tw-stock-confirm>確認配貨並開啟出貨單</button>`;

const MERGE_OLD = `        if (mergeIds.length < 2) return { order: order, merged: 0, warning: '' };
        return callOrderAdmin({ action: 'merge-shipment', orderIds: mergeIds }).then(function (mergeData) {
          return { order: order, merged: Math.max(0, Number(mergeData && mergeData.orderCount || mergeIds.length) - 1), warning: '' };
        }).catch(function (error) {
          return { order: order, merged: 0, warning: '現貨單已建立，但舊寄庫單尚未合併：' + (error.message || error) };
        });`;

const MERGE_NEW = `        if (mergeIds.length < 2) return { order: order, merged: 0, warning: '' };
        return callOrderAdmin({ action: 'merge-shipment', orderIds: mergeIds }).then(function (mergeData) {
          var masterId = String((mergeData && (mergeData.masterOrderId || (mergeData.order && mergeData.order.id))) || order.id || '');
          if (masterId) order.id = masterId;
          return { order: order, merged: Math.max(0, Number(mergeData && mergeData.orderCount || mergeIds.length) - 1), warning: '' };
        }).catch(function (error) {
          return { order: order, merged: 0, warning: '現貨單已建立，但舊寄庫單尚未合併：' + (error.message || error) };
        });`;

const CLICK_OLD = `      var close = event.target.closest ? event.target.closest('[data-preorder-tw-stock-close]') : null;
      var confirmButton = event.target.closest ? event.target.closest('[data-preorder-tw-stock-confirm]') : null;
      if (close) {
        modal.remove();
        toast('已保留為預購單，尚未扣台灣倉庫存');
        return;
      }
      if (!confirmButton) return;`;

const CLICK_NEW = `      var close = event.target.closest ? event.target.closest('[data-preorder-tw-stock-close]') : null;
      var openShipButton = event.target.closest ? event.target.closest('[data-preorder-tw-stock-open-ship]') : null;
      var confirmButton = event.target.closest ? event.target.closest('[data-preorder-tw-stock-confirm]') : null;
      if (close) {
        var alreadyConverted = !!modal.querySelector('[data-preorder-tw-stock-open-ship]');
        modal.remove();
        toast(alreadyConverted ? '已關閉台灣現貨提醒' : '已保留為預購單，尚未扣台灣倉庫存');
        return;
      }
      if (openShipButton) {
        event.preventDefault();
        event.stopPropagation();
        var shipOrderId = openShipButton.getAttribute('data-preorder-tw-stock-open-ship') || '';
        modal.remove();
        if (shipOrderId && typeof openFreightFifoShipmentSafe === 'function') {
          openFreightFifoShipmentSafe('', shipOrderId, openShipButton);
        }
        return;
      }
      if (!confirmButton) return;`;

const SUCCESS_OLD = `        confirmButton.remove();
        var closeButton = modal.querySelector('[data-preorder-tw-stock-close]');
        if (closeButton) closeButton.textContent = '完成／關閉';
        toast('台灣現貨已配貨並轉成正式現貨出貨單' + (outcome.merged ? '，原寄庫商品已一起合併' : ''));`;

const SUCCESS_NEW = `        confirmButton.remove();
        var closeButton = modal.querySelector('[data-preorder-tw-stock-close]');
        if (closeButton) closeButton.textContent = '稍後再說';
        var shipOrderId = String((outcome.order && (outcome.order.masterOrderId || outcome.order.id)) || outcome.masterOrderId || row.id || '');
        var actions = modal.querySelector('.freight-fifo-final-actions');
        if (actions && shipOrderId) {
          actions.innerHTML = '<button type="button" class="primary-button" data-similar-ship-ok="1" data-preorder-tw-stock-open-ship="' + escapeHtml(shipOrderId) + '">開啟出貨單／編輯／出貨</button>';
        }
        toast('台灣現貨已配貨並轉成正式現貨出貨單' + (outcome.merged ? '，原寄庫商品已一起合併' : '') + '，正在開啟出貨單');
        if (shipOrderId && typeof openFreightFifoShipmentSafe === 'function') {
          window.setTimeout(function () { openFreightFifoShipmentSafe('', shipOrderId); }, 40);
        }`;

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(ADMIN_JS, "tw-stock-open-ship"));
let js = fs.readFileSync(ADMIN_JS, "utf8");
if (twStockNoticePatched(js)) {
  console.log("js already patched");
} else {
  js = replaceOnce(js, CONFIRM_LABEL_OLD, CONFIRM_LABEL_NEW, "confirm opens shipment");
  js = replaceOnce(js, MERGE_OLD, MERGE_NEW, "keep merge master order id");
  js = replaceOnce(js, CLICK_OLD, CLICK_NEW, "open-ship click");
  js = replaceOnce(js, SUCCESS_OLD, SUCCESS_NEW, "success injects open-ship and auto-opens");
}
if (!twStockNoticePatched(js)) throw new Error("tw stock open-ship patch missing after write");
fs.writeFileSync(ADMIN_JS, js, "utf8");
console.log("js written", ADMIN_JS, "len", js.length);
console.log("LINGZANZAN tw stock open ship ok");
