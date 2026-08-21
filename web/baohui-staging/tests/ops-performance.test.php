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

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'baohui-ops-perf-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
putenv('BAOHUI_OPS_DATA_DIR=' . $tmp);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'ops-data-lib.php';
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'ops-product-index-lib.php';

$products = [
    [
        'id' => 'COM001P15096',
        'barcode' => 'COM001P15096',
        'title' => '測試主機',
        'category_group' => '組裝硬體',
        'category_type' => '主機',
        'color' => '黑',
        'color_code' => '96',
        'stock_total' => 3,
        'stock_reserved' => 1,
        'stock_sold' => 0,
        'cost' => 150,
        'image' => 'uploads/products/a.jpg',
        'extra_images' => ['uploads/products/b.jpg'],
        'description' => str_repeat('很長的說明', 50),
    ],
    [
        'id' => 'GPU002P88090',
        'barcode' => 'GPU002P88090',
        'title' => '顯示卡',
        'category_type' => '顯示卡',
        'stock_total' => 1,
        'cost' => 880,
        'image' => 'uploads/products/c.jpg',
    ],
];

write_data('products', $products);
$raw = (string)file_get_contents(data_path('products'));
expect(strpos($raw, "\n    ") === false, 'write_data stores compact JSON');
expect(read_data('products')[0]['id'] === 'COM001P15096', 'read_data returns written products');
expect(read_data('products') === read_data('products'), 'read_data reuses request cache');

$indexFile = ops_product_index_path();
expect(is_file($indexFile), 'product save writes search index cache');
$index = ops_load_product_index();
expect(count($index) === 2, 'search index has both products');
expect(!isset($index[0]['description']), 'search index omits description');
expect($index[0]['images'] === ['uploads/products/a.jpg', 'uploads/products/b.jpg'], 'search index keeps product images');

$ops = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'operations.php');
expect(!str_contains($ops, 'schedule-product-options'), 'operations page no longer dumps product datalist');
expect(str_contains($ops, 'ops-product-index.php'), 'operations page lazy-loads product index API');
expect(str_contains($ops, 'ops-status.php'), 'ops status poll uses tiny endpoint');
expect(!str_contains($ops, "fetch('operations.php?partial=ops_status'"), 'ops status poll no longer hits full operations.php');
expect(str_contains($ops, 'let scheduleProducts = []'), 'product catalog starts empty in HTML');
expect(str_contains($ops, 'ops-member-directory.php'), 'member directory is lazy-loaded');
expect(!str_contains($ops, 'json_encode(member_contact_directory($members)'), 'member directory is not embedded in HTML');
expect(str_contains($ops, 'function ops_should_sync_members'), 'member sync is throttled');
expect(str_contains($ops, 'data-ops-pending-tab'), 'operations page remembers the working tab before overview paints');
expect(str_contains($ops, "sessionStorage.setItem('baohuiOpsTab'"), 'current ops tab is stored for reloads');
expect(str_contains($ops, 'input[name="ops_tab"]'), 'form submits keep the current ops tab');

$status = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'ops-status.php');
expect(!str_contains($status, 'operations.php'), 'status endpoint does not boot the full operations page');

$jsonHelper = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ops-json-response.php');
expect(str_contains($jsonHelper, 'gzencode'), 'JSON responses gzip when the browser accepts it');

foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
@rmdir($tmp);

if ($failed > 0) {
    fwrite(STDERR, $failed . " assertion(s) failed\n");
    exit(1);
}
echo "all passed\n";
