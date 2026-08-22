#!/usr/bin/env node
"use strict";

/**
 * 後台 JS/CSS 改由 asset-boot.php 依檔案時間載入。
 * 偵測到新版本後自動重整套用，不必再按「套用」。
 *
 * 不改 admin.js 業務邏輯。HTML 只做 latin1 ASCII 取代。
 */

const fs = require("fs");
const path = require("path");
const { shouldTouchFile, transformHtml } = require("./lz-asset-html");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const PHP_FILES = ["asset-version-lib.php", "asset-version.php", "asset-boot.php"];
const HERE = (function () {
  const candidates = [
    process.env.LZ_ASSET_PHP_DIR,
    path.join(__dirname, "..", "lingzanzan-pages"),
    path.join(__dirname, "lingzanzan-pages"),
    __dirname,
  ].filter(Boolean);
  for (let i = 0; i < candidates.length; i += 1) {
    if (fs.existsSync(path.join(candidates[i], "asset-boot.php"))) return candidates[i];
  }
  return candidates[1];
})();

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

if (!fs.existsSync(path.join(ROOT, "assets", "admin.js"))) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

PHP_FILES.forEach(function (name) {
  const src = path.join(HERE, name);
  const dest = path.join(ROOT, name);
  if (!fs.existsSync(src)) throw new Error("missing " + src);
  if (fs.existsSync(dest)) backup(dest, "asset-boot-php");
  fs.copyFileSync(src, dest);
  console.log("copied", name);
});

const names = fs.readdirSync(ROOT).filter(function (name) {
  return /\.html$/i.test(name);
});
let n = 0;
let skipped = 0;
names.forEach(function (name) {
  const file = path.join(ROOT, name);
  if (!fs.existsSync(file) || !fs.statSync(file).isFile()) return;
  const html = fs.readFileSync(file, "latin1");
  if (!shouldTouchFile(name, html)) {
    skipped += 1;
    return;
  }
  const next = transformHtml(html);
  if (next === html) {
    console.log("unchanged", name);
    return;
  }
  backup(file, "asset-boot-html");
  fs.writeFileSync(file, Buffer.from(next, "latin1"));
  n += 1;
  console.log("transformed", name);
});

const admin = fs.readFileSync(path.join(ROOT, "admin.html"), "latin1");
if (admin.indexOf("./asset-boot.php") === -1) throw new Error("admin.html missing boot");
if (/[\s]src=["']\.\/assets\/admin\.js/.test(admin)) throw new Error("admin.html still auto-loads admin.js");
if (admin.indexOf('data-lz-src="./assets/admin.js"') === -1) throw new Error("admin.html missing lz admin.js");

console.log(JSON.stringify({ ok: true, transformed: n, skipped: skipped }));
