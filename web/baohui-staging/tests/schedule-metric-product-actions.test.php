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

$patcher = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'patch-live-schedule-metric-actions-ops.py';
expect(is_file($patcher), 'metric dialog action patcher exists');
$src = (string)file_get_contents($patcher);
expect(str_contains($src, 'schedule-metric-pick'), 'dialog rows get a product checkbox');
expect(str_contains($src, '編輯勾選產品'), 'dialog can edit selected products');
expect(str_contains($src, '刪除勾選排程'), 'dialog can delete selected schedules');
expect(str_contains($src, '刪除勾選產品'), 'dialog can delete selected products');
expect(str_contains($src, 'operations.php?edit_product='), 'each row links to product editor');
expect(str_contains($src, 'unsold-pick'), '流標管理 also gets product checkboxes');
expect(str_contains($src, 'unsoldEditSelected'), '流標管理 can edit selected products');

$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'schedule-metric-actions-' . bin2hex(random_bytes(3));
mkdir($tmpDir, 0775, true);
$fixture = $tmpDir . DIRECTORY_SEPARATOR . 'ops.php';
$cmd = 'python3 -c ' . escapeshellarg(
    "from pathlib import Path\n"
    . "import importlib.util\n"
    . "spec=importlib.util.spec_from_file_location('patcher', r" . var_export($patcher, true) . ")\n"
    . "mod=importlib.util.module_from_spec(spec); spec.loader.exec_module(mod)\n"
    . "text=''.join(old for old,_ in mod.PATCHES)\n"
    . "Path(r" . var_export($fixture, true) . ").write_text(text, encoding='utf-8')\n"
    . "updated=mod.apply(text)\n"
    . "Path(r" . var_export($fixture, true) . ").write_text(updated, encoding='utf-8')\n"
    . "print('patched')\n"
);
exec($cmd, $out, $code);
expect($code === 0, 'patcher applies cleanly to its own needles');
$updated = is_file($fixture) ? (string)file_get_contents($fixture) : '';
expect(str_contains($updated, 'class="schedule-metric-pick"'), 'patched dialog rows include pick checkboxes');
expect(str_contains($updated, '編輯產品'), 'patched dialog rows include 編輯產品');
expect(str_contains($updated, 'bindScheduleMetricProductActions'), 'patched page binds dialog select/edit/delete');
expect(str_contains($updated, 'id="unsoldEditSelected"'), 'patched 流標管理 can edit selected products');
expect(!str_contains($updated, 'grid-template-columns:58px minmax(180px,1fr) minmax(150px,.8fr) auto'), 'old metric row grid is replaced');

foreach ([$fixture] as $file) {
    if (is_file($file)) @unlink($file);
}
@rmdir($tmpDir);

if ($failed > 0) {
    fwrite(STDERR, "$failed failed\n");
    exit(1);
}
echo "all schedule metric product action tests passed\n";
