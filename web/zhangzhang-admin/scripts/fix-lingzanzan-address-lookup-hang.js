#!/usr/bin/env node
"use strict";

/**
 * 打六碼店號時不要卡在「查詢門市／郵遞區號中」。
 * 純數字當店號查 全家／7-11；0 開頭先查全家。查詢加上逾時。
 *
 * Cache-bust: address-helper.js ?v=20260821-addr-hang-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const API = path.join(ROOT, "address-helper-api.php");
const HELPER_JS = path.join(ROOT, "assets", "address-helper.js");
const STAMP = "20260821-addr-hang-1";
const PHP_MARKER = "function ah_looks_like_store_code(";
const JS_MARKER = "function lookupTimeout(";

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
  if (src.indexOf(newStr) !== -1 && src.indexOf(oldStr) === -1) {
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

function stampHtml(dir) {
  const names = fs.readdirSync(dir).filter((name) => /\.html$/i.test(name));
  let n = 0;
  for (const name of names) {
    const file = path.join(dir, name);
    const html = fs.readFileSync(file, "latin1");
    if (!/address-helper\.js/.test(html)) continue;
    const next = html.replace(/address-helper\.js(?:\?v=[^"']+)?/g, "address-helper.js?v=" + STAMP);
    if (next === html) continue;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n += 1;
    console.log("stamped", name);
  }
  console.log("html stamped", n);
}

const TIMEOUT_OLD = `            'timeout' => 7,`;
const TIMEOUT_NEW = `            'timeout' => 4,`;

const HELPER_PHP_OLD = `function ah_store_number(string $text): string {
    if (preg_match('/(?<!\\d)(\\d{4,6})(?!\\d)/u', $text, $match) !== 1) return '';
    return $match[1];
}`;

const HELPER_PHP_NEW = `function ah_store_number(string $text): string {
    if (preg_match('/(?<!\\d)(\\d{4,6})(?!\\d)/u', $text, $match) !== 1) return '';
    return $match[1];
}
function ah_looks_like_store_code(string $text): bool {
    return preg_match('/^\\d{4,6}$/u', preg_replace('/\\s+/', '', $text) ?? '') === 1;
}
function ah_family_first(string $storeNo): bool {
    $n = str_pad($storeNo, 6, '0', STR_PAD_LEFT);
    return strlen($n) === 6 && $n[0] === '0';
}`;

const LOOKUP_OLD = `$storeNo = ah_store_number($text);
$chainHint = preg_match('/7\\s*-?\\s*11|統一/u', $text) === 1 ? '7-11' : (preg_match('/全家|FAMILY/u', mb_strtoupper($text, 'UTF-8')) === 1 ? '全家' : '');
$looksLikeAddress = preg_match('/(市|縣|區|鄉|鎮|路|街|道|巷|號)/u', $text) === 1;
$forceStore = $mode === 'store';
$forceAddress = $mode === 'address';

if (!$forceAddress && ($forceStore || ($storeNo !== '' && !$looksLikeAddress))) {
    $stores = [];
    if ($storeNo !== '') {
        if ($chainHint !== '全家') {
            $row = ah_lookup_seven($storeNo);
            if ($row) $stores[] = $row;
        }
        if ($chainHint !== '7-11') {
            $familyNo = str_pad($storeNo, 6, '0', STR_PAD_LEFT);
            $row = ah_lookup_family($familyNo);
            if ($row) $stores[] = $row;
        }
    }
    ah_reply(['ok' => true, 'type' => 'store', 'storeNo' => $storeNo, 'stores' => $stores, 'ambiguous' => count($stores) > 1]);
}`;

const LOOKUP_NEW = `$storeNo = ah_store_number($text);
$chainHint = preg_match('/7\\s*-?\\s*11|統一/u', $text) === 1 ? '7-11' : (preg_match('/全家|FAMILY/u', mb_strtoupper($text, 'UTF-8')) === 1 ? '全家' : '');
$looksLikeAddress = preg_match('/(市|縣|區|鄉|鎮|路|街|道|巷|號)/u', $text) === 1;
$forceStore = $mode === 'store';
$forceAddress = $mode === 'address';
$digitsStore = ah_looks_like_store_code($text);

if ($forceStore || $digitsStore || (!$forceAddress && $storeNo !== '' && !$looksLikeAddress)) {
    @set_time_limit(12);
    @ini_set('default_socket_timeout', '4');
    $stores = [];
    if ($storeNo !== '') {
        $familyNo = str_pad($storeNo, 6, '0', STR_PAD_LEFT);
        $tryFamily = $chainHint !== '7-11';
        $trySeven = $chainHint !== '全家';
        if ($tryFamily && ($chainHint === '全家' || ah_family_first($storeNo))) {
            $row = ah_lookup_family($familyNo);
            if ($row) $stores[] = $row;
            $tryFamily = false;
        }
        if ($trySeven && !$stores) {
            $row = ah_lookup_seven($storeNo);
            if ($row) $stores[] = $row;
        }
        if ($tryFamily && !$stores) {
            $row = ah_lookup_family($familyNo);
            if ($row) $stores[] = $row;
        }
    }
    ah_reply(['ok' => true, 'type' => 'store', 'storeNo' => $storeNo, 'stores' => $stores, 'ambiguous' => count($stores) > 1]);
}`;

const JS_LOOKUP_OLD = `    var previous = requests.get(input);
    if (previous) previous.abort();
    var controller = new AbortController();
    requests.set(input, controller);
    helper.innerHTML = '<span class="address-helper-loading">' + (mode === 'store' ? '查詢 7-11／全家門市中...' : '查詢門市／郵遞區號中...') + '</span>';
    fetch('./address-helper-api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ text: text, mode: mode }),
      signal: controller.signal,
      cache: 'no-store'
    }).then(function (response) {
      return response.json().then(function (body) { if (!response.ok || !body.ok) throw new Error(body.error || '查詢失敗'); return body; });
    }).then(function (data) {
      if (mode === 'store' || data.type === 'store') renderStore(input, data);
      else renderAddress(input, data);
    }).catch(function (error) {
      if (error.name === 'AbortError') return;
      helper.innerHTML = '<span class="address-helper-warning">目前無法查詢，地址仍可照常儲存。</span>';
    });
  }`;

const JS_LOOKUP_NEW = `    var previous = requests.get(input);
    if (previous) previous.abort();
    var controller = new AbortController();
    requests.set(input, controller);
    helper.innerHTML = '<span class="address-helper-loading">' + (mode === 'store' || /^\\d{4,6}$/.test(text) ? '查詢 7-11／全家門市中...' : '查詢門市／郵遞區號中...') + '</span>';
    var timedOut = false;
    var timeoutId = setTimeout(function () {
      timedOut = true;
      try { controller.abort(); } catch (error) {}
    }, 8000);
    fetch('./address-helper-api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ text: text, mode: /^\\d{4,6}$/.test(text) ? 'store' : mode }),
      signal: controller.signal,
      cache: 'no-store'
    }).then(function (response) {
      return response.json().then(function (body) { if (!response.ok || !body.ok) throw new Error(body.error || '查詢失敗'); return body; });
    }).then(function (data) {
      if (requests.get(input) !== controller) return;
      var target = input;
      if (data.type === 'store' && !isStoreField(input)) {
        var root = splitAddressRoot(input);
        var storeInput = root && root.querySelector('[data-customer-store-address]');
        if (storeInput) {
          helper.innerHTML = '<span class="address-helper-idle">已改查超商門市</span>';
          target = storeInput;
        }
      }
      if (data.type === 'store' || lookupMode(target) === 'store') renderStore(target, data);
      else renderAddress(target, data);
    }).catch(function (error) {
      if (requests.get(input) !== controller) return;
      if (error && error.name === 'AbortError' && !timedOut) return;
      helper.innerHTML = '<span class="address-helper-warning">' + (timedOut ? '查詢逾時，地址仍可照常儲存。可再按「查詢門市」。' : '目前無法查詢，地址仍可照常儲存。') + '</span>';
    }).then(function () {
      clearTimeout(timeoutId);
      if (requests.get(input) === controller) requests.delete(input);
    });
  }`;

if (!fs.existsSync(API) || !fs.existsSync(HELPER_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup api", backup(API, "addr-hang"));
let php = fs.readFileSync(API, "utf8");
if (php.indexOf(PHP_MARKER) === -1) {
  php = replaceOnce(php, HELPER_PHP_OLD, HELPER_PHP_NEW, "store-code helpers");
  php = replaceOnce(php, LOOKUP_OLD, LOOKUP_NEW, "digits as store + family first");
} else {
  console.log("already: php store-code helpers");
}
if (php.indexOf("'timeout' => 4,") === -1) {
  php = replaceOnce(php, TIMEOUT_OLD, TIMEOUT_NEW, "http timeout 4s");
} else {
  console.log("already: http timeout 4s");
}
if (php.indexOf(PHP_MARKER) === -1) throw new Error("php helper missing");
fs.writeFileSync(API, php, "utf8");

console.log("backup helper js", backup(HELPER_JS, "addr-hang"));
let js = fs.readFileSync(HELPER_JS, "utf8");
if (js.indexOf("查詢逾時，地址仍可照常儲存") === -1) {
  js = replaceOnce(js, JS_LOOKUP_OLD, JS_LOOKUP_NEW, "lookup timeout + store digits");
} else {
  console.log("already: helper timeout");
}
if (js.indexOf("查詢逾時，地址仍可照常儲存") === -1) throw new Error("js timeout missing");
fs.writeFileSync(HELPER_JS, js, "utf8");
try {
  new Function(js);
  console.log("address-helper.js syntax ok");
} catch (error) {
  throw new Error("address-helper.js syntax: " + error.message);
}

stampHtml(ROOT);
console.log("LINGZANZAN address lookup hang fix", STAMP);
