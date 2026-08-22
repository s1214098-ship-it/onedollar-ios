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

if ($failed > 0) {
    fwrite(STDERR, $failed . " assertion(s) failed\n");
    exit(1);
}
echo "all passed\n";
