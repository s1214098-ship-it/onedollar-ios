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

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'facebook-daily-report.php';

expect(facebook_daily_ymd('2026-09-21 13:40') === '2026-09-21', 'naive Taipei wall clock stays on 9/21');
expect(facebook_daily_ymd('2026-09-22T03:50:11+08:00') === '2026-09-22', 'ISO Taipei overnight stamp is 9/22');
expect(facebook_daily_ymd('2026-09-21T19:50:11+00:00') === '2026-09-22', 'UTC evening converts to Taipei next calendar day');
expect(facebook_daily_date('2026-09-21') === '2026-09-21', 'explicit fb_day is kept');

$comb = [
    'id' => 'sch_fp2',
    'product_id' => 'FP2P33907',
    'product_title' => '可愛加厚梳子',
    'scheduled_publish_at' => '2026-09-21 13:40',
    'close_at' => '2026-09-21 23:59',
    'actual_publish_at' => '2026-09-22T03:50:11+08:00',
    'publish_status' => 'Facebook 已上架／人工找網址',
    'facebook_worker_status' => 'facebook_posted_url_missing',
    'manual_post_url_required' => '1',
    'post_url' => '',
    'listing_source' => 'codex',
];
expect(facebook_daily_recordable($comb) === true, 'url-missing 9/21 lot is still recordable');
expect(facebook_daily_report_date($comb) === '2026-09-21', 'overnight worker stamp does not move 梳子 off 9/21');

$g4900 = $comb;
$g4900['product_id'] = 'SING12P50';
$g4900['product_title'] = 'INTEL G4900(S)';
$g4900['scheduled_publish_at'] = '2026-09-21 13:35';
expect(facebook_daily_report_date($g4900) === '2026-09-21', 'G4900 overnight stamp stays on 9/21');

$sameDay = [
    'scheduled_publish_at' => '2026-09-21 17:55',
    'actual_publish_at' => '2026-09-21 18:07',
    'close_at' => '2026-09-21 23:59',
    'facebook_worker_status' => 'facebook_scheduled',
    'publish_status' => '已排入 Facebook 預約',
    'post_url' => 'https://www.facebook.com/groups/onecheep/posts/4033343033465955/',
];
expect(facebook_daily_report_date($sameDay) === '2026-09-21', 'same-day actual still uses scheduled 9/21');

$early = [
    'scheduled_publish_at' => '2026-09-22 00:10',
    'actual_publish_at' => '2026-09-21 23:50',
];
expect(facebook_daily_report_date($early) === '2026-09-21', 'posted earlier than reservation keeps actual day');

$noSchedule = [
    'actual_publish_at' => '2026-09-22T03:50:11+08:00',
];
expect(facebook_daily_report_date($noSchedule) === '2026-09-22', 'no reservation uses actual publish day');

$native = [
    'scheduled_publish_at' => '2026-09-23 13:15',
    'facebook_worker_status' => 'facebook_scheduled',
    'publish_status' => '已排入 Facebook 預約',
    'post_url' => 'https://www.facebook.com/groups/onecheep/posts/111/',
];
expect(facebook_daily_report_date($native) === '2026-09-23', 'native reservation without actual uses scheduled day');

