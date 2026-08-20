#!/usr/bin/env node
"use strict";

/**
 * 產品管理搜尋常漏：頁面跑的是 admin-product-forwarder-cost-8.js，
 * 上方搜尋比正式商品嚴格、未建檔的集運款（SE181）會空白。
 * 放寬編號／SKU 比對；找不到時查集運與詢問，結果顯示在搜尋框下方。
 *
 * Cache-bust: admin-product-forwarder-cost-8.js / admin-products-horizontal-9.css
 *             admin.js / admin-products.html
 *             ?v=20260821-product-search-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const STAMP = "20260821-product-search-1";
const MARKER = "function productCatalogMatchesQuery(";
const CSS_MARKER = "/* 20260821 product search status */";
const PAGES = [
  path.join(__dirname, "..", "lingzanzan-pages"),
  path.join(__dirname, "lingzanzan-pages"),
].find((dir) => fs.existsSync(path.join(dir, "admin-product-search-api.php")));

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

function stampHtml(file, replacements) {
  if (!fs.existsSync(file)) return false;
  const html = fs.readFileSync(file, "latin1");
  let next = html;
  replacements.forEach(([re, value]) => {
    next = next.replace(re, value);
  });
  if (next === html) return false;
  fs.writeFileSync(file, Buffer.from(next, "latin1"));
  console.log("stamped", path.basename(file));
  return true;
}

const HELPER = `  function productCatalogMatchesQuery(product, query) {
    var raw = String(query || '').trim();
    if (!raw) return true;
    if (productSearchRank(product, null, raw, productSearchText(product)) < 9999) return true;
    var skus = productSkus(state.skus, product.id);
    for (var i = 0; i < skus.length; i++) {
      if (productSearchRank(product, skus[i], raw) < 9999) return true;
    }
    return false;
  }
  var productMissSearchSeq = 0;
  function productAdminAuthHeaders() {
    var headers = {};
    try {
      var login = currentLogin() || {};
      var token = login.sessionToken || login.token || '';
      if (token) {
        headers.Authorization = 'Bearer ' + token;
        headers['X-Lingzanzan-Admin-Session'] = token;
      }
    } catch (error) {}
    return headers;
  }
  function renderProductSearchStatus(query, catalogCount, extra) {
    var box = document.querySelector('[data-product-search-status]');
    if (!box) return;
    extra = extra || {};
    if (!query) {
      box.hidden = true;
      box.innerHTML = '';
      box.className = 'product-search-status';
      return;
    }
    box.hidden = false;
    if (catalogCount > 0) {
      box.className = 'product-search-status is-found';
      box.innerHTML = '<strong>正式商品找到 ' + catalogCount + ' 筆</strong><p>會找編號、名稱、品牌、規格、SKU 與條碼。每頁仍只顯示 10 筆，請用下方頁碼翻頁；現貨、預購、下架、樣品分區列出。</p>';
      return;
    }
    var freight = Array.isArray(extra.freight) ? extra.freight : [];
    var inquiries = Array.isArray(extra.inquiries) ? extra.inquiries : [];
    var loading = extra.loading === true;
    var freightHtml = freight.slice(0, 8).map(function (row) {
      return '<li><b>' + escapeHtml(row.barcode || row.catalogCode || '') + '</b> ' + escapeHtml(row.title || '') + '　' + escapeHtml(row.progress || row.haohong || '') + (row.qty ? '　x' + escapeHtml(String(row.qty)) : '') + '</li>';
    }).join('');
    var inquiryHtml = inquiries.slice(0, 8).map(function (row) {
      return '<li><b>' + escapeHtml(row.sku || row.code || '') + '</b> ' + escapeHtml(row.title || '') + '　' + escapeHtml(row.orderId || '') + '　' + escapeHtml(row.status || '') + '</li>';
    }).join('');
    box.className = 'product-search-status ' + (loading ? 'is-loading' : (freight.length || inquiries.length ? 'is-forecast' : 'is-miss'));
    box.innerHTML = [
      '<strong>正式產品檔找不到「' + escapeHtml(query) + '」</strong>',
      loading ? '<p>正在查集運預報與客戶詢問…</p>' : '<p>這個編號還沒建成正式商品。豪鴻已收到或客戶已下預購，都還不會出現在產品清單。</p>',
      freight.length ? '<p><b>集運／豪鴻 ' + freight.length + ' 筆</b></p><ul>' + freightHtml + '</ul>' : '',
      inquiries.length ? '<p><b>客戶詢問／預購 ' + inquiries.length + ' 筆</b></p><ul>' + inquiryHtml + '</ul>' : '',
      !loading ? '<p>請到「物流集運」用同一編號查，或到「預購打單／客戶詢問」搜尋。正式入庫完成後才會出現在這一頁。</p>' : ''
    ].join('');
  }
  function refreshProductSearchMissHints(query, catalogCount) {
    renderProductSearchStatus(query, catalogCount, catalogCount > 0 ? {} : { loading: true });
    if (!query || catalogCount > 0) return;
    var seq = ++productMissSearchSeq;
    fetch('./admin-product-search-api.php?q=' + encodeURIComponent(query), {
      headers: productAdminAuthHeaders(),
      cache: 'no-store'
    }).then(function (res) { return res.json(); }).then(function (data) {
      if (seq !== productMissSearchSeq) return;
      renderProductSearchStatus(query, catalogCount, data && data.ok ? data : {});
    }).catch(function () {
      if (seq !== productMissSearchSeq) return;
      renderProductSearchStatus(query, catalogCount, {});
    });
  }

  function productSearchRank(product, sku, query, extraText) {`;

