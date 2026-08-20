<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$dataDir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
$snapshotFile = $dataDir . DIRECTORY_SEPARATOR . 'haohong-logistics-snapshot.json';
$freightFile = $dataDir . DIRECTORY_SEPARATOR . 'freight-forwarding-tracking.json';
$stateFile = $dataDir . DIRECTORY_SEPARATOR . 'admin-state.json';
$sessionsFile = $dataDir . DIRECTORY_SEPARATOR . 'admin-sessions.json';

function hh_table_out(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function hh_table_text($value, int $limit = 500): string {
    $text = trim((string)($value ?? ''));
    return function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit);
}

function hh_table_read(string $file): array {
    if (!is_file($file)) return [];
    $raw = file_get_contents($file);
    if ($raw === false || trim((string)$raw) === '') return [];
    $decoded = json_decode((string)preg_replace('/^\xEF\xBB\xBF/', '', (string)$raw), true);
    return is_array($decoded) ? $decoded : [];
}

function hh_table_track_key(string $trackingNo): string {
    return strtoupper((string)(preg_replace('/\s+/u', '', $trackingNo) ?? ''));
}

function hh_table_request_token(): string {
    $custom = trim((string)($_SERVER['HTTP_X_LINGZANZAN_ADMIN_SESSION'] ?? ''));
    if (preg_match('/^[A-Za-z0-9_-]{32,160}$/', $custom)) return $custom;
    $header = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^Bearer\s+([A-Za-z0-9_-]{32,160})$/i', $header, $match)) return (string)$match[1];
    $cookie = trim((string)($_COOKIE['lz_admin_session'] ?? ''));
    return preg_match('/^[A-Za-z0-9_-]{32,160}$/', $cookie) ? $cookie : '';
}

function hh_table_require_operator(string $sessionsFile, string $stateFile): array {
    $token = hh_table_request_token();
    if ($token === '') hh_table_out(401, ['ok' => false, 'error' => '請重新登入後台後再查看豪鴻對照表']);
    $targetHash = hash('sha256', $token);
    $now = time();
    foreach (hh_table_read($sessionsFile) as $session) {
        if (!is_array($session) || (int)($session['expiresAt'] ?? 0) <= $now) continue;
        $savedHash = trim((string)($session['tokenHash'] ?? ''));
        if ($savedHash === '' || !hash_equals($savedHash, $targetHash)) continue;
        $role = strtolower(trim((string)($session['role'] ?? '')));
        if (!in_array($role, ['admin', 'staff'], true)) hh_table_out(403, ['ok' => false, 'error' => '目前帳號不能查看豪鴻集運']);
        return [
            'role' => $role,
            'account' => hh_table_text($session['account'] ?? '', 100),
            'name' => hh_table_text($session['name'] ?? ($session['account'] ?? ''), 100),
        ];
    }
    hh_table_out(401, ['ok' => false, 'error' => '登入已逾時，請重新登入後台']);
}

function hh_table_is_haohong_batch(array $batch): bool {
    $blob = strtolower(implode(' ', [
        (string)($batch['provider'] ?? ''),
        (string)($batch['forwarder'] ?? ''),
        (string)($batch['costMode'] ?? ''),
        (string)($batch['id'] ?? ''),
        (string)($batch['haohongOrderId'] ?? ''),
        (string)($batch['haohongOrderCode'] ?? ''),
    ]));
    return strpos($blob, 'hao') !== false || strpos($blob, '豪鴻') !== false || strpos($blob, 'haohong-') !== false;
}

hh_table_require_operator($sessionsFile, $stateFile);

