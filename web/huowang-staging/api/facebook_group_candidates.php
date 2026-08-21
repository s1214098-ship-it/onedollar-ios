<?php
date_default_timezone_set('Asia/Taipei');
session_name('HUOMANGE_ADMIN');
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Headers: Content-Type, X-Facebook-Worker-Key');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$dataFile = __DIR__ . '/../data/facebook-group-candidates.json';

function fb_groups_read($file): array {
  if (!is_file($file)) return ['settings' => [], 'items' => []];
  $data = json_decode(file_get_contents($file) ?: '{}', true);
  return is_array($data) ? $data : ['settings' => [], 'items' => []];
}

function fb_groups_write($file, array $data): bool {
  $dir = dirname($file);
  if (!is_dir($dir) && !mkdir($dir, 0775, true)) return false;
  $tmp = $file . '.tmp';
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  return file_put_contents($tmp, $json, LOCK_EX) !== false && rename($tmp, $file);
}

function fb_groups_text($value): string { return trim((string)($value ?? '')); }
function fb_groups_id($value): string { return preg_replace('/[^A-Za-z0-9_-]/', '', fb_groups_text($value)); }

$data = fb_groups_read($dataFile);
$data['settings'] = is_array($data['settings'] ?? null) ? $data['settings'] : [];
$data['items'] = is_array($data['items'] ?? null) ? array_values($data['items']) : [];

