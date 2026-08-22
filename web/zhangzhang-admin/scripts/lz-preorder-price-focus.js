"use strict";

function shouldRebuildPreorderLinesOnPriceInput() {
  return false;
}

function preorderPriceInputKeepsFocus(rebuildsLines) {
  return rebuildsLines !== true;
}

function preorderLineSubtotalLabel(qty, price, money) {
  var total = Number(qty || 0) * Number(price || 0);
  return "小計 " + money(total);
}

module.exports = {
  shouldRebuildPreorderLinesOnPriceInput,
  preorderPriceInputKeepsFocus,
  preorderLineSubtotalLabel,
};
