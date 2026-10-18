<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'accounting-lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'accounting-parser.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'accounting-gmail-source.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'accounting-jieyuan-po.php';

$action = trim((string)($_GET['action'] ?? 'status'));
$pdo = acc_db();
$context = acc_require_capability('invoice_view');

if ($action === 'status') {
    $counts = [];
    foreach ([
        'pending' => "workflow_status IN ('pending_review','needs_official_voucher','ready_to_book')",
        'booked' => "workflow_status = 'booked'",
        'exception' => "workflow_status IN ('exception','duplicate')",
        'non_company' => "workflow_status = 'non_company'",
        'unprinted' => "print_status = 'not_printed'",
        'partners' => '1=1',
    ] as $key => $where) {
        if ($key === 'partners') {
            $counts[$key] = (int)$pdo->query('SELECT COUNT(*) FROM invoice_partners WHERE enabled = 1')->fetchColumn();
            continue;
        }
        $counts[$key] = (int)$pdo->query("SELECT COUNT(*) FROM electronic_invoices WHERE $where")->fetchColumn();
    }
    acc_json([
        'ok' => true,
        'user' => $context['user'],
        'department' => $context['department'],
        'capabilities' => $context['capabilities'],
        'csrf' => acc_csrf_token(),
        'isAdmin' => !empty($context['isAdmin']),
        'settings' => [
            'company_tax_id' => acc_setting($pdo, 'company_tax_id', ACC_COMPANY_TAX_ID),
            'company_name' => acc_setting($pdo, 'company_name', '寶輝電腦'),
            'gmail_sync_minutes' => (int)acc_setting($pdo, 'gmail_sync_minutes', ACC_DEFAULT_SYNC_MINUTES),
            'gmail_inbox_name' => 'Invoices/GmailInbox',
            'electronic_folder' => 'Invoices/電子檔',
            'printed_folder' => 'Invoices/已列印',
            'jieyuan_po_match_from' => acc_jieyuan_po_match_from(),
        ],
        'gmail' => (static function () {
            try {
                return acc_gmail_oauth_status();
            } catch (Throwable $e) {
                return [
                    'has_client' => false,
                    'connected' => false,
                    'email' => '',
                    'redirect_uri' => 'https://baohui.paohui.org/accounting-gmail-oauth.php',
                    'error' => $e->getMessage(),
                ];
            }
        })(),
        'counts' => $counts,
    ]);
}

if ($action === 'list') {
    $tab = trim((string)($_GET['tab'] ?? 'pending'));
    $where = match ($tab) {
        'booked' => "workflow_status = 'booked'",
        'exception' => "workflow_status IN ('exception','duplicate')",
        'non_company' => "workflow_status = 'non_company'",
        'unprinted' => "print_status = 'not_printed'",
        default => "workflow_status IN ('pending_review','needs_official_voucher','ready_to_book')",
    };
    $stmt = $pdo->query("SELECT i.id, i.invoice_uuid, i.seller_name, i.seller_tax_id, i.buyer_name, i.buyer_tax_id,
        i.invoice_number, i.invoice_date, i.net_amount, i.tax_amount, i.total_amount, i.source_type,
        i.workflow_status, i.official_voucher_status, i.parse_confidence, i.amount_check_status,
        i.current_pdf_type, i.current_pdf_version, i.print_status, i.print_count, i.last_printed_at, i.updated_at,
        i.po_match_status, i.po_document_no, i.po_match_note, i.jieyuan_order_no,
        (
            SELECT d.id FROM invoice_documents d
            WHERE d.invoice_id = i.id
            ORDER BY d.is_original DESC, d.pdf_version DESC, d.id DESC
            LIMIT 1
        ) AS document_id
        FROM electronic_invoices i WHERE $where ORDER BY i.seller_name COLLATE NOCASE, i.invoice_date DESC, i.id DESC LIMIT 500");
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['has_pdf'] = !empty($row['document_id']);
        $row['vendor_name'] = acc_invoice_vendor_label($row);
    }
    unset($row);
    acc_audit($pdo, $context, 'invoice_list_view', null, ['tab' => $tab, 'count' => count($rows)]);
    acc_json(['ok' => true, 'rows' => $rows]);
}

