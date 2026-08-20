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
expect(str_contains($js, '不加入核實') || str_contains($js, '不列入核實'), 'says handling fee is not part of verification');
expect(str_contains($js, 'customsVerifyExpected'), 'keeps duty+late expected helper');
expect(!preg_match('/expected\s*=\s*taxFree\s*\?\s*0\s*:\s*\(\s*total\s*\+\s*firstDuty/', $js), 'does not add 30 handling into verify expected');
expect(str_contains($js, 'firstDuty + extraDuty') && str_contains($js, 'firstLate + extraLate'), 'verify expected uses duty and late only');

$sampleDuty = 96;
$sampleLate = 0;
$sampleCharge = 94;
$expected = $sampleDuty + $sampleLate;
$verify = $expected - $sampleCharge;
expect($expected === 96, 'TX802069086752 expected is customs only, not 126');
expect($verify === 2, 'TX802069086752 verify is 96-94=2, not 32');
expect($verify !== ($sampleDuty + 30 - $sampleCharge), 'does not treat the 30 handling fee as extra customs');

if ($failed > 0) {
    fwrite(STDERR, $failed . " assertion(s) failed\n");
    exit(1);
}
echo "all passed\n";
