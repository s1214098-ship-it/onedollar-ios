<?php
declare(strict_types=1);

function product_service_kind_map(): array
{
    return [
        'wage' => [
            'kind' => 'wage',
            'group' => '工資類別',
            'type' => '工資',
            'type_code' => 'WAGE',
            'tab' => 'wage-items',
            'label' => '工資類別',
            'title_placeholder' => '例如：組裝工資、到府安裝、軟體安裝',
        ],
        'logistics' => [
            'kind' => 'logistics',
            'group' => '物流報價',
            'type' => '物流',
            'type_code' => 'LOGI',
            'tab' => 'logistics-items',
            'label' => '物流報價',
            'title_placeholder' => '例如：7-11 寄件、黑貓宅配、到府運費',
        ],
    ];
}

function product_service_group_names(): array
{
    $names = [];
    foreach (product_service_kind_map() as $meta) $names[] = $meta['group'];
    return $names;
}

function product_normalize_service_kind($kind): string
{
    $kind = strtolower(trim((string)$kind));
    return isset(product_service_kind_map()[$kind]) ? $kind : '';
}

function product_service_kind($row): string
{
    if (!is_array($row)) return '';
    $kind = product_normalize_service_kind($row['item_kind'] ?? '');
    if ($kind !== '') return $kind;
    $group = trim((string)($row['category_group'] ?? ($row['group'] ?? '')));
    $type = trim((string)($row['category_type'] ?? ($row['type'] ?? '')));
    foreach (product_service_kind_map() as $id => $meta) {
        if ($group === $meta['group']) return $id;
        if ($group === '' && $type === $meta['type']) return $id;
    }
    return '';
}

function product_is_inventory_item($row): bool
{
    return product_service_kind($row) === '';
}

function product_service_meta($kind): array
{
    $kind = product_normalize_service_kind($kind);
    $map = product_service_kind_map();
    return $kind !== '' ? $map[$kind] : [];
}

function product_clear_service_stock(array $row): array
{
    $row['stock_total'] = 0;
    $row['stock_reserved'] = 0;
    $row['stock_sold'] = 0;
    $row['cloud_auction_reserved'] = 0;
    $row['cloud_auction_locked'] = false;
    $row['warehouse_name'] = '';
    $row['shelf_code'] = '';
    $row['warehouse_location'] = '';
    return $row;
}

function product_build_service_item(array $existing, string $kind, string $title, $cost, $salePrice, string $note, string $id = ''): array
{
    $meta = product_service_meta($kind);
    if (!$meta) return [];
    $title = trim($title);
    $cost = max(0, (float)$cost);
    $salePrice = max(0, (float)$salePrice);
    $note = trim($note);
    $serial = trim((string)($existing['original_product_code'] ?? ''));
    if ($serial === '' && $id !== '') {
        $serial = preg_replace('/P\d.*$/', '', $id) ?: $id;
    }
    if ($serial === '') $serial = $id;
    $barcode = $serial !== '' ? ($serial . 'P' . (string)(int)round($cost)) : '';
    $skuId = $id !== '' ? $id : ($barcode !== '' ? $barcode : $serial);
    $row = array_merge($existing, [
        'id' => $skuId,
        'barcode' => $barcode,
        'original_product_code' => $serial,
        'title' => $title,
        'product_name' => $title,
        'item_kind' => $meta['kind'],
        'category_group' => $meta['group'],
        'category_type' => $meta['type'],
        'category_brand' => '',
        'category_spec' => '',
        'department' => '',
        'color' => '',
        'color_code' => '',
        'size' => '',
        'size_code' => '',
        'spec' => '',
        'description' => $note,
        'cost' => $cost,
        'barcode_cost' => $cost,
        'latest_cost' => $cost,
        'sale_price' => $salePrice,
        'purchase_source' => '內部',
        'purchase_source_currency' => 'TWD',
        'purchase_source_unit_cost' => $cost,
        'purchase_exchange_rate' => 1,
        'product_condition' => '服務項目',
        'status' => '可報價',
        'publish_storefront' => false,
        'created_at' => $existing['created_at'] ?? date('c'),
        'updated_at' => date('c'),
    ]);
    return product_clear_service_stock($row);
}
