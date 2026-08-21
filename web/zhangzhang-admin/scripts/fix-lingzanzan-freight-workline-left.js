#!/usr/bin/env node
"use strict";

/**
 * 集運頁三種工作列（自取／拼多多／豪鴻）原本在 FIFO 出貨清單下面，
 * 寬螢幕會以為左邊工作列不見。移到頁首下方，並在寬螢幕做成左側欄。
 * 到貨核對「＋ 新增其他款」補單功能不改，仍在核對視窗右上。
 *
 * Cache-bust: admin.css / admin-freight.html ?v=20260821-workline-left-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const STAMP = "20260821-workline-left-1";
const CSS_MARKER = "/* 20260821 freight workline left rail */";
const HTML = path.join(ROOT, "admin-freight.html");
const CSS = path.join(ROOT, "assets", "admin.css");

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

const CSS_PATCH = `
${CSS_MARKER}
@media (min-width: 1280px) {
  #freight.panel {
    display: grid;
    grid-template-columns: minmax(268px, 320px) minmax(0, 1fr);
    align-items: start;
    column-gap: 16px;
  }
  #freight > .panel-head {
    grid-column: 1 / -1;
  }
  #freight > .freight-workline-board {
    grid-column: 1;
    grid-row: 2;
    position: sticky;
    top: 12px;
    margin: 0 0 18px;
    max-height: calc(100vh - 24px);
    overflow: auto;
  }
  #freight > .freight-workline-board ~ * {
    grid-column: 2;
  }
  #freight .freight-workline-row {
    grid-template-columns: 44px minmax(0, 1fr);
    min-height: 0;
  }
  #freight .freight-workline-metrics,
  #freight .freight-workline-open {
    grid-column: 2;
    justify-self: start;
  }
  #freight .freight-workline-board > header {
    display: grid;
  }
  #freight .freight-workline-board > header > strong {
    justify-self: start;
  }
}
`;

function moveWorkline(html) {
  const startKey = '<section class="freight-workline-board"';
  const destKey = '<section class="freight-receiving-lookup"';
  const start = html.indexOf(startKey);
  const dest = html.indexOf(destKey);
  if (start < 0) throw new Error("missing workline board");
  if (dest < 0) throw new Error("missing receiving lookup");
  if (start < dest) {
    console.log("already: workline before receiving lookup");
    return html;
  }
  const endTag = "</section>";
  const end = html.indexOf(endTag, start);
  if (end < 0) throw new Error("missing workline close");
  let after = end + endTag.length;
  if (html[after] === "\r") after += 1;
  if (html[after] === "\n") after += 1;
  const block = html.slice(start, after);
  const next = html.slice(0, start) + html.slice(after);
  const dest2 = next.indexOf(destKey);
  if (dest2 < 0) throw new Error("receiving lookup lost after cut");
  console.log("patched: move workline above receiving/FIFO");
  return next.slice(0, dest2) + block + (block.endsWith("\n") ? "" : "\n") + next.slice(dest2);
}

if (!fs.existsSync(HTML)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup html", backup(HTML, "workline-left"));
let html = fs.readFileSync(HTML, "latin1");
html = moveWorkline(html);
html = html.replace(/admin\.css(?:\?v=[^"']+)?/g, "admin.css?v=" + STAMP);
fs.writeFileSync(HTML, Buffer.from(html, "latin1"));
console.log("html written", fs.statSync(HTML).size);

console.log("backup css", backup(CSS, "workline-left"));
let css = fs.readFileSync(CSS, "utf8");
if (css.indexOf(CSS_MARKER) === -1) {
  fs.writeFileSync(CSS, css.replace(/\s*$/, "") + "\n" + CSS_PATCH);
  console.log("css patched");
} else {
  console.log("css already patched");
}

const check = fs.readFileSync(HTML, "latin1");
const worklineAt = check.indexOf('<section class="freight-workline-board"');
const recvAt = check.indexOf('<section class="freight-receiving-lookup"');
console.log("order", { worklineAt, recvAt, ok: worklineAt >= 0 && worklineAt < recvAt });
console.log("done", STAMP);
