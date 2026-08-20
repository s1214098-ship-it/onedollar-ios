#!/usr/bin/env node
"use strict";

/**
 * Phone-only logistics lookup for sales who will not use a password.
 * Live page: /cek.html  (alias /sales-phone.html)
 * Uses existing customer-shipping-lookup-api.php (read-only).
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const PAGES = process.env.CEK_PAGES || [
  path.join(__dirname, "..", "lingzanzan-pages"),
  path.join(__dirname, "lingzanzan-pages")
].find((dir) => fs.existsSync(path.join(dir, "cek.html")));
const STAMP = "20260820-sales-phone-1";

if (!PAGES) throw new Error("missing lingzanzan-pages/cek.html next to install script");

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
  if (!fs.existsSync(src)) throw new Error("missing " + src);
  const destDir = path.dirname(dest);
  if (!fs.existsSync(destDir)) fs.mkdirSync(destDir, { recursive: true });
  fs.copyFileSync(src, dest);
  console.log("copied", destRel, fs.statSync(dest).size);
}

function replaceOnce(src, oldStr, newStr, label, alreadyNeedle) {
  if ((alreadyNeedle && src.indexOf(alreadyNeedle) !== -1) || src.indexOf(newStr) !== -1) {
    console.log("already:", label);
    return src;
  }
  const i = src.indexOf(oldStr);
  if (i < 0) throw new Error("missing snippet: " + label);
  if (src.indexOf(oldStr, i + oldStr.length) !== -1) throw new Error("not unique: " + label);
  console.log("patched:", label);
  return src.slice(0, i) + newStr + src.slice(i + oldStr.length);
}

const LOGIN_OLD = `    <div class="front-entry">
      <b>Masuk halaman depan tanpa password</b>
      <div class="front-links">
        <a href="./stock-inquiry.html?sales=lingzanzan&source=sales-login-front">LINGZANZAN</a>
        <a href="./stock-inquiry.html?sales=any&source=sales-login-front">ANY</a>
      </div>
    </div>`;

const LOGIN_NEW = `    <a class="cek-entry" href="./cek.html" style="display:grid;gap:6px;padding:18px;border:1px solid #f0bd54;border-radius:18px;background:rgba(240,189,84,.16);text-decoration:none">
      <strong style="color:#fff8ef;font-size:22px">Cek Kirim · 查貨不用登入</strong>
      <span style="color:#eadde8;font-weight:700;line-height:1.45">Ketik telepon atau nomor resi. Tidak perlu akun / password.</span>
    </a>
    <div class="front-entry">
      <b>Masuk halaman depan tanpa password</b>
      <div class="front-links">
        <a href="./stock-inquiry.html?sales=lingzanzan&source=sales-login-front">LINGZANZAN</a>
        <a href="./stock-inquiry.html?sales=any&source=sales-login-front">ANY</a>
      </div>
    </div>`;

const BUSINESS_OLD = `      <a class="pro-card command-card command-watch" href="./shipping-watch.html" style="display:block;margin:0 0 16px;padding:16px 18px;border:1px solid rgba(240,189,84,.4);border-radius:18px;background:#2a1a16;text-decoration:none">
        <span style="color:#f0bd54;font-weight:900">HP · 14 HARI</span>
        <h3 style="margin:6px 0 4px;color:#fff8ed">Lacak pengiriman</h3>
        <p class="muted" style="margin:0">Sedang disiapkan, dalam pengiriman, belum diterima, bermasalah. Status barang memakai Bahasa Indonesia.</p>
      </a>`;

const BUSINESS_NEW = `      <a class="pro-card command-card command-watch" href="./cek.html" style="display:block;margin:0 0 16px;padding:16px 18px;border:1px solid rgba(240,189,84,.4);border-radius:18px;background:#2a1a16;text-decoration:none">
        <span style="color:#f0bd54;font-weight:900">HP · TANPA LOGIN</span>
        <h3 style="margin:6px 0 4px;color:#fff8ed">Cek Kirim / 查貨</h3>
        <p class="muted" style="margin:0">Tidak perlu akun. Ketik telepon atau nomor resi, lalu lihat status pengiriman.</p>
      </a>
      <a class="pro-card command-card command-watch" href="./shipping-watch.html" style="display:block;margin:0 0 16px;padding:16px 18px;border:1px solid rgba(240,189,84,.4);border-radius:18px;background:#2a1a16;text-decoration:none">
        <span style="color:#f0bd54;font-weight:900">HP · 14 HARI</span>
        <h3 style="margin:6px 0 4px;color:#fff8ed">Lacak pengiriman</h3>
        <p class="muted" style="margin:0">Sedang disiapkan, dalam pengiriman, belum diterima, bermasalah. Status barang memakai Bahasa Indonesia.</p>
      </a>`;

if (!fs.existsSync(path.join(ROOT, "customer-shipping-lookup-api.php"))) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

copyUtf8("cek.html", "cek.html");
copyUtf8("cek.html", "sales-phone.html");
copyUtf8("cek.webmanifest", "cek.webmanifest");
copyUtf8("cek-kirim.css", "assets/cek-kirim.css");
copyUtf8("cek-kirim.js", "assets/cek-kirim.js");

const loginFile = path.join(ROOT, "sales-login.html");
console.log("backup sales-login", backup(loginFile, "sales-phone"));
let login = fs.readFileSync(loginFile, "utf8");
login = replaceOnce(login, LOGIN_OLD, LOGIN_NEW, "sales-login cek entry", "Cek Kirim · 查貨不用登入");
fs.writeFileSync(loginFile, login, "utf8");

const businessFile = path.join(ROOT, "business.html");
console.log("backup business", backup(businessFile, "sales-phone"));
let business = fs.readFileSync(businessFile, "utf8");
business = replaceOnce(business, BUSINESS_OLD, BUSINESS_NEW, "business.html cek card", "HP · TANPA LOGIN");
fs.writeFileSync(businessFile, business, "utf8");

const live = fs.readFileSync(path.join(ROOT, "cek.html"), "utf8");
if (live.indexOf("cek-kirim.js?v=" + STAMP) === -1) throw new Error("cek stamp missing");
if (live.indexOf("\uFFFD") !== -1) throw new Error("cek html has FFFD");
if (fs.readFileSync(loginFile, "utf8").indexOf("./cek.html") === -1) throw new Error("sales-login missing cek link");
console.log("LINGZANZAN sales phone cek page ok", STAMP);
