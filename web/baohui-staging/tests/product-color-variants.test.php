<?php
declare(strict_types=1);

$failed = 0;
function expect($ok, string $msg): void
{
    global $failed;
    if ($ok) {
        echo "ok  $msg\n";
        return;
    }
    $failed++;
    echo "FAIL  $msg\n";
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'product-color-variants-lib.php';

expect(compare_barcode_color_codes('90', '92') < 0, '90 sorts before 92');
expect(compare_barcode_color_codes('98', '904') < 0, '98 sorts before 904');
expect(compare_barcode_color_codes('901', '904') < 0, '901 sorts before 904');
expect(compare_barcode_color_codes('904', '92') > 0, '904 sorts after 92');

$sorted = sort_barcode_color_variants([
    ['code' => '904', 'name' => '卡其色', 'qty' => 2],
    ['code' => '92', 'name' => '白色 / PUTIH', 'qty' => 1],
    ['code' => '98', 'name' => '粉紅', 'qty' => 1],
]);
expect(array_column($sorted, 'code') === ['92', '98', '904'], 'picked colors sort by numeric code');

$parsed = parse_posted_color_variants([
    'color_variants' => json_encode([
        ['code' => '904', 'name' => '卡其色', 'qty' => 2],
        ['code' => '92', 'name' => '白色 / PUTIH', 'qty' => 1],
        ['code' => '92', 'name' => '白色 / PUTIH', 'qty' => 1],
    ], JSON_UNESCAPED_UNICODE),
], 0);
expect(count($parsed) === 2, 'duplicate color codes merge');
expect($parsed[0]['code'] === '92' && $parsed[0]['qty'] === 2, 'merged white qty is 2 and stays first');
expect($parsed[1]['code'] === '904' && $parsed[1]['qty'] === 2, 'khaki remains qty 2');

$fallback = parse_posted_color_variants([
    'color' => '白色 / PUTIH、粉紅',
    'color_code' => '92、98',
], 3);
expect(count($fallback) === 2, 'legacy joined colors still explode');
expect($fallback[0]['code'] === '92' && $fallback[0]['qty'] === 3, 'legacy total qty stays on first color');
expect($fallback[1]['code'] === '98' && $fallback[1]['qty'] === 0, 'legacy extra colors start at qty 0');

$blank = parse_posted_color_variants([], 4);
expect(count($blank) === 1 && $blank[0]['code'] === '' && $blank[0]['qty'] === 4, 'no color still creates one sku with fallback qty');

$map = color_code_image_lookup([
    ['code' => '92', 'image' => 'uploads/products/white.jpg'],
    ['code' => 'xx', 'image' => ''],
]);
expect(($map['92'] ?? '') === 'uploads/products/white.jpg', 'color thumb lookup keeps 92');

$rows = upsert_color_code_image_rows([], ['98' => 'uploads/products/pink.jpg']);
expect(($rows[0]['code'] ?? '') === '98' && ($rows[0]['image'] ?? '') === 'uploads/products/pink.jpg', 'new color thumb is stored');
$rows = upsert_color_code_image_rows($rows, ['98' => 'uploads/products/pink-2.jpg']);
expect(count($rows) === 1 && $rows[0]['image'] === 'uploads/products/pink-2.jpg', 'same color thumb is replaced');

if ($failed > 0) {
    fwrite(STDERR, $failed . " assertion(s) failed\n");
    exit(1);
}
echo "all passed\n";