const CSS_PATCH = `
${CSS_MARKER}
.product-search-status {
  display: grid;
  gap: 8px;
  margin: 12px 0 4px;
  padding: 12px 14px;
  border-radius: 14px;
  border: 1px solid rgba(53, 194, 174, .38);
  background: rgba(16, 39, 40, .78);
  color: #d9fff8;
}
.product-search-status[hidden] { display: none !important; }
.product-search-status strong { color: #fff8ed; }
.product-search-status p { margin: 0; color: #b9fff3; }
.product-search-status ul { margin: 0; padding-left: 18px; }
.product-search-status li { margin: 2px 0; }
.product-search-status.is-found {
  border-color: rgba(246, 189, 81, .42);
  background: rgba(42, 32, 18, .78);
  color: #ffe7b0;
}
.product-search-status.is-found p { color: #ffe7b0; }
.product-search-status.is-miss,
.product-search-status.is-forecast {
  border-color: rgba(224, 92, 126, .45);
  background: rgba(48, 18, 28, .78);
}
.product-search-status.is-miss p,
.product-search-status.is-forecast p { color: #ffd2dc; }
.admin-empty-state {
  margin: 12px 0;
  padding: 18px 16px;
  border-radius: 16px;
  border: 1px dashed rgba(224, 92, 126, .45);
  background: rgba(48, 18, 28, .55);
  color: #ffd2dc;
}
.admin-empty-state strong { display: block; margin-bottom: 8px; color: #fff8ed; }
.admin-empty-state p { margin: 0; }
`;

