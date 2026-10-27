<?php
declare(strict_types=1);

/**
 * Logistics-only patch for delivery_notes.
 * Never writes products / stock_movements, never restores stock, never
 * rebuilds cost / payment / unlist flows. sales_out remains the stock path.
 */

function ops_delivery_logistics_allowed_fields(): array
{
    return [
        'tracking_no',
        'logistics_company',
        'logistics_delivery_status',
        'logistics_status_note',
        'logistics_checked_date',
        'shipping_status',
    ];
}

function ops_delivery_logistics_aliases(): array
{
    return [
        'sales_tracking_no' => 'tracking_no',
        'sales_logistics_company' => 'logistics_company',
        'sales_logistics_delivery_status' => 'logistics_delivery_status',
        'sales_logistics_status_note' => 'logistics_status_note',
        'sales_logistics_checked_date' => 'logistics_checked_date',
        'sales_shipping_status' => 'shipping_status',
        'status' => 'shipping_status',
    ];
}

function ops_delivery_logistics_meta_keys(): array
{
    return ['id', 'delivery_no', 'dry_run', 'action', 'store', 'operator'];
}

function ops_delivery_logistics_allowed_shipping_statuses(): array
{
    return ['未出貨', '待出貨', '備貨中', '已出貨', '完成'];
}

function ops_delivery_logistics_allowed_stores(): array
{
    return ['delivery_notes'];
}

function ops_delivery_logistics_snapshot(array $note): array
{
    $shipping = trim((string)($note['shipping_status'] ?? $note['status'] ?? ''));
    return [
        'id' => (string)($note['id'] ?? ''),
        'delivery_no' => (string)($note['delivery_no'] ?? ''),
        'tracking_no' => (string)($note['tracking_no'] ?? ''),
        'logistics_company' => (string)($note['logistics_company'] ?? ''),
        'logistics_delivery_status' => (string)($note['logistics_delivery_status'] ?? ''),
        'logistics_status_note' => (string)($note['logistics_status_note'] ?? ''),
        'logistics_checked_date' => (string)($note['logistics_checked_date'] ?? ''),
        'shipping_status' => $shipping,
        'status' => (string)($note['status'] ?? $shipping),
    ];
}

function ops_delivery_logistics_normalize_date(string $raw): array
{
    $value = trim($raw);
    if ($value === '') {
        return ['ok' => true, 'value' => ''];
    }
    if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $value, $m)) {
        $y = (int)$m[1];
        $mo = (int)$m[2];
        $d = (int)$m[3];
        if (!checkdate($mo, $d, $y)) {
            return ['ok' => false, 'error' => 'logistics_checked_date 不是有效日期', 'rejected' => 'bad_date'];
        }
        return ['ok' => true, 'value' => sprintf('%04d-%02d-%02d', $y, $mo, $d)];
    }
    return ['ok' => false, 'error' => 'logistics_checked_date 須為 Y-m-d 或空白', 'rejected' => 'bad_date'];
}

function ops_delivery_logistics_unknown_keys(array $input): array
{
    $allowed = array_fill_keys(ops_delivery_logistics_allowed_fields(), true);
    foreach (ops_delivery_logistics_aliases() as $alias => $_canon) {
        $allowed[$alias] = true;
    }
    foreach (ops_delivery_logistics_meta_keys() as $meta) {
        $allowed[$meta] = true;
    }
    $unknown = [];
    foreach ($input as $key => $_value) {
        $name = trim((string)$key);
        if ($name === '' || isset($allowed[$name])) continue;
        $unknown[] = $name;
    }
    sort($unknown);
    return $unknown;
}

function ops_delivery_logistics_extract_patch(array $input): array
{
    $aliases = ops_delivery_logistics_aliases();
    $allowed = array_fill_keys(ops_delivery_logistics_allowed_fields(), true);
    $patch = [];
    foreach ($input as $key => $value) {
        $name = trim((string)$key);
        if ($name === '') continue;
        $canon = $aliases[$name] ?? $name;
        if (!isset($allowed[$canon])) continue;
        if (is_bool($value) || is_int($value) || is_float($value)) {
            $text = (string)$value;
        } elseif ($value === null) {
            $text = '';
        } else {
            $text = trim((string)$value);
        }
        $patch[$canon] = $text;
    }
    return $patch;
}

function ops_delivery_logistics_validate_patch(array $patch): ?array
{
    if (array_key_exists('shipping_status', $patch)) {
        $status = trim((string)$patch['shipping_status']);
        if ($status !== '' && !in_array($status, ops_delivery_logistics_allowed_shipping_statuses(), true)) {
            return [
                'ok' => false,
                'error' => 'shipping_status 只允許：' . implode('／', ops_delivery_logistics_allowed_shipping_statuses()),
                'rejected' => 'bad_status',
            ];
        }
    }
    if (array_key_exists('logistics_checked_date', $patch)) {
        $date = ops_delivery_logistics_normalize_date((string)$patch['logistics_checked_date']);
        if (empty($date['ok'])) return $date;
    }
    return null;
}

