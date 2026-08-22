#!/usr/bin/env node
"use strict";

/**
 * 豪鴻已簽收：批次狀態列與包裹表都標「已簽收」，待簽收也標清楚。
 * Cache-bust: admin-haohong-logistics.js/css ?v=20260820-signed-label-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const PAGES = process.env.HH_PAGES || [
  path.join(__dirname, "..", "lingzanzan-pages"),
  path.join(__dirname, "lingzanzan-pages"),
].find((dir) => fs.existsSync(path.join(dir, "admin-haohong-logistics.js")));
const STAMP = "20260820-signed-label-1";

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
  if (!fs.existsSync(src)) throw new Error("missing " + srcName);
  if (fs.existsSync(dest)) backup(dest, "signed-label");
  fs.copyFileSync(src, dest);
  console.log("copied", destRel, fs.statSync(dest).size);
}

if (!PAGES) throw new Error("missing lingzanzan-pages");
if (!fs.existsSync(path.join(ROOT, "assets", "admin.js"))) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

copyUtf8("haohong-logistics-api.php", "haohong-logistics-api.php");
copyUtf8("admin-haohong-logistics.js", "assets/admin-haohong-logistics.js");
copyUtf8("admin-haohong-logistics.css", "assets/admin-haohong-logistics.css");

const htmlFile = path.join(ROOT, "admin-haohong-logistics.html");
const html = fs.readFileSync(htmlFile, "latin1");
let next = html
  .replace(/admin-haohong-logistics\.js(?:\?v=[^"']+)?/g, "admin-haohong-logistics.js?v=" + STAMP)
  .replace(/admin-haohong-logistics\.css(?:\?v=[^"']+)?/g, "admin-haohong-logistics.css?v=" + STAMP);
if (next !== html) {
  fs.writeFileSync(htmlFile, Buffer.from(next, "latin1"));
  console.log("stamped admin-haohong-logistics.html");
}

const js = fs.readFileSync(path.join(ROOT, "assets", "admin-haohong-logistics.js"), "utf8");
const css = fs.readFileSync(path.join(ROOT, "assets", "admin-haohong-logistics.css"), "utf8");
const api = fs.readFileSync(path.join(ROOT, "haohong-logistics-api.php"), "utf8");
if (js.indexOf("haohong-status-pill") === -1) throw new Error("status pill missing");
if (css.indexOf(".haohong-status-pill") === -1) throw new Error("pill css missing");
if (api.indexOf("hh_table_status_display") === -1) throw new Error("php status display missing");
if (api.indexOf("function hh_table_compare_label") === -1) throw new Error("compare helper missing");
console.log("LINGZANZAN HaoHong signed label ok", STAMP);
