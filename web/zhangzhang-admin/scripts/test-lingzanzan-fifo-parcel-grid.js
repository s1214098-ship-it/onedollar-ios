"use strict";

const assert = require("assert");
const {
  fifoParcelGridJsHasCells,
  fifoParcelGridJsKeepsStatusHook,
  fifoParcelGridJsRenumbersInHead,
  fifoParcelGridCssHasRules,
  fifoParcelGridDoesNotRestyleActive,
} = require("./lz-fifo-parcel-grid");

const js = [
  "return '<div class=\"freight-fifo-parcel-row\" data-freight-fifo-parcel-row>'",
  "+ '<div class=\"freight-fifo-parcel-head\">'",
  "+ '<div class=\"freight-fifo-parcel-grid\">'",
  "+ '<label class=\"freight-fifo-parcel-cell\"><span>物流單號</span>'",
  "+ '<input data-freight-fifo-parcel-tracking'",
  "+ '<label class=\"freight-fifo-parcel-cell\"><span>代收金額</span>'",
  "+ '<input type=\"number\" min=\"0\" step=\"1\" data-freight-fifo-parcel-amount'",
  "+ '<em data-freight-fifo-parcel-status>'",
  "var head = row.querySelector('.freight-fifo-parcel-head') || row;",
].join("\n");

const css = [
  ".freight-fifo-parcel-head {",
  ".freight-fifo-parcel-grid {",
  ".freight-fifo-parcel-cell + .freight-fifo-parcel-cell {",
  "  border-left: 1px solid rgba(255, 248, 237, 0.16);",
  ".freight-fifo-parcel-status {",
  "  border-top: 1px dashed rgba(100, 240, 207, 0.38);",
].join("\n");

assert.strictEqual(fifoParcelGridJsHasCells(js), true);
assert.strictEqual(fifoParcelGridJsKeepsStatusHook(js), true);
assert.strictEqual(fifoParcelGridJsRenumbersInHead(js), true);
assert.strictEqual(fifoParcelGridCssHasRules(css), true);
assert.strictEqual(fifoParcelGridDoesNotRestyleActive(css), true);
assert.strictEqual(fifoParcelGridDoesNotRestyleActive(".is-active { color: gold }"), false);
assert.strictEqual(fifoParcelGridJsHasCells("freight-fifo-parcel-row"), false);
assert.strictEqual(fifoParcelGridCssHasRules(".freight-fifo-parcel-row { display:grid }"), false);

console.log(JSON.stringify({ ok: true, tests: 8 }));
