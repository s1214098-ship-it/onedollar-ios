<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ops-api-auth.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ops-data-lib.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
ops_api_require_login();

$schedules = read_data('schedules');
$now = time();
$today = date('Y-m-d');
$horizon = $now + (3 * 86400);
$upcoming = 0;
$closingSoon = 0;
$unshippedBuyers = [];
$relist = 0;
$pendingToday = 0;
$postedToday = 0;
$online = 0;

foreach (is_array($schedules) ? $schedules : [] as $s) {
    if (!is_array($s)) continue;
    $publishAt = trim((string)($s['scheduled_publish_at'] ?? $s['publish_at'] ?? ''));
    $closeAt = trim((string)($s['close_remind_at'] ?? $s['close_at'] ?? ''));
    $publishTs = $publishAt !== '' ? strtotime($publishAt) : false;
    $closeTs = $closeAt !== '' ? strtotime($closeAt) : false;
    $publishStatus = trim((string)($s['publish_status'] ?? $s['status'] ?? ''));
    $orderStatus = trim((string)($s['order_status'] ?? ''));
    $shippingStatus = trim((string)($s['shipping_status'] ?? ''));
    $hasBuyer = trim((string)($s['winner'] ?? '')) !== ''
        || trim((string)($s['winner_facebook'] ?? '')) !== ''
        || trim((string)($s['winner_phone'] ?? '')) !== '';
    $actual = trim((string)($s['actual_publish_at'] ?? ''));

    if ($publishTs && $publishTs >= $now && $publishTs <= $horizon && !in_array($publishStatus, ['已上架', '上架成功'], true)) {
        $upcoming++;
    }
    if ($closeTs && $closeTs >= $now && $closeTs <= ($now + 86400)) {
        $closingSoon++;
    }
    if ($hasBuyer && !in_array($orderStatus, ['已出貨', '完成', '取消'], true) && $shippingStatus !== '已出貨') {
        $key = trim((string)($s['winner_phone'] ?? '')) . '|' . trim((string)($s['winner'] ?? '')) . '|' . trim((string)($s['winner_facebook'] ?? ''));
        if ($key !== '||') $unshippedBuyers[$key] = true;
    }
    if (
        (function_exists('mb_stripos') && (mb_stripos($orderStatus, '棄標') !== false || mb_stripos($orderStatus, '弃標') !== false || mb_stripos($orderStatus, '取消') !== false))
        || (!function_exists('mb_stripos') && (stripos($orderStatus, '棄標') !== false || stripos($orderStatus, '弃標') !== false || stripos($orderStatus, '取消') !== false))
    ) {
        $relist++;
    }
    if (substr($publishAt, 0, 10) === $today && !in_array($publishStatus, ['已上架', '上架成功'], true)) $pendingToday++;
    if (substr($actual, 0, 10) === $today || ($publishStatus === '已上架' && substr($publishAt, 0, 10) === $today)) $postedToday++;
    if ($publishStatus === '已上架' && $closeTs && $closeTs > $now) $online++;
}

baohui_json_send([
    'ok' => true,
    'time' => date('H:i:s'),
    'upcoming' => $upcoming,
    'closingSoon' => $closingSoon,
    'unshippedBuyers' => count($unshippedBuyers),
    'relist' => $relist,
    'pendingToday' => $pendingToday,
    'postedToday' => $postedToday,
    'online' => $online,
]);
