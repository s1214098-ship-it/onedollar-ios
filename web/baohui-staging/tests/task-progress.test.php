<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'task-progress-audit-lib.php';

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

$js = file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'admin-task-progress.js');
expect($js !== false && $js !== '', 'overlay script exists');
expect(str_contains($js, '二次修正進度時間'), 'labels second revision time as reporter-proposed');
expect(str_contains($js, '回填進度時間'), 'asks for backfilled progress time');
expect(str_contains($js, '目前完成率') || str_contains($js, '完成綠比例'), 'asks for completion rate');
expect(str_contains($js, '目前做到哪裡'), 'requires current progress text');
expect(str_contains($js, 'progressLogs'), 'keeps a checkpoint trail');
expect(str_contains($js, 'task-progress-audit.php'), 'calls AI audit endpoint');
expect(str_contains($js, '曾憲') || str_contains($js, '曾麒'), 'keeps 曾憲/曾麒 completion rate visible');
expect(str_contains($js, '依目前進度表等分剩餘數量'), 'can split remaining work from the live progress table');
expect(str_contains($js, '還沒好的內容'), 'shows leftover work content');
expect(str_contains($js, '來不及完成'), 'lets staff report they cannot finish on time');
expect(str_contains($js, '完成綠比例'), 'asks for a green completion ratio');
expect(str_contains($js, '何時可以完成'), 'asks when the leftover work can be finished');
expect(str_contains($js, 'taskAssignMany'), 'allows assigning one remaining item to multiple people');

$evidence = [
    'now' => strtotime('2026-08-20 14:00:00'),
    'invoices' => ['total' => 366, 'printed' => 7, 'unprinted' => 359, 'available' => true],
    'products' => [
        'total' => 3754,
        'stocked' => 127,
        'zero_stock' => 3627,
        'mainland_source' => 1,
        'mainland_source_stocked' => 0,
        'mainland_recycle' => 108,
        'mainland_recycle_stocked' => 0,
        'mainland_inbound_docs' => 1,
        'available' => true,
    ],
];

$task = [
    'id' => 1,
    'name' => '公司內部系統 建檔及照片更新',
    'assign' => '曾麒',
    'incompleteReason' => '電子發票已全部印出，庫存建檔只剩大陸產品還沒建',
];
$payload = [
    'progressRate' => 80,
    'progressAt' => '2026-08-19 08:59',
    'progressNote' => '電子發票已全部印出，庫存建檔只剩大陸產品還沒建',
    'proposedCompleteAt' => '2026-08-21 17:30',
];
$audit = bh_task_audit_report($task, $payload, [], $evidence);
expect(($audit['verdict'] ?? '') === '與系統不符', 'invoice-all-printed claim is rejected against 7/366 printed, verdict=' . ($audit['verdict'] ?? ''));
expect(!empty($audit['claims']['invoice_all_printed']), 'extracts invoice-all-printed claim');
expect(!empty($audit['claims']['mainland_inventory_remaining']) || !empty($audit['claims']['only_mainland_left']), 'extracts mainland remaining claim');
$invoiceCheck = null;
foreach ($audit['checks'] as $check) {
    if (($check['id'] ?? '') === 'invoices') $invoiceCheck = $check;
}
expect(($invoiceCheck['status'] ?? '') === 'mismatch', 'invoice check is mismatch');
expect(str_contains((string)($invoiceCheck['detail'] ?? ''), '359') || str_contains((string)($invoiceCheck['detail'] ?? ''), '未印'), 'invoice mismatch cites unprinted count');

$okPayload = [
    'progressRate' => 40,
    'progressAt' => '2026-08-20 09:00',
    'progressNote' => '電子發票還沒印完，大陸產品還沒建檔',
    'proposedCompleteAt' => '2026-08-21 17:30',
];
$ok = bh_task_audit_report($task, $okPayload, [], $evidence);
expect(in_array($ok['verdict'] ?? '', ['核實', '部分核實'], true), 'honest remaining-work note is verified or partially verified, got ' . ($ok['verdict'] ?? ''));

