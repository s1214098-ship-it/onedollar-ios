<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'product-service-items-lib.php';

function ops_product_reserved_qty($product): int
{
    if (!is_array($product)) return 0;
    return max(0, (int)($product['stock_reserved'] ?? 0))
        + max(0, (int)($product['cloud_auction_reserved'] ?? 0));
}

function ops_product_available_qty($product): int
{
    if (!is_array($product)) return 0;
    $total = (int)($product['stock_total'] ?? $product['stock'] ?? 0);
    $sold = (int)($product['stock_sold'] ?? 0);
    return max(0, $total - ops_product_reserved_qty($product) - $sold);
}

function ops_product_is_zero_stock_inventory($product): bool
{
    if (!is_array($product)) return false;
    if (function_exists('product_is_inventory_item') && !product_is_inventory_item($product)) return false;
    if (ops_product_available_qty($product) > 0) return false;
    if (ops_product_reserved_qty($product) > 0) return false;
    return true;
}

function ops_split_zero_stock_products(array $products): array
{
    $keep = [];
    $remove = [];
    foreach ($products as $row) {
        if (!is_array($row)) continue;
        if (ops_product_is_zero_stock_inventory($row)) $remove[] = $row;
        else $keep[] = $row;
    }
    return [$keep, $remove];
}
