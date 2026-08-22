"use strict";

function readyOrderQtyFieldsAreTypeable(css) {
  const text = String(css || "");
  return text.indexOf("20260822 ready-order type") !== -1
    && text.indexOf(".admin-shipment-pick-stepper input") !== -1
    && text.indexOf("minmax(112px, 140px)") !== -1
    && text.indexOf(".is-active") === -1;
}

function readyOrderQtyHintSaysType(adminJs) {
  const js = String(adminJs || "");
  return js.indexOf("數量點中間格子直接打字") !== -1
    && js.indexOf("請先在各尺寸中間格子打數量") !== -1
    && js.indexOf('inputmode="numeric"') !== -1
    && js.indexOf("請先在各尺寸按 + 選數量，再一次帶入出貨單") === -1;
}

module.exports = {
  readyOrderQtyFieldsAreTypeable,
  readyOrderQtyHintSaysType,
};
