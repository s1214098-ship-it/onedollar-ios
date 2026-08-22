"use strict";

const assert = require("assert");
const {
  reservedHoldWarehouseSummary,
  reservedHoldDeleteConfirmText,
  reservedHoldDeleteBlocksShipped,
  reservedHoldDeleteUsesDeleteAction,
  reservedHoldPrintButtonInHtml,
  reservedHoldDoesNotRestyleActive,
} = require("./lz-reserved-hold-delete-print");

assert.strictEqual(
  reservedHoldWarehouseSummary({
    warehouse: "印尼倉",
    items: [{ sourceWarehouse: "台灣倉" }, { warehouseCode: "TW" }, { sourceWarehouse: "台灣倉" }],
  }),
  "台灣倉、TW"
);
assert.strictEqual(reservedHoldWarehouseSummary({ warehouse: "中國倉", items: [] }), "中國倉");

const copy = reservedHoldDeleteConfirmText({
  name: "YURSO",
  phone: "0966021038",
  warehouse: "台灣倉",
  items: "JA318 ×1",
});
assert.ok(copy.indexOf("撥回原本倉庫") !== -1);
assert.ok(copy.indexOf("YURSO／0966021038") !== -1);
assert.ok(copy.indexOf("台灣倉") !== -1);
assert.ok(copy.indexOf("取消寄庫") !== -1);

assert.strictEqual(reservedHoldDeleteBlocksShipped({ deliveryState: "in_transit" }), true);
assert.strictEqual(reservedHoldDeleteBlocksShipped({ status: "confirming" }), false);

const js = "data-reserved-hold-delete\nfunction deleteReservedHoldAndRestock(\n刪除寄庫並撥回倉庫\naction: 'delete'";
assert.strictEqual(reservedHoldDeleteUsesDeleteAction(js), true);
assert.strictEqual(reservedHoldDeleteUsesDeleteAction("data-reserved-cancel"), false);

assert.strictEqual(
  reservedHoldPrintButtonInHtml('<button data-reserved-print="hold">列印寄庫清單給業務</button>'),
  true
);
assert.strictEqual(reservedHoldDoesNotRestyleActive(".reserved-shipping-hold{}"), true);
assert.strictEqual(reservedHoldDoesNotRestyleActive(".is-active{color:gold}"), false);

console.log(JSON.stringify({ ok: true, tests: 9 }));
