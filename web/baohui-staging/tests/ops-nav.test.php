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

$ops = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'operations.php');

expect(str_contains($ops, "id = 'ops-pending-tab-style'"), 'head script injects pending-tab style before overview HTML');
expect(str_contains($ops, "html[data-ops-pending-tab] #overview.ops-tab{display:none!important}"), 'pending tab hides overview during page parse');
expect(str_contains($ops, "sessionStorage.setItem('baohuiOpsTab'"), 'openOpsTab remembers the working section');
expect(str_contains($ops, 'input[name="ops_tab"]'), 'form submit injects hidden ops_tab');
expect(str_contains($ops, "\$_POST['ops_tab']"), 'POST restores the working section on the server');
expect(str_contains($ops, 'window.resolveOpsTab'), 'permission guard can resolve in-page hashes to the parent tab');
expect(str_contains($ops, "rememberedOpsTab() || 'overview'"), 'empty hash does not dump the user to overview');
expect(!preg_match('/hashchange[^\n]+#overview\'\)\.slice\(1\)/', $ops), 'hashchange no longer hard-falls back to overview');

if ($failed > 0) {
    fwrite(STDERR, $failed . " assertion(s) failed\n");
    exit(1);
}
echo "all passed\n";
