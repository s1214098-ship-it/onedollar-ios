<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'product-service-items-lib.php';

const PRODUCT_CATEGORY_TREE_VERSION = 1;

function product_category_root_groups(): array
{
    return ['組裝硬體', '男性專區', '女性專區', '生活周邊'];
}

function product_category_types_by_group(): array
{
    return [
        '組裝硬體' => [
            '主機', '處理器', '主機板', '顯示卡', '記憶體', '硬碟SSD', '硬碟外接盒',
            '電源供應器', '電腦機箱', '散熱設備', '電腦螢幕', '筆電', '網路設備',
            '印表機', '電腦周邊', '鍵盤與滑鼠', '軟體專區', '線材類', '3C周邊',
            '光碟機燒錄機', '分享設備', '切換器', '讀卡機', '轉換設備', '隨身碟',
            '麥克風', '音響', '機箱燈條', '機殼風扇', '螢幕支架', '充電線',
            '套裝款設備', '變壓器', '遊戲片', '回收(大陸)', '福利標', '電子清潔用品', '消耗品',
        ],
        '男性專區' => ['鞋子', '包包', '上衣', '褲子', '帽子', '服飾配件'],
        '女性專區' => ['鞋子', '包包', '上衣', '褲子', '帽子', '服飾配件'],
        '生活周邊' => ['衛生紙', '廚房用具', '手工具', '行李箱', '健康設備', '燈飾', '毛巾', '清潔劑', '寢具', '收納', '汽車用品', '生活雜貨'],
    ];
}

function product_category_extra_type_codes(): array
{
    return [
        '鞋子' => 'SHOE', '包包' => 'BAG', '帽子' => 'HAT', '服飾配件' => 'GEAR',
        '衛生紙' => 'PAPER', '廚房用具' => 'KITC', '手工具' => 'TOOL', '行李箱' => 'LUGG',
        '燈飾' => 'LGT', '毛巾' => 'TOWL', '清潔劑' => 'CLN', '寢具' => 'BED',
        '收納' => 'STOR', '汽車用品' => 'CAR', '生活雜貨' => 'LIFE',
        '組裝硬體' => 'HW', '男性專區' => 'MEN', '女性專區' => 'WOM',
    ];
}

function product_category_haystack(array $row): string
{
    return trim(implode(' ', [
        $row['title'] ?? '',
        $row['product_name'] ?? '',
        $row['category_type'] ?? '',
        $row['main_category'] ?? '',
        $row['category_brand'] ?? '',
        $row['category_spec'] ?? '',
        $row['spec'] ?? '',
        $row['type'] ?? '',
        $row['brand'] ?? '',
        $row['group'] ?? '',
    ]));
}

function product_category_has_any(string $hay, array $needles): bool
{
    foreach ($needles as $needle) {
        if ($needle !== '' && mb_strpos($hay, $needle) !== false) return true;
    }
    return false;
}

function product_category_gender(string $hay, string $oldSpec, string $oldType): string
{
    foreach (['女士', '女生', '女用', '女款', '女裝', '女包', '女鞋', '女式', '女用'] as $word) {
        if (mb_strpos($hay, $word) !== false) return '女性專區';
    }
    foreach (['男士', '男生', '男用', '男款', '男裝', '男包', '男鞋', '男式'] as $word) {
        if (mb_strpos($hay, $word) !== false) return '男性專區';
    }
    if ($oldSpec === '女性專區' || $oldType === '女性專區') return '女性專區';
    if ($oldSpec === '男性專區' || $oldType === '男性專區') return '男性專區';
    $stripped = str_replace(['男女', '男女生'], '', $hay);
    if (mb_strpos($stripped, '女') !== false) return '女性專區';
    if (mb_strpos($stripped, '男') !== false) return '男性專區';
    return '';
}

