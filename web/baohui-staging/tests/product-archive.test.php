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

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'product-archive-lib.php';

$row = apply_product_computer_spec([
    'title' => '測試主機',
    'spec' => '自組',
], [
    'model' => 'B650M',
    'cpu' => 'R5 7600',
    'ram' => '32G',
    'storage' => '1TB',
    'gpu' => '4060',
    'serial_number' => 'SN123',
    'warranty' => '3年',
    'inspection_note' => 'OK',
]);
expect($row['cpu'] === 'R5 7600' && $row['gpu'] === '4060', 'posted computer spec is stored');
expect($row['serial_number'] === 'SN123', 'serial number is stored');

$kept = apply_product_computer_spec(['cpu' => 'old'], [], ['cpu' => 'i5', 'ram' => '16G']);
expect($kept['cpu'] === 'i5' && $kept['ram'] === '16G', 'missing post keeps existing spec');

$cleared = apply_product_computer_spec(['cpu' => 'i7'], ['cpu' => '', 'ram' => ''], ['cpu' => 'i7', 'ram' => '8G']);
expect($cleared['cpu'] === '' && $cleared['ram'] === '', 'blank post clears spec fields');

$summary = product_spec_summary([
    'category_brand' => 'ASUS',
    'model' => 'B650M',
    'cpu' => 'R5 7600',
    'ram' => '32G',
    'storage' => '1TB',
    'gpu' => '4060',
    'spec' => 'B650M',
    'color' => '黑',
    'size' => '',
]);
expect($summary === 'ASUS · B650M · R5 7600 · 32G · 1TB · 4060 · 黑', 'spec summary joins unique parts');
expect(product_is_computer_archive('組裝硬體') === true, 'hardware group is computer archive');
expect(product_is_computer_archive('男性專區') === false, 'clothing group is not computer archive');

$ops = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'operations.php');
expect(str_contains($ops, 'product-workspace'), 'products page uses two-column workspace');
expect(str_contains($ops, '第一步｜建檔必填'), 'step 1 fieldset exists');
expect(str_contains($ops, '第二步｜規格'), 'step 2 spec section exists');
expect(str_contains($ops, 'id="productComputerSpecPanel"'), 'computer spec panel exists');
expect(str_contains($ops, 'class="product-item"'), 'product list uses compact cards');
expect(!preg_match('/id="productColorPairSelect"\s+size="6"/', $ops), 'color picker is a dropdown not a listbox');
expect(!str_contains($ops, 'product-serial-note'), 'long barcode how-to banner is removed');
expect(str_contains($ops, 'apply_product_computer_spec'), 'save_product persists computer spec');

if ($failed > 0) {
    fwrite(STDERR, $failed . " assertion(s) failed\n");
    exit(1);
}
echo "all passed\n";
