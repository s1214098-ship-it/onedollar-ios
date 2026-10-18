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

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'ops-delivery-settle-lib.php';

$open = [
    'id' => 'dn_auc_1',
    'delivery_no' => 'AUC-SHIP-20260922-008',
    'source' => 'one_dollar_auction',
    'total' => 32,
    'payment_status' => '',
    'reconciliation_status' => '待核對',
    'schedule_ids' => ['sch_team2p100'],
    'items' => [['schedule_id' => 'sch_team2p100', 'product_id' => 'TEAM2P100']],
];
expect(ops_delivery_is_settled($open) === false, '待核對 auction note is not 已結款');

ops_delivery_mark_settled($open, '峰志', '2026-09-22T15:30:00+08:00');
expect(ops_delivery_is_settled($open) === true, 'mark settled turns the note into 已結款');
expect(($open['payment_status'] ?? '') === '已結款', 'payment_status is 已結款');
expect(($open['reconciliation_status'] ?? '') === '已銷帳', 'reconciliation is 已銷帳 so monthly billing drops it');
expect(($open['billing_status'] ?? '') === '不需月結', 'billing status leaves 月結');

$alreadyPaid = $open;
$alreadyPaid['payment_status'] = '已付款';
$alreadyPaid['reconciliation_status'] = '已銷帳';
expect(ops_delivery_is_settled($alreadyPaid) === true, '已付款／已銷帳 still counts as settled');

$schedules = [[
    'id' => 'sch_team2p100',
    'winning_price' => 32,
    'shipping_fee' => 0,
    'paid_amount' => 0,
    'payment_status' => '未付款',
]];
expect(ops_delivery_settle_linked_schedules($schedules, $open, '2026-09-22T15:30:00+08:00') === 1, 'linked 得標列 is closed with the shipping note');
expect((float)($schedules[0]['paid_amount'] ?? 0) === 32.0, 'unpaid remainder is applied as received payment');
expect(($schedules[0]['payment_status'] ?? '') === '已結款', 'linked schedule payment_status is 已結款');

if ($failed > 0) {
    fwrite(STDERR, "$failed failed\n");
    exit(1);
}
echo "all delivery settle tests passed\n";
