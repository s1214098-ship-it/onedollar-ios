<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ops-embed-auth-lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ops-data-lib.php';
$lifecycle = __DIR__ . DIRECTORY_SEPARATOR . 'schedule-lifecycle-lib.php';
if (is_file($lifecycle)) require_once $lifecycle;
require_once __DIR__ . DIRECTORY_SEPARATOR . 'facebook-daily-report.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: application/json; charset=utf-8');

baohui_ops_boot_hq_session();

$loggedIn = !empty($_SESSION['baohui_logged_in'])
    || !empty($_SESSION['logged_in'])
    || !empty($_SESSION['admin_logged_in'])
    || !empty($_SESSION['is_login'])
    || (!empty($_SESSION['user']) && is_array($_SESSION['user']));
if (!$loggedIn) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => '尚未登入'], JSON_UNESCAPED_UNICODE);
    exit;
}

$date = facebook_daily_date((string)($_GET['date'] ?? $_GET['fb_day'] ?? ''));
$schedules = function_exists('read_data') ? read_data('schedules') : [];
$products = function_exists('read_data') ? read_data('products') : [];
$sets = function_exists('read_data') ? read_data('post_reply_sets') : [];
if (!is_array($schedules)) $schedules = [];
if (!is_array($products)) $products = [];
if (!is_array($sets)) $sets = [];

$payload = facebook_daily_progress_payload($schedules, $products, $sets, $date);
$payload['ok'] = true;
$payload['generated_at'] = date('c');
echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
