<?php
declare(strict_types=1);

const ACC_JIEYUAN_PO_LOGIC = 2;
const ACC_JIEYUAN_PO_MATCH_FROM_DEFAULT = '2026-08-18';
const ACC_JIEYUAN_TAX_ID = '23134543';

function acc_jieyuan_po_match_from(): string
{
    try {
        $from = trim((string)acc_setting(acc_db(), 'jieyuan_po_match_from', ACC_JIEYUAN_PO_MATCH_FROM_DEFAULT));
    } catch (Throwable $e) {
        $from = ACC_JIEYUAN_PO_MATCH_FROM_DEFAULT;
    }
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1 ? $from : ACC_JIEYUAN_PO_MATCH_FROM_DEFAULT;
}

function acc_is_jieyuan_invoice_row(array $row): bool
{
    $tax = preg_replace('/\D+/', '', (string)($row['seller_tax_id'] ?? '')) ?? '';
    $name = (string)($row['seller_name'] ?? '');
    return $tax === ACC_JIEYUAN_TAX_ID || str_contains($name, '捷元');
}

function acc_normalize_doc_date(string $date): string
{
    $date = trim(str_replace(['/', '.'], '-', $date));
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $date, $m) === 1) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    return '';
}

function acc_jieyuan_line_amount(array $mv): int
{
    foreach (['amount', 'line_subtotal', 'total_amount'] as $key) {
        if (!array_key_exists($key, $mv) || $mv[$key] === '' || $mv[$key] === null) continue;
        return (int)round((float)$mv[$key]);
    }
    $qty = (float)($mv['qty'] ?? 0);
    $unit = (float)($mv['unit_cost'] ?? $mv['base_cost_twd'] ?? 0);
    if ($qty > 0 && $unit > 0) return (int)round($qty * $unit);
    return 0;
}

function acc_is_inbound_stock_movement(array $mv): bool
{
    $kind = implode(' ', [
        (string)($mv['type'] ?? ''),
        (string)($mv['source_doc_type'] ?? ''),
        (string)($mv['movement_type'] ?? ''),
        (string)($mv['doc_type'] ?? ''),
    ]);
    if (str_contains($kind, '退') || str_contains($kind, '付款')) return false;
    return str_contains($kind, '進貨') || ($mv['movement_type'] ?? '') === 'purchase';
}

function acc_is_jieyuan_stock_movement(array $mv): bool
{
    $supplier = trim((string)($mv['supplier_name'] ?? $mv['party_name'] ?? ''));
    if (str_contains($supplier, '捷元')) return true;
    if ($supplier !== '') return false;
    return acc_is_inbound_stock_movement($mv);
}

function acc_jieyuan_amount_candidates(int $amount): array
{
    if ($amount <= 0) return [];
    return array_values(array_unique([
        $amount,
        (int)round($amount * 1.05),
        (int)round($amount / 1.05),
    ]));
}

function acc_jieyuan_amounts_match(int $invoiceNet, int $invoiceTotal, int $docAmount): bool
{
    if ($docAmount <= 0) return false;
    $cands = acc_jieyuan_amount_candidates($docAmount);
    foreach ([$invoiceNet, $invoiceTotal] as $inv) {
        if ($inv > 0 && in_array($inv, $cands, true)) return true;
    }
    return false;
}

function acc_group_jieyuan_purchase_docs(array $movements, string $from): array
{
    $from = acc_normalize_doc_date($from) ?: ACC_JIEYUAN_PO_MATCH_FROM_DEFAULT;
    $docs = [];
    foreach ($movements as $mv) {
        if (!is_array($mv)) continue;
        if (!acc_is_jieyuan_stock_movement($mv)) continue;
        if (!acc_is_inbound_stock_movement($mv)) continue;
        $date = acc_normalize_doc_date((string)($mv['document_date'] ?? $mv['date'] ?? ''));
        if ($date === '' || $date < $from) continue;
        $no = trim((string)($mv['document_no'] ?? $mv['source_doc_no'] ?? ''));
        if ($no === '') continue;
        $docs[$no] ??= ['document_no' => $no, 'document_date' => $date, 'total_amount' => 0, 'products' => []];
        if ($date > $docs[$no]['document_date']) $docs[$no]['document_date'] = $date;
        $docs[$no]['total_amount'] += acc_jieyuan_line_amount($mv);
        $title = trim((string)($mv['product_title'] ?? $mv['summary'] ?? ''));
        if ($title !== '' && !in_array($title, $docs[$no]['products'], true) && count($docs[$no]['products']) < 80) {
            $docs[$no]['products'][] = $title;
        }
    }
    return array_values($docs);
}

