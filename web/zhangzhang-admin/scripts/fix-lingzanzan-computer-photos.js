#!/usr/bin/env node
"use strict";

/**
 * 電腦建檔補縮圖與明細照片：表單可傳、清單顯示、張張產品庫一併帶圖。
 * Cache-bust: admin-products-computer.js/css ?v=20260820-computer-photos-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const PAGES = process.env.HH_PAGES || [
  path.join(__dirname, "..", "lingzanzan-pages"),
  path.join(__dirname, "lingzanzan-pages"),
].find((dir) => fs.existsSync(path.join(dir, "admin-products-computer.html")));
const STAMP = "20260820-computer-photos-1";
const HTML = path.join(ROOT, "admin-products-computer.html");
const JS = path.join(ROOT, "assets", "admin-products-computer.js");
const CSS = path.join(ROOT, "assets", "admin-products-computer.css");
const API = path.join(ROOT, "computer-products-api.php");
const MARKER = "data-thumbnail-input";
const JS_MARKER = "function itemThumb(";

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

console.log("backup html", backup(HTML, "computer-photos"));
fs.copyFileSync(path.join(PAGES, "admin-products-computer.html"), HTML);
console.log("copied computer html", fs.statSync(HTML).size);

const CSS_ADD = `

/* ${STAMP} computer photos */
.computer-thumb-field { display: grid; gap: 8px; }
.computer-thumb-preview figure,
.computer-item-thumb {
  width: 72px;
  height: 72px;
  margin: 0;
  padding: 0;
  border: 1px solid var(--line);
  border-radius: 12px;
  overflow: hidden;
  background: #1b1518;
  display: grid;
  place-items: center;
  position: relative;
}
.computer-thumb-preview img,
.computer-item-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.computer-thumb-preview button,
.computer-thumb-actions button {
  min-height: 36px;
}
.computer-thumb-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.computer-item { grid-template-columns: 72px minmax(180px, 1fr) 150px 170px auto; }
.computer-item-thumb { border: 0; cursor: pointer; color: var(--muted); font-size: 12px; font-weight: 800; }
.computer-item-thumb.is-empty { color: #cbb8c4; }
.computer-item-thumb em {
  position: absolute;
  right: 4px;
  bottom: 4px;
  padding: 1px 6px;
  border-radius: 999px;
  background: rgba(0,0,0,.72);
  color: #ffd06a;
  font-size: 11px;
  font-style: normal;
  font-weight: 800;
}
.computer-photo-overlay {
  position: fixed;
  inset: 0;
  z-index: 80;
  display: grid;
  place-items: center;
  background: rgba(10, 6, 8, .88);
  padding: 24px;
}
.computer-photo-overlay[hidden] { display: none; }
.computer-photo-overlay img {
  max-width: min(92vw, 920px);
  max-height: 82vh;
  border-radius: 16px;
  object-fit: contain;
}
.computer-photo-overlay button {
  position: absolute;
  top: 18px;
  right: 18px;
  min-height: 40px;
  padding: 0 14px;
}
`;

console.log("backup css", backup(CSS, "computer-photos"));
let css = fs.readFileSync(CSS, "utf8");
if (css.indexOf("/* " + STAMP + " computer photos */") === -1) {
  css = css.replace(/\s*$/, "") + CSS_ADD;
  fs.writeFileSync(CSS, css, "utf8");
  console.log("patched css photos");
} else {
  console.log("already: css photos");
}

const JS_VAR_OLD = `  var detailImages = [];
  var colors = [];`;
const JS_VAR_NEW = `  var detailImages = [];
  var thumbnail = '';
  var colors = [];`;

const JS_HELPER_OLD = `  function newRequestId() { return 'computer-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10); }`;
const JS_HELPER_NEW = `  function newRequestId() { return 'computer-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10); }

  function itemThumb(item) {
    if (!item) return '';
    if (item.image) return String(item.image);
    if (Array.isArray(item.detail_images) && item.detail_images[0]) return String(item.detail_images[0]);
    if (Array.isArray(item.colors)) {
      for (var i = 0; i < item.colors.length; i++) {
        if (item.colors[i] && item.colors[i].image) return String(item.colors[i].image);
      }
    }
    return '';
  }

  function renderThumbnail() {
    var host = document.querySelector('[data-thumbnail-preview]');
    if (!host) return;
    host.innerHTML = thumbnail
      ? '<figure><img src="' + escapeHtml(thumbnail) + '" alt="縮圖"></figure>'
      : '<p class="empty-inline">尚未加入縮圖。</p>';
  }

  function openComputerPhoto(src) {
    var overlay = document.querySelector('[data-computer-photo-overlay]');
    var img = document.querySelector('[data-computer-photo-full]');
    if (!overlay || !img || !src) return;
    img.src = src;
    overlay.hidden = false;
  }

  function closeComputerPhoto() {
    var overlay = document.querySelector('[data-computer-photo-overlay]');
    var img = document.querySelector('[data-computer-photo-full]');
    if (img) img.removeAttribute('src');
    if (overlay) overlay.hidden = true;
  }`;

const JS_MEDIA_OLD = `    colorHost.innerHTML = colors.length ? colors.map(function (row, index) {
      return '<figure><img src="' + escapeHtml(row.image || './assets/brand-icon-192.png') + '" alt="' + escapeHtml(row.name) + '"><figcaption>' + escapeHtml(row.name) + '<small>' + Number(row.quantity || 1) + ' 件</small></figcaption><button type="button" data-remove-color="' + index + '">移除</button></figure>';
    }).join('') : '<p class="empty-inline">尚未建立顏色。</p>';
  }`;
const JS_MEDIA_NEW = `    colorHost.innerHTML = colors.length ? colors.map(function (row, index) {
      return '<figure><img src="' + escapeHtml(row.image || './assets/brand-icon-192.png') + '" alt="' + escapeHtml(row.name) + '"><figcaption>' + escapeHtml(row.name) + '<small>' + Number(row.quantity || 1) + ' 件</small></figcaption><button type="button" data-remove-color="' + index + '">移除</button></figure>';
    }).join('') : '<p class="empty-inline">尚未建立顏色。</p>';
    renderThumbnail();
  }`;

const JS_LIST_OLD = `      return '<article class="computer-item">' +
        '<div class="computer-item-main"><span>' + escapeHtml(item.code) + '</span><h3>' + escapeHtml(item.title) + '</h3><p>' + escapeHtml(specs || '電腦規格待補') + '</p></div>' +`;
const JS_LIST_NEW = `      var thumb = itemThumb(item);
      var extraCount = Array.isArray(item.detail_images) ? item.detail_images.length : 0;
      return '<article class="computer-item">' +
        '<button type="button" class="computer-item-thumb' + (thumb ? '' : ' is-empty') + '" data-computer-photo="' + escapeHtml(thumb) + '" aria-label="查看商品照片">' +
          (thumb ? '<img src="' + escapeHtml(thumb) + '" alt="" loading="lazy">' : '<span>無圖</span>') +
          (extraCount > 1 ? '<em>' + extraCount + ' 張</em>' : '') +
        '</button>' +
        '<div class="computer-item-main"><span>' + escapeHtml(item.code) + '</span><h3>' + escapeHtml(item.title) + '</h3><p>' + escapeHtml(specs || '電腦規格待補') + '</p></div>' +`;

const JS_PAYLOAD_OLD = `    data.detail_images = detailImages.slice();
    data.colors = colors.slice();`;
const JS_PAYLOAD_NEW = `    data.detail_images = detailImages.slice();
    data.image = thumbnail || (detailImages[0] || '');
    data.colors = colors.slice();`;

const JS_FILL_OLD = `    detailImages = Array.isArray(item.detail_images) ? item.detail_images.slice() : [];
    colors = Array.isArray(item.colors) ? item.colors.slice() : [];`;
const JS_FILL_NEW = `    detailImages = Array.isArray(item.detail_images) ? item.detail_images.slice() : [];
    thumbnail = String(item.image || (detailImages[0] || ''));
    colors = Array.isArray(item.colors) ? item.colors.slice() : [];`;

const JS_CLEAR_OLD = `    form.elements.namedItem('expected_version').value = String(version);
    detailImages = [];
    colors = [];`;
const JS_CLEAR_NEW = `    form.elements.namedItem('expected_version').value = String(version);
    detailImages = [];
    thumbnail = '';
    colors = [];`;

const JS_DETAIL_MSG_OLD = `      document.querySelector('[data-computer-form-status]').textContent = '已加入 ' + images.length + ' 張產品詳細圖。';`;
const JS_DETAIL_MSG_NEW = `      document.querySelector('[data-computer-form-status]').textContent = '已加入 ' + images.length + ' 張明細照片。';`;

const JS_INPUT_OLD = `    document.querySelector('[data-detail-image-input]').addEventListener('change', function (event) {`;
const JS_INPUT_NEW = `    document.querySelector('[data-thumbnail-input]').addEventListener('change', function (event) {
      var file = (event.target.files || [])[0];
      if (!file) return;
      fileToDataUrl(file).then(function (image) {
        thumbnail = image;
        if (!detailImages.length) detailImages = [image];
        event.target.value = '';
        renderMedia();
        document.querySelector('[data-computer-form-status]').textContent = '已加入縮圖。';
      }).catch(function (error) { document.querySelector('[data-computer-form-status]').textContent = error.message; });
    });
    document.querySelector('[data-paste-thumbnail]').addEventListener('click', function () {
      readClipboardImages(document.querySelector('[data-thumbnail-preview]'), function (files) {
        if (!files.length) return Promise.reject(new Error('剪貼簿沒有圖片。'));
        return fileToDataUrl(files[0]).then(function (image) {
          thumbnail = image;
          if (!detailImages.length) detailImages = [image];
          renderMedia();
          document.querySelector('[data-computer-form-status]').textContent = '已貼上縮圖。';
        });
      });
    });
    document.querySelector('[data-clear-thumbnail]').addEventListener('click', function () {
      thumbnail = '';
      renderMedia();
    });
    document.querySelector('[data-detail-image-input]').addEventListener('change', function (event) {`;

const JS_CLICK_OLD = `    document.querySelector('[data-computer-list]').addEventListener('click', function (event) {
      var printButton = event.target.closest('[data-computer-print]');`;
const JS_CLICK_NEW = `    var photoOverlay = document.querySelector('[data-computer-photo-overlay]');
    if (photoOverlay) {
      photoOverlay.addEventListener('click', function (event) {
        if (event.target === photoOverlay || event.target.closest('[data-computer-photo-close]')) closeComputerPhoto();
      });
    }
    document.querySelector('[data-computer-list]').addEventListener('click', function (event) {
      var photo = event.target.closest('[data-computer-photo]');
      if (photo) {
        var src = photo.getAttribute('data-computer-photo') || '';
        if (src) openComputerPhoto(src);
        return;
      }
      var printButton = event.target.closest('[data-computer-print]');`;

console.log("backup js", backup(JS, "computer-photos"));
let js = fs.readFileSync(JS, "utf8");
if (js.indexOf(JS_MARKER) === -1) {
  js = replaceOnce(js, JS_VAR_OLD, JS_VAR_NEW, "thumbnail var");
  js = replaceOnce(js, JS_HELPER_OLD, JS_HELPER_NEW, "thumb helpers");
  js = replaceOnce(js, JS_MEDIA_OLD, JS_MEDIA_NEW, "render thumbnail");
  js = replaceOnce(js, JS_LIST_OLD, JS_LIST_NEW, "list thumbs");
  js = replaceOnce(js, JS_PAYLOAD_OLD, JS_PAYLOAD_NEW, "save image");
  js = replaceOnce(js, JS_FILL_OLD, JS_FILL_NEW, "edit image");
  js = replaceOnce(js, JS_CLEAR_OLD, JS_CLEAR_NEW, "clear thumbnail");
  js = replaceOnce(js, JS_DETAIL_MSG_OLD, JS_DETAIL_MSG_NEW, "detail photo copy");
  js = replaceOnce(js, JS_INPUT_OLD, JS_INPUT_NEW, "thumbnail inputs");
  js = replaceOnce(js, JS_CLICK_OLD, JS_CLICK_NEW, "photo overlay");
} else {
  console.log("already: js thumbs");
}
if (js.indexOf(JS_MARKER) === -1) throw new Error("js thumb helper missing");
fs.writeFileSync(JS, js, "utf8");

const PHP_LINK_OLD = `            'serial_number' => (string)($product['serial_number'] ?? ''),
            'description' => (string)($product['description'] ?? ''),
            'carrier' => (string)($product['carrier'] ?? ''),
            'tracking_no' => (string)($product['tracking_no'] ?? ''),`;
const PHP_LINK_NEW = `            'serial_number' => (string)($product['serial_number'] ?? ''),
            'description' => (string)($product['description'] ?? ''),
            'image' => (string)($product['image'] ?? ''),
            'detail_images' => array_values(array_filter(array_unique(array_map('strval', array_merge(
                [(string)($product['image'] ?? '')],
                is_array($product['extra_images'] ?? null) ? $product['extra_images'] : []
            ))))),
            'carrier' => (string)($product['carrier'] ?? ''),
            'tracking_no' => (string)($product['tracking_no'] ?? ''),`;

const PHP_MEDIA_OLD = `$media = externalize_data_images(['detail_images' => clean_images($item['detail_images'] ?? []), 'colors' => $cleanColors], __DIR__);`;
const PHP_MEDIA_NEW = `$coverIn = trim((string)($item['image'] ?? ($item['thumbnail'] ?? '')));
    $detailIn = clean_images($item['detail_images'] ?? []);
    if ($coverIn !== '') array_unshift($detailIn, $coverIn);
    $media = externalize_data_images(['detail_images' => array_values(array_unique($detailIn)), 'colors' => $cleanColors], __DIR__);`;

const PHP_RECORD_OLD = `    'description' => trim((string)($item['description'] ?? '')), 'detail_images' => clean_images($media['detail_images'] ?? []),`;
const PHP_RECORD_NEW = `    'description' => trim((string)($item['description'] ?? '')), 'image' => (clean_images($media['detail_images'] ?? [])[0] ?? $coverIn), 'detail_images' => clean_images($media['detail_images'] ?? []),`;

const PHP_COPY_OLD = `        $images = [];
        foreach (array_merge($record['detail_images'] ?? [], array_column($record['colors'] ?? [], 'image')) as $src) {`;
const PHP_COPY_NEW = `        $images = [];
        foreach (array_merge([(string)($record['image'] ?? '')], $record['detail_images'] ?? [], array_column($record['colors'] ?? [], 'image')) as $src) {`;

console.log("backup api", backup(API, "computer-photos"));
let php = fs.readFileSync(API, "utf8");
php = replaceOnce(php, PHP_LINK_OLD, PHP_LINK_NEW, "zhangzhang images on list");
php = replaceOnce(php, PHP_MEDIA_OLD, PHP_MEDIA_NEW, "save cover into media");
php = replaceOnce(php, PHP_RECORD_OLD, PHP_RECORD_NEW, "persist image field");
php = replaceOnce(php, PHP_COPY_OLD, PHP_COPY_NEW, "sync cover to zhangzhang");
fs.writeFileSync(API, php, "utf8");

const liveHtml = fs.readFileSync(HTML, "utf8");
if (liveHtml.indexOf(MARKER) === -1) throw new Error("thumbnail field missing");
if (liveHtml.indexOf("\uFFFD") !== -1) throw new Error("computer html has FFFD");
if (liveHtml.indexOf("admin-products-computer.js?v=" + STAMP) === -1) throw new Error("js stamp missing");
if (fs.readFileSync(CSS, "utf8").indexOf(STAMP) === -1) throw new Error("css stamp missing");
console.log("LINGZANZAN computer photos ok", STAMP);
