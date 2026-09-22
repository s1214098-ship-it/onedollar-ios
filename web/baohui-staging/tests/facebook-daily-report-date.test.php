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
expect(count($publishDayRows) === 6, 'ops in_publish_day filter still keeps the six 9/21 lots');

$nextDay = facebook_daily_collect($screenshotLots, [], [], '2026-09-22');
$nextIds = array_column($nextDay, 'product_id');
expect($nextIds === ['OTHER'], 'overnight-stamped 9/21 lots no longer pollute 9/22');

if ($failed > 0) {
    fwrite(STDERR, "$failed failed\n");
    exit(1);
}
echo "all facebook daily date tests passed\n";