if ($action === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM electronic_invoices WHERE id = ?');
    $stmt->execute([$id]);
    $invoice = $stmt->fetch();
    if (!$invoice) acc_json(['ok' => false, 'error' => '找不到發票'], 404);
    $items = $pdo->prepare('SELECT * FROM invoice_line_items WHERE invoice_id = ? ORDER BY line_no, id');
    $items->execute([$id]);
    $documents = $pdo->prepare('SELECT id, pdf_type, pdf_sha256, pdf_generated_at, pdf_version, is_original, created_by, source_category, original_filename FROM invoice_documents WHERE invoice_id = ? ORDER BY pdf_version DESC');
    $documents->execute([$id]);
    acc_audit($pdo, $context, 'invoice_detail_view', $id);
    unset($invoice['current_pdf_storage_path']);
    acc_json(['ok' => true, 'invoice' => $invoice, 'items' => $items->fetchAll(), 'documents' => $documents->fetchAll()]);
}

if ($action === 'vendors') {
    $rows = $pdo->query('SELECT id, vendor_key, vendor_name, seller_tax_id, sender_pattern, subject_pattern, parser_class, requires_official_voucher, enabled, updated_at FROM invoice_vendor_rules ORDER BY vendor_name')->fetchAll();
    acc_json(['ok' => true, 'rows' => $rows]);
}

if ($action === 'exports') {
    acc_require_capability('invoice_export');
    $rows = $pdo->query('SELECT id, export_uuid, export_format, content_sha256, record_count, exported_by, exported_at FROM invoice_exports ORDER BY id DESC LIMIT 200')->fetchAll();
    acc_json(['ok' => true, 'rows' => $rows]);
}

if ($action === 'download') {
    $documentId = (int)($_GET['document_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT d.*, i.id AS invoice_id, i.invoice_number FROM invoice_documents d JOIN electronic_invoices i ON i.id = d.invoice_id WHERE d.id = ?');
    $stmt->execute([$documentId]);
    $document = $stmt->fetch();
    if (!$document) acc_json(['ok' => false, 'error' => '找不到文件'], 404);
    $path = (string)$document['pdf_storage_path'];
    if (!acc_is_private_document_path($path) || !is_file($path)) acc_json(['ok' => false, 'error' => '私有文件不存在'], 404);
    acc_audit($pdo, $context, 'invoice_pdf_download', (int)$document['invoice_id'], ['document_id' => $documentId, 'pdf_hash' => $document['pdf_sha256']]);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="invoice-' . preg_replace('/[^A-Za-z0-9-]/', '', (string)$document['invoice_number']) . '-v' . (int)$document['pdf_version'] . '.pdf"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

if ($action === 'print_view') {
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? '')))));
    if (!$ids) acc_json(['ok' => false, 'error' => '尚未選擇發票'], 422);
    $groupMode = ((string)($_GET['group'] ?? '')) === 'month' ? 'month' : 'vendor';
    $groups = [];
    foreach ($ids as $id) {
        $stmt = $pdo->prepare('SELECT * FROM electronic_invoices WHERE id = ?');
        $stmt->execute([$id]);
        $invoice = $stmt->fetch();
        if (!$invoice) continue;
        $document = acc_current_document($pdo, $id);
        if (!$document) continue;
        $key = $groupMode === 'month' ? acc_invoice_month_key($invoice) : acc_invoice_vendor_label($invoice);
        $label = $groupMode === 'month' ? acc_invoice_month_label($invoice) : acc_invoice_vendor_label($invoice);
        $groups[$key] ??= ['label' => $label, 'items' => []];
        $groups[$key]['items'][] = [
            'invoice' => $invoice,
            'document_id' => (int)$document['id'],
        ];
    }
    if ($groupMode === 'month') ksort($groups, SORT_STRING);
    else ksort($groups, SORT_STRING);
    $title = $groupMode === 'month' ? '依月份列印' : '依廠家分類列印';
    $hint = $groupMode === 'month'
        ? '已依發票月份分組。每個月份會從新的一頁開始，方便整月一次列印。'
        : '電子檔已依廠家存到「已列印」資料夾，可直接從該資料夾一次列印。若瀏覽器有開啟列印視窗，按 Ctrl+P 也可列印本頁。';
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: private, no-store');
    echo '<!doctype html><html lang="zh-TW"><head><meta charset="utf-8"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title><style>';
    echo 'body{font-family:"Microsoft JhengHei","Noto Sans TC",sans-serif;margin:16px;color:#172033}';
    echo 'h1{font-size:20px;margin:0 0 8px}p{color:#64748b}.vendor{page-break-before:always;margin-top:18px}.vendor:first-child{page-break-before:auto}';
    echo 'h2{font-size:18px;border-bottom:2px solid #155eef;padding-bottom:6px}.sheet{width:100%;height:1080px;border:1px solid #d9e2ec;margin:10px 0;background:#fff}';
    echo '@media print{body{margin:0}.no-print{display:none}.sheet{height:260mm;border:0}}';
    echo '</style></head><body>';
    echo '<div class="no-print"><h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1><p>' . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . '</p><button onclick="window.print()">立即列印</button></div>';
    foreach ($groups as $group) {
        echo '<section class="vendor"><h2>' . htmlspecialchars((string)$group['label'], ENT_QUOTES, 'UTF-8') . '（' . count($group['items']) . ' 張）</h2>';
        foreach ($group['items'] as $item) {
            $number = htmlspecialchars((string)$item['invoice']['invoice_number'], ENT_QUOTES, 'UTF-8');
            $vendor = htmlspecialchars(acc_invoice_vendor_label($item['invoice']), ENT_QUOTES, 'UTF-8');
            $date = htmlspecialchars((string)$item['invoice']['invoice_date'], ENT_QUOTES, 'UTF-8');
            echo '<p>' . $number . '　' . $date . ($groupMode === 'month' ? '　' . $vendor : '') . '</p>';
            echo '<iframe class="sheet" src="accounting-api.php?action=download&document_id=' . (int)$item['document_id'] . '" title="' . $number . '"></iframe>';
        }
        echo '</section>';
    }
    echo '<script>window.addEventListener("load",function(){setTimeout(function(){window.print()},600)});</script>';
    echo '</body></html>';
    exit;
}

