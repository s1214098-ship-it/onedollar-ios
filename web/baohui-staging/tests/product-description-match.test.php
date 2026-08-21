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

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'product-description-match-lib.php';

expect(product_match_extract_model(['model' => 'DUAL-RTX3070-O8G', 'title' => '雜訊']) === 'DUAL-RTX3070-O8G', 'explicit model wins');
expect(product_match_extract_model(['title' => 'INTEL I3-10105(S)']) === 'I3-10105', 'cpu model is extracted from title');
expect(product_match_brand_key('華碩') === 'ASUS', 'brand alias maps 華碩 to ASUS');
expect(product_match_brand_key('DELL戴爾') === 'DELL', 'brand alias maps DELL戴爾');

$catalog = [
    [
        'name' => 'INTEL i3-10105F/3.7G/4核8緒/1200無內顯',
        'brand' => 'INTEL',
        'category' => '處理器',
        'spec' => '處理器：i3-10105F',
        'specs' => [
            ['label' => '處理器', 'value' => 'i3-10105F'],
            ['label' => '保固期限', 'value' => '三年'],
        ],
    ],
    [
        'name' => 'ASUS DUAL-RTX3070-O8G 顯示卡-上網註冊4年保固',
        'brand' => 'ASUS',
        'category' => '顯示卡',
        'spec' => '繪圖處理器：GEFORCE RTX 3070 / 記憶體容量：8GB',
        'specs' => [
            ['label' => '繪圖處理器', 'value' => 'GEFORCE RTX 3070'],
            ['label' => '記憶體容量', 'value' => '8GB'],
            ['label' => '保固期限', 'value' => '4年'],
        ],
    ],
    [
        'name' => '威剛 DDR4 3200 16G 桌上型記憶體',
        'brand' => 'ADATA',
        'category' => '記憶體',
        'spec' => '容量：16G / 類別：DDR4 / 記憶體時脈：3200',
        'specs' => [
            ['label' => '容量', 'value' => '16G'],
            ['label' => '類別', 'value' => 'DDR4'],
        ],
    ],
    [
        'name' => 'GIGABYTE RTX5070 WINDFORCE OC SFF 12G顯卡',
        'brand' => 'GIGABYTE',
        'category' => '顯示卡',
        'specs' => [
            ['label' => '繪圖處理器', 'value' => 'GEFORCE RTX 5070'],
        ],
    ],
];

$cpu = ['id' => 'cpu1', 'title' => 'INTEL I3-10105(S)', 'category_brand' => 'Intel', 'stock_total' => 2, 'stock_sold' => 0, 'description' => ''];
$cpuMatch = product_best_catalog_rule_match($cpu, $catalog);
expect($cpuMatch !== null && ($cpuMatch['model_similarity'] ?? 0) >= 90, 'i3-10105 matches i3-10105F at 90%+');
expect($cpuMatch !== null && str_contains((string)($cpuMatch['block'] ?? ''), '【規格說明】'), 'catalog match formats a spec block');
expect($cpuMatch !== null && str_contains((string)($cpuMatch['block'] ?? ''), '保固期限：三年'), 'catalog spec rows are copied');

$gpu = ['id' => 'gpu1', 'title' => '華碩顯示卡', 'model' => 'DUAL-RTX3070-O8G', 'category_brand' => '華碩', 'stock_total' => 1, 'description' => ''];
$gpuMatch = product_best_catalog_rule_match($gpu, $catalog);
expect($gpuMatch !== null && str_contains((string)($gpuMatch['block'] ?? ''), 'DUAL-RTX3070-O8G'), 'exact GPU model attaches catalog rule');

$ram = ['id' => 'ram1', 'title' => '威剛DDR3 1333 4G桌上型記憶體(S)', 'category_brand' => '威剛', 'stock_total' => 3, 'description' => ''];
expect(product_best_catalog_rule_match($ram, $catalog) === null, 'DDR3 does not attach DDR4 rule below 90%');

$oldGpu = ['id' => 'gpu2', 'title' => '技嘉 GTX970', 'model' => 'GV-N970IOC-4GD', 'category_brand' => '技嘉', 'stock_total' => 1, 'description' => ''];
expect(product_best_catalog_rule_match($oldGpu, $catalog) === null, 'GTX970 does not attach RTX5070 rule');

$brandOnly = ['id' => 'brand1', 'title' => 'INTEL 風扇', 'category_brand' => 'Intel', 'stock_total' => 1, 'description' => ''];
expect(product_can_attach_rule_description($brandOnly, $catalog[0]) === false || product_best_catalog_rule_match($brandOnly, $catalog) === null, 'brand-only match cannot attach rule description');

$zeroStock = ['id' => 'cpu0', 'title' => 'INTEL I3-10105(S)', 'stock_total' => 0, 'description' => ''];
$applied = product_apply_rule_descriptions([$zeroStock, $cpu, $ram], $catalog);
expect($applied['updated'] === 1, 'only in-stock 90%+ model is updated');
expect($applied['skipped_no_stock'] === 1, 'zero-stock product is skipped');
expect($applied['skipped_below_threshold'] >= 1, 'below-90 model is skipped');
expect(str_contains((string)($applied['products'][1]['description'] ?? ''), '【規格說明】'), 'in-stock CPU description receives spec block');
expect(!str_contains((string)($applied['products'][2]['description'] ?? ''), '【規格說明】'), 'DDR3 description stays empty');

$existingCopy = "電競靈魂・無線制霸\n保留原文";
$withCopy = $cpu;
$withCopy['id'] = 'cpu3';
$withCopy['description'] = $existingCopy;
$merged = product_apply_rule_descriptions([$withCopy], $catalog);
expect(str_starts_with((string)($merged['products'][0]['description'] ?? ''), $existingCopy), 'existing writeup is kept');
expect(substr_count((string)($merged['products'][0]['description'] ?? ''), '【規格說明】') === 1, 'spec marker is appended once');

$again = product_apply_rule_descriptions($merged['products'], $catalog);
expect($again['updated'] === 0 && $again['unchanged'] === 1, 're-run does not duplicate the spec block');

$donor = [
    'id' => 'mouse1',
    'title' => '無線藍牙滑鼠靜音黑色',
    'stock_total' => 4,
    'description' => "【規格說明】\n2.4G＋藍牙5.2雙模｜靜音按鍵",
];
$sibling = [
    'id' => 'mouse2',
    'title' => '無線藍牙滑鼠靜音白色',
    'stock_total' => 2,
    'description' => '',
];
$far = [
    'id' => 'towel1',
    'title' => '懶人必備一次性毛巾',
    'stock_total' => 8,
    'description' => '',
];
$sib = product_apply_rule_descriptions([$donor, $sibling, $far], []);
expect(str_contains((string)($sib['products'][1]['description'] ?? ''), '2.4G＋藍牙5.2雙模'), '90%+ sibling model copies rule description');
expect(($sib['products'][2]['description'] ?? '') === '', 'unrelated in-stock title is not copied');

$ops = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'operations.php');
expect(str_contains($ops, 'product-description-match-lib.php'), 'operations loads description match lib');
expect(str_contains($ops, 'apply_stock_rule_descriptions'), 'stock page can apply rule descriptions');
expect(str_contains($ops, '套用規格說明'), 'stock tools expose apply button');

if ($failed > 0) {
    fwrite(STDERR, $failed . " assertion(s) failed\n");
    exit(1);
}
echo "all passed\n";
