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

    if ($action === 'split') {
        $names = $input['names'] ?? [];
        if (!is_array($names)) $names = [];
        $names = array_values(array_filter(array_map(static fn($v) => trim((string)$v), $names), static fn($v) => $v !== ''));
        $boardId = trim((string)($input['boardId'] ?? ''));
        $evidence = bh_task_collect_evidence();
        $board = is_array($evidence['board'] ?? null) ? $evidence['board'] : bh_task_progress_board();
        $row = null;
        foreach (($board['rows'] ?? []) as $candidate) {
            if (!is_array($candidate)) continue;
            if ($boardId !== '' && (string)($candidate['id'] ?? '') === $boardId) {
                $row = $candidate;
                break;
            }
        }
        if ($row === null && !empty($board['rows'][0]) && is_array($board['rows'][0])) {
            $row = $board['rows'][0];
        }
        if ($row === null || !$names) {
            bh_task_audit_respond([
                'ok' => false,
                'error' => '請選擇進度表項目，並勾選要等分的人員',
                'board' => $board,
            ], 422);
        }
        $shares = bh_task_equal_split((int)($row['remaining'] ?? 0), count($names));
        $out = [];
        foreach ($names as $i => $name) {
            $qty = (int)($shares[$i] ?? 0);
            $out[] = [
                'assign' => $name,
                'shareQty' => $qty,
                'name' => ((string)($row['title'] ?? '工作')) . '（等分 ' . ($i + 1) . '/' . count($names) . '）',
                'desc' => bh_task_share_note($row, $qty, count($names), $i),
                'boardId' => (string)($row['id'] ?? ''),
                'leftoverKind' => (string)($row['leftoverKind'] ?? ''),
                'leftover' => $row['leftover'] ?? [],
                'boardDone' => (int)($row['done'] ?? 0),
                'boardTotal' => (int)($row['total'] ?? 0),
                'boardRate' => (int)($row['rate'] ?? 0),
                'unit' => (string)($row['unit'] ?? '筆'),
            ];
        }
        bh_task_audit_respond([
            'ok' => true,
            'board' => $board,
            'row' => $row,
            'shares' => $out,
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
                'board' => $evidence['board'] ?? [],
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
