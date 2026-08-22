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
  "+ '<div class=\"freight-fifo-parcel-thead\"><span>包裹</span><span>物流單號</span><span>代收金額</span><span>貨態</span><span></span></div>'",
  "+ '<input data-freight-fifo-parcel-tracking'",
  "+ '<input type=\"number\" min=\"0\" step=\"1\" data-freight-fifo-parcel-amount'",
  "+ '<em data-freight-fifo-parcel-status>'",
  "var count = wrap.querySelectorAll('[data-freight-fifo-parcel-row]').length;",
  "var placeholder = row.querySelector('.freight-fifo-parcel-action');",
  "if (title) title.textContent = String(index + 1);",
].join("\n");

const css = [
  ".freight-fifo-parcel-thead {",
  "  grid-template-columns: 52px minmax(168px, 1.35fr) 108px minmax(160px, 1.45fr) 76px;",
  "  min-width: 640px;",
  "  border-right: 1px solid rgba(255, 248, 237, 0.16);",
].join("\n");

const snakeCss = [
  ".freight-fifo-parcel-thead {",
  "  grid-template-columns: 52px minmax(168px, 1.35fr) 108px minmax(160px, 1.45fr) 76px;",
  "  min-width: 640px;",
  "  border-right: 1px solid rgba(255, 248, 237, 0.16);",
  "@media (max-width: 720px) {",
  "  .freight-fifo-parcel-row {",
  "    grid-template-columns: 1fr;",
  "  }",
].join("\n");

assert.strictEqual(fifoParcelGridJsHasCells(js), true);
assert.strictEqual(fifoParcelGridJsKeepsStatusHook(js), true);
assert.strictEqual(fifoParcelGridJsRenumbersInHead(js), true);
assert.strictEqual(fifoParcelGridCssHasRules(css), true);
assert.strictEqual(fifoParcelGridCssHasRules(snakeCss), false);
assert.strictEqual(fifoParcelGridDoesNotRestyleActive(css), true);
assert.strictEqual(fifoParcelGridDoesNotRestyleActive(".is-active { color: gold }"), false);
assert.strictEqual(fifoParcelGridJsHasCells("freight-fifo-parcel-row"), false);
assert.strictEqual(fifoParcelGridJsHasCells("freight-fifo-parcel-cell"), false);
assert.strictEqual(fifoParcelGridCssHasRules(".freight-fifo-parcel-row { display:grid }"), false);

console.log(JSON.stringify({ ok: true, tests: 10 }));
