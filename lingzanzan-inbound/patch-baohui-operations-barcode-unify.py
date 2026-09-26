#!/usr/bin/env python3
"""Patch 寶輝 operations.php printed barcodes to inbound concat."""
from pathlib import Path
import sys

p = Path(sys.argv[1] if len(sys.argv) > 1 else 'operations.php')
s = p.read_text()
old = """function build_product_cost_barcode($base, $cost, $colorCode = '', $sizeCode = '') {
    $base = normalize_barcode_prefix($base);
    $costPart = (string)max(0, (int)round((float)$cost));
    $colorCode = first_product_code($colorCode);
    $sizeCode = first_product_code($sizeCode);
    if ($base === '' || $costPart === '0' || $colorCode === '') return '';
    if ($sizeCode === '' || $sizeCode === 'NOSIZE') $sizeCode = '00';
    return $base . 'P' . $costPart . 'C' . $colorCode . 'S' . $sizeCode;
}"""
new = """function pad_product_size_code($sizeCode) {
    $sizeCode = first_product_code($sizeCode);
    $map = ['XS'=>'01','S'=>'02','M'=>'03','L'=>'04','XL'=>'05','2XL'=>'06','XXL'=>'06','3XL'=>'07','XXXL'=>'07','4XL'=>'08'];
    if ($sizeCode === '' || $sizeCode === 'NOSIZE' || $sizeCode === 'NO-SIZE') return '00';
    if (isset($map[$sizeCode])) return $map[$sizeCode];
    if (preg_match('/^[0-9]$/', $sizeCode)) return '0' . $sizeCode;
    return $sizeCode;
}
function extract_product_serial_from_barcode($code) {
    $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$code) ?? '');
    if ($code === '') return '';
    if (preg_match('/^([A-Z]+\\d+)C[A-Z0-9]+S[A-Z0-9]+P\\d+$/', $code, $m)) return $m[1];
    if (preg_match('/^([A-Z]+\\d+)P\\d+C[A-Z0-9]+S[A-Z0-9]+$/', $code, $m)) return $m[1];
    if (preg_match('/^([A-Z]+\\d+)\\d{2,4}(?:00|0[1-8])P\\d+$/', $code, $m)) return $m[1];
    if (preg_match('/^([A-Z]+\\d+)P\\d+/', $code, $m)) return $m[1];
    if (preg_match('/^([A-Z]+\\d+)/', $code, $m)) return $m[1];
    return '';
}
function build_product_cost_barcode($base, $cost, $colorCode = '', $sizeCode = '') {
    /* LZ_BARCODE_UNIFY_20260926: same as inbound {編號}{色碼}{尺碼}P{成本}. Never C/S. */
    $base = normalize_barcode_prefix($base);
    $costPart = (string)max(0, (int)round((float)$cost));
    $colorCode = first_product_code($colorCode);
    $sizeCode = pad_product_size_code($sizeCode);
    if ($base === '' || $costPart === '0' || $colorCode === '') return '';
    return $base . $colorCode . $sizeCode . 'P' . $costPart;
}"""
if 'LZ_BARCODE_UNIFY_20260926: same as inbound' in s and old not in s:
    print('already patched', p)
    raise SystemExit(0)
if old not in s:
    raise SystemExit('build_product_cost_barcode block missing')
s = s.replace(old, new, 1)

old_base = """function product_serial_base($product) {
    $base = normalize_barcode_prefix($product['original_product_code'] ?? '');
    if ($base !== '') return $base;
    $barcode = strtoupper(trim((string)($product['barcode'] ?? '')));
    if ($barcode !== '' && preg_match('/^(.+?)P[0-9]/', $barcode, $match)) return normalize_barcode_prefix($match[1]);
    $id = strtoupper(trim((string)($product['id'] ?? '')));
    if ($id !== '' && preg_match('/^(.+?)P[0-9]/', $id, $match)) return normalize_barcode_prefix($match[1]);
    return normalize_barcode_prefix($id);
}"""
new_base = """function product_serial_base($product) {
    $base = normalize_barcode_prefix($product['original_product_code'] ?? '');
    if ($base !== '') return $base;
    $fromBarcode = extract_product_serial_from_barcode($product['barcode'] ?? '');
    if ($fromBarcode !== '') return $fromBarcode;
    $fromId = extract_product_serial_from_barcode($product['id'] ?? '');
    if ($fromId !== '') return $fromId;
    return normalize_barcode_prefix($product['id'] ?? '');
}"""
if old_base not in s:
    raise SystemExit('product_serial_base block missing')
s = s.replace(old_base, new_base, 1)

old_sort = """    if (preg_match('/^([A-Z]+)(\\d*)P(\\d+)C([A-Z0-9]+)S([A-Z0-9]+)$/', $code, $match)) {
        return sprintf('%s-%010d-P-%010d-%s-%s', $match[1], (int)$match[2], (int)$match[3], $match[4], $match[5]);
    }"""
new_sort = """    if (preg_match('/^([A-Z]+)(\\d*)P(\\d+)C([A-Z0-9]+)S([A-Z0-9]+)$/', $code, $match)) {
        return sprintf('%s-%010d-P-%010d-%s-%s', $match[1], (int)$match[2], (int)$match[3], $match[4], $match[5]);
    }
    if (preg_match('/^([A-Z]+)(\\d*)(\\d{2,4})(00|0[1-8])P(\\d+)$/', $code, $match)) {
        return sprintf('%s-%010d-P-%010d-%s-%s', $match[1], (int)$match[2], (int)$match[5], $match[3], $match[4]);
    }"""
if old_sort in s and '(\\d{2,4})(00|0[1-8])P' not in s:
    s = s.replace(old_sort, new_sort, 1)

p.write_text(s)
print('patched', p)
print('marker', 'LZ_BARCODE_UNIFY_20260926' in s)
