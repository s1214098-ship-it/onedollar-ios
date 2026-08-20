<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'quote-builder-lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ops-json-response.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

session_name('BAOHUI_ADMIN');
ini_set('session.gc_maxlifetime', '86400');
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function quote_builder_respond(array $payload, int $status = 200): void
{
    baohui_json_send($payload, $status);
}

if (empty($_SESSION['baohui_logged_in']) || trim((string)($_SESSION['baohui_user'] ?? '')) === '') {
    quote_builder_respond(['ok' => false, 'error' => '請先登入寶輝後台'], 401);
}

try {
    $catalog = quote_builder_catalog();
    quote_builder_respond([
        'ok' => true,
        'user' => (string)$_SESSION['baohui_user'],
        'productCount' => $catalog['productCount'],
        'slots' => $catalog['slots'],
        'extra' => $catalog['extra'],
    ]);
} catch (Throwable $e) {
    quote_builder_respond(['ok' => false, 'error' => '無法讀取產品分類'], 500);
}
