<?php
declare(strict_types=1);

/**
 * Logistics-only save for customer-shipping delivery notes.
 *
 * GET  /one-dollar-auction/ops-delivery-logistics.php?id=&delivery_no=
 * POST /one-dollar-auction/ops-delivery-logistics.php
 *   JSON: {id, delivery_no, tracking_no?, logistics_company?,
 *          logistics_delivery_status?, logistics_status_note?,
 *          logistics_checked_date?, shipping_status?, dry_run?}
 *
 * Does not call sales_out. Does not write products or stock_movements.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ops-api-auth.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ops-data-lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ops-delivery-logistics-lib.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
ops_api_require_login();

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'HEAD') $method = 'GET';

function ops_delivery_logistics_operator(): string
{
    foreach (['baohui_user', 'user', 'username', 'admin_user'] as $key) {
        $value = $_SESSION[$key] ?? '';
        if (is_array($value)) {
            foreach (['name', 'username', 'account', 'employee_name', 'id'] as $inner) {
                $text = trim((string)($value[$inner] ?? ''));
                if ($text !== '') return $text;
            }
            continue;
        }
        $text = trim((string)$value);
        if ($text !== '') return $text;
    }
    return 'unknown';
}

function ops_delivery_logistics_request_payload(string $method): array
{
    $payload = [];
    if ($method !== 'GET') {
        $raw = (string)file_get_contents('php://input');
        $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        $looksJson = $raw !== '' && ($raw[0] === '{' || $raw[0] === '[');
        if ($raw !== '' && (str_contains($ct, 'application/json') || $looksJson)) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                baohui_json_send(['ok' => false, 'error' => 'JSON 無法解析', 'rejected' => 'bad_json'], 400);
            }
            $payload = $decoded;
        } elseif (!empty($_POST) && is_array($_POST)) {
            $payload = $_POST;
        }
    }
    foreach (['id', 'delivery_no', 'dry_run'] as $key) {
        if (!isset($payload[$key]) || trim((string)$payload[$key]) === '') {
            if (isset($_GET[$key]) && trim((string)$_GET[$key]) !== '') {
                $payload[$key] = $_GET[$key];
            }
        }
    }
    return $payload;
}

function ops_delivery_logistics_fail(array $result): void
{
    $http = (int)($result['http'] ?? 400);
    unset($result['http'], $result['index'], $result['note']);
    $result['ok'] = false;
    baohui_json_send($result, $http > 0 ? $http : 400);
}

$payload = ops_delivery_logistics_request_payload($method);
$operator = ops_delivery_logistics_operator();

if ($method === 'GET') {
    $notes = read_data('delivery_notes');
    $found = ops_delivery_logistics_find_index(
        is_array($notes) ? $notes : [],
        (string)($payload['id'] ?? ''),
        (string)($payload['delivery_no'] ?? '')
    );
    if (empty($found['ok'])) {
        $found['http'] = ($found['rejected'] ?? '') === 'not_found' ? 404 : 400;
        ops_delivery_logistics_fail($found);
    }
    $note = $notes[(int)$found['index']] ?? [];
    baohui_json_send([
        'ok' => true,
        'id' => (string)($note['id'] ?? ''),
        'delivery_no' => (string)($note['delivery_no'] ?? ''),
        'operator' => $operator,
        'before' => ops_delivery_logistics_snapshot(is_array($note) ? $note : []),
        'after' => ops_delivery_logistics_snapshot(is_array($note) ? $note : []),
        'changed' => false,
        'dry_run' => true,
        'written' => [],
    ]);
}

if ($method !== 'POST' && $method !== 'PUT' && $method !== 'PATCH') {
    baohui_json_send(['ok' => false, 'error' => '只用 GET 讀取或 POST 回寫物流', 'rejected' => 'method'], 405);
}

$dryRun = ops_delivery_logistics_truthy($payload['dry_run'] ?? false);
$notes = read_data('delivery_notes');
if (!is_array($notes)) $notes = [];
$prepared = ops_delivery_logistics_prepare($notes, $payload, $operator);
if (empty($prepared['ok'])) ops_delivery_logistics_fail($prepared);

$written = [];
if (!$dryRun) {
    $notes[(int)$prepared['index']] = $prepared['note'];
    if (!write_data('delivery_notes', $notes)) {
        baohui_json_send(['ok' => false, 'error' => 'delivery_notes 寫入失敗', 'rejected' => 'write_failed'], 500);
    }
    $written[] = 'delivery_notes';
}

baohui_json_send([
    'ok' => true,
    'id' => $prepared['id'],
    'delivery_no' => $prepared['delivery_no'],
    'changed' => !empty($prepared['changed']),
    'operator' => $operator,
    'before' => $prepared['before'],
    'after' => $prepared['after'],
    'dry_run' => $dryRun,
    'written' => $written,
]);