$screenshotLots = [
    ['scheduled_publish_at' => '2026-09-21 13:15', 'actual_publish_at' => '2026-09-22T03:50:11+08:00', 'close_at' => '2026-09-21 23:59', 'product_id' => 'GOO014P2891', 'product_title' => '利民TF7 2G導熱硅脂', 'facebook_worker_status' => 'facebook_posted_url_missing', 'manual_post_url_required' => '1', 'post_url' => '', 'publish_status' => 'Facebook 已上架／人工找網址'],
    ['scheduled_publish_at' => '2026-09-21 13:20', 'actual_publish_at' => '2026-09-22T03:50:11+08:00', 'close_at' => '2026-09-21 23:59', 'product_id' => 'CAB002P219100', 'product_title' => '10條3米4K超清HDMI', 'facebook_worker_status' => 'facebook_posted_url_missing', 'manual_post_url_required' => '1', 'post_url' => '', 'publish_status' => 'Facebook 已上架／人工找網址'],
    ['scheduled_publish_at' => '2026-09-21 13:25', 'actual_publish_at' => '2026-09-22T03:50:11+08:00', 'close_at' => '2026-09-21 23:59', 'product_id' => 'ELE022P209900', 'product_title' => '加粗240W四合一硅膠數據線', 'facebook_worker_status' => 'facebook_posted_url_missing', 'manual_post_url_required' => '1', 'post_url' => '', 'publish_status' => 'Facebook 已上架／人工找網址'],
    ['scheduled_publish_at' => '2026-09-21 13:30', 'actual_publish_at' => '2026-09-22T03:50:11+08:00', 'close_at' => '2026-09-21 23:59', 'product_id' => 'SE233P359100', 'product_title' => '強磁十合一德式', 'facebook_worker_status' => 'facebook_posted_url_missing', 'manual_post_url_required' => '1', 'post_url' => '', 'publish_status' => 'Facebook 已上架／人工找網址'],
    ['scheduled_publish_at' => '2026-09-21 13:35', 'actual_publish_at' => '2026-09-22T03:50:11+08:00', 'close_at' => '2026-09-21 23:59', 'product_id' => 'SING12P50', 'product_title' => 'INTEL G4900(S)', 'facebook_worker_status' => 'facebook_posted_url_missing', 'manual_post_url_required' => '1', 'post_url' => '', 'publish_status' => 'Facebook 已上架／人工找網址'],
    ['scheduled_publish_at' => '2026-09-21 13:40', 'actual_publish_at' => '2026-09-22T03:50:11+08:00', 'close_at' => '2026-09-21 23:59', 'product_id' => 'FP2P33907', 'product_title' => '可愛加厚梳子', 'facebook_worker_status' => 'facebook_posted_url_missing', 'manual_post_url_required' => '1', 'post_url' => '', 'publish_status' => 'Facebook 已上架／人工找網址'],
    ['scheduled_publish_at' => '2026-09-22 13:15', 'actual_publish_at' => '', 'close_at' => '2026-09-22 23:59', 'product_id' => 'OTHER', 'product_title' => '隔日場', 'facebook_worker_status' => 'facebook_scheduled', 'post_url' => 'https://www.facebook.com/groups/onecheep/posts/999/', 'publish_status' => '已排入 Facebook 預約'],
];

$dateCounts = [];
foreach ($screenshotLots as $lot) {
    if (!facebook_daily_recordable($lot)) continue;
    $day = facebook_daily_report_date($lot);
    if ($day !== '') $dateCounts[$day] = ($dateCounts[$day] ?? 0) + 1;
}
expect(($dateCounts['2026-09-21'] ?? 0) === 6, '有資料日期 builds 9/21 with all six Facebook group lots');
expect(($dateCounts['2026-09-22'] ?? 0) === 1, '9/22 keeps only the next-day reservation');

$rows = facebook_daily_collect($screenshotLots, [], [], '2026-09-21');
$ids = array_column($rows, 'product_id');
expect(count($rows) === 6, 'collect puts the six 9/21 lots on the 9/21 daily report');
expect(in_array('FP2P33907', $ids, true), '梳子 is on 9/21');
expect(in_array('SING12P50', $ids, true), 'G4900 is on 9/21');
expect(in_array('SE233P359100', $ids, true), '強磁 is on 9/21');
expect(in_array('ELE022P209900', $ids, true), '矽膠數據線 is on 9/21');
expect(in_array('CAB002P219100', $ids, true), 'HDMI is on 9/21');
expect(in_array('GOO014P2891', $ids, true), 'TF7 is on 9/21');
expect(in_array('OTHER', $ids, true) === false, 'next-day lot stays off 9/21');

$publishDayRows = array_values(array_filter($rows, static fn($row) => !empty($row['in_publish_day'])));
expect(count($publishDayRows) === 6, '9/21 lots still count as publish-day rows');

$nextDay = facebook_daily_collect($screenshotLots, [], [], '2026-09-22');
$nextIds = array_column($nextDay, 'product_id');
expect($nextIds === ['OTHER'], 'overnight-stamped 9/21 lots no longer pollute 9/22');

$combNoWinner = $comb;
$combNoWinner['winner'] = '';
$combNoWinner['winning_price'] = 0;
expect(facebook_daily_can_mark_unsold($combNoWinner) === true, 'url-missing closed 梳子 can be marked 流標');
expect(facebook_daily_can_mark_unsold($g4900 + ['winner' => '', 'winning_price' => 0]) === true, 'url-missing closed G4900 can be marked 流標');

