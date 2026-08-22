"use strict";

function adminOrderIsSelfDeliver(order, carrier) {
  var carrierText = String(carrier || (order && order.shippingCarrier) || "").trim();
  if (/印尼業務自行出貨/i.test(carrierText)) return false;
  var deliveryType = String(
    (order && order.deliveryType) ||
    (order && order.customer && order.customer.deliveryType) ||
    ""
  ).toLowerCase();
  if (deliveryType === "self") return true;
  var blob = [
    carrierText,
    deliveryType,
    order && order.customer && order.customer.delivery,
  ].map(function (value) {
    return String(value || "");
  }).join(" ");
  if (/自取|自己送|面交/.test(blob)) return true;
  return /不需物流/.test(carrierText);
}

function fifoSelfDeliverUpdate(isHold, isSelfDeliver, isInTransit) {
  if (isHold) return { status: "accepted", deliveryState: "pending", complete: false };
  if (isSelfDeliver) return { status: "delivered", deliveryState: "delivered", complete: true };
  if (isInTransit) return { status: "shipped", deliveryState: "in_transit", complete: false };
  return { status: "accepted", deliveryState: "pending", complete: false };
}

function fifoSelfDeliverProgressNote(isHold, isSelfDeliver, isInTransit, holdText, reservedDate, trackingNo) {
  if (isHold) return "管理者已轉正式並寄庫（" + (holdText || "寄庫") + "），之後到寄庫名單拉出來出貨";
  if (isSelfDeliver) return "管理者自己送／自取，完成出貨。不需物流單號，不需再正式出貨核對。";
  if (reservedDate && !isInTransit) return "管理者已建立正式出貨單，預約 " + reservedDate + " 出貨，日期到仍需人工確認";
  if (isInTransit) return "管理者依物流驗收入庫 FIFO 順序完成出貨，已進入配送中";
  return trackingNo ? "物流單號已建立，尚未交寄，維持準備出貨" : "管理者已建立正式出貨單，物流單號可日後補登";
}

function fifoSelfDeliverSubmitLabel(isSelfDeliver, hasFormalOrder, taiwanReady) {
  if (isSelfDeliver) return "完成自己送／自取";
  if (hasFormalOrder) return "儲存正式訂單";
  return taiwanReady ? "扣台灣現貨並建立正式出貨單" : "送出正式訂單";
}

function fifoSelfDeliverSavingText(isHold, isSelfDeliver, isInTransit) {
  if (isHold) return "正在轉正式並寄庫，之後可到寄庫名單拉出來出貨…";
  if (isSelfDeliver) return "正在完成自己送／自取…";
  if (isInTransit) return "正在建立正式出貨單並設定配送中…";
  return "正在儲存準備出貨資料…";
}

function fifoSelfDeliverJsHasHelper(adminJs) {
  return String(adminJs || "").indexOf("function adminOrderIsSelfDeliver(") !== -1;
}

function fifoSelfDeliverConfirmCompletes(adminJs) {
  return /status: isSelfDeliver \? 'delivered' : \(isInTransit \? 'shipped' : 'accepted'\)/.test(String(adminJs || ""));
}

function fifoSelfDeliverBoardHasCompleteButton(adminJs) {
  return String(adminJs || "").indexOf("data-freight-fifo-self-deliver-complete") !== -1;
}

function fifoSelfDeliverSkipsPaymentCheck(adminJs) {
  return /if \(!adminOrderIsSelfDeliver\(order, carrier\)\) checks\.push\('核對收款方式'\)/.test(String(adminJs || ""));
}

module.exports = {
  adminOrderIsSelfDeliver,
  fifoSelfDeliverUpdate,
  fifoSelfDeliverProgressNote,
  fifoSelfDeliverSubmitLabel,
  fifoSelfDeliverSavingText,
  fifoSelfDeliverJsHasHelper,
  fifoSelfDeliverConfirmCompletes,
  fifoSelfDeliverBoardHasCompleteButton,
  fifoSelfDeliverSkipsPaymentCheck,
};
