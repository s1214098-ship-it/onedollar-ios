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
  return "寄庫與預約出貨在上面「② 客戶資料」下面，選原因後按「加入寄庫名單」。這裡只填物流公司與單號。";
}

module.exports = {
  fifoHoldReasonIsHold,
  fifoHoldStatusText,
  fifoHoldSaveLabel,
  fifoHoldNeedReasonMessage,
  fifoHoldJumpNote,
};
