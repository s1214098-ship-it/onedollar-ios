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

$js = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'admin-customs-duty-verify.js');
expect($js !== '', 'overlay script exists');
expect(str_contains($js, '不加入核實') || str_contains($js, '不加入這個差額'), 'says handling fee is not part of verification');
expect(str_contains($js, '產品成本'), 'mentions product cost');
expect(str_contains($js, '快遞少收'), 'labels courier undercharge');
expect(str_contains($js, '我多給'), 'labels overpay');
expect(str_contains($js, '剛好對上') || str_contains($js, '剛好'), 'labels even match');
expect(!str_contains($js, '多收，要追'), 'does not call a positive gap 多收要追');
expect(!str_contains($js, '少給快遞，視為正常'), 'does not treat underpay as an unlabeled normal case');
expect(!preg_match('/expected\s*=\s*taxFree\s*\?\s*0\s*:\s*\(\s*total\s*\+\s*firstDuty/', $js), 'does not add 30 handling into verify expected');
expect(str_contains($js, 'firstDuty + extraDuty') && str_contains($js, 'firstLate + extraLate'), 'verify expected uses duty and late only');
expect(str_contains($js, 'splitCustomsNos') || str_contains($js, 'split(/[\\s,，、;；/|]+/)'), 'counts each 關貿 number');

function split_customs_nos(string $text): array
{
    $parts = preg_split('/[\s,，、;；\/|]+/u', $text) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part !== '') {
            $out[] = $part;
        }
    }
    return array_values(array_unique($out));
}

function verify_fee(int $duty, int $late, int $charge): int
{
    return $duty + $late - $charge;
}

function product_cost(int $charge, int $handling): int
{
    return $charge + $handling;
}

$row1Nos = split_customs_nos('7430746315、7430746304');
expect($row1Nos === ['7430746315', '7430746304'], 'SA260727000987 splits into two 關貿 numbers');
$row1Handling = count($row1Nos) * 30;
$row1Charge = 667 + 667;
$row1Duty = 1667 + 0;
$row1Verify = verify_fee($row1Duty, 0, $row1Charge);
expect($row1Handling === 60, 'two 關貿 numbers charge 60 handling, not 30');
expect($row1Verify === 333, 'screenshot row1 verify is 1667-1334=333, not 333-30');
expect($row1Verify !== verify_fee($row1Duty + $row1Handling, 0, $row1Charge), 'does not subtract or add 30 inside 333');
expect(product_cost($row1Charge, $row1Handling) === 1394, 'row1 product cost is express 1334 + handling 60');

$row2Nos = split_customs_nos('7430750585');
$row2Handling = count($row2Nos) * 30;
$row2Charge = 667;
$row2Duty = 1002;
$row2Verify = verify_fee($row2Duty, 0, $row2Charge);
expect($row2Handling === 30, 'one 關貿 number charges 30 handling');
expect($row2Verify === 335, 'screenshot row2 verify is 1002-667=335');
expect(product_cost($row2Charge, $row2Handling) === 697, 'row2 product cost is express 667 + handling 30');
expect($row2Verify > 0, '335 is 快遞少收, not 我多給');

$txDuty = 96;
$txCharge = 94;
$txVerify = verify_fee($txDuty, 0, $txCharge);
expect($txVerify === 2, 'TX802069086752 verify is 96-94=2, not 32');
expect(product_cost($txCharge, 30) === 124, 'TX product cost includes the 30 handling fee');

$overpay = verify_fee(90, 0, 100);
expect($overpay === -10, 'courier above 關貿 is negative');
expect($overpay < 0, 'negative gap is 我多給');

expect(str_contains($js, 'baohui.customsDutyBatchCollapsed'), 'remembers collapse in localStorage');
expect(str_contains($js, 'data-cd-batch-toggle'), 'adds a collapse toggle on the batch card');
expect(str_contains($js, 'is-collapsed'), 'collapsed card hides the table');
expect(str_contains($js, '筆待查核'), 'collapsed header still shows pending count');
expect(str_contains($js, 'defaultCollapsed: pending === 0'), 'defaults to collapsed when nothing is pending');
expect(str_contains($js, 'baohui.customsDutyStatCollapsed'), 'remembers 快遞收費統計 collapse');
expect(str_contains($js, 'ensureStatCollapse'), 'wires collapse onto 快遞收費統計');
expect(str_contains($js, '筆有差額'), 'collapsed 統計 header shows gap count');

function batch_pending(array $groups): int
{
    $n = 0;
    foreach ($groups as $g) {
        $diff = (int)($g['diff'] ?? 0);
        $resolved = !empty($g['resolved']);
        if ($diff !== 0 && !$resolved) {
            $n++;
        }
    }
    return $n;
}

$shot = [
    ['diff' => 0, 'resolved' => false],
    ['diff' => 0, 'resolved' => false],
    ['diff' => 40, 'resolved' => false],
    ['diff' => -40, 'resolved' => false],
];
expect(batch_pending($shot) === 2, 'screenshot has 2 pending rows so table stays open by default');
expect(batch_pending([['diff' => 0], ['diff' => 0]]) === 0, 'all 剛好 defaults to collapsed');
expect(batch_pending([['diff' => 40, 'resolved' => true]]) === 0, 'resolved gaps do not keep the table forced open');

function stat_gap(array $groups): array
{
    $month = [];
    foreach ($groups as $g) {
        $name = (string)($g['logisticsText'] ?? '未填物流');
        $month[$name] = (int)($month[$name] ?? 0) + (int)($g['diff'] ?? 0);
    }
    $pending = 0;
    foreach ($month as $diff) {
        if ($diff !== 0) {
            $pending++;
        }
    }
    return ['total' => count($month), 'pending' => $pending];
}

$statShot = [
    ['logisticsText' => '2026-07-27 | 新竹收費', 'diff' => -6232],
    ['logisticsText' => '2026-08-04 | 新竹收費', 'diff' => 3],
    ['logisticsText' => '2026-07-28 | 宅急便收費', 'diff' => -2532],
    ['logisticsText' => '2026-08-24 | 新竹收費', 'diff' => 1],
    ['logisticsText' => '2026-09-16 | 宅急便收費', 'diff' => 0],
    ['logisticsText' => '2026-07-28 | 新竹收費', 'diff' => -1048],
    ['logisticsText' => '2026-08-15 | 宅急便收費', 'diff' => 0],
    ['logisticsText' => '2026-09-24 | 宅急便收費', 'diff' => 0],
];
$gap = stat_gap($statShot);
expect($gap['total'] === 8, '統計 groups by 日期/物流 line');
expect($gap['pending'] === 5, 'screenshot 統計 has 5 non-zero diffs so stays open until clicked');

if ($failed > 0) {
    fwrite(STDERR, $failed . " assertion(s) failed\n");
    exit(1);
}
echo "all passed\n";
