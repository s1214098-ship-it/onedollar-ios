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

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'baohui-logistics-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
putenv('BAOHUI_OPS_DATA_DIR=' . $tmp);

$root = dirname(__DIR__);
$auction = $root . DIRECTORY_SEPARATOR . 'one-dollar-auction';
require $auction . DIRECTORY_SEPARATOR . 'ops-data-lib.php';
require $auction . DIRECTORY_SEPARATOR . 'ops-delivery-logistics-lib.php';

$products = [[
    'id' => 'COM001P15096',
    'title' => '測試主機',
    'stock_total' => 3,
    'stock_sold' => 1,
    'cost' => 1500,
]];
$movements = [[
    'id' => 'sale_1',
    'document_no' => 'SELL-20261027-001',
    'type' => '銷售出庫單',
    'qty' => -1,
    'unit_cost' => 1500,
]];
$notes = [[
    'id' => 'dn_ship_1',
    'delivery_no' => 'SELL-20261027-001',
    'source' => 'sales_out',
    'status' => '待出貨',
    'shipping_status' => '待出貨',
    'tracking_no' => '',
    'logistics_company' => '',
    'logistics_delivery_status' => '',
    'logistics_status_note' => '',
    'logistics_checked_date' => '',
    'payment_status' => '未付款',
    'reconciliation_status' => '待核對',
    'items' => [[
        'product_id' => 'COM001P15096',
        'quantity' => 1,
        'unit_price' => 2000,
        'unit_cost' => 1500,
        'line_subtotal' => 2000,
    ]],
    'total' => 2000,
    'sales_cost' => 1500,
]];

write_data('products', $products);
write_data('stock_movements', $movements);
write_data('delivery_notes', $notes);
$productHash = hash_file('sha256', data_path('products'));
$moveHash = hash_file('sha256', data_path('stock_movements'));

$missing = ops_delivery_logistics_prepare($notes, ['tracking_no' => 'X'], '峰志');
expect(($missing['rejected'] ?? '') === 'missing_ids', 'id + delivery_no are required');

$mismatch = ops_delivery_logistics_prepare($notes, [
    'id' => 'dn_ship_1',
    'delivery_no' => 'SELL-NOPE',
    'tracking_no' => '123',
], '峰志');
expect(($mismatch['rejected'] ?? '') === 'mismatch', 'id / delivery_no mismatch is rejected');

$unknown = ops_delivery_logistics_prepare($notes, [
    'id' => 'dn_ship_1',
    'delivery_no' => 'SELL-20261027-001',
    'sales_items' => [['qty' => 1]],
    'payment_status' => '已付款',
], '峰志');
expect(($unknown['rejected'] ?? '') === 'forbidden', 'payment / items payload is forbidden');
expect(in_array('sales_items', $unknown['unknown'] ?? [], true), 'sales_items is named in forbidden keys');
expect(in_array('payment_status', $unknown['unknown'] ?? [], true), 'payment_status is named in forbidden keys');

$badStatus = ops_delivery_logistics_prepare($notes, [
    'id' => 'dn_ship_1',
    'delivery_no' => 'SELL-20261027-001',
    'shipping_status' => '取消下架',
], '峰志');
expect(($badStatus['rejected'] ?? '') === 'bad_status', 'unlist-like shipping_status is rejected');

$badDate = ops_delivery_logistics_prepare($notes, [
    'id' => 'dn_ship_1',
    'delivery_no' => 'SELL-20261027-001',
    'logistics_checked_date' => '2026/99/01',
], '峰志');
expect(($badDate['rejected'] ?? '') === 'bad_date', 'invalid checked date is rejected');

$ok = ops_delivery_logistics_prepare($notes, [
    'id' => 'dn_ship_1',
    'delivery_no' => 'SELL-20261027-001',
    'sales_tracking_no' => '88888888888',
    'sales_logistics_company' => '7-ELEVEN',
    'sales_logistics_delivery_status' => '已到店',
    'sales_logistics_status_note' => '門市：測試門市',
    'sales_logistics_checked_date' => '2026/10/27',
    'sales_shipping_status' => '已出貨',
], '峰志', '2026-10-27T12:01:00+08:00');
expect(!empty($ok['ok']), 'canonical + sales_* aliases apply');
expect(($ok['after']['tracking_no'] ?? '') === '88888888888', 'tracking_no is updated');
expect(($ok['after']['logistics_company'] ?? '') === '7-ELEVEN', 'logistics_company is updated');
expect(($ok['after']['logistics_delivery_status'] ?? '') === '已到店', 'delivery status is updated');
expect(($ok['after']['logistics_status_note'] ?? '') === '門市：測試門市', 'status note is updated');
expect(($ok['after']['logistics_checked_date'] ?? '') === '2026-10-27', 'checked date normalizes to Y-m-d');
expect(($ok['after']['shipping_status'] ?? '') === '已出貨', 'shipping_status is updated');
expect(($ok['after']['status'] ?? '') === '已出貨', 'status mirrors shipping_status');
expect(($ok['before']['shipping_status'] ?? '') === '待出貨', 'before snapshot keeps 待出貨');
expect(($ok['note']['payment_status'] ?? '') === '未付款', 'payment_status is untouched');
expect(($ok['note']['items'][0]['unit_cost'] ?? null) === 1500, 'line unit_cost is untouched');
expect(($ok['note']['sales_cost'] ?? null) === 1500, 'sales_cost is untouched');
expect(($ok['note']['items'][0]['quantity'] ?? null) === 1, 'items quantity is untouched');

