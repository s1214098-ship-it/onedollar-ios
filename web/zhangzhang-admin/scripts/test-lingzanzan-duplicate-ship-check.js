#!/usr/bin/env node
"use strict";

const {
  duplicateCustomerKey,
  duplicateProductKey,
  duplicateShipKind,
  duplicateKindLabel,
  findDuplicateMatches,
  orderHasDuplicateAck,
  orderAlreadyPenalized,
  shouldPenalizeDuplicateNeglect,
  buildDuplicatePenalty,
  duplicatePenaltyPrompt,
} = require("./lz-duplicate-ship-check");

function assert(cond, label) {
  if (!cond) throw new Error("fail: " + label);
  console.log("ok:", label);
}

const shipped = {
  id: "BYORDER-OLD",
  status: "shipped",
  shippedAt: "2026-08-10T02:00:00+08:00",
  customer: { name: "Sari", phone: "0912345678" },
  items: [{ code: "A100", color: "黑", size: "M", qty: 1 }],
  trackingNo: "E111",
};
const openTwin = {
  id: "BYORDER-OPEN",
  status: "confirming",
  createdAt: "2026-08-18T02:00:00+08:00",
  customer: { name: "Sari", phone: "0912345678" },
  items: [{ code: "A100", color: "黑", size: "M", qty: 1 }],
};
const current = {
  id: "BYORDER-NOW",
  status: "confirming",
  createdAt: "2026-08-21T04:00:00+08:00",
  customer: { name: "Sari", phone: "0912-345-678" },
  items: [{ code: "A100", color: "黑", size: "M", qty: 2 }],
};

assert(duplicateCustomerKey(current) === duplicateCustomerKey(shipped), "same customer phone key");
assert(duplicateProductKey(current.items[0]) === duplicateProductKey(shipped.items[0]), "same product key");
assert(duplicateShipKind(shipped) === "shipped", "shipped kind");
assert(duplicateShipKind(openTwin) === "open", "unshipped kind");
assert(duplicateKindLabel("shipped").indexOf("已出貨") !== -1, "已出貨 label");
assert(duplicateKindLabel("open").indexOf("未出貨") !== -1, "未出貨 label");

const matches = findDuplicateMatches(current, [shipped, openTwin, current], [], "2026-08-21");
assert(matches.length === 2, "finds shipped and unshipped twins");
assert(matches.some(function (row) { return row.kind === "shipped"; }), "includes 已出貨");
assert(matches.some(function (row) { return row.kind === "open"; }), "includes 未出貨");

const otherProduct = Object.assign({}, current, {
  items: [{ code: "B200", color: "白", size: "S", qty: 1 }],
});
assert(findDuplicateMatches(otherProduct, [shipped], [], "2026-08-21").length === 0, "different product is not a duplicate");

const oldTwin = Object.assign({}, shipped, {
  id: "BYORDER-OLD2",
  shippedAt: "2026-06-01T02:00:00+08:00",
});
assert(findDuplicateMatches(current, [oldTwin], [], "2026-08-21").length === 0, "older than 31 days is ignored");

assert(duplicatePenaltyPrompt().indexOf("確定") !== -1, "prompt asks for 確定");
assert(duplicatePenaltyPrompt().indexOf("100") !== -1, "prompt mentions 100");
assert(duplicatePenaltyPrompt().indexOf("1 點") !== -1, "prompt mentions 1 point");

const shippedNow = Object.assign({}, current, { status: "shipped", shippedAt: "2026-08-21T10:00:00+08:00" });
assert(shouldPenalizeDuplicateNeglect(shippedNow, matches, [], []) === true, "unacked shipped duplicate is neglect");
assert(
  shouldPenalizeDuplicateNeglect(shippedNow, matches, [], [], Date.parse("2026-08-21T10:00:00+08:00")) === true,
  "unacked still neglect with nowMs"
);
assert(
  shouldPenalizeDuplicateNeglect(
    Object.assign({}, shippedNow, { duplicateCheckAckAt: "2026-08-21T09:00:00+08:00" }),
    matches,
    [],
    []
  ) === false,
  "acked order is not neglect"
);
assert(
  shouldPenalizeDuplicateNeglect(shippedNow, matches, [{ orderId: "BYORDER-NOW", ackedAt: "2026-08-21T09:00:00+08:00" }], []) === false,
  "ack ledger by orderId skips penalty"
);
assert(
  shouldPenalizeDuplicateNeglect(
    shippedNow,
    matches,
    [{ customerKey: duplicateCustomerKey(shippedNow), ackedAt: "2026-08-21T09:30:00+08:00" }],
    [],
    Date.parse("2026-08-21T10:00:00+08:00")
  ) === false,
  "recent customer-key ack skips penalty"
);
assert(shouldPenalizeDuplicateNeglect(current, matches, [], []) === false, "unshipped order is not penalized yet");
assert(shouldPenalizeDuplicateNeglect(shippedNow, [], [], []) === false, "no matches means no neglect");

const first = buildDuplicatePenalty(shippedNow, matches, "小姐A", "2026-08-21T10:00:00+08:00");
assert(first.amountTwd === 100, "deducts 100 once");
assert(first.scorePoints === 1, "records 1 point");
assert(first.penaltyKind === "duplicate_unacked", "penalty kind");
assert(orderAlreadyPenalized({ duplicateNeglectPenaltyId: "SERR-1" }, []) === true, "order flag blocks second penalty");
assert(
  orderAlreadyPenalized(shippedNow, [{ penaltyKind: "duplicate_unacked", orderId: "BYORDER-NOW" }]) === true,
  "existing staff-error blocks second penalty"
);
assert(orderHasDuplicateAck({ duplicateCheckAckAt: "x" }, []) === true, "ack timestamp counts");

const dialog = [
  "<h3>發現重複相同訂單，請先核對</h3>",
  "<p>" + duplicatePenaltyPrompt() + "</p>",
  '<button type="button" data-similar-ship-ack>確定（已核對）</button>',
  '<button type="button" data-similar-ship-stop>先不要出</button>',
].join("");
assert(dialog.indexOf("確定（已核對）") !== -1, "dialog has 確定");
assert(dialog.indexOf("data-similar-ship-ack") !== -1, "dialog ack button marker");

console.log("LINGZANZAN duplicate-ship-check tests ok");
