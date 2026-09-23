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

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'dashboard-metrics-lib.php';

$products = [
    [
        'id' => 'KEEPZERO',
        'title' => '零庫存建檔',
        'stock_total' => 0,
        'stock' => 99,
        'updated_at' => '2026-08-21 10:00:00',
    ],
    [
        'id' => 'BOOKONLY',
        'title' => '账面有貨但已預約售出',
        'stock_total' => 5,
        'stock_reserved' => 3,
        'cloud_auction_reserved' => 1,
        'stock_sold' => 1,
        'updated_at' => '2026-08-21 11:00:00',
    ],
    [
        'id' => 'REALSTOCK',
        'title' => '實際可賣',
        'stock_total' => 10,
        'stock_reserved' => 2,
        'stock_sold' => 1,
        'updated_at' => '2026-08-21 12:00:00',
    ],
    [
        'id' => 'NEWIN',
        'title' => '新入庫',
        'stock_total' => 4,
        'updated_at' => '2026-08-21 13:00:00',
    ],
];

$summary = bhm_dashboard_metrics_from_rows($products, [], [], []);
expect($summary['product_count'] === 4, 'product_count is master rows including zero stock');
expect($summary['in_stock_count'] === 2, 'in_stock_count uses available qty > 0');
expect($summary['stock_total'] === 11, 'stock_total card uses available pieces');
expect($summary['zero_stock_count'] === 1, 'zero_stock_count is SKUs with book qty 0');
expect($summary['book_in_stock_count'] === 3, 'book_in_stock_count still sees reserved SKUs');
expect($summary['book_stock_total'] === 19, 'book_stock_total keeps warehouse book qty');
expect($summary['recent_products'][0]['id'] === 'NEWIN', 'recent products sort by updated_at');
expect($summary['recent_products'][0]['stock_total'] === 4, 'recent product stock_total is available');
expect(count($summary['recent_products']) === 2, 'recent products omit zero available SKUs');
expect(bhm_dashboard_available_qty($products[1]) === 0, 'fully reserved SKU has 0 available');

$js = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'admin-one-dollar-dashboard.js');
expect(str_contains($js, 'baohuiRenderOneDollarMetrics'), 'dashboard JS exposes metric renderer');
expect(str_contains($js, '含零庫存'), 'dashboard JS labels zero-stock master rows');
expect(str_contains($js, '有可用庫存'), 'dashboard JS labels available SKUs');
expect(str_contains($js, '可用 ${fmt(summary.stock_total)} 件'), 'dashboard JS shows available pieces');

if ($failed > 0) {
    fwrite(STDERR, $failed . " assertion(s) failed\n");
    exit(1);
}
echo "all passed\n";
