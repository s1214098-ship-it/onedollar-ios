#!/usr/bin/env node
"use strict";

const assert = require("assert");
const fs = require("fs");
const os = require("os");
const path = require("path");
const { spawnSync } = require("child_process");

const FIXER = path.join(__dirname, "fix-lingzanzan-haohong-placeholder-quantity.js");
const LIVE_JS = "/tmp/admin.js";
const LIVE_CSS = "/tmp/admin.css";
const LIVE_HTML = "/tmp/freight.html";

function count(hay, needle) {
  if (!needle) return 0;
  let n = 0;
  let from = 0;
  while (true) {
    const i = hay.indexOf(needle, from);
    if (i < 0) return n;
    n += 1;
    from = i + needle.length;
  }
}

function assertUnique(hay, needle, label) {
  const n = count(hay, needle);
  assert.strictEqual(n, 1, label + " should be unique, got " + n);
}

function freightVariantLines(item) {
  item = item && typeof item === "object" ? item : {};
  if (Array.isArray(item.variants) && item.variants.length) return item.variants;
  var hasLegacyLine = !!String(item.category || item.color || item.colorName || item.size || item.sizeName || item.sampleBarcode || item.taiwanBarcode || "").trim();
  return hasLegacyLine ? [item] : [];
}

const placeholder = {
  id: "HAOHONG-PACKAGE-100120753314",
  productName: "廣州王大家",
  trackingNo: "100120753314",
  variants: null,
  quantity: 1,
  progress: "到貨待分類",
  logisticsSource: "haohong",
};

assert.strictEqual(freightVariantLines(placeholder).length, 0, "haohong package has no sku lines");
assert.strictEqual(
  freightVariantLines({ color: "黑色", size: "M", quantity: 2 }).length,
  1,
  "legacy color/size still counts"
);

assert.strictEqual(fs.existsSync(LIVE_JS), true, "live admin.js dump");
assert.strictEqual(fs.existsSync(LIVE_CSS), true, "live admin.css dump");

const liveJs = fs.readFileSync(LIVE_JS, "utf8");
const liveCss = fs.readFileSync(LIVE_CSS, "utf8");

assertUnique(liveJs, "原物流沒有可追加的產品規格，請先到產品物流明細核對", "early return");
assertUnique(liveJs, "本張實際收到合計", "quantity total copy");
assertUnique(liveJs, "function toast(message) {", "toast fn");
assert.ok(liveJs.indexOf("data-freight-quantity-extra-open") !== -1, "gold extra button exists");
assert.ok(liveJs.indexOf("has-placeholder-source") === -1, "live not already patched");
assert.ok(liveCss.indexOf(".admin-toast") === -1, "live freight toast has no css");

const root = fs.mkdtempSync(path.join(os.tmpdir(), "lz-haohong-placeholder-"));
fs.mkdirSync(path.join(root, "assets"), { recursive: true });
fs.copyFileSync(LIVE_JS, path.join(root, "assets", "admin.js"));
fs.copyFileSync(LIVE_CSS, path.join(root, "assets", "admin.css"));
if (fs.existsSync(LIVE_HTML)) {
  const html = fs.readFileSync(LIVE_HTML, "utf8");
  fs.writeFileSync(path.join(root, "admin-freight.html"), html);
}

const run = spawnSync(process.execPath, [FIXER], {
  env: Object.assign({}, process.env, { LINGZANZAN_ROOT: root }),
  encoding: "utf8",
});
assert.strictEqual(run.status, 0, "fixer exit\n" + run.stdout + "\n" + run.stderr);
assert.ok(run.stdout.indexOf("LINGZANZAN haohong placeholder quantity ok") !== -1, run.stdout);

const patchedJs = fs.readFileSync(path.join(root, "assets", "admin.js"), "utf8");
const patchedCss = fs.readFileSync(path.join(root, "assets", "admin.css"), "utf8");

assert.ok(patchedJs.indexOf("原物流沒有可追加的產品規格") === -1, "early return removed");
assert.ok(patchedJs.indexOf("has-placeholder-source") !== -1, "placeholder class added");
assert.ok(patchedJs.indexOf("這張還沒有顏色尺寸可點貨") !== -1, "quantity empty copy");
assert.ok(patchedJs.indexOf("el.style.zIndex = '30000'") !== -1, "toast z-index");
assert.ok(patchedJs.indexOf("請先新增實際收到的產品，再核對數量") !== -1, "save refuse toast");
assert.ok(patchedJs.indexOf("((request.body && request.body.lines) || []).length > 0") !== -1, "skip empty groups");
assert.ok(patchedJs.indexOf("不要填廠商名稱") !== -1, "placeholder title not prefilled");
assert.ok(patchedCss.indexOf("z-index: 30000") !== -1, "css toast above modal");
assert.ok(patchedCss.indexOf(".admin-toast") !== -1, "admin-toast styled");

new Function(patchedJs);

if (fs.existsSync(path.join(root, "admin-freight.html"))) {
  const stamped = fs.readFileSync(path.join(root, "admin-freight.html"), "latin1");
  assert.ok(stamped.indexOf("admin.js?v=20260921-haohong-placeholder-1") !== -1, "html js stamp");
  assert.ok(stamped.indexOf("admin.css?v=20260921-haohong-placeholder-1") !== -1, "html css stamp");
}

const rerun = spawnSync(process.execPath, [FIXER], {
  env: Object.assign({}, process.env, { LINGZANZAN_ROOT: root }),
  encoding: "utf8",
});
assert.strictEqual(rerun.status, 0, "idempotent fixer\n" + rerun.stdout + "\n" + rerun.stderr);
assert.ok(/already:/.test(rerun.stdout), "second run is already");

console.log(JSON.stringify({
  ok: true,
  tests: 18,
  stamp: "20260921-haohong-placeholder-1",
  placeholderLines: freightVariantLines(placeholder).length,
}));
