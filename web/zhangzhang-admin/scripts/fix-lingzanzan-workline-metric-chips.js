#!/usr/bin/env node
"use strict";

/**
 * 集運三種工作列的待處理／批次／運送中／已入庫／異常
 * 原本寫成一整串「待處理 22｜批次 0｜…」，窄螢幕連成一條。
 * 改成獨立格子，可換行。
 *
 * Cache-bust: admin.js / admin.css ?v=20260821-workline-chips-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const ADMIN_CSS = path.join(ROOT, "assets", "admin.css");
const STAMP = "20260821-workline-chips-1";
const JS_MARKER = "freight-workline-metric is-";
const CSS_MARKER = "/* 20260821 workline metric chips */";

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

function replaceOnce(src, oldStr, newStr, label) {
  if (src.indexOf(newStr) !== -1) {
    console.log("already:", label);
    return src;
  }
  let from = oldStr;
  let to = newStr;
  let i = src.indexOf(from);
  if (i < 0) {
    from = oldStr.replace(/\n/g, "\r\n");
    to = newStr.replace(/\n/g, "\r\n");
    i = src.indexOf(from);
  }
  if (i < 0) throw new Error("missing snippet: " + label);
  if (src.indexOf(from, i + from.length) !== -1) throw new Error("not unique: " + label);
  console.log("patched:", label);
  return src.slice(0, i) + to + src.slice(i + from.length);
}

const METRICS_OLD = `      if (host) host.textContent = '待處理 ' + metrics.waiting + '｜批次 ' + metrics.batches + '｜運送中 ' + metrics.inTransit + '｜已入庫 ' + metrics.stocked + '｜異常 ' + metrics.issues;`;

const METRICS_NEW = `      if (host) host.innerHTML = [
        { k: 'wait', l: '待處理', n: metrics.waiting },
        { k: 'batch', l: '批次', n: metrics.batches },
        { k: 'transit', l: '運送中', n: metrics.inTransit },
        { k: 'stock', l: '已入庫', n: metrics.stocked },
        { k: 'issue', l: '異常', n: metrics.issues }
      ].map(function (item) {
        var hot = item.k === 'issue' && Number(item.n) > 0 ? ' is-hot' : '';
        return '<span class="freight-workline-metric is-' + item.k + hot + '"><small>' + item.l + '</small><b>' + item.n + '</b></span>';
      }).join('');`;

const CSS_PATCH = `
${CSS_MARKER}
.freight-workline-metrics {
  display: flex;
  flex-wrap: wrap;
  align-items: stretch;
  gap: 6px;
  width: 100%;
  padding: 0;
  border-radius: 0;
  background: transparent;
  font-size: inherit;
  font-weight: inherit;
  line-height: inherit;
}
.freight-workline-metric {
  display: grid;
  gap: 1px;
  flex: 1 1 64px;
  min-width: 58px;
  padding: 6px 8px;
  border-radius: 10px;
  background: rgba(6, 10, 13, .55);
  text-align: left;
}
.freight-workline-metric small {
  color: #c9c1ca;
  font-size: .68rem;
  font-weight: 800;
  letter-spacing: .04em;
}
.freight-workline-metric b {
  color: #fff;
  font-size: 1.08rem;
  font-weight: 950;
  line-height: 1.15;
}
.freight-workline-metric.is-issue.is-hot {
  background: rgba(239, 123, 131, .2);
}
.freight-workline-metric.is-issue.is-hot b {
  color: #ffb3b8;
}
.freight-workline-metric.is-transit b {
  color: #9fd7ff;
}
.freight-workline-metric.is-stock b {
  color: #8fe8d6;
}
@media (min-width: 1280px) {
  #freight .freight-workline-metrics {
    grid-column: 2;
    justify-self: stretch;
  }
}
@media (max-width: 760px) {
  .freight-workline-metrics {
    grid-column: 1 / -1;
  }
}
`;

function stampHtml(dir) {
  const names = [
    "admin-freight.html",
    "admin-orders.html",
    "admin-preorders.html",
    "admin-live.html",
    "admin-reserved-shipping.html",
    "admin.html",
  ];
  let n = 0;
  names.forEach((name) => {
    const file = path.join(dir, name);
    if (!fs.existsSync(file)) return;
    const html = fs.readFileSync(file, "latin1");
    if (html.indexOf("admin.js") === -1 && html.indexOf("admin.css") === -1) return;
    const next = html
      .replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP)
      .replace(/admin\.css(?:\?v=[^"']+)?/g, "admin.css?v=" + STAMP);
    if (next === html) return;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n += 1;
    console.log("stamped", name);
  });
  console.log("html stamped", n);
}

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(ADMIN_JS, "workline-chips"));
let js = fs.readFileSync(ADMIN_JS, "utf8");
if (js.indexOf(JS_MARKER) === -1) {
  js = replaceOnce(js, METRICS_OLD, METRICS_NEW, "workline metric chips");
} else {
  console.log("js already patched");
}
fs.writeFileSync(ADMIN_JS, js);
console.log("admin.js written", js.length);

console.log("backup css", backup(ADMIN_CSS, "workline-chips"));
let css = fs.readFileSync(ADMIN_CSS, "utf8");
if (css.indexOf(CSS_MARKER) === -1) {
  css = css.replace(/\s*$/, "") + "\n" + CSS_PATCH;
  console.log("css chips");
} else {
  console.log("css already");
}
fs.writeFileSync(ADMIN_CSS, css);

stampHtml(ROOT);
if (js.indexOf(JS_MARKER) === -1) throw new Error("metric chips missing after patch");
console.log("done", STAMP);
