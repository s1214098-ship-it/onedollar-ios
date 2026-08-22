#!/usr/bin/env node
"use strict";

function leftoverTracking(order) {
  var no = String(order && order.trackingNo || "").trim();
  if (no) return no;
  var parcels = (order && order.outboundParcels) || [];
  for (var i = 0; i < parcels.length; i++) {
    no = String(parcels[i] && parcels[i].trackingNo || "").trim();
    if (no) return no;
  }
  return "";
}

function revertBlocked(order, reason, extras) {
  extras = extras || {};
  var status = String(order.status || "").toLowerCase();
  var deliveryState = String(order.deliveryState || "").toLowerCase();
  if (order.shipmentMergeGroupId) return "merge";
  if (["delivered", "returned", "refunded", "cancelled", "reverted_to_waiting"].indexOf(status) >= 0) return "closed";
  if (status === "shipped" || status === "in_transit" || deliveryState === "in_transit" || extras.shippedAt || extras.carrierHandoffAt) return "shipped";
  var tracking = leftoverTracking(order);
  if (tracking && extras.requireReason && !String(reason || "").trim()) return "need-reason";
  return "";
}

function assert(cond, label) {
  if (!cond) throw new Error("fail: " + label);
  console.log("ok:", label);
}

var nia = {
  id: "BYORDER-20260722-CFFB02",
  status: "accepted",
  deliveryState: "pending",
  trackingNo: "E85178398227",
  outboundParcels: [{ trackingNo: "E85178398227", carrier: "7-11" }],
};

assert(leftoverTracking(nia) === "E85178398227", "detect leftover 7-11 number");
assert(revertBlocked(nia, "") === "", "unshipped leftover tracking can revert without typing a reason");
assert(revertBlocked(nia, "已刪除超商編號") === "", "explicit reason still allowed");
assert(revertBlocked({ status: "accepted", trackingNo: "" }, "") === "", "no tracking does not need reason");
assert(revertBlocked({ status: "in_transit", trackingNo: "E1" }, "已刪除") === "shipped", "in transit still blocked");
assert(revertBlocked({ status: "accepted", trackingNo: "E1" }, "已刪除", { shippedAt: "2026-08-21" }) === "shipped", "handed off still blocked");

console.log("all tests passed");
