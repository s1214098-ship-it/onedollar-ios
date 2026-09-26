<?php
declare(strict_types=1);

function baohui_approval_status(array $row, string $fallback = ''): string
{
    return trim((string)($row['status'] ?? $fallback));
}

function baohui_approval_pending_leaves(array $data): array
{
    $out = [];
    foreach ($data['leaves'] ?? [] as $row) {
        if (!is_array($row)) continue;
        if (baohui_approval_status($row, '已登錄') === '待審核') $out[] = $row;
    }
    return array_values($out);
}

function baohui_approval_pending_reports(array $data): array
{
    $out = [];
    foreach ($data['taskReports'] ?? [] as $row) {
        if (!is_array($row)) continue;
        $status = baohui_approval_status($row, '待處理');
        if ($status === '待處理') $out[] = $row;
    }
    return array_values($out);
}

function baohui_approval_pending_extensions(array $data): array
{
    $out = [];
    foreach ($data['tasks'] ?? [] as $row) {
        if (!is_array($row)) continue;
        if (baohui_approval_status($row) === '完成') continue;
        if (trim((string)($row['extensionStatus'] ?? '')) === '待審核') $out[] = $row;
    }
    return array_values($out);
}

function baohui_approval_row_id(array $row, string $prefix): string
{
    $id = trim((string)($row['id'] ?? ''));
    if ($id !== '') return $prefix . ':' . $id;
    $bits = [
        $prefix,
        (string)($row['emp'] ?? $row['assign'] ?? ''),
        (string)($row['createdAt'] ?? $row['date'] ?? $row['name'] ?? ''),
    ];
    return implode(':', $bits);
}

function baohui_approval_snapshot(array $data): array
{
    $leaves = baohui_approval_pending_leaves($data);
    $reports = baohui_approval_pending_reports($data);
    $extensions = baohui_approval_pending_extensions($data);
    $ids = [];
    foreach ($leaves as $row) $ids[] = baohui_approval_row_id($row, 'leave');
    foreach ($reports as $row) $ids[] = baohui_approval_row_id($row, 'report');
    foreach ($extensions as $row) $ids[] = baohui_approval_row_id($row, 'task');
    return [
        'leaves' => $leaves,
        'reports' => $reports,
        'extensions' => $extensions,
        'ids' => $ids,
        'pending' => count($leaves) + count($reports) + count($extensions),
    ];
}

function baohui_approval_new_ids(array $currentIds, array $previousIds): array
{
    $prev = array_fill_keys($previousIds, true);
    $out = [];
    foreach ($currentIds as $id) {
        if (!isset($prev[$id])) $out[] = $id;
    }
    return $out;
}

function baohui_approval_leave_line(array $row): string
{
    $start = (string)($row['startDate'] ?? $row['date'] ?? '-');
    $end = (string)($row['endDate'] ?? $row['date'] ?? $start);
    $hours = (string)($row['hours'] ?? '');
    $memo = trim((string)($row['memo'] ?? ''));
    $line = trim((string)($row['emp'] ?? '-')) . '｜' . trim((string)($row['type'] ?? '-')) . '｜' . $start . '～' . $end;
    if ($hours !== '') $line .= '｜' . $hours;
    if ($memo !== '') $line .= '｜' . $memo;
    return $line;
}

function baohui_approval_report_line(array $row): string
{
    $who = trim((string)($row['assign'] ?? '-'));
    $task = trim((string)($row['taskName'] ?? $row['name'] ?? '-'));
    $type = trim((string)($row['type'] ?? ''));
    $detail = trim((string)($row['detail'] ?? ''));
    if (mb_strlen($detail, 'UTF-8') > 40) $detail = mb_substr($detail, 0, 40, 'UTF-8') . '…';
    $line = $who . '｜' . $task;
    if ($type !== '') $line .= '｜' . $type;
    if ($detail !== '') $line .= '｜' . $detail;
    return $line;
}

function baohui_approval_task_line(array $row): string
{
    $who = trim((string)($row['assign'] ?? '-'));
    $name = trim((string)($row['name'] ?? '-'));
    $when = trim((string)($row['proposedCompleteAt'] ?? ''));
    $line = $who . '｜' . $name . '｜第二次完成時間待核准';
    if ($when !== '') $line .= '｜' . $when;
    return $line;
}

