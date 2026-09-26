<?php
declare(strict_types=1);

function receipt_text($value): string {
    return trim((string)($value ?? ''));
}
function receipt_first_text(array $values): string {
    foreach ($values as $value) {
        $text = receipt_text($value);
        if ($text !== '') return $text;
    }
    return '';
}
function receipt_normalize_product_code($value): string {
    return strtoupper((string)preg_replace('/[^A-Z0-9]/i', '', (string)($value ?? '')));
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($value, $encoding = null) { return strtolower((string)$value); }
}
if (!function_exists('mb_strpos')) {
    function mb_strpos($haystack, $needle, $offset = 0, $encoding = null) { return strpos((string)$haystack, (string)$needle, $offset); }
}

$src = file_get_contents(__DIR__ . '/stock-inquiry-api.php');
if ($src === false) {
    fwrite(STDERR, "missing api\n");
    exit(1);
}
foreach ([
    'receipt_is_placeholder_inbound_color',
    'purchase_receipt_line_barcode_set',
    'purchase_receipt_color_loose_same',
    'purchase_receipt_find_delete_line_index',
] as $name) {
    if (!preg_match('/function ' . preg_quote($name, '/') . '\(.*?^}/ms', $src, $m)) {
        fwrite(STDERR, "missing $name\n");
        exit(1);
    }
        eval($m[0]);
}

$lines = [
    ['skuId' => 'sku-1', 'barcode' => 'SET0039403P570', 'color' => '未填顏色', 'size' => 'M', 'productCode' => 'SET003'],
    ['skuId' => 'sku-2', 'barcode' => 'SET00390203P570', 'companyBarcode' => 'SET00390203P570', 'color' => '咖色', 'size' => 'M', 'productCode' => 'SET003'],
    ['skuId' => 'sku-3', 'barcode' => 'SET00390703P570', 'color' => '圖片色', 'size' => 'M', 'productCode' => 'SET003', 'barcodeAliases' => ['SET00390703P570']],
];

$hit = purchase_receipt_find_delete_line_index($lines, 2, 'sku-3', 'SET00390703P570', '咖色(cokelat)', 'M', 'SET003');
if ($hit !== 2) {
    fwrite(STDERR, "expected index 2 by barcode despite color mismatch, got $hit\n");
    exit(1);
}

$hit = purchase_receipt_find_delete_line_index($lines, 0, '', 'SET0039403P570', '白色(PUTI)', 'M', 'SET003');
if ($hit !== 0) {
    fwrite(STDERR, "expected index 0 for 未填顏色 vs display white, got $hit\n");
    exit(1);
}

$hit = purchase_receipt_find_delete_line_index($lines, 99, '', 'SET00390203P570', '咖色(cokelat)', 'L', 'SET003');
if ($hit !== 1) {
    fwrite(STDERR, "expected unique barcode hit 1, got $hit\n");
    exit(1);
}

$miss = purchase_receipt_find_delete_line_index($lines, 1, '', 'NO-SUCH', 'x', 'M', 'ZZZ');
if ($miss !== -1) {
    fwrite(STDERR, "expected miss, got $miss\n");
    exit(1);
}

echo "ok\n";
