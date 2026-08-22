"use strict";

const DUPLICATE_CODE = "duplicate_order";
const DUPLICATE_LABEL = "重複打單";
const DUPLICATE_OPTION_LABEL = "8. 重複打單";
const OTHER_OPTION_LABEL = "9. 其他（請填入內容）";

function returnReasonNeedsOtherDetail(code) {
  return String(code || "") === "other";
}

function returnReasonPlainLabel(label) {
  return String(label || "")
    .replace(/^\d+\.\s*/, "")
    .replace(/（[^）]*）/g, "")
    .replace(/\([^)]*\)/g, "")
    .trim();
}

function returnReasonFilledNote(code, selectedLabel, otherDetail, typedNote) {
  const typed = String(typedNote || "").trim();
  if (typed) return typed;
  if (String(code || "") === "other") {
    const detail = String(otherDetail || "").trim();
    return detail ? ("其他：" + detail) : "";
  }
  return returnReasonPlainLabel(selectedLabel);
}

function returnReasonNoteIsRequired(code) {
  return !String(code || "").trim();
}

function returnReasonIsDuplicateOrder(code) {
  return String(code || "") === DUPLICATE_CODE;
}

function returnReasonCountsTowardScore(code) {
  return [
    "wrong_address",
    "sales_error",
    "extra_item",
    "staff_goods_error",
    "duplicate_unacked",
  ].indexOf(String(code || "")) !== -1;
}

function hasDuplicateOrderReason(src) {
  return String(src || "").indexOf("value: 'duplicate_order'") !== -1
    || String(src || "").indexOf("'duplicate_order'") !== -1
    || String(src || "").indexOf('value="duplicate_order"') !== -1;
}

function withCrlf(text) {
  return String(text || "").replace(/\n/g, "\r\n");
}

function replaceSnippet(src, oldStr, newStr) {
  src = String(src || "");
  if (src.indexOf(newStr) !== -1 && src.indexOf(oldStr) === -1) return src;
  if (src.indexOf(oldStr) !== -1) return src.split(oldStr).join(newStr);
  const oldWin = withCrlf(oldStr);
  const newWin = withCrlf(newStr);
  if (src.indexOf(oldWin) !== -1) return src.split(oldWin).join(newWin);
  return src;
}

function insertDuplicateOrderJsOptions(src) {
  src = String(src || "");
  if (src.indexOf("value: 'duplicate_order'") !== -1) return src;
  const old = `{ value: 'staff_goods_error', label: '7. 小姐出錯貨物（列入評分）', score: true, party: 'staff' },
      { value: 'other', label: '8. 其他（請填入內容）', score: false, party: 'customer' }`;
  const next = `{ value: 'staff_goods_error', label: '7. 小姐出錯貨物（列入評分）', score: true, party: 'staff' },
      { value: 'duplicate_order', label: '8. 重複打單', score: false, party: 'staff' },
      { value: 'other', label: '9. 其他（請填入內容）', score: false, party: 'customer' }`;
  return replaceSnippet(src, old, next);
}

function insertDuplicateOrderPhpMap(src) {
  src = String(src || "");
  if (src.indexOf("'duplicate_order' =>") !== -1) return src;
  const old = `        'duplicate_unacked' => ['label' => '未核對重複相同訂單', 'party' => 'staff', 'score' => true],
        'other' => ['label' => '其他', 'party' => 'customer', 'score' => false],`;
  const next = `        'duplicate_unacked' => ['label' => '未核對重複相同訂單', 'party' => 'staff', 'score' => true],
        'duplicate_order' => ['label' => '重複打單', 'party' => 'staff', 'score' => false],
        'other' => ['label' => '其他', 'party' => 'customer', 'score' => false],`;
  const withUnacked = replaceSnippet(src, old, next);
  if (withUnacked !== src) return withUnacked;
  const oldNoDupUnacked = `        'staff_goods_error' => ['label' => '小姐出錯貨物', 'party' => 'staff', 'score' => true],
        'other' => ['label' => '其他', 'party' => 'customer', 'score' => false],`;
  const nextNoDupUnacked = `        'staff_goods_error' => ['label' => '小姐出錯貨物', 'party' => 'staff', 'score' => true],
        'duplicate_order' => ['label' => '重複打單', 'party' => 'staff', 'score' => false],
        'other' => ['label' => '其他', 'party' => 'customer', 'score' => false],`;
  return replaceSnippet(src, oldNoDupUnacked, nextNoDupUnacked);
}

function insertDuplicateOrderApiError(src) {
  src = String(src || "");
  const old = "請選擇退貨原因：客人取消、客人故意不領、家裡沒人、出錯地址、業務出錯、多出東西、小姐出錯貨物、其他";
  const next = "請選擇退貨原因：客人取消、客人故意不領、家裡沒人、出錯地址、業務出錯、多出東西、小姐出錯貨物、重複打單、其他";
  if (src.indexOf(next) !== -1) return src;
  if (src.indexOf(old) === -1) return src;
  return src.split(old).join(next);
}

function insertDuplicateOrderHint(src) {
  src = String(src || "");
  const old = "選「其他」請填入內容，先不列入評分，管理層再評估（例如多打單故取消）。財務系統可再改歸屬並統計報損運費。";
  const next = "選「重複打單」用於多打的單，不必再填其他原因，先不列入評分。選「其他」才要另填內容。財務系統可再改歸屬並統計報損運費。";
  return replaceSnippet(src, old, next);
}

function insertDuplicateOrderHtmlOption(src) {
  src = String(src || "");
  if (src.indexOf('value="duplicate_order"') !== -1) return src;
  const old = `<option value="staff_goods_error">7. 小姐出錯貨物（列入評分）</option>
                  <option value="other">8. 其他（請填入內容）</option>`;
  const next = `<option value="staff_goods_error">7. 小姐出錯貨物（列入評分）</option>
                  <option value="duplicate_order">8. 重複打單</option>
                  <option value="other">9. 其他（請填入內容）</option>`;
  return replaceSnippet(src, old, next);
}

module.exports = {
  DUPLICATE_CODE,
  DUPLICATE_LABEL,
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
  replaceSnippet,
};
