<?php
declare(strict_types=1);

if (!function_exists('mb_strpos')) {
    function mb_strpos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null): int|false
    {
        return strpos($haystack, $needle, $offset);
    }
}
if (!function_exists('mb_stripos')) {
    function mb_stripos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null): int|false
    {
        return stripos($haystack, $needle, $offset);
    }
}

function quote_builder_products_path(): string
{
    $configured = trim((string)getenv('BAOHUI_PRODUCTS_JSON'));
    if ($configured !== '') return $configured;
    return __DIR__ . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'products.json';
}

function quote_builder_slots(): array
{
    return [
        ['id' => 'aio', 'label' => '品牌小主機、AIO', 'types' => ['主機', '套裝款設備']],
        ['id' => 'laptop', 'label' => '筆電｜平板', 'types' => ['筆電']],
        ['id' => 'cpu', 'label' => '處理器 CPU', 'types' => ['處理器']],
        ['id' => 'mb', 'label' => '主機板 MB', 'types' => ['主機板']],
        ['id' => 'ram', 'label' => '記憶體 RAM', 'types' => ['記憶體']],
        ['id' => 'ssd', 'label' => '固態硬碟 M.2｜SSD', 'types' => ['硬碟SSD'], 'exclude' => ['HDD', '機械硬碟', '7200轉', '5400轉']],
        ['id' => 'hdd', 'label' => '傳統內接硬碟 HDD', 'types' => ['硬碟SSD'], 'include' => ['HDD', '機械', '7200轉', '5400轉', '藍標', '紫標', '紅標']],
        ['id' => 'flash', 'label' => '隨身碟｜記憶卡', 'types' => ['隨身碟']],
        ['id' => 'cooler', 'label' => '散熱器｜散熱膏', 'types' => ['散熱設備'], 'exclude' => ['水冷']],
        ['id' => 'aio_cool', 'label' => '封閉式｜開放式水冷', 'types' => ['散熱設備'], 'include' => ['水冷']],
        ['id' => 'vga', 'label' => '顯示卡 VGA', 'types' => ['顯示卡']],
        ['id' => 'monitor', 'label' => '螢幕｜支架', 'types' => ['電腦螢幕', '螢幕支架']],
        ['id' => 'case', 'label' => 'CASE 機殼', 'types' => ['電腦機箱']],
        ['id' => 'psu', 'label' => '電源供應器', 'types' => ['電源供應器']],
        ['id' => 'fan', 'label' => '機殼風扇｜燈條', 'types' => ['機殼風扇', '機箱燈條']],
        ['id' => 'kb', 'label' => '鍵盤｜滑鼠', 'types' => ['鍵盤與滑鼠']],
        ['id' => 'net', 'label' => '網卡｜網通設備', 'types' => ['網路設備'], 'exclude' => ['NAS', 'IPCAM', '監視']],
        ['id' => 'nas', 'label' => '網路 NAS｜IPCAM', 'types' => ['網路設備'], 'include' => ['NAS', 'IPCAM', '監視', '錄影']],
        ['id' => 'audio', 'label' => '喇叭｜耳機｜麥克風', 'types' => ['音響', '麥克風']],
        ['id' => 'burner', 'label' => '燒錄器 CD/DVD', 'types' => ['光碟機燒錄機']],
        ['id' => 'usb', 'label' => 'USB 週邊｜硬碟座｜讀卡機', 'types' => ['讀卡機', '硬碟外接盒', '轉換設備', '3C周邊']],
        ['id' => 'cable', 'label' => '線材｜轉頭｜KVM', 'types' => ['線材類', '充電線', '切換器', '分享設備']],
        ['id' => 'printer', 'label' => 'UPS｜印表機', 'types' => ['印表機']],
        ['id' => 'os', 'label' => '作業系統｜軟體', 'types' => ['軟體專區']],
        ['id' => 'clearance', 'label' => '福利品｜回收', 'types' => ['福利標', '回收(大陸)']],
        ['id' => 'accessory', 'label' => '電腦周邊｜消耗品', 'types' => ['電腦周邊', '消耗品', '電子清潔用品', '遊戲片']],
    ];
}

function quote_builder_hay(array $row): string
{
    return trim(implode(' ', array_map('strval', [
        $row['title'] ?? '',
        $row['product_name'] ?? '',
        $row['category_type'] ?? '',
        $row['main_category'] ?? '',
        $row['category_brand'] ?? '',
        $row['category_spec'] ?? '',
        $row['spec'] ?? '',
        $row['brand'] ?? '',
    ])));
}

