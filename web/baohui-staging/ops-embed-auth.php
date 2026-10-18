<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ops-embed-auth-lib.php';

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

function baohui_ops_auth_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$loggedIn = !empty($_SESSION['baohui_logged_in']);
$user = trim((string)($_SESSION['baohui_user'] ?? ''));
$isAdmin = !empty($_SESSION['baohui_is_admin']);
$role = trim((string)($_SESSION['baohui_role'] ?? ''));
if ($role === '') $role = $isAdmin ? '最高管理員' : '';

if (!$loggedIn || $user === '') {
    baohui_ops_auth_respond([
        'ok' => false,
        'error' => '請先登入寶輝後台',
        'loggedIn' => false,
    ], 401);
}

try {
    $ticket = baohui_ops_issue_embed_ticket([
        'user' => $user,
        'isAdmin' => $isAdmin,
        'role' => $role,
    ]);
} catch (Throwable $e) {
    baohui_ops_auth_respond([
        'ok' => false,
        'error' => '無法建立庫存作業登入票',
        'loggedIn' => true,
    ], 500);
}

baohui_ops_auth_respond([
    'ok' => true,
    'ticket' => $ticket,
    'user' => $user,
    'isAdmin' => $isAdmin,
    'role' => $role,
    'ttl' => BAOHUI_OPS_TICKET_TTL_SECONDS,
]);
