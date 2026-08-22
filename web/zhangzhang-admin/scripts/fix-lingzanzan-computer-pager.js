#!/usr/bin/env node
"use strict";

/**
 * 電腦建檔清單分頁，避免一次畫完全部當機。
 * 列表只帶縮圖；補資料再讀明細照片。
 * Cache-bust: admin-products-computer.js/css ?v=20260820-computer-page-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const PAGES = process.env.HH_PAGES || [
  path.join(__dirname, "..", "lingzanzan-pages"),
  path.join(__dirname, "lingzanzan-pages"),
].find((dir) => fs.existsSync(path.join(dir, "admin-products-computer.html")));
const STAMP = "20260820-computer-page-1";
const HTML = path.join(ROOT, "admin-products-computer.html");
const JS = path.join(ROOT, "assets", "admin-products-computer.js");
const CSS = path.join(ROOT, "assets", "admin-products-computer.css");
const API = path.join(ROOT, "computer-products-api.php");
const JS_MARKER = "var listPage = 1;";

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

if (!PAGES) throw new Error("missing admin-products-computer.html");
if (!fs.existsSync(JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup html", backup(HTML, "computer-page"));
fs.copyFileSync(path.join(PAGES, "admin-products-computer.html"), HTML);
console.log("copied computer html", fs.statSync(HTML).size);

const CSS_ADD = `

/* ${STAMP} computer pager */
.computer-pager {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px solid var(--line);
}
.computer-pager[hidden] { display: none; }
.computer-pager button,
.computer-pager select {
  min-height: 38px;
  padding: 0 12px;
  border: 1px solid var(--line);
  border-radius: 10px;
  background: #241c20;
  color: #fff;
  font-weight: 800;
  cursor: pointer;
}
.computer-pager button:disabled {
  opacity: .45;
  cursor: default;
}
.computer-pager strong {
  color: #ffd06a;
  font-size: 13px;
}
`;

console.log("backup css", backup(CSS, "computer-page"));
let css = fs.readFileSync(CSS, "utf8");
if (css.indexOf("/* " + STAMP + " computer pager */") === -1) {
  css = css.replace(/\s*$/, "") + CSS_ADD;
  fs.writeFileSync(CSS, css, "utf8");
  console.log("patched css pager");
} else {
  console.log("already: css pager");
}

const JS_VAR_OLD = `  var items = [];
  var version = 0;`;
const JS_VAR_NEW = `  var items = [];
  var listPage = 1;
  var listPageSize = 20;
  var version = 0;`;

const JS_RENDER_OLD = `  function renderList() {
    var host = document.querySelector('[data-computer-list]');
    var keyword = String(document.querySelector('[data-computer-search]').value || '').trim().toLowerCase();
    var visible = items.filter(function (item) {
      return !keyword || [item.code, item.title, item.category, item.tracking_no, item.brand, item.model, item.serial_number, item.supplier, item.warehouse].join(' ').toLowerCase().indexOf(keyword) >= 0;
    });
    document.querySelector('[data-computer-count]').textContent = visible.length + ' 筆';
    if (!visible.length) { host.innerHTML = '<p class="empty-state">' + (keyword ? '找不到符合的電腦商品。' : '目前還沒有同步到張張的電腦商品。') + '</p>'; return; }
    host.innerHTML = visible.map(function (item) {`;
const JS_RENDER_NEW = `  function renderPager(total, pages) {
    var pager = document.querySelector('[data-computer-pager]');
    if (!pager) return;
    if (total <= listPageSize) { pager.hidden = true; pager.innerHTML = ''; return; }
    pager.hidden = false;
    pager.innerHTML = '<button type="button" data-computer-page="prev"' + (listPage <= 1 ? ' disabled' : '') + '>上一頁</button>' +
      '<strong>第 ' + listPage + '／' + pages + ' 頁</strong>' +
      '<button type="button" data-computer-page="next"' + (listPage >= pages ? ' disabled' : '') + '>下一頁</button>' +
      '<label>每頁 <select data-computer-page-size><option value="20">20</option><option value="50">50</option><option value="100">100</option></select></label>';
    var select = pager.querySelector('[data-computer-page-size]');
    if (select) select.value = String(listPageSize);
  }

  function renderList() {
    var host = document.querySelector('[data-computer-list]');
    var pager = document.querySelector('[data-computer-pager]');
    var keyword = String(document.querySelector('[data-computer-search]').value || '').trim().toLowerCase();
    var visible = items.filter(function (item) {
      return !keyword || [item.code, item.title, item.category, item.tracking_no, item.brand, item.model, item.serial_number, item.supplier, item.warehouse].join(' ').toLowerCase().indexOf(keyword) >= 0;
    });
    var pages = Math.max(1, Math.ceil(visible.length / listPageSize));
    if (listPage > pages) listPage = pages;
    if (listPage < 1) listPage = 1;
    document.querySelector('[data-computer-count]').textContent = visible.length + ' 筆｜第 ' + listPage + '／' + pages + ' 頁';
    if (!visible.length) {
      host.innerHTML = '<p class="empty-state">' + (keyword ? '找不到符合的電腦商品。' : '目前還沒有同步到張張的電腦商品。') + '</p>';
      if (pager) { pager.hidden = true; pager.innerHTML = ''; }
      return;
    }
    var pageRows = visible.slice((listPage - 1) * listPageSize, listPage * listPageSize);
    renderPager(visible.length, pages);
    host.innerHTML = pageRows.map(function (item) {`;

const JS_COUNT_OLD = `      var extraCount = Array.isArray(item.detail_images) ? item.detail_images.length : 0;`;
const JS_COUNT_NEW = `      var extraCount = Number(item.photo_count || (Array.isArray(item.detail_images) ? item.detail_images.length : 0));`;

const JS_SEARCH_OLD = `    document.querySelector('[data-computer-search]').addEventListener('input', renderList);`;
const JS_SEARCH_NEW = `    document.querySelector('[data-computer-search]').addEventListener('input', function () { listPage = 1; renderList(); });
    document.addEventListener('click', function (event) {
      var pageBtn = event.target.closest('[data-computer-page]');
      if (!pageBtn || pageBtn.disabled) return;
      if (pageBtn.getAttribute('data-computer-page') === 'prev') listPage -= 1;
      if (pageBtn.getAttribute('data-computer-page') === 'next') listPage += 1;
      renderList();
    });
    document.addEventListener('change', function (event) {
      var size = event.target.closest('[data-computer-page-size]');
      if (!size) return;
      listPageSize = Number(size.value || 20) || 20;
      listPage = 1;
      renderList();
    });`;

const JS_EDIT_OLD = `      var button = event.target.closest('[data-computer-edit]'); if (!button) return;
      var item = items.find(function (row) { return String(row.id) === String(button.dataset.computerEdit); }); if (item) fillForm(item);`;
const JS_EDIT_NEW = `      var button = event.target.closest('[data-computer-edit]'); if (!button) return;
      var status = document.querySelector('[data-computer-form-status]');
      if (status) status.textContent = '正在讀取這筆電腦商品…';
      api(API + '?id=' + encodeURIComponent(button.dataset.computerEdit) + '&_=' + Date.now()).then(function (payload) {
        fillForm(payload.item || {});
      }).catch(function (error) {
        var fallback = items.find(function (row) { return String(row.id) === String(button.dataset.computerEdit); });
        if (fallback) fillForm(fallback);
        else if (status) status.textContent = error.message || '讀取失敗';
      });`;

const JS_LOAD_OLD = `      items = Array.isArray(payload.items) ? payload.items : [];`;
const JS_LOAD_NEW = `      items = Array.isArray(payload.items) ? payload.items : [];
      listPage = 1;`;

const PHP_FN_OLD = `function clean_images($value): array {
    if (!is_array($value)) return [];
    return array_values(array_slice(array_filter($value, fn($url) => is_string($url) && trim($url) !== ''), 0, 24));
}`;
const PHP_FN_NEW = `function clean_images($value): array {
    if (!is_array($value)) return [];
    return array_values(array_slice(array_filter($value, fn($url) => is_string($url) && trim($url) !== ''), 0, 24));
}

function computer_list_card(array $row): array {
    $details = is_array($row['detail_images'] ?? null) ? $row['detail_images'] : [];
    $image = trim((string)($row['image'] ?? ''));
    if ($image === '' && $details) $image = trim((string)$details[0]);
    if ($image === '' && is_array($row['colors'] ?? null)) {
        foreach ($row['colors'] as $color) {
            if (!is_array($color)) continue;
            $src = trim((string)($color['image'] ?? ''));
            if ($src !== '') { $image = $src; break; }
        }
    }
    $row['image'] = $image;
    $row['photo_count'] = max(count($details), $image === '' ? 0 : 1);
    unset($row['detail_images'], $row['colors']);
    return $row;
}`;

const PHP_GET_OLD = `    usort($items, fn($a, $b) => strcmp((string)($b['updatedAt'] ?? ''), (string)($a['updatedAt'] ?? '')));`;
const PHP_GET_NEW = `    usort($items, fn($a, $b) => strcmp((string)($b['updatedAt'] ?? ''), (string)($a['updatedAt'] ?? '')));
    $itemId = trim((string)($_GET['id'] ?? ''));
    if ($itemId !== '') {
        foreach ($items as $row) {
            if (!is_array($row)) continue;
            $ids = [(string)($row['id'] ?? ''), (string)($row['code'] ?? ''), (string)($row['destination_product_id'] ?? '')];
            if (in_array($itemId, $ids, true)) respond(['ok' => true, 'version' => (int)$handoffs['version'], 'item' => $row]);
        }
        respond(['ok' => false, 'error' => '找不到這筆電腦商品'], 404);
    }`;

const PHP_ITEMS_OLD = `'items' => $items, 'categories' => array_keys($catalog),`;
const PHP_ITEMS_NEW = `'items' => array_map('computer_list_card', $items), 'categories' => array_keys($catalog),`;

console.log("backup js", backup(JS, "computer-page"));
let js = fs.readFileSync(JS, "utf8");
if (js.indexOf(JS_MARKER) === -1) {
  js = replaceOnce(js, JS_VAR_OLD, JS_VAR_NEW, "pager vars");
  js = replaceOnce(js, JS_RENDER_OLD, JS_RENDER_NEW, "paged renderList");
  js = replaceOnce(js, JS_COUNT_OLD, JS_COUNT_NEW, "photo count");
  js = replaceOnce(js, JS_SEARCH_OLD, JS_SEARCH_NEW, "search reset page");
  js = replaceOnce(js, JS_EDIT_OLD, JS_EDIT_NEW, "edit loads full item");
  js = replaceOnce(js, JS_LOAD_OLD, JS_LOAD_NEW, "reset page on load");
} else {
  console.log("already: js pager");
}
if (js.indexOf(JS_MARKER) === -1) throw new Error("pager vars missing");
fs.writeFileSync(JS, js, "utf8");

console.log("backup api", backup(API, "computer-page"));
let php = fs.readFileSync(API, "utf8");
if (php.indexOf("function computer_list_card") === -1) {
  php = replaceOnce(php, PHP_FN_OLD, PHP_FN_NEW, "list card slim");
  php = replaceOnce(php, PHP_GET_OLD, PHP_GET_NEW, "get one item");
  php = replaceOnce(php, PHP_ITEMS_OLD, PHP_ITEMS_NEW, "slim list payload");
} else {
  console.log("already: php list card");
}
fs.writeFileSync(API, php, "utf8");

const liveHtml = fs.readFileSync(HTML, "utf8");
if (liveHtml.indexOf("data-computer-pager") === -1) throw new Error("pager missing");
if (liveHtml.indexOf("\uFFFD") !== -1) throw new Error("computer html has FFFD");
if (liveHtml.indexOf("admin-products-computer.js?v=" + STAMP) === -1) throw new Error("js stamp missing");
console.log("LINGZANZAN computer pager ok", STAMP);
