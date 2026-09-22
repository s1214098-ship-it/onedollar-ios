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

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'schedule-lifecycle-lib.php';

$base = [
    'id' => 'sch_retry',
    'product_id' => 'FP2P33907',
    'listing_source' => 'codex',
    'post_url' => '',
    'facebook_worker_status' => 'pending_review',
    'publish_status' => '上架未確認',
    'facebook_worker_error' => 'admin_confirmed_not_posted',
    'close_at' => '2026-09-23 23:59',
    'scheduled_publish_at' => '2026-09-23 15:59',
];
expect(schedule_lifecycle_can_manual_retry($base) === true, 'admin_confirmed_not_posted pending_review can retry');

$timeout = $base;
$timeout['facebook_worker_error'] = '處理逾時，結果待核對；禁止自動重送';
expect(schedule_lifecycle_can_manual_retry($timeout) === true, 'timeout before submit can retry');
expect(schedule_lifecycle_can_manual_retry($timeout, ['status' => 'pending_review', 'audit' => []]) === true, 'timeout task without submissionStartedAt can retry');

$submitted = $timeout;
expect(schedule_lifecycle_can_manual_retry($submitted, [
    'status' => 'pending_review',
    'audit' => ['submissionStartedAt' => '2026-09-21T15:00:00+08:00'],
    'result' => ['failureReason' => '處理逾時，結果待核對；禁止自動重送'],
]) === false, 'timeout after Facebook submit is not auto-retried');

$hasUrl = $base;
$hasUrl['post_url'] = 'https://www.facebook.com/groups/onecheep/posts/4033343033465955/';
expect(schedule_lifecycle_can_manual_retry($hasUrl) === false, 'real permalink is not retried');

$scheduled = $base;
$scheduled['facebook_worker_status'] = 'facebook_scheduled';
$scheduled['publish_status'] = '已排入 Facebook 預約';
$scheduled['facebook_worker_error'] = '';
expect(schedule_lifecycle_can_manual_retry($scheduled) === false, 'already Facebook-scheduled is not retried');

$unlisted = $base;
$unlisted['facebook_worker_status'] = 'unlisted_manual_review';
$unlisted['auto_relist_blocked'] = '1';
$unlisted['facebook_worker_error'] = 'Facebook 分頁載入後 10 秒仍未接手任務';
expect(schedule_lifecycle_can_manual_retry($unlisted) === true, 'unlisted no-url hold can be manually retried');

schedule_lifecycle_clear_manual_retry_hold($timeout);
expect(($timeout['facebook_worker_status'] ?? '') === 'queued', 'clear hold sets worker queued');
expect(($timeout['publish_status'] ?? '') === '準備中', 'clear hold returns 上架未確認 to 準備中');
expect(($timeout['auto_relist_blocked'] ?? '') === '', 'clear hold drops auto_relist_blocked');

if ($failed > 0) {
    fwrite(STDERR, "$failed failed\n");
    exit(1);
}
echo "all schedule retry tests passed\n";
