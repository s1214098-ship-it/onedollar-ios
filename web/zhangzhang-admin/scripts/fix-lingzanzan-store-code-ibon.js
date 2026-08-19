#!/usr/bin/env node
"use strict";

/**
 * 7-11 同一間店常有好幾種店號（emap POIID / ibon / 舊交貨便）。
 * emap SearchStore 只吃 POIID，所以 254971 查不到，欄位又被 overflow 切成 2549。
 * 改成 emap 找不到就走 ibon，店號欄改成整列顯示，查到就帶入店名。
 *
 * Cache-bust: admin.css / address-helper.css / address-helper.js / admin.js
 * ?v=20260819-store-code-1
 */

const fs = require("fs");
const path = require("path");
const { execFileSync } = require("child_process");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_CSS = path.join(ROOT, "assets", "admin.css");
const HELPER_CSS = path.join(ROOT, "assets", "address-helper.css");
const HELPER_JS = path.join(ROOT, "assets", "address-helper.js");
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const API = path.join(ROOT, "address-helper-api.php");
const STAMP = "20260819-store-code-1";
const CSS_MARKER = "/* 20260819 store code: show 6-digit numbers and resolved labels */";
const PHP_MARKER = "function ah_lookup_seven_ibon(";
const JS_MARKER = "function isResolvedStoreLabel(";
const PHP_BIN = process.env.LZ_PHP || "C:\\PHP82\\php.exe";

function backup(file, tag) {
  const dir = path.join(ROOT, "data", "audit");
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(
    dir,
    path.basename(file) + "." + tag + "-" + new Date().toISOString().replace(/[:.]/g, "-")
  );
  fs.copyFileSync(file, dest);
  return dest;
}

function replaceOnce(src, oldStr, newStr, label) {
  if (src.indexOf(newStr) !== -1 && src.indexOf(oldStr) === -1) {
    console.log("already:", label);
    return src;
  }
  const i = src.indexOf(oldStr);
  if (i < 0) throw new Error("missing snippet: " + label);
  if (src.indexOf(oldStr, i + oldStr.length) !== -1) throw new Error("not unique: " + label);
  console.log("patched:", label);
  return src.slice(0, i) + newStr + src.slice(i + oldStr.length);
}

function appendCss(file) {
  let css = fs.readFileSync(file, "utf8");
  if (css.includes(CSS_MARKER)) {
    css = css.replace(/\n\/\* 20260819 store code:[\s\S]*$/m, "");
  }
  fs.writeFileSync(file, css.replace(/\s*$/, "") + "\n" + CSS_PATCH);
  console.log("css patched", file, fs.statSync(file).size);
}

const CSS_PATCH = `
${CSS_MARKER}
.customer-shipping-option {
  overflow: visible;
}
.customer-store-address-row {
  grid-template-columns: minmax(0, 1fr) !important;
  align-items: stretch !important;
}
.customer-store-address-row [data-customer-store-address] {
  min-width: 12ch !important;
  width: 100% !important;
  max-width: 100% !important;
  box-sizing: border-box !important;
  font-size: 16px !important;
  letter-spacing: 0 !important;
  overflow: visible !important;
  text-overflow: clip !important;
  white-space: nowrap !important;
}
.customer-store-address-row [data-address-store-search],
.customer-store-search-button {
  justify-self: start;
  width: auto;
}
`;

const STORE_NUMBER_OLD = `function ah_store_number(string $text): string {
    if (preg_match('/(?<!\\d)(\\d{4,6})(?!\\d)/u', $text, $match) !== 1) return '';
    return str_pad($match[1], 6, '0', STR_PAD_LEFT);
}

function ah_lookup_seven(string $storeNo): ?array {`;

const STORE_NUMBER_NEW = `function ah_store_number(string $text): string {
    if (preg_match('/(?<!\\d)(\\d{4,6})(?!\\d)/u', $text, $match) !== 1) return '';
    return $match[1];
}

function ah_lookup_seven_ibon(string $storeNo): ?array {
    $body = ah_http('https://www.ibon.com.tw/mobile/retail_inquiry_showinfo.aspx?store_ID=' . rawurlencode($storeNo), [
        'headers' => ['Referer: https://www.ibon.com.tw/', 'User-Agent: Mozilla/5.0'],
    ]);
    if (!$body) return null;
    $text = trim(preg_replace('/\\s+/u', ' ', strip_tags($body)) ?? '');
    if (preg_match('/(\\S+)\\s+門市資訊\\s+店號：\\s*(\\d{4,6})\\s+地址：\\s*(\\S.+?)\\s+查看Google Map/u', $text, $match) !== 1) return null;
    $label = trim($match[1]);
    if ($label === '' || $label === '搜尋' || $match[2] === '') return null;
    return ['chain' => '7-11', 'storeNo' => $storeNo, 'storeName' => $label, 'address' => trim($match[3])];
}

function ah_lookup_seven(string $storeNo): ?array {`;

