#!/usr/bin/env node
"use strict";

/**
 * 領讚讚「張張／一元競標」改成電腦產品同步建檔。
 * 側欄不再進一元競標登入；電腦頁儲存後寫入張張產品庫，並把張張已建檔的電腦商品拉回來同一份清單。
 *
 * Cache-bust: admin-products-computer.js/css + admin-navigation.js ?v=20260820-computer-sync-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const PAGES = process.env.CEK_PAGES || [
  path.join(__dirname, "..", "lingzanzan-pages"),
  path.join(__dirname, "lingzanzan-pages"),
].find((dir) => fs.existsSync(path.join(dir, "admin-products-computer.html")));
const STAMP = "20260820-computer-sync-1";
const HTML = path.join(ROOT, "admin-products-computer.html");
const JS = path.join(ROOT, "assets", "admin-products-computer.js");
const CSS = path.join(ROOT, "assets", "admin-products-computer.css");
const NAV = path.join(ROOT, "assets", "admin-navigation.js");
const API = path.join(ROOT, "computer-products-api.php");
const JS_MARKER = "正在同步建檔到張張電腦產品庫";
const NAV_MARKER = "電腦部建檔";
const PHP_MARKER = "function baohui_linked_computer_rows(";

if (!PAGES) throw new Error("missing lingzanzan-pages/admin-products-computer.html");

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

function stampNavLatin1(file) {
  const src = fs.readFileSync(file);
  const text = src.toString("latin1");
  const next = text.replace(/admin-navigation\.js\?v=[^"'>\s]+/g, "admin-navigation.js?v=" + STAMP);
  if (next === text) return false;
  fs.writeFileSync(file, Buffer.from(next, "latin1"));
  return true;
}

const NAV_OLD = `    { title: '張張／一元競標', links: [
      ['zhangzhang.html', '張張管理後台'], ['lingzanzan-computer-receipts.php', '張張電腦已帶入']
    ]},`;

const NAV_NEW = `    { title: '電腦部建檔', links: [
      ['admin-products-computer.html', '電腦產品同步張張']
    ]},`;

const PHP_FN_OLD = `function atomic_write_or_throw(string $file, array $payload): bool {
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(5));
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

$operator = require_operator($sessionsFile, $stateFile);`;

const PHP_FN_NEW = `function atomic_write_or_throw(string $file, array $payload): bool {
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(5));
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function baohui_linked_computer_rows(array $handoffItems): array {
    $seen = [];
    foreach ($handoffItems as $row) {
        if (!is_array($row)) continue;
        foreach (['destination_product_id', 'code', 'temporary_barcode', 'id'] as $key) {
            $value = trim((string)($row[$key] ?? ''));
            if ($value !== '') $seen[$value] = true;
        }
    }
    $file = baohui_data_dir() . DIRECTORY_SEPARATOR . 'products.json';
    if (!is_file($file)) return [];
    $out = [];
    foreach (read_json($file) as $product) {
        if (!is_array($product)) continue;
        $dept = trim((string)($product['department'] ?? ($product['category_group'] ?? '')));
        $cat = trim((string)($product['main_category'] ?? ($product['category_type'] ?? '')));
        if ($dept !== '' && !in_array($dept, ['電腦部門', '電腦', 'computer'], true)) continue;
        if ($dept === '' && ($cat === '' || !is_computer_category_name($cat))) continue;
        $pid = trim((string)($product['id'] ?? ''));
        $barcode = trim((string)($product['barcode'] ?? ''));
        $handoffId = trim((string)($product['lingzanzan_handoff_id'] ?? ''));
        if (($pid !== '' && isset($seen[$pid])) || ($barcode !== '' && isset($seen[$barcode])) || ($handoffId !== '' && isset($seen[$handoffId]))) continue;
        $out[] = [
            'id' => $handoffId !== '' ? $handoffId : ('zhangzhang-' . ($pid !== '' ? $pid : $barcode)),
            'code' => $barcode !== '' ? $barcode : $pid,
            'temporary_barcode' => $barcode !== '' ? $barcode : $pid,
            'title' => (string)($product['title'] ?? ($product['product_name'] ?? $pid)),
            'category' => $cat !== '' ? $cat : '電腦商品',
            'quantity' => max(0, (int)($product['stock_total'] ?? 0)),
            'tentative_cost' => (float)($product['cost'] ?? ($product['latest_cost'] ?? 0)),
            'supplier' => (string)($product['purchase_source'] ?? ''),
            'warehouse' => (string)($product['warehouse_name'] ?? ''),
            'brand' => (string)($product['brand'] ?? ($product['category_brand'] ?? '')),
            'model' => (string)($product['model'] ?? ''),
            'cpu' => '',
            'ram' => '',
            'storage' => '',
            'gpu' => '',
            'serial_number' => (string)($product['serial_number'] ?? ''),
            'description' => (string)($product['description'] ?? ''),
            'carrier' => (string)($product['carrier'] ?? ''),
            'tracking_no' => (string)($product['tracking_no'] ?? ''),
            'stock_mode' => (string)($product['stock_mode'] ?? 'ready'),
            'stock_mode_label' => ((string)($product['stock_mode'] ?? 'ready') === 'ready') ? '現貨' : '在途',
            'department' => 'computer',
            'source_system' => (string)($product['source_system'] ?? 'zhangzhang'),
            'destination_product_id' => $pid,
            'status' => 'linked_zhangzhang',
            'status_label' => '張張產品庫已建檔',
            'legacy' => false,
            'updatedAt' => (string)($product['updated_at'] ?? ($product['created_at'] ?? '')),
        ];
        if ($pid !== '') $seen[$pid] = true;
        if ($barcode !== '') $seen[$barcode] = true;
        if ($handoffId !== '') $seen[$handoffId] = true;
    }
    return $out;
}

$operator = require_operator($sessionsFile, $stateFile);`;

const PHP_MERGE_OLD = `$items = array_merge($handoffItems, $legacy);`;
const PHP_MERGE_NEW = `$items = array_merge($handoffItems, $legacy, baohui_linked_computer_rows($handoffItems));`;

const PHP_MSG_OLD = `'status' => 'imported_to_baohui', 'status_label' => '已帶入寶輝電商'`;
const PHP_MSG_NEW = `'status' => 'imported_to_baohui', 'status_label' => '已同步張張產品庫'`;

const PHP_OK_OLD = `    'message' => $stockMode === 'ready'
        ? '現貨已直接帶入寶輝電商產品庫'
        : '已直接帶入寶輝電商產品庫，物流單號 ' . $trackingNo]);`;
const PHP_OK_NEW = `    'message' => $stockMode === 'ready'
        ? '現貨已同步建檔到張張電腦產品庫'
        : '已同步建檔到張張電腦產品庫，物流單號 ' . $trackingNo]);`;

if (!fs.existsSync(ROOT) || !fs.existsSync(API)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup html", backup(HTML, "computer-sync"));
fs.copyFileSync(path.join(PAGES, "admin-products-computer.html"), HTML);
console.log("copied computer html", fs.statSync(HTML).size);

console.log("backup js", backup(JS, "computer-sync"));
let js = fs.readFileSync(JS, "utf8");
js = js.replace("目前尚未帶入寶輝的電腦商品。", "目前還沒有同步到張張的電腦商品。");
js = js.replace("現貨已帶入寶輝", "現貨已同步張張");
js = js.replace("已帶入寶輝電商", "已同步張張產品庫");
js = js.replace("正在直接帶入寶輝電商產品庫…", "正在同步建檔到張張電腦產品庫…");
js = js.replace("已直接帶入寶輝電商產品庫。", "已同步建檔到張張電腦產品庫。");
if (js.indexOf(JS_MARKER) === -1) throw new Error("js copy not updated");
fs.writeFileSync(JS, js, "utf8");

if (fs.existsSync(CSS)) {
  let css = fs.readFileSync(CSS, "utf8");
  if (css.indexOf("/* " + STAMP + " computer sync */") === -1) {
    backup(CSS, "computer-sync");
    fs.writeFileSync(CSS, css.replace(/\s*$/, "") + "\n/* " + STAMP + " computer sync */\n", "utf8");
  }
}

