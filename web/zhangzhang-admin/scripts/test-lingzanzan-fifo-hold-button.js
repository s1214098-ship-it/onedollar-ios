#!/usr/bin/env node
"use strict";

const {
  fifoHoldReasonIsHold,
  fifoHoldStatusText,
  fifoHoldSaveLabel,
  fifoHoldNeedReasonMessage,
  fifoHoldJumpNote,
  fifoHoldPanelTitle,
  fifoHoldShortcutLabel,
  fifoHoldIsUnderShipping,
  fifoHoldIsInSection5,
  fifoHoldHasShortcut,
  fifoHoldHasFooterSave,
  fifoHoldIsAfterShare,
  fifoShipConfirmIgnoresHold,
  fifoHoldOnlyFromHoldSave,
  fifoFormalSubmitKeepsShipLabel,
  fifoHoldOverlapCss,
  fifoHoldHasOverlapFix,
} = require("./lz-fifo-hold-button");

function assert(cond, label) {
  if (!cond) throw new Error("fail: " + label);
  console.log("ok:", label);
}

assert(fifoHoldReasonIsHold("wait_live") === true, "wait_live is 寄庫");
assert(fifoHoldReasonIsHold("sales_no_ship") === true, "sales_no_ship is 寄庫");
assert(fifoHoldReasonIsHold("other") === true, "other is 寄庫");
assert(fifoHoldReasonIsHold("") === false, "empty is not 寄庫");
assert(fifoHoldReasonIsHold("scheduled_ship") === false, "scheduled_ship is 預約 not 寄庫");

assert(fifoHoldStatusText("", "", "") === "尚未寄庫", "empty status");
assert(fifoHoldStatusText("scheduled_ship", "", "預約指定出貨日") === "尚未寄庫", "schedule is not 已在寄庫");
assert(fifoHoldStatusText("wait_live", "", "等待直播") === "此單已在寄庫名單／等待直播", "wait_live status includes reason");
assert(fifoHoldStatusText("other", "客人出國", "") === "此單已在寄庫名單／客人出國", "other uses free text");
assert(fifoHoldStatusText("wait_other", "", "") === "此單已在寄庫名單", "hold without label still shows 已在寄庫");

assert(fifoHoldSaveLabel(false) === "加入寄庫名單", "new hold button");
assert(fifoHoldSaveLabel(true) === "更新寄庫原因", "existing hold button");
assert(fifoHoldNeedReasonMessage().indexOf("預約指定出貨日") !== -1, "error distinguishes 預約");
assert(fifoHoldJumpNote().indexOf("加入寄庫名單") !== -1, "copy mentions 加入寄庫名單");
assert(fifoHoldJumpNote().indexOf("⑤") !== -1, "copy points at section ⑤");
assert(fifoHoldPanelTitle() === "寄庫（先不出貨）", "title matches 寄庫 wording");
assert(fifoHoldShortcutLabel() === "寄庫（先不出貨）", "shortcut uses the same 寄庫 title");

const afterShare = `data-freight-fifo-share>LINE 傳送／出貨小卡</button></div></div><div class="freight-fifo-reservation"`;
const underShip = `}, { textarea: true, hiddenAttrs: 'data-freight-fifo-address' }) + '<div class="freight-fifo-reservation"`;
assert(fifoHoldIsAfterShare(afterShare) === true, "detects hold after LINE share");
assert(fifoHoldIsUnderShipping(underShip) === true, "detects hold under 超商／住家");
assert(fifoHoldIsUnderShipping(afterShare) === false, "share placement is not shipping placement");
assert(fifoHoldIsAfterShare(underShip) === false, "shipping placement is not share placement");

const section5 = `<h4 class="freight-fifo-shipping-title">⑤ 正式出貨資料</h4><div class="freight-fifo-reservation" data-freight-fifo-hold-panel>`;
assert(fifoHoldIsInSection5(section5) === true, "detects hold in section ⑤");
assert(fifoHoldIsInSection5(underShip) === false, "under-shipping html is not section ⑤");
assert(fifoHoldHasShortcut('data-freight-fifo-hold-jump-btn') === true, "detects ② shortcut");
assert(fifoHoldHasFooterSave('data-freight-fifo-hold-footer') === true, "detects footer 加入寄庫名單");

const overlapCss = fifoHoldOverlapCss();
assert(fifoHoldHasOverlapFix(overlapCss) === true, "overlap css is self-detecting");
assert(fifoHoldHasOverlapFix(".freight-fifo-reservation > div { grid-template-columns: minmax(320px, .9fr) minmax(260px, 1.1fr); }") === false, "old two-col reservation is not the overlap fix");
assert(overlapCss.indexOf("minmax(0, 1fr)") !== -1, "hold fields stay one column");
assert(overlapCss.indexOf("minmax(0, 0.7fr)") !== -1, "modal left column can shrink");
assert(overlapCss.indexOf("minmax(320px") === -1, "hold panel does not keep 320px min column");

assert(fifoShipConfirmIgnoresHold("confirm") === true, "出貨鈕 ignores 寄庫 dropdown");
assert(fifoShipConfirmIgnoresHold("handoff") === true, "已交寄 checkbox still ships");
assert(fifoShipConfirmIgnoresHold("hold-save") === false, "加入寄庫名單 is the hold action");
assert(fifoHoldOnlyFromHoldSave("hold-save") === true, "only hold-save writes 寄庫");
assert(fifoHoldOnlyFromHoldSave("confirm") === false, "confirm is not 寄庫");
assert(fifoFormalSubmitKeepsShipLabel(true, "儲存正式訂單") === "儲存正式訂單", "hold selected still shows 出貨 copy");
assert(fifoFormalSubmitKeepsShipLabel(true, "扣台灣現貨並建立正式出貨單") === "扣台灣現貨並建立正式出貨單", "ready-to-ship copy stays");

console.log("LINGZANZAN fifo hold button tests ok");