function quote_builder_has(string $hay, array $needles): bool
{
    foreach ($needles as $needle) {
        if ($needle !== '' && mb_stripos($hay, $needle) !== false) return true;
    }
    return false;
}

function quote_builder_match_slot(array $row, array $slot): bool
{
    $type = trim((string)($row['category_type'] ?? $row['main_category'] ?? ''));
    $types = $slot['types'] ?? [];
    if ($types && !in_array($type, $types, true)) return false;
    $hay = quote_builder_hay($row);
    if (!empty($slot['exclude']) && quote_builder_has($hay, $slot['exclude'])) return false;
    if (!empty($slot['include']) && !quote_builder_has($hay, $slot['include'])) return false;
    return true;
}

function quote_builder_slot_for_product(array $row): ?string
{
    foreach (quote_builder_slots() as $slot) {
        if (quote_builder_match_slot($row, $slot)) return $slot['id'];
    }
    return null;
}

function quote_builder_price(array $row): int
{
    foreach (['sale_price', 'reference_price', 'shop_price'] as $key) {
        $n = (int)round((float)($row[$key] ?? 0));
        if ($n > 0) return $n;
    }
    return 0;
}

function quote_builder_cost(array $row): int
{
    foreach (['barcode_cost', 'cost'] as $key) {
        $n = (int)round((float)($row[$key] ?? 0));
        if ($n > 0) return $n;
    }
    return 0;
}

function quote_builder_item(array $row): array
{
    $name = trim((string)($row['title'] ?? $row['product_name'] ?? ''));
    $brand = trim((string)($row['category_brand'] ?? $row['brand'] ?? ''));
    $spec = trim((string)($row['category_spec'] ?? $row['spec'] ?? ''));
    return [
        'id' => (string)($row['id'] ?? ''),
        'name' => $name,
        'brand' => $brand,
        'spec' => $spec,
        'barcode' => (string)($row['barcode'] ?? ''),
        'category_group' => (string)($row['category_group'] ?? ''),
        'category_type' => (string)($row['category_type'] ?? $row['main_category'] ?? ''),
        'price' => quote_builder_price($row),
        'cost' => quote_builder_cost($row),
        'stock' => max(0, (int)($row['stock_total'] ?? $row['stock'] ?? 0)),
    ];
}

function quote_builder_load_products(): array
{
    $path = quote_builder_products_path();
    if (!is_file($path)) return [];
    $rows = json_decode((string)file_get_contents($path), true);
    return is_array($rows) ? $rows : [];
}

function quote_builder_catalog(?array $products = null): array
{
    $products = $products ?? quote_builder_load_products();
    $slots = quote_builder_slots();
    $grouped = [];
    foreach ($slots as $slot) $grouped[$slot['id']] = [];
    $extra = [];
    foreach ($products as $row) {
        if (!is_array($row)) continue;
        $item = quote_builder_item($row);
        if ($item['name'] === '') continue;
        $slotId = quote_builder_slot_for_product($row);
        if ($slotId) $grouped[$slotId][] = $item;
        else {
            $key = trim($item['category_group'] . ' / ' . $item['category_type'], ' /');
            if ($key === '') $key = '未分類';
            $extra[$key][] = $item;
        }
    }
    $outSlots = [];
    foreach ($slots as $slot) {
        $items = $grouped[$slot['id']] ?? [];
        usort($items, static function ($a, $b) {
            return [$b['stock'], $a['name']] <=> [$a['stock'], $b['name']];
        });
        $outSlots[] = [
            'id' => $slot['id'],
            'label' => $slot['label'],
            'types' => $slot['types'],
            'count' => count($items),
            'items' => array_slice($items, 0, 400),
        ];
    }
    ksort($extra, SORT_STRING);
    $extraSlots = [];
    foreach ($extra as $label => $items) {
        usort($items, static function ($a, $b) {
            return [$b['stock'], $a['name']] <=> [$a['stock'], $b['name']];
        });
        $extraSlots[] = [
            'id' => 'extra-' . substr(sha1($label), 0, 8),
            'label' => $label,
            'types' => [],
            'count' => count($items),
            'items' => array_slice($items, 0, 400),
        ];
    }
    return [
        'slots' => $outSlots,
        'extra' => $extraSlots,
        'productCount' => count($products),
    ];
}