function product_category_fashion_type(string $hay): string
{
    if (product_category_has_any($hay, ['行李箱', '拉桿箱', '旅行箱'])) return '';
    if (product_category_has_any($hay, ['拖鞋', '涼鞋', '球鞋', '運動鞋', '休閒鞋', '靴子', '短靴', '鞋'])) return '鞋子';
    if (product_category_has_any($hay, ['胸包', '斜挎', '斜跨', '雙肩', '書包', '背包', '後背包', '錢包', '皮夾', '手提包', '郵差', '單肩', '腰包', '包'])) return '包包';
    if (product_category_has_any($hay, ['短袖', '長袖', '上衣', 'T恤', 't恤', '襯衫', '外套', '背心', '帽T'])) return '上衣';
    if (product_category_has_any($hay, ['褲子', '長褲', '短褲', '牛仔褲', '褲'])) return '褲子';
    if (product_category_has_any($hay, ['帽子', '棒球帽', '漁夫帽', '帽'])) return '帽子';
    if (product_category_has_any($hay, ['襪', '皮帶', '圍巾', '手套'])) return '服飾配件';
    return '';
}

function product_category_life_type(string $hay, string $oldType, string $oldSpec): string
{
    if ($oldType === '健康設備' || product_category_has_any($hay, ['筋膜槍', '按摩槍', '按摩器', '足部按摩'])) return '健康設備';
    if ($oldType === '燈飾專區' || $oldType === '露營燈' || product_category_has_any($hay, ['太陽能燈', '露營燈', '頭燈', '氣氛燈', '氛圍燈'])) return '燈飾';
    if ($oldType === '毛巾' || product_category_has_any($hay, ['毛巾', '浴巾'])) return '毛巾';
    if ($oldType === '清潔劑' || product_category_has_any($hay, ['清潔劑', '泡沫清潔'])) return '清潔劑';
    if ($oldType === '枕頭類' || product_category_has_any($hay, ['枕頭', '抱枕', '夏被', '涼被', '冰絲'])) return '寢具';
    if ($oldType === '汽車周邊用品' || product_category_has_any($hay, ['行車記錄', '行車紀錄', '鍍膜劑', '車用'])) return '汽車用品';
    if ($oldType === '收納盒/包/杯' || product_category_has_any($hay, ['收納盒', '收納箱'])) return '收納';
    if ($oldSpec === '紙類' || product_category_has_any($hay, ['衛生紙', '面紙', '濕紙巾', '抽取式', 'A4全張', '標籤紙'])) return '衛生紙';
    if ($oldSpec === '廚具用品' || $oldType === '廚房用具' || product_category_has_any($hay, ['廚房', '廚具', '炒鍋', '平底鍋', '飯盒', '不銹鋼鍋', '不鏽鋼', '保溫杯', '水壺', '咖啡杯'])) return '廚房用具';
    if ($oldType === '電動手工具周邊' || $oldType === '施工設備' || $oldType === '手工具' || product_category_has_any($hay, ['手工具', '電鑽', '扳手', '螺絲刀', '螺絲起子', '工具箱', '工具套裝', '棘輪', '梯子', '步梯'])) return '手工具';
    if ($oldType === '行李箱' || $oldType === '電子磅秤' || product_category_has_any($hay, ['行李箱', '拉桿箱', '行李秤', '登機箱'])) return '行李箱';
    if (in_array($oldType, ['生活周邊', '生活用品', '生活雜貨'], true)) {
        if ($oldSpec !== '' && $oldSpec !== '一般規格' && $oldSpec !== '不指定品牌') return $oldSpec;
        return '生活雜貨';
    }
    return '';
}

