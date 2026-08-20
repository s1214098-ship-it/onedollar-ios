<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'task-progress-audit-lib.php';

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

function bh_task_audit_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$loggedIn = !empty($_SESSION['baohui_logged_in']);
$user = trim((string)($_SESSION['baohui_user'] ?? ''));
if (!$loggedIn || $user === '') {
    bh_task_audit_respond([
        'ok' => false,
        'error' => '請先登入寶輝後台',
        'loggedIn' => false,
    ], 401);
}

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));
$raw = (string)file_get_contents('php://input');
$input = $raw !== '' ? json_decode($raw, true) : $_POST;
if (!is_array($input)) $input = [];
if ($action === '') $action = trim((string)($input['action'] ?? 'audit'));

try {
    if ($action === 'snapshot') {
        bh_task_audit_respond([
            'ok' => true,
            'evidence' => bh_task_collect_evidence(),
            'user' => $user,
        ]);
    }

    if ($action === 'batch') {
        $tasks = $input['tasks'] ?? [];
        if (!is_array($tasks)) $tasks = [];
        $evidence = bh_task_collect_evidence();
        $audits = [];
        foreach ($tasks as $task) {
            if (!is_array($task)) continue;
            $id = (string)($task['id'] ?? '');
            if ($id === '') continue;
            $payload = bh_task_normalize_payload($task, $task);
            $logs = is_array($task['progressLogs'] ?? null) ? $task['progressLogs'] : [];
            $audit = bh_task_audit_report($task, $payload, $logs, $evidence, ['display' => true]);
            $audits[$id] = $audit;
        }
        bh_task_audit_respond([
            'ok' => true,
            'audits' => $audits,
            'evidence' => [
                'invoices' => $evidence['invoices'] ?? [],
                'products' => $evidence['products'] ?? [],
            ],
            'user' => $user,
        ]);
    }

    $task = is_array($input['task'] ?? null) ? $input['task'] : [];
    $payload = bh_task_normalize_payload($task, is_array($input['payload'] ?? null) ? $input['payload'] : $input);
    $logs = is_array($input['previousLogs'] ?? null) ? $input['previousLogs'] : (is_array($task['progressLogs'] ?? null) ? $task['progressLogs'] : []);
    $display = !empty($input['display']);
    $audit = bh_task_audit_report($task, $payload, $logs, null, ['display' => $display]);
    if (!$display) {
        $audit = bh_task_llm_refine($audit, $task, $payload);
    }
    if (!empty($audit['blocking'])) {
        bh_task_audit_respond([
            'ok' => false,
            'blocking' => true,
            'error' => $audit['summary'],
            'audit' => $audit,
        ], 422);
    }
    bh_task_audit_respond([
        'ok' => true,
        'audit' => $audit,
        'user' => $user,
    ]);
} catch (Throwable $e) {
    bh_task_audit_respond([
        'ok' => false,
        'error' => '進度核實失敗',
        'detail' => $e->getMessage(),
    ], 500);
}
