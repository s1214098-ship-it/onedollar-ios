<?php
declare(strict_types=1);

if (!function_exists('mb_strpos')) {
    function mb_strpos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null): int|false
    {
        return strpos($haystack, $needle, $offset);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int
    {
        return strlen($string);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string
    {
        return $length === null ? substr($string, $start) : substr($string, $start, $length);
    }
}

function bh_task_audit_now(?int $now = null): int
{
    return $now ?? time();
}

function bh_task_parse_ts(string $value): ?int
{
    $raw = trim(str_replace('T', ' ', $value));
    if ($raw === '') return null;
    $raw = preg_replace('/\.\d+$/', '', $raw) ?? $raw;
    $raw = preg_replace('/\//', '-', $raw) ?? $raw;
    $ts = strtotime($raw);
    return $ts === false ? null : $ts;
}

function bh_task_format_ts(int $ts): string
{
    return date('Y-m-d H:i', $ts);
}

function bh_task_clamp_rate($value): int
{
    $n = (int)round((float)$value);
    if ($n < 0) return 0;
    if ($n > 100) return 100;
    return $n;
}

function bh_task_hay(array $parts): string
{
    return trim(implode(' ', array_map(static fn($v) => trim((string)$v), $parts)));
}

function bh_task_has(string $hay, array $needles): bool
{
    foreach ($needles as $needle) {
        if ($needle !== '' && mb_strpos($hay, $needle) !== false) return true;
    }
    return false;
}

function bh_task_extract_claims(string $text): array
{
    $hay = preg_replace('/\s+/', '', $text) ?? $text;
    $invoiceAll = bh_task_has($hay, ['發票已全部印', '發票已全印', '電子發票已全部印', '電子發票已全印', '發票都印', '發票已印完', '全部印出', '全數印出', '已全部列印', '已全部印出']);
    $invoiceRemain = bh_task_has($hay, ['發票還沒', '發票尚未', '發票未印', '還沒印發票', '未列印', '還沒列印']);
    $mainlandRemain = bh_task_has($hay, ['大陸還沒', '大陸尚未', '大陸產品還沒', '大陸品還沒', '只剩大陸', '剩下大陸', '大陸還在建', '大陸還沒建']);
    $mainlandDone = bh_task_has($hay, ['大陸已建', '大陸都建', '大陸已完成', '大陸產品已']);
    $onlyMainlandLeft = bh_task_has($hay, ['只剩大陸', '剩下大陸', '僅剩大陸']);
    $inventoryDone = bh_task_has($hay, ['庫存已建完', '庫存建檔完成', '庫存已全部', '建檔完成']);
    $vague = mb_strlen($text) < 8;
    return [
        'invoice_all_printed' => $invoiceAll && !$invoiceRemain,
        'invoice_still_unprinted' => $invoiceRemain && !$invoiceAll,
        'mainland_inventory_remaining' => $mainlandRemain && !$mainlandDone,
        'mainland_inventory_done' => $mainlandDone && !$mainlandRemain,
        'only_mainland_left' => $onlyMainlandLeft,
        'inventory_all_done' => $inventoryDone,
        'vague' => $vague,
        'has_where' => !$vague,
    ];
}

function bh_task_is_mainland_product(array $row): array
{
    $src = trim((string)($row['purchase_source'] ?? $row['stock_source'] ?? ''));
    $type = trim((string)($row['category_type'] ?? ''));
    $hay = bh_task_hay([
        $src,
        $type,
        $row['title'] ?? '',
        $row['product_name'] ?? '',
        $row['category_group'] ?? '',
        $row['main_category'] ?? '',
        $row['warehouse_name'] ?? '',
        $row['description'] ?? '',
        $row['import_source'] ?? '',
    ]);
    $recycle = $type === '回收(大陸)' || mb_strpos($hay, '回收(大陸)') !== false;
    $source = in_array($src, ['拼多多', '豪鴻', '淘寶', '拼多多/淘寶', '拚多多 淘寶', '拚多多/淘寶'], true)
        || bh_task_has($hay, ['拼多多', '淘寶', '豪鴻', '東莞']);
    return [
        'recycle' => $recycle,
        'source' => $source && !$recycle,
        'any' => $recycle || $source,
    ];
}

function bh_task_products_path(): string
{
    $configured = trim((string)getenv('BAOHUI_PRODUCTS_JSON'));
    if ($configured !== '') return $configured;
    return __DIR__ . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'products.json';
}

function bh_task_movements_path(): string
{
    $configured = trim((string)getenv('BAOHUI_STOCK_MOVEMENTS_JSON'));
    if ($configured !== '') return $configured;
    return __DIR__ . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'stock_movements.json';
}

function bh_task_collect_invoice_evidence(): array
{
    $empty = ['total' => 0, 'printed' => 0, 'unprinted' => 0, 'available' => false, 'leftover' => []];
    try {
        if (!function_exists('acc_db')) {
            $lib = __DIR__ . DIRECTORY_SEPARATOR . 'accounting-lib.php';
            if (!is_file($lib)) return $empty;
            require_once $lib;
        }
        $pdo = acc_db();
        $total = (int)$pdo->query('SELECT COUNT(*) FROM electronic_invoices')->fetchColumn();
        $printed = (int)$pdo->query("SELECT COUNT(*) FROM electronic_invoices WHERE print_status = 'printed_confirmed'")->fetchColumn();
        $unprinted = (int)$pdo->query("SELECT COUNT(*) FROM electronic_invoices WHERE print_status = 'not_printed'")->fetchColumn();
        $leftover = [];
        try {
            $stmt = $pdo->query("SELECT invoice_number, seller_name, invoice_date FROM electronic_invoices WHERE print_status = 'not_printed' AND IFNULL(workflow_status,'') <> 'duplicate' ORDER BY invoice_date DESC, id DESC LIMIT 12");
            foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
                $no = trim((string)($row['invoice_number'] ?? ''));
                $seller = trim((string)($row['seller_name'] ?? ''));
                $date = trim((string)($row['invoice_date'] ?? ''));
                $label = trim($no . ($seller !== '' ? '／' . $seller : '') . ($date !== '' ? '（' . $date . '）' : ''), '／');
                if ($label === '') continue;
                $leftover[] = [
                    'kind' => '未印電子發票',
                    'label' => $label,
                ];
            }
        } catch (Throwable $e) {
            $leftover = [];
        }
        return [
            'total' => $total,
            'printed' => $printed,
            'unprinted' => $unprinted,
            'available' => $total > 0,
            'leftover' => $leftover,
        ];
    } catch (Throwable $e) {
        return $empty;
    }
}