$snapshot = hh_table_read($snapshotFile);
$freight = hh_table_read($freightFile);
$localBatches = [];
foreach (($freight['batches'] ?? []) as $batch) {
    if (!is_array($batch) || !hh_table_is_haohong_batch($batch)) continue;
    $tracks = [];
    foreach ((array)($batch['trackingNumbers'] ?? []) as $no) {
        $key = hh_table_track_key((string)$no);
        if ($key !== '') $tracks[$key] = hh_table_text((string)$no, 80);
    }
    $first = hh_table_text($batch['firstTrackingNo'] ?? '', 80);
    if ($first !== '') $tracks[hh_table_track_key($first)] = $first;
    $localBatches[] = [
        'id' => hh_table_text($batch['id'] ?? '', 80),
        'batchNo' => hh_table_text($batch['batchNo'] ?? ($batch['haohongOrderCode'] ?? ($batch['haohongOrderId'] ?? '')), 80),
        'haohongOrderId' => hh_table_text($batch['haohongOrderId'] ?? '', 80),
        'haohongOrderCode' => hh_table_text($batch['haohongOrderCode'] ?? '', 80),
        'logisticsTrackingNo' => hh_table_text($batch['logisticsTrackingNo'] ?? '', 80),
        'status' => hh_table_text($batch['status'] ?? '', 80),
        'date' => hh_table_text($batch['date'] ?? '', 40),
        'packageCount' => (int)($batch['packageCount'] ?? count($tracks)),
        'itemCount' => (int)($batch['itemCount'] ?? 0),
        'shippingFeeTwd' => (float)($batch['shippingFeeTwd'] ?? ($batch['chargeTotalTwd'] ?? 0)),
        'totalBilledWeightKg' => (float)($batch['totalBilledWeightKg'] ?? 0),
        'totalActualWeightKg' => (float)($batch['totalActualWeightKg'] ?? 0),
        'destinationWarehouse' => hh_table_text($batch['destinationWarehouse'] ?? '', 40),
        'haohongSyncedAt' => hh_table_text($batch['haohongSyncedAt'] ?? '', 40),
        'note' => hh_table_text($batch['note'] ?? '', 300),
        'trackingNumbers' => array_values($tracks),
        'trackingKeys' => array_keys($tracks),
    ];
}

$localItems = [];
$itemsByTrack = [];
foreach (($freight['items'] ?? []) as $item) {
    if (!is_array($item)) continue;
    $source = strtolower((string)($item['logisticsSource'] ?? $item['provider'] ?? $item['forwarder'] ?? ''));
    $trackingNo = hh_table_text($item['trackingNo'] ?? ($item['haohongTrackingNo'] ?? ''), 80);
    $key = hh_table_track_key($trackingNo);
    $isHaohongSource = strpos($source, 'hao') !== false || strpos($source, '豪鴻') !== false;
    $inHaohongBatch = false;
    if ($key !== '') {
        foreach ($localBatches as $batch) {
            if (in_array($key, $batch['trackingKeys'], true)) { $inHaohongBatch = true; break; }
        }
    }
    if (!$isHaohongSource && !$inHaohongBatch) continue;
    $row = [
        'id' => hh_table_text($item['id'] ?? '', 80),
        'batchId' => hh_table_text($item['batchId'] ?? '', 80),
        'trackingNo' => $trackingNo,
        'productCode' => hh_table_text($item['productCode'] ?? ($item['catalogProductCode'] ?? ''), 80),
        'productName' => hh_table_text($item['productName'] ?? ($item['productFiledProductTitle'] ?? ''), 200),
        'color' => hh_table_text($item['color'] ?? '', 80),
        'size' => hh_table_text($item['size'] ?? '', 40),
        'quantity' => (int)($item['quantity'] ?? 0),
        'trackingStatus' => hh_table_text($item['trackingStatus'] ?? ($item['progress'] ?? ''), 80),
        'billedWeightKg' => (float)($item['billedWeightKg'] ?? ($item['weightKg'] ?? 0)),
    ];
    $localItems[] = $row;
    if ($key !== '') $itemsByTrack[$key][] = $row;
}

$remoteOrders = is_array($snapshot['orders'] ?? null) ? $snapshot['orders'] : [];
$remotePackages = is_array($snapshot['packages'] ?? null) ? $snapshot['packages'] : [];

