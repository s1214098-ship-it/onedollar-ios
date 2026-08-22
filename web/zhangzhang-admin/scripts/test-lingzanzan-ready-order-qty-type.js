"use strict";

const assert = require("assert");
const {
  readyOrderQtyFieldsAreTypeable,
  readyOrderQtyHintSaysType,
} = require("./lz-ready-order-qty-type");

const css = "/* 20260822 ready-order type */\n.admin-shipment-pick-stepper input { min-width:64px }\ngrid-template-columns: minmax(0, 1.3fr) minmax(148px, 168px) minmax(112px, 140px);";
const js = '數量點中間格子直接打字\n請先在各尺寸中間格子打數量\ninputmode="numeric"';

assert.strictEqual(readyOrderQtyFieldsAreTypeable(css), true);
assert.strictEqual(readyOrderQtyFieldsAreTypeable(".is-active{color:gold}"), false);
assert.strictEqual(readyOrderQtyHintSaysType(js), true);
assert.strictEqual(readyOrderQtyHintSaysType("請先在各尺寸按 + 選數量，再一次帶入出貨單"), false);

console.log(JSON.stringify({ ok: true, tests: 4 }));
