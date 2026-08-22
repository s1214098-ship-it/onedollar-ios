"use strict";

function readyOrderStoreFieldsAreMounted(adminJs) {
  const js = String(adminJs || "");
  return js.indexOf("function ensureAdminShipmentPhotoPlacement(") !== -1
    && js.indexOf("function ensureAdminShipmentTrackingField(") !== -1
    && js.indexOf("ensureAdminShipmentPhotoPlacement();") !== -1
    && js.indexOf("ensureAdminShipmentTrackingField();") !== -1
    && js.indexOf('data-admin-shipment-tracking') !== -1;
}

function readyOrderTrackingGoesOnCreate(adminJs) {
  const js = String(adminJs || "");
  return js.indexOf("fd.get('trackingNo')") !== -1
    && js.indexOf("outboundParcels: trackingNo") !== -1;
}

function readyOrderPhoneSearchShowsCustomerOnly(adminJs) {
  const js = String(adminJs || "");
  return js.indexOf("function adminReadyOrderCustomerSearchHits(") !== -1
    && js.indexOf("function adminReadyOrderCustomerOnlyHtml(") !== -1
    && js.indexOf("只有客戶資料，還沒有現貨出貨單") !== -1
    && js.indexOf("儲存客戶資料") !== -1
    && js.indexOf("data-admin-ready-fill-customer") !== -1;
}

function readyOrderStoreCssKeepsLogisticsVisible(css) {
  const text = String(css || "");
  return text.indexOf("20260822 ready-order store logistics") !== -1
    && text.indexOf(".admin-shipment-grid .admin-shipment-customer-photo") !== -1
    && text.indexOf("grid-row: auto") !== -1
    && text.indexOf(".admin-ready-customer-only") !== -1;
}

function readyOrderCartApiKeepsTracking(php) {
  const text = String(php || "");
  return text.indexOf("$payload['trackingNo']") !== -1
    && text.indexOf("$payload['outboundParcels']") !== -1;
}

function readyOrderStoreDoesNotRestyleActive(snippet) {
  return String(snippet || "").indexOf(".is-active") === -1;
}

module.exports = {
  readyOrderStoreFieldsAreMounted,
  readyOrderTrackingGoesOnCreate,
  readyOrderPhoneSearchShowsCustomerOnly,
  readyOrderStoreCssKeepsLogisticsVisible,
  readyOrderCartApiKeepsTracking,
  readyOrderStoreDoesNotRestyleActive,
};