$batchesOut = [];
$seenRemote = [];
foreach ($remoteOrders as $order) {
    if (!is_array($order)) continue;
    $orderId = hh_table_text($order['haohongOrderId'] ?? '', 80);
    $orderCode = hh_table_text($order['haohongOrderCode'] ?? $orderId, 80);
    $seenRemote[$orderId ?: $orderCode] = true;
    $remoteTracks = [];
    foreach ((array)($order['rows'] ?? []) as $pkg) {
        if (!is_array($pkg)) continue;
        $key = hh_table_track_key((string)($pkg['trackingNo'] ?? ''));
        if ($key !== '') $remoteTracks[$key] = true;
    }
    $local = null;
    foreach ($localBatches as $batch) {
        $ids = [$batch['id'], $batch['batchNo'], $batch['haohongOrderId'], $batch['haohongOrderCode']];
        if (in_array($orderId, $ids, true) || in_array($orderCode, $ids, true)) { $local = $batch; break; }
    }
    $localKeys = $local ? $local['trackingKeys'] : [];
    $matched = 0;
    foreach (array_keys($remoteTracks) as $key) {
        if (in_array($key, $localKeys, true) || isset($itemsByTrack[$key])) $matched++;
    }
    $remoteCount = count($remoteTracks);
    $diff = $local ? ($remoteCount - count($localKeys)) : $remoteCount;
    $batchesOut[] = [
        'batchNo' => $orderCode ?: $orderId,
        'haohongOrderId' => $orderId,
        'haohongStatus' => hh_table_text($order['status'] ?? '', 80),
        'orderDate' => hh_table_text($order['orderDate'] ?? '', 40),
        'transferOrderNo' => hh_table_text($order['transferOrderNo'] ?? '', 80),
        'remotePackageCount' => (int)($order['packageCount'] ?? $remoteCount),
        'remoteBilledKg' => (float)($order['totalBilledWeightKg'] ?? 0),
        'remoteActualKg' => (float)($order['totalActualWeightKg'] ?? 0),
        'remoteFeeTwd' => (float)($order['shippingFeeTwd'] ?? 0),
        'inBackend' => $local !== null,
        'localId' => $local['id'] ?? '',
        'localStatus' => $local['status'] ?? '',
        'localPackageCount' => $local['packageCount'] ?? 0,
        'localItemCount' => $local['itemCount'] ?? 0,
        'localFeeTwd' => $local['shippingFeeTwd'] ?? 0,
        'localBilledKg' => $local['totalBilledWeightKg'] ?? 0,
        'matchedTrackingCount' => $matched,
        'packageDiff' => $diff,
        'compare' => $local === null ? '豪鴻有、後台未帶入' : ($diff === 0 ? '已對上' : '件數不同'),
        'source' => 'haohong',
    ];
}

foreach ($localBatches as $batch) {
    $key = $batch['haohongOrderId'] ?: ($batch['haohongOrderCode'] ?: $batch['batchNo']);
    if (isset($seenRemote[$key]) || isset($seenRemote[$batch['batchNo']])) continue;
    if ($remoteOrders) {
        $batchesOut[] = [
            'batchNo' => $batch['batchNo'],
            'haohongOrderId' => $batch['haohongOrderId'],
            'haohongStatus' => '',
            'orderDate' => $batch['date'],
            'transferOrderNo' => $batch['logisticsTrackingNo'],
            'remotePackageCount' => 0,
            'remoteBilledKg' => 0,
            'remoteActualKg' => 0,
            'remoteFeeTwd' => 0,
            'inBackend' => true,
            'localId' => $batch['id'],
            'localStatus' => $batch['status'],
            'localPackageCount' => $batch['packageCount'],
            'localItemCount' => $batch['itemCount'],
            'localFeeTwd' => $batch['shippingFeeTwd'],
            'localBilledKg' => $batch['totalBilledWeightKg'],
            'matchedTrackingCount' => 0,
            'packageDiff' => 0 - (int)$batch['packageCount'],
            'compare' => '後台有、這次豪鴻清單沒有',
            'source' => 'backend',
        ];
    } else {
        $batchesOut[] = [
            'batchNo' => $batch['batchNo'],
            'haohongOrderId' => $batch['haohongOrderId'],
            'haohongStatus' => '',
            'orderDate' => $batch['date'],
            'transferOrderNo' => $batch['logisticsTrackingNo'],
            'remotePackageCount' => (int)$batch['packageCount'],
            'remoteBilledKg' => (float)$batch['totalBilledWeightKg'],
            'remoteActualKg' => (float)$batch['totalActualWeightKg'],
            'remoteFeeTwd' => (float)$batch['shippingFeeTwd'],
            'inBackend' => true,
            'localId' => $batch['id'],
            'localStatus' => $batch['status'],
            'localPackageCount' => $batch['packageCount'],
            'localItemCount' => $batch['itemCount'],
            'localFeeTwd' => $batch['shippingFeeTwd'],
            'localBilledKg' => $batch['totalBilledWeightKg'],
            'matchedTrackingCount' => count($batch['trackingKeys']),
            'packageDiff' => 0,
            'compare' => '後台已帶入（尚未重抓豪鴻清單）',
            'source' => 'backend',
        ];
    }
}