function ops_delivery_logistics_find_index(array $notes, string $id, string $deliveryNo): array
{
    $id = trim($id);
    $deliveryNo = trim($deliveryNo);
    if ($id === '' || $deliveryNo === '') {
        return [
            'ok' => false,
            'error' => '寫入前必須同時提供 id 與 delivery_no',
            'rejected' => 'missing_ids',
            'index' => -1,
        ];
    }
    $idHit = -1;
    $noHit = -1;
    foreach ($notes as $i => $note) {
        if (!is_array($note)) continue;
        $noteId = trim((string)($note['id'] ?? ''));
        $noteNo = trim((string)($note['delivery_no'] ?? ''));
        if ($noteId === $id && $noteNo === $deliveryNo) {
            return ['ok' => true, 'index' => (int)$i, 'rejected' => null];
        }
        if ($noteId === $id && $idHit < 0) $idHit = (int)$i;
        if ($noteNo === $deliveryNo && $noHit < 0) $noHit = (int)$i;
    }
    if ($idHit >= 0 || $noHit >= 0) {
        $foundId = $idHit >= 0 ? trim((string)($notes[$idHit]['id'] ?? '')) : '';
        $foundNo = $idHit >= 0 ? trim((string)($notes[$idHit]['delivery_no'] ?? '')) : trim((string)($notes[$noHit]['delivery_no'] ?? ''));
        return [
            'ok' => false,
            'error' => 'id 與 delivery_no 對不上同一張出貨單',
            'rejected' => 'mismatch',
            'index' => -1,
            'found_id' => $foundId !== '' ? $foundId : trim((string)($notes[$noHit]['id'] ?? '')),
            'found_delivery_no' => $foundNo,
        ];
    }
    return [
        'ok' => false,
        'error' => '找不到這張出貨單',
        'rejected' => 'not_found',
        'index' => -1,
    ];
}

function ops_delivery_logistics_apply(array $note, array $patch, string $operator = '', ?string $at = null): array
{
    $at = $at ?? date('c');
    if (array_key_exists('logistics_checked_date', $patch)) {
        $date = ops_delivery_logistics_normalize_date((string)$patch['logistics_checked_date']);
        if (!empty($date['ok'])) $patch['logistics_checked_date'] = $date['value'];
    }
    foreach (ops_delivery_logistics_allowed_fields() as $field) {
        if (!array_key_exists($field, $patch)) continue;
        $note[$field] = (string)$patch[$field];
    }
    if (array_key_exists('shipping_status', $patch)) {
        $note['status'] = (string)$patch['shipping_status'];
        $note['shipping_status'] = (string)$patch['shipping_status'];
    }
    $note['updated_at'] = $at;
    if ($operator !== '') {
        $note['updated_by'] = $operator;
        $note['operator'] = $operator;
    }
    return $note;
}

function ops_delivery_logistics_truthy($value): bool
{
    if ($value === true || $value === 1 || $value === '1') return true;
    $text = strtolower(trim((string)$value));
    return in_array($text, ['1', 'true', 'yes', 'on', 'dry_run'], true);
}

function ops_delivery_logistics_prepare(array $notes, array $input, string $operator = '', ?string $at = null): array
{
    $unknown = ops_delivery_logistics_unknown_keys($input);
    if ($unknown) {
        return [
            'ok' => false,
            'error' => '拒絕非物流欄位：' . implode(', ', $unknown),
            'rejected' => 'forbidden',
            'unknown' => $unknown,
            'http' => 400,
        ];
    }
    $store = trim((string)($input['store'] ?? 'delivery_notes'));
    if ($store === '') $store = 'delivery_notes';
    if (!in_array($store, ops_delivery_logistics_allowed_stores(), true)) {
        return [
            'ok' => false,
            'error' => 'store 只允許 delivery_notes（customer-shipping）',
            'rejected' => 'forbidden',
            'http' => 400,
        ];
    }
    $id = trim((string)($input['id'] ?? ''));
    $deliveryNo = trim((string)($input['delivery_no'] ?? ''));
    $found = ops_delivery_logistics_find_index($notes, $id, $deliveryNo);
    if (empty($found['ok'])) {
        $found['http'] = ($found['rejected'] ?? '') === 'not_found' ? 404 : 400;
        return $found;
    }
    $index = (int)$found['index'];
    $note = is_array($notes[$index] ?? null) ? $notes[$index] : [];
    $patch = ops_delivery_logistics_extract_patch($input);
    $invalid = ops_delivery_logistics_validate_patch($patch);
    if ($invalid) {
        $invalid['http'] = 400;
        return $invalid;
    }
    $before = ops_delivery_logistics_snapshot($note);
    $updated = ops_delivery_logistics_apply($note, $patch, $operator, $at);
    $after = ops_delivery_logistics_snapshot($updated);
    return [
        'ok' => true,
        'store' => $store,
        'index' => $index,
        'id' => $before['id'],
        'delivery_no' => $before['delivery_no'],
        'operator' => $operator,
        'before' => $before,
        'after' => $after,
        'changed' => $before !== $after,
        'note' => $updated,
    ];
}
