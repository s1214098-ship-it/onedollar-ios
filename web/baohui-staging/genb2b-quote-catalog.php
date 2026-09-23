<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'genb2b-quote-lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ops-json-response.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
session_name('BAOHUI_ADMIN');
ini_set('session.gc_maxlifetime', '2592000');
session_set_cookie_params(['lifetime' => 2592000, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
session_start();

if (empty($_SESSION['baohui_logged_in']) || trim((string)($_SESSION['baohui_user'] ?? '')) === '') {
    baohui_json_send(['ok' => false, 'error' => '請先登入寶輝後台'], 401);
}

try {
    $result = genb2b_quote_search((string)($_GET['q'] ?? ''), !empty($_GET['refresh']));
    baohui_json_send($result, 200);
} catch (InvalidArgumentException $e) {
    baohui_json_send(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    baohui_json_send(['ok' => false, 'error' => '捷元公開商品目前讀取失敗，請稍後再試'], 502);
}

