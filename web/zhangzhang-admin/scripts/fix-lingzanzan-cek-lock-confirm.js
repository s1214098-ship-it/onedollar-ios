#!/usr/bin/env node
"use strict";

/**
 * 查貨頁按黑名單要先跳出確認：確定封鎖才鎖，取消不鎖。
 * 不用瀏覽器 confirm（手機／PWA 常看不到）。不改 .is-active 金鈕。
 *
 * Cache-bust: cek-kirim.js/css ?v=20260822-cek-lock-1
 */

const fs = require("fs");
const path = require("path");
const {
  cekLockJsUsesInPageDialog,
  cekLockCssHasDialog,
  cekLockDoesNotRestyleActive,
} = require("./lz-cek-lock-confirm");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const PAGES = process.env.CEK_PAGES || [
  path.join(__dirname, "lingzanzan-pages"),
  path.join(__dirname, "..", "lingzanzan-pages"),
].find((dir) => fs.existsSync(path.join(dir, "cek-kirim.js")));
const STAMP = "20260822-cek-lock-1";

if (!PAGES) throw new Error("missing lingzanzan-pages/cek-kirim.js");

function backup(file, tag) {
  const dir = path.join(ROOT, "data", "audit");
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(
    dir,
    path.basename(file) + "." + tag + "-" + new Date().toISOString().replace(/[:.]/g, "-")
  );
  if (fs.existsSync(file)) fs.copyFileSync(file, dest);
  return dest;
}

function copyUtf8(srcName, destRel) {
  const src = path.join(PAGES, srcName);
  const dest = path.join(ROOT, destRel);
  const destDir = path.dirname(dest);
  if (!fs.existsSync(destDir)) fs.mkdirSync(destDir, { recursive: true });
  fs.copyFileSync(src, dest);
  console.log("copied", destRel, fs.statSync(dest).size);
}

const srcJs = fs.readFileSync(path.join(PAGES, "cek-kirim.js"), "utf8");
const srcCss = fs.readFileSync(path.join(PAGES, "cek-kirim.css"), "utf8");
const srcHtml = fs.readFileSync(path.join(PAGES, "cek.html"), "utf8");
if (!cekLockJsUsesInPageDialog(srcJs)) throw new Error("source js missing in-page lock confirm");
if (!cekLockCssHasDialog(srcCss)) throw new Error("source css missing lock dialog");
if (srcHtml.indexOf("cek-kirim.js?v=" + STAMP) === -1) throw new Error("source html stamp missing");
if (!cekLockDoesNotRestyleActive(srcCss.slice(srcCss.indexOf(".cek-lock-dialog")))) {
  throw new Error("lock dialog css restyles .is-active");
}

if (!fs.existsSync(path.join(ROOT, "cek.html"))) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

backup(path.join(ROOT, "assets", "cek-kirim.js"), "cek-lock");
backup(path.join(ROOT, "assets", "cek-kirim.css"), "cek-lock");
backup(path.join(ROOT, "cek.html"), "cek-lock");

copyUtf8("cek-kirim.js", "assets/cek-kirim.js");
copyUtf8("cek-kirim.css", "assets/cek-kirim.css");
copyUtf8("cek.html", "cek.html");
copyUtf8("cek.html", "sales-phone.html");

const liveJs = fs.readFileSync(path.join(ROOT, "assets", "cek-kirim.js"), "utf8");
const liveCss = fs.readFileSync(path.join(ROOT, "assets", "cek-kirim.css"), "utf8");
const liveHtml = fs.readFileSync(path.join(ROOT, "cek.html"), "utf8");
if (!cekLockJsUsesInPageDialog(liveJs)) throw new Error("live js missing in-page lock confirm");
if (!cekLockCssHasDialog(liveCss)) throw new Error("live css missing lock dialog");
if (liveHtml.indexOf("cek-kirim.js?v=" + STAMP) === -1) throw new Error("live html stamp missing");
if (liveHtml.indexOf("\uFFFD") !== -1) throw new Error("cek html has FFFD");
console.log("LINGZANZAN cek lock confirm ok", STAMP);
