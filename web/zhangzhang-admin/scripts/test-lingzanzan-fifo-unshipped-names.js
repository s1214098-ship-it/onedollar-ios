#!/usr/bin/env node
"use strict";

const assert = require("assert");
const {
  fifoLinePasteBlocked,
  fifoUnshippedMemberNames,
  fifoUnshippedNamesLine,
  fifoUnshippedNamesCopiedToast,
  fifoPrintHasUnshippedNamesBox,
  fifoBoardHasCopyUnshippedNames,
} = require("./lz-fifo-unshipped-names");

const rows = {
  waiting: [
    { id: "A1", customer: { name: "ANITA(FORMOSZZA BAE)", phone: "0909545375" } },
    { id: "A2", customer: { name: "ANITA(FORMOSZZA BAE)", phone: "0909545375" } },
  ],
  selected: [
    { id: "B1", customerName: "Winda Ribby-TK", customerPhone: "0900324537" },
  ],
  wait_notify: [
    { id: "C1", customer: { name: "等通知客人", phone: "0911111111" } },
  ],
  formal: [
    { id: "D1", customer: { name: "核對中客人", phone: "0922222222" } },
  ],
  transit: [
    { id: "E1", customer: { name: "配送中不該出現", phone: "0933333333" } },
  ],
};

const names = fifoUnshippedMemberNames(rows);
assert.deepStrictEqual(names, [
  "ANITA(FORMOSZZA BAE)",
  "Winda Ribby-TK",
  "等通知客人",
  "核對中客人",
]);
assert.strictEqual(names.indexOf("配送中不該出現"), -1);
assert.strictEqual(fifoUnshippedNamesLine(names), "ANITA(FORMOSZZA BAE)，Winda Ribby-TK，等通知客人，核對中客人");

assert.strictEqual(fifoLinePasteBlocked({
  id: "BYORDER-20260720-078596",
  customer: { name: "Blocked", phone: "0937476065" },
}), true);
assert.strictEqual(fifoLinePasteBlocked({
  customer: { name: "Blocked", phone: "0937476065" },
}), true);

const withBlocked = fifoUnshippedMemberNames({
  selected: [
    { id: "BYORDER-20260720-078596", customer: { name: "Blocked", phone: "0937476065" } },
    { id: "OK1", customer: { name: "可出的客人", phone: "0988888888" } },
  ],
  transit: [{ id: "T1", customer: { name: "已出", phone: "0977777777" } }],
});
assert.deepStrictEqual(withBlocked, ["可出的客人"]);

assert.strictEqual(fifoUnshippedMemberNames({ waiting: [{ customer: { name: "未填客戶", phone: "0900000000" } }] }).length, 0);
assert.strictEqual(fifoUnshippedNamesCopiedToast(9), "已複製 9 位還沒出貨名字，可直接貼 LINE");
assert.strictEqual(fifoPrintHasUnshippedNamesBox('<textarea data-unshipped-names>A，B</textarea>'), true);
assert.strictEqual(fifoBoardHasCopyUnshippedNames('data-freight-fifo-copy-unshipped-names'), true);

console.log("LINGZANZAN fifo unshipped names tests ok");
