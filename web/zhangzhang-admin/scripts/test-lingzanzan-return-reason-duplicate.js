#!/usr/bin/env node
"use strict";

const {
  DUPLICATE_CODE,
  DUPLICATE_OPTION_LABEL,
  OTHER_OPTION_LABEL,
  returnReasonNeedsOtherDetail,
  returnReasonPlainLabel,
  returnReasonFilledNote,
  returnReasonNoteIsRequired,
  returnReasonIsDuplicateOrder,
  returnReasonCountsTowardScore,
  hasDuplicateOrderReason,
  insertDuplicateOrderJsOptions,
  insertDuplicateOrderPhpMap,
  insertDuplicateOrderApiError,
  insertDuplicateOrderHint,
  insertDuplicateOrderHtmlOption,
} = require("./lz-return-reason-duplicate");

function assert(cond, label) {
  if (!cond) throw new Error("fail: " + label);
  console.log("ok:", label);
}

assert(returnReasonIsDuplicateOrder("duplicate_order") === true, "duplicate code");
assert(returnReasonIsDuplicateOrder("other") === false, "other is not duplicate");
assert(returnReasonNeedsOtherDetail("other") === true, "other still needs fill-in");
assert(returnReasonNeedsOtherDetail(DUPLICATE_CODE) === false, "duplicate does not need other text");
assert(returnReasonNoteIsRequired("") === true, "no reason still needs a note");
assert(returnReasonNoteIsRequired("duplicate_order") === false, "selected reason does not need typed note");
assert(returnReasonFilledNote("duplicate_order", "8. 重複打單", "", "") === "重複打單", "dropdown fills note");
assert(returnReasonFilledNote("other", "9. 其他（請填入內容）", "客人改地址", "") === "其他：客人改地址", "other detail fills note");
assert(returnReasonFilledNote("duplicate_order", "8. 重複打單", "", "門市退回") === "門市退回", "typed note wins");
assert(returnReasonPlainLabel("8. 重複打單") === "重複打單", "strips option number");
assert(returnReasonCountsTowardScore(DUPLICATE_CODE) === false, "duplicate is not scored immediately");
assert(returnReasonCountsTowardScore("staff_goods_error") === true, "staff goods still scored");
assert(returnReasonCountsTowardScore("duplicate_unacked") === true, "unacked duplicate ship still scored");

const jsSrc = `      { value: 'staff_goods_error', label: '7. 小姐出錯貨物（列入評分）', score: true, party: 'staff' },
      { value: 'other', label: '8. 其他（請填入內容）', score: false, party: 'customer' }`;
const jsTwice = jsSrc + "\n" + jsSrc;
const jsPatched = insertDuplicateOrderJsOptions(jsTwice);
assert(jsPatched.indexOf(DUPLICATE_OPTION_LABEL) !== -1, "js has 8. 重複打單");
assert(jsPatched.indexOf(OTHER_OPTION_LABEL) !== -1, "js renumbers 其他 to 9");
assert((jsPatched.match(/value: 'duplicate_order'/g) || []).length === 2, "patches both js copies");
assert(insertDuplicateOrderJsOptions(jsPatched) === jsPatched, "js insert is idempotent");
assert(hasDuplicateOrderReason(jsPatched) === true, "detects js duplicate option");

const phpSrc = `        'duplicate_unacked' => ['label' => '未核對重複相同訂單', 'party' => 'staff', 'score' => true],
        'other' => ['label' => '其他', 'party' => 'customer', 'score' => false],`;
const phpPatched = insertDuplicateOrderPhpMap(phpSrc);
assert(phpPatched.indexOf("'duplicate_order' => ['label' => '重複打單'") !== -1, "php map has duplicate_order");
assert(phpPatched.indexOf("'duplicate_unacked'") !== -1, "keeps unacked penalty reason");
assert(insertDuplicateOrderPhpMap(phpPatched) === phpPatched, "php insert is idempotent");

const errSrc = "請選擇退貨原因：客人取消、客人故意不領、家裡沒人、出錯地址、業務出錯、多出東西、小姐出錯貨物、其他";
const errPatched = insertDuplicateOrderApiError(errSrc);
assert(errPatched.indexOf("小姐出錯貨物、重複打單、其他") !== -1, "api error lists 重複打單");
assert(insertDuplicateOrderApiError(errPatched) === errPatched, "api error insert is idempotent");

const hintSrc = "選「其他」請填入內容，先不列入評分，管理層再評估（例如多打單故取消）。財務系統可再改歸屬並統計報損運費。";
const hintPatched = insertDuplicateOrderHint(hintSrc);
assert(hintPatched.indexOf("選「重複打單」") !== -1, "hint names 重複打單");
assert(hintPatched.indexOf("不必再填其他原因") !== -1, "hint says no extra fill-in");

const htmlSrc = `<option value="staff_goods_error">7. 小姐出錯貨物（列入評分）</option>
                  <option value="other">8. 其他（請填入內容）</option>`;
const htmlPatched = insertDuplicateOrderHtmlOption(htmlSrc);
assert(htmlPatched.indexOf('value="duplicate_order"') !== -1, "html option added");
assert(htmlPatched.indexOf(">9. 其他（請填入內容）<") !== -1, "html other is 9");

console.log("LINGZANZAN return reason duplicate tests ok");
