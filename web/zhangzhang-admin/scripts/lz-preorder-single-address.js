"use strict";

function preorderLooksLikeStore(text) {
  return /7\s*-?\s*11|711|全家|family|familymart|超商|店到店|minimarket/i.test(String(text || ""));
}

function preorderStoreNo(text) {
  var match = String(text || "").match(/(\d{4,6})/);
  return match ? match[1] : "";
}

function preorderSameStore(a, b) {
  var na = preorderStoreNo(a);
  var nb = preorderStoreNo(b);
  if (na && nb) return na === nb;
  var left = String(a || "").replace(/\s+/g, "");
  var right = String(b || "").replace(/\s+/g, "");
  return !!(left && right && left === right);
}

function preorderKeepSingleShippingAddress(shipping, deliveryType) {
  shipping = shipping || {};
  var store = String(shipping.storeAddress || "").trim();
  var home = String(shipping.homeAddress || "").trim();
  var address = String(shipping.address || "").trim();
  var primary = String(shipping.addressPrimary || "").trim();
  var delivery = String(deliveryType || shipping.deliveryType || shipping.deliveryLabel || "").toLowerCase();
  if (store && home && (preorderSameStore(store, home) || preorderLooksLikeStore(home))) {
    home = "";
    primary = "store";
  }
  if (!primary) {
    if (/store|超商|7-11|全家/.test(delivery) || preorderLooksLikeStore(store || address)) primary = "store";
    else if (/home|住家|宅配|郵局|post|hct|ktj|大榮/.test(delivery)) primary = "home";
    else primary = store && !home ? "store" : "home";
  }
  if (primary === "store") {
    store = store || (preorderLooksLikeStore(address) ? address : store);
    home = "";
    address = store || address;
  } else {
    home = home || (!preorderLooksLikeStore(address) ? address : home);
    store = "";
    address = home || address;
  }
  return {
    storeAddress: store,
    homeAddress: home,
    address: address,
    addressPrimary: primary,
    deliveryType: primary === "store" ? "store" : "home",
    deliveryLabel: primary === "store" ? "超商取貨" : "住家宅配"
  };
}

function preorderAdminSavesSingleAddress(adminJs) {
  var src = String(adminJs || "");
  return src.indexOf("function preorderKeepSingleShippingAddress(") !== -1
    && src.indexOf("ensureManualPreorderSingleAddress(") !== -1
    && src.indexOf("shipping = preorderKeepSingleShippingAddress(") !== -1;
}

function preorderHelperWatchesSingleField(helperJs) {
  var src = String(helperJs || "");
  return src.indexOf("[data-manual-preorder-form] [data-manual-customer-address]") !== -1;
}

module.exports = {
  preorderLooksLikeStore,
  preorderStoreNo,
  preorderSameStore,
  preorderKeepSingleShippingAddress,
  preorderAdminSavesSingleAddress,
  preorderHelperWatchesSingleField,
};