$blocked = bh_task_audit_report($task, [
    'progressRate' => 50,
    'progressAt' => '2026-08-20 18:00',
    'progressNote' => '大陸產品還在建檔中',
    'proposedCompleteAt' => '2026-08-20 10:00',
], [], $evidence);
expect(!empty($blocked['blocking']), 'proposed time before progress time is blocking');
expect(($blocked['verdict'] ?? '') === '時間不合理', 'blocking verdict is 時間不合理');

$missing = bh_task_audit_report($task, [
    'progressRate' => 50,
    'progressAt' => '2026-08-20 09:00',
    'progressNote' => '',
    'proposedCompleteAt' => '2026-08-21 17:30',
], [], $evidence);
expect(!empty($missing['blocking']), 'missing current-status note is blocking');

$display = bh_task_audit_report($task, [
    'progressNote' => $task['incompleteReason'],
    'proposedCompleteAt' => '2026-08-21 17:30',
], [], $evidence, ['display' => true]);
expect(($display['verdict'] ?? '') === '與系統不符', 'existing unfinished reason is audited on display');
expect(empty($display['blocking']), 'display audit does not block the page');

$printedAll = $evidence;
$printedAll['invoices'] = ['total' => 10, 'printed' => 10, 'unprinted' => 0, 'available' => true];
$printedAll['products']['zero_stock'] = 1;
$printedAll['products']['stocked'] = 3753;
$honestDone = bh_task_audit_report($task, [
    'progressRate' => 90,
    'progressAt' => '2026-08-20 09:00',
    'progressNote' => '電子發票已全部印出，庫存建檔只剩大陸產品還沒建',
    'proposedCompleteAt' => '2026-08-21 17:30',
], [], $printedAll);
expect(($honestDone['verdict'] ?? '') === '核實' || ($honestDone['verdict'] ?? '') === '部分核實', 'matching invoice and mainland leftover can pass, got ' . ($honestDone['verdict'] ?? ''));

expect(bh_task_equal_split(10, 3) === [4, 3, 3], '10 remaining split across 3 people is 4,3,3');
expect(bh_task_equal_split(5, 1) === [5], 'one person gets the whole remaining quantity');
expect(bh_task_equal_split(0, 2) === [0, 0], 'zero remaining splits to zeros');
expect(bh_task_green_rate(7, 366) === (int)round(100 * 7 / 366), 'green rate matches done/total');

$board = bh_task_progress_board($evidence['invoices'], $evidence['products']);
expect(($board['remainingTotal'] ?? 0) >= 359, 'board remaining total includes unprinted invoices');
$invoiceRow = null;
$skuRow = null;
foreach ($board['rows'] as $row) {
    if (($row['id'] ?? '') === 'invoice-print') $invoiceRow = $row;
    if (($row['id'] ?? '') === 'sku-available') $skuRow = $row;
}
expect(($invoiceRow['done'] ?? null) === 7, 'invoice row carries printed count');
expect(($invoiceRow['total'] ?? null) === 366, 'invoice row carries total count');
expect(($invoiceRow['remaining'] ?? null) === 359, 'invoice row carries leftover count');
expect(($invoiceRow['leftoverKind'] ?? '') === '未確認列印的電子發票', 'invoice leftover kind is named');
expect(($skuRow['remaining'] ?? null) === 3627, 'product row uses zero-stock leftover when available-qty is absent');
$shareNote = bh_task_share_note($invoiceRow, 120, 3, 0);
expect(str_contains($shareNote, '還沒好'), 'share note lists leftover kind');
expect(str_contains($shareNote, '359'), 'share note lists remaining quantity');
expect(str_contains($shareNote, '綠比例'), 'share note lists green completion ratio');
expect(str_contains($shareNote, '何時可以完成'), 'share note tells staff how to report a new finish time');

if ($failed > 0) {
    fwrite(STDERR, $failed . " assertion(s) failed\n");
    exit(1);
}
echo "all passed\n";