function product_category_hardware_type(string $hay, string $oldType): string
{
    $map = [
        '主機板' => ['主機板', 'motherboard'],
        '顯示卡' => ['顯示卡', '顯卡', 'GTX', 'RTX', 'Quadro', 'RX5', 'RX6', 'RX7'],
        '處理器' => ['處理器', 'CPU', 'INTEL G', 'I3 ', 'I5 ', 'I7 ', 'Ryzen'],
        '記憶體' => ['記憶體', 'DDR2', 'DDR3', 'DDR4', 'DDR5'],
        '硬碟SSD' => ['硬碟SSD', 'SSD', 'NVMe', 'NVME', 'SATA3', '固態硬碟', '固態'],
        '硬碟外接盒' => ['硬碟外接盒', '外接盒', '硬盤盒', '硬碟盒'],
        '電源供應器' => ['電源供應器', '電源供應', ' PSU', 'SUPERNOVA'],
        '電腦機箱' => ['電腦機箱', '機箱', '機殼'],
        '散熱設備' => ['散熱設備', '散熱器', '機殼風扇', '水冷', '散熱墊'],
        '電腦螢幕' => ['電腦螢幕', '螢幕', '顯示器'],
        '筆電' => ['筆電', '筆記型'],
        '網路設備' => ['網路設備', '分享器', '交換機', '網卡', '路由器'],
        '印表機' => ['印表機', '打印機', '標籤打印'],
        '電腦周邊' => ['電腦周邊', '滑鼠', '鼠標', '鍵盤', '耳機', '電競椅', '電腦桌'],
        '軟體專區' => ['軟體專區', 'WINDOWS', 'WIN11', 'WIN10', '作業系統', '卡巴斯基', '會計系統'],
        '線材類' => ['線材類', 'HDMI', '網路線', '延長線', '充電線', '電源線'],
        '3C周邊' => ['3C周邊', '壁掛', '支架', '外接盒'],
        '光碟機燒錄機' => ['光碟機', '燒錄機', '光驅', 'DVD'],
        '分享設備' => ['分享設備', 'HUB', '分線'],
        '切換器' => ['切換器', 'KVM', '分配器'],
        '讀卡機' => ['讀卡機', '拓展塢', '擴展塢'],
        '轉換設備' => ['轉換設備', '轉接', '轉換器'],
        '隨身碟' => ['隨身碟', 'USB碟'],
        '麥克風' => ['麥克風'],
        '音響' => ['音響', '喇叭', '音箱'],
        '機箱燈條' => ['機箱燈條', '燈條', '燈帶'],
        '機殼風扇' => ['機殼風扇'],
        '螢幕支架' => ['螢幕支架', '顯示器支架'],
        '充電線' => ['充電線', '充電頭'],
        '套裝款設備' => ['套裝款', '主板套件'],
        '變壓器' => ['變壓器'],
        '遊戲片' => ['遊戲片'],
        '回收(大陸)' => ['回收(大陸)', '顯卡晶片'],
        '福利標' => ['福利標'],
        '電子清潔用品' => ['電子清潔', '除鏽', '清洗劑'],
        '消耗品' => ['消耗品', '散熱膏', '硅脂', '矽脂', '滑鼠墊', '鼠標墊', '束帶', '墨水', '碳粉'],
        '主機' => ['電腦主機', '組裝主機'],
    ];
    if ($oldType !== '' && isset($map[$oldType])) return $oldType;
    $aliases = [
        '線材類(網路頭）' => '線材類',
        '鍵盤與滑鼠' => '電腦周邊',
        '散熱器' => '散熱設備',
        '主機開關延長線' => '線材類',
        '標籤雷射貼紙' => '軟體專區',
        '消耗性產品' => '消耗品',
    ];
    if (isset($aliases[$oldType])) return $aliases[$oldType];
    foreach ($map as $type => $needles) {
        if (product_category_has_any($hay, $needles)) return $type;
    }
    return $oldType !== '' && !in_array($oldType, ['電腦部門', '電腦類', '服裝', '服飾專區', '生活周邊', '生活用品'], true)
        ? $oldType
        : '電腦周邊';
}

