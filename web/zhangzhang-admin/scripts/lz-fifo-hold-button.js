"use strict";

function fifoHoldReasonIsHold(code) {
  code = String(code || "").trim();
  return !!(code && code !== "scheduled_ship");
}

function fifoHoldStatusText(code, reasonText, reasonLabel) {
  if (!fifoHoldReasonIsHold(code)) return "尚未寄庫";
  var label = String(code === "other" ? reasonText : reasonLabel || reasonText || "").trim();
  return label ? "此單已在寄庫名單／" + label : "此單已在寄庫名單";
}

function fifoHoldSaveLabel(alreadyHold) {
  return alreadyHold ? "更新寄庫原因" : "加入寄庫名單";
}

function fifoHoldNeedReasonMessage() {
  return "寄庫請先選擇原因。「預約指定出貨日」是預約排單，不是寄庫。";
}

function fifoHoldJumpNote() {
  return "寄庫在上面②「超商出貨／住家出貨」欄下面，選原因後按「加入寄庫名單」。這裡只填物流公司與單號。";
}

function fifoHoldPanelTitle() {
  return "寄庫（先不出貨）";
}

function fifoHoldIsUnderShipping(adminJs) {
  return /data-freight-fifo-address' \}\) \+ '<div class="freight-fifo-reservation"/.test(String(adminJs || ""));
}

function fifoHoldIsAfterShare(adminJs) {
  return /data-freight-fifo-share>LINE[\s\S]{0,80}<div class="freight-fifo-reservation"/.test(String(adminJs || ""));
}

module.exports = {
  fifoHoldReasonIsHold,
  fifoHoldStatusText,
  fifoHoldSaveLabel,
  fifoHoldNeedReasonMessage,
  fifoHoldJumpNote,
  fifoHoldPanelTitle,
  fifoHoldIsUnderShipping,
  fifoHoldIsAfterShare,
};