function bh_task_collect_product_evidence(): array
{
    $out = [
        'total' => 0,
        'stocked' => 0,
        'zero_stock' => 0,
        'mainland_source' => 0,
        'mainland_source_stocked' => 0,
        'mainland_recycle' => 0,
        'mainland_recycle_stocked' => 0,
        'mainland_inbound_docs' => 0,
        'available' => false,
        'with_available_qty' => 0,
        'zero_available' => 0,
        'leftover' => [],
        'mainland_leftover' => [],
    ];
    $path = bh_task_products_path();
    if (!is_file($path)) return $out;
    $rows = json_decode((string)file_get_contents($path), true);
    if (!is_array($rows)) return $out;
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $out['total']++;
        $stock = (int)($row['stock_total'] ?? $row['stock'] ?? 0);
        if ($stock > 0) $out['stocked']++;
        else $out['zero_stock']++;
        $kind = bh_task_is_mainland_product($row);
        if ($kind['source']) {
            $out['mainland_source']++;
            if ($stock > 0) $out['mainland_source_stocked']++;
        }
        if ($kind['recycle']) {
            $out['mainland_recycle']++;
            if ($stock > 0) $out['mainland_recycle_stocked']++;
        }
        $availableQty = function_exists('ops_product_available_qty')
            ? ops_product_available_qty($row)
            : max(0, $stock - (int)($row['stock_sold'] ?? 0) - max(0, (int)($row['stock_reserved'] ?? 0)) - max(0, (int)($row['cloud_auction_reserved'] ?? 0)));
        if ($availableQty > 0) {
            $out['with_available_qty']++;
        } else {
            $out['zero_available']++;
        }
        if ($availableQty <= 0) {
            $title = trim((string)($row['title'] ?? $row['product_name'] ?? $row['id'] ?? ''));
            $kindLabel = $kind['source'] ? '大陸來源未入庫' : ($kind['recycle'] ? '回收(大陸)未入庫' : '商品無可用庫存');
            if ($title !== '') {
                $item = [
                    'kind' => $kindLabel,
                    'label' => $title,
                ];
                if (count($out['leftover']) < 12) {
                    $out['leftover'][] = $item;
                }
                if ($kind['source'] && count($out['mainland_leftover']) < 12) {
                    $out['mainland_leftover'][] = $item;
                }
            }
        }
    }
    $out['leftover'] = array_slice($out['leftover'], 0, 12);
    $out['mainland_leftover'] = array_slice($out['mainland_leftover'], 0, 12);
    $out['available'] = $out['total'] > 0;

    $movPath = bh_task_movements_path();
    if (is_file($movPath)) {
        $movs = json_decode((string)file_get_contents($movPath), true);
        if (is_array($movs)) {
            foreach ($movs as $mov) {
                if (!is_array($mov)) continue;
                $supplier = trim((string)($mov['supplier_name'] ?? $mov['party_name'] ?? ''));
                $type = (string)($mov['type'] ?? '') . (string)($mov['movement_type'] ?? '');
                if (mb_strpos($supplier, '運費') !== false) continue;
                $isMainlandSupplier = bh_task_has($supplier, ['拼多多', '淘寶', '豪鴻']);
                $isInbound = bh_task_has($type, ['進貨入庫', '入庫']) || (($mov['movement_type'] ?? '') === 'in');
                if ($isMainlandSupplier && $isInbound) $out['mainland_inbound_docs']++;
            }
        }
    }
    return $out;
}