$notes[(int)$ok['index']] = $ok['note'];
expect(write_data('delivery_notes', $notes) === true, 'only delivery_notes is written');
ops_data_forget();
$saved = read_data('delivery_notes')[0];
expect(($saved['tracking_no'] ?? '') === '88888888888', 'saved note keeps tracking_no');
expect(($saved['payment_status'] ?? '') === '未付款', 'saved note still 未付款');
expect(hash_file('sha256', data_path('products')) === $productHash, 'products.json fingerprint unchanged');
expect(hash_file('sha256', data_path('stock_movements')) === $moveHash, 'stock_movements.json fingerprint unchanged');
expect((int)(read_data('products')[0]['stock_sold'] ?? 0) === 1, 'stock_sold is unchanged');

$lib = (string)file_get_contents($auction . DIRECTORY_SEPARATOR . 'ops-delivery-logistics-lib.php');
$api = (string)file_get_contents($auction . DIRECTORY_SEPARATOR . 'ops-delivery-logistics.php');
$js = (string)file_get_contents($auction . DIRECTORY_SEPARATOR . 'ops-delivery-logistics.js');
$ops = (string)file_get_contents($auction . DIRECTORY_SEPARATOR . 'operations.php');
$patcher = (string)file_get_contents($auction . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'patch-live-delivery-logistics-ops.py');

expect(!str_contains($lib, "write_data('products'"), 'lib never writes products');
expect(!str_contains($lib, "write_data('stock_movements'"), 'lib never writes stock_movements');
expect(!str_contains($lib, 'restore_delivery_note_stock'), 'lib never restores stock');
expect(!str_contains($api, "write_data('products'"), 'endpoint never writes products');
expect(!str_contains($api, "write_data('stock_movements'"), 'endpoint never writes stock_movements');
expect(str_contains($api, "write_data('delivery_notes'"), 'endpoint writes delivery_notes only');
expect(str_contains($js, 'data-logistics-safe-save'), 'overlay injects 只更新物流 button');
expect(str_contains($js, 'ops-delivery-logistics.php'), 'overlay posts to the logistics endpoint');
expect(!str_contains($js, 'sales_out'), 'overlay does not submit sales_out');
expect(str_contains($ops, 'ops-delivery-logistics.js'), 'git operations.php loads the overlay script');
expect(str_contains($ops, "if (\$action === 'sales_out')"), 'sales_out stock path is still present and unused by this API');
expect(str_contains($patcher, 'ops-delivery-logistics.js'), 'live patcher injects overlay JS');
expect(!str_contains($patcher, 'facebook-auction-worker-queue'), 'patcher does not touch auction worker queue');
expect(!str_contains($api, 'facebook-auction-worker-queue'), 'endpoint does not touch auction worker queue');

$dry = ops_delivery_logistics_prepare(read_data('delivery_notes'), [
    'id' => 'dn_ship_1',
    'delivery_no' => 'SELL-20261027-001',
    'tracking_no' => 'DRYONLY',
], '峰志');
expect(!empty($dry['ok']) && ($dry['after']['tracking_no'] ?? '') === 'DRYONLY', 'dry_run prepare still returns after');
expect((read_data('delivery_notes')[0]['tracking_no'] ?? '') === '88888888888', 'dry_run prepare does not write by itself');

$notFound = ops_delivery_logistics_find_index($notes, 'dn_missing', 'SELL-MISSING');
expect(($notFound['rejected'] ?? '') === 'not_found', 'unknown id+delivery_no is not_found');

foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
@rmdir($tmp);

if ($failed > 0) {
    fwrite(STDERR, "$failed failed\n");
    exit(1);
}
echo "all delivery logistics tests passed\n";