function patchForwarder(src) {
  if (src.indexOf(MARKER) === -1) {
    src = replaceOnce(src, "  function productSearchRank(product, sku, query, extraText) {", HELPER, "forwarder search helpers");
  } else {
    console.log("already: forwarder helpers");
  }

  src = replaceOnce(
    src,
    `      else if (compact.length >= 2 && text.charAt(0) === compact.charAt(0) && text.indexOf(compact) > 0) best = Math.min(best, 4);`,
    `      else if (compact.length >= 2 && text.indexOf(compact) !== -1) best = Math.min(best, 4);`,
    "forwarder sku contains match"
  );
  src = replaceOnce(
    src,
    `      else if (compact.length >= 2 && text.indexOf(compact) > 0) best = Math.min(best, 5);`,
    `      else if (compact.length >= 2 && text.indexOf(compact) !== -1) best = Math.min(best, 5);`,
    "forwarder name contains match"
  );

  src = replaceOnce(
    src,
    `    return searchTextNormalize([
      product.id, product.code, product.title, product.frontTitle, product.category, product.productLine,
      product.brand, product.brandName, product.spec, product.specification, product.variantSpec,
      product.description, product.productMode, product.status,`,
    `    return searchTextNormalize([
      product.id, product.code, product.title, product.frontTitle, product.name,
      product.englishName, product.nameEn, product.titleEn, product.englishTitle,
      (Array.isArray(product.nameAliases) ? product.nameAliases : []).join(' '),
      product.category, product.productLine,
      product.brand, product.brandName, product.spec, product.specification, product.variantSpec,
      product.description, product.productMode, product.status,`,
    "forwarder search includes aliases"
  );

  src = replaceOnce(
    src,
    `    var productQuery = searchTextNormalize(document.querySelector('[data-product-search]')?.value || '');
    var warehouseFilter = document.querySelector('[data-product-warehouse-filter]')?.value || 'all';
    var shelfFilter = document.querySelector('[data-product-shelf-filter]')?.value || 'all';
    var layerFilter = document.querySelector('[data-product-layer-filter]')?.value || 'all';
    var list = document.querySelector('[data-admin-products]');
    if (!list) return;
    var filteredProducts = state.products.filter(function (product) {
      var pSkus = productSkus(state.skus, product.id);
      var location = productLocation(product, pSkus);
      var text = productSearchText(product);
      return (!productQuery || text.indexOf(productQuery) !== -1) &&
        matchesFilter(location.warehouse, warehouseFilter) &&
        matchesFilter(location.shelf, shelfFilter) &&
        matchesFilter(location.layer, layerFilter);
    });
    filteredProducts = sortProductsByCurrentRule(filteredProducts);`,
    `    var productQueryRaw = String(document.querySelector('[data-product-search]')?.value || '').trim();
    var productQuery = searchTextNormalize(productQueryRaw);
    var warehouseFilter = document.querySelector('[data-product-warehouse-filter]')?.value || 'all';
    var shelfFilter = document.querySelector('[data-product-shelf-filter]')?.value || 'all';
    var layerFilter = document.querySelector('[data-product-layer-filter]')?.value || 'all';
    var list = document.querySelector('[data-admin-products]');
    if (!list) return;
    var filteredProducts = state.products.filter(function (product) {
      var pSkus = productSkus(state.skus, product.id);
      var location = productLocation(product, pSkus);
      return (!productQueryRaw || productCatalogMatchesQuery(product, productQueryRaw)) &&
        matchesFilter(location.warehouse, warehouseFilter) &&
        matchesFilter(location.shelf, shelfFilter) &&
        matchesFilter(location.layer, layerFilter);
    });
    filteredProducts = sortProductsByCurrentRule(filteredProducts);`,
    "forwarder top search uses rank"
  );

  src = replaceOnce(
    src,
    `    var orderedProducts = readyAll.concat(preorderAll, chinaSampleAll, offlineAll);
    var totalFilteredProducts = orderedProducts.length;`,
    `    var orderedProducts = readyAll.concat(preorderAll, chinaSampleAll, offlineAll);
    var totalFilteredProducts = orderedProducts.length;
    refreshProductSearchMissHints(productQueryRaw, totalFilteredProducts);`,
    "forwarder search status banner"
  );

  src = replaceOnce(
    src,
    `    if (productQuery && !totalFilteredProducts && !activeSectionSearch) {
      list.innerHTML = '<section class="admin-empty-state" role="status"><strong>找不到「' + escapeHtml(document.querySelector('[data-product-search]')?.value || '') + '」的正式商品或 SKU</strong><p>如果剛從物流集運完成驗收，代表正式商品建檔或 SKU 寫入尚未完成。請回物流集運，對該筆貨物繼續完成正式入庫。</p></section>';`,
    `    if (productQuery && !totalFilteredProducts) {
      list.innerHTML = '<section class="admin-empty-state" role="status"><strong>找不到「' + escapeHtml(productQueryRaw || document.querySelector('[data-product-search]')?.value || '') + '」的正式商品或 SKU</strong><p>正式產品檔沒有這一筆。若集運或詢問有貨，說明會顯示在上方搜尋框下面；請到物流集運完成正式入庫後才會出現在產品清單。</p></section>';`,
    "forwarder empty state always explains"
  );

  return src;
}

