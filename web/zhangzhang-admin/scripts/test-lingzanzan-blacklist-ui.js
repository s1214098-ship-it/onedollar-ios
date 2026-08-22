#!/usr/bin/env node
"use strict";

const {
  blacklistActionLabel,
  blacklistCustomerStatus,
  blacklistActionHostSelector,
  cancelledBucketTitle,
  isActionLabelNotStatus,
  isStatusNotAction,
} = require("./lz-blacklist-ui");

function assert(cond, label) {
  if (!cond) throw new Error("fail: " + label);
  console.log("ok:", label);
}

assert(blacklistActionLabel(false) === "加入黑名單", "unlisted button is 加入黑名單");
assert(blacklistActionLabel(true) === "解除黑名單", "listed button is 解除黑名單");
assert(blacklistActionLabel(false) !== "標注黑名單", "does not use 標注黑名單");
assert(isActionLabelNotStatus("加入黑名單") === true, "加入 is an action");
assert(isStatusNotAction("加入黑名單") === false, "加入 is not a status");
assert(blacklistCustomerStatus(null) === "", "no status when not listed");
assert(blacklistCustomerStatus({}) === "此客為黑名單", "listed status without reason");
assert(blacklistCustomerStatus({ reason: "詐騙" }) === "此客為黑名單／詐騙", "listed status with reason");
assert(isStatusNotAction("此客為黑名單／詐騙") === true, "status starts with 此客為黑名單");
assert(blacklistActionHostSelector().indexOf(".order-tracking-actions") !== -1, "button host is right actions");
assert(blacklistActionHostSelector().indexOf(".order-tracking-customer") === -1, "button host is not under the name");
assert(cancelledBucketTitle().indexOf("已取消") !== -1, "cancelled bucket keeps 已取消");
assert(cancelledBucketTitle().indexOf("黑名單／") === -1, "cancelled bucket is not titled 黑名單");

console.log("LINGZANZAN blacklist ui wording tests ok");