function bh_task_collect_evidence(?int $now = null): array
{
    $invoices = bh_task_collect_invoice_evidence();
    $products = bh_task_collect_product_evidence();
    return [
        'invoices' => $invoices,
        'products' => $products,
        'board' => bh_task_progress_board($invoices, $products),
        'now' => bh_task_audit_now($now),
    ];
}

function bh_task_check(string $id, string $label, string $status, string $detail): array
{
    return compact('id', 'label', 'status', 'detail');
}

function bh_task_green_rate(int $done, int $total): int
{
    if ($total <= 0) {
        return $done > 0 ? 100 : 0;
    }
    return (int)round(100 * max(0, $done) / $total);
}

function bh_task_leftover_labels(array $items): array
{
    $out = [];
    foreach ($items as $item) {
        $label = trim((string)($item['label'] ?? $item['number'] ?? $item['title'] ?? ''));
        if ($label !== '') {
            $out[] = $label;
        }
    }
    return $out;
}

function bh_task_progress_board(?array $invoices = null, ?array $products = null): array
{
    $invoices = $invoices ?? bh_task_collect_invoice_evidence();
    $products = $products ?? bh_task_collect_product_evidence();
    $invDone = (int)($invoices['printed'] ?? 0);
    $invTotal = (int)($invoices['total'] ?? 0);
    $invLeft = (int)($invoices['unprinted'] ?? max(0, $invTotal - $invDone));
    $prodDone = (int)($products['with_available_qty'] ?? $products['stocked'] ?? 0);
    $prodTotal = (int)($products['total'] ?? 0);
    $prodLeft = (int)($products['zero_available'] ?? $products['zero_stock'] ?? max(0, $prodTotal - $prodDone));
    $mainlandLeft = max(0, (int)($products['mainland_source'] ?? 0) - (int)($products['mainland_source_stocked'] ?? 0));
    $rows = [
        [
            'id' => 'invoice-print',
            'title' => '電子發票列印',
            'kind' => 'invoice',
            'done' => $invDone,
            'total' => $invTotal,
            'remaining' => $invLeft,
            'rate' => bh_task_green_rate($invDone, $invTotal),
            'unit' => '張',
            'leftoverKind' => $invLeft > 0 ? '未確認列印的電子發票' : '無',
            'leftover' => bh_task_leftover_labels($invoices['leftover'] ?? []),
            'available' => !empty($invoices['available']),
        ],
        [
            'id' => 'sku-available',
            'title' => '庫存商品建檔／有可用數量',
            'kind' => 'product',
            'done' => $prodDone,
            'total' => $prodTotal,
            'remaining' => $prodLeft,
            'rate' => bh_task_green_rate($prodDone, $prodTotal),
            'unit' => '筆',
            'leftoverKind' => $prodLeft > 0 ? '尚無可用數量的商品' : '無',
            'leftover' => bh_task_leftover_labels($products['leftover'] ?? []),
            'available' => !empty($products['available']),
        ],
        [
            'id' => 'mainland-source',
            'title' => '大陸來源商品建檔',
            'kind' => 'product',
            'done' => (int)($products['mainland_source_stocked'] ?? 0),
            'total' => (int)($products['mainland_source'] ?? 0),
            'remaining' => $mainlandLeft,
            'rate' => bh_task_green_rate((int)($products['mainland_source_stocked'] ?? 0), (int)($products['mainland_source'] ?? 0)),
            'unit' => '筆',
            'leftoverKind' => $mainlandLeft > 0 ? '拼多多／淘寶／豪鴻尚未有庫存數量' : '無',
            'leftover' => bh_task_leftover_labels($products['mainland_leftover'] ?? []),
            'available' => !empty($products['available']),
        ],
    ];
    $remainingTotal = 0;
    foreach ($rows as $row) {
        $remainingTotal += (int)$row['remaining'];
    }
    return [
        'updatedAt' => date('c'),
        'remainingTotal' => $remainingTotal,
        'rows' => $rows,
    ];
}

