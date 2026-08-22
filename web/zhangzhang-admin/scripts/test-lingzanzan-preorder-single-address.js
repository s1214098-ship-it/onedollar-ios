#!/usr/bin/env node
"use strict";

const {
  preorderLooksLikeStore,
  preorderSameStore,
  preorderKeepSingleShippingAddress,
  preorderAdminSavesSingleAddress,
  preorderHelperWatchesSingleField,
} = require("./lz-preorder-single-address");

function assert(cond, label) {
  if (!cond) throw new Error("fail: " + label);
  console.log("ok:", label);
}

assert(preorderLooksLikeStore("7-11 校和店(881685)") === true, "7-11 looks like store");
assert(preorderLooksLikeStore("屏東縣竹田鄉鳳平路2巷9號(HCT)") === false, "HCT home is not store");
assert(preorderSameStore("7-11 校和店(881685)", "7-11 校和店 881685") === true, "same store number is one address");

const dup = preorderKeepSingleShippingAddress({
  storeAddress: "7-11 校和店(881685)",
  homeAddress: "7-11 校和店 881685",
  address: "7-11 校和店(881685)",
  addressPrimary: "store"
}, "超商取貨 NT$70");
assert(dup.storeAddress.indexOf("校和") !== -1, "keeps 超商");
assert(dup.homeAddress === "", "drops duplicate home 7-11");
assert(dup.addressPrimary === "store", "primary stays store");

const homeOnly = preorderKeepSingleShippingAddress({
  storeAddress: "",
  homeAddress: "屏東縣竹田鄉鳳明村鳳平路2巷9號(HCT)",
  address: "屏東縣竹田鄉鳳明村鳳平路2巷9號(HCT)",
  addressPrimary: "home"
}, "住家出貨 NT$100");
assert(homeOnly.storeAddress === "", "home shipment does not create store");
assert(homeOnly.homeAddress.indexOf("竹田") !== -1, "keeps home");

const patched = [
  "function preorderKeepSingleShippingAddress(shipping, deliveryType) {",
  "function ensureManualPreorderSingleAddress() {",
  "shipping = preorderKeepSingleShippingAddress(shipping, String(fields.delivery && fields.delivery.value || ''));",
].join("\n");
assert(preorderAdminSavesSingleAddress(patched) === true, "detects admin single-address patch");
assert(preorderAdminSavesSingleAddress("兩個地址都會記住") === false, "old dual-address copy is not the patch");

const helperNew = "source === 'preorder' ? '[data-manual-preorder-form] [data-manual-customer-address], [data-manual-preorder-form] input[name=\"address\"]'";
assert(preorderHelperWatchesSingleField(helperNew) === true, "helper watches only preorder address field");
assert(preorderHelperWatchesSingleField("source === 'preorder' ? '[data-manual-customer-address], [data-customer-home-address]'") === false, "old helper watched store and home");

console.log("LINGZANZAN preorder single address tests ok");
