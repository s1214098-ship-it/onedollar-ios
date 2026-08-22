<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'staff-error.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'shipping-loss.php';

function dup_ship_ack_file(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'duplicate-ship-acks.json';
}

function dup_ship_phone_key($value): string
{
    $phone = preg_replace('/\D+/', '', (string)$value) ?? '';
    if (strlen($phone) >= 11 && strpos($phone, '886') === 0) $phone = '0' . substr($phone, 3);
    return strlen($phone) >= 8 ? substr($phone, -9) : '';
}

function dup_ship_customer_key(array $order): string
{
    $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
    $recordId = trim((string)($customer['customerRecordId'] ?? ($customer['customerId'] ?? ($order['customerRecordId'] ?? ($order['customerId'] ?? '')))));
    if ($recordId !== '') return 'id:' . $recordId;
    $phone = dup_ship_phone_key($customer['phone'] ?? ($order['customerPhone'] ?? ($order['phone'] ?? '')));
    if ($phone !== '') return 'p:' . $phone;
    $name = strtolower(preg_replace('/\s+/', '', (string)($customer['name'] ?? ($order['customerName'] ?? ($order['name'] ?? '')))) ?? '');
    return strlen($name) >= 2 ? 'n:' . $name : '';
}

function dup_ship_product_key($item): string
{
    if (!is_array($item)) return '';
    $rawCode = (string)($item['code'] ?? $item['productCode'] ?? $item['productId'] ?? $item['sku'] ?? $item['skuId'] ?? $item['title'] ?? '');
    $code = strtoupper(trim((string)preg_replace('/\s+/', '', $rawCode)));
    if ($code === '' || $code === '-' || $code === '未選商品') return '';
    $color = strtolower(trim((string)($item['color'] ?? ($item['colorName'] ?? ''))));
    $size = strtoupper(trim((string)($item['size'] ?? ($item['sizeName'] ?? ''))));
    return $code . '|' . $color . '|' . $size;
}

function dup_ship_product_keys(array $row): array
{
    $keys = [];
    foreach (is_array($row['items'] ?? null) ? $row['items'] : [] as $item) {
        $key = dup_ship_product_key($item);
        if ($key !== '') $keys[$key] = is_array($item) ? $item : [];
    }
    return $keys;
}

function dup_ship_linked_ids(array $row): array
{
    $ids = [];
    foreach (['id', 'orderId', 'convertedToOrderId', 'convertedOrderId', 'sourceInquiryId', 'inquiryId', 'preorderId', 'formalOrderId'] as $field) {
        $value = trim((string)($row[$field] ?? ''));
        if ($value !== '') $ids[$value] = true;
    }
    return $ids;
}

function dup_ship_kind(array $order): string
{
    $status = strtolower(trim((string)($order['status'] ?? ($order['stage'] ?? ''))));
    if (in_array($status, ['cancelled', 'canceled', 'deleted', 'void', 'refunded', 'closed'], true)) return '';
    $delivery = strtolower(trim((string)($order['deliveryState'] ?? '')));
    if ($delivery === 'returned' || $status === 'returned') return 'returned';
    if (in_array($status, ['delivered', 'completed', 'shipped', 'in_transit'], true)) return 'shipped';
    if (in_array($delivery, ['in_transit', 'arrived_store', 'delivered', 'shipped'], true)) return 'shipped';
    if (trim((string)($order['shippedAt'] ?? '')) !== ''
        || trim((string)($order['trackingNo'] ?? '')) !== ''
        || trim((string)($order['shippingTrackingNo'] ?? '')) !== ''
        || trim((string)($order['deliveredAt'] ?? '')) !== '') {
        return 'shipped';
    }
    return 'open';
}

function dup_ship_taipei_today(): string
{
    try {
        $tz = new DateTimeZone('Asia/Taipei');
        return (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    } catch (Throwable $error) {
        return date('Y-m-d');
    }
}

function dup_ship_date_key($value): string
{
    $raw = trim((string)$value);
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $match)) return $match[1];
    if ($raw === '') return '';
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d', $ts) : '';
}

function dup_ship_record_date(array $order): string
{
    return dup_ship_date_key(
        $order['shippedAt']
        ?? ($order['deliveredAt'] ?? ($order['pickedUpAt'] ?? ($order['shippingDate'] ?? ($order['orderDate'] ?? ($order['createdAt'] ?? ($order['updatedAt'] ?? ''))))))
    );
}

