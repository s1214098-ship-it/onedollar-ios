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

$js = file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'admin-task-progress.js');
expect($js !== false && $js !== '', 'overlay script exists');
expect(str_contains($js, '二次修正進度時間'), 'labels second revision time as reporter-proposed');
expect(str_contains($js, '回填進度時間'), 'asks for backfilled progress time');
expect(str_contains($js, '目前完成率'), 'asks for completion rate');
expect(str_contains($js, '目前做到哪裡'), 'requires current progress text');
expect(str_contains($js, 'auditPending') || str_contains($js, 'audit.pending') || str_contains($js, 'pending: true'), 'leaves audit hook for later');
expect(str_contains($js, '曾憲') || str_contains($js, '曾麒'), 'keeps 曾憲/曾麒 completion rate visible');

if ($failed > 0) {
    fwrite(STDERR, $failed . " assertion(s) failed\n");
    exit(1);
}
echo "all passed\n";
