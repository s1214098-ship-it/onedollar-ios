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

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'baohui-zero-stock-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
putenv('BAOHUI_OPS_DATA_DIR=' . $tmp);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'ops-data-lib.php';
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'ops-product-index-lib.php';
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'ops-product-stock-lib.php';

$products = [
    ['id' => 'HAS1', 'title' => '有貨', 'category_group' => '組裝硬體', 'stock_total' => 2, 'stock_reserved' => 0, 'stock_sold' => 0],
    ['id' => 'ZERO1', 'title' => '空 SKU', 'category_group' => '組裝硬體', 'stock_total' => 0, 'stock_reserved' => 0, 'stock_sold' => 0],
    ['id' => 'SOLD1', 'title' => '賣完', 'category_group' => '組裝硬體', 'stock_total' => 1, 'stock_reserved' => 0, 'stock_sold' => 1],
    ['id' => 'RES1', 'title' => '預約中', 'category_group' => '組裝硬體', 'stock_total' => 1, 'stock_reserved' => 1, 'stock_sold' => 0],
    ['id' => 'WAGE1', 'title' => '網路維修', 'item_kind' => 'wage', 'category_group' => '工資類別', 'stock_total' => 0],
];

[$keep, $remove] = ops_split_zero_stock_products($products);
$keepIds = array_map(function ($row) { return $row['id']; }, $keep);
$removeIds = array_map(function ($row) { return $row['id']; }, $remove);
sort($keepIds);
sort($removeIds);
expect($keepIds === ['HAS1', 'RES1', 'WAGE1'], 'keep in-stock, reserved, and wage items');
expect($removeIds === ['SOLD1', 'ZERO1'], 'remove empty and sold-out inventory SKUs');

$many = [];
for ($i = 0; $i < 220; $i++) {
    $many[] = ['id' => 'KEEP' . $i, 'title' => '主檔' . $i, 'stock_total' => 0];
}
write_data('products', $many);
ops_data_forget('products');
$refused = write_data('products', array_slice($many, 0, 10));
expect($refused === false, 'ordinary write still refuses dropping most products');
$allowed = write_data('products', array_slice($many, 0, 10), ['allow_large_shrink' => true]);
expect($allowed === true, 'explicit purge may shrink the product master');
ops_data_forget('products');
expect(count(read_data('products')) === 10, 'allowed shrink actually writes');

$script = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'purge-zero-stock-products.php');
expect(str_contains($script, "'allow_large_shrink' => true"), 'purge tool uses the explicit shrink flag');
expect(str_contains($script, '--apply'), 'purge tool stays dry-run unless --apply');

foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
@rmdir($tmp);

if ($failed > 0) {
    fwrite(STDERR, $failed . " assertion(s) failed\n");
    exit(1);
}
echo "all passed\n";