function product_category_detect_brand(string $hay, string $oldBrand): string
{
    $oldBrand = trim($oldBrand);
    if ($oldBrand !== '' && !in_array($oldBrand, ['不指定品牌', '三線', '三線廠牌', '雜牌'], true)) return $oldBrand;
    $brands = [
        'ASUS' => ['ASUS', '華碩'],
        'MSI' => ['MSI', '微星'],
        'GIGABYTE' => ['GIGABYTE', '技嘉'],
        'ASRock' => ['ASRock', '華擎'],
        'Intel' => ['Intel', 'INTEL'],
        'AMD' => ['AMD'],
        'NVIDIA' => ['NVIDIA'],
        'Kingston' => ['Kingston', '金士頓'],
        'Samsung' => ['Samsung', '三星'],
        'SanDisk' => ['SanDisk', 'SANDISK'],
        'WD' => ['WD ', 'Western Digital', '威騰'],
        'Seagate' => ['Seagate', '希捷'],
        'Crucial' => ['Crucial', '美光'],
        'ADATA' => ['ADATA', '威剛'],
        'TeamGroup' => ['TeamGroup', '十銓'],
        'Transcend' => ['Transcend', '創見'],
        'Logitech' => ['Logitech', '羅技'],
        'EVGA' => ['EVGA'],
        'CORSAIR' => ['CORSAIR', '海盜船'],
        'Cooler Master' => ['Cooler Master', '酷碼'],
        'Thermaltake' => ['Thermaltake', '曜越'],
        'TP-Link' => ['TP-Link', 'TP-LINK'],
        'D-Link' => ['D-Link', 'D-LINK'],
        'Dell' => ['Dell', 'DELL', '戴爾'],
        'Acer' => ['Acer', 'ACER', '宏碁'],
    ];
    foreach ($brands as $canonical => $needles) {
        if (product_category_has_any($hay, $needles)) return $canonical;
    }
    return $oldBrand;
}

function product_category_clean_spec(string $type, string $brand, string $spec, string $productSpec, string $title): string
{
    $spec = trim($spec);
    $productSpec = trim($productSpec);
    $brandLike = ['華碩', '技嘉', '微星', 'ASUS', 'MSI', 'GIGABYTE', 'ASRock', 'NVIDIA', '宏碁', '雜牌', '三線', '三線廠牌', '不指定品牌', $brand];
    $isBrandLike = $spec === '' || in_array($spec, $brandLike, true) || $spec === $brand;
    if (in_array($type, ['主機板', '顯示卡', '處理器', '電源供應器', '記憶體', '硬碟SSD'], true) && $isBrandLike) {
        if ($productSpec !== '' && !in_array($productSpec, $brandLike, true)) return $productSpec;
        if ($type === '主機板' && preg_match('/(LGA\s?\d{3,4}|AM[345]|H\d{2,3}M?|B\d{3}|Z\d{3}|X\d{3}|A\d{3}M?)/i', $title, $match)) {
            return strtoupper(str_replace(' ', '', $match[1]));
        }
        if ($type === '顯示卡' && preg_match('/(RTX\s?\d{3,4}|GTX\s?\d{3,4}|RX\s?\d{3,4}\s?XT?)/i', $title, $match)) {
            return strtoupper(preg_replace('/\s+/', '', $match[1]));
        }
    }
    if ($spec === '男性專區' || $spec === '女性專區' || $spec === '管家婆庫存' || $spec === '一般規格') {
        return $productSpec !== '' ? $productSpec : '';
    }
    return $spec;
}