function patchAdminJs(src) {
  if (src.indexOf(MARKER) === -1) {
    src = replaceOnce(src, "  function productSearchRank(product, sku, query, extraText) {", HELPER, "admin.js search helpers");
  } else {
    console.log("already: admin.js helpers");
  }

  src = replaceOnce(
    src,
    `    var productQuery = searchTextNormalize(document.querySelector('[data-product-search]')?.value || '');
    var warehouseFilter = document.querySelector('[data-product-warehouse-filter]')?.value || 'all';
    var shelfFilter = document.querySelector('[data-product-shelf-filter]')?.value || 'all';
    var layerFilter = document.querySelector('[data-product-layer-filter]')?.value || 'all';
    var priceSort = document.querySelector('[data-product-price-sort]')?.value || 'default';
    var list = document.querySelector('[data-admin-products]');
    if (!list) return;
    var filteredProducts = state.products.filter(function (product) {
      var pSkus = productSkus(state.skus, product.id);
      var location = productLocation(product, pSkus);
      var text = productSearchText(product);
      return (!productQuery || text.indexOf(productQuery) !== -1) &&
        matchesFilter(location.warehouse, warehouseFilter) &&
        matchesFilter(location.shelf, shelfFilter) &&
        matchesFilter(location.layer, layerFilter);
    });
    if (priceSort !== 'default') {
      filteredProducts = filteredProducts.slice().sort(function (a, b) {
        var field = priceSort.indexOf('cost') === 0 ? 'cost' : 'price';
        var av = productSortNumber(a, field);
        var bv = productSortNumber(b, field);
        return priceSort.indexOf('desc') > -1 ? bv - av : av - bv;
      });
    }
    filteredProducts = filteredProducts.filter(function (product) {
      return !isComputerDepartmentRecord(product);
    });`,
    `    var productQueryRaw = String(document.querySelector('[data-product-search]')?.value || '').trim();
    var productQuery = searchTextNormalize(productQueryRaw);
    var warehouseFilter = document.querySelector('[data-product-warehouse-filter]')?.value || 'all';
    var shelfFilter = document.querySelector('[data-product-shelf-filter]')?.value || 'all';
    var layerFilter = document.querySelector('[data-product-layer-filter]')?.value || 'all';
    var priceSort = document.querySelector('[data-product-price-sort]')?.value || 'default';
    var list = document.querySelector('[data-admin-products]');
    if (!list) return;
    var filteredProducts = state.products.filter(function (product) {
      var pSkus = productSkus(state.skus, product.id);
      var location = productLocation(product, pSkus);
      return (!productQueryRaw || productCatalogMatchesQuery(product, productQueryRaw)) &&
        matchesFilter(location.warehouse, warehouseFilter) &&
        matchesFilter(location.shelf, shelfFilter) &&
        matchesFilter(location.layer, layerFilter);
    });
    if (priceSort !== 'default') {
      filteredProducts = filteredProducts.slice().sort(function (a, b) {
        var field = priceSort.indexOf('cost') === 0 ? 'cost' : 'price';
        var av = productSortNumber(a, field);
        var bv = productSortNumber(b, field);
        return priceSort.indexOf('desc') > -1 ? bv - av : av - bv;
      });
    }
    filteredProducts = filteredProducts.filter(function (product) {
      return !isComputerDepartmentRecord(product);
    });`,
    "admin.js top search uses rank"
  );

  src = replaceOnce(
    src,
    `    var orderedProducts = readyAll.concat(preorderAll, offlineAll);
    var totalFilteredProducts = orderedProducts.length;`,
    `    var orderedProducts = readyAll.concat(preorderAll, offlineAll);
    var totalFilteredProducts = orderedProducts.length;
    refreshProductSearchMissHints(productQueryRaw, totalFilteredProducts);`,
    "admin.js search status banner"
  );

  src = replaceOnce(
    src,
    `    if (productQuery && !totalFilteredProducts && !activeSectionSearch) {
      list.innerHTML = '<section class="admin-empty-state" role="status"><strong>找不到「' + escapeHtml(document.querySelector('[data-product-search]')?.value || '') + '」的正式商品或 SKU</strong><p>如果剛從物流集運完成驗收，代表正式商品建檔或 SKU 寫入尚未完成。請回物流集運，對該筆貨物繼續完成正式入庫。</p></section>';`,
    `    if (productQuery && !totalFilteredProducts) {
      list.innerHTML = '<section class="admin-empty-state" role="status"><strong>找不到「' + escapeHtml(productQueryRaw || document.querySelector('[data-product-search]')?.value || '') + '」的正式商品或 SKU</strong><p>正式產品檔沒有這一筆。若集運或詢問有貨，說明會顯示在上方搜尋框下面；請到物流集運完成正式入庫後才會出現在產品清單。</p></section>';`,
    "admin.js empty state always explains"
  );

  return src;
}

