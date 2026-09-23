<?php
declare(strict_types=1);

$opsDir = dirname(__DIR__);
$dataDir = $opsDir . DIRECTORY_SEPARATOR . 'data';
if (!is_dir($dataDir)) {
    fwrite(STDERR, "找不到 data 目錄\n");
    exit(1);
}

function data_path($name) {
    global $dataDir;
    return $dataDir . DIRECTORY_SEPARATOR . $name . '.json';
}
function json_flags($flags = 0) {
    return $flags | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0);
}
function read_data($name) {
    $file = data_path($name);
    if (!is_file($file)) return [];
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') return [];
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
    $data = json_decode($raw, true, 512, json_flags());
    return is_array($data) ? $data : [];
}
function read_json_object($name) {
    $data = read_data($name);
    return is_array($data) ? $data : [];
}
function write_data($name, $data) {
    file_put_contents(data_path($name), json_encode(array_values($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function uid($prefix) {
    return $prefix . date('ymdHis') . substr(bin2hex(random_bytes(3)), 0, 6);
}
function normalize_barcode_prefix($value) {
    return strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', trim((string)$value)));
}
function category_type_code_map() {
    return [
        '主機板' => 'MB', '顯示卡' => 'GPU', '處理器' => 'CPU', '記憶體' => 'RAM',
        '硬碟SSD' => 'SSD', '電源供應器' => 'PSU', '電腦機箱' => 'CASE', '散熱設備' => 'FAN',
        '電腦螢幕' => 'MON', '筆電' => 'NB', '網路設備' => 'NET', '印表機' => 'PRT',
        '電腦周邊' => 'ACC', '軟體專區' => 'SW', '線材類' => 'CBL', '消耗品' => 'CON',
        '生活周邊' => 'LIFE', '褲子' => 'PNT', '上衣' => 'TOP',
    ] + (function_exists('product_category_extra_type_codes') ? product_category_extra_type_codes() : []);
}
function category_type_code($type, $explicit = '', $barcodePrefix = '') {
    $explicit = normalize_barcode_prefix($explicit);
    if ($explicit !== '') return $explicit;
    $map = category_type_code_map();
    $type = trim((string)$type);
    if ($type !== '' && isset($map[$type])) return $map[$type];
    return $type !== '' ? 'ITEM' : '';
}
function find_product_category_rule($categories, $group, $type, $brand, $spec) {
    foreach ($categories as $category) {
        if (trim((string)($category['group'] ?? '')) !== $group) continue;
        if (trim((string)($category['type'] ?? '')) !== $type) continue;
        if (trim((string)($category['brand'] ?? '')) !== $brand) continue;
        if (trim((string)($category['spec'] ?? '')) !== $spec) continue;
        return $category;
    }
    return null;
}
function ensure_product_category_rule(&$categories, $group, $type, $brand = '', $spec = '') {
    $group = trim((string)$group);
    $type = trim((string)$type);
    $brand = trim((string)$brand);
    $spec = trim((string)$spec);
    if ($group === '') $group = '組裝硬體';
    if ($type === '') return null;
    $existing = find_product_category_rule($categories, $group, $type, $brand, $spec);
    if ($existing) return $existing;
    $typeCode = category_type_code($type);
    $row = [
        'id' => uid('pc_'),
        'group' => $group,
        'type' => $type,
        'brand' => $brand,
        'spec' => $spec,
        'type_code' => $typeCode,
        'barcode_prefix' => $typeCode,
        'sort' => 0,
        'note' => '分類樹自動建立',
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ];
    $categories[] = $row;
    return $row;
}

require_once $opsDir . DIRECTORY_SEPARATOR . 'product-category-tree.php';

$apply = in_array('--apply', $argv, true);
$categories = read_data('product_categories');
$products = read_data('products');
$mappings = read_data('marketplace_category_mappings');

$counts = [];
foreach ($products as $product) {
    $classified = product_category_classify($product);
    $key = $classified['group'] . ' / ' . $classified['type'];
    $counts[$key] = ($counts[$key] ?? 0) + 1;
}
ksort($counts, SORT_NATURAL);
echo "products=" . count($products) . " apply=" . ($apply ? 'yes' : 'dry-run') . PHP_EOL;
foreach ($counts as $key => $count) {
    echo str_pad((string)$count, 5, ' ', STR_PAD_LEFT) . '  ' . $key . PHP_EOL;
}
if (!$apply) {
    echo "加 --apply 才會寫回。" . PHP_EOL;
    exit(0);
}

$result = product_category_tree_migrate($categories, $products, $mappings, true);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
