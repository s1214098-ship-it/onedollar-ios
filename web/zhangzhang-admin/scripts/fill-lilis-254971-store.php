<?php
declare(strict_types=1);

/**
 * LILIS(LILIS TIKTOK MUZAMIL)(WA) 0989468228
 * 店號 254971 = 7-11 康喜門市（文山區興隆路三段42號）。
 * 只覆寫仍是純店號 254971 的超商地址，不改其他門市。
 */

$root = getenv('LINGZANZAN_ROOT') ?: 'F:/Web/lingzanzan-staging';
date_default_timezone_set('Asia/Taipei');
require_once $root . DIRECTORY_SEPARATOR . 'json-atomic-write.php';

const LABEL = '7-11 康喜店(254971)';
const ORDER_ID = 'BYORDER-20260816-13FFE8';
const PHONE = '0989468228';
const RAW = '254971';

function digits($value): string {
    return preg_replace('/\D+/', '', (string)$value) ?? '';
}

function is_raw_store($value): bool {
    $text = trim((string)$value);
    if ($text === '' || $text === LABEL) return false;
    return $text === RAW || preg_match('/^0*' . RAW . '$/', $text) === 1;
}

function phone_of(array $row): string {
    $customer = is_array($row['customer'] ?? null) ? $row['customer'] : [];
    return digits($customer['phone'] ?? $row['phone'] ?? $row['customerPhone'] ?? '');
}

function touch_row(array &$row, string $reason, array &$hits): bool {
    $id = (string)($row['id'] ?? $row['orderId'] ?? $row['memberId'] ?? $row['inquiryId'] ?? '');
    $phone = phone_of($row);
    $store = (string)($row['storeAddress'] ?? '');
    $address = (string)($row['address'] ?? '');
    $customer = is_array($row['customer'] ?? null) ? $row['customer'] : null;
    $cStore = is_array($customer) ? (string)($customer['storeAddress'] ?? '') : '';
    $cAddr = is_array($customer) ? (string)($customer['address'] ?? '') : '';

    $matchOrder = $id === ORDER_ID;
    $matchRaw = is_raw_store($store) || is_raw_store($address) || is_raw_store($cStore) || is_raw_store($cAddr);
    $matchPhoneRaw = $phone === PHONE && $matchRaw;
    if (!$matchOrder && !$matchPhoneRaw && !$matchRaw) return false;

    $changed = false;
    foreach (['storeAddress', 'address'] as $key) {
        if (!array_key_exists($key, $row) && !$matchOrder) continue;
        $current = (string)($row[$key] ?? '');
        if ($current === LABEL) continue;
        if (is_raw_store($current) || ($matchOrder && $current === '')) {
            $row[$key] = LABEL;
            $changed = true;
        }
    }
    if (is_array($customer)) {
        foreach (['storeAddress', 'address'] as $key) {
            $current = (string)($customer[$key] ?? '');
            if ($current === LABEL) continue;
            if (is_raw_store($current) || ($matchOrder && $current === '')) {
                $row['customer'][$key] = LABEL;
                $changed = true;
            }
        }
        if ($matchOrder || $phone === PHONE) {
            $row['customer']['addressPrimary'] = 'store';
        }
    }
    if ($matchOrder || $phone === PHONE) {
        if (($row['addressPrimary'] ?? null) !== 'store' && array_key_exists('addressPrimary', $row)) {
            $row['addressPrimary'] = 'store';
            $changed = true;
        }
        if (($row['shippingType'] ?? null) === '') {
            $row['shippingType'] = 'store';
            $changed = true;
        }
    }
    if ($changed) {
        $row['updatedAt'] = date('c');
        $hits[] = ['fileReason' => $reason, 'id' => $id, 'phone' => $phone, 'storeAddress' => $row['storeAddress'] ?? ($row['customer']['storeAddress'] ?? null)];
    }
    return $changed;
}

function walk(&$node, string $reason, array &$hits): int {
    $n = 0;
    if (!is_array($node)) return 0;
    $isRecord = isset($node['id']) || isset($node['orderId']) || isset($node['phone']) || isset($node['customer']) || isset($node['storeAddress']) || isset($node['address']);
    $isList = array_is_list($node) || (isset($node[0]) && is_array($node[0] ?? null));
    if ($isList) {
        foreach ($node as &$row) {
            $n += walk($row, $reason, $hits);
        }
        unset($row);
        return $n;
    }
    if ($isRecord && (isset($node['storeAddress']) || isset($node['address']) || isset($node['customer']) || isset($node['phone']))) {
        if (touch_row($node, $reason, $hits)) $n++;
    }
    foreach (['orders', 'inquiries', 'members', 'customers', 'items', 'list'] as $key) {
        if (isset($node[$key]) && is_array($node[$key])) {
            $n += walk($node[$key], $reason . ':' . $key, $hits);
        }
    }
    return $n;
}

$files = [
    $root . '/data/orders.json',
    $root . '/data/inquiries.json',
    $root . '/data/members.json',
    $root . '/data/customers.json',
];

$audit = $root . '/data/audit';
if (!is_dir($audit)) mkdir($audit, 0775, true);

$allHits = [];
foreach ($files as $file) {
    if (!is_file($file)) {
        echo "skip missing {$file}\n";
        continue;
    }
    $raw = file_get_contents($file);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        echo "skip unreadable {$file}\n";
        continue;
    }
    $hits = [];
    $n = walk($data, basename($file), $hits);
    if ($n === 0) {
        echo basename($file) . " unchanged\n";
        continue;
    }
    $bak = $audit . '/' . basename($file) . '.lilis-254971-' . date('Ymd-His');
    copy($file, $bak);
    $err = lz_atomic_write_json($file, $data);
    if ($err !== '') {
        fwrite(STDERR, basename($file) . " write failed: {$err}\n");
        exit(1);
    }
    echo basename($file) . " updated={$n} backup={$bak}\n";
    foreach ($hits as $hit) {
        echo json_encode($hit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $allHits[] = $hit;
    }
}

echo json_encode(['ok' => true, 'label' => LABEL, 'hits' => count($allHits)], JSON_UNESCAPED_UNICODE) . "\n";
