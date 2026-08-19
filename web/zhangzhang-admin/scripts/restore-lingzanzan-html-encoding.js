#!/usr/bin/env node
"use strict";

/**
 * Restore admin HTML that was corrupted by UTF-8 round-trip stamping.
 * latin1 (byte-preserving) is used for admin.js cache-bust only.
 *
 * Cache-bust: admin.js ?v=20260820-preorder-catalog-all-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const STAMP = "20260820-preorder-catalog-all-1";
const FFFD = Buffer.from("efbfbd", "hex");
const HERE = path.dirname(__filename);
const RESTORE_DIR = fs.existsSync(path.join(HERE, "html-restore"))
  ? path.join(HERE, "html-restore")
  : path.join(HERE, "scripts", "html-restore");
const AUDIT = path.join(
  ROOT,
  "data",
  "audit",
  "html-encoding-restore-" + new Date().toISOString().replace(/[:.]/g, "-")
);

const BACKUPS = {
  "admin-backup-v2.html":
    "F:/Web/lingzanzan-staging/_backups/admin-asset-version-20260808-053157/admin-backup-v2.html",
  "admin-backup.html":
    "F:/Web/lingzanzan-staging/_backups/admin-asset-version-20260808-053157/admin-backup.html",
  "admin-customer-returns.html":
    "F:/Web/lingzanzan-staging/_backups/admin-asset-version-20260808-053157/admin-customer-returns.html",
  "admin-freight.html":
    "F:/Web/lingzanzan-staging/_backups/workline-search-20260815-2/html/admin-freight.html",
  "admin-live.html":
    "F:/Web/lingzanzan-staging/_backups/customer-address-freeze-20260815-2/admin-live.html",
  "admin-logistics.html":
    "F:/Web/lingzanzan-staging/_backups/admin-asset-version-20260808-053157/admin-logistics.html",
  "admin-members.html":
    "F:/Web/lingzanzan-staging/_backups/admin-asset-version-20260808-053157/admin-members.html",
  "admin-order-tracking.html":
    "F:/Web/lingzanzan-staging/_backups/workline-search-20260815-8/admin-order-tracking.html",
  "admin-orders.html":
    "F:/Web/lingzanzan-staging/_backups/customer-address-freeze-20260815-2/admin-orders.html",
  "admin-preorder-demand.html":
    "F:/Web/lingzanzan-staging/_backups/admin-asset-version-20260808-053157/admin-preorder-demand.html",
  "admin-preorder-waiting.html":
    "F:/Web/lingzanzan-staging/_backups/workline-memory-20260815-1/admin-preorder-waiting.html",
  "admin-preorders.html":
    "F:/Web/lingzanzan-staging/_backups/customer-address-freeze-20260815-2/admin-preorders.html",
  "admin-profit.html":
    "F:/Web/lingzanzan-staging/_backups/workline-profit-20260815-10/admin-profit.html",
  "admin-purchase.html":
    "F:/Web/lingzanzan-staging/_backups/admin-asset-version-20260808-053157/admin-purchase.html",
  "admin-reserved-shipping.html":
    "F:/Web/lingzanzan-staging/_backups/admin-asset-version-20260808-053157/admin-reserved-shipping.html",
  "admin-sales.html":
    "F:/Web/lingzanzan-staging/_backups/admin-asset-version-20260808-053157/admin-sales.html",
  "admin-security.html":
    "F:/Web/lingzanzan-staging/_backups/admin-asset-version-20260808-053157/admin-security.html",
  "admin-staff.html":
    "F:/Web/lingzanzan-staging/_backups/admin-asset-version-20260808-053157/admin-staff.html",
  "admin-style-columns.html":
    "F:/Web/lingzanzan-staging/_backups/admin-asset-version-20260808-053157/admin-style-columns.html",
  "admin-suppliers.html":
    "F:/Web/lingzanzan-staging/_backups/admin-asset-version-20260808-053157/admin-suppliers.html",
  "admin-vendor-returns.html":
    "F:/Web/lingzanzan-staging/_backups/admin-asset-version-20260808-053157/admin-vendor-returns.html"
};

const OVERLAYS = [
  "admin.html",
  "admin-help.html",
  "admin-live-keyword.html",
  "admin-haohong-logistics.html"
];

function countFffd(buf) {
  let n = 0;
  for (let i = 0; i < buf.length - 2; i++) {
    if (buf[i] === 0xef && buf[i + 1] === 0xbf && buf[i + 2] === 0xbd) {
      n += 1;
      i += 2;
    }
  }
  return n;
}

function titleOf(buf) {
  const s = buf.toString("utf8");
  const m = s.match(/<title>([\s\S]*?)<\/title>/i);
  return (m ? m[1] : "").replace(/\s+/g, " ").trim();
}

function binaryStampAdminJs(file) {
  const buf = fs.readFileSync(file);
  const ascii = buf.toString("latin1");
  if (ascii.indexOf("admin.js") === -1) return false;
  const next = ascii.replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP);
  if (next === ascii) return false;
  fs.writeFileSync(file, Buffer.from(next, "latin1"));
  return true;
}

function copyFile(from, to) {
  fs.copyFileSync(from, to);
}

fs.mkdirSync(AUDIT, { recursive: true });

const liveHtml = fs.readdirSync(ROOT).filter((n) => /^admin.*\.html$/i.test(n));
liveHtml.forEach((name) => {
  const src = path.join(ROOT, name);
  copyFile(src, path.join(AUDIT, name + ".corrupt"));
});
console.log("audit", AUDIT);

Object.keys(BACKUPS).forEach((name) => {
  const dest = path.join(ROOT, name);
  const src = BACKUPS[name];
  if (!fs.existsSync(src)) throw new Error("missing backup " + src);
  if (!fs.existsSync(dest)) {
    console.log("skip missing live", name);
    return;
  }
  copyFile(src, dest);
  binaryStampAdminJs(dest);
  const buf = fs.readFileSync(dest);
  console.log("restored", name, buf.length, "fffd", countFffd(buf), "title", titleOf(buf));
});

OVERLAYS.forEach((name) => {
  const src = path.join(RESTORE_DIR, name);
  const dest = path.join(ROOT, name);
  if (!fs.existsSync(src)) throw new Error("missing overlay " + src);
  copyFile(src, dest);
  binaryStampAdminJs(dest);
  const buf = fs.readFileSync(dest);
  if (countFffd(buf)) throw new Error("overlay still has FFFD: " + name);
  if (buf.toString("utf8").indexOf("管理者登入") === -1) {
    throw new Error("overlay missing login heading: " + name);
  }
  console.log("overlay", name, buf.length, "title", titleOf(buf));
});

const leftover = liveHtml
  .filter((n) => n.indexOf("拷貝") === -1)
  .map((n) => {
    const buf = fs.readFileSync(path.join(ROOT, n));
    return { n, fffd: countFffd(buf), title: titleOf(buf) };
  })
  .filter((row) => row.fffd > 0);
if (leftover.length) {
  console.log("STILL HAS FFFD", leftover);
} else {
  console.log("no FFFD left in live admin*.html (except skipped copies)");
}

const admin = fs.readFileSync(path.join(ROOT, "admin.html"), "utf8");
if (admin.indexOf("data-login-form") === -1) throw new Error("admin.html missing login form");
if (admin.indexOf("data-sales-entry") === -1) throw new Error("admin.html missing sales entry");
if (admin.indexOf("\uFFFD") !== -1) throw new Error("admin.html still has replacement chars");
console.log("LINGZANZAN html encoding restore ok");