$packagesOut = [];
foreach ($remotePackages as $pkg) {
    if (!is_array($pkg)) continue;
    $trackingNo = hh_table_text($pkg['trackingNo'] ?? '', 80);
    $key = hh_table_track_key($trackingNo);
    $localHits = $itemsByTrack[$key] ?? [];
    $batchNo = '';
    foreach ($remoteOrders as $order) {
        foreach ((array)($order['rows'] ?? []) as $row) {
            if (hh_table_track_key((string)($row['trackingNo'] ?? '')) === $key) {
                $batchNo = hh_table_text($order['haohongOrderCode'] ?? ($order['haohongOrderId'] ?? ''), 80);
                break 2;
            }
        }
    }
    if ($batchNo === '') {
        foreach ($localBatches as $batch) {
            if (in_array($key, $batch['trackingKeys'], true)) { $batchNo = $batch['batchNo']; break; }
        }
    }
    $packagesOut[] = [
        'trackingNo' => $trackingNo,
        'batchNo' => $batchNo,
        'productName' => hh_table_text($pkg['productName'] ?? '', 200),
        'warehouse' => hh_table_text($pkg['warehouse'] ?? '', 80),
        'receivedAt' => hh_table_text($pkg['receivedAt'] ?? '', 40),
        'packageStatus' => hh_table_text($pkg['packageStatus'] ?? '', 80),
        'quantity' => (int)($pkg['quantity'] ?? 1),
        'actualWeightKg' => (float)($pkg['actualWeightKg'] ?? 0),
        'volumeWeightKg' => (float)($pkg['volumeWeightKg'] ?? 0),
        'billedWeightKg' => (float)($pkg['billedWeightKg'] ?? 0),
        'note' => hh_table_text($pkg['note'] ?? '', 200),
        'inBackend' => $localHits ? true : false,
        'backendProduct' => $localHits ? hh_table_text($localHits[0]['productName'] ?? '', 200) : '',
        'backendCode' => $localHits ? hh_table_text($localHits[0]['productCode'] ?? '', 80) : '',
        'backendStatus' => $localHits ? hh_table_text($localHits[0]['trackingStatus'] ?? '', 80) : '',
        'compare' => $localHits ? '已對上' : '豪鴻有、後台未帶入',
        'source' => 'haohong',
    ];
}

foreach ($remoteOrders as $order) {
    if (!is_array($order)) continue;
    $batchNo = hh_table_text($order['haohongOrderCode'] ?? ($order['haohongOrderId'] ?? ''), 80);
    foreach ((array)($order['rows'] ?? []) as $pkg) {
        if (!is_array($pkg)) continue;
        $trackingNo = hh_table_text($pkg['trackingNo'] ?? '', 80);
        $key = hh_table_track_key($trackingNo);
        if ($key === '') continue;
        $name = hh_table_text($pkg['productName'] ?? '', 200);
        $found = false;
        foreach ($packagesOut as &$existing) {
            if (hh_table_track_key((string)($existing['trackingNo'] ?? '')) !== $key) continue;
            $found = true;
            if ($name !== '' && hh_table_text($existing['productName'] ?? '', 200) === '') $existing['productName'] = $name;
            if ($batchNo !== '' && hh_table_text($existing['batchNo'] ?? '', 80) === '') $existing['batchNo'] = $batchNo;
            break;
        }
        unset($existing);
        if ($found) continue;
        $localHits = $itemsByTrack[$key] ?? [];
        $packagesOut[] = [
            'trackingNo' => $trackingNo,
            'batchNo' => $batchNo,
            'productName' => $name !== '' ? $name : '豪鴻訂單有單、品名空白',
            'warehouse' => hh_table_text($pkg['warehouse'] ?? '', 80),
            'receivedAt' => hh_table_text($pkg['receivedAt'] ?? '', 40),
            'packageStatus' => hh_table_text($order['status'] ?? '', 80),
            'quantity' => (int)($pkg['quantity'] ?? 1),
            'actualWeightKg' => (float)($pkg['actualWeightKg'] ?? 0),
            'volumeWeightKg' => (float)($pkg['volumeWeightKg'] ?? 0),
            'billedWeightKg' => (float)($pkg['billedWeightKg'] ?? 0),
            'note' => hh_table_text($pkg['note'] ?? '', 200),
            'inBackend' => $localHits ? true : false,
            'backendProduct' => $localHits ? hh_table_text($localHits[0]['productName'] ?? '', 200) : '',
            'backendCode' => $localHits ? hh_table_text($localHits[0]['productCode'] ?? '', 80) : '',
            'backendStatus' => $localHits ? hh_table_text($localHits[0]['trackingStatus'] ?? '', 80) : '',
            'compare' => $localHits ? '已對上' : '豪鴻有單、後台未建檔',
            'source' => 'haohong',
        ];
    }
}

