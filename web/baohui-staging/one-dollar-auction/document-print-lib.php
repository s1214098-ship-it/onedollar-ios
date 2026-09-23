<?php
declare(strict_types=1);

function baohui_ops_print_h($value): string
{
    if (function_exists('h')) {
        return h($value);
    }
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function baohui_ops_print_money($value): string
{
    if (function_exists('money')) {
        return money($value);
    }
    return number_format((float)($value ?? 0)) . ' 元';
}

function baohui_ops_document_print_href(string $no, string $type = '', bool $autoprint = true): string
{
    $query = ['print_document' => $no];
    $type = trim($type);
    if ($type !== '') {
        $query['doc_type'] = $type;
    }
    if ($autoprint) {
        $query['autoprint'] = '1';
    }
    return 'operations.php?' . http_build_query($query);
}

function baohui_ops_print_link_html(string $no, string $type = '', string $label = '列印', string $class = 'button-like small'): string
{
    $no = trim($no);
    if ($no === '') {
        return '';
    }
    return '<a class="' . baohui_ops_print_h($class) . '" target="_blank" rel="noopener" href="'
        . baohui_ops_print_h(baohui_ops_document_print_href($no, $type))
        . '">' . baohui_ops_print_h($label) . '</a>';
}

function baohui_ops_print_has(string $haystack, string $needle): bool
{
    return $needle !== '' && str_contains($haystack, $needle);
}

function baohui_ops_print_no_match($candidates, string $needle): bool
{
    $needle = trim($needle);
    if ($needle === '') {
        return false;
    }
    foreach ((array)$candidates as $value) {
        if (strcasecmp(trim((string)$value), $needle) === 0) {
            return true;
        }
    }
    return false;
}

function baohui_ops_print_row_no(array $row): string
{
    foreach (['document_no', 'source_doc_no', 'doc_no', 'delivery_no', 'repair_no', 'return_no', 'receipt_no', 'request_no', 'workflow_no', 'formal_document_no', 'movement_no', 'count_no', 'transfer_no', 'payment_no', 'case_no', 'asset_no', 'mobile_asset_no', 'quote_no', 'id'] as $key) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function baohui_ops_print_company(array $company): array
{
    return [
        'name' => trim((string)($company['company_name'] ?? '寶輝科技有限公司')) ?: '寶輝科技有限公司',
        'phone' => trim((string)($company['phone'] ?? '039-773280')) ?: '039-773280',
        'fax' => trim((string)($company['fax'] ?? '039-773669')) ?: '039-773669',
        'contact' => trim((string)($company['contact_name'] ?? '郭先生、李先生、曾小姐')) ?: '郭先生、李先生、曾小姐',
        'address' => trim((string)($company['address'] ?? '')),
    ];
}

function baohui_ops_print_empty(string $title, string $no, array $meta = [], array $items = [], float $total = 0.0, string $note = ''): array
{
    return [
        'title' => $title,
        'subtitle' => $meta['subtitle'] ?? '正式單據紙本',
        'no' => $no,
        'date' => $meta['date'] ?? '',
        'status' => $meta['status'] ?? '',
        'left_title' => $meta['left_title'] ?? '對象',
        'left' => $meta['left'] ?? [],
        'right_title' => $meta['right_title'] ?? '單據資料',
        'right' => $meta['right'] ?? [],
        'columns' => $meta['columns'] ?? ['項目', '說明', '數量', '單價', '小計'],
        'items' => $items,
        'total_label' => $meta['total_label'] ?? '整單合計',
        'total' => $total,
        'extras' => $meta['extras'] ?? [],
        'note' => $note,
        'sign_left' => $meta['sign_left'] ?? '對方簽收',
        'sign_right' => $meta['sign_right'] ?? '寶輝科技經辦',
        'handler' => $meta['handler'] ?? '',
    ];
}

function baohui_ops_print_from_stock(array $rows, string $no, string $type): ?array
{
    $matched = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (!baohui_ops_print_no_match([
            $row['document_no'] ?? '',
            $row['source_doc_no'] ?? '',
            $row['doc_no'] ?? '',
            $row['id'] ?? '',
        ], $no)) {
            continue;
        }
        $rowType = trim((string)($row['source_doc_type'] ?? ($row['type'] ?? '')));
        if ($type !== '' && $rowType !== '' && function_exists('ops_document_type_label')) {
            $want = ops_document_type_label($type);
            $have = ops_document_type_label($rowType);
            if ($want !== $have && !baohui_ops_print_has($have, $want) && !baohui_ops_print_has($want, $have)) {
                continue;
            }
        }
        $matched[] = $row;
    }
    if (!$matched) {
        return null;
    }
    $first = $matched[0];
    $docType = function_exists('ops_document_type_label')
        ? ops_document_type_label((string)($first['source_doc_type'] ?? ($first['type'] ?? ($type !== '' ? $type : '進貨單據'))))
        : (string)($first['source_doc_type'] ?? ($type !== '' ? $type : '進貨單據'));
    $isReturn = baohui_ops_print_has($docType, '退');
    $title = $isReturn ? '進貨退回單' : '進貨單';
    $items = [];
    $total = 0.0;
    $qtyTotal = 0.0;
    foreach ($matched as $row) {
        $qty = (float)($row['qty'] ?? 0);
        $amount = isset($row['amount']) ? (float)$row['amount'] : (isset($row['total_amount']) ? (float)$row['total_amount'] : ($qty * (float)($row['unit_cost'] ?? 0)));
        $spec = trim(implode(' / ', array_filter([
            (string)($row['color'] ?? ''),
            (string)($row['size'] ?? ''),
            (string)($row['spec'] ?? ''),
        ], static function ($value) {
            return trim((string)$value) !== '';
        })));
        $items[] = [
            (string)($row['product_title'] ?? ($row['product_id'] ?? '')),
            trim((string)($row['barcode'] ?? '') . ($spec !== '' ? "\n" . $spec : '')),
            (string)$qty,
            baohui_ops_print_money($row['unit_cost'] ?? 0),
            baohui_ops_print_money($amount),
            trim(($row['warehouse_name'] ?? '') . ' ' . ($row['shelf_code'] ?? '') . ' ' . ($row['warehouse_location'] ?? '')),
        ];
        $total += $amount;
        $qtyTotal += $qty;
    }
    $date = substr((string)($first['document_date'] ?? ($first['date'] ?? ($first['created_at'] ?? ''))), 0, 10);
    return baohui_ops_print_empty($title, baohui_ops_print_row_no($first) ?: $no, [
        'subtitle' => $isReturn ? '退回廠商／扣回庫存聯' : '進貨入庫／成本確認聯',
        'date' => $date,
        'status' => (string)($first['payment_status'] ?? ($first['status'] ?? '入庫完成')),
        'left_title' => '廠商',
        'left' => [
            '名稱：' . (string)($first['supplier_name'] ?? ($first['party_name'] ?? '未填')),
            '發票／憑證：' . (string)($first['invoice_no'] ?? ''),
            '供應來源：' . (string)($first['purchase_source'] ?? ($first['source'] ?? '')),
        ],
        'right_title' => '單據',
        'right' => [
            '經手人：' . (string)($first['handler'] ?? ($first['operator'] ?? '')),
            '部門：' . (string)($first['department'] ?? ''),
            '台灣快遞：' . baohui_ops_print_money($first['taiwan_shipping'] ?? 0),
            '整批運費：' . baohui_ops_print_money($first['shipping_fee'] ?? 0),
        ],
        'columns' => ['產品', '條碼 / 規格', '數量', '單位成本', '小計', '倉位'],
        'total_label' => '進貨合計（' . rtrim(rtrim(sprintf('%.2f', $qtyTotal), '0'), '.') . ' 件）',
        'extras' => array_values(array_filter([
            (string)($first['cost_formula'] ?? ''),
        ])),
        'sign_left' => $isReturn ? '廠商簽收' : '驗收／倉管',
        'handler' => (string)($first['handler'] ?? ($first['operator'] ?? '')),
    ], $items, $total, (string)($first['document_note'] ?? ($first['note'] ?? '')));
}

function baohui_ops_print_kv_doc(string $title, string $no, array $lines, array $items = [], float $total = 0.0, array $meta = []): array
{
    $left = [];
    $right = [];
    foreach ($lines as $index => $line) {
        if ($index % 2 === 0) {
            $left[] = $line;
        } else {
            $right[] = $line;
        }
    }
    $meta = array_merge([
        'left' => $left,
        'right' => $right,
        'columns' => ['項目', '說明', '數量', '單價', '小計'],
    ], $meta);
    return baohui_ops_print_empty($title, $no, $meta, $items, $total, (string)($meta['note'] ?? ''));
}

function baohui_ops_print_find_row(array $rows, string $no, array $keys): ?array
{
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $candidates = [];
        foreach ($keys as $key) {
            $candidates[] = $row[$key] ?? '';
        }
        if (baohui_ops_print_no_match($candidates, $no)) {
            return $row;
        }
    }
    return null;
}

