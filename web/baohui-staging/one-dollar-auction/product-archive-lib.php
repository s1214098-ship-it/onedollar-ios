<?php

function product_computer_spec_keys(): array
{
    return ['model', 'cpu', 'ram', 'storage', 'gpu', 'serial_number', 'warranty', 'inspection_note'];
}

function apply_product_computer_spec(array $row, array $post, array $existing = []): array
{
    foreach (product_computer_spec_keys() as $key) {
        if (array_key_exists($key, $post)) {
            $row[$key] = trim((string)$post[$key]);
            continue;
        }
        $row[$key] = trim((string)($existing[$key] ?? ($row[$key] ?? '')));
    }
    return $row;
}

function product_spec_summary(array $product): string
{
    $parts = [];
    foreach ([
        $product['category_brand'] ?? '',
        $product['model'] ?? '',
        $product['cpu'] ?? '',
        $product['ram'] ?? '',
        $product['storage'] ?? '',
        $product['gpu'] ?? '',
        $product['spec'] ?? '',
    ] as $value) {
        $value = trim((string)$value);
        if ($value !== '') $parts[] = $value;
    }
    $colorSize = trim((string)($product['color'] ?? ''));
    $size = trim((string)($product['size'] ?? ''));
    if ($size !== '') $colorSize = trim($colorSize . ' / ' . $size, ' /');
    if ($colorSize !== '' && $colorSize !== '/') $parts[] = $colorSize;
    $unique = [];
    foreach ($parts as $part) {
        if (!in_array($part, $unique, true)) $unique[] = $part;
    }
    return implode(' · ', $unique);
}

function product_is_computer_archive($categoryGroup): bool
{
    $group = trim((string)$categoryGroup);
    return $group === '' || $group === '組裝硬體' || in_array($group, ['電腦部門', '電腦'], true);
}

function department_warehouse_map(): array
{
    return [
        '電腦部門' => ['台灣倉', '電腦倉', '中國倉'],
        '服裝部門' => ['台灣倉', '中國倉', '印尼倉'],
    ];
}

function default_warehouse_name($department = '電腦部門'): string
{
    $map = department_warehouse_map();
    $dept = trim((string)$department);
    if ($dept !== '' && !empty($map[$dept][0])) return $map[$dept][0];
    return '台灣倉';
}