function acc_refresh_jieyuan_purchase_docs(PDO $pdo): array
{
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'stock_movements.json';
    if (!is_file($path)) return [];
    $mtime = (int)filemtime($path);
    $cachedMtime = (int)($pdo->query('SELECT MAX(source_mtime) FROM jieyuan_purchase_docs')->fetchColumn() ?: 0);
    $cachedLogic = (int)acc_setting($pdo, 'jieyuan_po_logic', '0');
    if ($cachedMtime === $mtime && $cachedLogic === ACC_JIEYUAN_PO_LOGIC && $cachedMtime > 0) {
        $rows = $pdo->query('SELECT document_no, document_date, total_amount, products_json FROM jieyuan_purchase_docs')->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'document_no' => (string)$row['document_no'],
                'document_date' => (string)$row['document_date'],
                'total_amount' => (int)$row['total_amount'],
                'products' => json_decode((string)$row['products_json'], true) ?: [],
            ];
        }
        return $out;
    }
    $raw = (string)file_get_contents($path);
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
    $movements = json_decode($raw, true);
    if (!is_array($movements)) return [];
    $docs = acc_group_jieyuan_purchase_docs($movements, acc_jieyuan_po_match_from());
    $pdo->exec('DELETE FROM jieyuan_purchase_docs');
    $insert = $pdo->prepare('INSERT INTO jieyuan_purchase_docs (document_no, document_date, total_amount, products_json, source_mtime) VALUES (?, ?, ?, ?, ?)');
    foreach ($docs as $doc) {
        $insert->execute([
            $doc['document_no'],
            $doc['document_date'],
            $doc['total_amount'],
            json_encode($doc['products'], JSON_UNESCAPED_UNICODE),
            $mtime,
        ]);
    }
    $pdo->prepare('INSERT INTO accounting_settings (setting_key, setting_value) VALUES (?, ?)
        ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value')
        ->execute(['jieyuan_po_logic', (string)ACC_JIEYUAN_PO_LOGIC]);
    return $docs;
}

function acc_jieyuan_reconcile(PDO $pdo): array
{
    $from = acc_jieyuan_po_match_from();
    $pdo->prepare("UPDATE electronic_invoices
        SET po_match_status='skip_before_cutoff', po_document_no='', po_match_note=?
        WHERE (seller_tax_id=? OR seller_name LIKE '%捷元%')
          AND invoice_date <> '' AND invoice_date < ?
          AND po_match_status <> 'matched'")
        ->execute(['2026-08-18前不比對', ACC_JIEYUAN_TAX_ID, $from]);

    $docs = acc_refresh_jieyuan_purchase_docs($pdo);
    $pdo->prepare("UPDATE electronic_invoices
        SET po_match_status='unmatched', po_document_no='', po_match_note=?
        WHERE (seller_tax_id=? OR seller_name LIKE '%捷元%')
          AND (invoice_date='' OR invoice_date>=?)
          AND workflow_status NOT IN ('duplicate')")
        ->execute(['2026-08-18起尚未對上進貨單', ACC_JIEYUAN_TAX_ID, $from]);

    $stmt = $pdo->prepare("SELECT id, invoice_number, invoice_date, net_amount, total_amount
        FROM electronic_invoices
        WHERE (seller_tax_id=? OR seller_name LIKE '%捷元%')
          AND (invoice_date='' OR invoice_date>=?)
          AND workflow_status NOT IN ('duplicate')");
    $stmt->execute([ACC_JIEYUAN_TAX_ID, $from]);
    $invoices = $stmt->fetchAll();

    $used = [];
    $matched = 0;
    $unmatched = 0;
    $update = $pdo->prepare("UPDATE electronic_invoices SET po_match_status=?, po_document_no=?, po_match_note=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
    foreach ($invoices as $inv) {
        $id = (int)$inv['id'];
        $net = (int)$inv['net_amount'];
        $total = (int)$inv['total_amount'];
        $date = acc_normalize_doc_date((string)$inv['invoice_date']);
        $best = null;
        $bestScore = 0;
        if ($net > 0 || $total > 0) {
            foreach ($docs as $doc) {
                $no = (string)$doc['document_no'];
                if (isset($used[$no])) continue;
                $docAmount = (int)$doc['total_amount'];
                if (!acc_jieyuan_amounts_match($net, $total, $docAmount)) continue;
                $docDate = (string)$doc['document_date'];
                $days = 0;
                if ($date !== '' && $docDate !== '') {
                    $days = (int)round(abs(strtotime($date) - strtotime($docDate)) / 86400);
                }
                if ($days > 31) continue;
                $exact = ($net > 0 && $net === $docAmount) || ($total > 0 && $total === $docAmount);
                $score = ($exact ? 2000 : 1000) - $days;
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $doc;
                }
            }
        }
        if ($best) {
            $used[(string)$best['document_no']] = true;
            $note = '進貨單 ' . $best['document_no'] . '／' . $best['document_date'] . '／' . $best['total_amount'] . ' 元';
            if ((int)$best['total_amount'] !== $total && (int)$best['total_amount'] !== $net) {
                $note .= '（含稅差 5%）';
            }
            $update->execute(['matched', $best['document_no'], $note, $id]);
            $matched++;
            continue;
        }
        $update->execute(['unmatched', '', '2026-08-18起尚未對上進貨單', $id]);
        $unmatched++;
    }

    $unmatchedDocs = [];
    foreach ($docs as $doc) {
        if (!isset($used[(string)$doc['document_no']])) {
            $unmatchedDocs[] = [
                'document_no' => (string)$doc['document_no'],
                'document_date' => (string)$doc['document_date'],
                'total_amount' => (int)$doc['total_amount'],
            ];
        }
    }

    return [
        'from' => $from,
        'purchase_docs' => count($docs),
        'matched' => $matched,
        'unmatched' => $unmatched,
        'unmatched_docs' => count($unmatchedDocs),
        'unmatched_doc_list' => array_slice($unmatchedDocs, 0, 20),
    ];
}