function baohui_ops_print_build(array $ctx, string $no, string $type = ''): ?array
{
    $no = trim($no);
    $type = trim($type);
    if ($no === '') {
        return null;
    }
    $fromStock = baohui_ops_print_from_stock($ctx['stockMovements'] ?? [], $no, $type);
    $preferStock = $type === '' || preg_match('/進貨|入庫|退貨|退回/u', $type);
    if ($fromStock && $preferStock) {
        return $fromStock;
    }

    $delivery = baohui_ops_print_find_row($ctx['deliveryNotes'] ?? [], $no, ['delivery_no', 'id']);
    if ($delivery && ($type === '' || preg_match('/銷售|出貨/u', $type))) {
        $buyer = is_array($delivery['buyer'] ?? null) ? $delivery['buyer'] : [];
        $items = [];
        foreach ((array)($delivery['items'] ?? []) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $items[] = [
                (string)($it['product_title'] ?? ($it['product_id'] ?? '')),
                (string)($it['product_barcode'] ?? ($it['barcode'] ?? '')),
                (string)($it['quantity'] ?? 0),
                baohui_ops_print_money($it['unit_price'] ?? 0),
                baohui_ops_print_money($it['line_subtotal'] ?? 0),
            ];
        }
        return baohui_ops_print_empty('出貨單', (string)($delivery['delivery_no'] ?? $no), [
            'subtitle' => '銷售出庫／客戶簽收聯',
            'date' => substr((string)($delivery['date'] ?? ($delivery['delivery_date'] ?? ($delivery['created_at'] ?? ''))), 0, 10),
            'status' => (string)($delivery['shipping_status'] ?? ($delivery['status'] ?? '')),
            'left_title' => '客戶',
            'left' => [
                '名稱：' . (string)($buyer['name'] ?? ($delivery['customer_name'] ?? ($delivery['member_name'] ?? ''))),
                '電話：' . (string)($buyer['phone'] ?? ''),
                '地址：' . (string)($buyer['address'] ?? ''),
            ],
            'right_title' => '物流 / 發票',
            'right' => [
                '物流：' . (string)($delivery['logistics_company'] ?? ''),
                '單號：' . (string)($delivery['tracking_no'] ?? ''),
                '發票：' . (string)($delivery['invoice_no'] ?? ''),
                '經手人：' . (string)($delivery['handler'] ?? ''),
            ],
            'columns' => ['產品', '條碼', '數量', '單價', '小計'],
            'sign_left' => '客戶簽收',
            'handler' => (string)($delivery['handler'] ?? ''),
        ], $items, (float)($delivery['total'] ?? ($delivery['total_amount'] ?? 0)), (string)($delivery['note'] ?? ''));
    }

    $repair = baohui_ops_print_find_row($ctx['repairDocuments'] ?? [], $no, ['repair_no', 'id']);
    if ($repair && ($type === '' || baohui_ops_print_has($type, '維修'))) {
        return baohui_ops_print_kv_doc('維修單', (string)($repair['repair_no'] ?? $no), [
            '維修商品：' . (string)($repair['item'] ?? ''),
            '聯絡人：' . (string)($repair['contact_name'] ?? ''),
            '電話：' . (string)($repair['phone'] ?? ''),
            '地址：' . (string)($repair['address'] ?? ''),
            '維修人：' . (string)($repair['technician'] ?? ''),
            '登記人：' . (string)($repair['registrar'] ?? ''),
            '處理進度：' . (string)($repair['repair_status'] ?? ''),
            '結算：' . (string)($repair['settlement_status'] ?? ''),
        ], [[
            (string)($repair['item'] ?? '維修'),
            trim((string)($repair['repair_condition'] ?? '') . "\n" . (string)($repair['exclusion_condition'] ?? '')),
            '1',
            baohui_ops_print_money($repair['estimated_fee'] ?? 0),
            baohui_ops_print_money($repair['estimated_fee'] ?? 0),
        ]], (float)($repair['estimated_fee'] ?? 0), [
            'date' => substr((string)($repair['created_at'] ?? ''), 0, 10),
            'status' => (string)($repair['repair_status'] ?? ''),
            'note' => (string)($repair['note'] ?? ''),
            'handler' => (string)($repair['technician'] ?? ($repair['registrar'] ?? '')),
            'sign_left' => '客戶簽收',
        ]);
    }

    $returnDoc = baohui_ops_print_find_row($ctx['returns'] ?? [], $no, ['return_no', 'id']);
    if ($returnDoc && ($type === '' || baohui_ops_print_has($type, '退'))) {
        return baohui_ops_print_kv_doc('銷貨退回單', (string)($returnDoc['return_no'] ?? $no), [
            '會員：' . (string)($returnDoc['member_name'] ?? ''),
            '電話：' . (string)($returnDoc['member_phone'] ?? ''),
            '處理方式：' . (string)($returnDoc['solution'] ?? ''),
            '狀態：' . (string)($returnDoc['status'] ?? ''),
        ], [[
            (string)($returnDoc['product_id'] ?? '退回商品'),
            (string)($returnDoc['reason'] ?? ''),
            (string)($returnDoc['return_qty'] ?? 1),
            baohui_ops_print_money($returnDoc['refund_amount'] ?? 0),
            baohui_ops_print_money($returnDoc['refund_amount'] ?? 0),
        ]], (float)($returnDoc['refund_amount'] ?? 0), [
            'date' => (string)($returnDoc['received_date'] ?? substr((string)($returnDoc['created_at'] ?? ''), 0, 10)),
            'status' => (string)($returnDoc['status'] ?? ''),
            'note' => (string)($returnDoc['condition_note'] ?? ($returnDoc['note'] ?? '')),
            'sign_left' => '客戶簽收',
        ]);
    }

    $count = baohui_ops_print_find_row($ctx['inventoryCounts'] ?? [], $no, ['doc_no', 'count_no', 'id']);
    if ($count && ($type === '' || baohui_ops_print_has($type, '盤點'))) {
        $items = [];
        foreach ((array)($count['lines'] ?? []) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $items[] = [
                (string)($line['title'] ?? ($line['product_id'] ?? '')),
                (string)($line['barcode'] ?? ($line['scan_code'] ?? '')),
                '實盤 ' . (string)($line['qty'] ?? 0),
                '系統 ' . (string)($line['system_qty'] ?? 0),
                '差異 ' . (string)($line['diff_qty'] ?? 0),
            ];
        }
        return baohui_ops_print_empty('盤點單', (string)($count['doc_no'] ?? $no), [
            'subtitle' => '盤點核對聯',
            'date' => (string)($count['date'] ?? ''),
            'status' => (string)($count['status'] ?? ''),
            'left_title' => '盤點倉庫',
            'left' => ['倉庫：' . (string)($count['warehouse_name'] ?? '')],
            'right_title' => '單據',
            'right' => ['建立人：' . (string)($count['operator'] ?? ''), '筆數：' . count($items)],
            'columns' => ['產品', '條碼', '實盤', '系統', '差異'],
            'sign_left' => '盤點人',
            'handler' => (string)($count['operator'] ?? ''),
        ], $items, 0, (string)($count['note'] ?? ''));
    }

    $transfer = baohui_ops_print_find_row($ctx['inventoryTransfers'] ?? [], $no, ['doc_no', 'transfer_no', 'id']);
    if ($transfer && ($type === '' || baohui_ops_print_has($type, '調撥'))) {
        $items = [];
        foreach ((array)($transfer['lines'] ?? []) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $items[] = [
                (string)($line['title'] ?? ($line['product_id'] ?? ($line['barcode'] ?? '調撥品項'))),
                (string)($line['barcode'] ?? ''),
                (string)($line['qty'] ?? 1),
                '',
                '',
            ];
        }
        if (!$items && trim((string)($transfer['transfer_lines'] ?? '')) !== '') {
            foreach (preg_split('/\R/u', (string)$transfer['transfer_lines']) as $raw) {
                $raw = trim($raw);
                if ($raw === '') {
                    continue;
                }
                $items[] = [$raw, '', '', '', ''];
            }
        }
        return baohui_ops_print_empty('調撥單', (string)($transfer['doc_no'] ?? $no), [
            'subtitle' => '倉庫調撥聯',
            'date' => (string)($transfer['date'] ?? ''),
            'status' => (string)($transfer['status'] ?? ''),
            'left_title' => '來源',
            'left' => ['來源：' . trim(($transfer['from_warehouse'] ?? '') . ' / ' . ($transfer['from_shelf'] ?? '') . ' / ' . ($transfer['from_location'] ?? ''), ' /')],
            'right_title' => '目的',
            'right' => ['目的：' . trim(($transfer['to_warehouse'] ?? '') . ' / ' . ($transfer['to_shelf'] ?? '') . ' / ' . ($transfer['to_location'] ?? ''), ' /')],
            'columns' => ['產品', '條碼', '數量', '', ''],
            'handler' => (string)($transfer['operator'] ?? ''),
            'sign_left' => '收貨倉',
        ], $items, 0, (string)($transfer['note'] ?? ''));
    }

    $receipt = baohui_ops_print_find_row($ctx['collectionReceipts'] ?? [], $no, ['receipt_no', 'id']);
    if ($receipt && ($type === '' || baohui_ops_print_has($type, '收款'))) {
        return baohui_ops_print_kv_doc('收款單', (string)($receipt['receipt_no'] ?? $no), [
            '客戶：' . (string)($receipt['customer_name'] ?? ''),
            '對應單號：' . (string)($receipt['document_no'] ?? ''),
            '收款方式：' . (string)($receipt['payment_method'] ?? ''),
            '帳戶：' . (string)($receipt['account_name'] ?? ''),
        ], [[
            '收款',
            (string)($receipt['note'] ?? ''),
            '1',
            baohui_ops_print_money($receipt['amount'] ?? 0),
            baohui_ops_print_money($receipt['amount'] ?? 0),
        ]], (float)($receipt['amount'] ?? 0), [
            'date' => (string)($receipt['receipt_date'] ?? ''),
            'status' => (string)($receipt['status'] ?? ''),
            'note' => (string)($receipt['note'] ?? ''),
            'handler' => (string)($receipt['handler'] ?? ''),
            'sign_left' => '客戶簽收',
        ]);
    }

    $billing = baohui_ops_print_find_row($ctx['billingRequests'] ?? [], $no, ['request_no', 'id']);
    if ($billing && ($type === '' || baohui_ops_print_has($type, '請款'))) {
        $items = [];
        $total = (float)($billing['total_amount'] ?? 0);
        foreach ((array)($billing['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $line = (float)($item['request_amount'] ?? 0);
            $items[] = [
                (string)($item['product_title'] ?? ($item['product_id'] ?? '')),
                (string)($item['document_no'] ?? ''),
                (string)($item['quantity'] ?? ''),
                baohui_ops_print_money($item['receivable'] ?? 0),
                baohui_ops_print_money($line),
            ];
        }
        return baohui_ops_print_empty('請款單', (string)($billing['request_no'] ?? $no), [
            'subtitle' => '客戶請款聯',
            'date' => substr((string)($billing['created_at'] ?? ''), 0, 10),
            'status' => (string)($billing['status'] ?? ''),
            'left_title' => '客戶',
            'left' => ['名稱：' . (string)($billing['customer_name'] ?? '')],
            'right_title' => '單據',
            'right' => ['狀態：' . (string)($billing['status'] ?? '')],
            'columns' => ['品項', '來源單號', '數量', '原應收', '請款'],
            'sign_left' => '客戶簽收',
        ], $items, $total, (string)($billing['note'] ?? ''));
    }

    $adjust = baohui_ops_print_find_row($ctx['inventoryAdjustments'] ?? [], $no, ['doc_no', 'id']);
    if ($adjust && ($type === '' || preg_match('/報損|報溢|調整|盤虧|盤盈/u', $type))) {
        $kind = (($adjust['type'] ?? '') === 'loss' || baohui_ops_print_has($type, '報損') || baohui_ops_print_has($type, '盤虧')) ? '報損單' : '報溢單';
        $cost = (float)($adjust['unit_cost'] ?? 0) * (float)($adjust['qty'] ?? 0);
        return baohui_ops_print_kv_doc($kind, (string)($adjust['doc_no'] ?? $no), [
            '產品：' . (string)($adjust['product_title'] ?? ($adjust['product_id'] ?? '')),
            '庫存：' . (string)($adjust['before_qty'] ?? '') . ' → ' . (string)($adjust['after_qty'] ?? ''),
            '原因：' . (string)($adjust['reason'] ?? ''),
            '建立人：' . (string)($adjust['operator'] ?? ''),
        ], [[
            (string)($adjust['product_title'] ?? ($adjust['product_id'] ?? $kind)),
            (string)($adjust['reason'] ?? ($adjust['note'] ?? '')),
            (string)($adjust['qty'] ?? ''),
            baohui_ops_print_money($adjust['unit_cost'] ?? 0),
            baohui_ops_print_money($cost),
        ]], $cost, [
            'date' => (string)($adjust['date'] ?? substr((string)($adjust['created_at'] ?? ''), 0, 10)),
            'status' => (string)($adjust['status'] ?? ''),
            'note' => (string)($adjust['note'] ?? ''),
            'handler' => (string)($adjust['operator'] ?? ''),
            'sign_left' => '倉管確認',
        ]);
    }

    $bad = baohui_ops_print_find_row($ctx['badDebts'] ?? [], $no, ['case_no', 'id']);
    if ($bad && ($type === '' || baohui_ops_print_has($type, '呆帳'))) {
        $items = [];
        foreach ((array)($bad['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $items[] = [
                (string)($item['product_title'] ?? ($item['document_no'] ?? '應收')),
                (string)($item['document_no'] ?? ''),
                (string)($item['quantity'] ?? ''),
                baohui_ops_print_money($item['receivable'] ?? 0),
                baohui_ops_print_money($item['bad_debt_amount'] ?? 0),
            ];
        }
        if (!$items) {
            $items[] = [
                '呆帳金額',
                (string)($bad['reason'] ?? ''),
                '1',
                baohui_ops_print_money($bad['amount'] ?? 0),
                baohui_ops_print_money($bad['remaining_amount'] ?? ($bad['amount'] ?? 0)),
            ];
        }
        return baohui_ops_print_empty('呆帳案件', (string)($bad['case_no'] ?? $no), [
            'subtitle' => '催收／沖銷聯',
            'date' => (string)($bad['recognition_date'] ?? substr((string)($bad['created_at'] ?? ''), 0, 10)),
            'status' => (string)($bad['status'] ?? ''),
            'left_title' => '客戶',
            'left' => [
                '名稱：' . (string)($bad['customer_name'] ?? ''),
                '電話：' . (string)($bad['customer_phone'] ?? ''),
                '原因：' . (string)($bad['reason'] ?? ''),
            ],
            'right_title' => '案件',
            'right' => [
                '原額：' . baohui_ops_print_money($bad['amount'] ?? 0),
                '已追回：' . baohui_ops_print_money($bad['recovered_amount'] ?? 0),
                '未追回：' . baohui_ops_print_money($bad['remaining_amount'] ?? 0),
                '負責人：' . (string)($bad['owner'] ?? ''),
            ],
            'columns' => ['品項', '來源單號', '數量', '原應收', '轉入呆帳'],
            'total_label' => '未追回',
            'sign_left' => '負責人簽核',
            'handler' => (string)($bad['owner'] ?? ($bad['operator'] ?? '')),
        ], $items, (float)($bad['remaining_amount'] ?? ($bad['amount'] ?? 0)), (string)($bad['note'] ?? ''));
    }

    $fixedPay = baohui_ops_print_find_row($ctx['fixedExpensePayments'] ?? [], $no, ['payment_no', 'id']);
    if ($fixedPay && ($type === '' || baohui_ops_print_has($type, '固定開支'))) {
        return baohui_ops_print_kv_doc('固定開支付款', (string)($fixedPay['payment_no'] ?? $no), [
            '項目：' . (string)($fixedPay['expense_name'] ?? ''),
            '科目：' . trim((string)($fixedPay['account_code'] ?? '') . ' ' . (string)($fixedPay['category_name'] ?? '')),
            '廠商：' . (string)($fixedPay['supplier_name'] ?? ''),
            '到期日：' . (string)($fixedPay['due_date'] ?? ''),
            '付款日：' . (string)($fixedPay['paid_date'] ?? ''),
            '方式：' . (string)($fixedPay['payment_method'] ?? ''),
            '帳戶：' . (string)($fixedPay['account_name'] ?? ''),
            '發票：' . (string)($fixedPay['invoice_no'] ?? ''),
        ], [[
            (string)($fixedPay['expense_name'] ?? '固定開支'),
            (string)($fixedPay['note'] ?? ''),
            '1',
            baohui_ops_print_money($fixedPay['amount'] ?? 0),
            baohui_ops_print_money($fixedPay['amount'] ?? 0),
        ]], (float)($fixedPay['amount'] ?? 0), [
            'date' => (string)($fixedPay['paid_date'] ?? ($fixedPay['due_date'] ?? '')),
            'status' => (string)($fixedPay['status'] ?? ''),
            'note' => (string)($fixedPay['note'] ?? ''),
            'handler' => (string)($fixedPay['handler'] ?? ''),
            'sign_left' => '廠商簽收',
        ]);
    }

    $asset = baohui_ops_print_find_row($ctx['fixedAssets'] ?? [], $no, ['asset_no', 'id']);
    if ($asset && ($type === '' || baohui_ops_print_has($type, '固定資產'))) {
        return baohui_ops_print_kv_doc('固定資產卡', (string)($asset['asset_no'] ?? $no), [
            '名稱：' . (string)($asset['asset_name'] ?? ''),
            '類別：' . (string)($asset['asset_category'] ?? ''),
            '型號：' . (string)($asset['brand_model'] ?? ''),
            '序號：' . (string)($asset['serial_no'] ?? ''),
            '來源單據：' . (string)($asset['source_no'] ?? '手動建檔'),
            '廠商：' . (string)($asset['supplier_name'] ?? ''),
            '位置：' . (string)($asset['location'] ?? ''),
            '保管人：' . (string)($asset['custodian'] ?? ''),
        ], [[
            (string)($asset['asset_name'] ?? '固定資產'),
            trim((string)($asset['brand_model'] ?? '') . ' / ' . (string)($asset['serial_no'] ?? ''), ' /'),
            (string)($asset['quantity'] ?? 1),
            baohui_ops_print_money($asset['acquisition_cost'] ?? 0),
            baohui_ops_print_money($asset['acquisition_cost'] ?? 0),
        ]], (float)($asset['acquisition_cost'] ?? 0), [
            'date' => (string)($asset['acquisition_date'] ?? ''),
            'status' => (string)($asset['status'] ?? ''),
            'note' => (string)($asset['note'] ?? ''),
            'handler' => (string)($asset['custodian'] ?? ''),
            'sign_left' => '保管人簽收',
        ]);
    }

    $mobileMove = baohui_ops_print_find_row($ctx['mobileAssetMovements'] ?? [], $no, ['movement_no', 'id']);
    if ($mobileMove && ($type === '' || preg_match('/移動資產|領用|借出|歸還/u', $type))) {
        return baohui_ops_print_kv_doc('移動資產異動', (string)($mobileMove['movement_no'] ?? $no), [
            '類型：' . (string)($mobileMove['movement_type'] ?? ''),
            '資產：' . trim((string)($mobileMove['mobile_asset_no'] ?? '') . ' ' . (string)($mobileMove['asset_name'] ?? '')),
            '固定資產：' . (string)($mobileMove['fixed_asset_no'] ?? ''),
            '原位置：' . (string)($mobileMove['from_location'] ?? ''),
            '原保管人：' . (string)($mobileMove['from_holder'] ?? ''),
            '新位置：' . (string)($mobileMove['to_location'] ?? ''),
            '新保管人：' . (string)($mobileMove['to_holder'] ?? ''),
            '預計歸還：' . (string)($mobileMove['expected_return_date'] ?? ''),
        ], [[
            (string)($mobileMove['asset_name'] ?? ($mobileMove['mobile_asset_no'] ?? '移動資產')),
            (string)($mobileMove['note'] ?? ''),
            '1',
            '',
            '',
        ]], 0, [
            'date' => (string)($mobileMove['movement_date'] ?? ''),
            'status' => (string)($mobileMove['status_after'] ?? ($mobileMove['movement_type'] ?? '')),
            'note' => (string)($mobileMove['note'] ?? ''),
            'handler' => (string)($mobileMove['operator'] ?? ''),
            'sign_left' => '接收人簽收',
        ]);
    }

    $payRec = baohui_ops_print_find_row($ctx['paymentRecords'] ?? [], $no, ['payment_no', 'id']);
    if ($payRec && ($type === '' || preg_match('/付款紀錄|付款單/u', $type))) {
        $items = [];
        foreach ((array)($payRec['allocations'] ?? []) as $alloc) {
            if (!is_array($alloc)) {
                continue;
            }
            $items[] = [
                (string)($alloc['product_id'] ?? ($alloc['schedule_id'] ?? '沖帳')),
                (string)($alloc['schedule_id'] ?? ''),
                '1',
                baohui_ops_print_money($alloc['amount'] ?? 0),
                baohui_ops_print_money($alloc['amount'] ?? 0),
            ];
        }
        if (!$items) {
            $items[] = [
                '付款沖帳',
                (string)($payRec['note'] ?? ''),
                '1',
                baohui_ops_print_money($payRec['amount'] ?? 0),
                baohui_ops_print_money($payRec['applied_amount'] ?? ($payRec['amount'] ?? 0)),
            ];
        }
        return baohui_ops_print_empty('付款紀錄', (string)($payRec['payment_no'] ?? ($payRec['id'] ?? $no)), [
            'subtitle' => '收款沖帳聯',
            'date' => substr((string)($payRec['paid_at'] ?? ($payRec['payment_date'] ?? ($payRec['created_at'] ?? ''))), 0, 10),
            'status' => (string)($payRec['status'] ?? '已付款'),
            'left_title' => '客戶',
            'left' => [
                '名稱：' . (string)($payRec['customer_name'] ?? ($payRec['member_name'] ?? '')),
                '方式：' . (string)($payRec['method'] ?? ''),
            ],
            'right_title' => '沖帳',
            'right' => [
                '匯入金額：' . baohui_ops_print_money($payRec['amount'] ?? 0),
                '已沖：' . baohui_ops_print_money($payRec['applied_amount'] ?? 0),
                '未分配：' . baohui_ops_print_money($payRec['remaining_amount'] ?? 0),
            ],
            'columns' => ['項目', '來源', '數量', '金額', '小計'],
            'sign_left' => '客戶簽收',
        ], $items, (float)($payRec['amount'] ?? 0), (string)($payRec['note'] ?? ''));
    }

    if ($fromStock) {
        return $fromStock;
    }

    $workflow = baohui_ops_print_find_row($ctx['documentWorkflows'] ?? [], $no, ['formal_document_no', 'workflow_no', 'offset_document_no', 'id']);
    if ($workflow) {
        return baohui_ops_print_kv_doc((string)($workflow['document_type'] ?? '流程單據'), baohui_ops_print_row_no($workflow) ?: $no, [
            '對象：' . (string)($workflow['target'] ?? ''),
            '狀態：' . (string)($workflow['status'] ?? ''),
            '摘要：' . (string)($workflow['summary'] ?? ''),
        ], [[
            (string)($workflow['summary'] ?? '流程單據'),
            (string)($workflow['lines'] ?? ($workflow['workflow_lines'] ?? '')),
            '1',
            baohui_ops_print_money($workflow['amount'] ?? 0),
            baohui_ops_print_money($workflow['amount'] ?? 0),
        ]], (float)($workflow['amount'] ?? 0), [
            'date' => (string)($workflow['workflow_date'] ?? substr((string)($workflow['created_at'] ?? ''), 0, 10)),
            'status' => (string)($workflow['status'] ?? ''),
            'note' => (string)($workflow['summary'] ?? ''),
        ]);
    }

    return baohui_ops_print_generic_fallback($ctx, $no, $type);
}

function baohui_ops_print_generic_from_row(array $row, string $no, string $title): array
{
    $skip = ['id', 'password', 'token', 'items', 'lines', 'followups', 'allocations', 'json_data'];
    $lines = [];
    foreach ($row as $key => $value) {
        if (in_array((string)$key, $skip, true) || is_array($value)) {
            continue;
        }
        $text = trim((string)$value);
        if ($text === '') {
            continue;
        }
        $lines[] = (string)$key . '：' . $text;
        if (count($lines) >= 10) {
            break;
        }
    }
    $total = 0.0;
    foreach (['total_amount', 'amount', 'remaining_amount', 'acquisition_cost', 'refund_amount'] as $field) {
        if (isset($row[$field]) && is_numeric($row[$field])) {
            $total = (float)$row[$field];
            break;
        }
    }
    $items = [];
    if ($total !== 0.0) {
        $items[] = [
            (string)($row['summary'] ?? ($row['asset_name'] ?? ($row['product_title'] ?? $title))),
            (string)($row['note'] ?? ''),
            '1',
            baohui_ops_print_money($total),
            baohui_ops_print_money($total),
        ];
    }
    return baohui_ops_print_kv_doc($title !== '' ? $title : '單據', baohui_ops_print_row_no($row) ?: $no, $lines ?: ['單號：' . $no], $items, $total, [
        'date' => substr((string)($row['date'] ?? ($row['paid_at'] ?? ($row['created_at'] ?? ''))), 0, 10),
        'status' => (string)($row['status'] ?? ($row['status_after'] ?? '')),
        'note' => (string)($row['note'] ?? ($row['summary'] ?? '')),
        'handler' => (string)($row['operator'] ?? ($row['handler'] ?? ($row['custodian'] ?? ''))),
    ]);
}

function baohui_ops_print_generic_fallback(array $ctx, string $no, string $type = ''): ?array
{
    $titles = [
        'badDebts' => '呆帳案件',
        'fixedExpensePayments' => '固定開支付款',
        'fixedAssets' => '固定資產卡',
        'mobileAssetMovements' => '移動資產異動',
        'paymentRecords' => '付款紀錄',
        'repairDocuments' => '維修單',
        'returns' => '銷貨退回單',
        'inventoryCounts' => '盤點單',
        'inventoryTransfers' => '調撥單',
        'collectionReceipts' => '收款單',
        'billingRequests' => '請款單',
        'inventoryAdjustments' => '庫存調整單',
        'documentWorkflows' => '流程單據',
        'deliveryNotes' => '出貨單',
        'stockMovements' => '進貨單',
    ];
    foreach ($ctx as $ctxKey => $rows) {
        if ($ctxKey === 'company' || !is_array($rows)) {
            continue;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!baohui_ops_print_no_match([baohui_ops_print_row_no($row), $row['id'] ?? ''], $no)) {
                continue;
            }
            $title = $type !== '' ? $type : (string)($titles[$ctxKey] ?? '單據');
            return baohui_ops_print_generic_from_row($row, $no, $title);
        }
    }
    return null;
}

function baohui_ops_print_sheet_css(): string
{
    return <<<'CSS'
body{margin:0;background:#eef2f6;color:#122033;font-family:"Noto Sans TC","Microsoft JhengHei",Arial,sans-serif;font-size:12px}
.actions{max-width:210mm;margin:12px auto 0;display:flex;gap:10px;align-items:center;justify-content:flex-end}
.hint{color:#64748b;font-size:12px}
.btn{background:#0f766e;color:#fff;border:0;border-radius:6px;padding:7px 12px;font-size:13px;cursor:pointer;text-decoration:none;display:inline-block}
.btn.secondary{background:#2563eb}
.sheet{width:190mm;max-width:calc(100% - 24px);min-height:128mm;max-height:138mm;margin:10px auto 24px;background:#fff;border:1px solid #cbd5e1;border-radius:4px;padding:8mm 8mm 6mm;box-sizing:border-box;overflow:hidden}
.top{display:flex;justify-content:space-between;gap:10px;border-bottom:2px solid #0f766e;padding-bottom:6px;margin-bottom:8px}
.brand h1{margin:0;font-size:16px;letter-spacing:.02em}.brand p{margin:2px 0 0;color:#64748b;font-size:11px}
.meta{text-align:right;color:#334155;font-size:11px;line-height:1.45}.meta p{margin:0}
.info{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin:0 0 6px}
.box{border:1px solid #d8e0ea;background:#f8fafc;border-radius:4px;padding:6px 8px;line-height:1.45;font-size:11px}
.box b{display:block;margin-bottom:2px}
table{width:100%;border-collapse:collapse;margin-top:4px;font-size:11px}
th,td{border:1px solid #d8e0ea;padding:3px 5px;text-align:left;vertical-align:top}th{background:#e7f8f3;font-weight:700}
.right{text-align:right}.muted{color:#64748b}.total{margin:6px 0 0;font-size:12px}
.note{margin:4px 0 0;font-size:11px}
.sign{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:8px;border-top:1px solid #d8e0ea;padding-top:6px;font-size:11px}
.sign p{margin:2px 0 0}
.sign-line{height:22px;border-bottom:1px solid #475569}
.company{margin-top:6px;padding-top:4px;border-top:1px solid #d8e0ea;color:#475569;font-size:10px}
@media print{
  @page{size:A4 portrait;margin:8mm 8mm 148mm 8mm}
  html,body{background:#fff;height:auto}
  .actions{display:none}
  .sheet{width:auto;max-width:none;min-height:0;max-height:138mm;margin:0;border:0;border-radius:0;padding:0;box-shadow:none}
}
CSS;
}

function baohui_ops_print_html(array $doc, bool $autoPrint = false): string
{
    $company = $doc['company'] ?? baohui_ops_print_company([]);
    $itemsHtml = '';
    $colCount = max(1, count($doc['columns'] ?? ['項目']));
    foreach ((array)($doc['items'] ?? []) as $item) {
        $cells = is_array($item) ? $item : [$item];
        while (count($cells) < $colCount) {
            $cells[] = '';
        }
        $itemsHtml .= '<tr>';
        foreach (array_slice($cells, 0, $colCount) as $index => $cell) {
            $class = $index >= $colCount - 3 ? ' class="right"' : '';
            $itemsHtml .= '<td' . $class . '>' . nl2br(baohui_ops_print_h($cell)) . '</td>';
        }
        $itemsHtml .= '</tr>';
    }
    if ($itemsHtml === '') {
        $itemsHtml = '<tr><td colspan="' . $colCount . '" class="muted">沒有明細。</td></tr>';
    }
    $headHtml = '';
    foreach ((array)($doc['columns'] ?? []) as $col) {
        $headHtml .= '<th>' . baohui_ops_print_h($col) . '</th>';
    }
    $leftHtml = '';
    foreach ((array)($doc['left'] ?? []) as $line) {
        $leftHtml .= baohui_ops_print_h($line) . '<br>';
    }
    $rightHtml = '';
    foreach ((array)($doc['right'] ?? []) as $line) {
        $rightHtml .= baohui_ops_print_h($line) . '<br>';
    }
    $extras = '';
    foreach ((array)($doc['extras'] ?? []) as $line) {
        if (trim((string)$line) !== '') {
            $extras .= '<p>' . baohui_ops_print_h($line) . '</p>';
        }
    }
    $note = trim((string)($doc['note'] ?? ''));
    $address = trim((string)($company['address'] ?? ''));
    $auto = $autoPrint
        ? '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},400);});</script>'
        : '';
    $totalBlock = ((float)($doc['total'] ?? 0) !== 0.0)
        ? '<p class="right total"><b>' . baohui_ops_print_h($doc['total_label'] ?? '整單合計') . ' ' . baohui_ops_print_h(baohui_ops_print_money($doc['total'])) . '</b></p>'
        : '';
    return '<!doctype html><html lang="zh-Hant"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
        . baohui_ops_print_h(($doc['title'] ?? '單據') . ' ' . ($doc['no'] ?? ''))
        . '</title><style>' . baohui_ops_print_sheet_css() . '</style>' . $auto . '</head><body>
<div class="actions"><button class="btn" type="button" onclick="window.print()">列印半張 ' . baohui_ops_print_h($doc['title'] ?? '單據') . '</button><span class="hint">半張 A4，印在紙張上半部</span></div>
<main class="sheet">
  <section class="top">
    <div class="brand"><h1>' . baohui_ops_print_h(($company['name'] ?? '寶輝科技有限公司') . ' ' . ($doc['title'] ?? '單據')) . '</h1><p>' . baohui_ops_print_h($doc['subtitle'] ?? '正式單據紙本') . '</p></div>
    <div class="meta">
      <p>單號：<b>' . baohui_ops_print_h($doc['no'] ?? '') . '</b></p>
      <p>單據日期：' . baohui_ops_print_h($doc['date'] ?? '') . '</p>
      <p>狀態：' . baohui_ops_print_h($doc['status'] ?? '') . '</p>
    </div>
  </section>
  <section class="info">
    <div class="box"><b>' . baohui_ops_print_h($doc['left_title'] ?? '對象') . '</b><br>' . $leftHtml . '</div>
    <div class="box"><b>' . baohui_ops_print_h($doc['right_title'] ?? '單據資料') . '</b><br>' . $rightHtml . '</div>
  </section>
  <table><thead><tr>' . $headHtml . '</tr></thead><tbody>' . $itemsHtml . '</tbody></table>
  ' . $totalBlock . $extras . ($note !== '' ? '<p class="note">備註：' . nl2br(baohui_ops_print_h($note)) . '</p>' : '') . '
  <section class="sign">
    <div><b>' . baohui_ops_print_h($doc['sign_left'] ?? '對方簽收') . '</b><div class="sign-line"></div><p>單位 / 姓名：</p><p>日期：</p></div>
    <div><b>' . baohui_ops_print_h($doc['sign_right'] ?? '寶輝科技經辦') . '</b><div class="sign-line"></div><p>經辦：' . baohui_ops_print_h($doc['handler'] ?? '') . '</p><p>日期：</p></div>
  </section>
  <div class="company">' . baohui_ops_print_h($company['name'] ?? '') . '　電話：' . baohui_ops_print_h($company['phone'] ?? '') . '　傳真：' . baohui_ops_print_h($company['fax'] ?? '') . '　聯絡人：' . baohui_ops_print_h($company['contact'] ?? '') . ($address !== '' ? '　地址：' . baohui_ops_print_h($address) : '') . '</div>
</main></body></html>';
}

function baohui_ops_render_document_print(array $ctx, string $no, string $type = '', bool $autoPrint = false): void
{
    $type = trim($type);
    $no = trim($no);
    if ($no !== '' && $type !== '' && preg_match('/銷售出|出貨/u', $type) && function_exists('find_delivery_note')) {
        $note = find_delivery_note($ctx['deliveryNotes'] ?? [], $no);
        if (is_array($note)) {
            $id = (string)($note['id'] ?? $no);
            header('Location: operations.php?print_delivery=' . rawurlencode($id) . '&autoprint=1');
            return;
        }
    }
    $doc = baohui_ops_print_build($ctx, $no, $type);
    if (!$doc) {
        http_response_code(404);
        echo '<!doctype html><meta charset="utf-8"><title>找不到單據</title><body style="font-family:Arial,sans-serif;padding:32px"><h2>找不到這張單據</h2><p>單號：' . baohui_ops_print_h($no) . '</p></body>';
        return;
    }
    $doc['company'] = baohui_ops_print_company($ctx['company'] ?? []);
    echo baohui_ops_print_html($doc, $autoPrint);
}
