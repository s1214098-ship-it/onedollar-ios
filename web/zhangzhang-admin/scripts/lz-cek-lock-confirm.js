"use strict";

function cekLockConfirmCopy(lang) {
  if (lang === "id") {
    return {
      title: "Yakin kunci daftar hitam?",
      yes: "Yakin kunci",
      no: "Batal",
    };
  }
  return {
    title: "確定要封鎖這個客人嗎？",
    yes: "確定封鎖",
    no: "取消",
  };
}

function cekLockJsUsesInPageDialog(js) {
  const src = String(js || "");
  return src.indexOf("function askCekLockConfirm(") !== -1
    && src.indexOf("data-cek-lock-yes") !== -1
    && src.indexOf("data-cek-lock-cancel") !== -1
    && src.indexOf("確定封鎖") !== -1
    && src.indexOf("window.confirm(t().lockConfirm") === -1;
}

function cekLockCssHasDialog(css) {
  const src = String(css || "");
  return src.indexOf(".cek-lock-dialog") !== -1
    && src.indexOf(".cek-lock-yes") !== -1
    && src.indexOf(".cek-lock-no") !== -1;
}

function cekLockDoesNotRestyleActive(snippet) {
  return String(snippet || "").indexOf(".is-active") === -1;
}

module.exports = {
  cekLockConfirmCopy,
  cekLockJsUsesInPageDialog,
  cekLockCssHasDialog,
  cekLockDoesNotRestyleActive,
};
