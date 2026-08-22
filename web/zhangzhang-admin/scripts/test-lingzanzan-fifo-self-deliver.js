#!/usr/bin/env node
"use strict";

const assert = require("assert");
const {
  adminOrderIsSelfDeliver,
  fifoSelfDeliverUpdate,
  fifoSelfDeliverProgressNote,
  fifoSelfDeliverSubmitLabel,
  fifoSelfDeliverSavingText,
  fifoSelfDeliverJsHasHelper,
  fifoSelfDeliverConfirmCompletes,
  fifoSelfDeliverBoardHasCompleteButton,
  fifoSelfDeliverSkipsPaymentCheck,
} = require("./lz-fifo-self-deliver");

assert.strictEqual(adminOrderIsSelfDeliver({ shippingCarrier: "自取／不需物流" }), true);
assert.strictEqual(adminOrderIsSelfDeliver({}, "自取／不需物流"), true);
assert.strictEqual(adminOrderIsSelfDeliver({ deliveryType: "self" }), true);
assert.strictEqual(adminOrderIsSelfDeliver({ customer: { deliveryType: "self" } }), true);
assert.strictEqual(adminOrderIsSelfDeliver({ customer: { delivery: "面交" } }), true);
assert.strictEqual(adminOrderIsSelfDeliver({ shippingCarrier: "自己送" }), true);
assert.strictEqual(adminOrderIsSelfDeliver({ shippingCarrier: "7-11" }), false);
assert.strictEqual(adminOrderIsSelfDeliver({ shippingCarrier: "全家" }), false);
assert.strictEqual(adminOrderIsSelfDeliver({ shippingCarrier: "印尼業務自行出貨" }), false);
assert.strictEqual(adminOrderIsSelfDeliver({
  shippingCarrier: "印尼業務自行出貨",
  trackingOptionalReason: "印尼業務自行出貨不需物流單號",
}), false);
assert.strictEqual(adminOrderIsSelfDeliver({
  shippingCarrier: "7-11",
  trackingOptional: true,
  trackingOptionalReason: "管理者指定不建物流單號",
}), false);
assert.strictEqual(adminOrderIsSelfDeliver({
  id: "BYORDER-20260822-510E9C",
  shippingCarrier: "自取／不需物流",
  customer: { address: "7-11 福同店(209362)" },
}), true);

assert.deepStrictEqual(fifoSelfDeliverUpdate(true, true, false), {
  status: "accepted",
  deliveryState: "pending",
  complete: false,
});
assert.deepStrictEqual(fifoSelfDeliverUpdate(false, true, false), {
  status: "delivered",
  deliveryState: "delivered",
  complete: true,
});
assert.deepStrictEqual(fifoSelfDeliverUpdate(false, false, true), {
  status: "shipped",
  deliveryState: "in_transit",
  complete: false,
});
assert.deepStrictEqual(fifoSelfDeliverUpdate(false, false, false), {
  status: "accepted",
  deliveryState: "pending",
  complete: false,
});

assert.ok(fifoSelfDeliverProgressNote(false, true, false).indexOf("不需再正式出貨核對") !== -1);
assert.strictEqual(fifoSelfDeliverSubmitLabel(true, true, false), "完成自己送／自取");
assert.strictEqual(fifoSelfDeliverSubmitLabel(false, true, false), "儲存正式訂單");
assert.strictEqual(fifoSelfDeliverSubmitLabel(false, false, true), "扣台灣現貨並建立正式出貨單");
assert.ok(fifoSelfDeliverSavingText(false, true, false).indexOf("自己送") !== -1);

assert.strictEqual(fifoSelfDeliverJsHasHelper("function adminOrderIsSelfDeliver(order, carrier) {"), true);
assert.strictEqual(fifoSelfDeliverConfirmCompletes("status: isSelfDeliver ? 'delivered' : (isInTransit ? 'shipped' : 'accepted')"), true);
assert.strictEqual(fifoSelfDeliverBoardHasCompleteButton('data-freight-fifo-self-deliver-complete'), true);
assert.strictEqual(fifoSelfDeliverSkipsPaymentCheck("if (!adminOrderIsSelfDeliver(order, carrier)) checks.push('核對收款方式');"), true);

console.log("LINGZANZAN fifo self-deliver tests ok");
