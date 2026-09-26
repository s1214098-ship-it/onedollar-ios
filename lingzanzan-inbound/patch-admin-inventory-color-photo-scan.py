#!/usr/bin/env python3
"""Fix warehouse color-card photo lookup: 棕色 vs 棕色(cokelat) still finds the photo."""
from pathlib import Path
import sys

p = Path(sys.argv[1] if len(sys.argv) > 1 else 'admin-inventory-color-auto-10.js')
s = p.read_text()
old = """        var colorMeta = (product.colors || []).find(function (item) { return canonicalInventoryColor(item.name || item.color || item.code || '').name === color; });
        var ownImage = colorMeta && colorMeta.image ? colorMeta.image : '';"""
new = """        var representative = ((displayColorGroups[color][0] && displayColorGroups[color][0].skus) || [])[0] || {};
        /* LZ_COLOR_PHOTO_SCAN_20260926: match by canonical name OR code OR sku photo. 棕色==棕色(cokelat). */
        var colorMeta = (typeof inventoryFindProductColor === 'function'
          ? inventoryFindProductColor(product, color, representative.colorCode || representative.colorNo, representative.colorImage || representative.image)
          : null) || (product.colors || []).find(function (item) {
          var canonicalName = canonicalInventoryColor(item.name || item.color || item.code || '').name;
          return canonicalName === color
            || canonicalName === canonicalInventoryColor(color).name
            || String(item.name || item.color || item.colorName || '') === color;
        });
        var ownImage = (colorMeta && colorMeta.image) || representative.colorImage || representative.image || '';"""
if 'LZ_COLOR_PHOTO_SCAN_20260926' in s and old not in s:
    print('already patched cards', p)
else:
    if old not in s:
        raise SystemExit('color card lookup missing')
    s = s.replace(old, new, 1)

old2 = """  function stocktakeSkuImage(product, sku) {
    var color = sku.colorName || sku.color || '';
    var colorMeta = (product.colors || []).find(function (item) {
      return item.name === color || item.code === color;
    });
    return (colorMeta && colorMeta.image) || product.mainImage || './assets/brand-logo.png';
  }"""
new2 = """  function stocktakeSkuImage(product, sku) {
    var color = sku.colorName || sku.color || '';
    var colorMeta = typeof inventoryFindProductColor === 'function'
      ? inventoryFindProductColor(product, color, sku.colorCode || sku.colorNo, sku.colorImage || sku.image)
      : (product.colors || []).find(function (item) {
        return item.name === color || item.code === color;
      });
    return (colorMeta && colorMeta.image) || sku.colorImage || sku.image || product.mainImage || './assets/brand-logo.png';
  }"""
if old2 in s:
    s = s.replace(old2, new2, 1)

p.write_text(s)
print('patched', p)
print('scanMarker', 'LZ_COLOR_PHOTO_SCAN_20260926' in s)
print('barcodeStill', 'LZ_BARCODE_UNIFY_20260926' in s)
