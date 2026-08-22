"use strict";

const FIFO_LINE_PASTE_BLOCKED_PHONES = ["0937476065"];
const FIFO_LINE_PASTE_BLOCKED_IDS = ["BYORDER-20260720-078596"];
const FIFO_UNSHIPPED_PRINT_STAGES = ["waiting", "selected", "wait_notify", "formal"];

function fifoRowMemberPhone(row) {
  row = row || {};
  const customer = row.customer || {};
  return String(customer.phone || row.customerPhone || row.phone || "").replace(/\D/g, "");
}

function fifoRowMemberName(row) {
  row = row || {};
  const customer = row.customer || {};
  return String(customer.name || row.customerName || customer.displayName || "").trim();
}

function fifoRowMemberId(row) {
  row = row || {};
  return String(row.id || row.sourceInquiryId || row.convertedFromInquiryId || row.inquiryId || "");
}

function fifoLinePasteBlocked(row) {
  const phone = fifoRowMemberPhone(row);
  const id = fifoRowMemberId(row);
  if (phone && FIFO_LINE_PASTE_BLOCKED_PHONES.indexOf(phone) !== -1) return true;
  if (id && FIFO_LINE_PASTE_BLOCKED_IDS.indexOf(id) !== -1) return true;
  return false;
}

function fifoUnshippedMemberNames(printRows) {
  printRows = printRows || {};
  const seen = Object.create(null);
  const names = [];
  FIFO_UNSHIPPED_PRINT_STAGES.forEach(function (stage) {
    (printRows[stage] || []).forEach(function (row) {
      if (!row || fifoLinePasteBlocked(row)) return;
      const name = fifoRowMemberName(row);
      if (!name || name === "未填客戶") return;
      const phone = fifoRowMemberPhone(row);
      const key = phone || name.toLowerCase();
      if (seen[key]) return;
      seen[key] = true;
      names.push(name);
    });
  });
  return names;
}

function fifoUnshippedNamesLine(names) {
  return (names || []).join("，");
}

function fifoUnshippedNamesCopiedToast(count) {
  return "已複製 " + count + " 位還沒出貨名字，可直接貼 LINE";
}

function fifoPrintHasUnshippedNamesBox(html) {
  return String(html || "").indexOf("data-unshipped-names") !== -1
    || String(html || "").indexOf("還沒出貨會員") !== -1;
}

function fifoBoardHasCopyUnshippedNames(adminJs) {
  return String(adminJs || "").indexOf("data-freight-fifo-copy-unshipped-names") !== -1;
}

module.exports = {
  FIFO_LINE_PASTE_BLOCKED_PHONES,
  FIFO_LINE_PASTE_BLOCKED_IDS,
  FIFO_UNSHIPPED_PRINT_STAGES,
  fifoRowMemberPhone,
  fifoRowMemberName,
  fifoLinePasteBlocked,
  fifoUnshippedMemberNames,
  fifoUnshippedNamesLine,
  fifoUnshippedNamesCopiedToast,
  fifoPrintHasUnshippedNamesBox,
  fifoBoardHasCopyUnshippedNames,
};