if ($_SERVER['REQUEST_METHOD'] === 'GET' && fb_groups_text($_GET['action'] ?? '') === 'claim') {
  $key = fb_groups_text($_GET['key'] ?? ($_SERVER['HTTP_X_FACEBOOK_WORKER_KEY'] ?? ''));
  $expected = fb_groups_text($data['settings']['workerKey'] ?? '');
  if ($expected === '' || !hash_equals($expected, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '執行器金鑰不正確'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $today = date('Y-m-d');
  $runCount = (($data['settings']['runDate'] ?? '') === $today) ? (int)($data['settings']['runCount'] ?? 0) : 0;
  $limit = max(1, min(3, (int)($data['settings']['dailyLimit'] ?? 1)));
  if (empty($data['settings']['autoJoinEnabled']) || $runCount >= $limit || date('H:i') < ($data['settings']['runAt'] ?? '10:30')) {
    echo json_encode(['ok' => true, 'job' => null], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $job = null;
  foreach ($data['items'] as &$item) {
    if (($item['status'] ?? '') !== 'auto_queued' || ($item['joinability'] ?? '') !== 'direct') continue;
    $item['status'] = 'processing';
    $item['claimedAt'] = date('c');
    $item['updatedAt'] = date('c');
    $job = $item;
    break;
  }
  unset($item);
  if ($job) {
    $data['updatedAt'] = date('c');
    fb_groups_write($dataFile, $data);
  }
  echo json_encode(['ok' => true, 'job' => $job], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '請先登入主後台'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  echo json_encode(['ok' => true, 'settings' => $data['settings'], 'items' => $data['items'], 'updatedAt' => $data['updatedAt'] ?? ''], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
  exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'JSON格式錯誤'], JSON_UNESCAPED_UNICODE);
  exit;
}

$action = fb_groups_text($payload['action'] ?? '');
if ($action !== 'worker_report' && empty($_SESSION['admin_logged_in'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'message' => '請先登入主後台'], JSON_UNESCAPED_UNICODE);
  exit;
}
if ($action === 'save_settings') {
  $limit = max(1, min(3, (int)($payload['dailyLimit'] ?? 1)));
  $data['settings'] = array_merge($data['settings'], [
    'autoJoinEnabled' => !empty($payload['autoJoinEnabled']),
    'runAt' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', fb_groups_text($payload['runAt'] ?? '')) ? fb_groups_text($payload['runAt']) : '10:30',
    'dailyLimit' => $limit,
    'directOnly' => true,
  ]);
} elseif ($action === 'set_status') {
  $id = fb_groups_id($payload['id'] ?? '');
  $status = fb_groups_text($payload['status'] ?? 'candidate');
  $allowed = ['candidate', 'auto_queued', 'processing', 'pending', 'joined', 'needs_manual', 'ignored'];
  if ($id === '' || !in_array($status, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => '社團或狀態不正確'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $found = false;
  foreach ($data['items'] as &$item) {
    if (fb_groups_id($item['id'] ?? '') !== $id) continue;
    if ($status === 'auto_queued' && fb_groups_text($item['joinability'] ?? '') !== 'direct') {
      http_response_code(409);
      echo json_encode(['ok' => false, 'message' => '只有可直接加入的社團能排入自動佇列'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $item['status'] = $status;
    $item['updatedAt'] = date('c');
    $found = true;
    break;
  }
  unset($item);
  if (!$found) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => '找不到社團'], JSON_UNESCAPED_UNICODE);
    exit;
  }
} elseif ($action === 'worker_report') {
  $key = fb_groups_text($payload['key'] ?? ($_SERVER['HTTP_X_FACEBOOK_WORKER_KEY'] ?? ''));
  $expected = fb_groups_text($data['settings']['workerKey'] ?? '');
  if ($expected === '' || !hash_equals($expected, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '執行器金鑰不正確'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $id = fb_groups_id($payload['id'] ?? '');
  $result = fb_groups_text($payload['result'] ?? 'failed');
  $map = [
    'joined' => 'joined',
    'pending' => 'pending',
    'needs_manual' => 'needs_manual',
    'failed' => 'candidate',
    'candidate' => 'candidate',
  ];
  $mapped = $map[$result] ?? 'candidate';
  $found = false;
  foreach ($data['items'] as &$item) {
    if (fb_groups_id($item['id'] ?? '') !== $id) continue;
    $item['status'] = $mapped;
    $item['note'] = fb_groups_text($payload['message'] ?? $item['note'] ?? '');
    $item['updatedAt'] = date('c');
    $found = true;
    break;
  }
  unset($item);
  if (!$found) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => '找不到執行中的社團'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if ($result === 'pending' || $result === 'joined') {
    $today = date('Y-m-d');
    $data['settings']['runCount'] = (($data['settings']['runDate'] ?? '') === $today) ? ((int)($data['settings']['runCount'] ?? 0) + 1) : 1;
    $data['settings']['runDate'] = $today;
  }
} elseif ($action === 'upsert') {
  $url = fb_groups_text($payload['url'] ?? '');
  $id = fb_groups_id($payload['id'] ?? '');
  if ($id === '' && preg_match('~/groups/([^/?#]+)~', $url, $match)) $id = fb_groups_id($match[1]);
  if ($id === '' || $url === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => '缺少社團網址'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $next = [
    'id' => $id,
    'name' => fb_groups_text($payload['name'] ?? ''),
    'url' => $url,
    'counties' => array_values(array_filter(array_map('fb_groups_text', is_array($payload['counties'] ?? null) ? $payload['counties'] : []))),
    'category' => fb_groups_text($payload['category'] ?? '房屋土地'),
    'account' => fb_groups_text($payload['account'] ?? '羅東透天農舍 郭火旺（粉絲團）'),
    'joinability' => in_array(fb_groups_text($payload['joinability'] ?? ''), ['direct', 'questions', 'unknown'], true) ? fb_groups_text($payload['joinability']) : 'unknown',
    'status' => 'candidate',
    'note' => fb_groups_text($payload['note'] ?? ''),
    'updatedAt' => date('c'),
  ];
  $found = false;
  foreach ($data['items'] as &$item) {
    if (fb_groups_id($item['id'] ?? '') !== $id) continue;
    $next['status'] = $item['status'] ?? 'candidate';
    $next['createdAt'] = $item['createdAt'] ?? date('c');
    $item = array_merge($item, $next);
    $found = true;
    break;
  }
  unset($item);
  if (!$found) {
    $next['createdAt'] = date('c');
    $data['items'][] = $next;
  }
} else {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => '不支援的操作'], JSON_UNESCAPED_UNICODE);
  exit;
}

$data['updatedAt'] = date('c');
if (!fb_groups_write($dataFile, $data)) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => '寫入社團加入設定失敗'], JSON_UNESCAPED_UNICODE);
  exit;
}

echo json_encode(['ok' => true, 'settings' => $data['settings'], 'items' => $data['items'], 'updatedAt' => $data['updatedAt']], JSON_UNESCAPED_UNICODE);
?>
