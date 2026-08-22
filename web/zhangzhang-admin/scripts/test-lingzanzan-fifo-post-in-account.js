"use strict";

const assert = require("assert");
const {
  officialTextMeansCarrierHandoff,
  fifoPostInAccountIsHandoff,
  fifoPostInAccountAlreadyInTransit,
  fifoPostInAccountCarrierSelected,
  fifoPostInAccountJsHasHandoffText,
  fifoPostInAccountJsReadsParcelOfficial,
  fifoPostInAccountJsPrefillsCarrier,
  fifoPostInAccountJsAlreadyInTransitUsesInquiry,
} = require("./lz-fifo-post-in-account");

assert.strictEqual(officialTextMeansCarrierHandoff("入帳成功 20260821162603 頭份郵局"), true);
assert.strictEqual(officialTextMeansCarrierHandoff("尚未至門市寄件"), false);
assert.strictEqual(
  fifoPostInAccountIsHandoff({
    outboundParcels: [{ trackingNo: "29553300104374", officialStatus: "入帳成功 頭份郵局" }],
  }),
  true
);
assert.strictEqual(
  fifoPostInAccountIsHandoff({ trackingNo: "29553300104374", externalStatus: "" }),
  false
);
assert.strictEqual(
  fifoPostInAccountAlreadyInTransit("", { status: "preorder_pending" }, {
    outboundParcels: [{ officialStatus: "入帳成功" }],
  }),
  true
);
assert.strictEqual(
  fifoPostInAccountAlreadyInTransit("", { status: "preorder_pending" }, { trackingNo: "29553300104374" }),
  false
);
assert.strictEqual(
  fifoPostInAccountCarrierSelected(null, { shippingCarrier: "POST 郵局" }, null),
  "POST 郵局"
);
assert.strictEqual(fifoPostInAccountCarrierSelected({ shippingCarrier: "" }, { shippingCarrier: "" }, {}), "");

const patched = [
  "if (/入帳成功|已收件|已交寄/.test(externalText)) return true;",
  "parcel.officialStatus || parcel.officialStatusText",
  "shippingCarrierOptionsHtml((order && order.shippingCarrier) || (row && row.shippingCarrier) || (preorder && preorder.shippingCarrier) || '', true)",
  "var alreadyInTransit = orderTrackingHasCarrierHandoff(currentOrder) || orderTrackingHasCarrierHandoff(sourceInquiry);",
].join("\n");
assert.strictEqual(fifoPostInAccountJsHasHandoffText(patched), true);
assert.strictEqual(fifoPostInAccountJsReadsParcelOfficial(patched), true);
assert.strictEqual(fifoPostInAccountJsPrefillsCarrier(patched), true);
assert.strictEqual(fifoPostInAccountJsAlreadyInTransitUsesInquiry(patched), true);

console.log(JSON.stringify({ ok: true, tests: 12 }));
