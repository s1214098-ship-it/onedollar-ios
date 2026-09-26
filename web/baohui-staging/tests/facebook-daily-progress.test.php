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

$root = dirname(__DIR__);
require $root . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'facebook-daily-report.php';

$js = (string)file_get_contents($root . DIRECTORY_SEPARATOR . 'admin-facebook-daily-progress.js');
expect($js !== '', 'dashboard overlay exists');
expect(str_contains($js, '當日臉書上架進度表'), 'card title is 當日臉書上架進度表');
expect(str_contains($js, 'facebook-daily-progress.php'), 'loads lightweight progress JSON');
expect(str_contains($js, 'openOneDollarModule'), 'opens full 當日日報');
expect(str_contains($js, 'refreshDashboard'), 'hooks dashboard refresh');
expect(str_contains($js, 'leaveDashboardCard'), 'places card after 員工請假表');
expect(str_contains($js, 'fb-daily-product'), 'puts thumbnail on the product cell');
expect(str_contains($js, 'align-items:flex-start'), 'thumbnail sits top-left of the product');
expect(str_contains($js, 'fb-daily-thumb'), 'renders product thumbnails');
expect(str_contains($js, 'function money'), 'formats bid amount');

$endpoint = (string)file_get_contents($root . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'facebook-daily-progress.php');
expect(str_contains($endpoint, 'facebook_daily_progress_payload'), 'endpoint uses compact payload');
expect(str_contains($endpoint, 'baohui_ops_boot_hq_session'), 'endpoint boots HQ session');

$posted = [
    'id' => 'sch_ok',
    'product_id' => 'SE100',
    'product_title' => '已上架測試',
    'scheduled_publish_at' => '2026-09-26 10:00',
    'close_at' => '2026-09-26 23:59',
    'publish_status' => '已上架',
    'facebook_worker_status' => 'published',
    'facebook_publish_completed' => '1',
    'post_url' => 'https://www.facebook.com/groups/x/posts/123',
    'listing_source' => 'codex',
    'schedule_image' => 'uploads/products/SE100.png',
];
$needPost = [
    'id' => 'sch_todo',
    'product_id' => 'SE200',
    'product_title' => '待發文測試',
    'scheduled_publish_at' => '2026-09-26 00:01',
    'close_at' => '2026-09-26 23:59',
    'publish_status' => '已排入 Facebook 預約',
    'facebook_worker_status' => 'facebook_scheduled',
    'facebook_queue_status' => 'scheduled',
    'facebook_status' => 'scheduled_visible_no_url',
    'post_url' => '',
    'listing_source' => 'codex',
];
$products = [
    ['id' => 'SE200', 'title' => '待發文測試', 'image' => 'uploads/products/SE200.jpg'],
];
$payload = facebook_daily_progress_payload([$posted, $needPost], $products, [], '2026-09-26');
expect($payload['date'] === '2026-09-26', 'payload date is Taipei day');
expect($payload['planned'] >= 1, 'planned includes today lots');
expect(count($payload['rows']) === 2, 'two compact rows');
expect($payload['rows'][0]['product_id'] === 'SE200', 'open todos sort first');
expect(str_contains((string)$payload['rows'][0]['todo_text'], '發文'), 'pending lot todo includes 發文');
expect(str_contains((string)$payload['rows'][0]['image'], 'SE200.jpg'), 'pending lot uses product thumbnail');
expect(str_contains((string)$payload['rows'][1]['image'], 'SE100.png'), 'posted lot keeps schedule thumbnail');
expect(str_starts_with((string)$payload['rows'][0]['image'], '/one-dollar-auction/'), 'relative thumbnail is dashboard-safe');
expect(in_array('發文', facebook_daily_todo_labels(['need_post' => true]), true), 'need_post becomes 發文');
expect(facebook_daily_todo_labels(['posted' => true]) === [], 'done lot has no todos');

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fb-prog-' . bin2hex(random_bytes(3));
mkdir($tmp, 0777, true);
$admin = $tmp . DIRECTORY_SEPARATOR . 'admin.php';
file_put_contents($admin, "<script src=\"admin-leave-dashboard.js?v=leave-dashboard-20260926\" charset=\"UTF-8\"></script>\n");
$patcher = $root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'patch-live-facebook-daily-progress-admin.php';
exec('php ' . escapeshellarg($patcher) . ' ' . escapeshellarg($admin) . ' fb-daily-progress-test', $out, $code);
expect($code === 0, 'admin.php patcher exits 0');
$patched = (string)file_get_contents($admin);
expect(str_contains($patched, 'admin-facebook-daily-progress.js?v=fb-daily-progress-test'), 'injects progress overlay');
expect(substr_count($patched, 'admin-facebook-daily-progress.js') === 1, 'injects once');

foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
@rmdir($tmp);

if ($failed > 0) {
    fwrite(STDERR, $failed . " assertion(s) failed\n");
    exit(1);
}
echo "all passed\n";
