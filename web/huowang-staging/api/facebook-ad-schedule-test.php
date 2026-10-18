<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Taipei');
require_once __DIR__ . '/facebook-listing-lib.php';

$failed = 0;
function expect($ok, string $message): void {
    global $failed;
    if ($ok) {
        echo "ok  $message\n";
        return;
    }
    $failed++;
    echo "FAIL $message\n";
}

$pages = fb_fan_pages();
expect(count($pages) === 3, 'three fan pages');
$ids = array_column($pages, 'id');
$accounts = array_column($pages, 'account');
expect($ids === ['GUOHUOWANG', 'NONGSHE', 'GUOHUOWANGFAN'], 'page ids GUOHUOWANG / NONGSHE / GUOHUOWANGFAN');
expect($accounts[0] === '郭火旺（個人）', 'page 1 郭火旺（個人）');
expect($accounts[1] === '羅東透天農舍 郭火旺（粉絲團）', 'page 2 農舍郭火旺');
expect($accounts[2] === '郭火旺（粉絲團）', 'page 3 郭火旺（粉絲團）');
expect(count(array_unique($accounts)) === 3, 'three distinct accounts');

$policy = fb_ad_policy([]);
expect((int)$policy['personalPerPage'] === 10, 'personal 10 per page');
expect((int)$policy['regionsPerHour'] === 1, 'one post per region per hour');
expect((int)$policy['regionCount'] === 4 && (int)$policy['regionJobsPerHour'] === 4, '北中南東 four slots per hour');
expect((int)$policy['hourStart'] === 7 && (int)$policy['hourEnd'] === 21, '07:00-21:00 window');

$listing = function (string $id, string $city, string $district): array {
    return [
        'id' => $id,
        'title' => $id . ' 測試物件',
        'city' => $city,
        'district' => $district,
        'kind' => '農舍',
        'type' => '農舍',
        'floor' => '1 / 2樓',
        'houseAge' => '5',
        'areaText' => '建 40坪',
        'mainBuildArea' => '40',
        'build' => '40',
        'landArea' => '200',
        'totalArea' => '240',
        'price' => '1280萬',
        'unitPrice' => '32萬/坪',
        'layout' => '4房2廳3衛',
        'summaryZh' => '測試文案',
        'adText' => '',
        'sourceUrl' => 'https://buy.yungching.com.tw/house/' . $id,
        'imageUrls' => ['https://example.com/' . $id . '.jpg'],
        'storeName' => '永慶不動產羅東文化盛群加盟店',
    ];
};
$items = [];
for ($i = 1; $i <= 24; $i++) {
    $items[] = $listing('CASE' . $i, '宜蘭縣', '羅東鎮');
}
$shared = ['sameStoreItems' => $items];
$groups = [[
    'id' => 'GRP-YILAN-TEST',
    'name' => '宜蘭房屋買賣交流',
    'url' => 'https://www.facebook.com/groups/yilan-test/',
    'areas' => ['宜蘭縣', '羅東鎮'],
    'account' => '郭火旺（個人）',
    'enabled' => true,
]];
$publish = [
    'facebookPublishJobs' => [],
    'facebookPublishSchedules' => [],
    'facebookGroupRegistry' => $groups,
];
$target = date('Y-m-d', strtotime('+1 day'));
$bundle = fb_generate_today_bundle($shared, $publish, $target, false);
$result = $bundle['result'];
$jobs = fb_rows($bundle['publish']['facebookPublishJobs'] ?? []);
expect($result['ok'] === true, 'generate_today ok');
expect((int)$result['createdJobs']['personal'] === 30, '三團個人合計 30');
expect((int)$result['createdJobs']['NONGSHE_personal'] === 10, '農舍郭火旺 personal 10');
expect((int)$result['createdJobs']['GUOHUOWANGFAN_personal'] === 10, '郭火旺粉絲團 personal 10');
expect((int)$result['createdJobs']['GUOHUOWANG_personal'] === 10, '郭火旺個人 personal 10');

$hours = 21 - 7 + 1;
expect((int)$result['createdJobs']['NONGSHE_regions'] === $hours * 4, "農舍郭火旺 北中南東 {$hours}h × 4");
expect((int)$result['createdJobs']['GUOHUOWANGFAN_regions'] === $hours * 4, "郭火旺粉絲團 北中南東 {$hours}h × 4");
expect((int)$result['createdJobs']['GUOHUOWANG_regions'] === $hours * 4, "郭火旺個人 北中南東 {$hours}h × 4");

$nongsheNorth = array_values(array_filter($jobs, fn($job) => ($job['pageId'] ?? '') === 'NONGSHE' && ($job['lane'] ?? '') === 'north'));
expect(count($nongsheNorth) === $hours, '農舍郭火旺 北部 hourly count');
if ($nongsheNorth) {
    $sorted = array_map(fn($job) => strtotime($job['scheduledAt']), $nongsheNorth);
    sort($sorted);
    $gaps = [];
    for ($i = 1; $i < count($sorted); $i++) {
        $gaps[] = $sorted[$i] - $sorted[$i - 1];
    }
    expect($gaps && min($gaps) === 3600 && max($gaps) === 3600, '北部 jobs are hourly');
}

$unverified = array_values(array_filter($jobs, fn($job) => fb_unverified_account(fb_text($job['account'] ?? ''))));
expect($unverified === [], 'all generated accounts are allowed');

expect(!fb_unverified_account('郭火旺（粉絲團）'), 'third 郭火旺 fan page is allowed');
expect(fb_unverified_account('待核對帳號'), 'unverified label still blocked');

if ($failed) {
    echo "\n$failed failed\n";
    exit(1);
}
echo "\nall passed\n";
exit(0);
