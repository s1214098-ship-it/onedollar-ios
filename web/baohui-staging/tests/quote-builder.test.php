<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'quote-builder-lib.php';

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

$cpu = ['title' => 'Intel i5-14400', 'category_type' => '處理器', 'category_group' => '組裝硬體'];
$mb = ['title' => '華碩 B650', 'category_type' => '主機板'];
$ssd = ['title' => '金士頓 NVMe 1TB', 'category_type' => '硬碟SSD'];
$hdd = ['title' => 'WD 4TB 藍標 5400轉', 'category_type' => '硬碟SSD'];
$water = ['title' => '華碩 360 水冷', 'category_type' => '散熱設備'];
$air = ['title' => 'ID-COOLING 空冷塔', 'category_type' => '散熱設備'];
$bag = ['title' => '男用後背包', 'category_group' => '男性專區', 'category_type' => '包包'];

expect(quote_builder_slot_for_product($cpu) === 'cpu', 'CPU maps to cpu slot');
expect(quote_builder_slot_for_product($mb) === 'mb', 'motherboard maps to mb slot');
expect(quote_builder_slot_for_product($ssd) === 'ssd', 'NVMe maps to ssd slot');
expect(quote_builder_slot_for_product($hdd) === 'hdd', 'WD blue HDD maps to hdd slot');
expect(quote_builder_slot_for_product($water) === 'aio_cool', 'AIO cooler maps to water slot');
expect(quote_builder_slot_for_product($air) === 'cooler', 'air cooler maps to cooler slot');
expect(quote_builder_slot_for_product($bag) === null, 'fashion bag is extra, not a PC slot');

$catalog = quote_builder_catalog([
    $cpu, $mb, $ssd, $hdd, $water, $air, $bag,
    ['title' => '無名稱跳過', 'category_type' => '處理器', 'title' => ''],
]);
$byId = [];
foreach ($catalog['slots'] as $slot) $byId[$slot['id']] = $slot;
expect(($byId['cpu']['count'] ?? 0) === 1, 'cpu slot has 1 item');
expect(($byId['ssd']['count'] ?? 0) === 1, 'ssd slot has 1 item');
expect(($byId['hdd']['count'] ?? 0) === 1, 'hdd slot has 1 item');
expect(($byId['aio_cool']['count'] ?? 0) === 1, 'water slot has 1 item');
expect(!empty($catalog['extra']), 'unmapped bag goes to extra category slots');

$withImage = quote_builder_item([
    'title' => '有圖機殼',
    'category_type' => '電腦機箱',
    'image' => 'uploads/case.jpg',
    'stock_total' => 2,
]);
expect(($withImage['image'] ?? '') === 'uploads/case.jpg', 'catalog item keeps product image');

$js = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'admin-quote-builder.js');
expect(str_contains($js, 'quote-builder-catalog.php'), 'builder loads catalog API');
expect(str_contains($js, 'qb-card'), 'builder uses product cards instead of row selects');
expect(!str_contains($js, 'size="6"'), 'builder no longer uses tall select lists');
expect(str_contains($js, '寶輝組裝估價'), 'builder is Baohui products, not CoolPC');
expect(!str_contains($js, '原價屋報價'), 'does not label Baohui catalog as CoolPC');

if ($failed > 0) {
    fwrite(STDERR, $failed . " assertion(s) failed\n");
    exit(1);
}
echo "all passed\n";
