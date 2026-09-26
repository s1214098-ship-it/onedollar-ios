#!/usr/bin/env python3
"""Patch live scanner-api.php generated labels to inbound concat."""
from pathlib import Path
import sys

p = Path(sys.argv[1] if len(sys.argv) > 1 else 'scanner-api.php')
s = p.read_text()
if "require_once __DIR__ . DIRECTORY_SEPARATOR . 'barcode-format-lib.php';" not in s:
    old_req = "require_once __DIR__ . DIRECTORY_SEPARATOR . 'barcode-integrity-lib.php';\n"
    new_req = (
        "require_once __DIR__ . DIRECTORY_SEPARATOR . 'barcode-integrity-lib.php';\n"
        "require_once __DIR__ . DIRECTORY_SEPARATOR . 'barcode-format-lib.php';\n"
    )
    if old_req not in s:
        raise SystemExit('scanner-api require block missing')
    s = s.replace(old_req, new_req, 1)

old = """function scanner_generated_label_barcode(array $product,array $sku): string {
    // LZ_BARCODE_SERIAL_PEND_20260924 canonical: serial C color S size P cost
    $parts=scanner_generated_label_parts($product,$sku);
    if(($parts['base']??'')===''||($parts['cost']??0)<=0||($parts['color']??'')==='') return '';
    return $parts['base'].'C'.$parts['color'].'S'.$parts['size'].'P'.$parts['cost'];
}"""
new = """function scanner_generated_label_barcode(array $product,array $sku): string {
    // LZ_BARCODE_UNIFY_20260926 canonical: {編號}{色碼}{尺碼}P{成本}. Never C/S.
    $parts=scanner_generated_label_parts($product,$sku);
    if(($parts['base']??'')===''||($parts['cost']??0)<=0||($parts['color']??'')==='') return '';
    $composed=lz_barcode_compose((string)$parts['base'],(string)$parts['color'],(string)($parts['size']??'00'),$parts['cost']);
    return $composed!==''?$composed:$parts['base'].(string)$parts['color'].lz_barcode_pad_size($parts['size']??'00').'P'.$parts['cost'];
}"""
if 'LZ_BARCODE_UNIFY_20260926 canonical' in s and old not in s:
    print('already patched', p)
    p.write_text(s)
    raise SystemExit(0)
if old not in s:
    raise SystemExit('scanner_generated_label_barcode block missing')
s = s.replace(old, new, 1)
p.write_text(s)
print('patched', p)
