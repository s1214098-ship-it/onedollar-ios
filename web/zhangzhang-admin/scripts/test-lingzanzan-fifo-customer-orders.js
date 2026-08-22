#!/usr/bin/env node
"use strict";

const {
  fifoCustomerOrderStatusKey,
  fifoCustomerOrderStatusLabel,
  fifoCustomerOrderItemLine,
  fifoCustomerOrderOpenKind,
  fifoWorkbenchWhyHidden,
  fifoCollectCustomerOrders,
  fifoShouldAutoOpenCustomerOrders,
  fifoCustomerOrderIsHold,
  fifoCustomerOrderCanMerge,
  fifoCustomerOrderMergeIds,
  fifoCustomerOrderMergeButtonLabel,
  fifoCustomerOrderResolveMergeIds,
  fifoCustomerOrderMergeForbidden,
} = require("./lz-fifo-customer-orders");

function assert(cond, label) {
  if (!cond) throw new Error("fail: " + label);
  console.log("ok:", label);
}

const liveC160 = {
  id: "LIVE-20260726-7592A0",
  status: "confirming",
  statusLabel: "接單確認中",
  source: "live-order",
  customer: { name: "Ika Miyati(Icha Nyk Icha TK)", phone: "0916464082" },
  items: [{ code: "C160", title: "2026LO新款高個子長褲", color: "黑色(HITEM)", size: "M", qty: 1 }],
};

const liveSe166 = {
  id: "LIVE-20260726-4E3AC5",
  status: "confirming",
  source: "live-order",
  customer: { name: "Ika Miyati(Icha Nyk Icha TK)", phone: "0916464082" },
  items: [{ code: "SE166", title: "2026新款夏季套裝卡通(TK)", color: "粉紅(PINK)", size: "NO SIZE", qty: 1 }],
};

const cancelledInquiry = {
  id: "BYORDER-20260822-074B00",
  status: "cancelled",
  source: "stock-inquiry",
  customer: { name: "Ika Miyati(Icha Nyk Icha TK)", phone: "0916464082" },
  items: [
    { code: "C160", color: "黑色(HITEM)", size: "M", qty: 1 },
    { code: "SE166", color: "粉紅色", size: "NO SIZE", qty: 1 },
  ],
};

assert(fifoCustomerOrderStatusKey(liveC160) === "draft", "live confirming is draft");
assert(fifoCustomerOrderStatusLabel(liveC160) === "已打單／接單確認中", "live label");
assert(fifoCustomerOrderStatusLabel(cancelledInquiry) === "已取消", "cancelled label");
assert(fifoCustomerOrderItemLine(liveC160.items[0]).indexOf("C160") !== -1, "item has code");
assert(fifoCustomerOrderItemLine(liveC160.items[0]).indexOf("×1") !== -1, "item has qty");
assert(fifoCustomerOrderOpenKind(liveC160, [liveC160, liveSe166]) === "order", "live opens as order");
assert(fifoCustomerOrderOpenKind(cancelledInquiry, [liveC160, liveSe166]) === "inquiry", "byorder opens as inquiry");
assert(fifoWorkbenchWhyHidden(liveC160) === "live-draft-not-in-fifo-buckets", "why live missing from 待配貨");
assert(fifoWorkbenchWhyHidden(cancelledInquiry) === "cancelled-inquiry-not-in-order-returned", "why cancelled missing from 已取消");

const collected = fifoCollectCustomerOrders(
  [liveC160, liveSe166],
  [cancelledInquiry, liveC160],
  function (row) {
    return String((row.customer && row.customer.phone) || "") === "0916464082";
  }
);
assert(collected.length === 3, "dedupes live that exists in both lists");
assert(collected.some((row) => row.id === "LIVE-20260726-7592A0"), "keeps live draft");
assert(collected.some((row) => row.id === "BYORDER-20260822-074B00"), "keeps cancelled inquiry");

assert(fifoShouldAutoOpenCustomerOrders("0916464082", collected, "") === true, "auto-open first search");
assert(fifoShouldAutoOpenCustomerOrders("0916464082", collected, "0916464082") === false, "do not re-open same query");
assert(fifoShouldAutoOpenCustomerOrders("", collected, "") === false, "no popup without query");
assert(fifoShouldAutoOpenCustomerOrders("0916464082", [], "") === false, "no popup without rows");

const risaPlaced = {
  id: "BYORDER-20260822-C021E5",
  status: "accepted",
  deliveryState: "pending",
  customer: { name: "Risa(Sagittarius Ayin)", phone: "0984179286" },
  items: [{ code: "SE172", qty: 1 }],
};
const risaConfirming = {
  id: "BYORDER-20260819-86BB07",
  status: "confirming",
  customer: { name: "Risa(Sagittarius Ayin)", phone: "0984179286" },
  items: [{ code: "SE166", qty: 1 }],
};
const risaDelivered = {
  id: "BYORDER-20260817-27F843",
  status: "delivered",
  deliveryState: "delivered",
  customer: { name: "Risa(Sagittarius Ayin)", phone: "0984179286" },
  items: [{ code: "A", qty: 1 }, { code: "B", qty: 1 }],
};
const risaLive = {
  id: "LIVE-20260726-B99A87",
  status: "accepted",
  statusLabel: "已接單",
  deliveryState: "pending",
  source: "live-order",
  reservedShippingHoldReason: "wait_other",
  reservedShippingHoldReasonText: "等其他商品到齊",
  customer: { name: "Risa(Sagittarius Ayin)", phone: "0984179286" },
  items: [{ code: "SE178", qty: 1 }],
};
const risaHold = {
  id: "BYORDER-20260822-980B1D",
  status: "preorder_pending",
  reservedShippingHoldReason: "wait_other",
  customer: { name: "Risa(Sagittarius Ayin)", phone: "0984179286" },
  items: [{ code: "HOLD", qty: 1 }],
};
const risaRows = [risaPlaced, risaConfirming, risaDelivered, risaLive, risaHold];