if (!$packagesOut) {
    foreach ($localItems as $item) {
        $packagesOut[] = [
            'trackingNo' => $item['trackingNo'],
            'batchNo' => $item['batchId'],
            'productName' => $item['productName'],
            'warehouse' => '',
            'receivedAt' => '',
            'packageStatus' => '',
            'quantity' => $item['quantity'],
            'actualWeightKg' => 0,
            'volumeWeightKg' => 0,
            'billedWeightKg' => $item['billedWeightKg'],
            'note' => '',
            'inBackend' => true,
            'backendProduct' => $item['productName'],
            'backendCode' => $item['productCode'],
            'backendStatus' => $item['trackingStatus'],
            'compare' => '後台已帶入（尚未重抓豪鴻清單）',
            'source' => 'backend',
        ];
    }
}

usort($batchesOut, static function ($a, $b) {
    return strcmp((string)($b['orderDate'] ?? ''), (string)($a['orderDate'] ?? ''));
});

foreach ($batchesOut as &$batch) {
    $ids = array_values(array_filter([
        hh_table_text($batch['batchNo'] ?? '', 80),
        hh_table_text($batch['haohongOrderId'] ?? '', 80),
        hh_table_text($batch['localId'] ?? '', 80),
    ], static function ($value) {
        return $value !== '';
    }));
    $attached = [];
    $seenTrack = [];
    foreach ($packagesOut as $pkg) {
        if (!in_array((string)($pkg['batchNo'] ?? ''), $ids, true)) continue;
        $attached[] = $pkg;
        $key = hh_table_track_key((string)($pkg['trackingNo'] ?? ''));
        if ($key !== '') $seenTrack[$key] = true;
    }
    foreach ($localBatches as $local) {
        $localIds = [$local['id'], $local['batchNo'], $local['haohongOrderId'], $local['haohongOrderCode']];
        $overlap = false;
        foreach ($ids as $id) {
            if (in_array($id, $localIds, true)) { $overlap = true; break; }
        }
        if (!$overlap) continue;
        foreach ($local['trackingNumbers'] as $no) {
            $key = hh_table_track_key((string)$no);
            if ($key === '' || isset($seenTrack[$key])) continue;
            $hits = $itemsByTrack[$key] ?? [];
            $hasItem = $hits ? true : false;
            $attached[] = [
                'trackingNo' => hh_table_text((string)$no, 80),
                'batchNo' => hh_table_text($batch['batchNo'] ?? '', 80),
                'productName' => $hasItem ? hh_table_text($hits[0]['productName'] ?? '', 200) : '後台尚未建檔',
                'warehouse' => '',
                'receivedAt' => '',
                'packageStatus' => $hasItem ? hh_table_text($hits[0]['trackingStatus'] ?? '', 80) : '',
                'quantity' => $hasItem ? (int)($hits[0]['quantity'] ?? 1) : 1,
                'actualWeightKg' => 0,
                'volumeWeightKg' => 0,
                'billedWeightKg' => $hasItem ? (float)($hits[0]['billedWeightKg'] ?? 0) : 0,
                'note' => '',
                'inBackend' => $hasItem,
                'backendProduct' => $hasItem ? hh_table_text($hits[0]['productName'] ?? '', 200) : '後台尚未建檔',
                'backendCode' => $hasItem ? hh_table_text($hits[0]['productCode'] ?? '', 80) : '',
                'backendStatus' => $hasItem ? hh_table_text($hits[0]['trackingStatus'] ?? '', 80) : '',
                'compare' => $hasItem ? '後台已帶入' : '豪鴻有單、後台未建檔',
                'source' => $hasItem ? 'backend' : 'haohong-only',
            ];
            $seenTrack[$key] = true;
        }
    }
    $batch['packages'] = $attached;
    $batch['packageListCount'] = count($attached);
}
unset($batch);

hh_table_out(200, [
    'ok' => true,
    'snapshotAt' => hh_table_text($snapshot['savedAt'] ?? ($snapshot['syncedAt'] ?? ''), 40),
    'snapshotWarnings' => is_array($snapshot['warnings'] ?? null) ? $snapshot['warnings'] : [],
    'hasSnapshot' => $remoteOrders || $remotePackages,
    'batchCount' => count($batchesOut),
    'packageCount' => count($packagesOut),
    'localHaohongBatchCount' => count($localBatches),
    'batches' => $batchesOut,
    'packages' => $packagesOut,
]);
