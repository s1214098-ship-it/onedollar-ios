<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require __DIR__ . '/peer-photo-lib.php';

function respond($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$ids = [];
foreach (['id', 'externalId', 'key'] as $field) {
    $value = trim((string)($_GET[$field] ?? ''));
    if ($value !== '') $ids[] = $value;
}
$ids = array_values(array_unique($ids));
if (!$ids) {
    respond(['ok' => false, 'images' => [], 'error' => 'missing id'], 400);
}

$cacheDir = peerPhotoCacheDir();
$images = [];
foreach ($ids as $id) {
    $file = $cacheDir . '/' . peerPhotoShardName($id);
    if (!is_file($file)) continue;
    $map = json_decode((string)file_get_contents($file), true);
    if (!is_array($map)) continue;
    if (!empty($map[$id]) && is_array($map[$id])) {
        $images = $map[$id];
        break;
    }
}

respond([
    'ok' => true,
    'id' => $ids[0],
    'images' => array_values(array_filter(array_map('strval', $images))),
    'count' => count($images),
]);
