#!/usr/bin/env python3
"""Patch live admin-inventory-color-auto-10.js print compose to inbound concat."""
from pathlib import Path
import sys

p = Path(sys.argv[1] if len(sys.argv) > 1 else 'admin-inventory-color-auto-10.js')
s = p.read_text()
old = """  function composeLatestLabelBarcode(productCode, cost, colorCode, sizeCode) {
    var base = labelBarcodeBaseProductCode(productCode);
    var normalizedCost = String(Math.max(0, Math.round(Number(cost || 0))));
    var normalizedColor = normalizeScanCode(colorCode);
    var normalizedSize = normalizeScanCode(sizeCode) || '00';
    if (!base || normalizedCost === '0' || !normalizedColor) return '';
    if (normalizedSize === 'NOSIZE' || normalizedSize === 'NO-SIZE') normalizedSize = '00';
    // V3 printed labels: 編號 + 顏色 + 尺寸 + P台幣. Scan identity stops before P.
    return base + 'C' + normalizedColor + 'S' + normalizedSize + 'P' + normalizedCost;
  }"""
new = """  function padLabelSizeCode(sizeCode) {
    var normalizedSize = normalizeScanCode(sizeCode) || '00';
    if (normalizedSize === 'NOSIZE' || normalizedSize === 'NO-SIZE') normalizedSize = '00';
    if (/^\\d$/.test(normalizedSize)) normalizedSize = '0' + normalizedSize;
    return normalizedSize;
  }

  function composeLatestLabelBarcode(productCode, cost, colorCode, sizeCode) {
    var base = labelBarcodeBaseProductCode(productCode);
    var normalizedCost = String(Math.max(0, Math.round(Number(cost || 0))));
    var normalizedColor = normalizeScanCode(colorCode);
    var normalizedSize = padLabelSizeCode(sizeCode);
    if (!base || normalizedCost === '0' || !normalizedColor) return '';
    /* LZ_BARCODE_UNIFY_20260926: same as inbound {編號}{色碼}{尺碼}P{成本}. Never C/S. */
    return base + normalizedColor + normalizedSize + 'P' + normalizedCost;
  }"""
if 'LZ_BARCODE_UNIFY_20260926' in s and old not in s:
    print('already patched', p)
    raise SystemExit(0)
if old not in s:
    raise SystemExit('composeLatestLabelBarcode block missing')
s = s.replace(old, new, 1)

old_base = """    var identity = raw.match(/^([A-Z]+\\d+)C[A-Z0-9]+S[A-Z0-9]+$/);
    if (identity) return identity[1];
    var legacy = raw.match(/^(.+)P\\d{4,}$/);
    return legacy ? legacy[1] : raw;"""
new_base = """    var identity = raw.match(/^([A-Z]+\\d+)C[A-Z0-9]+S[A-Z0-9]+$/);
    if (identity) return identity[1];
    var concat = typeof searchParseConcatBarcode === 'function' ? searchParseConcatBarcode(raw) : null;
    if (concat && concat.productCode) return concat.productCode;
    var legacy = raw.match(/^(.+)P\\d{4,}$/);
    return legacy ? legacy[1] : raw;"""
if old_base not in s:
    raise SystemExit('labelBarcodeBaseProductCode concat hook missing')
if 'searchParseConcatBarcode(raw)' not in s.split('function composeLatestLabelBarcode', 1)[0]:
    s = s.replace(old_base, new_base, 1)

p.write_text(s)
print('patched', p)
print('marker', 'LZ_BARCODE_UNIFY_20260926' in s)
print('cs_compose', "base + 'C' + normalizedColor + 'S'" in s)
