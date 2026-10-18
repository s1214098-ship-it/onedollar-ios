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

$patcher = dirname(__DIR__) . '/one-dollar-auction/tools/patch-live-winner-message-ops.py';
expect(is_file($patcher), 'live winner-message patcher exists');

$fixture = <<<'JS'
function buildWinnerFacebookMessage(button) {
  const qty = Math.max(1, Number(form.dataset.quantity || 1));
  const billing = [
    '【本筆金額明細】',
    '整標得標金額：' + moneyText(price),
    '本標內容：共 ' + qty + ' 件（出貨時庫存扣 ' + qty + ' 件）',
    '稅金：' + moneyText(tax) + (addTax ? '（未稅外加 5%）' : '（本筆不加 5%）'),
    '運費：' + moneyText(shipping)
  ];
}
JS;

$tmp = sys_get_temp_dir() . '/winner-facebook-message-fixture.php.js';
file_put_contents($tmp, $fixture);
$cmd = escapeshellarg('python3') . ' ' . escapeshellarg($patcher) . ' ' . escapeshellarg($tmp);
exec($cmd . ' 2>&1', $out, $code);
expect($code === 0, 'patcher applies to the live JS needle: ' . implode(' ', $out));
$patched = (string)file_get_contents($tmp);
@unlink($tmp);

expect(!str_contains($patched, '出貨時庫存扣'), 'customer message no longer mentions 出貨時庫存扣');
expect(str_contains($patched, "'本標內容：共 ' + qty + ' 件',"), 'keeps 本標內容：共 N 件 without the warehouse note');
expect(substr_count($patched, '本標內容：共') === 1, 'only one 本標內容 line remains');

$qty = 1;
$line = '本標內容：共 ' . $qty . ' 件';
expect($line === '本標內容：共 1 件', 'CAB002 screenshot line becomes 本標內容：共 1 件');
expect(!str_contains($line, '庫存'), 'customer-facing line has no 庫存 wording');

if ($failed > 0) {
    fwrite(STDERR, "$failed failed\n");
    exit(1);
}
echo "all winner facebook message tests passed\n";
