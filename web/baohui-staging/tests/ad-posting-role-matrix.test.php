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

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ad-posting-role-matrix.php';

$matrix = baohui_ad_posting_role_matrix();
expect($matrix['auction_listing_identity'] === 'Kuo Feng Cheng', 'Kuo keeps auction listing lane');
expect($matrix['auction_reminder_identity'] === 'Kuo Feng Cheng', 'Kuo keeps bid-reminder lane');
expect($matrix['bid_close_identity'] === 'YUKI LIU', 'YUKI owns 通州截標');
expect(in_array('YUKI LIU', $matrix['ad_posting_identities'], true), 'YUKI can publish posts');
expect(in_array('海賊團行政專員', $matrix['ad_posting_identities'], true), '海賊團行政專員 can publish posts');
expect(in_array('寶輝科技官方網站 粉絲團', $matrix['ad_posting_identities'], true), 'official fan page can publish posts');
expect(!in_array('Kuo Feng Cheng', $matrix['ad_posting_identities'], true), 'Kuo stays out of general ads');
expect(baohui_ad_identity_may_publish_posts('寶輝科技有限公司 頭城竹安店'), 'historical 頭城竹安店 rows remain valid');
expect(baohui_ad_identity_canonical('KUO GFENG CHENG') === 'Kuo Feng Cheng', 'typed KUO GFENG CHENG aliases to Kuo Feng Cheng');

$helper = file_get_contents(dirname(__DIR__) . '/baohui-posting-helper/service-worker.js');
$page = file_get_contents(dirname(__DIR__) . '/admin-ad-posting.php');
$content = file_get_contents(dirname(__DIR__) . '/baohui-posting-helper/content.js');
$ops = file_get_contents(dirname(__DIR__) . '/one-dollar-auction/operations.php');

expect(str_contains($helper, "const BID_CLOSE_IDENTITY = 'YUKI LIU'"), 'helper bid-close identity is YUKI LIU');
expect(str_contains($helper, "const FAN_PAGE_IDENTITY = '寶輝科技官方網站 粉絲團'"), 'helper fan-page identity is official page');
expect(str_contains($helper, "'海賊團行政專員'"), 'helper still lists 海賊團行政專員');
expect(!str_contains($helper, "const AD_POSTING_IDENTITIES = ['寶輝科技有限公司 頭城竹安店', '海賊團行政專員']"), 'helper no longer uses only 頭城竹安店 + 海賊團行政專員');
expect(str_contains($content, "return BID_CLOSE_IDENTITY") || str_contains($content, "return 'YUKI LIU'"), 'content script routes 結標後產品推薦 to YUKI');
expect(str_contains($page, 'YUKI LIU'), 'adPosting page names YUKI LIU');
expect(str_contains($page, '寶輝科技官方網站 粉絲團'), 'adPosting page names official fan page');
expect(str_contains($page, '通州截標'), 'adPosting page names 通州截標');
expect(str_contains($ops, "setOpsStaffPermissionRole('close')"), 'ops keeps 截標人員 preset on facebook-daily');
expect(str_contains($ops, "close: ['overview','facebook-daily','settlement','settlement-edit']"), '截標人員 preset does not swallow Kuo schedule column');
expect(str_contains($ops, '通州截標'), 'facebook-daily work column is labeled 通州截標');

if ($failed > 0) {
    fwrite(STDERR, "$failed failed\n");
    exit(1);
}
echo "all ad-posting role-matrix tests passed\n";
