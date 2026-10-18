<?php
declare(strict_types=1);

@ini_set('memory_limit', '512M');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$dataFile = __DIR__ . '/../data/shared-db.json';
$cacheFile = __DIR__ . '/../data/cache/peer-list.json';
$publicCacheFile = __DIR__ . '/peer-list.json';

require __DIR__ . '/peer-photo-lib.php';

function respond($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function serveFile(string $path): void {
    global $publicCacheFile;
    if (!empty($publicCacheFile) && !file_exists($publicCacheFile)) {
        @copy($path, $publicCacheFile);
    }
    header('X-Peer-Cache: hit');
    readfile($path);
    exit;
}

function parseList($value): array {
    if (is_array($value)) return $value;
    if (is_string($value)) {
        $decoded = json_decode($value ?: '[]', true);
        return is_array($decoded) ? $decoded : [];
    }
    return [];
}

function firstImageSrc(array $item): string {
    $srcs = peerAllImageSrcs($item);
    return $srcs[0] ?? '';
}

function photoCount(array $item): int {
    $n = count(peerAllImageSrcs($item));
    return $n ?: (firstImageSrc($item) !== '' ? 1 : 0);
}

function keepPeerItem(array $item): bool {
    $blockedKeys = ['YCUT-1012453', 'd70563f5-e6cb-4563-a3b9-b99ceb78bd5f', '羅東旁阿嬤ㄟ厝靜巷超值透天'];
    $blob = implode(' ', [
        (string)($item['id'] ?? ''),
        (string)($item['publicNo'] ?? ''),
        (string)($item['externalId'] ?? ''),
        (string)($item['sourceUrl'] ?? ''),
        (string)($item['title'] ?? ''),
    ]);
    foreach ($blockedKeys as $key) {
        if ($key !== '' && strpos($blob, $key) !== false) return false;
    }
    $source = (string)($item['sourceSystem'] ?? '');
    if ($source === '公開同業網站同步') {
        $blob = ($item['county'] ?? '') . ($item['address'] ?? '') . ($item['title'] ?? '');
        return strpos($blob, '宜蘭') !== false;
    }
    return true;
}

function slimPeerItem(array $item): array {
    $src = firstImageSrc($item);
    $out = [
        'id' => $item['id'] ?? '',
        'externalId' => $item['externalId'] ?? '',
        'publicNo' => $item['publicNo'] ?? '',
        'sourceSystem' => $item['sourceSystem'] ?? '',
        'sourceUrl' => $item['sourceUrl'] ?? '',
        'sourceHost' => $item['sourceHost'] ?? '',
        'sourceCompany' => $item['sourceCompany'] ?? '',
        'storeName' => $item['storeName'] ?? '',
        'company' => $item['company'] ?? '',
        'title' => $item['title'] ?? '',
        'county' => $item['county'] ?? '',
        'area' => $item['area'] ?? '',
        'district' => $item['district'] ?? '',
        'address' => $item['address'] ?? '',
        'road' => $item['road'] ?? '',
        'price' => $item['price'] ?? '',
        'priceNumber' => $item['priceNumber'] ?? 0,
        'type' => $item['type'] ?? '',
        'layout' => $item['layout'] ?? '',
        'landArea' => $item['landArea'] ?? '',
        'build' => $item['build'] ?? '',
        'status' => $item['status'] ?? '',
        'listedDate' => $item['listedDate'] ?? ($item['listedAt'] ?? ''),
        'image' => $src,
        'photoCount' => photoCount($item),
    ];
    $out['images'] = $src === '' ? [] : [['name' => '照片01', 'data' => $src]];
    return $out;
}

if (file_exists($cacheFile) && file_exists($dataFile) && filemtime($cacheFile) >= filemtime($dataFile)) {
    serveFile($cacheFile);
}

if (!file_exists($dataFile)) {
    respond(['ok' => true, 'data' => ['peerDevelopmentItems' => []], 'total' => 0, 'withPhotos' => 0]);
}

$db = json_decode(file_get_contents($dataFile) ?: '{}', true);
if (!is_array($db)) $db = [];

$rows = parseList($db['peerDevelopmentItems'] ?? []);
$slim = [];
$withPhotos = 0;
$shards = [];
foreach ($rows as $item) {
    if (!is_array($item) || !keepPeerItem($item)) continue;
    $row = slimPeerItem($item);
    $srcs = peerAllImageSrcs($item);
    $row['photoCount'] = count($srcs);
    if ($row['image'] !== '') $withPhotos += 1;
    $slim[] = $row;
    if (!$srcs) continue;
    foreach (peerPhotoLookup($item) as $lookupId) {
        $shard = peerPhotoShardName($lookupId);
        if (!isset($shards[$shard])) $shards[$shard] = [];
        $shards[$shard][$lookupId] = $srcs;
    }
}

$cacheDir = dirname($cacheFile);
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
foreach ($shards as $name => $map) {
    @file_put_contents($cacheDir . '/' . $name, json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

$payload = json_encode([
    'ok' => true,
    'cachedAt' => date('c'),
    'data' => ['peerDevelopmentItems' => $slim],
    'total' => count($slim),
    'withPhotos' => $withPhotos,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if (is_dir($cacheDir)) @file_put_contents($cacheFile, $payload);
@file_put_contents($publicCacheFile, $payload);

header('X-Peer-Cache: miss');
echo $payload;
