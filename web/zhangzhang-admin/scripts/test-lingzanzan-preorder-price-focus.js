#!/usr/bin/env node
"use strict";

const {
  shouldRebuildPreorderLinesOnPriceInput,
  preorderPriceInputKeepsFocus,
  preorderLineSubtotalLabel,
} = require("./lz-preorder-price-focus");

function assert(cond, label) {
  if (!cond) throw new Error("fail: " + label);
  console.log("ok:", label);
}

function money(n) {
  return "NT$" + Number(n).toLocaleString("zh-TW");
}

assert(shouldRebuildPreorderLinesOnPriceInput() === false, "price typing does not rebuild the line list");
assert(preorderPriceInputKeepsFocus(true) === false, "rebuild steals focus");
assert(preorderPriceInputKeepsFocus(false) === true, "in-place update keeps focus");
assert(preorderLineSubtotalLabel(1, 200, money) === "小計 NT$200", "subtotal follows unit price");
assert(preorderLineSubtotalLabel(2, 150, money) === "小計 NT$300", "subtotal uses qty × price");

console.log("LINGZANZAN preorder price focus tests ok");
