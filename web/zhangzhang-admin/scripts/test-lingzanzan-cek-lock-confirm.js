"use strict";

const assert = require("assert");
const {
  cekLockConfirmCopy,
  cekLockJsUsesInPageDialog,
  cekLockCssHasDialog,
  cekLockDoesNotRestyleActive,
} = require("./lz-cek-lock-confirm");

const zh = cekLockConfirmCopy("zh");
const id = cekLockConfirmCopy("id");
assert.strictEqual(zh.yes, "確定封鎖");
assert.strictEqual(zh.no, "取消");
assert.ok(zh.title.indexOf("封鎖") !== -1);
assert.strictEqual(id.yes, "Yakin kunci");
assert.strictEqual(id.no, "Batal");

const js = [
  "function askCekLockConfirm(opts, onYes) {}",
  "data-cek-lock-yes",
  "data-cek-lock-cancel",
  "確定封鎖",
  "askCekLockConfirm({ name: name, phone: phone }, function () { submitCekLock(lockBtn, phone, name, reason); });",
].join("\n");
assert.strictEqual(cekLockJsUsesInPageDialog(js), true);
assert.strictEqual(cekLockJsUsesInPageDialog("if (!window.confirm(t().lockConfirm)) return;"), false);

const css = ".cek-lock-dialog{}\n.cek-lock-yes{}\n.cek-lock-no{}";
assert.strictEqual(cekLockCssHasDialog(css), true);
assert.strictEqual(cekLockDoesNotRestyleActive(css), true);
assert.strictEqual(cekLockDoesNotRestyleActive(".is-active{background:gold}"), false);

console.log(JSON.stringify({ ok: true, tests: 8 }));
