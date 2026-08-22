#!/usr/bin/env node
"use strict";

const {
  fifoHoldCustomerKey,
  fifoHoldRowIsActiveHold,
  fifoHoldItemSummary,
  fifoCollectCustomerHoldRows,
  fifoHoldGroupByCustomer,
  fifoHoldReminderMessage,
  fifoShouldPopupHoldMerge,
  fifoHoldMergeIds,
  fifoHoldExpandVisibleWithSiblings,
  fifoHoldMergeButtonLabel,
} = require("./lz-fifo-hold-merge");

function assert(cond, label) {
  if (!cond) throw new Error("fail: " + label);
  console.log("ok:", label);
}

const ikaHoldC160 = {
  id: "BYORDER-A",
  status: "reserved",
  reservedShippingHoldReason: "wait_other",
  customer: { name: "Ika Miyati", phone: "0916464082" },
  items: [{ code: "C160", color: "黑色(HITEM)", size: "M", qty: 1 }],
};

const ikaHoldSe166 = {
  id: "LIVE-B",
  status: "confirming",
  reservedShippingHoldReason: "wait_live",
  customer: { name: "Ika Miyati", phone: "0916464082" },
  items: [{ code: "SE166", color: "粉紅色", size: "NO SIZE", qty: 1 }],
};

const ikaShipped = {
  id: "BYORDER-C",
  status: "delivered",
  reservedShippingHoldReason: "wait_other",
  customer: { phone: "0916464082" },
  items: [{ code: "OLD", qty: 1 }],
};

const otherHold = {
  id: "BYORDER-D",
  status: "reserved",
  reservedShippingHoldReason: "hospital",
  customer: { name: "Susi", phone: "0912804136" },
  items: [{ code: "K345", qty: 1 }],
};

const scheduled = {
  id: "BYORDER-E",
  status: "reserved",
  reservedShippingHoldReason: "scheduled_ship",
  reservedShippingDate: "2026-08-25",
  customer: { phone: "0916464082" },
};

assert(fifoHoldCustomerKey(ikaHoldC160) === "p:0916464082", "phone key");
assert(fifoHoldRowIsActiveHold(ikaHoldC160) === true, "wait_other is hold");
assert(fifoHoldRowIsActiveHold(ikaHoldSe166) === true, "wait_live is hold");
assert(fifoHoldRowIsActiveHold(ikaShipped) === false, "delivered is not active hold");
assert(fifoHoldRowIsActiveHold(scheduled) === false, "scheduled_ship is not hold");
assert(fifoHoldRowIsActiveHold({
  id: "X",
  status: "reserved",
  shippingNote: "先不出貨，寄庫",
  customer: { phone: "0916464082" },
}) === true, "note 寄庫 counts as hold");

assert(fifoHoldItemSummary(ikaHoldC160).indexOf("C160") !== -1, "item summary has code");
assert(fifoHoldItemSummary(ikaHoldC160).indexOf("×1") !== -1, "item summary has qty");

const siblings = fifoCollectCustomerHoldRows(
  [ikaHoldSe166, ikaShipped, otherHold],
  [ikaHoldC160, scheduled],
  ikaHoldC160
);
assert(siblings.length === 1, "only the other Ika hold");
assert(siblings[0].id === "LIVE-B", "sibling is SE166 hold");

const allIka = fifoCollectCustomerHoldRows(
  [ikaHoldSe166],
  [ikaHoldC160],
  "0916464082",
  ""
);
assert(allIka.length === 2, "phone collect includes both without exclude");

const groups = fifoHoldGroupByCustomer([ikaHoldC160, ikaHoldSe166, otherHold]);
assert(groups.length === 1, "only Ika has 2+ holds");
assert(groups[0].rows.length === 2, "Ika group size 2");

const msg = fifoHoldReminderMessage(siblings);
assert(msg.indexOf("已有 1 張寄庫單") !== -1, "reminder counts siblings");
assert(msg.indexOf("SE166") !== -1, "reminder lists product");
assert(msg.indexOf("合併一起出") !== -1, "reminder says merge");

assert(fifoShouldPopupHoldMerge("0916464082", groups, "") === true, "search pops first time");
assert(fifoShouldPopupHoldMerge("0916464082", groups, "0916464082") === false, "same query does not re-pop");
assert(fifoShouldPopupHoldMerge("", groups, "") === false, "empty query no popup");
assert(fifoShouldPopupHoldMerge("0916464082", [], "") === false, "no groups no popup");

assert(fifoHoldMergeIds(ikaHoldC160, siblings).join(",") === "BYORDER-A,LIVE-B", "merge ids current+siblings");
const expanded = fifoHoldExpandVisibleWithSiblings(
  [ikaHoldC160],
  [ikaHoldSe166, ikaShipped, otherHold],
  [ikaHoldC160, scheduled]
);
assert(expanded.length === 2, "searching one hold expands the other Ika hold");
assert(expanded.some((row) => row.id === "LIVE-B"), "expanded includes SE166 hold");
assert(fifoShouldPopupHoldMerge("BYORDER-A", expanded.length >= 2 ? [{ rows: expanded }] : [], "") === true, "order-id search still pops siblings");

assert(fifoHoldMergeButtonLabel(1) === "合併同客人 2 張寄庫單一起出", "button names 2 sheets");
assert(fifoHoldMergeButtonLabel(0) === "", "no button without siblings");

console.log("LINGZANZAN fifo hold merge tests ok");
