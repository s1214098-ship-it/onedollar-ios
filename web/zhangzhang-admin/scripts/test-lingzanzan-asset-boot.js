#!/usr/bin/env node
"use strict";

const {
  BOOT_SRC,
  shouldTouchFile,
  transformHtml,
  isTransformed,
} = require("./lz-asset-html");

function assert(cond, label) {
  if (!cond) throw new Error("fail: " + label);
  console.log("ok:", label);
}

const sample = [
  "<!doctype html>",
  "<html>",
  "<head>",
  '  <link rel="icon" href="./assets/brand-icon-192.png">',
  '  <link rel="stylesheet" href="./assets/admin.css?v=20260821-member-search-1">',
  '  <link rel="stylesheet" href="./assets/admin-navigation.css?v=old" data-admin-navigation-style>',
  "</head>",
  "<body>",
  '  <script src="./assets/admin-labels.js?v=login-1"></script>',
  '  <script src="./assets/admin.js?v=20260821-member-search-1" defer></script>',
  "</body>",
  "</html>",
  "",
].join("\n");

assert(shouldTouchFile("admin.html", sample), "admin.html is in scope");
assert(!shouldTouchFile("admin-backup.html", sample), "backup html is skipped");
assert(!shouldTouchFile("design-options.html", sample), "design html is skipped");
assert(!shouldTouchFile("admin.html", "<html></html>"), "html without admin assets is skipped");
assert(shouldTouchFile("business.html", '<script src="./assets/business.js?v=1"></script>'), "business.js pages are in scope");
assert(shouldTouchFile("admin-products.html", '<link rel="stylesheet" href="./assets/admin-products-computer.css?v=1">'), "product admin pages are in scope");
assert(!shouldTouchFile("cart.html", '<script src="./assets/cart.js?v=1"></script>'), "storefront cart is skipped");

const once = transformHtml(sample);
assert(once.indexOf(BOOT_SRC) !== -1, "boot script is inserted");
assert(once.indexOf('<script src="./asset-boot.php"></script>') !== -1, "boot tag is exact");
assert(once.indexOf("brand-icon-192.png") !== -1, "png icon stays");
assert(once.indexOf('href="./assets/admin.css?v=') === -1, "css query stamp is removed");
assert(once.indexOf('rel="lz-asset"') !== -1, "css becomes lz-asset");
assert(once.indexOf('data-lz-href="./assets/admin.css"') !== -1, "css path kept without version");
assert(once.indexOf('data-admin-navigation-style') !== -1, "extra css attrs kept");
assert(once.indexOf('type="lz/asset"') !== -1, "js becomes lz/asset");
assert(once.indexOf('data-lz-src="./assets/admin.js"') !== -1, "admin.js path kept");
assert(/\bdefer\b/.test(once) && once.indexOf('data-lz-src="./assets/admin.js"') !== -1, "defer kept on admin.js");
assert(!/[\s]src=["']\.\/assets\/admin\.js/.test(once), "browser no longer auto-loads old admin.js");
assert(isTransformed(once), "transformed html is detected");

const twice = transformHtml(once);
assert(twice.split(BOOT_SRC).length === 2, "second pass does not insert a second boot");
assert((twice.match(/type="lz\/asset"/g) || []).length === (once.match(/type="lz\/asset"/g) || []).length, "second pass does not duplicate scripts");

const crlf = sample.replace(/\n/g, "\r\n");
const crlfOut = transformHtml(crlf);
assert(crlfOut.indexOf("\r\n  <script src=\"./asset-boot.php\"></script>") !== -1, "crlf pages keep crlf after head");

console.log("all asset boot html tests passed");