function bh_task_equal_split(int $remaining, int $people): array
{
    $people = max(1, $people);
    $remaining = max(0, $remaining);
    $base = intdiv($remaining, $people);
    $extra = $remaining % $people;
    $shares = [];
    for ($i = 0; $i < $people; $i++) {
        $shares[] = $base + ($i < $extra ? 1 : 0);
    }
    return $shares;
}

function bh_task_share_note(array $row, int $shareQty, int $people, int $index): string
{
    $title = (string)($row['title'] ?? '工作');
    $remaining = (int)($row['remaining'] ?? 0);
    $done = (int)($row['done'] ?? 0);
    $total = (int)($row['total'] ?? 0);
    $rate = (int)($row['rate'] ?? 0);
    $unit = (string)($row['unit'] ?? '筆');
    $kind = (string)($row['leftoverKind'] ?? '剩餘內容');
    $samples = array_slice((array)($row['leftover'] ?? []), 0, 8);
    $sampleText = $samples ? implode('、', $samples) : '見進度表剩餘清單';
    $n = $index + 1;
    return "【進度表等分】{$title}\n"
        . "目前進度：已完成 {$done}／共 {$total}{$unit}（綠比例 {$rate}%）\n"
        . "還沒好：{$kind}，剩餘 {$remaining}{$unit}\n"
        . "等分：{$people} 人，你是第 {$n} 位，分到 {$shareQty}{$unit}\n"
        . "剩餘內容例：{$sampleText}\n"
        . "來不及完成時請在回報勾選「來不及完成」，填綠比例與何時可以完成。";
}

function bh_task_verdict_from_checks(array $checks): string
{
    $statuses = array_column($checks, 'status');
    if (in_array('blocking', $statuses, true)) return '時間不合理';
    if (in_array('mismatch', $statuses, true)) return '與系統不符';
    $hasOk = in_array('match', $statuses, true);
    $hasWarn = in_array('caution', $statuses, true);
    $hasUnknown = in_array('unknown', $statuses, true);
    if ($hasOk && ($hasWarn || $hasUnknown)) return '部分核實';
    if ($hasOk && !$hasWarn && !$hasUnknown) return '核實';
    if ($hasWarn && !$hasOk) return '部分核實';
    return '無法核實';
}