function product_category_classify(array $row): array
{
    $oldGroup = trim((string)($row['category_group'] ?? ($row['group'] ?? ($row['department'] ?? ''))));
    $oldType = trim((string)($row['category_type'] ?? ($row['type'] ?? ($row['main_category'] ?? ''))));
    $oldBrand = trim((string)($row['category_brand'] ?? ($row['brand'] ?? '')));
    $oldSpec = trim((string)($row['category_spec'] ?? ($row['spec'] ?? '')));
    $title = trim((string)($row['title'] ?? ($row['product_name'] ?? '')));
    $productSpec = trim((string)($row['spec'] ?? ''));
    if (isset($row['category_spec']) && isset($row['spec']) && $row['category_spec'] !== $row['spec']) {
        $productSpec = trim((string)$row['spec']);
    }
    $hay = product_category_haystack($row);

    if (!product_is_inventory_item($row)) {
        $kind = product_service_kind($row);
        $meta = product_service_meta($kind);
        return [
            'group' => $meta['group'] ?? $oldGroup,
            'type' => $meta['type'] ?? ($oldType !== '' ? $oldType : '工資'),
            'brand' => $oldBrand === '不指定品牌' ? '' : $oldBrand,
            'spec' => $oldSpec,
        ];
    }

    $fashionType = product_category_fashion_type($hay);
    $isFashion = $oldType === '服飾專區' || $oldGroup === '服裝' || $oldGroup === '服裝部門'
        || in_array($oldType, ['上衣', '褲子', '鞋子', '包包', '帽子', '服飾配件'], true)
        || (
            in_array($fashionType, ['包包', '鞋子', '上衣', '褲子', '帽子'], true)
            && !in_array($oldType, ['3C周邊', '硬碟外接盒', '電腦周邊', '線材類', '線材類(網路頭）', '主機板', '顯示卡'], true)
        );
    if ($isFashion && $fashionType !== '') {
        $gender = product_category_gender($hay, $oldSpec, $oldType) ?: '男性專區';
        return [
            'group' => $gender,
            'type' => $fashionType,
            'brand' => $oldBrand === '不指定品牌' ? '' : $oldBrand,
            'spec' => product_category_clean_spec($fashionType, $oldBrand, $oldSpec, $productSpec, $title),
        ];
    }
    if ($oldType === '服飾專區') {
        $gender = product_category_gender($hay, $oldSpec, $oldType) ?: '男性專區';
        return [
            'group' => $gender,
            'type' => $fashionType !== '' ? $fashionType : '包包',
            'brand' => $oldBrand === '不指定品牌' ? '' : $oldBrand,
            'spec' => product_category_clean_spec('包包', $oldBrand, $oldSpec, $productSpec, $title),
        ];
    }

    $lifeOldTypes = ['健康設備', '燈飾專區', '露營燈', '毛巾', '清潔劑', '枕頭類', '汽車周邊用品', '施工設備', '探測設備', '收納盒/包/杯', '電動手工具周邊', '生活周邊', '生活用品', '電子磅秤'];
    $lifeType = product_category_life_type($hay, $oldType, $oldSpec);
    $forceLife = $oldSpec === '紙類'
        || in_array($oldType, $lifeOldTypes, true)
        || $oldGroup === '生活周邊'
        || in_array($lifeType, ['衛生紙', '廚房用具', '手工具', '行李箱'], true);
    if ($forceLife && $lifeType !== '') {
        return [
            'group' => '生活周邊',
            'type' => $lifeType,
            'brand' => product_category_detect_brand($hay, $oldBrand),
            'spec' => product_category_clean_spec($lifeType, $oldBrand, $oldSpec, $productSpec, $title) ?: $oldSpec,
        ];
    }
    $type = product_category_hardware_type($hay, $oldType);
    $brand = product_category_detect_brand($hay, $oldBrand);
    $spec = product_category_clean_spec($type, $brand, $oldSpec, $productSpec, $title);
    return [
        'group' => '組裝硬體',
        'type' => $type !== '' ? $type : '電腦周邊',
        'brand' => $brand,
        'spec' => $spec,
    ];
}

function product_category_tree_state_path(): string
{
    return data_path('product_category_tree_state');
}

function product_category_tree_needed(): bool
{
    $state = read_json_object('product_category_tree_state');
    return (int)($state['version'] ?? 0) < PRODUCT_CATEGORY_TREE_VERSION;
}

