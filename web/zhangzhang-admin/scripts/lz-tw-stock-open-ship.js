"use strict";

function twStockNoticeShipOrderId(outcome, row) {
  outcome = outcome || {};
  var order = outcome.order || {};
  return String(
    outcome.masterOrderId
      || order.masterOrderId
      || order.id
      || (row && (row.convertedOrderId || row.formalOrderId || row.orderId || row.id))
      || ""
  ).trim();
}

function twStockNoticeOpenShipButtonHtml(orderId, escapeHtml) {
  var id = String(orderId || "").trim();
  if (!id) return "";
  var safe = typeof escapeHtml === "function"
    ? escapeHtml(id)
    : String(id).replace(/[&<>"']/g, "");
  return '<button type="button" class="primary-button" data-similar-ship-ok="1" data-preorder-tw-stock-open-ship="'
    + safe
    + '">開啟出貨單／編輯／出貨</button>';
}

function twStockNoticeCloseLabel(converted) {
  return converted ? "稍後再說" : "先不轉";
}

function twStockNoticeCloseToast(converted) {
  return converted ? "已關閉台灣現貨提醒" : "已保留為預購單，尚未扣台灣倉庫存";
}

function twStockNoticeShouldOpenWorkbench(orderId) {
  return !!String(orderId || "").trim();
}

function twStockNoticePatched(adminJs) {
  var src = String(adminJs || "");
  return src.indexOf("data-preorder-tw-stock-open-ship") !== -1
    && src.indexOf("開啟出貨單／編輯／出貨") !== -1
    && src.indexOf("openFreightFifoShipmentSafe('', shipOrderId") !== -1;
}

module.exports = {
  twStockNoticeShipOrderId,
  twStockNoticeOpenShipButtonHtml,
  twStockNoticeCloseLabel,
  twStockNoticeCloseToast,
  twStockNoticeShouldOpenWorkbench,
  twStockNoticePatched,
};
