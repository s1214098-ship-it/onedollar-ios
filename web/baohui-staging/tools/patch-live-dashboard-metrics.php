<?php
declare(strict_types=1);

$target = $argv[1] ?? '';
if ($target === '' || !is_file($target)) {
    fwrite(STDERR, "usage: php patch-live-dashboard-metrics.php <api.php>\n");
    exit(1);
}

$src = (string)file_get_contents($target);
if ($src === '') {
    fwrite(STDERR, "empty file: {$target}\n");
    exit(1);
}

$summaryFn = <<<'PHP'
function bhm_one_dollar_summary(): array
{
    $dir = bhm_one_dollar_data_dir();
    $stamp = bhm_one_dollar_data_stamp();
    $lib = __DIR__ . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'dashboard-metrics-lib.php';
    $ver = 'legacy';
    if (is_file($lib)) {
        require_once $lib;
        if (defined('BHM_DASHBOARD_METRICS_VERSION')) $ver = BHM_DASHBOARD_METRICS_VERSION;
    }
    $cacheFile = $dir . DIRECTORY_SEPARATOR . '_native_summary_cache.json';
    $cacheKey = $stamp . '|' . $ver;
    if (is_file($cacheFile)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && ($cached['stamp'] ?? '') === $cacheKey && isset($cached['summary']) && is_array($cached['summary'])) {
            return $cached['summary'];
        }
    }
    $summary = bhm_one_dollar_summary_compute();
    @file_put_contents(
        $cacheFile,
        json_encode(['stamp' => $cacheKey, 'summary' => $summary, 'cached_at' => date('c')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    return $summary;
}
PHP;

$computeFn = <<<'PHP'
function bhm_one_dollar_summary_compute(): array
{
    $dir = bhm_one_dollar_data_dir();
    $products = bhm_json_file($dir . DIRECTORY_SEPARATOR . 'products.json');
    $schedules = bhm_json_file($dir . DIRECTORY_SEPARATOR . 'schedules.json');
    $members = bhm_json_file($dir . DIRECTORY_SEPARATOR . 'members.json');
    if (count($members) === 0) {
        $members = bhm_json_file($dir . DIRECTORY_SEPARATOR . 'members.gjp.latest.json');
    }
    $suppliers = bhm_json_file($dir . DIRECTORY_SEPARATOR . 'suppliers.json');
    $lib = __DIR__ . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'dashboard-metrics-lib.php';
    if (is_file($lib)) {
        require_once $lib;
        if (function_exists('bhm_dashboard_metrics_from_rows')) {
            return bhm_dashboard_metrics_from_rows($products, $schedules, $members, $suppliers);
        }
    }
    $stockTotal = 0;
    $inStock = 0;
    $recentProducts = [];
    foreach ($products as $row) {
        if (!is_array($row)) continue;
        $qty = bhm_int_value($row, ['stock_total', 'stock', 'qty', 'quantity']);
        $stockTotal += $qty;
        if ($qty > 0) {
            $inStock++;
            $recentProducts[] = [
                'id' => bhm_row_text($row, ['id', 'product_id']),
                'barcode' => bhm_row_text($row, ['barcode', 'product_barcode', 'original_product_code']),
                'title' => bhm_row_text($row, ['title', 'product_name', 'name']),
                'product_name' => bhm_row_text($row, ['product_name', 'title', 'name']),
                'color' => bhm_row_text($row, ['color']),
                'size' => bhm_row_text($row, ['size']),
                'spec' => bhm_row_text($row, ['spec', 'category_spec']),
                'stock_total' => $qty,
                'warehouse_name' => bhm_row_text($row, ['warehouse_name', 'warehouse']),
                'shelf_code' => bhm_row_text($row, ['shelf_code', 'shelf']),
                'warehouse_location' => bhm_row_text($row, ['warehouse_location', 'location']),
                'updated_at' => bhm_row_text($row, ['updated_at', 'created_at', 'imported_at']),
                'imported_at' => bhm_row_text($row, ['imported_at']),
            ];
        }
    }
    usort($recentProducts, static function ($a, $b) {
        return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
    });
    $recentProducts = array_slice($recentProducts, 0, 10);
    $pendingSchedules = 0;
    $recentSchedules = [];
    foreach ($schedules as $row) {
        if (!is_array($row)) continue;
        $status = bhm_row_text($row, ['publish_status', 'status']);
        if ($status === '' || strpos($status, '未') !== false || stripos($status, 'pending') !== false) {
            $pendingSchedules++;
        }
        $recentSchedules[] = [
            'id' => bhm_row_text($row, ['id']),
            'product_title' => bhm_row_text($row, ['product_title', 'title', 'product_name']),
            'product_barcode' => bhm_row_text($row, ['product_barcode', 'barcode']),
            'scheduled_publish_at' => bhm_row_text($row, ['scheduled_publish_at', 'publish_at']),
            'publish_at' => bhm_row_text($row, ['publish_at']),
            'close_at' => bhm_row_text($row, ['close_at', 'close_remind_at']),
            'quantity' => bhm_int_value($row, ['quantity', 'qty']),
            'status' => $status,
            'publish_status' => bhm_row_text($row, ['publish_status']),
            'updated_at' => bhm_row_text($row, ['updated_at', 'scheduled_publish_at', 'publish_at', 'close_at']),
        ];
    }
    usort($recentSchedules, static function ($a, $b) {
        return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
    });
    $recentSchedules = array_slice($recentSchedules, 0, 10);
    return [
        'product_count' => count($products),
        'in_stock_count' => $inStock,
        'stock_total' => $stockTotal,
        'schedule_count' => count($schedules),
        'pending_schedule_count' => $pendingSchedules,
        'member_count' => count($members),
        'supplier_count' => count($suppliers),
        'recent_products' => $recentProducts,
        'recent_schedules' => $recentSchedules,
    ];
}
PHP;

if (!preg_match('/function bhm_one_dollar_summary\(\): array\s*\{.*?\n\}/s', $src)) {
    fwrite(STDERR, "could not find bhm_one_dollar_summary in {$target}\n");
    exit(1);
}
if (!preg_match('/function bhm_one_dollar_summary_compute\(\): array\s*\{.*?\n\}/s', $src)) {
    fwrite(STDERR, "could not find bhm_one_dollar_summary_compute in {$target}\n");
    exit(1);
}

$out = preg_replace('/function bhm_one_dollar_summary\(\): array\s*\{.*?\n\}/s', $summaryFn, $src, 1, $count1);
$out = preg_replace('/function bhm_one_dollar_summary_compute\(\): array\s*\{.*?\n\}/s', $computeFn, $out, 1, $count2);
if ($count1 !== 1 || $count2 !== 1) {
    fwrite(STDERR, "replace counts summary={$count1} compute={$count2}\n");
    exit(1);
}

if (strpos($out, 'dashboard-metrics-lib.php') === false || strpos($out, 'bhm_dashboard_metrics_from_rows') === false) {
    fwrite(STDERR, "patched file missing metrics lib hook\n");
    exit(1);
}

if (file_put_contents($target, $out) === false) {
    fwrite(STDERR, "write failed: {$target}\n");
    exit(1);
}

echo "patched {$target}\n";
