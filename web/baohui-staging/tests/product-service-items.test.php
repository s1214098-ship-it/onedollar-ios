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

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'product-service-items-lib.php';
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'dashboard-metrics-lib.php';
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'quote-builder-lib.php';

$wage = product_build_service_item([], 'wage', '組裝工資', 500, 800, '到府組裝');
expect($wage['item_kind'] === 'wage', 'wage item_kind is stored');
expect($wage['category_group'] === '工資類別', 'wage uses 工資類別 group');
expect($wage['stock_total'] === 0 && $wage['stock_sold'] === 0, 'wage has no warehouse qty');
expect($wage['cost'] === 500.0 && $wage['sale_price'] === 800.0, 'wage stores cost and sale price');
expect($wage['warehouse_name'] === '', 'wage has no warehouse');
expect(product_is_inventory_item($wage) === false, 'wage is not inventory');

$ship = product_build_service_item([], 'logistics', '黑貓宅配', 60, 120, '');
expect($ship['item_kind'] === 'logistics', 'logistics item_kind is stored');
expect($ship['category_group'] === '物流報價', 'logistics uses 物流報價 group');
expect(product_is_inventory_item($ship) === false, 'logistics is not inventory');
expect(product_service_kind(['category_group' => '物流報價', 'title' => '舊資料']) === 'logistics', 'legacy logistics group is detected');

$stockSku = ['id' => 'COM001P150', 'title' => '主機', 'category_group' => '組裝硬體', 'stock_total' => 4, 'cost' => 150, 'sale_price' => 300];
expect(product_is_inventory_item($stockSku) === true, 'hardware SKU stays inventory');

$summary = bhm_dashboard_metrics_from_rows([
    $stockSku,
    array_merge($wage, ['id' => 'WAGE001P500', 'original_product_code' => 'WAGE001']),
    array_merge($ship, ['id' => 'LOGI001P60', 'original_product_code' => 'LOGI001', 'stock_total' => 99]),
], [], [], []);
expect($summary['product_count'] === 1, 'dashboard product_count skips wage and logistics');
expect($summary['in_stock_count'] === 1, 'dashboard in-stock skips service items even if book qty leaked');
expect($summary['stock_total'] === 4, 'dashboard available qty ignores logistics book qty');
expect($summary['book_stock_total'] === 4, 'dashboard book qty ignores service items');

expect(quote_builder_slot_for_product($wage) === 'wage', 'wage maps to quote wage slot');
expect(quote_builder_slot_for_product($ship) === 'logistics_fee', 'logistics maps to quote logistics slot');
expect(quote_builder_price($wage) === 800, 'quote uses wage sale price');

$ops = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'operations.php');
expect(str_contains($ops, "'wage-items' => '工資類別'"), 'ops nav has 工資類別');
expect(str_contains($ops, "'logistics-items' => '物流報價'"), 'ops nav has 物流報價');
expect(str_contains($ops, 'save_service_item'), 'ops can save service items');
expect(str_contains($ops, 'product_service_kind_map()'), 'ops renders wage and logistics tabs from the service map');
expect(str_contains($ops, 'productIsInventoryItem'), 'sales/search JS knows service items');

if ($failed > 0) {
    fwrite(STDERR, $failed . " assertion(s) failed\n");
    exit(1);
}
echo "all passed\n";
