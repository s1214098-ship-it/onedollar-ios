<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ops-data-lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'product-service-items-lib.php';

function ops_product_index_version(): int
{
    return 2;
}

function ops_product_index_preview_image($product): string
{
    if (!is_array($product)) return '';
    $first = trim((string)($product['image'] ?? ''));
    if ($first !== '') return $first;
    foreach (($product['extra_images'] ?? []) as $img) {
        $img = trim((string)$img);
        if ($img !== '') return $img;
    }
    return '';
}

function ops_product_images($product): array
{
    $first = ops_product_index_preview_image($product);
    return $first !== '' ? [$first] : [];
}

function ops_product_index_row($product): array
{
    $p = is_array($product) ? $product : [];
    $image = ops_product_index_preview_image($p);
    return [
        'id' => (string)($p['id'] ?? ''),
        'barcode' => (string)($p['barcode'] ?? ''),
        'title' => (string)($p['title'] ?? ''),
        'product_condition' => (string)($p['product_condition'] ?? ''),
        'category_group' => (string)($p['category_group'] ?? ''),
        'category_type' => (string)($p['category_type'] ?? ''),
        'category_brand' => (string)($p['category_brand'] ?? ''),
        'category_spec' => (string)($p['category_spec'] ?? ''),
        'color' => (string)($p['color'] ?? ''),
        'color_code' => (string)($p['color_code'] ?? ''),
        'size' => (string)($p['size'] ?? ''),
        'size_code' => (string)($p['size_code'] ?? ''),
        'spec' => (string)($p['spec'] ?? ''),
        'warehouse_name' => (string)($p['warehouse_name'] ?? ''),
        'shelf_code' => (string)($p['shelf_code'] ?? ''),
        'warehouse_location' => (string)($p['warehouse_location'] ?? ''),
        'department' => (string)($p['department'] ?? ''),
        'stock_total' => (int)($p['stock_total'] ?? 0),
        'stock_reserved' => (int)($p['stock_reserved'] ?? 0),
        'cloud_auction_reserved' => (int)($p['cloud_auction_reserved'] ?? 0),
        'cloud_auction_locked' => !empty($p['cloud_auction_locked']),
        'stock_sold' => (int)($p['stock_sold'] ?? 0),
        'cost' => (float)($p['cost'] ?? 0),
        'sale_price' => (float)($p['sale_price'] ?? 0),
        'reference_price' => (float)($p['reference_price'] ?? 0),
        'item_kind' => (string)($p['item_kind'] ?? ''),
        'is_inventory' => function_exists('product_is_inventory_item') ? product_is_inventory_item($p) : true,
        'image' => $image,
        'images' => $image !== '' ? [$image] : [],
    ];
}

function ops_product_index_path(): string
{
    return ops_data_dir() . DIRECTORY_SEPARATOR . 'products-search-index.json';
}

function ops_product_index_cache_current(array $cached): bool
{
    if (!$cached) return true;
    $sample = $cached[0] ?? null;
    if (!is_array($sample)) return false;
    if (isset($sample['purchase_source'])) return false;
    if (count($sample['images'] ?? []) > 1) return false;
    return true;
}

function ops_write_product_index_cache(array $products): void
{
    $items = [];
    foreach ($products as $row) {
        if (!is_array($row)) continue;
        $items[] = ops_product_index_row($row);
    }
    $json = json_encode($items, json_flags(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($json === false) $json = '[]';
    file_put_contents(ops_product_index_path(), $json, LOCK_EX);
}

function ops_load_product_index(): array
{
    $source = data_path('products');
    $cache = ops_product_index_path();
    $sourceMtime = is_file($source) ? (int)filemtime($source) : 0;
    $cacheMtime = is_file($cache) ? (int)filemtime($cache) : 0;
    if ($cacheMtime > 0 && $cacheMtime >= $sourceMtime) {
        $cached = ops_decode_json_file($cache);
        if (is_array($cached) && ops_product_index_cache_current($cached)) return $cached;
    }
    $products = read_data('products');
    ops_write_product_index_cache(is_array($products) ? $products : []);
    $cached = ops_decode_json_file($cache);
    return is_array($cached) ? $cached : [];
}
