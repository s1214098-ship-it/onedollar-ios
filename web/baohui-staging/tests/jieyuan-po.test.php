<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'accounting-jieyuan-po.php';

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

expect(acc_normalize_doc_date('2026-8-18') === '2026-08-18', 'normalize date');
expect(acc_jieyuan_amounts_match(47549, 49926, 47549), 'net matches untaxed PO');
expect(acc_jieyuan_amounts_match(0, 49926, 47549), 'invoice total matches PO * 1.05');
expect(acc_jieyuan_amounts_match(100, 105, 100), 'small VAT pair');
expect(!acc_jieyuan_amounts_match(2000, 2100, 100), 'unrelated amount does not match');

$named = [
    'supplier_name' => '捷元股份有限公司',
    'type' => '進貨入庫單',
    'source_doc_type' => '進貨入庫單',
    'document_date' => '2026-08-19',
    'document_no' => 'JH-20260819-001',
    'amount' => 10,
    'product_title' => 'named',
];
$empty = [
    'supplier_name' => '',
    'party_name' => '',
    'type' => '進貨入庫單',
    'source_doc_type' => '進貨入庫單',
    'document_date' => '2026-08-18',
    'document_no' => 'JH-20260818-001',
    'amount' => 100,
    'product_title' => '十銓DDR3 1333 4GB桌上型記憶體(S)',
];
$other = [
    'supplier_name' => '拚多多 淘寶',
    'type' => '進貨入庫單',
    'document_date' => '2026-08-18',
    'document_no' => 'JH-OTHER',
    'amount' => 400,
];
$before = [
    'supplier_name' => '',
    'type' => '進貨入庫單',
    'document_date' => '2026-08-17',
    'document_no' => 'JH-20260817-001',
    'amount' => 999,
];
$sale = [
    'supplier_name' => '',
    'type' => '銷售出庫單',
    'source_doc_type' => '銷售出庫單',
    'document_date' => '2026-08-18',
    'document_no' => 'XS-1',
    'amount' => 50,
];

expect(acc_is_jieyuan_stock_movement($named), 'named 捷元 is included');
expect(acc_is_jieyuan_stock_movement($empty), 'empty supplier inbound is included');
expect(!acc_is_jieyuan_stock_movement($other), 'other named supplier is skipped');
expect(!acc_is_inbound_stock_movement($sale), 'sales outbound is not inbound');

$docs = acc_group_jieyuan_purchase_docs([
    $empty,
    array_merge($empty, ['amount' => 1050, 'product_title' => '金士頓 DDR4']),
    $named,
    $other,
    $before,
    $sale,
], '2026-08-18');

$byNo = [];
foreach ($docs as $doc) $byNo[$doc['document_no']] = $doc;

expect(count($docs) === 2, 'two purchase docs after cutoff');
expect(($byNo['JH-20260818-001']['total_amount'] ?? 0) === 1150, 'empty-supplier lines sum to 1150');
expect(($byNo['JH-20260819-001']['total_amount'] ?? 0) === 10, 'named 捷元 still grouped');
expect(!isset($byNo['JH-20260817-001']), 'before cutoff skipped');
expect(!isset($byNo['JH-OTHER']), 'other supplier skipped');

$memory = [];
for ($i = 0; $i < 45; $i++) {
    $memory[] = [
        'supplier_name' => '',
        'type' => '進貨入庫單',
        'source_doc_type' => '進貨入庫單',
        'document_date' => '2026-08-18',
        'document_no' => 'JH-20260818-001',
        'amount' => $i === 0 ? 47549 - 44 : 1,
        'product_title' => 'item' . $i,
    ];
}
$grouped = acc_group_jieyuan_purchase_docs($memory, '2026-08-18');
expect(count($grouped) === 1 && $grouped[0]['total_amount'] === 47549, '45 memory lines total 47549');

if ($failed > 0) {
    fwrite(STDERR, "$failed failed\n");
    exit(1);
}
echo "all passed\n";
