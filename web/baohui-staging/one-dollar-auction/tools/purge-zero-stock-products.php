<?php
declare(strict_types=1);

$opsDir = dirname(__DIR__);
require_once $opsDir . DIRECTORY_SEPARATOR . 'ops-data-lib.php';
require_once $opsDir . DIRECTORY_SEPARATOR . 'ops-product-index-lib.php';
require_once $opsDir . DIRECTORY_SEPARATOR . 'ops-product-stock-lib.php';

$apply = in_array('--apply', $argv ?? [], true);

$products = read_data('products');
[$keep, $remove] = ops_split_zero_stock_products(is_array($products) ? $products : []);
$before = count($products);
$keepCount = count($keep);
$removeCount = count($remove);

echo "products before: {$before}\n";
echo "keep (has stock / reserved / wage / logistics): {$keepCount}\n";
echo "remove (no on-hand stock): {$removeCount}\n";

if ($removeCount === 0) {
    echo "nothing to remove\n";
    exit(0);
}

if (!$apply) {
    echo "dry-run only. pass --apply to backup and write.\n";
    exit(0);
}

$src = data_path('products');
if (!is_file($src)) {
    fwrite(STDERR, "missing products.json\n");
    exit(1);
}

$stamp = date('Ymd-His');
$backupName = 'products.before-zero-stock-' . $stamp . '.json';
$localBackup = ops_data_dir() . DIRECTORY_SEPARATOR . $backupName;
if (!@copy($src, $localBackup) || !is_file($localBackup)) {
    fwrite(STDERR, "backup failed: {$localBackup}\n");
    exit(1);
}
echo "backup: {$localBackup}\n";

$accountDir = 'F:\\Data\\BaohuiAccounting';
if (is_dir($accountDir)) {
    $accountBackup = $accountDir . DIRECTORY_SEPARATOR . $backupName;
    if (@copy($src, $accountBackup)) echo "backup: {$accountBackup}\n";
}

$removedSaved = write_data('products.zero-stock-removed', $remove);
if ($removedSaved === false) {
    fwrite(STDERR, "failed to save removed product archive\n");
    exit(1);
}

$ok = write_data('products', $keep, ['allow_large_shrink' => true]);
if ($ok === false) {
    fwrite(STDERR, "write_data refused or failed; master not changed\n");
    exit(1);
}

ops_data_forget('products');
$after = count(read_data('products'));
echo "products after: {$after}\n";
if ($after !== $keepCount) {
    fwrite(STDERR, "count mismatch after write\n");
    exit(1);
}
echo "done\n";
