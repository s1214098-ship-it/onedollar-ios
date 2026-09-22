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

$heartbeat = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'baohui-helper-heartbeat-' . bin2hex(random_bytes(4)) . '.json';
putenv('BAOHUI_HELPER_HEARTBEAT_PATH=' . $heartbeat);
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'schedule-lifecycle-lib.php';

$queued = [
    'id' => 'FB-AUC-sch_cloud_1',
    'contentType' => 'facebook_auction_listing',
    'status' => 'queued',
    'publishMode' => 'instant',
    'audit' => ['manualFullBatchStartedAt' => '2026-09-22T06:33:00+00:00'],
];
expect(schedule_lifecycle_helper_may_claim_prepared_listing($queued) === true, 'cloud/啟動上架 queued instant listing is claimable on helper poll');

$future = $queued;
$future['publishMode'] = 'scheduled';
$future['audit']['cloudDispatchAt'] = '2026-09-22T06:33:00+00:00';
unset($future['audit']['manualFullBatchStartedAt']);
expect(schedule_lifecycle_helper_may_claim_prepared_listing($future) === true, 'cloud-dispatched scheduled listing is claimable without another click');

$idle = $queued;
$idle['audit'] = [];
expect(schedule_lifecycle_helper_may_claim_prepared_listing($idle) === false, 'unstarted listing stays off background poll');
expect(schedule_lifecycle_helper_may_claim_prepared_listing($idle, true) === true, 'operator 啟動上架 can still claim an unstarted listing');

$reminder = $queued;
$reminder['contentType'] = 'facebook_auction_reminder';
expect(schedule_lifecycle_helper_may_claim_prepared_listing($reminder) === false, 'price reminders are not treated as listing auto-claim');

$now = 1780000000;
$written = schedule_lifecycle_record_helper_heartbeat('1.10.229', 'batch-three-pass-v162', $now);
expect(($written['helper_version'] ?? '') === '1.10.229', 'heartbeat stores helper version');
$fresh = schedule_lifecycle_helper_status($now + 20);
expect(($fresh['connected'] ?? false) === true, 'helper poll within 3 minutes counts as connected');
$stale = schedule_lifecycle_helper_status($now + 400);
expect(($stale['connected'] ?? true) === false, 'helper poll older than 3 minutes is offline');
expect(($stale['stale'] ?? false) === true, 'offline after a previous poll is marked stale');

@unlink($heartbeat);

if ($failed > 0) {
    fwrite(STDERR, "$failed failed\n");
    exit(1);
}
echo "all facebook helper cloud-dispatch tests passed\n";
