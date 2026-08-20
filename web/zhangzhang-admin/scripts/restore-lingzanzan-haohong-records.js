#!/usr/bin/env node
"use strict";

/**
 * Put the 豪鴻紀錄／對照 entry back after the encoding restore used an older
 * freight HTML that dropped the later Haohong sync panel and sidebar link.
 *
 * UTF-8 HTML I/O: admin-freight.html is valid UTF-8 after the encoding restore.
 * Do not latin1-write Chinese; that truncates code units and garbles the page.
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const STAMP = "20260820-haohong-records-1";
const FREIGHT = path.join(ROOT, "admin-freight.html");
const ADMIN = path.join(ROOT, "admin.html");
const AUDIT = path.join(ROOT, "data", "audit");

const NEW_PANEL = `<section class="freight-haohong-sync-panel" data-freight-haohong-sync-panel data-haohong-sync-panel>
            <header>
              <div>
                <small>HAOHONG PACKAGE &amp; ORDER SYNC</small>
                <h3>豪鴻包裹與集運訂單紀錄</h3>
                <p>登入後可記住帳號。先同步豪鴻「我的包裹」與「我的訂單」，同一物流單號會對上後台；同步後可帶入單號與重量計費。也可把後台單號預報到豪鴻，或把公司品名寫回豪鴻。</p>
              </div>
              <div class="panel-actions">
                <a class="ghost-button" href="./admin-haohong-logistics.html">打開豪鴻集運對照表</a>
                <a class="ghost-button" href="https://member.haohong56.com/#/myBag" target="_blank" rel="noopener">開啟豪鴻我的包裹</a>
              </div>
            </header>
            <div class="freight-haohong-sync-controls">
              <label>豪鴻帳號<input autocomplete="username" data-haohong-sync-username placeholder="豪鴻會員帳號"></label>
              <label>豪鴻密碼<input type="password" autocomplete="current-password" data-haohong-sync-password placeholder="已記住可留空"></label>
              <label class="freight-haohong-remember"><input type="checkbox" data-haohong-sync-remember checked>記住登入</label>
              <button type="button" class="primary-button" data-haohong-sync-load>同步我的包裹與集運訂單</button>
              <button type="button" class="ghost-button" data-haohong-register-missing>後台單號預報到豪鴻</button>
              <button type="button" class="ghost-button" data-haohong-name-sync>公司品名同步到豪鴻</button>
            </div>
            <div class="freight-haohong-sync-status" data-haohong-sync-status>正在讀取已記住的豪鴻登入…</div>
            <div class="freight-haohong-sync-preview" data-haohong-sync-preview></div>
          </section>`;

function backup(file, tag) {
  if (!fs.existsSync(AUDIT)) fs.mkdirSync(AUDIT, { recursive: true });
  const dest = path.join(
    AUDIT,
    path.basename(file) + "." + tag + "-" + new Date().toISOString().replace(/[:.]/g, "-")
  );
  fs.copyFileSync(file, dest);
  return dest;
}

function utfReplace(file, from, to, label) {
  const html = fs.readFileSync(file, "utf8");
  if (html.indexOf(to) !== -1 && html.indexOf(from) === -1) {
    console.log("already:", label);
    return html;
  }
  if (html.indexOf(from) === -1) throw new Error("missing snippet: " + label);
  const next = html.split(from).join(to);
  fs.writeFileSync(file, next, "utf8");
  console.log("patched:", label);
  return next;
}

function replaceHaohongPanel(file) {
  const html = fs.readFileSync(file, "utf8");
  if (html.indexOf("豪鴻包裹與集運訂單紀錄") !== -1 && html.indexOf("data-haohong-register-missing") !== -1) {
    console.log("already: freight haohong records panel");
    return;
  }
  const startNeedle = '<section class="freight-haohong-sync-panel"';
  const start = html.indexOf(startNeedle);
  if (start < 0) throw new Error("missing haohong sync section");
  const end = html.indexOf("</section>", start);
  if (end < 0) throw new Error("missing haohong sync section end");
  const next = html.slice(0, start) + NEW_PANEL + html.slice(end + "</section>".length);
  fs.writeFileSync(file, next, "utf8");
  console.log("patched: freight haohong records panel");
}

function stampNav(file) {
  const html = fs.readFileSync(file, "latin1");
  const next = html.replace(
    /admin-navigation\.js(?:\?v=[^"']+)?/g,
    "admin-navigation.js?v=" + STAMP
  );
  if (next !== html) {
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    console.log("stamped nav", path.basename(file));
  }
}

const GOOD_FREIGHT_CANDIDATES = [
  "F:/Web/lingzanzan-staging/data/audit/admin-freight.html.haohong-records-2026-08-20T03-37-11-388Z",
  "F:/Web/lingzanzan-staging/_backups/workline-search-20260815-2/html/admin-freight.html"
];

if (!fs.existsSync(FREIGHT)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

if (fs.existsSync(FREIGHT)) {
  const cur = fs.readFileSync(FREIGHT, "utf8");
  if (cur.indexOf("從豪鴻") === -1 && cur.indexOf("豪鴻包裹與集運訂單紀錄") === -1) {
    const good = GOOD_FREIGHT_CANDIDATES.find((p) => fs.existsSync(p));
    if (!good) throw new Error("no utf8 freight backup to revert");
    fs.copyFileSync(good, FREIGHT);
    console.log("reverted damaged freight html from", good);
  }
}

console.log("backup freight", backup(FREIGHT, "haohong-records"));
console.log("backup admin", backup(ADMIN, "haohong-records"));

replaceHaohongPanel(FREIGHT);

utfReplace(
  FREIGHT,
  `<a class="ghost-button" data-freight-view-stocked href="./admin-freight.html?view=tracking&amp;stage=stocked">已入庫</a>
              <a class="primary-button freight-batch-quick-link" href="#freight-batch-workbench">建立新批號／加入批次</a>`,
  `<a class="ghost-button" data-freight-view-stocked href="./admin-freight.html?view=tracking&amp;stage=stocked">已入庫</a>
              <a class="ghost-button" href="./admin-haohong-logistics.html">豪鴻集運對照</a>
              <a class="primary-button freight-batch-quick-link" href="#freight-batch-workbench">建立新批號／加入批次</a>`,
  "freight view switch 豪鴻對照"
);

utfReplace(
  FREIGHT,
  `<a href="./admin-freight.html?view=tracking&amp;stage=stocked">已入庫</a>
        <a href="./admin-live.html">直播打單</a>`,
  `<a href="./admin-freight.html?view=tracking&amp;stage=stocked">已入庫</a>
        <a href="./admin-haohong-logistics.html">豪鴻集運對照</a>
        <a href="./admin-live.html">直播打單</a>`,
  "freight sidebar 豪鴻對照"
);

const adminHtml = fs.readFileSync(ADMIN, "utf8");
if (adminHtml.indexOf("admin-haohong-logistics.html") === -1) {
  utfReplace(
    ADMIN,
    `<a href="./admin-freight.html">物流集運</a>
        <a href="./admin-profit.html">營銷毛利</a>`,
    `<a href="./admin-freight.html">物流集運</a>
        <a href="./admin-haohong-logistics.html">豪鴻集運對照</a>
        <a href="./admin-profit.html">營銷毛利</a>`,
    "admin.html sidebar 豪鴻對照"
  );
} else {
  console.log("already: admin.html sidebar 豪鴻對照");
}

stampNav(FREIGHT);
stampNav(ADMIN);

const live = fs.readFileSync(FREIGHT, "utf8");
if (live.indexOf("豪鴻包裹與集運訂單紀錄") === -1) throw new Error("panel title missing");
if (live.indexOf("admin-haohong-logistics.html") === -1) throw new Error("對照 link missing");
if (live.indexOf("\uFFFD") !== -1) throw new Error("freight html has FFFD");
if (!fs.existsSync(path.join(ROOT, "admin-haohong-logistics.html"))) {
  throw new Error("missing admin-haohong-logistics.html");
}
console.log("LINGZANZAN haohong records restore ok", STAMP);
