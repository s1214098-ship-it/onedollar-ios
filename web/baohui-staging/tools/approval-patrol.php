<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'approval-patrol-lib.php';

$sqlite = $argv[1] ?? '';
if ($sqlite === '') {
    $candidates = [
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'baohui.sqlite',
        'F:\\Web\\baohui-staging\\data\\baohui.sqlite',
    ];
    foreach ($candidates as $path) {
        if (is_file($path)) { $sqlite = $path; break; }
    }
}
if ($sqlite === '' || !is_file($sqlite)) {
    fwrite(STDERR, "usage: php approval-patrol.php [baohui.sqlite]\n");
    exit(1);
}

$statePath = dirname($sqlite) . DIRECTORY_SEPARATOR . 'approval-patrol-state.json';
$data = baohui_approval_read_app_data($sqlite);
$snap = baohui_approval_snapshot($data);
$prev = baohui_approval_load_state($statePath);
$newIds = baohui_approval_new_ids($snap['ids'], $prev['ids']);
$reminder = baohui_approval_reminder($snap, $newIds);
baohui_approval_save_state($statePath, $snap['ids']);

$out = [
    'ok' => true,
    'sqlite' => $sqlite,
    'pending' => $snap['pending'],
    'leaves' => count($snap['leaves']),
    'reports' => count($snap['reports']),
    'extensions' => count($snap['extensions']),
    'newCount' => $reminder['newCount'],
    'needed' => $reminder['needed'],
    'subject' => $reminder['subject'],
    'text' => $reminder['text'],
];
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