function dup_ship_within_month(string $key, string $today = ''): bool
{
    if ($today === '') $today = dup_ship_taipei_today();
    $key = substr($key, 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $key) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) return false;
    $cutoff = date('Y-m-d', strtotime($today . ' -31 days'));
    return $key >= $cutoff && $key <= $today;
}

function dup_ship_find_matches(array $source, array $orders, array $inquiries = []): array
{
    $customerKey = dup_ship_customer_key($source);
    $productKeys = dup_ship_product_keys($source);
    if ($customerKey === '' || !$productKeys) return [];
    $skip = dup_ship_linked_ids($source);
    $rows = [];
    $consider = static function (array $order, string $sourceType) use (&$rows, &$skip, $customerKey, $productKeys): void {
        $id = trim((string)($order['id'] ?? ''));
        if ($id === '' || !empty($skip[$id])) return;
        if (dup_ship_customer_key($order) !== $customerKey) return;
        $kind = dup_ship_kind($order);
        if ($kind === '' || $kind === 'closed') return;
        $dateKey = dup_ship_record_date($order);
        if (!dup_ship_within_month($dateKey)) return;
        $overlap = [];
        foreach (is_array($order['items'] ?? null) ? $order['items'] : [] as $item) {
            $key = dup_ship_product_key($item);
            if ($key !== '' && isset($productKeys[$key])) $overlap[] = $item;
        }
        if (!$overlap) return;
        $skip[$id] = true;
        $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
        $rows[] = [
            'id' => $id,
            'sourceType' => $sourceType,
            'kind' => $kind,
            'dateKey' => $dateKey,
            'name' => (string)($customer['name'] ?? ($order['customerName'] ?? ($order['name'] ?? '-'))),
            'phone' => (string)($customer['phone'] ?? ($order['customerPhone'] ?? ($order['phone'] ?? '-'))),
            'tracking' => (string)($order['trackingNo'] ?? ($order['shippingTrackingNo'] ?? '')),
            'carrier' => (string)($order['shippingCarrier'] ?? ($order['carrier'] ?? '')),
            'delivery' => (string)($order['deliveryState'] ?? ($order['status'] ?? $kind)),
        ];
    };
    foreach ($orders as $order) {
        if (is_array($order)) $consider($order, 'order');
    }
    foreach ($inquiries as $row) {
        if (is_array($row)) $consider($row, 'inquiry');
    }
    usort($rows, static fn($a, $b) => strcmp((string)($b['dateKey'] ?? ''), (string)($a['dateKey'] ?? '')));
    return array_slice($rows, 0, 10);
}

function dup_ship_read_acks(): array
{
    $file = dup_ship_ack_file();
    if (!is_file($file)) return [];
    $raw = rtrim((string)file_get_contents($file), "\0 \t\n\r\x0B");
    if ($raw === '') return [];
    $data = json_decode($raw, true);
    if (isset($data['rows']) && is_array($data['rows'])) return array_values($data['rows']);
    return is_array($data) ? array_values($data) : [];
}

function dup_ship_write_acks(array $rows): bool
{
    $file = dup_ship_ack_file();
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $payload = [
        'version' => 1,
        'updatedAt' => date(DATE_ATOM),
        'rows' => array_values($rows),
    ];
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(3));
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) return false;
    $ok = file_put_contents($tmp, $json, LOCK_EX) !== false && rename($tmp, $file);
    if (!$ok) @unlink($tmp);
    return $ok;
}

function dup_ship_has_ack(array $order, array $acks = []): bool
{
    if (trim((string)($order['duplicateCheckAckAt'] ?? '')) !== '') return true;
    if (trim((string)($order['duplicateCheckAckBy'] ?? '')) !== '') return true;
    if (!$acks) $acks = dup_ship_read_acks();
    $orderId = trim((string)($order['id'] ?? ''));
    $customerKey = dup_ship_customer_key($order);
    $now = time();
    foreach ($acks as $row) {
        if (!is_array($row) || (string)($row['status'] ?? '') === 'void') continue;
        if ($orderId !== '' && trim((string)($row['orderId'] ?? '')) === $orderId) return true;
        if ($customerKey === '' || trim((string)($row['customerKey'] ?? '')) !== $customerKey) continue;
        $at = strtotime((string)($row['ackedAt'] ?? ''));
        if ($at && abs($now - $at) <= 12 * 3600) return true;
    }
    return false;
}