$won = $screenshotLots[0];
$won['winner'] = '蘇文敏';
$won['winning_price'] = 30;
expect(facebook_daily_can_mark_unsold($won) === false, 'lot with a winner is not 流標');

$alreadyUnsold = $combNoWinner;
$alreadyUnsold['auction_result'] = 'unsold';
$alreadyUnsold['order_status'] = '流標區保留';
expect(facebook_daily_can_mark_unsold($alreadyUnsold) === false, 'already-unsold lot is not marked again');

$openLot = $combNoWinner;
$openLot['close_at'] = '2026-09-22 23:59';
expect(facebook_daily_can_mark_unsold($openLot) === false, 'not-yet-closed lot cannot 流標');

$unsoldRows = facebook_daily_collect([
    $combNoWinner,
    $g4900 + ['winner' => '', 'winning_price' => 0, 'close_at' => '2026-09-21 23:59'],
    $won,
], [], [], '2026-09-21');
$unsoldable = array_values(array_filter($unsoldRows, static fn($row) => !empty($row['can_mark_unsold'])));
$unsoldableIds = array_column($unsoldable, 'product_id');
expect(in_array('FP2P33907', $unsoldableIds, true), '9/21 daily report offers 確認流標 for 梳子');
expect(in_array('SING12P50', $unsoldableIds, true), '9/21 daily report offers 確認流標 for G4900');
$compare = facebook_daily_compare($unsoldRows, '2026-09-21');
expect((int)($compare['need_unsold'] ?? 0) >= 2, 'compare counts pending 流標 lots');

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'schedule-lifecycle-lib.php';

$staffFuture = [
    'id' => 'sch_manual_future',
    'listing_source' => 'staff',
    'created_by' => '小姐 Facebook 人工預約',
    'product_id' => 'STAFF001',
    'product_title' => '小姐人工排程測試',
    'post_url' => 'https://www.facebook.com/groups/onecheep/posts/4033343033465955/',
    'facebook_worker_status' => 'manual_facebook_scheduled',
    'publish_status' => '已排程',
    'facebook_publish_completed' => '0',
    'scheduled_publish_at' => '2026-09-23 15:59',
    'actual_publish_at' => '2026-09-22 07:00',
    'close_at' => '2026-09-23 23:59',
];
expect(facebook_daily_has_real_post_url($staffFuture) === true, '小姐 reservation with permalink has a real URL');
expect(facebook_daily_recordable($staffFuture) === true, '小姐 reservation with permalink is filed into 當日報 immediately');
expect(facebook_daily_publish_completed($staffFuture) === true, '人工排程 with permalink counts as archived/completed');
expect(facebook_daily_report_date($staffFuture) === '2026-09-23', '小姐 reservation files onto the Facebook public day, not the capture day');
expect(facebook_daily_native_scheduled($staffFuture) === true, '小姐 Facebook 預約 is a native scheduled listing');

$staffRows = facebook_daily_collect([$staffFuture], [], [], '2026-09-23');
expect(count($staffRows) === 1, '9/23 當日報 includes the 小姐 lot with a link');
expect(($staffRows[0]['need_manual_publish'] ?? true) === false, 'lot with a permalink is not still waiting for 人工上架');
expect(str_contains((string)($staffRows[0]['publish_reason'] ?? ''), '已歸檔') === true, 'reason says archived to daily report');

$captureDay = facebook_daily_collect([$staffFuture], [], [], '2026-09-22');
expect($captureDay === [], 'permalink capture day does not steal the 小姐 reservation from its public day');

$noUrlStaff = $staffFuture;
$noUrlStaff['post_url'] = '';
$noUrlStaff['facebook_worker_status'] = 'manual_waiting';
$noUrlStaff['publish_status'] = '未上架';
expect(facebook_daily_recordable($noUrlStaff) === false, '人工排程 without a permalink stays out of 當日報');

$archived = $staffFuture;
expect(schedule_lifecycle_archive_manual_listing_to_daily_report($archived) === true, 'lifecycle archives 人工 lot once it has a permalink');
expect(($archived['facebook_daily_archived'] ?? '') === '1', 'archive flag is set');
expect(($archived['facebook_publish_completed'] ?? '') === '1', 'archive marks publish completed');
expect(schedule_lifecycle_archive_manual_listing_to_daily_report($archived) === false, 'already-archived 人工 lot is not rewritten');

if ($failed > 0) {
    fwrite(STDERR, "$failed failed\n");
    exit(1);
}
echo "all facebook daily date tests passed\n";
