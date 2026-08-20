<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$dataFile = __DIR__ . '/../data/shared-db.json';

function respond($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

function mediaSrc($value): string {
    if (is_string($value)) return trim($value);
    if (is_array($value)) {
        foreach (['data', 'src', 'url', 'imageUrl', 'href'] as $key) {
            if (!empty($value[$key]) && is_string($value[$key])) return trim($value[$key]);
        }
    }
    return '';
}

function collectImageSrcs(array $item): array {
    $srcs = [];
    foreach (['images', 'photos', 'photoList', 'gallery'] as $key) {
        if (empty($item[$key])) continue;
        $value = $item[$key];
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }
        if (!is_array($value)) continue;
        foreach ($value as $img) {
            $src = mediaSrc($img);
            if ($src !== '' && !in_array($src, $srcs, true)) $srcs[] = $src;
        }
    }
    foreach (['image', 'imageUrl', 'photo', 'cover', 'thumb', 'thumbnail'] as $key) {
        $src = mediaSrc($item[$key] ?? '');
        if ($src !== '' && !in_array($src, $srcs, true)) $srcs[] = $src;
    }
    return $srcs;
}

function slimPeerItem(array $item): array {
    $srcs = collectImageSrcs($item);
    $covers = array_slice($srcs, 0, 3);
    $keep = [
        'id', 'externalId', 'publicNo', 'sourceSystem', 'sourceUrl', 'sourceHost',
        'sourceCompany', 'storeName', 'company', 'title', 'county', 'area', 'district',
        'address', 'road', 'price', 'priceNumber', 'type', 'spec', 'layout', 'landArea',
        'build', 'status', 'listedDate', 'listedAt', 'importRegion', 'unitPrice'
    ];
    $out = [];
    foreach ($keep as $key) {
        if (array_key_exists($key, $item)) $out[$key] = $item[$key];
    }
    $out['images'] = [];
    foreach ($covers as $i => $src) {
        $out['images'][] = [
            'name' => '照片' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT),
            'data' => $src,
        ];
    }
    $out['image'] = $covers[0] ?? '';
    $out['photoCount'] = count($srcs);
    return $out;
}

if (!file_exists($dataFile)) {
    respond(['ok' => true, 'data' => ['peerDevelopmentItems' => []], 'total' => 0, 'withPhotos' => 0]);
}

$db = json_decode(file_get_contents($dataFile) ?: '{}', true);
if (!is_array($db)) $db = [];

$rows = parseList($db['peerDevelopmentItems'] ?? []);
$slim = [];
$withPhotos = 0;
foreach ($rows as $item) {
    if (!is_array($item)) continue;
    $row = slimPeerItem($item);
    if (!empty($row['image'])) $withPhotos += 1;
    $slim[] = $row;
}

respond([
    'ok' => true,
    'data' => ['peerDevelopmentItems' => $slim],
    'total' => count($slim),
    'withPhotos' => $withPhotos,
]);
