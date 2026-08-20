#!/usr/bin/env node
"use strict";

/**
 * 預購倉打單：不管台灣／中國／印尼有沒有庫存，後台所有產品的
 * 顏色×尺寸都要看得到、勾得到。沒建 SKU 的規格用預購等待列補上。
 *
 * Cache-bust: admin.js ?v=20260820-preorder-catalog-all-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const STAMP = "20260820-preorder-catalog-all-1";
const MARKER = "manualPreorderDeclaredCatalogRows";

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
    // latin1 round-trip is byte-preserving. Never decode HTML as UTF-8.
    const html = fs.readFileSync(file, "latin1");
    if (html.indexOf("admin.js") === -1) continue;
    const next = html.replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP);
    if (next === html) continue;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n += 1;
    console.log("stamped", name);
  }
  console.log("html stamped", n);
}

const DECLARED_HELPER = `  function manualPreorderProductColorNames(product) {
    return (Array.isArray(product && product.colors) ? product.colors : []).map(function (item) {
      if (!item) return '';
      if (typeof item === 'string') return String(item).trim();
      return String(item.name || item.colorName || item.color || '').trim();
    }).filter(Boolean);
  }
  function manualPreorderProductSizeNames(product) {
    return (Array.isArray(product && product.sizes) ? product.sizes : []).map(function (item) {
      if (!item) return '';
      if (typeof item === 'string') return String(item).trim();
      return String(item.name || item.sizeName || item.size || '').trim();
    }).filter(Boolean);
  }
  function manualPreorderDeclaredCatalogRows(existingRows) {
    var seen = {};
    (existingRows || []).forEach(function (row) {
      seen[manualPreorderVariantIdentity(row)] = true;
    });
    var extra = [];
    (state.products || []).forEach(function (product) {
      if (!product || !(product.id || product.code)) return;
      var related = (existingRows || []).filter(function (row) {
        var productId = String(product.id || '');
        var productCode = freightDemandIdentity(labelBarcodeBaseProductCode(product.code || product.productCode || product.id || '') || product.code || '');
        var rowId = String(row.product && row.product.id || '');
        var rowCode = freightDemandIdentity(labelBarcodeBaseProductCode(row.product && (row.product.code || row.product.productCode || row.product.id) || '') || '');
        return (productId && rowId === productId) || (productCode && rowCode === productCode);
      });
      var colors = manualPreorderProductColorNames(product);
      related.forEach(function (row) {
        var color = strictSkuColorName(row.sku);
        if (color && colors.indexOf(color) === -1) colors.push(color);
      });
      if (!colors.length) colors = ['未填顏色'];
      var sizes = manualPreorderProductSizeNames(product);
      related.forEach(function (row) {
        var size = String(row.sku && (row.sku.sizeName || row.sku.size) || 'NO SIZE').trim() || 'NO SIZE';
        if (sizes.indexOf(size) === -1) sizes.push(size);
      });
      if (!sizes.length) sizes = ['NO SIZE'];
      colors.forEach(function (color) {
        var colorMeta = (Array.isArray(product.colors) ? product.colors : []).find(function (item) {
          return item && String(item.name || item.colorName || item.color || '').trim() === color;
        }) || {};
        sizes.forEach(function (size) {
          var fakeSku = {
            id: 'PREORDER-WAIT:' + String(product.code || product.id || '') + ':' + color + ':' + size,
            sku: 'PREORDER-WAIT:' + String(product.code || product.id || '') + ':' + color + ':' + size,
            colorName: color,
            color: color,
            sizeName: size,
            size: size,
            stock: 0,
            warehouse: '預購倉',
            warehouseName: '預購倉',
            warehouseCode: 'PREORDER',
            productId: product.id,
            colorImage: colorMeta.image || product.mainImage || '',
            sourceType: 'preorder_catalog'
          };
          var variant = { product: product, sku: fakeSku, sourceType: 'preorder_catalog' };
          var key = manualPreorderVariantIdentity(variant);
          if (seen[key]) return;
          seen[key] = true;
          extra.push(variant);
        });
      });
    });
    return extra;
  }
`;

const FORMAL_OLD = `    var formalRows = (state.skus || []).filter(function (sku) { return !sku.status || sku.status === 'active'; }).map(function (sku) {`;
const FORMAL_NEW = `    var formalRows = (state.skus || []).filter(function (sku) {
      if (!sku || sku.archived === true || sku.active === false) return false;
      var status = String(sku.status || '').toLowerCase();
      return status !== 'inactive' && status !== 'deleted';
    }).map(function (sku) {`;

const CONCAT_OLD = `    var rows = manualPreorderStableSortRows(formalRows.concat(freightRows));`;
const CONCAT_NEW = `    var declaredRows = manualPreorderDeclaredCatalogRows(formalRows.concat(freightRows));
    var rows = manualPreorderStableSortRows(formalRows.concat(freightRows).concat(declaredRows));`;

const SLICE_OLD = `      return group;
    }).slice(0, 10);
  }`;
const SLICE_NEW = `      return group;
    }).slice(0, 80);
  }`;

const FIFO_OLD = `      if (source !== 'PREORDER' && freightSkuWarehouseCode(sku) !== source) return false;
      if (source !== 'PREORDER' && Math.max(0, Number(sku.stock || 0)) <= 0) return false;`;
const FIFO_NEW = `      if (source !== 'PREORDER' && freightSkuWarehouseCode(sku) !== source) return false;
      if (source !== 'PREORDER' && Math.max(0, Number(sku.stock || 0)) <= 0) return false;
      // 預購倉不看各倉有無庫存，只排除作廢列。`;

const FIFO_SLICE_OLD = `      return String(left.code || left.title).localeCompare(String(right.code || right.title), 'zh-Hant');
    }).slice(0, 30);
  }`;
const FIFO_SLICE_NEW = `      return String(left.code || left.title).localeCompare(String(right.code || right.title), 'zh-Hant');
    }).slice(0, 80);
  }`;

const FIFO_RETURN_OLD = `    }).filter(function (sku) {
      if (source !== 'PREORDER') return true;
      var key = [
        String(sku.productId || ''),
        freightDemandIdentity(sku.colorName || sku.color),
        freightDemandIdentity(canonicalNoSize(sku.sizeName || sku.size || 'NO SIZE'))
      ].join('|');
      if (seen[key]) return false;
      seen[key] = true;
      return true;
    }).map(function (sku) {`;

const FIFO_RETURN_NEW = `    }).filter(function (sku) {
      if (source !== 'PREORDER') return true;
      var key = [
        String(sku.productId || ''),
        freightDemandIdentity(sku.colorName || sku.color),
        freightDemandIdentity(canonicalNoSize(sku.sizeName || sku.size || 'NO SIZE'))
      ].join('|');
      if (seen[key]) return false;
      seen[key] = true;
      return true;
    }).map(function (sku) {`;

const FIFO_MAP_END_OLD = `        price: Math.max(0, Number(sku.price || product.price || 0))
      };
    }).sort(function (left, right) {`;

const FIFO_MAP_END_NEW = `        price: Math.max(0, Number(sku.price || product.price || 0))
      };
    });
    if (source === 'PREORDER') {
      var skuRows = mappedRows;
      mappedRows = skuRows.concat(manualPreorderDeclaredCatalogRows(skuRows.map(function (row) {
        return { product: row.product, sku: row.sku, sourceType: 'formal_inventory' };
      })).filter(function (row) {
        var product = row.product || {};
        var sku = row.sku || {};
        var searchable = searchLooseNormalize([
          product.code, product.productLine, product.title, product.name, product.frontTitle,
          product.englishName, product.nameEn, product.titleEn, product.englishTitle,
          Array.isArray(product.nameAliases) ? product.nameAliases.join(' ') : '',
          sku.id, sku.sku, sku.colorName, sku.color, sku.sizeName, sku.size
        ].filter(Boolean).join(' '));
        var needle = searchLooseNormalize(keyword);
        return !needle || searchable.indexOf(needle) !== -1;
      }).map(function (row) {
        var product = row.product || {};
        var sku = row.sku || {};
        return {
          sku: sku,
          product: product,
          code: product.code || product.productLine || sku.productCode || '',
          title: product.title || product.name || sku.productName || '未命名商品',
          color: sku.colorName || sku.color || '未設定顏色',
          size: canonicalNoSize(sku.sizeName || sku.size || 'NO SIZE'),
          image: sku.colorImage || sku.image || product.mainImage || product.image || product.cover || '',
          stock: 0,
          price: Math.max(0, Number(sku.price || product.price || 0))
        };
      }));
    }
    return mappedRows.sort(function (left, right) {`;

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(ADMIN_JS, "preorder-catalog-all"));
let src = fs.readFileSync(ADMIN_JS, "utf8");

if (src.indexOf(MARKER) === -1) {
  src = replaceOnce(
    src,
    "  function manualPreorderFreightSkuRows() {",
    DECLARED_HELPER + "  function manualPreorderFreightSkuRows() {",
    "declared catalog helper"
  );
  src = replaceOnce(src, FORMAL_OLD, FORMAL_NEW, "include non-inactive skus");
  src = replaceOnce(src, CONCAT_OLD, CONCAT_NEW, "merge declared color/size rows");
  src = replaceOnce(src, SLICE_OLD, SLICE_NEW, "show more product groups");
} else {
  console.log("js catalog helper already present");
}

if (src.indexOf("mappedRows = skuRows.concat(manualPreorderDeclaredCatalogRows") === -1) {
  src = replaceOnce(
    src,
    "    var seen = {};\n    return (state.skus || []).filter(function (sku) {\n      if (!sku || sku.archived === true || sku.active === false || String(sku.status || '').toLowerCase() === 'inactive') return false;",
    "    var seen = {};\n    var mappedRows = (state.skus || []).filter(function (sku) {\n      if (!sku || sku.archived === true || sku.active === false || String(sku.status || '').toLowerCase() === 'inactive') return false;",
    "fifo mappedRows start"
  );
  src = replaceOnce(src, FIFO_MAP_END_OLD, FIFO_MAP_END_NEW, "fifo PREORDER includes declared catalog");
  src = replaceOnce(src, FIFO_SLICE_OLD, FIFO_SLICE_NEW, "fifo result cap 80");
} else {
  console.log("fifo PREORDER catalog already patched");
}

fs.writeFileSync(ADMIN_JS, src);
console.log("js written", src.length);
stampHtml(ROOT);
console.log("LINGZANZAN preorder catalog all:", STAMP);