if ($action === 'partners') {
    $rows = $pdo->query('SELECT * FROM invoice_partners ORDER BY enabled DESC, partner_name')->fetchAll();
    $ledger = [];
    foreach ($rows as &$row) {
        $summary = acc_partner_summary($pdo, $row);
        foreach ($summary['entries'] as $entry) {
            $type = (string)($entry['entry_type'] ?? '');
            $purpose = (string)($entry['purpose'] ?? '');
            if ($type === 'offset') continue;
            if ($type === 'inbound' && $purpose === 'park') continue;
            if ($type === 'outbound' && $purpose === 'sale') continue;
            $ledger[] = [
                'partner_id' => (int)$row['id'],
                'partner_name' => (string)$row['partner_name'],
                'tax_id' => (string)$row['tax_id'],
                'entry_id' => (int)($entry['id'] ?? 0),
                'entry_type' => $type,
                'purpose' => $purpose,
                'entry_date' => (string)($entry['entry_date'] ?? ''),
                'invoice_number' => (string)($entry['invoice_number'] ?? ''),
                'face_amount' => (int)($entry['face_amount'] ?? 0),
                'net_amount' => (int)($entry['net_amount'] ?? 0),
                'paper_vat' => (int)($entry['paper_vat'] ?? 0),
                'buyback_amount' => (int)($entry['buyback_amount'] ?? 0),
                'receipt_due_amount' => (int)($entry['receipt_due_amount'] ?? 0),
                'receipt_use' => (string)($entry['receipt_use'] ?? ''),
                'issuer_name' => (string)($entry['issuer_name'] ?? ''),
                'receiver_name' => (string)($entry['receiver_name'] ?? ''),
                'item_note' => (string)($entry['item_note'] ?? ''),
                'auto_from_invoice' => (int)($entry['auto_from_invoice'] ?? 0),
            ];
        }
        unset($summary['entries']);
        $row['summary'] = $summary;
    }
    unset($row);
    $invoicePool = (int)$pdo->query("SELECT COUNT(*) FROM electronic_invoices WHERE workflow_status NOT IN ('exception','duplicate','non_company','failed')")->fetchColumn();
    acc_json(['ok' => true, 'rows' => $rows, 'ledger' => $ledger, 'invoice_pool_count' => $invoicePool]);
}

if ($action === 'partner_detail') {
    $id = (int)($_GET['id'] ?? 0);
    $partner = acc_partner_get($pdo, $id);
    if (!$partner) acc_json(['ok' => false, 'error' => '找不到往來客戶'], 404);
    $summary = acc_partner_summary($pdo, $partner);
    acc_json([
        'ok' => true,
        'partner' => $partner,
        'summary' => $summary,
        'our_company' => acc_our_company($pdo),
        'partner_company' => acc_partner_company($partner),
        'records' => acc_partner_invoice_records($pdo, $partner),
    ]);
}