const SEVEN_LOOKUP_OLD = `    if (!$body || preg_match('/<POIName>(?:<!\\[CDATA\\[)?(.*?)(?:\\]\\]>)?<\\/POIName>/su', $body, $name) !== 1) return null;
    $label = trim(strip_tags(html_entity_decode($name[1], ENT_QUOTES | ENT_XML1, 'UTF-8')));
    if ($label === '') return null;
    return ['chain' => '7-11', 'storeNo' => $storeNo, 'storeName' => $label];
}`;

const SEVEN_LOOKUP_NEW = `    if ($body && preg_match('/<POIName>(?:<!\\[CDATA\\[)?(.*?)(?:\\]\\]>)?<\\/POIName>/su', $body, $name) === 1) {
        $label = trim(strip_tags(html_entity_decode($name[1], ENT_QUOTES | ENT_XML1, 'UTF-8')));
        if ($label !== '') return ['chain' => '7-11', 'storeNo' => $storeNo, 'storeName' => $label];
    }
    return ah_lookup_seven_ibon($storeNo);
}`;

const FAMILY_CALL_OLD = `        if ($chainHint !== '7-11') {
            $row = ah_lookup_family($storeNo);
            if ($row) $stores[] = $row;
        }`;

const FAMILY_CALL_NEW = `        if ($chainHint !== '7-11') {
            $familyNo = str_pad($storeNo, 6, '0', STR_PAD_LEFT);
            $row = ah_lookup_family($familyNo);
            if ($row) $stores[] = $row;
        }`;

const HELPER_FN_OLD = `  function isStoreField(input) {
    return !!(input && input.matches && input.matches('[data-customer-store-address]'));
  }`;

const HELPER_FN_NEW = `  function isStoreField(input) {
    return !!(input && input.matches && input.matches('[data-customer-store-address]'));
  }

  function isResolvedStoreLabel(text) {
    return /^(?:7-11|全家)\\s+\\S+\\(\\d{4,6}\\)$/u.test(String(text || '').trim());
  }`;

const HELPER_LOOKUP_OLD = `    if (text.length < 4) {
      helper.innerHTML = isStoreField(input)
        ? '<span class="address-helper-idle"><b>查詢門市</b> 請輸入 4～6 碼店號，再按「查詢門市」</span>'
        : '';
      return;
    }`;

const HELPER_LOOKUP_NEW = `    if (text.length < 4) {
      helper.innerHTML = isStoreField(input)
        ? '<span class="address-helper-idle"><b>查詢門市</b> 請輸入完整店號後按「查詢門市」</span>'
        : '';
      return;
    }
    if (isStoreField(input) && isResolvedStoreLabel(text)) {
      helper.innerHTML = '<span class="address-helper-idle"><b>已帶入</b> ' + esc(text) + '</span>';
      return;
    }`;

const STORE_CONTROL_OLD = `    var storeControl = '<input data-customer-store-address placeholder="輸入店號，例如 275233" value="' + escapeHtml(split.storeAddress) + '">';`;

const STORE_CONTROL_NEW = `    var storeControl = '<input data-customer-store-address maxlength="80" spellcheck="false" autocomplete="off" placeholder="輸入完整店號，例如 254971" value="' + escapeHtml(split.storeAddress) + '">';`;

const STORE_HINT_OLD = `      + '<small>輸入 4～6 碼店號後按「查詢門市」</small>'`;

const STORE_HINT_NEW = `      + '<small>輸入完整 6 碼店號後按「查詢門市」。7-11 同一間店可能有好幾種店號，查到就會帶入店名。</small>'`;

