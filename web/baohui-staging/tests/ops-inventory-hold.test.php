<?php
declare(strict_types=1);

$js = file_get_contents(dirname(__DIR__) . '/one-dollar-auction/ops-inventory-hold.js');
$ops = file_get_contents(dirname(__DIR__) . '/one-dollar-auction/operations.php');
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

expect($js !== false && $js !== '', 'hold overlay exists');
expect(str_contains($js, 'hydrateInventoryHoldTab'), 'rebinds 寄庫 form after lazy tab load');
expect(str_contains($js, 'manualHoldProductSearch'), 'listens for product search on 寄庫單');
expect(str_contains($js, 'hold_product_ids'), 'blocks save unless a product was added');
expect(str_contains($js, 'stock-tool-btn'), 'keeps red stock filter chips clickable');
expect(str_contains($ops, "\$_POST['ops_tab'] ?? \$_POST['tab']"), 'POST 寄庫 tab wins over leftover GET tab');

if ($failed) {
    fwrite(STDERR, $failed . " assertion(s) failed\n");
    exit(1);
}
echo "all passed\n";
