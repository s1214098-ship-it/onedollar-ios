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

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'monthly-settlement-lib.php';

$aug20 = baohui_monthly_settlement_cycle('2026-08-20');
expect($aug20['from'] === '2026-07-25', '8/20 monthly cycle starts 7/25');
expect($aug20['to'] === '2026-08-24', '8/20 monthly cycle ends 8/24');
expect($aug20['month'] === '2026-08', '8/20 monthly cycle is labeled as August');

$aug24 = baohui_monthly_settlement_cycle('2026-08-24');
expect($aug24['from'] === '2026-07-25' && $aug24['to'] === '2026-08-24', '8/24 is still the current August cycle');

$aug25 = baohui_monthly_settlement_cycle('2026-08-25');
expect($aug25['from'] === '2026-08-25', '8/25 starts the next monthly cycle');
expect($aug25['to'] === '2026-09-24', '8/25 cycle ends 9/24');
expect($aug25['month'] === '2026-09', '8/25 cycle is labeled as September');

$july = baohui_monthly_settlement_cycle_for_month('2026-07');
expect($july['from'] === '2026-06-25' && $july['to'] === '2026-07-24', 'July cycle is 6/25 to 7/24');

$jan = baohui_monthly_settlement_cycle_for_month('2026-01');
expect($jan['from'] === '2025-12-25' && $jan['to'] === '2026-01-24', 'January cycle crosses the year');

expect(baohui_billing_is_settled(['status' => '已結清']) === true, '已結清 counts as settled');
expect(baohui_billing_is_settled(['status' => '待請款']) === false, '待請款 is unsettled');
expect(baohui_receipt_is_settled(['status' => '已確認']) === true, 'confirmed receipts are settled');
expect(baohui_receipt_is_settled(['status' => '部分收款']) === false, 'partial receipts are unsettled');

$monthlyBill = ['period_from' => '2026-07-25', 'period_to' => '2026-08-24', 'status' => '待請款'];
expect(baohui_billing_matches_range($monthlyBill, '2026-07-25', '2026-08-24') === true, 'stored monthly period matches the August cycle');
expect(baohui_billing_matches_range($monthlyBill, '2026-06-25', '2026-07-24') === false, 'August cycle request is outside July');

$legacyBill = ['request_date' => '2026-08-10', 'items' => [['date' => '2026-08-01']]];
expect(baohui_billing_matches_range($legacyBill, '2026-07-25', '2026-08-24') === true, 'legacy requests still match by item/request date');

if ($failed > 0) {
    fwrite(STDERR, $failed . " assertion(s) failed\n");
    exit(1);
}
echo "all passed\n";