function dup_ship_already_penalized(array $order, array $errors = []): bool
{
    if (trim((string)($order['duplicateNeglectPenaltyId'] ?? '')) !== '') return true;
    $orderId = trim((string)($order['id'] ?? ''));
    $lists = [];
    if (is_array($order['staffErrors'] ?? null)) $lists = array_merge($lists, $order['staffErrors']);
    $lists = array_merge($lists, $errors);
    foreach ($lists as $row) {
        if (!is_array($row)) continue;
        if ((string)($row['penaltyKind'] ?? '') !== 'duplicate_unacked') continue;
        $rowOrder = trim((string)($row['orderId'] ?? ''));
        if ($orderId !== '' && $rowOrder !== '' && $rowOrder !== $orderId) continue;
        return true;
    }
    return false;
}

function dup_ship_stamp_ack_on_order(string $orderId, array $ack): bool
{
    if ($orderId === '' || !function_exists('customer_company_debt_mutate_order')) return false;
    return customer_company_debt_mutate_order($orderId, static function (array &$order) use ($ack): void {
        if (trim((string)($order['duplicateCheckAckAt'] ?? '')) === '') {
            $order['duplicateCheckAckAt'] = (string)($ack['ackedAt'] ?? date(DATE_ATOM));
            $order['duplicateCheckAckBy'] = (string)($ack['ackedBy'] ?? '行政出貨人員');
            $order['duplicateCheckMatchIds'] = is_array($ack['matchIds'] ?? null) ? array_values($ack['matchIds']) : [];
        }
    });
}

function dup_ship_handle_ack(array $payload, array $orders, array $inquiries = []): array
{
    $orderId = trim((string)($payload['orderId'] ?? ''));
    $order = [];
    foreach ($orders as $row) {
        if (is_array($row) && trim((string)($row['id'] ?? '')) === $orderId) {
            $order = $row;
            break;
        }
    }
    if (!$order) {
        $order = [
            'id' => $orderId,
            'customer' => [
                'name' => trim((string)($payload['customerName'] ?? '')),
                'phone' => trim((string)($payload['customerPhone'] ?? '')),
            ],
            'customerName' => trim((string)($payload['customerName'] ?? '')),
            'customerPhone' => trim((string)($payload['customerPhone'] ?? '')),
        ];
    }
    $matchIds = [];
    foreach (is_array($payload['matchIds'] ?? null) ? $payload['matchIds'] : [] as $id) {
        $id = trim((string)$id);
        if ($id !== '') $matchIds[] = $id;
    }
    $now = date(DATE_ATOM);
    $ack = [
        'id' => 'DACK-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2))),
        'orderId' => $orderId,
        'customerKey' => dup_ship_customer_key($order),
        'customerName' => trim((string)($payload['customerName'] ?? ($order['customerName'] ?? ''))),
        'customerPhone' => trim((string)($payload['customerPhone'] ?? ($order['customerPhone'] ?? ''))),
        'matchIds' => array_values(array_unique($matchIds)),
        'matchKinds' => is_array($payload['matchKinds'] ?? null) ? array_values($payload['matchKinds']) : [],
        'ackedAt' => $now,
        'ackedBy' => trim((string)($payload['ackedBy'] ?? '行政出貨人員')) ?: '行政出貨人員',
        'status' => 'acked',
    ];
    $acks = dup_ship_read_acks();
    $acks[] = $ack;
    if (count($acks) > 4000) $acks = array_slice($acks, -4000);
    if (!dup_ship_write_acks($acks)) {
        return ['ok' => false, 'error' => '核對紀錄寫入失敗'];
    }
    if ($orderId !== '') dup_ship_stamp_ack_on_order($orderId, $ack);
    return ['ok' => true, 'ack' => $ack];
}

