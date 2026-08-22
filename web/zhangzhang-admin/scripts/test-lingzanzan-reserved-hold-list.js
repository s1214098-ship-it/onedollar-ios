"use strict";

const assert = require("assert");
const {
  reservedShippingQueryWantsHold,
  reservedShippingHoldSearchHay,
  reservedShippingMatchesHoldQuery,
  reservedShippingDateFilterKeepsHold,
  reservedShippingHoldListHtmlHasMount,
  reservedShippingHoldQuickHasButton,
  reservedShippingHoldJsHasSearchAlias,
  reservedShippingHoldDoesNotRestyleActive,
} = require("./lz-reserved-hold-list");

assert.strictEqual(reservedShippingQueryWantsHold("寄庫"), true);
assert.strictEqual(reservedShippingQueryWantsHold("0966021038 寄庫"), true);
assert.strictEqual(reservedShippingQueryWantsHold("0966021038"), false);

assert.strictEqual(reservedShippingHoldSearchHay({ reservedShippingHoldReason: "wait_other" }), "寄庫 已寄庫 先不出貨 hold");
assert.strictEqual(reservedShippingHoldSearchHay({ reservedShippingHoldReason: "scheduled_ship" }), "");
assert.strictEqual(reservedShippingHoldSearchHay({}), "");

assert.strictEqual(reservedShippingMatchesHoldQuery({ id: "A" }, "寄庫", { lane: "hold" }), true);
assert.strictEqual(reservedShippingMatchesHoldQuery({ id: "A" }, "寄庫", { lane: "schedule" }), false);
assert.strictEqual(reservedShippingMatchesHoldQuery({ id: "A" }, "0966021038 寄庫", { lane: "hold", phones: ["0966021038"], phoneHit: true }), true);
assert.strictEqual(reservedShippingMatchesHoldQuery({ id: "A" }, "0966021038 寄庫", { lane: "schedule", phones: ["0966021038"], phoneHit: true }), false);

assert.strictEqual(reservedShippingDateFilterKeepsHold("today", "hold"), true);
assert.strictEqual(reservedShippingDateFilterKeepsHold("schedule", "hold"), false);
assert.strictEqual(reservedShippingDateFilterKeepsHold("today", "schedule"), null);

assert.strictEqual(reservedShippingHoldListHtmlHasMount('<div data-reserved-hold-list></div><h2>寄庫名單</h2>'), true);
assert.strictEqual(reservedShippingHoldQuickHasButton('<button data-reserved-quick="hold">寄庫</button>'), true);
assert.strictEqual(reservedShippingHoldJsHasSearchAlias("reservedShippingQueryWantsHold\n寄庫 已寄庫 先不出貨 hold\nentry.lane === 'hold'"), true);
assert.strictEqual(reservedShippingHoldDoesNotRestyleActive(".reserved-shipping-hold{}"), true);
assert.strictEqual(reservedShippingHoldDoesNotRestyleActive(".is-active{color:gold}"), false);

console.log(JSON.stringify({ ok: true, tests: 16 }));
