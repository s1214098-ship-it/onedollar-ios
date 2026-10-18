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
$openLot['close_at'] = '2099-12-31 23:59';
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
expect(facebook_daily_archived_as_settled($archived) === false, 'permalink archive without a winner is not 得標結算');

$se269 = [
    'id' => 'sch_26092216170221656d',
    'product_id' => 'SE269P869700',
    'product_title' => '秋葉原2026款金屬筆記本【一標1個】',
    'listing_source' => 'staff',
    'created_by' => '曾麒',
    'status' => '已上架',
    'publish_status' => '已上架',
    'facebook_daily_archived' => '1',
    'facebook_publish_completed' => '1',
    'facebook_worker_status' => 'published',
    'winner' => '',
    'winning_price' => 0,
    'current_bid' => 30,
    'order_status' => '待記單',
    'post_url' => 'https://www.facebook.com/groups/onecheep/permalink/4039681576165434',
    'scheduled_publish_at' => '2026-09-22 17:00',
    'actual_publish_at' => '2026-09-22 16:33',
    'close_at' => '2026-09-22 23:59',
];
$life040 = $se269;
$life040['id'] = 'sch_260922171118b91bc6';
$life040['product_id'] = 'LIFE040P999100';
$life040['product_title'] = '保鮮盒黑色【一標1盒】';
$life040['post_url'] = 'https://www.facebook.com/groups/onecheep/permalink/4039718009495124';
$ele019 = $se269;
$ele019['id'] = 'sch_2609221717443f0d1c';
$ele019['product_id'] = 'ELE019P27092';
$ele019['product_title'] = '塗鴉智能WIFI面板燈【一標1件】';
$ele019['post_url'] = 'https://www.facebook.com/groups/onecheep/permalink/4039722149494710';

foreach ([$se269, $life040, $ele019] as $shotLot) {
    $pid = (string)$shotLot['product_id'];
    expect(facebook_daily_archived_as_settled($shotLot) === false, $pid . ' archived listing is not 已轉得標結算');
    expect(facebook_daily_has_winner($shotLot) === false, $pid . ' has no winner yet');
    expect(facebook_daily_can_mark_unsold($shotLot) === true, $pid . ' can still 流標 after close');
    expect(facebook_daily_closed($shotLot) === true, $pid . ' already closed 9/22 23:59');
}

$shotRows = facebook_daily_collect([$se269, $life040, $ele019], [], [], '2026-09-22');
expect(count($shotRows) === 3, '9/22 當日報 still lists the three screenshot lots');
foreach ($shotRows as $shotRow) {
    $pid = (string)($shotRow['product_id'] ?? '');
    expect(empty($shotRow['archived_as_settled']), $pid . ' daily row is not compact 已轉得標結算');
    expect(!empty($shotRow['need_winner_record']), $pid . ' still needs 結標 / 補得標人');
    expect(!empty($shotRow['can_mark_unsold']), $pid . ' daily row still offers 確認流標');
}

$wonSettled = $se269;
$wonSettled['winner'] = '蘇文敏';
$wonSettled['winning_price'] = 130;
$wonSettled['settlement_ready'] = '1';
expect(facebook_daily_archived_as_settled($wonSettled) === true, 'winner + 得標金額 is 已轉得標結算');
$wonRows = facebook_daily_collect([$wonSettled], [], [], '2026-09-22');
expect(!empty($wonRows[0]['archived_as_settled']), 'settled winner row uses compact 已轉得標結算');
expect(empty($wonRows[0]['need_winner_record']), 'settled winner does not still ask for 結標');

$unsoldArchived = $se269;
$unsoldArchived['auction_result'] = 'unsold';
$unsoldArchived['order_status'] = '流標區保留';
expect(facebook_daily_archived_as_settled($unsoldArchived) === false, '流標 archive is not 得標結算');

if ($failed > 0) {
    fwrite(STDERR, "$failed failed\n");
    exit(1);
}
echo "all facebook daily date tests passed\n";
