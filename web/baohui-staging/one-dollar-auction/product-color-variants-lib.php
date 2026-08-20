<?php

function split_picked_product_values($value): array
{
    $parts = preg_split('/[、,，\n]+/u', trim((string)$value));
    if (!is_array($parts)) {
        return [];
    }
    $out = [];
    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part !== '') {
            $out[] = $part;
        }
    }
    return $out;
}

function normalize_barcode_color_code($value): string
{
    return preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim((string)$value))) ?? '';
}

function compare_barcode_color_codes($a, $b): int
{
    $a = normalize_barcode_color_code($a);
    $b = normalize_barcode_color_code($b);
    if ($a === $b) {
        return 0;
    }
    if ($a === '') {
        return 1;
    }
    if ($b === '') {
        return -1;
    }
    if (ctype_digit($a) && ctype_digit($b)) {
        return ((int)$a <=> (int)$b);
    }
    return strnatcasecmp($a, $b);
}

function sort_barcode_color_variants(array $items): array
{
    usort($items, function ($a, $b) {
        $cmp = compare_barcode_color_codes($a['code'] ?? '', $b['code'] ?? '');
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });
    return array_values($items);
}

function merge_barcode_color_variants(array $items): array
{
    $merged = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $code = normalize_barcode_color_code($item['code'] ?? '');
        $name = trim((string)($item['name'] ?? ''));
        $qty = max(0, (int)($item['qty'] ?? 0));
        if ($code === '' && $name === '') {
            continue;
        }
        $key = $code !== '' ? ('c:' . $code) : ('n:' . $name);
        if (isset($merged[$key])) {
            $merged[$key]['qty'] += $qty;
            if ($merged[$key]['name'] === '' && $name !== '') {
                $merged[$key]['name'] = $name;
            }
            continue;
        }
        $merged[$key] = [
            'code' => $code,
            'name' => $name,
            'qty' => $qty,
        ];
    }
    return sort_barcode_color_variants(array_values($merged));
}

function parse_posted_color_variants($post, $fallbackQty = 0): array
{
    $post = is_array($post) ? $post : [];
    $fallbackQty = max(0, (int)$fallbackQty);
    $items = [];
    $raw = trim((string)($post['color_variants'] ?? ''));
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $items[] = [
                    'code' => $row['code'] ?? '',
                    'name' => $row['name'] ?? '',
                    'qty' => $row['qty'] ?? 0,
                ];
            }
        }
    }
    $items = merge_barcode_color_variants($items);
    if ($items) {
        return $items;
    }

    $codes = split_picked_product_values($post['color_code'] ?? '');
    $names = split_picked_product_values($post['color'] ?? '');
    $count = max(count($codes), count($names));
    for ($i = 0; $i < $count; $i++) {
        $items[] = [
            'code' => $codes[$i] ?? '',
            'name' => $names[$i] ?? '',
            'qty' => ($count === 1 ? $fallbackQty : ($i === 0 ? $fallbackQty : 0)),
        ];
    }
    $items = merge_barcode_color_variants($items);
    if ($items) {
        return $items;
    }

    return [[
        'code' => '',
        'name' => '',
        'qty' => $fallbackQty,
    ]];
}

function color_code_image_lookup(array $rows): array
{
    $map = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = normalize_barcode_color_code($row['code'] ?? '');
        $image = trim((string)($row['image'] ?? ''));
        if ($code === '' || $image === '') {
            continue;
        }
        $map[$code] = $image;
    }
    return $map;
}

function upsert_color_code_image_rows(array $rows, array $uploads): array
{
    foreach ($uploads as $code => $image) {
        $code = normalize_barcode_color_code($code);
        $image = trim((string)$image);
        if ($code === '' || $image === '') {
            continue;
        }
        $found = false;
        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }
            if (normalize_barcode_color_code($row['code'] ?? '') !== $code) {
                continue;
            }
            $row['code'] = $code;
            $row['image'] = $image;
            $row['updated_at'] = date('c');
            $found = true;
            break;
        }
        unset($row);
        if (!$found) {
            $rows[] = [
                'code' => $code,
                'image' => $image,
                'updated_at' => date('c'),
            ];
        }
    }
    return array_values($rows);
}
