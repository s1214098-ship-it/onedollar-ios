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

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'document-print-lib.php';

$href = baohui_ops_document_print_href('JH-20260804-001', '進貨入庫單');
expect(str_contains($href, 'print_document=JH-20260804-001'), 'print URL includes document number');
expect(str_contains($href, 'doc_type='), 'print URL includes document type');

$lines = [
    ['document_no' => 'JH-20260804-001', 'source_doc_type' => '進貨入庫單', 'product_title' => '記憶體', 'barcode' => 'RAM001', 'qty' => 2, 'unit_cost' => 667, 'amount' => 1334, 'supplier_name' => '新竹物流', 'handler' => '郭峰志', 'document_date' => '2026-08-04', 'payment_status' => '未付款'],
    ['document_no' => 'JH-20260804-001', 'source_doc_type' => '進貨入庫單', 'product_title' => '固態硬碟', 'barcode' => 'SSD001', 'qty' => 1, 'unit_cost' => 60, 'amount' => 60, 'supplier_name' => '新竹物流', 'document_date' => '2026-08-04'],
];
$doc = baohui_ops_print_build(['stockMovements' => $lines], 'JH-20260804-001', '進貨入庫單');
expect(is_array($doc), 'builds a stock-in document');
expect(($doc['title'] ?? '') === '進貨單', 'title is 進貨單');
expect(($doc['no'] ?? '') === 'JH-20260804-001', 'keeps the purchase document number');
expect(count($doc['items'] ?? []) === 2, 'groups both purchase lines onto one sheet');
expect((float)($doc['total'] ?? 0) === 1394.0, 'purchase total is 1334+60');

$html = baohui_ops_print_html($doc, false);
expect(str_contains($html, 'JH-20260804-001'), 'print HTML shows the document number');
expect(str_contains($html, '進貨單'), 'print HTML shows 進貨單');
expect(str_contains($html, 'window.print()'), 'print HTML has a print button');
expect(str_contains($html, '半張'), 'print layout is labeled as a half sheet');
expect(str_contains($html, 'max-height:138mm') || str_contains($html, '148mm'), 'print CSS keeps the voucher on the top half of A4');
expect(!str_contains($html, 'font-size:28px'), 'does not use the oversized full-page title');

$missing = baohui_ops_print_build(['stockMovements' => $lines], 'NO-SUCH-DOC', '進貨入庫單');
expect($missing === null, 'unknown document number returns null');

$repair = baohui_ops_print_build([
    'repairDocuments' => [['repair_no' => 'RP-1', 'item' => '筆電', 'estimated_fee' => 800, 'contact_name' => '王小明']],
], 'RP-1', '維修單據');
expect(is_array($repair) && ($repair['title'] ?? '') === '維修單', 'repair documents are printable');

if ($failed > 0) {
    fwrite(STDERR, $failed . " assertion(s) failed\n");
    exit(1);
}
echo "all passed\n";
