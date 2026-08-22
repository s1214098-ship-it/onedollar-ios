"use strict";

const assert = require("assert");
const {
  blacklistShouldAskCancel,
  blacklistAskCancelMessage,
  recordIdFromBlacklistCard,
  blacklistAskCancelUsesExistingCancel,
  blacklistAskCancelHooksRiskToggle,
  blacklistAskCancelDoesNotRestyleActive,
} = require("./lz-blacklist-ask-cancel");

assert.strictEqual(blacklistShouldAskCancel({ status: "pending" }), true);
assert.strictEqual(blacklistShouldAskCancel({ status: "cancelled" }), false);
assert.strictEqual(blacklistShouldAskCancel({ deliveryState: "delivered" }), false);
assert.strictEqual(blacklistShouldAskCancel({ deliveryState: "in_transit" }), true);
assert.strictEqual(blacklistShouldAskCancel(null), false);

const copy = blacklistAskCancelMessage("YURSO", "BYORDER-20260819-17B73B");
assert.ok(copy.indexOf("列入黑名單") !== -1);
assert.ok(copy.indexOf("一起取消") !== -1);
assert.ok(copy.indexOf("只封鎖") !== -1);
assert.ok(copy.indexOf("BYORDER-20260819-17B73B") !== -1);

assert.strictEqual(
  recordIdFromBlacklistCard({
    getAttribute: function (name) {
      return name === "data-order-id" ? "LIVE-1" : "";
    },
  }),
  "LIVE-1"
);

assert.strictEqual(
  blacklistAskCancelUsesExistingCancel(
    "function askCancelAfterBlacklist(\nopenFreightFifoCancelOrder(\nLingzanzanAdminActions"
  ),
  true
);
assert.strictEqual(blacklistAskCancelUsesExistingCancel("加入黑名單"), false);
assert.strictEqual(
  blacklistAskCancelHooksRiskToggle("askCancelAfterBlacklist\ndata-risk-record-id\npayload.action !== 'save'"),
  true
);
assert.strictEqual(blacklistAskCancelDoesNotRestyleActive(".order-risk-toggle{}"), true);
assert.strictEqual(blacklistAskCancelDoesNotRestyleActive(".is-active{color:gold}"), false);

console.log(JSON.stringify({ ok: true, tests: 12 }));
