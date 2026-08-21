#!/usr/bin/env node
"use strict";

function freightItemHasOpenWorklineIssue(item) {
  item = item || {};
  if (item.issueType && item.issueType !== "正常") return true;
  var progress = String(item.progress || item.issueProgress || "");
  if (/缺件/.test(progress) || (/異常/.test(progress) && !/無異常/.test(progress))) return true;
  if (!item.quantityReconciliationRequired) return false;
  var diff = Number(item.receivingQuantityDifference || 0);
  if (diff > 0) return false;
  if ((item.arrivalExtraOnTracking || item.isSupplementalSplitReceipt) && diff >= 0) return false;
  return true;
}

function phpReconRequired(isFinal, difference) {
  return !!(isFinal && difference < 0);
}

function extraItemFlags(sourceItem, trackingNo) {
  return {
    trackingNo: trackingNo,
    parentFreightItemId: String(sourceItem.parentFreightItemId || sourceItem.id || ""),
    isSupplementalSplitReceipt: true,
    arrivalExtraOnTracking: true,
    supplementalTrackingNo: trackingNo,
    quantityReconciliationRequired: false,
    issueType: "",
  };
}

function allowsSameTracking(item, items, trackingNo) {
  if (!trackingNo) return false;
  if (!item.isSupplementalSplitReceipt && !item.arrivalExtraOnTracking) return false;
  var parentId = String(item.parentFreightItemId || "");
  if (!parentId) return false;
  return items.some(function (saved) {
    return String(saved.id || "") === parentId && String(saved.trackingNo || "") === trackingNo;
  });
}

function assert(cond, label) {
  if (!cond) throw new Error("fail: " + label);
  console.log("ok:", label);
}

assert(
  !freightItemHasOpenWorklineIssue({
    quantityReconciliationRequired: true,
    receivingQuantityDifference: 1,
    progress: "已入庫",
  }),
  "confirmed overage is not a workline issue"
);
assert(
  freightItemHasOpenWorklineIssue({
    quantityReconciliationRequired: true,
    receivingQuantityDifference: -1,
    progress: "已入庫",
  }),
  "shortage still counts as issue"
);
assert(
  freightItemHasOpenWorklineIssue({ issueType: "缺件", progress: "已入庫" }),
  "explicit issueType still counts"
);
assert(
  freightItemHasOpenWorklineIssue({ progress: "缺件待處理" }),
  "shortage progress still counts"
);
assert(
  !freightItemHasOpenWorklineIssue({ progress: "台灣收到待點貨" }),
  "normal inbound progress is not an issue"
);
assert(phpReconRequired(true, -2) === true, "php shortage stays open");
assert(phpReconRequired(true, 1) === false, "php overage does not stay open");
assert(phpReconRequired(false, 1) === false, "php draft overage does not stay open");

var parent = { id: "FRTI-PARENT", trackingNo: "79024383254812" };
var extra = extraItemFlags(parent, "79024383254812");
assert(extra.isSupplementalSplitReceipt === true, "extra is supplemental");
assert(extra.parentFreightItemId === "FRTI-PARENT", "extra points at parent");
assert(
  allowsSameTracking(extra, [parent], "79024383254812") === true,
  "same tracking extra is allowed"
);
assert(
  allowsSameTracking(
    { trackingNo: "79024383254812", arrivalExtraOnTracking: false, isSupplementalSplitReceipt: false, parentFreightItemId: "FRTI-PARENT" },
    [parent],
    "79024383254812"
  ) === false,
  "plain duplicate tracking is blocked"
);

console.log("all tests passed");