function bh_task_audit_report(array $task, array $payload, array $previousLogs = [], ?array $evidence = null, array $options = []): array
{
    $display = !empty($options['display']);
    $note = trim((string)($payload['progressNote'] ?? $payload['incompleteReason'] ?? $task['progressNote'] ?? $task['incompleteReason'] ?? ''));
    $taskText = bh_task_hay([
        $task['name'] ?? '',
        $task['detail'] ?? '',
        $task['incompleteReason'] ?? '',
        $note,
        $payload['detail'] ?? '',
    ]);
    $progressAt = trim((string)($payload['progressAt'] ?? ''));
    $proposedAt = trim((string)($payload['proposedCompleteAt'] ?? $payload['secondProgressAt'] ?? $task['proposedCompleteAt'] ?? ''));
    $rate = bh_task_clamp_rate($payload['progressRate'] ?? $task['progressRate'] ?? 0);
    $evidence = $evidence ?? bh_task_collect_evidence();
    $now = (int)($evidence['now'] ?? time());
    $invoices = $evidence['invoices'] ?? [];
    $products = $evidence['products'] ?? [];
    $claims = bh_task_extract_claims($taskText);
    $checks = [];
    $blocking = false;

    if ($note === '' || mb_strlen($note) < 4) {
        if ($display) {
            $checks[] = bh_task_check('where', '目前做到哪', 'caution', '這筆還沒回報目前做到哪。');
        } else {
            $blocking = true;
            $checks[] = bh_task_check('where', '目前做到哪', 'blocking', '每次延長或回報都要寫目前做到哪，不能只改時間。');
        }
    } else {
        $checks[] = bh_task_check('where', '目前做到哪', 'match', '已寫目前進度：' . mb_substr($note, 0, 80));
    }

    $progressTs = bh_task_parse_ts($progressAt);
    $proposedTs = bh_task_parse_ts($proposedAt);
    if ($progressTs === null) {
        if ($display) {
            $checks[] = bh_task_check('progress_time', '回填進度時間', 'caution', '尚未回填這次實際進度時間。');
        } else {
            $blocking = true;
            $checks[] = bh_task_check('progress_time', '回填進度時間', 'blocking', '請填這次實際做到這個進度的時間。');
        }
    } elseif ($progressTs > $now + 3600) {
        $checks[] = bh_task_check('progress_time', '回填進度時間', 'mismatch', '回填進度時間晚於現在，時間不合理。');
    } else {
        $checks[] = bh_task_check('progress_time', '回填進度時間', 'match', '已回填 ' . bh_task_format_ts($progressTs));
    }

    if ($proposedTs === null) {
        if ($display) {
            $checks[] = bh_task_check('proposed_time', '二次修正進度時間', 'caution', '可以延長，但還沒提出下次進度時間。');
        } else {
            $blocking = true;
            $checks[] = bh_task_check('proposed_time', '二次修正進度時間', 'blocking', '可以延長，但必須提出適當的進度時間。');
        }
    } elseif ($progressTs !== null && $proposedTs <= $progressTs) {
        $blocking = true;
        $checks[] = bh_task_check('proposed_time', '二次修正進度時間', 'blocking', '提出的完成時間必須晚於回填進度時間。');
    } elseif ($proposedTs < $now - 300 && !$display) {
        $checks[] = bh_task_check('proposed_time', '二次修正進度時間', 'mismatch', '提出的完成時間已經過了，請改成還做得到的時間。');
    } else {
        $hours = $progressTs ? max(1, (int)round(($proposedTs - $progressTs) / 3600)) : 0;
        $status = 'match';
        $detail = '提出做到 ' . bh_task_format_ts($proposedTs);
        if ($hours > 72 && !bh_task_has($note, ['需要較長', '還很多', '來不及', '下週', '下周'])) {
            $status = 'caution';
            $detail .= '（距本次進度超過 3 天，請確認這個時間是否適當）';
        }
        $checks[] = bh_task_check('proposed_time', '二次修正進度時間', $status, $detail);
    }

    $prevRate = null;
    if ($previousLogs) {
        $last = $previousLogs[count($previousLogs) - 1];
        if (is_array($last) && isset($last['progressRate'])) $prevRate = bh_task_clamp_rate($last['progressRate']);
    } elseif (isset($task['progressRate']) && $task['progressRate'] !== '' && $task['progressRate'] !== null) {
        $prevRate = bh_task_clamp_rate($task['progressRate']);
        if ($prevRate === $rate) $prevRate = null;
    }
    if ($prevRate !== null && $rate + 5 < $prevRate && !bh_task_has($note, ['重做', '退回', '重盤', '打回', '重來'])) {
        $checks[] = bh_task_check('rate', '完成率', 'caution', "完成率從 {$prevRate}% 降到 {$rate}%，請說明為什麼進度後退。");
    } elseif ($rate >= 90 && ($claims['mainland_inventory_remaining'] || $claims['invoice_still_unprinted'])) {
        $checks[] = bh_task_check('rate', '完成率', 'caution', "完成率寫 {$rate}%，但說明還有大項沒做完，完成率可能偏高。");
    } else {
        $checks[] = bh_task_check('rate', '完成率', 'match', "目前完成率 {$rate}%");
    }

    if ($claims['invoice_all_printed']) {
        if (!empty($invoices['available'])) {
            $printed = (int)$invoices['printed'];
            $total = (int)$invoices['total'];
            $unprinted = (int)$invoices['unprinted'];
            if ($unprinted === 0 && $total > 0) {
                $checks[] = bh_task_check('invoices', '電子發票列印', 'match', "系統已確認列印 {$printed}／共 {$total}，與「已全部印出」相符。");
            } else {
                $checks[] = bh_task_check('invoices', '電子發票列印', 'mismatch', "說明寫電子發票已全部印出，但系統是已確認列印 {$printed}／共 {$total}，未印 {$unprinted} 張。");
            }
        } else {
            $checks[] = bh_task_check('invoices', '電子發票列印', 'unknown', '說明提到發票已印完，但現在讀不到發票列印資料。');
        }
    } elseif ($claims['invoice_still_unprinted'] && !empty($invoices['available'])) {
        $unprinted = (int)$invoices['unprinted'];
        $status = $unprinted > 0 ? 'match' : 'mismatch';
        $detail = $unprinted > 0
            ? "系統仍有 {$unprinted} 張未印，與「發票還沒印完」相符。"
            : '說明寫發票還沒印完，但系統未印張數已是 0。';
        $checks[] = bh_task_check('invoices', '電子發票列印', $status, $detail);
    }

    if ($claims['mainland_inventory_remaining'] || $claims['only_mainland_left'] || $claims['mainland_inventory_done']) {
        if (!empty($products['available'])) {
            $src = (int)$products['mainland_source'];
            $srcStocked = (int)$products['mainland_source_stocked'];
            $recycle = (int)$products['mainland_recycle'];
            $inbound = (int)$products['mainland_inbound_docs'];
            $unbuiltSource = max(0, $src - $srcStocked);
            if ($claims['mainland_inventory_done']) {
                $status = ($unbuiltSource === 0 && $src > 0) ? 'match' : 'mismatch';
                $checks[] = bh_task_check('mainland', '大陸產品建檔', $status, $status === 'match'
                    ? "拼多多／淘寶／豪鴻建檔 {$src} 筆且都有庫存數量。"
                    : "說明寫大陸產品已建完，但來源建檔 {$src} 筆、有庫存 {$srcStocked} 筆，入庫單 {$inbound} 張。");
            } else {
                $status = ($unbuiltSource > 0 || $src === 0 || $inbound > $srcStocked) ? 'match' : 'caution';
                $checks[] = bh_task_check(
                    'mainland',
                    '大陸產品建檔',
                    $status,
                    "拼多多／淘寶／豪鴻建檔 {$src} 筆、有庫存 {$srcStocked} 筆；大陸入庫單 {$inbound} 張；回收(大陸)目錄 {$recycle} 筆。大陸品還沒建完這點與庫存資料大致相符。"
                );
            }
            if ($claims['only_mainland_left']) {
                $otherZero = max(0, (int)$products['zero_stock'] - $unbuiltSource - max(0, $recycle - (int)$products['mainland_recycle_stocked']));
                if ($otherZero > 200) {
                    $checks[] = bh_task_check('only_left', '是否只剩大陸', 'caution', "說明寫只剩大陸還沒建，但系統還有約 {$otherZero} 筆非大陸商品庫存為 0。若建檔是指入庫數量，這句可能不完整。");
                } else {
                    $checks[] = bh_task_check('only_left', '是否只剩大陸', 'match', '其他商品大多已有庫存數量，只剩大陸品較符合。');
                }
            }
        } else {
            $checks[] = bh_task_check('mainland', '大陸產品建檔', 'unknown', '說明提到大陸產品建檔，但現在讀不到產品資料。');
        }
    }

    if ($claims['vague']) {
        $checks[] = bh_task_check('detail', '說明具體程度', 'caution', '說明太短，主管看不出做到哪、還剩什麼。');
    }

    $workloadHours = 0;
    if (!empty($invoices['available']) && $claims['invoice_all_printed'] === false && (int)$invoices['unprinted'] > 30) {
        $workloadHours += (int)ceil(((int)$invoices['unprinted']) / 40);
    }
    if (!empty($products['available']) && ($claims['mainland_inventory_remaining'] || $claims['only_mainland_left'])) {
        $left = max(1, (int)$products['mainland_source'] - (int)$products['mainland_source_stocked']);
        $workloadHours += (int)ceil($left / 20);
    }
    if ($proposedTs !== null && $progressTs !== null && $workloadHours > 0) {
        $given = ($proposedTs - $progressTs) / 3600;
        if ($given > 0 && $given < $workloadHours && !empty($invoices['unprinted']) && $claims['invoice_all_printed']) {
            $checks[] = bh_task_check('pace', '時間是否適當', 'mismatch', '提出的完成時間偏趕，且發票列印情況與說明不符。');
        } elseif ($given > 0 && $given < max(4, $workloadHours) && ((int)($invoices['unprinted'] ?? 0) > 50 || ($claims['mainland_inventory_remaining'] && (int)($products['mainland_source'] ?? 0) <= 1))) {
            $checks[] = bh_task_check('pace', '時間是否適當', 'caution', '可以延長，但請確認這個時間內真的能做到你寫的進度。');
        }
    }

    $verdict = $blocking ? '時間不合理' : bh_task_verdict_from_checks($checks);
    $summaryParts = [];
    foreach ($checks as $check) {
        if (in_array($check['status'], ['mismatch', 'blocking', 'caution'], true)) {
            $summaryParts[] = $check['detail'];
        }
    }
    if (!$summaryParts) {
        $summaryParts[] = $verdict === '核實' ? '這次說明與系統資料相符，時間與目前做到哪都有寫。' : '沒有可對的系統資料，先記下這次進度。';
    }

    $score = 100;
    foreach ($checks as $check) {
        $score -= match ($check['status']) {
            'blocking', 'mismatch' => 35,
            'caution' => 12,
            'unknown' => 8,
            default => 0,
        };
    }
    $score = max(0, min(100, $score));

    return [
        'verdict' => $verdict,
        'score' => $score,
        'summary' => implode(' ', $summaryParts),
        'checks' => $checks,
        'claims' => $claims,
        'evidence' => [
            'invoices' => $invoices,
            'products' => [
                'total' => (int)($products['total'] ?? 0),
                'stocked' => (int)($products['stocked'] ?? 0),
                'zero_stock' => (int)($products['zero_stock'] ?? 0),
                'mainland_source' => (int)($products['mainland_source'] ?? 0),
                'mainland_source_stocked' => (int)($products['mainland_source_stocked'] ?? 0),
                'mainland_recycle' => (int)($products['mainland_recycle'] ?? 0),
                'mainland_inbound_docs' => (int)($products['mainland_inbound_docs'] ?? 0),
                'available' => !empty($products['available']),
            ],
        ],
        'blocking' => $blocking,
        'pending' => false,
        'engine' => 'evidence-ai',
        'auditedAt' => date('Y-m-d H:i:s', $now),
    ];
}