function dup_ship_apply_penalty(array &$orders, int $index, array $matches, string $staffName = '系統'): ?array
{
    if ($index < 0 || !isset($orders[$index]) || !is_array($orders[$index])) return null;
    $order = $orders[$index];
    $errors = staff_error_read();
    if (dup_ship_already_penalized($order, $errors)) return null;
    $now = date(DATE_ATOM);
    $errorId = 'SERR-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
    $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
    $errorRow = [
        'id' => $errorId,
        'status' => 'open',
        'amountTwd' => 100,
        'amount' => 100,
        'scorePoints' => 1,
        'countsTowardScore' => true,
        'penaltyKind' => 'duplicate_unacked',
        'customerName' => (string)($customer['name'] ?? ($order['customerName'] ?? ($order['name'] ?? ''))),
        'customerPhone' => (string)($customer['phone'] ?? ($order['customerPhone'] ?? ($order['phone'] ?? ''))),
        'orderId' => (string)($order['id'] ?? ''),
        'trackingNo' => (string)($order['trackingNo'] ?? ($order['shippingTrackingNo'] ?? '')),
        'reason' => '未核對重複相同訂單（系統查詢確認疏忽）',
        'note' => '同一客戶已出貨或未出貨的相同商品訂單未經確定核對。記 1 點，扣款 NT$100 一次。',
        'pendingOp' => 'none',
        'pendingFactor' => 0,
        'pendingResultTwd' => 100,
        'matchIds' => array_values(array_filter(array_map(static fn($row) => (string)($row['id'] ?? ''), $matches))),
        'createdAt' => $now,
        'createdBy' => $staffName !== '' ? $staffName : '系統',
    ];
    $errorRow['searchText'] = staff_error_search_text($errorRow) . ' 重複訂單 未核對 扣款 評分 1點';
    $errors[] = $errorRow;
    if (!staff_error_write($errors)) return null;
    $orderId = trim((string)($order['id'] ?? ''));
    if ($orderId !== '') staff_error_stamp_order($orderId, $errorRow);

    $reviews = shipping_loss_read();
    $reviewId = 'SHIP-DUP-' . strtoupper(substr(hash('sha256', $orderId . '|duplicate_unacked'), 0, 16));
    $exists = false;
    foreach ($reviews as $review) {
        if (is_array($review) && trim((string)($review['id'] ?? '')) === $reviewId) {
            $exists = true;
            break;
        }
    }
    if (!$exists) {
        $reviews[] = shipping_loss_normalize([
            'id' => $reviewId,
            'orderId' => $orderId,
            'customerName' => $errorRow['customerName'],
            'customerPhone' => $errorRow['customerPhone'],
            'salesName' => (string)($order['salesName'] ?? ($order['sales'] ?? '')),
            'reasonCode' => 'duplicate_unacked',
            'reasonLabel' => '未核對重複相同訂單',
            'party' => 'staff',
            'countsTowardScore' => true,
            'scorePoints' => 1,
            'staffNote' => $errorRow['note'],
            'shippingLossTwd' => 0,
            'trackingNo' => $errorRow['trackingNo'],
            'createdBy' => $errorRow['createdBy'],
            'createdAt' => $now,
            'managementReviewStatus' => 'recorded',
            'staffErrorId' => $errorId,
        ]);
        shipping_loss_write($reviews);
    }

    $orders[$index]['duplicateNeglectPenaltyId'] = $errorId;
    $orders[$index]['duplicateNeglectPenaltyAt'] = $now;
    $orders[$index]['duplicateNeglectScorePoints'] = 1;
    $orders[$index]['duplicateNeglectAmountTwd'] = 100;
    $orders[$index]['updatedAt'] = $now;
    return $errorRow;
}

function dup_ship_audit_order(array &$orders, array $inquiries, int $index, string $staffName = '系統'): ?array
{
    if ($index < 0 || !isset($orders[$index]) || !is_array($orders[$index])) return null;
    $order = $orders[$index];
    if (dup_ship_kind($order) !== 'shipped') return null;
    $matches = dup_ship_find_matches($order, $orders, $inquiries);
    if (!$matches) return null;
    if (dup_ship_has_ack($order)) return null;
    return dup_ship_apply_penalty($orders, $index, $matches, $staffName);
}

function dup_ship_handle_audit(array $payload, array &$orders, array $inquiries = []): array
{
    $orderId = trim((string)($payload['orderId'] ?? ''));
    $scanAll = !empty($payload['scanAll']);
    $staffName = trim((string)($payload['auditedBy'] ?? '系統')) ?: '系統';
    $penalties = [];
    $indexes = [];
    if ($orderId === '' && !$scanAll) {
        return ['ok' => false, 'error' => '請指定出貨單號，系統只查這張是否未核對疏忽'];
    }
    if ($orderId !== '') {
        foreach ($orders as $index => $order) {
            if (is_array($order) && trim((string)($order['id'] ?? '')) === $orderId) {
                $indexes[] = (int)$index;
                break;
            }
        }
    } else {
        foreach ($orders as $index => $order) {
            if (is_array($order) && dup_ship_kind($order) === 'shipped') $indexes[] = (int)$index;
        }
    }
    foreach ($indexes as $index) {
        $penalty = dup_ship_audit_order($orders, $inquiries, $index, $staffName);
        if ($penalty) $penalties[] = $penalty;
    }
    return [
        'ok' => true,
        'scanned' => count($indexes),
        'penalized' => count($penalties),
        'penalties' => $penalties,
    ];
}
