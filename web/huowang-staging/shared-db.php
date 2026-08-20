<?php
declare(strict_types=1);

@ini_set('memory_limit', '512M');

session_name('HUOMANGE_ADMIN');
$sessionLifetime = 86400;
ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
if (!empty($_SESSION['admin_logged_in'])) {
    $expiresAt = (int)($_SESSION['admin_expires_at'] ?? 0);
    if ($expiresAt <= 0) {
        $expiresAt = time() + $sessionLifetime;
        $_SESSION['admin_expires_at'] = $expiresAt;
    }
    if ($expiresAt <= time()) {
        $_SESSION = [];
        session_destroy();
    } else {
        setcookie(session_name(), session_id(), ['expires'=>$expiresAt,'path'=>'/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Lax']);
    }
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$dataFile = __DIR__ . '/../data/shared-db.json';
$publicKeys = ['properties', 'sameStoreItems', 'borrowItems', 'layouts', 'types', 'agentInfo'];

function respond($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cacheDir(string $dataFile): string {
    return dirname($dataFile) . '/cache';
}

function clearReadCaches(string $dataFile): void {
    $dir = cacheDir($dataFile);
    if (!is_dir($dir)) return;
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $name = basename($file);
        if (strpos($name, 'photos-') === 0 || $name === 'peer-list.json') continue;
        @unlink($file);
    }
}

function keyCacheFile(string $dataFile, string $kind, array $keys): string {
    return cacheDir($dataFile) . '/' . $kind . '-' . md5(implode(',', $keys)) . '.json';
}

function serveJsonCache(string $dataFile, string $cacheFile, $builder): void {
    if (file_exists($cacheFile) && filemtime($cacheFile) >= filemtime($dataFile)) {
        header('X-Db-Cache: hit');
        readfile($cacheFile);
        exit;
    }
    $payload = json_encode($builder(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $dir = dirname($cacheFile);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (is_dir($dir)) @file_put_contents($cacheFile, $payload);
    header('X-Db-Cache: miss');
    echo $payload;
    exit;
}

function readDb(string $dataFile): array {
    if (!file_exists($dataFile)) {
        return [];
    }
    $json = file_get_contents($dataFile);
    $data = json_decode($json ?: '{}', true);
    $data = is_array($data) ? $data : [];
    return stripBlockedListingsFromDb($data);
}

function isAdminLoggedIn(): bool {
    return !empty($_SESSION['admin_logged_in']);
}

function pickDbKeys(array $db, array $keys): array {
    $out = [];
    foreach ($keys as $key) {
        if (array_key_exists($key, $db)) $out[$key] = $db[$key];
    }
    return $out;
}

function publicDb(array $db, array $publicKeys, array $requestedKeys = []): array {
    if ($requestedKeys) {
        $publicKeys = array_values(array_intersect($publicKeys, $requestedKeys));
    }
    return pickDbKeys($db, $publicKeys);
}

function blockedListingKeys(): array {
    return [
        'YCUT-1012453',
        'd70563f5-e6cb-4563-a3b9-b99ceb78bd5f',
        '羅東旁阿嬤ㄟ厝靜巷超值透天',
    ];
}

function isBlockedListing($item): bool {
    if (!is_array($item)) return false;
    $blob = implode(' ', [
        (string)($item['id'] ?? ''),
        (string)($item['publicNo'] ?? ''),
        (string)($item['externalId'] ?? ''),
        (string)($item['contractNo'] ?? ''),
        (string)($item['sourceUrl'] ?? ''),
        (string)($item['title'] ?? ''),
        (string)($item['showCaseNo'] ?? ''),
        (string)($item['caseName'] ?? ''),
    ]);
    foreach (blockedListingKeys() as $key) {
        if ($key !== '' && strpos($blob, $key) !== false) return true;
    }
    return false;
}

function stripBlockedListingsFromValue($value) {
    $wasString = is_string($value);
    $rows = $value;
    if ($wasString) {
        $decoded = json_decode($value ?: '[]', true);
        if (!is_array($decoded)) return $value;
        $rows = $decoded;
    }
    if (!is_array($rows) || $rows === [] || !array_key_exists(0, $rows)) {
        return $value;
    }
    $kept = [];
    $changed = false;
    foreach ($rows as $item) {
        if (is_array($item) && isBlockedListing($item)) {
            $changed = true;
            continue;
        }
        $kept[] = $item;
    }
    if (!$changed) return $value;
    return $wasString ? json_encode($kept, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $kept;
}

function stripBlockedListingsFromDb(array $db): array {
    foreach (['properties', 'borrowItems', 'sameStoreItems', 'peerDevelopmentItems', 'targets', 'storeDevelopmentItems'] as $key) {
        if (array_key_exists($key, $db)) {
            $db[$key] = stripBlockedListingsFromValue($db[$key]);
        }
    }
    return $db;
}

function writeDb(string $dataFile, array $data): void {
    $dir = dirname($dataFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $fp = fopen($dataFile, 'c+');
    if (!$fp) {
        respond(['ok' => false, 'error' => 'Cannot open data/shared-db.json. Check PHT-SR write permission.'], 500);
    }

    flock($fp, LOCK_EX);
    $data = stripBlockedListingsFromDb($data);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    clearReadCaches($dataFile);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $requestedKeys = array_values(array_filter(array_map('trim', explode(',', (string)($_GET['keys'] ?? '')))));
    if (($_GET['public'] ?? '') === '1') {
        serveJsonCache($dataFile, keyCacheFile($dataFile, 'public', $requestedKeys ?: $publicKeys), function () use ($dataFile, $publicKeys, $requestedKeys) {
            $db = readDb($dataFile);
            return ['ok' => true, 'public' => true, 'data' => publicDb($db, $publicKeys, $requestedKeys)];
        });
    }
    if (!isAdminLoggedIn()) {
        respond(['ok' => false, 'error' => '尚未登入，不能讀取後台資料。'], 401);
    }
    if ($requestedKeys) {
        serveJsonCache($dataFile, keyCacheFile($dataFile, 'admin', $requestedKeys), function () use ($dataFile, $requestedKeys) {
            $db = readDb($dataFile);
            return ['ok' => true, 'data' => pickDbKeys($db, $requestedKeys)];
        });
    }
    $db = readDb($dataFile);
    respond(['ok' => true, 'data' => $db]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

if (!isAdminLoggedIn()) {
    respond(['ok' => false, 'error' => '尚未登入，不能寫入後台資料。'], 401);
}

$body = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($body)) {
    respond(['ok' => false, 'error' => 'Invalid JSON.'], 400);
}

$action = (string)($body['action'] ?? 'set');
$db = readDb($dataFile);

if ($action === 'bulk') {
    $items = $body['items'] ?? [];
    if (!is_array($items)) {
        respond(['ok' => false, 'error' => 'Invalid items.'], 400);
    }
    foreach ($items as $key => $value) {
        $db[(string)$key] = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    writeDb($dataFile, $db);
    respond(['ok' => true, 'savedKeys' => array_keys($items)]);
}

$key = isset($body['key']) ? (string)$body['key'] : '';

if ($action === 'remove') {
    unset($db[$key]);
    writeDb($dataFile, $db);
    respond(['ok' => true]);
}

if ($action === 'clear') {
    writeDb($dataFile, []);
    respond(['ok' => true]);
}

if ($key === '') {
    respond(['ok' => false, 'error' => 'Missing key.'], 400);
}

$db[$key] = isset($body['value']) ? (string)$body['value'] : '';
writeDb($dataFile, $db);
respond(['ok' => true, 'savedKeys' => [$key]]);
