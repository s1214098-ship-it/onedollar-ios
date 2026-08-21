<?php
declare(strict_types=1);

const BHM_DASHBOARD_METRICS_VERSION = '20260821-available-1';

function bhm_dashboard_int_value($row, array $keys): int
{
    if (!is_array($row)) return 0;
    foreach ($keys as $key) {
        if (isset($row[$key]) && is_numeric($row[$key])) return (int) $row[$key];
    }
    return 0;
}

function bhm_dashboard_row_text($row, array $keys): string
{
    if (!is_array($row)) return '';
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string) $row[$key]) !== '') return (string) $row[$key];
    }
    return '';
}

function bhm_dashboard_book_qty(array $row): int
{
    return max(0, bhm_dashboard_int_value($row, ['stock_total', 'stock', 'qty', 'quantity']));
}

function bhm_dashboard_reserved_qty(array $row): int
{
    return max(0, (int)($row['stock_reserved'] ?? 0)) + max(0, (int)($row['cloud_auction_reserved'] ?? 0));
}

function bhm_dashboard_sold_qty(array $row): int
{
    return max(0, bhm_dashboard_int_value($row, ['stock_sold', 'sold']));
}

function bhm_dashboard_available_qty(array $row): int
{
    return max(0, bhm_dashboard_book_qty($row) - bhm_dashboard_reserved_qty($row) - bhm_dashboard_sold_qty($row));
}

function bhm_dashboard_metrics_from_rows(array $products, array $schedules, array $members, array $suppliers): array
{
    $bookTotal = 0;
    $availableTotal = 0;
    $reservedTotal = 0;
    $soldTotal = 0;
    $bookSku = 0;
    $availableSku = 0;
    $recentProducts = [];

    foreach ($products as $row) {
        if (!is_array($row)) continue;
        $book = bhm_dashboard_book_qty($row);
        $reserved = bhm_dashboard_reserved_qty($row);
        $sold = bhm_dashboard_sold_qty($row);
        $available = max(0, $book - $reserved - $sold);
        $bookTotal += $book;
        $reservedTotal += $reserved;
        $soldTotal += $sold;
        $availableTotal += $available;
        if ($book > 0) $bookSku++;
        if ($available <= 0) continue;
        $availableSku++;
        $recentProducts[] = [
            'id' => bhm_dashboard_row_text($row, ['id', 'product_id']),
            'barcode' => bhm_dashboard_row_text($row, ['barcode', 'product_barcode', 'original_product_code']),
            'title' => bhm_dashboard_row_text($row, ['title', 'product_name', 'name']),
            'product_name' => bhm_dashboard_row_text($row, ['product_name', 'title', 'name']),
            'color' => bhm_dashboard_row_text($row, ['color']),
            'size' => bhm_dashboard_row_text($row, ['size']),
            'spec' => bhm_dashboard_row_text($row, ['spec', 'category_spec']),
            'stock_total' => $available,
            'book_stock_total' => $book,
            'warehouse_name' => bhm_dashboard_row_text($row, ['warehouse_name', 'warehouse']),
            'shelf_code' => bhm_dashboard_row_text($row, ['shelf_code', 'shelf']),
            'warehouse_location' => bhm_dashboard_row_text($row, ['warehouse_location', 'location']),
            'updated_at' => bhm_dashboard_row_text($row, ['updated_at', 'created_at', 'imported_at']),
            'imported_at' => bhm_dashboard_row_text($row, ['imported_at']),
        ];
    }

    usort($recentProducts, static function ($a, $b) {
        return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
    });
    $recentProducts = array_slice($recentProducts, 0, 10);

    $pendingSchedules = 0;
    $recentSchedules = [];
    foreach ($schedules as $row) {
        if (!is_array($row)) continue;
        $status = bhm_dashboard_row_text($row, ['publish_status', 'status']);
        if ($status === '' || strpos($status, '未') !== false || stripos($status, 'pending') !== false) {
            $pendingSchedules++;
        }
        $recentSchedules[] = [
            'id' => bhm_dashboard_row_text($row, ['id']),
            'product_title' => bhm_dashboard_row_text($row, ['product_title', 'title', 'product_name']),
            'product_barcode' => bhm_dashboard_row_text($row, ['product_barcode', 'barcode']),
            'scheduled_publish_at' => bhm_dashboard_row_text($row, ['scheduled_publish_at', 'publish_at']),
            'publish_at' => bhm_dashboard_row_text($row, ['publish_at']),
            'close_at' => bhm_dashboard_row_text($row, ['close_at', 'close_remind_at']),
            'quantity' => bhm_dashboard_int_value($row, ['quantity', 'qty']),
            'status' => $status,
            'publish_status' => bhm_dashboard_row_text($row, ['publish_status']),
            'updated_at' => bhm_dashboard_row_text($row, ['updated_at', 'scheduled_publish_at', 'publish_at', 'close_at']),
        ];
    }
    usort($recentSchedules, static function ($a, $b) {
        return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
    });
    $recentSchedules = array_slice($recentSchedules, 0, 10);

    $productCount = count($products);
    return [
        'product_count' => $productCount,
        'in_stock_count' => $availableSku,
        'stock_total' => $availableTotal,
        'zero_stock_count' => max(0, $productCount - $bookSku),
        'book_in_stock_count' => $bookSku,
        'book_stock_total' => $bookTotal,
        'reserved_stock_total' => $reservedTotal,
        'sold_stock_total' => $soldTotal,
        'schedule_count' => count($schedules),
        'pending_schedule_count' => $pendingSchedules,
        'member_count' => count($members),
        'supplier_count' => count($suppliers),
        'recent_products' => $recentProducts,
        'recent_schedules' => $recentSchedules,
        'metrics_version' => BHM_DASHBOARD_METRICS_VERSION,
    ];
}
