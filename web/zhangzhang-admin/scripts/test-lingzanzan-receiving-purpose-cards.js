#!/usr/bin/env node
"use strict";

const assert = require("assert");
const fs = require("fs");
const os = require("os");
const path = require("path");
const { spawnSync } = require("child_process");

const FIXER = path.join(__dirname, "fix-lingzanzan-receiving-purpose-cards.js");
const LIVE_JS = "/tmp/admin.js";
const LIVE_CSS = "/tmp/admin.css";
const LIVE_HTML = "/tmp/freight.html";

assert.strictEqual(fs.existsSync(LIVE_JS), true, "live admin.js dump");
assert.strictEqual(fs.existsSync(LIVE_CSS), true, "live admin.css dump");

const liveJs = fs.readFileSync(LIVE_JS, "utf8");
const liveCss = fs.readFileSync(LIVE_CSS, "utf8");

assert.ok(liveJs.indexOf("name=\"freight-receiving-goods-purpose\"") !== -1, "purpose radios exist");
assert.ok(liveJs.indexOf("createElement('label');\n    purposePanel.className = 'freight-receiving-stock-purpose'") !== -1, "live purpose panel is a label");
assert.ok(liveCss.indexOf("appearance:auto!important;-webkit-appearance:radio!important") !== -1, "live native radios");
assert.ok(liveCss.indexOf("goods-purpose label.is-selected b:after{") !== -1, "garbled selected after exists");
assert.ok(liveCss.indexOf("\\5DF2\\9078") === -1, "live not already patched");

const root = fs.mkdtempSync(path.join(os.tmpdir(), "lz-receiving-purpose-"));
fs.mkdirSync(path.join(root, "assets"), { recursive: true });
fs.copyFileSync(LIVE_JS, path.join(root, "assets", "admin.js"));
fs.copyFileSync(LIVE_CSS, path.join(root, "assets", "admin.css"));
if (fs.existsSync(LIVE_HTML)) {
  fs.writeFileSync(path.join(root, "admin-freight.html"), fs.readFileSync(LIVE_HTML, "utf8"));
}

const run = spawnSync(process.execPath, [FIXER], {
  env: Object.assign({}, process.env, { LINGZANZAN_ROOT: root }),
  encoding: "utf8",
});
assert.strictEqual(run.status, 0, "fixer exit\n" + run.stdout + "\n" + run.stderr);
assert.ok(run.stdout.indexOf("LINGZANZAN receiving purpose cards ok") !== -1, run.stdout);

const patchedJs = fs.readFileSync(path.join(root, "assets", "admin.js"), "utf8");
const patchedCss = fs.readFileSync(path.join(root, "assets", "admin.css"), "utf8");

assert.ok(patchedJs.indexOf("createElement('div');\n    purposePanel.className = 'freight-receiving-stock-purpose'") !== -1, "panel is div");
assert.ok(patchedJs.indexOf("createElement('label');\n    purposePanel.className = 'freight-receiving-stock-purpose'") === -1, "label panel gone");
assert.ok(patchedJs.indexOf("event.preventDefault();\n        radio.checked = true;") !== -1, "card click preventDefault");
assert.ok(patchedCss.indexOf("appearance:none!important") !== -1, "native radios hidden");
assert.ok(patchedCss.indexOf("\\5DF2\\9078") !== -1, "selected uses unicode escape");
assert.ok(patchedCss.indexOf(".freight-receiving-routing .freight-receiving-stock-purpose[hidden]") !== -1, "hidden panel stays hidden");
new Function(patchedJs);

if (fs.existsSync(path.join(root, "admin-freight.html"))) {
  const stamped = fs.readFileSync(path.join(root, "admin-freight.html"), "latin1");
  assert.ok(stamped.indexOf("admin.js?v=20260921-receiving-purpose-1") !== -1, "html js stamp");
}

const rerun = spawnSync(process.execPath, [FIXER], {
  env: Object.assign({}, process.env, { LINGZANZAN_ROOT: root }),
  encoding: "utf8",
});
assert.strictEqual(rerun.status, 0, "idempotent fixer\n" + rerun.stdout + "\n" + rerun.stderr);
assert.ok(/already:/.test(rerun.stdout), "second run is already");

console.log(JSON.stringify({
  ok: true,
  tests: 12,
  stamp: "20260921-receiving-purpose-1",
}));
