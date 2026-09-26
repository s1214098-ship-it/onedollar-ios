<?php
declare(strict_types=1);

$failed = 0;
function expect($ok, string $msg): void
{
    global $failed;
    if ($ok) {
        echo "ok  $msg\n";
        return;
    }
    $failed++;
    echo "FAIL  $msg\n";
}

$root = dirname(__DIR__);
require_once $root . DIRECTORY_SEPARATOR . 'approval-patrol-lib.php';

$js = (string)file_get_contents($root . DIRECTORY_SEPARATOR . 'admin-leave-dashboard.js');
expect($js !== '', 'leave dashboard overlay exists');
expect(str_contains($js, '員工請假表'), 'card title is 員工請假表');
expect(str_contains($js, 'leaveDashboardCard'), 'uses leaveDashboardCard id');
expect(str_contains($js, 'data-leave-approve'), 'approve from dashboard');
expect(str_contains($js, 'data-leave-reject'), 'reject from dashboard');
expect(str_contains($js, 'refreshDashboard'), 'hooks dashboard refresh');
expect(str_contains($js, 'taskDashboardCard'), 'inserts before 工作任務 card');

$data = [
    'leaves' => [
        ['id' => 'L1', 'emp' => '曾麒', 'type' => '事假', 'status' => '待審核', 'startDate' => '2026-09-26', 'endDate' => '2026-09-26', 'hours' => '8 小時', 'memo' => '家庭事務', 'createdAt' => '2026/9/26 09:00:00'],
        ['id' => 'L2', 'emp' => '李御榛', 'type' => '特休', 'status' => '已核准', 'startDate' => '2026-09-20', 'endDate' => '2026-09-20', 'hours' => '8 小時'],
    ],
    'taskReports' => [
        ['id' => 'R1', 'assign' => '李建宏', 'taskName' => '3F 整理', 'status' => '待處理', 'type' => '需要批准', 'detail' => '3樓還沒上去處理', 'createdAt' => '2026/8/19 10:24:47'],
        ['id' => 'R2', 'assign' => '曾麒', 'taskName' => '盤點', 'status' => '已核准'],
    ],
    'tasks' => [
        ['id' => 9, 'assign' => '李御榛', 'name' => '盤點機', 'status' => '處理中', 'extensionStatus' => '待審核', 'proposedCompleteAt' => '2026-09-27 18:00'],
        ['id' => 10, 'assign' => '曾麒', 'name' => '建檔', 'status' => '完成', 'extensionStatus' => '待審核'],
    ],
];

$snap = baohui_approval_snapshot($data);
expect(count($snap['leaves']) === 1 && $snap['leaves'][0]['id'] === 'L1', 'pending leave is 曾麒 事假');
expect(count($snap['reports']) === 1 && $snap['reports'][0]['id'] === 'R1', 'pending work report is 李建宏');
expect(count($snap['extensions']) === 1 && (int)$snap['extensions'][0]['id'] === 9, 'pending extension ignores completed tasks');
expect($snap['pending'] === 3, 'pending total is 3');

$new = baohui_approval_new_ids($snap['ids'], ['report:R1']);
expect(in_array('leave:L1', $new, true) && !in_array('report:R1', $new, true), 'new ids skip already seen reports');

$remind = baohui_approval_reminder($snap, $new);
expect($remind['needed'] === true, 'reminder needed when pending exists');
expect(str_contains($remind['text'], '請打開控制台決定核准與否'), 'asks to approve or not');
expect(str_contains($remind['text'], '曾麒｜事假'), 'lists pending leave');
expect(str_contains($remind['text'], '李建宏｜3F 整理'), 'lists pending work report');
expect(str_contains($remind['text'], '第二次完成時間待核准'), 'lists pending task extension');
expect(str_contains($remind['subject'], '新增待核准'), 'subject flags new items');

$clear = baohui_approval_reminder(baohui_approval_snapshot(['leaves' => [], 'taskReports' => [], 'tasks' => []]), []);
expect($clear['needed'] === false, 'no reminder when queue is empty');
expect(str_contains($clear['text'], '目前沒有待審核'), 'empty patrol says all clear');

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'leave-dash-' . bin2hex(random_bytes(3));
mkdir($tmp, 0777, true);
$admin = $tmp . DIRECTORY_SEPARATOR . 'admin.php';
file_put_contents($admin, "<script src=\"admin-main.js?v=test\" charset=\"UTF-8\"></script>\n<script src=\"admin-hide-codex-report.js?v=hide-codex-20260926\" charset=\"UTF-8\"></script>\n");
$patcher = $root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'patch-live-leave-dashboard-admin.php';
exec('php ' . escapeshellarg($patcher) . ' ' . escapeshellarg($admin) . ' leave-dashboard-test', $out, $code);
expect($code === 0, 'admin.php patcher exits 0');
$patched = (string)file_get_contents($admin);
expect(str_contains($patched, 'admin-leave-dashboard.js?v=leave-dashboard-test'), 'injects leave dashboard script');
expect(substr_count($patched, 'admin-leave-dashboard.js') === 1, 'injects once');

$sqlite = $tmp . DIRECTORY_SEPARATOR . 'baohui.sqlite';
$jsonFile = $tmp . DIRECTORY_SEPARATOR . 'seed.json';
file_put_contents($jsonFile, json_encode($data, JSON_UNESCAPED_UNICODE));
$create = 'python3 -c ' . escapeshellarg(
    "import sqlite3,json,sys; p,j=sys.argv[1],sys.argv[2]; d=json.load(open(j,encoding='utf-8')); c=sqlite3.connect(p); c.execute('create table app_data (id integer primary key, json_data text)'); c.execute('insert into app_data values (1,?)', (json.dumps(d,ensure_ascii=False),)); c.commit()"
) . ' ' . escapeshellarg($sqlite) . ' ' . escapeshellarg($jsonFile);
exec($create, $created, $createCode);
if ($createCode === 0 && is_file($sqlite)) {
    $cli = [];
    exec('php ' . escapeshellarg($root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'approval-patrol.php') . ' ' . escapeshellarg($sqlite), $cli, $cliCode);
    $payload = json_decode(implode("\n", $cli), true);
    expect($cliCode === 0 && is_array($payload), 'patrol CLI returns JSON');
    expect(!empty($payload['needed']) && (int)$payload['pending'] === 3, 'patrol CLI sees 3 pending');
    expect(is_file($tmp . DIRECTORY_SEPARATOR . 'approval-patrol-state.json'), 'patrol writes state beside sqlite');
} else {
    echo "skip  patrol CLI sqlite\n";
}

foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
@rmdir($tmp);

if ($failed > 0) {
    fwrite(STDERR, $failed . " assertion(s) failed\n");
    exit(1);
}
echo "all passed\n";
