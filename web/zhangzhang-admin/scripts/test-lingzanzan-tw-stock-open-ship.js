#!/usr/bin/env node
"use strict";

const {
  twStockNoticeShipOrderId,
  twStockNoticeOpenShipButtonHtml,
  twStockNoticeCloseLabel,
  twStockNoticeCloseToast,
  twStockNoticeShouldOpenWorkbench,
  twStockNoticePatched,
} = require("./lz-tw-stock-open-ship");

function assert(cond, label) {
  if (!cond) throw new Error("fail: " + label);
  console.log("ok:", label);
}

assert(
  twStockNoticeShipOrderId({ order: { id: "LIVE-20260822-E7C0FD" } }, { id: "BYORDER-20260822-E7C0FD" }) === "LIVE-20260822-E7C0FD",
  "prefers converted formal order id"
);
assert(
  twStockNoticeShipOrderId(
    { masterOrderId: "LIVE-20260822-MERGED", order: { id: "LIVE-20260822-E7C0FD" } },
    { id: "BYORDER-20260822-E7C0FD" }
  ) === "LIVE-20260822-MERGED",
  "prefers merge master order id"
);
assert(
  twStockNoticeShipOrderId({}, { id: "BYORDER-20260822-E7C0FD", convertedOrderId: "LIVE-20260822-AA" }) === "LIVE-20260822-AA",
  "falls back to row convertedOrderId"
);
assert(twStockNoticeShouldOpenWorkbench("LIVE-1") === true, "has order id can open workbench");
assert(twStockNoticeShouldOpenWorkbench("") === false, "empty id does not open workbench");

const html = twStockNoticeOpenShipButtonHtml("LIVE-20260822-E7C0FD", function (value) {
  return String(value).replace(/"/g, "&quot;");
});
assert(html.indexOf("data-preorder-tw-stock-open-ship=\"LIVE-20260822-E7C0FD\"") !== -1, "button carries formal order id");
assert(html.indexOf("開啟出貨單／編輯／出貨") !== -1, "button label is open/edit/ship");
assert(html.indexOf('data-similar-ship-ok="1"') !== -1, "skips a second similar-ship stop");
assert(twStockNoticeOpenShipButtonHtml("") === "", "no button without order id");

assert(twStockNoticeCloseLabel(false) === "先不轉", "before convert close stays 先不轉");
assert(twStockNoticeCloseLabel(true) === "稍後再說", "after convert close is 稍後再說");
assert(twStockNoticeCloseToast(false).indexOf("尚未扣台灣倉庫存") !== -1, "before convert close warns not deducted");
assert(twStockNoticeCloseToast(true) === "已關閉台灣現貨提醒", "after convert close does not say still preorder");

const patched = [
  'data-preorder-tw-stock-open-ship="',
  "開啟出貨單／編輯／出貨",
  "openFreightFifoShipmentSafe('', shipOrderId, openShipButton);",
].join("\n");
assert(twStockNoticePatched(patched) === true, "detects live patch");
assert(twStockNoticePatched("完成／關閉") === false, "old complete/close only is not the patch");

console.log("LINGZANZAN tw stock open ship tests ok");