function product_category_tree_migrate(array &$categories, array &$products, array &$mappings = null, bool $write = true): array
{
    $movedProducts = 0;
    $movedRules = 0;
    $ensured = 0;
    $neededRules = [];
    foreach ($products as &$product) {
        if (!is_array($product)) continue;
        $classified = product_category_classify($product);
        $oldGroup = trim((string)($product['category_group'] ?? ($product['department'] ?? '')));
        $oldType = trim((string)($product['category_type'] ?? ($product['main_category'] ?? '')));
        $oldBrand = trim((string)($product['category_brand'] ?? ''));
        $oldSpec = trim((string)($product['category_spec'] ?? ''));
        $neededRules[$classified['group'] . "\n" . $classified['type'] . "\n" . $classified['brand'] . "\n" . $classified['spec']] = $classified;
        if ($oldGroup === $classified['group'] && $oldType === $classified['type'] && $oldBrand === $classified['brand'] && $oldSpec === $classified['spec']) {
            continue;
        }
        $product['category_group'] = $classified['group'];
        $product['category_type'] = $classified['type'];
        $product['category_brand'] = $classified['brand'];
        $product['category_spec'] = $classified['spec'];
        if (isset($product['main_category'])) $product['main_category'] = $classified['type'];
        $product['updated_at'] = date('c');
        $movedProducts++;
    }
    unset($product);

    $seen = [];
    $kept = [];
    foreach ($categories as $category) {
        if (!is_array($category)) continue;
        $fake = [
            'category_group' => $category['group'] ?? '',
            'category_type' => $category['type'] ?? '',
            'category_brand' => $category['brand'] ?? '',
            'category_spec' => $category['spec'] ?? '',
            'title' => trim(($category['type'] ?? '') . ' ' . ($category['brand'] ?? '') . ' ' . ($category['spec'] ?? '')),
            'spec' => $category['spec'] ?? '',
        ];
        $classified = product_category_classify($fake);
        $before = [$category['group'] ?? '', $category['type'] ?? '', $category['brand'] ?? '', $category['spec'] ?? ''];
        $after = [$classified['group'], $classified['type'], $classified['brand'], $classified['spec']];
        if ($before !== $after) $movedRules++;
        $category['group'] = $classified['group'];
        $category['type'] = $classified['type'];
        $category['brand'] = $classified['brand'];
        $category['spec'] = $classified['spec'];
        $typeCode = category_type_code($classified['type'], $category['type_code'] ?? '', $category['barcode_prefix'] ?? '');
        if ($typeCode !== '') {
            $category['type_code'] = $typeCode;
            if (trim((string)($category['barcode_prefix'] ?? '')) === '') $category['barcode_prefix'] = $typeCode;
        }
        $category['updated_at'] = date('c');
        $key = implode("\n", $after);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $kept[] = $category;
    }
    $categories = $kept;
    foreach ($neededRules as $classified) {
        ensure_product_category_rule($categories, $classified['group'], $classified['type'], $classified['brand'], $classified['spec']);
        $ensured++;
    }

    $seed = [
        ['組裝硬體', '主機板', 'ASUS', 'LGA1700', 'MB'],
        ['組裝硬體', '顯示卡', 'ASUS', 'RTX', 'GPU'],
        ['男性專區', '包包', '', '', 'BAG'],
        ['男性專區', '鞋子', '', '', 'SHOE'],
        ['女性專區', '包包', '', '', 'BAG'],
        ['女性專區', '鞋子', '', '', 'SHOE'],
        ['生活周邊', '衛生紙', '', '', 'PAPER'],
        ['生活周邊', '廚房用具', '', '', 'KITC'],
        ['生活周邊', '手工具', '', '', 'TOOL'],
        ['生活周邊', '行李箱', '', '', 'LUGG'],
    ];
    foreach ($seed as $row) {
        ensure_product_category_rule($categories, $row[0], $row[1], $row[2], $row[3]);
        $ensured++;
    }

    if (is_array($mappings)) {
        foreach ($mappings as &$mapping) {
            if (!is_array($mapping)) continue;
            $fake = [
                'category_group' => $mapping['source_group'] ?? '',
                'category_type' => $mapping['source_type'] ?? '',
                'title' => (string)($mapping['source_type'] ?? ''),
            ];
            $classified = product_category_classify($fake);
            $mapping['source_group'] = $classified['group'];
            $mapping['source_type'] = $classified['type'];
        }
        unset($mapping);
    }

    if ($write) {
        write_data('product_categories', $categories);
        write_data('products', $products);
        if (is_array($mappings)) write_data('marketplace_category_mappings', $mappings);
        file_put_contents(product_category_tree_state_path(), json_encode([
            'version' => PRODUCT_CATEGORY_TREE_VERSION,
            'applied_at' => date('c'),
            'moved_products' => $movedProducts,
            'moved_rules' => $movedRules,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    return [
        'moved_products' => $movedProducts,
        'moved_rules' => $movedRules,
        'ensured' => $ensured,
        'category_count' => count($categories),
        'product_count' => count($products),
    ];
}