assert(fifoCustomerOrderCanMerge(risaPlaced) === true, "accepted pending can merge");
assert(fifoCustomerOrderCanMerge(risaConfirming) === true, "confirming can merge");
assert(fifoCustomerOrderCanMerge(risaLive) === false, "已寄庫 live does not merge with 出貨");
assert(fifoCustomerOrderStatusKey(risaLive) === "hold", "accepted hold is 寄庫 not 已接單");
assert(fifoCustomerOrderStatusLabel(risaLive) === "已寄庫", "accepted hold label 已寄庫");
assert(fifoCustomerOrderStatusKey(risaHold) === "hold", "inquiry hold is 寄庫");
assert(fifoCustomerOrderStatusLabel(risaHold) === "已寄庫", "inquiry hold label 已寄庫");
assert(fifoCustomerOrderStatusLabel({
  id: "Z",
  status: "confirming",
  reservedShippingHoldReason: "wait_live",
}) === "已寄庫", "接單確認中 hold becomes 已寄庫");
assert(fifoCustomerOrderStatusKey(risaPlaced) === "open", "non-hold accepted stays open");
assert(fifoCustomerOrderCanMerge(risaDelivered) === false, "delivered stays out of merge");
assert(fifoCustomerOrderIsHold(risaHold) === true, "wait_other is hold");
assert(fifoCustomerOrderCanMerge(risaHold) === false, "寄庫 stays out of 出貨 merge");
assert(fifoCustomerOrderCanMerge({ id: "X", status: "confirming", cancelled: true }) === false, "cancelled flag blocked");
assert(fifoCustomerOrderCanMerge({ id: "Y", status: "confirming", deliveryState: "arrived_store" }) === false, "arrived store blocked");

const mergeIds = fifoCustomerOrderMergeIds(risaRows);
assert(mergeIds.length === 2, "two unshipped 出貨單; 寄庫 stays out");
assert(mergeIds.indexOf("BYORDER-20260817-27F843") === -1, "delivered id excluded");
assert(mergeIds.indexOf("BYORDER-20260822-980B1D") === -1, "hold id excluded");
assert(mergeIds.indexOf("LIVE-20260726-B99A87") === -1, "accepted 寄庫 excluded from 出貨 merge");
assert(fifoCustomerOrderMergeButtonLabel(2) === "合併未出貨 2 張一起出", "button names 2 sheets");
assert(fifoCustomerOrderMergeButtonLabel(3) === "合併未出貨 3 張一起出", "button names 3 sheets");
assert(fifoCustomerOrderMergeButtonLabel(1) === "", "no button for a single sheet");
assert(fifoCustomerOrderMergeButtonLabel(0) === "", "no button without mergeable rows");
assert(fifoCustomerOrderMergeButtonLabel(2, "idle") === "請勾選要合併的單", "idle asks to pick");
assert(fifoCustomerOrderMergeButtonLabel(0, "idle") === "", "idle hidden without enough sheets");
assert(fifoCustomerOrderMergeButtonLabel(1, "picked") === "再勾選至少 1 張才能合併", "one picked still waits");
assert(fifoCustomerOrderMergeButtonLabel(2, "picked") === "合併已勾選 2 張一起出", "picked names 2 sheets");

const pickedOnlyBowl = fifoCustomerOrderMergeIds(risaRows, ["BYORDER-20260819-86BB07"]);
assert(pickedOnlyBowl.join(",") === "BYORDER-20260819-86BB07", "selected filter keeps one mergeable");
const pickedDeliveredIgnored = fifoCustomerOrderMergeIds(risaRows, ["BYORDER-20260817-27F843", "BYORDER-20260822-C021E5"]);
assert(pickedDeliveredIgnored.join(",") === "BYORDER-20260822-C021E5", "selected delivered stays out");
const pickedNone = fifoCustomerOrderMergeIds(risaRows, []);
assert(pickedNone.length === 0, "empty selection merges nothing");
const pickedHoldIgnored = fifoCustomerOrderMergeIds(risaRows, ["LIVE-20260726-B99A87", "BYORDER-20260822-C021E5"]);
assert(pickedHoldIgnored.join(",") === "BYORDER-20260822-C021E5", "selected 寄庫 stays out");

const resolved = fifoCustomerOrderResolveMergeIds(risaRows, [risaPlaced, risaConfirming, risaDelivered, risaLive]);
assert(resolved.resolved.join(",") === "BYORDER-20260822-C021E5,BYORDER-20260819-86BB07", "resolves only 出貨單 ids");
assert(resolved.skipped.length === 0, "no skipped when mergeable orders exist");

const inquiryOnly = {
  id: "BYORDER-UNCONVERTED",
  status: "draft",
  customer: { phone: "0984179286" },
};
const mixed = fifoCustomerOrderResolveMergeIds([risaPlaced, inquiryOnly], [risaPlaced]);
assert(mixed.resolved.join(",") === "BYORDER-20260822-C021E5", "keeps converted order");
assert(mixed.skipped.join(",") === "BYORDER-UNCONVERTED", "skips inquiry not yet a formal order");

assert(fifoCustomerOrderMergeForbidden(risaRows) === false, "Risa is allowed to merge");
assert(fifoCustomerOrderMergeForbidden([{ id: "BYORDER-20260720-078596", customer: { phone: "0911111111" } }]) === true, "blocked order id");
assert(fifoCustomerOrderMergeForbidden([{ id: "LIVE-X", customer: { phone: "16346546308" } }]) === true, "blocked phone");

console.log("LINGZANZAN fifo customer orders tests ok");
