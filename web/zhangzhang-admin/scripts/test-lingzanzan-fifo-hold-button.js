#!/usr/bin/env node
"use strict";

const {
  fifoHoldReasonIsHold,
  fifoHoldStatusText,
  fifoHoldSaveLabel,
  fifoHoldNeedReasonMessage,
  fifoHoldJumpNote,
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
assert(fifoHoldJumpNote().indexOf("加入寄庫名單") !== -1, "section ⑤ points to the button");
assert(fifoHoldJumpNote().indexOf("②") !== -1, "section ⑤ points up to customer block");

console.log("LINGZANZAN fifo hold button tests ok");
