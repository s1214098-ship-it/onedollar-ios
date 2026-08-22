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

function fifoHoldOverlapCssMarker() {
  return "/* 20260822 fifo hold overlap: keep 寄庫 inside ②, do not cover ④ */";
}

function fifoHoldOverlapCss() {
  return `
${fifoHoldOverlapCssMarker()}
.freight-fifo-modal-grid {
  align-items: start;
  grid-template-columns: minmax(0, 0.7fr) minmax(0, 1.3fr);
}
[data-freight-fifo-hold-panel],
.freight-fifo-detail > .freight-fifo-reservation {
  min-width: 0 !important;
  max-width: 100% !important;
  width: 100%;
  box-sizing: border-box;
  grid-column: 1 / -1;
}
[data-freight-fifo-hold-panel] > div,
.freight-fifo-detail > .freight-fifo-reservation > div {
  display: grid !important;
  grid-template-columns: minmax(0, 1fr) !important;
  min-width: 0 !important;
  max-width: 100% !important;
}
[data-freight-fifo-hold-panel] .reservation-date-picker,
.freight-fifo-detail > .freight-fifo-reservation .reservation-date-picker {
  grid-template-columns: minmax(0, 1fr) auto !important;
}
[data-freight-fifo-hold-panel] .reservation-date-picker input,
.freight-fifo-detail > .freight-fifo-reservation .reservation-date-picker input {
  min-width: 0 !important;
  width: 100%;
}
`;
}

function fifoHoldHasOverlapFix(css) {
  css = String(css || "");
  return css.indexOf(fifoHoldOverlapCssMarker()) !== -1
    && css.indexOf("[data-freight-fifo-hold-panel] > div") !== -1
    && /grid-template-columns:\s*minmax\(0,\s*1fr\)/.test(css)
    && /grid-template-columns:\s*minmax\(0,\s*0\.7fr\)/.test(css);
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
  fifoHoldOverlapCssMarker,
  fifoHoldOverlapCss,
  fifoHoldHasOverlapFix,
};