if ($action === 'gmail_status') {
    acc_json(['ok' => true, 'gmail' => acc_gmail_oauth_status(), 'isAdmin' => !empty($context['isAdmin'])]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') acc_json(['ok' => false, 'error' => '不支援的請求'], 405);
acc_require_csrf();

if ($action === 'update') {
    $context = acc_require_capability('invoice_edit');
    $input = acc_request_json();
    $id = (int)($input['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM electronic_invoices WHERE id = ?');
    $stmt->execute([$id]);
    $before = $stmt->fetch();
    if (!$before) acc_json(['ok' => false, 'error' => '找不到發票'], 404);
    if (($before['workflow_status'] ?? '') === 'booked') acc_json(['ok' => false, 'error' => '已入帳發票不可直接修改，請走更正流程'], 409);
    $sellerTaxId = preg_replace('/\D+/', '', (string)($input['seller_tax_id'] ?? $before['seller_tax_id']));
    $buyerTaxId = preg_replace('/\D+/', '', (string)($input['buyer_tax_id'] ?? $before['buyer_tax_id']));
    $invoiceNumber = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($input['invoice_number'] ?? $before['invoice_number'])));
    $net = (int)($input['net_amount'] ?? $before['net_amount']);
    $tax = (int)($input['tax_amount'] ?? $before['tax_amount']);
    $total = (int)($input['total_amount'] ?? $before['total_amount']);
    $companyTaxId = acc_setting($pdo, 'company_tax_id', ACC_COMPANY_TAX_ID);
    $companyMatch = $buyerTaxId !== '' && hash_equals($companyTaxId, $buyerTaxId) ? 1 : 0;
    $amountStatus = ($net + $tax === $total) ? 'balanced' : 'mismatch';
    $workflow = $companyMatch ? (($amountStatus === 'balanced') ? 'pending_review' : 'exception') : 'non_company';
    $update = $pdo->prepare('UPDATE electronic_invoices SET seller_name=?, seller_tax_id=?, buyer_name=?, buyer_tax_id=?, invoice_number=?, invoice_date=?, net_amount=?, tax_amount=?, total_amount=?, company_match=?, amount_check_status=?, workflow_status=?, review_note=?, row_version=row_version+1, updated_at=CURRENT_TIMESTAMP WHERE id=?');
    try {
        $update->execute([
            trim((string)($input['seller_name'] ?? $before['seller_name'])), $sellerTaxId,
            trim((string)($input['buyer_name'] ?? $before['buyer_name'])), $buyerTaxId,
            $invoiceNumber, trim((string)($input['invoice_date'] ?? $before['invoice_date'])),
            $net, $tax, $total, $companyMatch, $amountStatus, $workflow,
            trim((string)($input['review_note'] ?? $before['review_note'])), $id,
        ]);
    } catch (PDOException $e) {
        if (str_contains(strtolower($e->getMessage()), 'unique')) acc_json(['ok' => false, 'error' => '相同賣方統編與發票號碼已存在，已阻擋重複入帳'], 409);
        throw $e;
    }
    acc_audit($pdo, $context, 'invoice_update', $id, ['before_version' => (int)$before['row_version'], 'amount_check_status' => $amountStatus, 'company_match' => $companyMatch]);
    acc_json(['ok' => true, 'workflow_status' => $workflow, 'amount_check_status' => $amountStatus]);
}

if ($action === 'review') {
    $context = acc_require_capability('invoice_review');
    $input = acc_request_json();
    $id = (int)($input['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM electronic_invoices WHERE id = ?');
    $stmt->execute([$id]);
    $invoice = $stmt->fetch();
    if (!$invoice) acc_json(['ok' => false, 'error' => '找不到發票'], 404);
    if (!(int)$invoice['company_match']) acc_json(['ok' => false, 'error' => '買方統編不符合公司統編，不可核准入帳'], 409);
    if ($invoice['amount_check_status'] !== 'balanced') acc_json(['ok' => false, 'error' => '金額不平，請先修正'], 409);
    $needsOfficial = $invoice['official_voucher_status'] === 'required_missing';
    $workflow = $needsOfficial ? 'needs_official_voucher' : 'ready_to_book';
    $update = $pdo->prepare('UPDATE electronic_invoices SET workflow_status=?, reviewed_by=?, reviewed_at=CURRENT_TIMESTAMP, review_note=?, row_version=row_version+1, updated_at=CURRENT_TIMESTAMP WHERE id=?');
    $update->execute([$workflow, $context['user'], trim((string)($input['note'] ?? '')), $id]);
    acc_audit($pdo, $context, 'invoice_review', $id, ['result' => $workflow]);
    acc_json(['ok' => true, 'workflow_status' => $workflow]);
}

if ($action === 'book') {
    $context = acc_require_capability('invoice_book');
    $input = acc_request_json();
    $id = (int)($input['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM electronic_invoices WHERE id = ?');
    $stmt->execute([$id]);
    $invoice = $stmt->fetch();
    if (!$invoice) acc_json(['ok' => false, 'error' => '找不到發票'], 404);
    if ($invoice['workflow_status'] !== 'ready_to_book' || !(int)$invoice['company_match'] || $invoice['amount_check_status'] !== 'balanced') {
        acc_json(['ok' => false, 'error' => '此發票尚未符合正式入帳條件'], 409);
    }
    if ($invoice['official_voucher_status'] === 'required_missing') acc_json(['ok' => false, 'error' => '尚未取得正式會計憑證'], 409);
    $update = $pdo->prepare("UPDATE electronic_invoices SET workflow_status='booked', booked_by=?, booked_at=CURRENT_TIMESTAMP, row_version=row_version+1, updated_at=CURRENT_TIMESTAMP WHERE id=?");
    $update->execute([$context['user'], $id]);
    acc_audit($pdo, $context, 'invoice_book', $id);
    acc_json(['ok' => true]);
}

if ($action === 'print_submit') {
    $context = acc_require_capability('invoice_print');
    $input = acc_request_json();
    $documentId = (int)($input['document_id'] ?? 0);
    $copies = max(1, min(20, (int)($input['copies'] ?? 1)));
    $printer = trim((string)($input['printer_name'] ?? '瀏覽器列印'));
    $reprintNote = trim((string)($input['reprint_note'] ?? ''));
    $stmt = $pdo->prepare('SELECT d.*, i.print_status, i.print_count FROM invoice_documents d JOIN electronic_invoices i ON i.id=d.invoice_id WHERE d.id=?');
    $stmt->execute([$documentId]);
    $doc = $stmt->fetch();
    if (!$doc) acc_json(['ok' => false, 'error' => '找不到列印文件'], 404);
    if (($doc['print_status'] !== 'not_printed' || (int)$doc['print_count'] > 0) && $reprintNote === '') acc_json(['ok' => false, 'error' => '重印必須填寫原因'], 422);
    $pdo->beginTransaction();
    $log = $pdo->prepare("INSERT INTO invoice_print_logs (invoice_id, document_id, user_name, printer_name, copies, pdf_hash, result, reprint_note) VALUES (?, ?, ?, ?, ?, ?, 'submitted_pending_confirmation', ?)");
    $log->execute([(int)$doc['invoice_id'], $documentId, $context['user'], $printer, $copies, $doc['pdf_sha256'], $reprintNote]);
    $printLogId = (int)$pdo->lastInsertId();
    $invoiceStmt = $pdo->prepare('SELECT * FROM electronic_invoices WHERE id = ?');
    $invoiceStmt->execute([(int)$doc['invoice_id']]);
    $invoice = $invoiceStmt->fetch() ?: [];
    if ($invoice && is_file((string)$doc['pdf_storage_path'])) {
        acc_archive_printed_pdf($invoice, (string)$doc['pdf_storage_path']);
        acc_copy_to_electronic_folder($invoice, (string)$doc['pdf_storage_path'], (string)$doc['original_filename']);
    }
    $pdo->prepare("UPDATE electronic_invoices SET print_status='submitted_pending_confirmation', last_printed_at=CURRENT_TIMESTAMP, last_printed_by=?, last_printer_name=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
        ->execute([$context['user'], $printer, (int)$doc['invoice_id']]);
    $pdo->commit();
    acc_audit($pdo, $context, 'invoice_print_submitted', (int)$doc['invoice_id'], ['document_id' => $documentId, 'copies' => $copies, 'printer' => $printer, 'pdf_hash' => $doc['pdf_sha256']]);
    acc_json(['ok' => true, 'print_status' => 'submitted_pending_confirmation', 'print_log_id' => $printLogId]);
}

if ($action === 'print_batch') {
    $context = acc_require_capability('invoice_print');
    $input = acc_request_json();
    $ids = array_values(array_unique(array_map('intval', (array)($input['ids'] ?? []))));
    $ids = array_values(array_filter($ids, static fn($id) => $id > 0));
    if (!$ids) acc_json(['ok' => false, 'error' => '請先勾選要列印的發票'], 422);
    $note = trim((string)($input['reprint_note'] ?? '')) ?: '大量列印';
    $vendors = array_values(array_unique(array_filter(array_map('trim', (array)($input['vendors'] ?? [])))));
    $vendor = trim((string)($input['vendor'] ?? ''));
    if ($vendor !== '') $vendors[] = $vendor;
    $vendors = array_values(array_unique($vendors));
    $months = array_values(array_unique(array_filter(array_map('trim', (array)($input['months'] ?? [])))));
    $groupBy = trim((string)($input['group_by'] ?? ''));
    if (!in_array($groupBy, ['month', 'vendor'], true)) {
        $groupBy = $months ? 'month' : 'vendor';
    }
    if ($vendors) {
        $filtered = [];
        foreach ($ids as $id) {
            $stmt = $pdo->prepare('SELECT * FROM electronic_invoices WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row && in_array(acc_invoice_vendor_label($row), $vendors, true)) $filtered[] = $id;
        }
        $ids = $filtered;
    }
    if ($months) {
        $filtered = [];
        foreach ($ids as $id) {
            $stmt = $pdo->prepare('SELECT * FROM electronic_invoices WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row && in_array(acc_invoice_month_key($row), $months, true)) $filtered[] = $id;
        }
        $ids = $filtered;
    }
    $result = acc_mark_invoices_printed($pdo, $context, $ids, '瀏覽器列印', $note);
    if (!$result['printed']) acc_json(['ok' => false, 'error' => '勾選的發票還沒有電子檔，請先匯入 Gmail PDF'], 409);
    $printView = 'accounting-api.php?action=print_view&ids=' . implode(',', array_column($result['printed'], 'invoice_id'));
    if ($groupBy === 'month') $printView .= '&group=month';
    acc_json([
        'ok' => true,
        'print_view' => $printView,
        'printed_count' => count($result['printed']),
        'missing_count' => count($result['missing']),
        'groups' => $result['groups'],
        'printed_folder' => $groupBy === 'month' ? 'Invoices/已列印（依月份分組列印）' : 'Invoices/已列印',
    ]);
}

if ($action === 'print_unmark') {
    $context = acc_require_capability('invoice_print');
    $input = acc_request_json();
    $ids = array_values(array_unique(array_map('intval', (array)($input['ids'] ?? []))));
    $ids = array_values(array_filter($ids, static fn($id) => $id > 0));
    if (!$ids) acc_json(['ok' => false, 'error' => '請先勾選要改回未列印的發票'], 422);
    $count = acc_unmark_invoices_printed($pdo, $context, $ids);
    acc_json(['ok' => true, 'unmarked' => $count]);
}

if ($action === 'gmail_save_client') {
    $context = acc_require_capability('invoice_edit');
    if (empty($context['isAdmin'])) acc_json(['ok' => false, 'error' => '只有管理員可以設定 Gmail OAuth 用戶端'], 403);
    $input = acc_request_json();
    try {
        acc_gmail_save_client((string)($input['client_id'] ?? ''), (string)($input['client_secret'] ?? ''));
    } catch (Throwable $e) {
        acc_json(['ok' => false, 'error' => $e->getMessage()], 422);
    }
    acc_audit($pdo, $context, 'gmail_oauth_client_saved', null, ['client_id' => (string)($input['client_id'] ?? '')]);
    acc_json(['ok' => true, 'gmail' => acc_gmail_oauth_status()]);
}

if ($action === 'gmail_oauth_start') {
    $context = acc_require_capability('invoice_edit');
    acc_start_session();
    $state = bin2hex(random_bytes(16));
    $_SESSION['gmail_oauth_state'] = $state;
    try {
        $url = acc_gmail_authorize_url($state);
    } catch (Throwable $e) {
        acc_json(['ok' => false, 'error' => $e->getMessage()], 422);
    }
    acc_audit($pdo, $context, 'gmail_oauth_start', null, []);
    acc_json(['ok' => true, 'url' => $url, 'gmail' => acc_gmail_oauth_status()]);
}

if ($action === 'gmail_disconnect') {
    $context = acc_require_capability('invoice_edit');
    if (empty($context['isAdmin'])) acc_json(['ok' => false, 'error' => '只有管理員可以解除 Gmail 連線'], 403);
    acc_gmail_clear_token();
    acc_audit($pdo, $context, 'gmail_oauth_disconnect', null, []);
    acc_json(['ok' => true, 'gmail' => acc_gmail_oauth_status()]);
}

if ($action === 'gmail_sync') {
    $context = acc_require_capability('invoice_edit');
    try {
        $result = acc_gmail_sync_enqueue();
    } catch (Throwable $e) {
        acc_json(['ok' => false, 'error' => $e->getMessage()], 422);
    }
    acc_audit($pdo, $context, 'gmail_oauth_sync_queued', null, $result);
    acc_json(['ok' => true, 'gmail' => acc_gmail_oauth_status()] + $result);
}

if ($action === 'jieyuan_po_match') {
    $context = acc_require_capability('invoice_edit');
    try {
        $result = acc_jieyuan_reconcile($pdo);
    } catch (Throwable $e) {
        acc_json(['ok' => false, 'error' => $e->getMessage()], 422);
    }
    acc_audit($pdo, $context, 'jieyuan_po_match', null, $result);
    acc_json(['ok' => true] + $result);
}

if ($action === 'ingest_gmail') {
    $context = acc_require_capability('invoice_edit');
    $paths = acc_collect_ingest_pdf_paths();
    $uploadDir = acc_gmail_inbox_dir() . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0770, true);
    $files = $_FILES['files'] ?? ($_FILES['files[]'] ?? null);
    if (is_array($files) && !empty($files['name'])) {
        $names = (array)$files['name'];
        $tmps = (array)($files['tmp_name'] ?? []);
        $errors = (array)($files['error'] ?? []);
        foreach ($names as $index => $name) {
            if ((int)($errors[$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            $tmp = (string)($tmps[$index] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) continue;
            $safe = acc_safe_folder_name(pathinfo((string)$name, PATHINFO_FILENAME)) . '.pdf';
            $dest = $uploadDir . DIRECTORY_SEPARATOR . $safe;
            if (!move_uploaded_file($tmp, $dest)) continue;
            $paths[] = $dest;
        }
    }
    $paths = array_values(array_unique($paths));
    $result = acc_ingest_gmail_pdfs($pdo, $paths, $context['user']);
    acc_audit($pdo, $context, 'gmail_pdf_ingest', null, ['imported' => $result['imported'], 'attached' => $result['attached'], 'skipped' => $result['skipped']]);
    acc_json(['ok' => true] + $result);
}

if ($action === 'print_confirm') {
    $context = acc_require_capability('invoice_print');
    $input = acc_request_json();
    $logId = (int)($input['print_log_id'] ?? 0);
    $result = (string)($input['result'] ?? 'confirmed');
    if (!in_array($result, ['confirmed', 'failed'], true)) acc_json(['ok' => false, 'error' => '無效的列印結果'], 422);
    $stmt = $pdo->prepare('SELECT * FROM invoice_print_logs WHERE id = ?');
    $stmt->execute([$logId]);
    $log = $stmt->fetch();
    if (!$log) acc_json(['ok' => false, 'error' => '找不到列印工作'], 404);
    $status = $result === 'confirmed' ? 'printed_confirmed' : 'failed';
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE invoice_print_logs SET result=?, reason=? WHERE id=?')->execute([$status, trim((string)($input['reason'] ?? '')), $logId]);
    if ($result === 'confirmed') {
        $pdo->prepare("UPDATE electronic_invoices SET print_status='printed_confirmed', first_printed_at=CASE WHEN first_printed_at='' THEN CURRENT_TIMESTAMP ELSE first_printed_at END, last_printed_at=CURRENT_TIMESTAMP, print_count=print_count+?, last_printed_by=?, last_printer_name=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([(int)$log['copies'], $context['user'], $log['printer_name'], (int)$log['invoice_id']]);
    } else {
        $pdo->prepare("UPDATE electronic_invoices SET print_status='failed', updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([(int)$log['invoice_id']]);
    }
    $pdo->commit();
    acc_audit($pdo, $context, 'invoice_print_' . $result, (int)$log['invoice_id'], ['print_log_id' => $logId, 'pdf_hash' => $log['pdf_hash']]);
    acc_json(['ok' => true, 'print_status' => $status]);
}

if ($action === 'partner_save') {
    $context = acc_require_capability('invoice_edit');
    $input = acc_request_json();
    $id = (int)($input['id'] ?? 0);
    $name = trim((string)($input['partner_name'] ?? ''));
    if ($name === '') acc_json(['ok' => false, 'error' => '請填往來客戶名稱'], 422);
    $companyName = trim((string)($input['company_name'] ?? ''));
    $taxId = preg_replace('/\D+/', '', (string)($input['tax_id'] ?? '')) ?? '';
    $inRate = (float)($input['inbound_tax_rate'] ?? 2);
    $vatRate = (float)($input['outbound_vat_rate'] ?? 5);
    $incomeRate = (float)($input['outbound_income_tax_rate'] ?? 3);
    $note = trim((string)($input['note'] ?? ''));
    $enabled = !empty($input['enabled']) ? 1 : 1;
    if ($id > 0) {
        $pdo->prepare('UPDATE invoice_partners SET partner_name=?, company_name=?, tax_id=?, inbound_tax_rate=?, outbound_vat_rate=?, outbound_income_tax_rate=?, note=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([$name, $companyName, $taxId, $inRate, $vatRate, $incomeRate, $note, $id]);
    } else {
        $pdo->prepare('INSERT INTO invoice_partners (partner_name, company_name, tax_id, inbound_tax_rate, outbound_vat_rate, outbound_income_tax_rate, note) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$name, $companyName, $taxId, $inRate, $vatRate, $incomeRate, $note]);
        $id = (int)$pdo->lastInsertId();
    }
    acc_audit($pdo, $context, 'invoice_partner_save', null, ['partner_id' => $id, 'partner_name' => $name]);
    $partner = acc_partner_get($pdo, $id);
    acc_json(['ok' => true, 'partner' => $partner, 'summary' => acc_partner_summary($pdo, $partner)]);
}

if ($action === 'partner_entry') {
    $context = acc_require_capability('invoice_edit');
    $input = acc_request_json();
    $partner = acc_partner_get($pdo, (int)($input['partner_id'] ?? 0));
    if (!$partner) acc_json(['ok' => false, 'error' => '找不到往來客戶'], 404);
    try {
        $saved = acc_partner_insert_entry($pdo, $context, $partner, $input);
    } catch (InvalidArgumentException $e) {
        acc_json(['ok' => false, 'error' => $e->getMessage()], 422);
    }
    acc_audit($pdo, $context, 'invoice_partner_entry', null, [
        'partner_id' => (int)$partner['id'],
        'entry_type' => $input['entry_type'] ?? '',
        'face_amount' => $input['face_amount'] ?? 0,
        'buyback_amount' => $saved['buyback_amount'] ?? null,
        'receipt_due' => $saved['receipt_due'] ?? null,
    ]);
    acc_json(['ok' => true] + $saved + ['summary' => acc_partner_summary($pdo, acc_partner_get($pdo, (int)$partner['id']))]);
}

if ($action === 'partner_settle') {
    $context = acc_require_capability('invoice_edit');
    $input = acc_request_json();
    $partner = acc_partner_get($pdo, (int)($input['partner_id'] ?? 0));
    if (!$partner) acc_json(['ok' => false, 'error' => '找不到往來客戶'], 404);
    try {
        $saved = acc_partner_settle($pdo, $context, $partner);
    } catch (InvalidArgumentException $e) {
        acc_json(['ok' => false, 'error' => $e->getMessage()], 422);
    }
    acc_audit($pdo, $context, 'invoice_partner_settle', null, ['partner_id' => (int)$partner['id'], 'settle_amount' => $saved['settle_amount'], 'buyback_amount' => $saved['buyback_amount'] ?? 0, 'tax_spread' => $saved['tax_spread']]);
    acc_json(['ok' => true] + $saved + ['summary' => acc_partner_summary($pdo, acc_partner_get($pdo, (int)$partner['id']))]);
}

acc_json(['ok' => false, 'error' => 'Unknown action'], 404);
