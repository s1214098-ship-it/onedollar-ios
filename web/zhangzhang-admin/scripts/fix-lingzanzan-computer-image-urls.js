#!/usr/bin/env node
"use strict";

/**
 * 張張電腦商品圖在 uploads/products/…，電腦建檔頁在領讚讚根目錄會 404。
 * 改成 /one-dollar-auction/uploads/… 才能打開縮圖與放大圖。
 * Cache-bust: admin-products-computer.js/css ?v=20260820-computer-img-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const PAGES = process.env.HH_PAGES || [
  path.join(__dirname, "..", "lingzanzan-pages"),
  path.join(__dirname, "lingzanzan-pages"),
].find((dir) => fs.existsSync(path.join(dir, "admin-products-computer.html")));
const STAMP = "20260820-computer-img-1";
const HTML = path.join(ROOT, "admin-products-computer.html");
const JS = path.join(ROOT, "assets", "admin-products-computer.js");
const API = path.join(ROOT, "computer-products-api.php");
const JS_MARKER = "function publicComputerImage(src)";

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
if (!fs.existsSync(JS) || !fs.existsSync(API)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup html", backup(HTML, "computer-img"));
fs.copyFileSync(path.join(PAGES, "admin-products-computer.html"), HTML);
console.log("copied computer html", fs.statSync(HTML).size);

const JS_THUMB_OLD = `  function itemThumb(item) {
    if (!item) return '';
    if (item.image) return String(item.image);
    if (Array.isArray(item.detail_images) && item.detail_images[0]) return String(item.detail_images[0]);
    if (Array.isArray(item.colors)) {
      for (var i = 0; i < item.colors.length; i++) {
        if (item.colors[i] && item.colors[i].image) return String(item.colors[i].image);
      }
    }
    return '';
  }`;
const JS_THUMB_NEW = `  function publicComputerImage(src) {
    src = String(src || '').trim().replace(/\\\\/g, '/');
    if (!src) return '';
    if (/^data:|^https?:\\/\\//i.test(src)) return src;
    if (src.indexOf('/one-dollar-auction/') === 0) return src;
    if (/^(?:\\.\\/)?uploads\\//.test(src) || src.indexOf('/uploads/') === 0 || src.indexOf('one-dollar-auction/uploads/') === 0) {
      src = src.replace(/^\\.\\//, '').replace(/^\\//, '').replace(/^one-dollar-auction\\//, '');
      return '/one-dollar-auction/' + src;
    }
    return src;
  }

  function itemThumb(item) {
    if (!item) return '';
    var src = '';
    if (item.image) src = String(item.image);
    else if (Array.isArray(item.detail_images) && item.detail_images[0]) src = String(item.detail_images[0]);
    else if (Array.isArray(item.colors)) {
      for (var i = 0; i < item.colors.length; i++) {
        if (item.colors[i] && item.colors[i].image) { src = String(item.colors[i].image); break; }
      }
    }
    return publicComputerImage(src);
  }`;

const JS_OPEN_OLD = `    img.src = src;
    overlay.hidden = false;`;
const JS_OPEN_NEW = `    img.src = publicComputerImage(src);
    overlay.hidden = false;`;

console.log("backup js", backup(JS, "computer-img"));
let js = fs.readFileSync(JS, "utf8");
if (js.indexOf(JS_MARKER) === -1) {
  js = replaceOnce(js, JS_THUMB_OLD, JS_THUMB_NEW, "public image helper");
  js = replaceOnce(js, JS_OPEN_OLD, JS_OPEN_NEW, "overlay uses public url");
} else {
  console.log("already: js public image");
}
if (js.indexOf(JS_MARKER) === -1) throw new Error("js publicComputerImage missing");
fs.writeFileSync(JS, js, "utf8");

const PHP_FN_OLD = `function computer_list_card(array $row): array {
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
const PHP_FN_NEW = `function computer_public_image_url(string $src): string {
    $src = trim(str_replace('\\\\', '/', $src));
    if ($src === '' || str_starts_with($src, 'data:') || preg_match('#^https?://#i', $src)) return $src;
    if (str_starts_with($src, '/one-dollar-auction/')) return $src;
    if (str_starts_with($src, './')) {
        $rest = substr($src, 2);
        return str_starts_with($rest, 'uploads/') ? computer_public_image_url($rest) : $src;
    }
    if (preg_match('#^/?uploads/#', $src) || str_starts_with($src, 'one-dollar-auction/uploads/')) {
        $src = ltrim($src, '/');
        $src = preg_replace('#^one-dollar-auction/#', '', $src);
        return '/one-dollar-auction/' . $src;
    }
    return $src;
}

function computer_public_media(array $row): array {
    $row['image'] = computer_public_image_url((string)($row['image'] ?? ''));
    if (is_array($row['detail_images'] ?? null)) {
        $row['detail_images'] = array_values(array_filter(array_map(
            static fn($url) => computer_public_image_url((string)$url),
            $row['detail_images']
        )));
    }
    if (is_array($row['colors'] ?? null)) {
        foreach ($row['colors'] as &$color) {
            if (!is_array($color)) continue;
            $color['image'] = computer_public_image_url((string)($color['image'] ?? ''));
        }
        unset($color);
    }
    return $row;
}

function computer_list_card(array $row): array {
    $row = computer_public_media($row);
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

const PHP_GET_OLD = `            if (in_array($itemId, $ids, true)) respond(['ok' => true, 'version' => (int)$handoffs['version'], 'item' => $row]);`;
const PHP_GET_NEW = `            if (in_array($itemId, $ids, true)) respond(['ok' => true, 'version' => (int)$handoffs['version'], 'item' => computer_public_media($row)]);`;

console.log("backup api", backup(API, "computer-img"));
let php = fs.readFileSync(API, "utf8");
if (php.indexOf("function computer_public_image_url") === -1) {
  php = replaceOnce(php, PHP_FN_OLD, PHP_FN_NEW, "public image url");
  php = replaceOnce(php, PHP_GET_OLD, PHP_GET_NEW, "get item rewrites urls");
} else {
  console.log("already: php public image");
}
if (php.indexOf("function computer_public_image_url") === -1) throw new Error("php helper missing");
fs.writeFileSync(API, php, "utf8");

const liveHtml = fs.readFileSync(HTML, "utf8");
if (liveHtml.indexOf("\uFFFD") !== -1) throw new Error("computer html has FFFD");
if (liveHtml.indexOf("admin-products-computer.js?v=" + STAMP) === -1) throw new Error("js stamp missing");
console.log("LINGZANZAN computer image urls ok", STAMP);
