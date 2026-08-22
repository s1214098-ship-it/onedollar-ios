#!/usr/bin/env node
"use strict";

const fs = require("fs");
const path = require("path");
const {
  fifoCustomerOrderMergeIds,
  fifoCustomerOrderMergeButtonLabel,
} = require("./lz-fifo-customer-orders");

function assert(cond, label) {
  if (!cond) throw new Error("fail: " + label);
}

const patch = fs.readFileSync(path.join(__dirname, "fix-lingzanzan-fifo-customer-merge-pick.js"), "utf8");
assert(patch.indexOf("data-freight-fifo-customer-order-merge-pick") !== -1, "installer has pick checkbox");
assert(patch.indexOf("合併這張") !== -1, "installer has pick label");
assert(patch.indexOf("請先勾選要合併的單") !== -1, "installer asks to pick");
assert(patch.indexOf("開啟出貨單（可加商品）") !== -1, "keeps open shipment");
assert(patch.indexOf("沒勾的不會併入") !== -1, "confirm skips unchecked");
assert(patch.indexOf("customerOrdersMerge") !== -1, "list click uses picked ids");
assert(patch.indexOf("freightFifoBindCustomerOrderMergePicks") !== -1, "binds checkbox changes");

assert(fifoCustomerOrderMergeButtonLabel(2, "idle") === "請勾選要合併的單", "idle label");
assert(fifoCustomerOrderMergeIds(
  [{ id: "A", status: "accepted" }, { id: "B", status: "accepted" }, { id: "C", status: "delivered" }],
  ["A", "C"]
).join(",") === "A", "picked filter");

console.log("LINGZANZAN fifo customer merge pick tests ok");
