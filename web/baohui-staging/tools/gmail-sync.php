<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

date_default_timezone_set('Asia/Taipei');
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'accounting-gmail-source.php';

$force = in_array('--force', $argv ?? [], true);
$lockPath = acc_private_root() . DIRECTORY_SEPARATOR . 'gmail-sync.lock';
$logPath = acc_private_root() . DIRECTORY_SEPARATOR . 'gmail-sync-schedule.log';

function acc_gmail_cli_log(string $path, array $payload): void
{
    $line = date('c') . ' ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

if (is_file($lockPath) && (time() - (int)filemtime($lockPath)) < 900) {
    acc_gmail_cli_log($logPath, ['ok' => true, 'skipped' => true, 'reason' => 'already_running']);
    exit(0);
}

$pdo = acc_db();
$status = acc_gmail_oauth_status();
if (empty($status['connected'])) {
    acc_gmail_cli_log($logPath, ['ok' => false, 'error' => 'Gmail 尚未連接']);
    exit(2);
}

$minutes = max(5, (int)($status['sync_minutes'] ?? ACC_DEFAULT_SYNC_MINUTES));
$last = $status['last_sync'] ?? [];
if (!$force && ($last['status'] ?? '') === 'ok') {
    $finished = strtotime((string)($last['finished_at'] ?? '')) ?: 0;
    if ($finished > 0 && (time() - $finished) < ($minutes * 60)) {
        acc_gmail_cli_log($logPath, [
            'ok' => true,
            'skipped' => true,
            'reason' => 'synced_recently',
            'minutes' => $minutes,
            'last_finished' => $last['finished_at'] ?? '',
        ]);
        exit(0);
    }
}

@file_put_contents($lockPath, (string)getmypid());
try {
    $result = acc_gmail_sync($pdo, 'schedule');
    acc_gmail_cli_log($logPath, ['ok' => true] + $result);
} catch (Throwable $e) {
    acc_gmail_cli_log($logPath, ['ok' => false, 'error' => $e->getMessage()]);
    exit(1);
} finally {
    if (is_file($lockPath)) @unlink($lockPath);
}
