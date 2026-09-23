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

$link = baohui_ops_print_link_html('RP-1', '維修單據');
expect(str_contains($link, 'print_document=RP-1'), 'print helper emits a document URL');
expect(str_contains($link, '列印'), 'print helper uses 列印 as the default label');

$count = baohui_ops_print_build([
    'inventoryCounts' => [['doc_no' => 'CNT-1', 'warehouse_name' => '總倉', 'date' => '2026-08-20', 'lines' => [['title' => 'SSD', 'qty' => 2, 'system_qty' => 1, 'diff_qty' => 1]]]],
], 'CNT-1', '盤點單據');
expect(is_array($count) && ($count['title'] ?? '') === '盤點單', 'inventory counts are printable');

$transfer = baohui_ops_print_build([
    'inventoryTransfers' => [['doc_no' => 'TRF-1', 'from_warehouse' => 'A', 'to_warehouse' => 'B', 'lines' => [['title' => 'RAM', 'qty' => 1]]]],
], 'TRF-1', '調撥單據');
expect(is_array($transfer) && ($transfer['title'] ?? '') === '調撥單', 'transfers are printable');

$receipt = baohui_ops_print_build([
    'collectionReceipts' => [['receipt_no' => 'RC-1', 'customer_name' => '王小明', 'amount' => 500]],
], 'RC-1', '收款單');
expect(is_array($receipt) && ($receipt['title'] ?? '') === '收款單' && (float)($receipt['total'] ?? 0) === 500.0, 'collection receipts are printable');

$billing = baohui_ops_print_build([
    'billingRequests' => [['request_no' => 'BL-1', 'customer_name' => '李小姐', 'total_amount' => 1200, 'items' => [['product_title' => '主機', 'request_amount' => 1200]]]],
], 'BL-1', '請款單');
expect(is_array($billing) && ($billing['title'] ?? '') === '請款單', 'billing requests are printable');

$loss = baohui_ops_print_build([
    'inventoryAdjustments' => [['doc_no' => 'LOSS-1', 'type' => 'loss', 'product_title' => '鍵盤', 'qty' => 1, 'unit_cost' => 200]],
], 'LOSS-1', '報損單');
expect(is_array($loss) && ($loss['title'] ?? '') === '報損單', 'loss slips are printable');

$bad = baohui_ops_print_build([
    'badDebts' => [['case_no' => 'BD-1', 'customer_name' => '呆帳客戶', 'amount' => 3000, 'remaining_amount' => 3000, 'items' => [['product_title' => '螢幕', 'bad_debt_amount' => 3000, 'receivable' => 3000]]]],
], 'BD-1', '呆帳案件');
expect(is_array($bad) && ($bad['title'] ?? '') === '呆帳案件', 'bad-debt cases are printable');

$fixedPay = baohui_ops_print_build([
    'fixedExpensePayments' => [['payment_no' => 'FX-1', 'expense_name' => '租金', 'amount' => 15000, 'supplier_name' => '房東']],
], 'FX-1', '固定開支付款');
expect(is_array($fixedPay) && ($fixedPay['title'] ?? '') === '固定開支付款', 'fixed expense payments are printable');

$asset = baohui_ops_print_build([
    'fixedAssets' => [['asset_no' => 'FA-1', 'asset_name' => 'NAS', 'acquisition_cost' => 18000, 'custodian' => '倉管']],
], 'FA-1', '固定資產卡');
expect(is_array($asset) && ($asset['title'] ?? '') === '固定資產卡', 'fixed assets are printable');

$move = baohui_ops_print_build([
    'mobileAssetMovements' => [['movement_no' => 'MV-1', 'movement_type' => '借出', 'asset_name' => '筆電', 'to_holder' => '工程師']],
], 'MV-1', '移動資產異動');
expect(is_array($move) && ($move['title'] ?? '') === '移動資產異動', 'mobile asset movements are printable');

$pay = baohui_ops_print_build([
    'paymentRecords' => [['id' => 'pay_batch_1', 'amount' => 800, 'applied_amount' => 800, 'method' => '匯款', 'allocations' => [['schedule_id' => 's1', 'amount' => 800]]]],
], 'pay_batch_1', '付款紀錄');
expect(is_array($pay) && ($pay['title'] ?? '') === '付款紀錄', 'payment records are printable');

$generic = baohui_ops_print_build([
    'unknownDocs' => [['id' => 'X-99', 'title_name' => '臨時單', 'amount' => 10, 'note' => 'fallback']],
], 'X-99', '其他單據');
expect(is_array($generic) && ($generic['title'] ?? '') === '其他單據', 'unknown document types still print via generic fallback');
expect(str_contains(baohui_ops_print_html($generic, false), '半張'), 'generic fallback still uses the half-sheet layout');

if ($failed > 0) {
    fwrite(STDERR, $failed . " assertion(s) failed\n");
    exit(1);
}
echo "all passed\n";