console.log("backup nav", backup(NAV, "computer-sync"));
let nav = fs.readFileSync(NAV, "utf8");
if (nav.indexOf(NAV_MARKER) === -1) {
  nav = replaceOnce(nav, NAV_OLD, NAV_NEW, "nav computer filing");
  fs.writeFileSync(NAV, nav, "utf8");
} else {
  console.log("already: nav computer filing");
}

console.log("backup api", backup(API, "computer-sync"));
let php = fs.readFileSync(API, "utf8");
if (php.indexOf(PHP_MARKER) === -1) {
  php = replaceOnce(php, PHP_FN_OLD, PHP_FN_NEW, "pull zhangzhang computer rows");
} else {
  console.log("already: pull zhangzhang computer rows");
}
if (php.indexOf("baohui_linked_computer_rows($handoffItems)") === -1) {
  php = replaceOnce(php, PHP_MERGE_OLD, PHP_MERGE_NEW, "merge linked computer rows");
} else {
  console.log("already: merge linked computer rows");
}
if (php.indexOf("已同步張張產品庫") === -1) {
  php = replaceOnce(php, PHP_MSG_OLD, PHP_MSG_NEW, "status label");
  php = replaceOnce(php, PHP_OK_OLD, PHP_OK_NEW, "save message");
} else {
  console.log("already: status copy");
}
fs.writeFileSync(API, php, "utf8");

let stamped = 0;
for (const name of fs.readdirSync(ROOT)) {
  if (!/\.html?$/i.test(name)) continue;
  if (stampNavLatin1(path.join(ROOT, name))) {
    stamped += 1;
    console.log("nav stamp", name);
  }
}
console.log("nav html stamped", stamped);

const liveHtml = fs.readFileSync(HTML, "utf8");
if (liveHtml.indexOf("admin-products-computer.js?v=" + STAMP) === -1) throw new Error("computer js stamp missing");
if (liveHtml.indexOf("data-stock-mode") === -1) throw new Error("computer form still stale");
if (liveHtml.indexOf("\uFFFD") !== -1) throw new Error("computer html has FFFD");
if (fs.readFileSync(NAV, "utf8").indexOf(NAV_MARKER) === -1) throw new Error("nav title missing");
if (fs.readFileSync(API, "utf8").indexOf(PHP_MARKER) === -1) throw new Error("php linker missing");
console.log("LINGZANZAN computer sync filing ok", STAMP);
