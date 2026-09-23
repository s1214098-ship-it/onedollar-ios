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

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'ops-data-lib.php';

$_POST = ['action' => 'save_inventory_count'];
expect(ops_request_is_inventory_count_draft() === true, '盤點機 save_inventory_count is a count draft');
$_POST = ['action' => 'update_inventory_count_draft'];
expect(ops_request_is_inventory_count_draft() === true, 'update_inventory_count_draft is a count draft');
$_POST = ['action' => 'return_inventory_count'];
expect(ops_request_is_inventory_count_draft() === true, 'return_inventory_count is a count draft');
$_POST = ['action' => 'approve_inventory_count'];
expect(ops_request_is_inventory_count_draft() === false, '管理者核准盤點 may write official stock');
$_POST = ['action' => 'stock_in'];
expect(ops_request_is_inventory_count_draft() === false, '快速入庫 is not a count draft');
$_POST = [];
expect(ops_request_is_inventory_count_draft() === false, 'GET / empty POST is not a count draft');

$patcher = dirname(__DIR__) . '/one-dollar-auction/tools/patch-live-inventory-count-draft-ops.py';
expect(is_file($patcher), 'live operations count-draft patcher exists');
$src = (string)file_get_contents($patcher);
expect(str_contains($src, '$countDraftOnly = in_array((string)($_POST[\'action\'] ?? \'\'), [\'save_inventory_count\', \'update_inventory_count_draft\', \'return_inventory_count\'], true);'), 'patcher defines countDraftOnly before product writes');
expect(str_contains($src, 'if (!$countDraftOnly && ($productPricingRulesApplied > 0 || $productShippingDefaultsApplied > 0)) write_data(\'products\', $products);'), 'pricing rewrite is skipped on count draft');
expect(str_contains($src, 'if (!$countDraftOnly && $productMasterSerialsAdded > 0)'), 'serial backfill is skipped on count draft');
expect(str_contains($src, 'if (!$countDraftOnly && $inventorySerialsAdded > 0) write_data(\'inventory_units\', $inventoryUnits);'), 'inventory_units backfill is skipped on count draft');
if ($failed > 0) {
    fwrite(STDERR, "$failed failed\n");
    exit(1);
}
echo "all inventory count draft tests passed\n";
