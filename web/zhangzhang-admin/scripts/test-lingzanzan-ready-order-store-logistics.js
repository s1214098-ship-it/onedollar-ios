"use strict";

const assert = require("assert");
const {
  readyOrderStoreFieldsAreMounted,
  readyOrderTrackingGoesOnCreate,
  readyOrderPhoneSearchShowsCustomerOnly,
  readyOrderStoreCssKeepsLogisticsVisible,
  readyOrderCartApiKeepsTracking,
  readyOrderStoreDoesNotRestyleActive,
} = require("./lz-ready-order-store-logistics");

const js = [
  "function ensureAdminShipmentPhotoPlacement() {}",
  "function ensureAdminShipmentTrackingField() {}",
  "ensureAdminShipmentPhotoPlacement();",
  "ensureAdminShipmentTrackingField();",
  "data-admin-shipment-tracking",
  "fd.get('trackingNo')",
  "outboundParcels: trackingNo",
  "function adminReadyOrderCustomerSearchHits() {}",
  "function adminReadyOrderCustomerOnlyHtml() {}",
  "只有客戶資料，還沒有現貨出貨單",
  "儲存客戶資料",
  "data-admin-ready-fill-customer",
].join("\n");

const css = "/* 20260822 ready-order store logistics */\n.admin-shipment-grid .admin-shipment-customer-photo { grid-row: auto; }\n.admin-ready-customer-only { gap: 8px; }";
const php = "$order['trackingNo'] = $payload['trackingNo'];\n$order['parcels'] = $payload['outboundParcels'];";

assert.strictEqual(readyOrderStoreFieldsAreMounted(js), true);
assert.strictEqual(readyOrderStoreFieldsAreMounted("ensureAdminShipmentAddressFields()"), false);
assert.strictEqual(readyOrderTrackingGoesOnCreate(js), true);
assert.strictEqual(readyOrderPhoneSearchShowsCustomerOnly(js), true);
assert.strictEqual(readyOrderStoreCssKeepsLogisticsVisible(css), true);
assert.strictEqual(readyOrderCartApiKeepsTracking(php), true);
assert.strictEqual(readyOrderStoreDoesNotRestyleActive(css), true);
assert.strictEqual(readyOrderStoreDoesNotRestyleActive(".is-active{color:gold}"), false);

console.log(JSON.stringify({ ok: true, tests: 8 }));