function stampHtml(dir) {
  const names = fs.readdirSync(dir).filter((name) => /\.html$/i.test(name));
  let n = 0;
  for (const name of names) {
    const file = path.join(dir, name);
    let html = fs.readFileSync(file, "latin1");
    if (!/admin\.css|address-helper\.css|address-helper\.js|admin\.js/.test(html)) continue;
    const next = html
      .replace(/admin\.css(?:\?v=[^"']+)?/g, `admin.css?v=${STAMP}`)
      .replace(/address-helper\.css(?:\?v=[^"']+)?/g, `address-helper.css?v=${STAMP}`)
      .replace(/address-helper\.js(?:\?v=[^"']+)?/g, `address-helper.js?v=${STAMP}`)
      .replace(/admin\.js(?:\?v=[^"']+)?/g, `admin.js?v=${STAMP}`);
    if (next === html) continue;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n++;
    console.log("stamped", name);
  }
  console.log("html stamped", n);
}

function patchPhp() {
  console.log("backup api", backup(API, "store-code"));
  let php = fs.readFileSync(API, "utf8");
  if (php.includes(PHP_MARKER)) {
    console.log("php already patched");
    return;
  }
  php = replaceOnce(php, STORE_NUMBER_OLD, STORE_NUMBER_NEW, "store number + ibon");
  php = replaceOnce(php, SEVEN_LOOKUP_OLD, SEVEN_LOOKUP_NEW, "seven fallback ibon");
  php = replaceOnce(php, FAMILY_CALL_OLD, FAMILY_CALL_NEW, "family pad only");
  fs.writeFileSync(API, php);
  console.log("php written", php.length);
}

function patchHelperJs() {
  console.log("backup helper js", backup(HELPER_JS, "store-code"));
  let js = fs.readFileSync(HELPER_JS, "utf8");
  if (js.includes(JS_MARKER)) {
    console.log("helper js already patched");
    return;
  }
  js = replaceOnce(js, HELPER_FN_OLD, HELPER_FN_NEW, "resolved label helper");
  js = replaceOnce(js, HELPER_LOOKUP_OLD, HELPER_LOOKUP_NEW, "skip resolved lookup");
  js = js
    .replace(/請輸入 4～6 碼店號，再按「查詢門市」/g, "請輸入完整店號後按「查詢門市」")
    .replace(/請輸入 4～6 碼店號，再按「查詢門市」/g, "請輸入完整店號後按「查詢門市」");
  fs.writeFileSync(HELPER_JS, js);
  console.log("helper js written", js.length);
}

function patchAdminJs() {
  console.log("backup admin js", backup(ADMIN_JS, "store-code"));
  let js = fs.readFileSync(ADMIN_JS, "utf8");
  if (js.includes('placeholder="輸入完整店號，例如 254971"')) {
    console.log("admin js already patched");
    return;
  }
  js = replaceOnce(js, STORE_CONTROL_OLD, STORE_CONTROL_NEW, "store input attrs");
  js = replaceOnce(js, STORE_HINT_OLD, STORE_HINT_NEW, "store hint");
  fs.writeFileSync(ADMIN_JS, js);
  console.log("admin js written", js.length);
  try {
    new Function(js);
    console.log("admin.js syntax ok");
  } catch (error) {
    throw new Error("admin.js syntax: " + error.message);
  }
}

function fillLilisStore() {
  const phpFile = path.join("C:/Temp/lz-outline", "fill-lilis-254971-store.php");
  const src = path.join(__dirname, "fill-lilis-254971-store.php");
  if (fs.existsSync(src)) {
    fs.copyFileSync(src, phpFile);
  }
  if (!fs.existsSync(phpFile)) {
    console.log("skip fill: missing", phpFile);
    return;
  }
  const out = execFileSync(PHP_BIN, [phpFile], { encoding: "utf8" });
  console.log(out.trim());
}

function main() {
  console.log("backup admin.css", backup(ADMIN_CSS, "store-code"));
  if (fs.existsSync(HELPER_CSS)) console.log("backup helper.css", backup(HELPER_CSS, "store-code"));
  appendCss(ADMIN_CSS);
  if (fs.existsSync(HELPER_CSS)) appendCss(HELPER_CSS);
  patchPhp();
  patchHelperJs();
  patchAdminJs();
  stampHtml(ROOT);
  try {
    fillLilisStore();
  } catch (error) {
    console.error("fill failed:", error.message);
  }
}

main();