function bh_task_llm_config(): ?array
{
    $path = '';
    if (function_exists('acc_private_root')) {
        $path = acc_private_root() . DIRECTORY_SEPARATOR . 'ai-config.json';
    } else {
        $live = 'F:' . DIRECTORY_SEPARATOR . 'Data' . DIRECTORY_SEPARATOR . 'BaohuiAccounting' . DIRECTORY_SEPARATOR . 'ai-config.json';
        $path = is_file($live) ? $live : (__DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'ai-config.json');
    }
    if (!is_file($path)) return null;
    $cfg = json_decode((string)file_get_contents($path), true);
    if (!is_array($cfg)) return null;
    $key = trim((string)($cfg['apiKey'] ?? getenv('BAOHUI_LLM_API_KEY') ?? getenv('OPENAI_API_KEY') ?? getenv('GEMINI_API_KEY') ?? ''));
    if ($key === '') return null;
    $cfg['apiKey'] = $key;
    return $cfg;
}

function bh_task_llm_refine(array $audit, array $task, array $payload): array
{
    $cfg = bh_task_llm_config();
    if ($cfg === null) return $audit;
    $prompt = "你是寶輝後台的進度核實助理。用繁體中文、短句。不要改動系統已經判定為「與系統不符」的結論。\n"
        . "任務：" . json_encode([
            'name' => $task['name'] ?? '',
            'assign' => $task['assign'] ?? '',
            'note' => $payload['progressNote'] ?? '',
            'rate' => $payload['progressRate'] ?? '',
            'progressAt' => $payload['progressAt'] ?? '',
            'proposedCompleteAt' => $payload['proposedCompleteAt'] ?? '',
        ], JSON_UNESCAPED_UNICODE)
        . "\n既有核實：" . json_encode([
            'verdict' => $audit['verdict'] ?? '',
            'checks' => $audit['checks'] ?? [],
            'evidence' => $audit['evidence'] ?? [],
        ], JSON_UNESCAPED_UNICODE)
        . "\n請用 1 到 2 句補一句主管看得懂的評語。";
    try {
        $provider = strtolower((string)($cfg['provider'] ?? 'openai'));
        if ($provider === 'gemini') {
            $model = trim((string)($cfg['model'] ?? 'gemini-2.0-flash'));
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode((string)$cfg['apiKey']);
            $body = json_encode(['contents' => [['parts' => [['text' => $prompt]]]]], JSON_UNESCAPED_UNICODE);
            $raw = bh_task_http_post($url, $body, ['Content-Type: application/json']);
            $json = json_decode($raw, true);
            $text = trim((string)($json['candidates'][0]['content']['parts'][0]['text'] ?? ''));
        } else {
            $url = trim((string)($cfg['endpoint'] ?? 'https://api.openai.com/v1/chat/completions'));
            $model = trim((string)($cfg['model'] ?? 'gpt-4o-mini'));
            $body = json_encode([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => '你核對員工進度說明是否與系統資料相符。'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.1,
            ], JSON_UNESCAPED_UNICODE);
            $raw = bh_task_http_post($url, $body, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $cfg['apiKey'],
            ]);
            $json = json_decode($raw, true);
            $text = trim((string)($json['choices'][0]['message']['content'] ?? ''));
        }
        if ($text !== '') {
            $audit['llmNote'] = mb_substr($text, 0, 240);
            $audit['engine'] = 'evidence-ai+llm';
            $audit['summary'] = trim($audit['summary'] . ' ' . $audit['llmNote']);
        }
    } catch (Throwable $e) {
        $audit['llmError'] = '語言模型略過：' . $e->getMessage();
    }
    return $audit;
}

function bh_task_http_post(string $url, string $body, array $headers): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException($err ?: 'llm request failed');
        }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400) throw new RuntimeException('llm http ' . $code);
        return (string)$raw;
    }
    $headerStr = implode("\r\n", $headers);
    $raw = @file_get_contents($url, false, stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => $headerStr,
            'content' => $body,
            'timeout' => 12,
            'ignore_errors' => true,
        ],
    ]));
    if ($raw === false) throw new RuntimeException('llm request failed');
    return $raw;
}

function bh_task_normalize_payload(array $task, array $input): array
{
    return [
        'progressRate' => bh_task_clamp_rate($input['progressRate'] ?? $task['progressRate'] ?? 0),
        'progressAt' => trim((string)($input['progressAt'] ?? $task['progressAt'] ?? '')),
        'progressNote' => trim((string)($input['progressNote'] ?? $input['incompleteReason'] ?? $task['progressNote'] ?? $task['incompleteReason'] ?? '')),
        'proposedCompleteAt' => trim((string)($input['proposedCompleteAt'] ?? $input['secondProgressAt'] ?? $task['proposedCompleteAt'] ?? $task['approvedCompleteAt'] ?? '')),
        'detail' => trim((string)($input['detail'] ?? '')),
    ];
}