function patchHtml(file) {
  if (!fs.existsSync(file)) return;
  backup(file, "product-search");
  let html = fs.readFileSync(file, "latin1");
  if (html.indexOf("data-product-search-status") === -1) {
    const key = 'value="cost-asc"';
    const i = html.indexOf(key);
    if (i < 0) throw new Error("missing cost-asc in " + file);
    const close = html.indexOf("</div>", i);
    if (close < 0) throw new Error("missing tools close in " + file);
    const at = close + "</div>".length;
    html =
      html.slice(0, at) +
      '\n        <div class="product-search-status" data-product-search-status hidden role="status"></div>' +
      html.slice(at);
    console.log("patched: products html status box");
  } else {
    console.log("already: products html status box");
  }
  fs.writeFileSync(file, Buffer.from(html, "latin1"));
}

function copyApi() {
  if (!PAGES) throw new Error("missing admin-product-search-api.php in repo pages");
  const src = path.join(PAGES, "admin-product-search-api.php");
  const dest = path.join(ROOT, "admin-product-search-api.php");
  backup(dest, "product-search-api");
  fs.copyFileSync(src, dest);
  console.log("copied api", dest, fs.statSync(dest).size);
}

function stampAssets() {
  const htmlReplacements = [
    [/admin-product-forwarder-cost-8\.js(?:\?v=[^"']+)?/g, "admin-product-forwarder-cost-8.js?v=" + STAMP],
    [/admin-products-horizontal-9\.css(?:\?v=[^"']+)?/g, "admin-products-horizontal-9.css?v=" + STAMP],
    [/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP],
  ];
  const dir = ROOT;
  const names = fs.readdirSync(dir).filter((name) => /\.html$/i.test(name));
  let n = 0;
  names.forEach((name) => {
    if (stampHtml(path.join(dir, name), htmlReplacements)) n += 1;
  });
  console.log("html stamped", n);
}

if (!fs.existsSync(path.join(ROOT, "assets"))) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

copyApi();

const forwarder = path.join(ROOT, "assets", "admin-product-forwarder-cost-8.js");
console.log("backup js", backup(forwarder, "product-search"));
let fwd = fs.readFileSync(forwarder, "utf8");
fwd = patchForwarder(fwd);
fs.writeFileSync(forwarder, fwd);
console.log("forwarder written", fwd.length);

const adminJs = path.join(ROOT, "assets", "admin.js");
if (fs.existsSync(adminJs)) {
  console.log("backup admin.js", backup(adminJs, "product-search"));
  let js = fs.readFileSync(adminJs, "utf8");
  js = patchAdminJs(js);
  fs.writeFileSync(adminJs, js);
  console.log("admin.js written", js.length);
}

const cssFile = path.join(ROOT, "assets", "admin-products-horizontal-9.css");
console.log("backup css", backup(cssFile, "product-search"));
let css = fs.readFileSync(cssFile, "utf8");
if (css.indexOf(CSS_MARKER) === -1) {
  fs.writeFileSync(cssFile, css.replace(/\s*$/, "") + "\n" + CSS_PATCH);
  console.log("css patched");
} else {
  console.log("css already patched");
}

patchHtml(path.join(ROOT, "admin-products.html"));
stampAssets();
console.log("done", STAMP);
