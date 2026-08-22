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

console.log("LINGZANZAN fifo customer orders tests ok");
