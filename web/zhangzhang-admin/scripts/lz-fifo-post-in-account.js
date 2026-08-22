"use strict";

function orderTrackingOfficialBlob(order) {
  order = order || {};
  const parcels = Array.isArray(order.outboundParcels) ? order.outboundParcels : [];
  const bits = [
    order.externalStatus,
    order.internalStatus,
    order.deliveryStatus,
    order.officialStatus,
  ];
  parcels.forEach(function (parcel) {
    bits.push(
      parcel &&
        (parcel.officialStatus || parcel.officialStatusText || parcel.statusMessage || parcel.status)
    );
  });
  return bits
    .map(function (value) {
      return String(value || "");
    })
    .join(" ");
}

function officialTextMeansCarrierHandoff(text) {
  return /入帳成功|已收件|已交寄|門市已收|運輸中|配送中|轉運中|離開寄件門市|郵件處理中|貨件已受理|到達門市|抵達門市|已到店|已送達|取件完成|收寄/.test(
    String(text || "")
  );
}

function fifoPostInAccountIsHandoff(order) {
  return officialTextMeansCarrierHandoff(orderTrackingOfficialBlob(order));
}

function fifoPostInAccountAlreadyInTransit(orderId, order, inquiry) {
  if (fifoPostInAccountIsHandoff(order) || fifoPostInAccountIsHandoff(inquiry)) return true;
  return !!(orderId && order && (order.status === "shipped" || order.deliveryState === "in_transit"));
}

function fifoPostInAccountCarrierSelected(order, row, preorder) {
  return String(
    (order && order.shippingCarrier) ||
      (row && row.shippingCarrier) ||
      (preorder && preorder.shippingCarrier) ||
      ""
  ).trim();
}

function fifoPostInAccountJsHasHandoffText(adminJs) {
  return /入帳成功\|已收件\|已交寄/.test(String(adminJs || ""));
}

function fifoPostInAccountJsReadsParcelOfficial(adminJs) {
  return /parcel\.officialStatus \|\| parcel\.officialStatusText/.test(String(adminJs || ""));
}

function fifoPostInAccountJsPrefillsCarrier(adminJs) {
  return /shippingCarrierOptionsHtml\(\(order && order\.shippingCarrier\) \|\| \(row && row\.shippingCarrier\)/.test(
    String(adminJs || "")
  );
}

function fifoPostInAccountJsAlreadyInTransitUsesInquiry(adminJs) {
  return /orderTrackingHasCarrierHandoff\(currentOrder\) \|\| orderTrackingHasCarrierHandoff\(sourceInquiry\)/.test(
    String(adminJs || "")
  );
}

module.exports = {
  orderTrackingOfficialBlob,
  officialTextMeansCarrierHandoff,
  fifoPostInAccountIsHandoff,
  fifoPostInAccountAlreadyInTransit,
  fifoPostInAccountCarrierSelected,
  fifoPostInAccountJsHasHandoffText,
  fifoPostInAccountJsReadsParcelOfficial,
  fifoPostInAccountJsPrefillsCarrier,
  fifoPostInAccountJsAlreadyInTransitUsesInquiry,
};