function baohui_approval_reminder(array $snapshot, array $newIds = []): array
{
    $pending = (int)($snapshot['pending'] ?? 0);
    $leaves = $snapshot['leaves'] ?? [];
    $reports = $snapshot['reports'] ?? [];
    $extensions = $snapshot['extensions'] ?? [];
    $new = array_fill_keys($newIds, true);
    $newCount = 0;
    foreach ($snapshot['ids'] ?? [] as $id) {
        if (isset($new[$id])) $newCount++;
    }

    if ($pending === 0) {
        return [
            'needed' => false,
            'subject' => '寶輝後台巡視：目前沒有待核准請假或工作紀錄',
            'text' => "目前沒有待審核請假、待處理工作回報或待核准第二次完成時間。\n控制台：https://baohui.paohui.org/admin.php#dashboard",
            'pending' => 0,
            'newCount' => 0,
        ];
    }

    $lines = ['請打開控制台決定核准與否。'];
    $lines[] = '控制台：https://baohui.paohui.org/admin.php#dashboard';
    if ($newCount > 0) $lines[] = '這次新出現 ' . $newCount . ' 筆。';
    $lines[] = '待審核請假 ' . count($leaves) . ' 筆；待處理工作回報 ' . count($reports) . ' 筆；待核准第二次完成時間 ' . count($extensions) . ' 筆。';
    if ($leaves) {
        $lines[] = '';
        $lines[] = '【請假】';
        foreach ($leaves as $row) $lines[] = '- ' . baohui_approval_leave_line($row);
    }
    if ($reports) {
        $lines[] = '';
        $lines[] = '【工作回報】';
        foreach (array_slice($reports, 0, 12) as $row) $lines[] = '- ' . baohui_approval_report_line($row);
        if (count($reports) > 12) $lines[] = '- …另有 ' . (count($reports) - 12) . ' 筆';
    }
    if ($extensions) {
        $lines[] = '';
        $lines[] = '【工作任務第二次時間】';
        foreach ($extensions as $row) $lines[] = '- ' . baohui_approval_task_line($row);
    }

    $subject = '寶輝後台待核准：請假 ' . count($leaves) . '／工作回報 ' . count($reports);
    if ($newCount > 0) $subject = '寶輝後台新增待核准 ' . $newCount . ' 筆（請假 ' . count($leaves) . '／工作 ' . count($reports) . '）';

    return [
        'needed' => true,
        'subject' => $subject,
        'text' => implode("\n", $lines),
        'pending' => $pending,
        'newCount' => $newCount,
    ];
}

function baohui_approval_load_state(string $path): array
{
    if (!is_file($path)) return ['ids' => [], 'at' => ''];
    $raw = json_decode((string)file_get_contents($path), true);
    if (!is_array($raw)) return ['ids' => [], 'at' => ''];
    $ids = [];
    foreach ($raw['ids'] ?? [] as $id) {
        if (is_string($id) && $id !== '') $ids[] = $id;
    }
    return ['ids' => $ids, 'at' => (string)($raw['at'] ?? '')];
}

function baohui_approval_save_state(string $path, array $ids): void
{
    $dir = dirname($path);
    if ($dir !== '' && $dir !== '.' && !is_dir($dir)) @mkdir($dir, 0777, true);
    file_put_contents(
        $path,
        json_encode(['at' => date('c'), 'ids' => array_values($ids)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function baohui_approval_read_app_data(string $sqlitePath): array
{
    if (!is_file($sqlitePath)) throw new RuntimeException('找不到資料庫：' . $sqlitePath);
    $json = '';
    $drivers = class_exists('PDO') ? PDO::getAvailableDrivers() : [];
    if (in_array('sqlite', $drivers, true)) {
        $pdo = new PDO('sqlite:' . $sqlitePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $row = $pdo->query('SELECT json_data FROM app_data WHERE id = 1')->fetch();
        if (!is_array($row)) throw new RuntimeException('資料庫沒有 app_data');
        $json = (string)($row['json_data'] ?? '');
    } else {
        $cmd = 'python3 -c ' . escapeshellarg(
            "import sqlite3,sys; c=sqlite3.connect(sys.argv[1]); print(c.execute('select json_data from app_data where id=1').fetchone()[0])"
        ) . ' ' . escapeshellarg($sqlitePath);
        $json = (string)shell_exec($cmd);
    }
    $data = json_decode($json, true);
    if (!is_array($data)) throw new RuntimeException('app_data JSON 無法解析');
    return $data;
}
