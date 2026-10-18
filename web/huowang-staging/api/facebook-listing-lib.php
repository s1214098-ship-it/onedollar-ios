<?php
declare(strict_types=1);

const FB_BROKER_LINE = '經紀人：馮雋引（113）000395字號';
const FB_CARD_URL = 'https://huowang.paohui.org/huowang/assets/huowang-card.jpg';
const FB_PAGE_ACCOUNT = '羅東透天農舍 郭火旺（粉絲團）';
const FB_PERSONAL_ACCOUNT = '郭火旺（個人）';
const FB_PAGE_NAME = '羅東透天農舍 郭火旺粉絲團動態';
const FB_PAGE_URL = 'https://www.facebook.com/profile.php?id=61571273973945';
const FB_PAGE_GROUP_ID = 'PAGE-LUODONG-HUOWANG-DAILY20';
const FB_TRUEHAPPINESS_NAME = '真幸福房地產（宜蘭縣市專營土地，農地，建地，房地產）';
const FB_TRUEHAPPINESS_URL = 'https://www.facebook.com/groups/2208778192674204/';
const FB_TRUEHAPPINESS_GROUP_ID = 'GRP-guo_huowang-2208778192674204';
const FB_STORE_NAME = '永慶不動產羅東文化盛群加盟店';
const FB_PUBLIC_PHONE = '0911-294-374';
const FB_PUBLIC_LINE = '0911294374';
const FB_PUBLIC_AGENT = '郭火旺';
const FB_PUBLIC_SITE_ZH = 'https://huowang.paohui.org/huowang/?lang=zh';
const FB_PUBLIC_SITE_VI = 'https://huowang.paohui.org/huowang/?lang=vi';
const FB_PAGE_FAN_ACCOUNT = '郭火旺（粉絲團）';
const FB_AD_HOUR_START = 7;
const FB_AD_HOUR_END = 21;
const FB_AD_PERSONAL_PER_PAGE = 10;
const FB_AD_REGIONS_PER_HOUR = 1;

function fb_text(mixed $value): string {
    return trim((string)($value ?? ''));
}

function fb_id(mixed $value): string {
    return preg_replace('/[^A-Za-z0-9_-]/', '', fb_text($value));
}

function fb_rows(mixed $value): array {
    if (is_array($value)) {
        return array_values($value);
    }
    if (is_string($value) && trim($value) !== '') {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }
    return [];
}

function fb_first(array $row, array $keys): string {
    foreach ($keys as $key) {
        $value = fb_text($row[$key] ?? '');
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function fb_number(string $value): float {
    return (float)preg_replace('/[^0-9.\-]/', '', $value);
}

function fb_read_json(string $file): array {
    $data = json_decode(@file_get_contents($file) ?: '{}', true);
    return is_array($data) ? $data : [];
}

function fb_write_json(string $file, array $data, string $backupSuffix = ''): void {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        throw new RuntimeException('JSON 編碼失敗');
    }
    if ($backupSuffix !== '') {
        @copy($file, $file . $backupSuffix);
    }
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $file)) {
        throw new RuntimeException('Facebook 排程資料寫入失敗');
    }
}

function fb_merge_publish(array $existing, array $patch): array {
    return array_merge($existing, $patch, ['updatedAt' => date('c')]);
}

function fb_image_urls(array $row): array {
    $values = [];
    foreach (['images', 'photos', 'photoList', 'gallery', 'imageUrls', 'backendImageUrls'] as $key) {
        if (isset($row[$key]) && is_array($row[$key])) {
            $values = array_merge($values, $row[$key]);
        }
    }
    foreach (['image', 'imageUrl', 'img', 'photo', 'cover', 'thumb', 'thumbnail', 'mainImage'] as $key) {
        if (isset($row[$key])) {
            $values[] = $row[$key];
        }
    }
    $out = [];
    foreach ($values as $value) {
        if (is_array($value)) {
            $value = $value['url'] ?? $value['src'] ?? $value['imageUrl'] ?? $value['data'] ?? '';
        }
        $value = fb_text($value);
        if ($value === '' || $value === '[object Object]') {
            continue;
        }
        if ((str_starts_with($value, 'http://') || str_starts_with($value, 'https://') || str_starts_with($value, 'data:image/')) && !in_array($value, $out, true)) {
            $out[] = $value;
        }
    }
    return array_slice($out, 0, 12);
}

function fb_price_text(string $price): string {
    $price = fb_text($price);
    if ($price === '') {
        return '洽詢';
    }
    if (!preg_match('/萬|元/u', $price)) {
        $price .= '萬';
    }
    if (preg_match('/^([0-9,.]+)\s*萬$/u', $price, $m)) {
        return number_format((float)str_replace(',', '', $m[1])) . ' 萬';
    }
    return $price;
}

function fb_age_text(string $age): string {
    $age = fb_text($age);
    if ($age === '' || $age === '--') {
        return '';
    }
    if (fb_number($age) <= 0) {
        return '新成屋';
    }
    return preg_match('/年/u', $age) ? $age : ($age . ' 年');
}

function fb_ensure_broker(string $text): string {
    $text = trim((string)preg_replace('/\n?經紀人：.*(?:字號|[0-9]{6}).*/u', '', $text));
    return $text . "\n" . FB_BROKER_LINE;
}

function fb_public_site_lines(): array {
    return [
        '🌐 火旺自有網站看更多物件：',
        '中文：' . FB_PUBLIC_SITE_ZH,
        'Tiếng Việt：' . FB_PUBLIC_SITE_VI,
    ];
}

function fb_post_text(array $item, string $headline = ''): string {
    $title = fb_text($item['title'] ?? '');
    $city = fb_text($item['city'] ?? '');
    $district = fb_text($item['district'] ?? '');
    $kind = fb_text($item['kind'] ?? $item['type'] ?? '');
    $layout = fb_text($item['layout'] ?? '');
    $floor = fb_text($item['floor'] ?? '');
    $age = fb_age_text(fb_text($item['houseAge'] ?? ''));
    $build = fb_text($item['mainBuildArea'] ?? $item['build'] ?? '');
    $land = fb_text($item['landArea'] ?? '');
    $price = fb_price_text(fb_text($item['price'] ?? ''));
    $unit = fb_text($item['unitPrice'] ?? '');
    $sourceUrl = fb_text($item['sourceUrl'] ?? '');
    $caseId = fb_text($item['id'] ?? $item['caseId'] ?? '');
    $lines = [];
    if ($headline !== '') {
        $lines[] = $headline;
    } else {
        $lines[] = '🏡 ' . $title;
    }
    if ($headline !== '') {
        $lines[] = $title;
    }
    $lines[] = '';
    $lines[] = '📍 地點：' . $city . $district;
    if ($kind !== '') {
        $lines[] = '🏷️ 型態：' . $kind;
    }
    if ($layout !== '' && $layout !== '--' && $layout !== '格局洽詢') {
        if ($layout === '土地') {
            $lines[] = '🏷️ 類型：土地';
        } else {
            $layout = trim((string)preg_replace('/([0-9]+)\s*(房|廳|衛)/u', '$1 $2 ', $layout));
            $lines[] = '🏠 格局：' . $layout;
        }
    }
    $areaParts = [];
    if ($build !== '') {
        $areaParts[] = '建物 ' . $build . ' 坪';
    }
    if ($land !== '') {
        $areaParts[] = '土地 ' . $land . ' 坪';
    }
    $area = $areaParts ? implode('｜', $areaParts) : fb_text($item['areaText'] ?? '');
    if ($area !== '') {
        $lines[] = '📐 坪數：' . $area;
    }
    if ($floor !== '' && $floor !== '--') {
        $lines[] = '🏢 樓層：' . $floor;
    }
    if ($age !== '') {
        $lines[] = '⏳ 屋齡：' . $age;
    }
    $lines[] = '💰 總價：' . $price;
    if ($unit !== '') {
        $lines[] = '📊 單價：約 ' . str_replace(['/坪', '/ 坪'], ['／坪', '／坪'], $unit);
    }
    if ($caseId !== '') {
        $lines[] = '🔖 物件編號：' . $caseId;
    }
    if ($sourceUrl !== '') {
        $lines[] = '🔗 詳細資料：' . $sourceUrl;
    }
    $summary = fb_text($item['summaryZh'] ?? '') ?: fb_text($item['adText'] ?? '');
    if ($summary !== '') {
        $lines[] = '';
        $lines[] = $summary;
    }
    $amenities = is_array($item['amenities'] ?? null) ? $item['amenities'] : [];
    if ($amenities) {
        $lines[] = '';
        $lines[] = '🏙️ 周邊生活機能';
        foreach ($amenities as $amenity) {
            $meters = (int)($amenity['meters'] ?? 0);
            $distance = $meters < 1000 ? $meters . ' 公尺' : number_format($meters / 1000, 1) . ' 公里';
            $lines[] = '・' . fb_text($amenity['label'] ?? '機能') . '：' . fb_text($amenity['name'] ?? '') . '｜約 ' . $distance;
        }
    }
    $kindTag = $kind !== '' ? $kind : (mb_strpos($title, '農舍') !== false ? '農舍' : (mb_strpos($title, '土地') !== false ? '土地' : '房屋'));
    $areaTag = preg_replace('/[縣市]$/u', '', $city);
    $tags = '#郭火旺';
    if ($areaTag !== '') {
        $tags .= ' #' . $areaTag . '房地產';
        $tags .= ' #' . $areaTag . $kindTag;
    }
    if ($district !== '') {
        $tags .= ' #' . $district . $kindTag;
    }
    $lines = array_merge($lines, [
        '',
        '🏢 ' . FB_STORE_NAME,
        '👤 ' . FB_PUBLIC_AGENT,
        '📞 服務專線：' . FB_PUBLIC_PHONE,
        '💬 LINE ID：' . FB_PUBLIC_LINE,
        ...fb_public_site_lines(),
        '',
        '詳細資料歡迎私訊郭火旺。',
        '',
        $tags,
    ]);
    return fb_ensure_broker(implode("\n", $lines));
}

function fb_overseas_text(string $text): bool {
    return (bool)preg_match('/越南|Vietnam|胡志明|河內|Hanoi|海外|中國大陸|中国大陆|馬來西亞|马来西亚|新加坡|柬埔|泰國|泰国|菲律賓|菲律宾|BẤT ĐỘNG|Bất động/iu', $text);
}

function fb_taiwan_regions(): array {
    return [
        'north' => [
            'key' => 'north',
            'label' => '北',
            'cities' => ['基隆市', '台北市', '臺北市', '新北市', '桃園市', '新竹市', '新竹縣'],
            'keywords' => ['北部', '北區', '北台灣', '北臺灣'],
        ],
        'central' => [
            'key' => 'central',
            'label' => '中',
            'cities' => ['苗栗縣', '台中市', '臺中市', '彰化縣', '南投縣', '雲林縣'],
            'keywords' => ['中部', '中區', '中台灣', '中臺灣'],
        ],
        'south' => [
            'key' => 'south',
            'label' => '南',
            'cities' => ['嘉義市', '嘉義縣', '台南市', '臺南市', '高雄市', '屏東縣'],
            'keywords' => ['南部', '南區', '南台灣', '南臺灣'],
        ],
        'east' => [
            'key' => 'east',
            'label' => '東',
            'cities' => ['宜蘭縣', '花蓮縣', '台東縣', '臺東縣'],
            'keywords' => ['東部', '東區', '東台灣', '東臺灣', '宜蘭'],
        ],
    ];
}

function fb_cadence_defaults(): array {
    return [
        [
            'id' => 'GUOHUOWANG',
            'label' => '郭火旺',
            'account' => FB_PERSONAL_ACCOUNT,
            'accountKey' => 'personal_primary',
            'publisherId' => '100009183179584',
            'pageName' => '郭火旺個人動態',
            'pageUrl' => '',
            'groupId' => 'PAGE-GUOHUOWANG-PERSONAL',
            'personalViaGroups' => true,
        ],
        [
            'id' => 'NONGSHE',
            'label' => '農舍郭火旺',
            'account' => FB_PAGE_ACCOUNT,
            'accountKey' => 'fanpage',
            'publisherId' => '61571273973945',
            'pageName' => FB_PAGE_NAME,
            'pageUrl' => FB_PAGE_URL,
            'groupId' => FB_PAGE_GROUP_ID,
            'personalViaGroups' => false,
        ],
        [
            'id' => 'GUOHUOWANGFAN',
            'label' => '郭火旺',
            'account' => FB_PAGE_FAN_ACCOUNT,
            'accountKey' => 'personal_secondary',
            'publisherId' => '61574296001770',
            'pageName' => '郭火旺粉絲團動態',
            'pageUrl' => '',
            'groupId' => 'PAGE-GUOHUOWANG-FANPAGE',
            'personalViaGroups' => false,
        ],
    ];
}

function fb_cadence_path(): string {
    $here = dirname(__DIR__) . '/facebook-ad-cadence.json';
    return is_file($here) ? $here : '';
}

function fb_fan_pages(): array {
    $defaults = [];
    foreach (fb_cadence_defaults() as $page) {
        $defaults[$page['id']] = $page;
    }
    $pages = fb_cadence_defaults();
    $path = fb_cadence_path();
    if ($path !== '') {
        $data = json_decode((string)file_get_contents($path), true);
        if (is_array($data['pages'] ?? null) && $data['pages']) {
            $pages = $data['pages'];
        }
    }
    $out = [];
    foreach ($pages as $page) {
        if (!is_array($page)) {
            continue;
        }
        $id = fb_text($page['id'] ?? '');
        $base = $defaults[$id] ?? [];
        $merged = array_merge($base, $page);
        if (fb_text($merged['pageUrl'] ?? '') === '' && fb_text($merged['publisherId'] ?? '') !== '') {
            $merged['pageUrl'] = 'https://www.facebook.com/profile.php?id=' . fb_text($merged['publisherId']);
        }
        if (fb_text($merged['id'] ?? '') === '') {
            continue;
        }
        $out[] = $merged;
    }
    return $out ?: fb_cadence_defaults();
}

function fb_allowed_accounts(): array {
    $out = [FB_PERSONAL_ACCOUNT, FB_PAGE_ACCOUNT, FB_PAGE_FAN_ACCOUNT];
    foreach (fb_fan_pages() as $page) {
        $out[] = fb_text($page['account'] ?? '');
    }
    return array_values(array_unique(array_filter($out)));
}

function fb_unverified_account(string $account): bool {
    return $account === ''
        || (bool)preg_match('/待核對/u', $account)
        || !in_array($account, fb_allowed_accounts(), true);
}

function fb_ad_policy(array $publish = []): array {
    $saved = is_array($publish['facebookFanPageAdPolicy'] ?? null) ? $publish['facebookFanPageAdPolicy'] : [];
    $file = [];
    $path = fb_cadence_path();
    if ($path !== '') {
        $decoded = json_decode((string)file_get_contents($path), true);
        $file = is_array($decoded) ? $decoded : [];
    }
    $start = max(6, min(20, (int)($saved['hourStart'] ?? $file['hourStart'] ?? FB_AD_HOUR_START)));
    $end = max($start, min(21, (int)($saved['hourEnd'] ?? $file['hourEnd'] ?? FB_AD_HOUR_END)));
    $personal = max(1, min(30, (int)($saved['personalPerPage'] ?? $file['personalPerPage'] ?? FB_AD_PERSONAL_PER_PAGE)));
    $enabled = !array_key_exists('enabled', $saved) || (bool)$saved['enabled'];
    $hours = $end - $start + 1;
    $pages = fb_fan_pages();
    $regionCount = max(1, count(fb_taiwan_regions()));
    $rawPerRegion = (int)($saved['regionsPerHour'] ?? $file['regionsPerHour'] ?? FB_AD_REGIONS_PER_HOUR);
    // 舊值 4 代表「每小時北中南東四則」，不是每區 4 則。
    $perRegion = $rawPerRegion >= $regionCount ? 1 : max(1, min(4, $rawPerRegion));
    $regionJobsPerHour = $regionCount * $perRegion;
    return [
        'enabled' => $enabled,
        'mode' => fb_text($file['mode'] ?? $saved['mode'] ?? 'three_fanpage_personal10_regions_hourly1'),
        'consumer' => fb_text($file['consumer'] ?? '火旺臉書上架助手'),
        'hourStart' => $start,
        'hourEnd' => $end,
        'hoursPerDay' => $hours,
        'personalPerPage' => $personal,
        'regionsPerHour' => $perRegion,
        'regionCount' => $regionCount,
        'regionJobsPerHour' => $regionJobsPerHour,
        'regionJobsPerPage' => $hours * $regionJobsPerHour,
        'pageCount' => count($pages),
        'pages' => array_map(fn($page) => [
            'id' => $page['id'],
            'label' => $page['label'],
            'account' => $page['account'],
            'accountKey' => $page['accountKey'] ?? '',
            'publisherId' => $page['publisherId'] ?? '',
            'pageName' => $page['pageName'],
            'pageUrl' => $page['pageUrl'],
            'personalViaGroups' => !empty($page['personalViaGroups']),
        ], $pages),
        'regions' => array_values(array_map(fn($region) => [
            'key' => $region['key'],
            'label' => $region['label'],
            'cities' => $region['cities'],
        ], fb_taiwan_regions())),
        'dailyTarget' => (count($pages) * $personal) + (count($pages) * $hours * $regionJobsPerHour) + 20,
        'note' => '三個粉絲團各有「個人」每日 ' . $personal . ' 則，以及北中南東每小時各一則（' . sprintf('%02d', $start) . ':00–' . sprintf('%02d', $end) . ':00）。真幸福社團每日 20 則維持既有通道。',
    ];
}

function fb_city_region(string $city): string {
    foreach (fb_taiwan_regions() as $key => $region) {
        if (in_array($city, $region['cities'], true)) {
            return $key;
        }
    }
    return '';
}

function fb_group_region(array $group): string {
    $lane = fb_text($group['lane'] ?? $group['region'] ?? '');
    $map = ['personal' => 'personal', '個人' => 'personal', 'north' => 'north', '北' => 'north', 'central' => 'central', '中' => 'central', 'south' => 'south', '南' => 'south', 'east' => 'east', '東' => 'east'];
    if (isset($map[$lane])) {
        return $map[$lane];
    }
    $hay = fb_text($group['name'] ?? '') . ' ' . implode(' ', array_map('strval', is_array($group['areas'] ?? null) ? $group['areas'] : []));
    foreach (fb_taiwan_regions() as $key => $region) {
        foreach ($region['keywords'] as $keyword) {
            if (mb_strpos($hay, $keyword) !== false) {
                return $key;
            }
        }
        foreach ($region['cities'] as $city) {
            if ($city !== '' && mb_strpos($hay, $city) !== false) {
                return $key;
            }
        }
    }
    return '';
}

function fb_region_groups(array $groups, string $account, string $regionKey): array {
    $out = [];
    foreach ($groups as $group) {
        if (($group['enabled'] ?? true) === false) {
            continue;
        }
        if (fb_text($group['account'] ?? '') !== $account) {
            continue;
        }
        $name = fb_text($group['name'] ?? '');
        $url = fb_text($group['url'] ?? '');
        if ($url === FB_TRUEHAPPINESS_URL || mb_strpos($name, '真幸福') !== false) {
            continue;
        }
        if (fb_excluded_group_name($name . ' ' . $url)) {
            continue;
        }
        if (fb_group_region($group) !== $regionKey) {
            continue;
        }
        $out[] = $group;
    }
    return $out;
}

function fb_page_region_destination(array $page, string $regionKey, array $groups, array $usedUrls): ?array {
    $region = fb_taiwan_regions()[$regionKey] ?? null;
    if (!$region) {
        return null;
    }
    $matches = fb_region_groups($groups, fb_text($page['account'] ?? ''), $regionKey);
    $best = $matches ? fb_best_personal_group($matches, ['city' => $region['cities'][0] ?? '', 'district' => ''], $usedUrls) : null;
    if ($best) {
        return [
            'groupId' => fb_id($best['id'] ?? ''),
            'destinationName' => fb_text($best['name'] ?? ''),
            'destinationUrl' => fb_text($best['url'] ?? ''),
        ];
    }
    $pageUrl = fb_text($page['pageUrl'] ?? '');
    if ($pageUrl === '') {
        return null;
    }
    return [
        'groupId' => fb_id($page['groupId'] ?? '') . '-' . strtoupper($regionKey),
        'destinationName' => fb_text($page['pageName'] ?? $page['label'] ?? '') . '｜' . $region['label'] . '部廣告',
        'destinationUrl' => $pageUrl,
    ];
}

function fb_hourly_times(string $targetDate, int $hourStart, int $hourEnd, int $minute): array {
    $out = [];
    for ($hour = $hourStart; $hour <= $hourEnd; $hour++) {
        $out[] = strtotime($targetDate . ' ' . sprintf('%02d:%02d:00', $hour, $minute));
    }
    return $out;
}

function fb_personal_slot_times(string $targetDate, int $count, int $offsetMinutes = 0): array {
    $base = ['12:10', '12:25', '12:40', '12:55', '18:10', '18:25', '18:40', '18:55', '19:10', '19:25', '13:10', '13:25', '17:10', '17:25', '19:40', '20:00', '20:20', '20:40', '21:00', '16:10', '16:25', '16:40', '17:40', '18:05', '14:10', '14:25', '15:10', '15:25', '11:40', '11:55'];
    $slots = array_slice($base, 0, max(1, $count));
    return array_map(function ($time) use ($targetDate, $offsetMinutes) {
        return strtotime($targetDate . ' ' . $time) + ($offsetMinutes * 60);
    }, $slots);
}

function fb_pick_region_items(array $items, string $regionKey, array $hourUsed, int $limit): array {
    $prefer = array_values(array_filter($items, function ($item) use ($regionKey, $hourUsed) {
        if (!empty($hourUsed[$item['id'] ?? ''])) {
            return false;
        }
        return fb_city_region(fb_text($item['city'] ?? '')) === $regionKey;
    }));
    if (count($prefer) >= $limit) {
        return array_slice($prefer, 0, $limit);
    }
    $fallback = array_values(array_filter($items, fn($item) => empty($hourUsed[$item['id'] ?? ''])));
    $merged = [];
    $seen = [];
    foreach (array_merge($prefer, $fallback) as $item) {
        $id = $item['id'] ?? '';
        if ($id === '' || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $merged[] = $item;
        if (count($merged) >= $limit) {
            break;
        }
    }
    return $merged;
}

function fb_count_jobs_matching(array $jobs, string $today, callable $match): array {
    $out = ['queued' => 0, 'published' => 0, 'pending_review' => 0, 'blocked' => 0, 'failed' => 0, 'running' => 0, 'total' => 0];
    foreach ($jobs as $job) {
        if (fb_job_day($job) !== $today) {
            continue;
        }
        if (!$match($job)) {
            continue;
        }
        $status = fb_text($job['status'] ?? 'unknown');
        $out['total']++;
        if (isset($out[$status])) {
            $out[$status]++;
        }
    }
    return $out;
}

function fb_active_job(array $job): bool {
    return !in_array($job['status'] ?? '', ['cancelled', 'failed', 'blocked'], true);
}

function fb_excluded_group_name(string $name): bool {
    if (fb_overseas_text($name)) {
        return true;
    }
    return (bool)preg_match('/法拍|二手商品|全新二手|求租資訊|出租、求租/u', $name);
}

function fb_same_store_items(array $shared): array {
    $items = [];
    foreach (fb_rows($shared['sameStoreItems'] ?? []) as $row) {
        if (!is_array($row) || !empty($row['hidden']) || ($row['saleStatus'] ?? '') === 'sold') {
            continue;
        }
        $id = fb_first($row, ['id', 'caseId', 'publicNo', 'sourcePublicNo']);
        $title = fb_first($row, ['title', 'objectName', 'name', 'caseName']);
        $city = fb_first($row, ['city', 'county']);
        $district = fb_first($row, ['district', 'area', 'town']);
        $images = fb_image_urls($row);
        if ($id === '' || $title === '' || $city === '' || $district === '' || !$images) {
            continue;
        }
        $store = fb_first($row, ['storeName', 'sourceStore', 'branchName', 'company']);
        if ($store !== '' && mb_strpos($store, '羅東文化盛群') === false) {
            continue;
        }
        $main = fb_first($row, ['mainBuildArea', 'sameStoreMainBuildArea', 'buildingArea', 'buildingPing', 'buildArea', 'build']);
        $land = fb_first($row, ['landArea', 'sameStoreLandArea', 'landPing']);
        $total = fb_first($row, ['totalArea', 'totalPing', 'totalAreaQuick']);
        $price = fb_price_text(fb_first($row, ['price', 'sameStorePrice', 'totalPrice', 'salePrice', 'askingPrice']));
        $unit = fb_first($row, ['unitPrice', 'sameStoreUnitPrice']);
        if ($unit === '' && fb_number($price) > 0) {
            $base = fb_number($main) ?: (fb_number($total) ?: fb_number($land));
            if ($base > 0) {
                $unit = rtrim(rtrim(number_format(fb_number($price) / $base, 2, '.', ''), '0'), '.') . '萬/坪';
            }
        }
        $areaParts = [];
        if ($main !== '') {
            $areaParts[] = '建 ' . $main . '坪';
        }
        if ($land !== '') {
            $areaParts[] = '地 ' . $land . '坪';
        }
        if (!$areaParts && $total !== '') {
            $areaParts[] = '總 ' . $total . '坪';
        }
        $items[] = [
            'id' => $id,
            'title' => $title,
            'city' => $city,
            'district' => $district,
            'sourceCategory' => '同店',
            'kind' => fb_first($row, ['kind', 'type', 'category', 'propertyType']),
            'type' => fb_first($row, ['type', 'kind', 'category', 'propertyType']),
            'floor' => fb_first($row, ['floor']),
            'houseAge' => fb_first($row, ['houseAge']),
            'areaText' => implode(' / ', $areaParts),
            'mainBuildArea' => $main,
            'build' => $main,
            'landArea' => $land,
            'totalArea' => $total,
            'price' => $price,
            'unitPrice' => $unit,
            'layout' => fb_first($row, ['layout', 'sameStoreLayout', 'roomLayout']) ?: '格局洽詢',
            'summaryZh' => fb_text($row['summaryZh'] ?? $row['publicNote'] ?? ''),
            'adText' => fb_text($row['adText'] ?? ''),
            'sourceUrl' => fb_first($row, ['sourceUrl', 'url', 'listingUrl']),
            'imageUrls' => $images,
        ];
    }
    usort($items, fn($a, $b) => strcmp(($a['city'] . $a['district'] . $a['id']), ($b['city'] . $b['district'] . $b['id'])));
    return $items;
}

function fb_job_day(array $job): string {
    return substr(fb_text($job['scheduledAt'] ?? ''), 0, 10);
}

function fb_cancel_stale_jobs(array &$jobs, string $today): array {
    $counts = ['stale' => 0, 'overseas' => 0, 'unverified' => 0];
    foreach ($jobs as &$job) {
        if (($job['status'] ?? '') !== 'queued' && ($job['status'] ?? '') !== 'running') {
            continue;
        }
        $account = fb_text($job['account'] ?? '');
        $dest = fb_text($job['destinationName'] ?? '') . ' ' . fb_text($job['destinationUrl'] ?? '');
        $day = fb_job_day($job);
        $note = fb_text($job['note'] ?? '');
        if (fb_unverified_account($account)) {
            $job['status'] = 'blocked';
            $job['note'] = trim($note . '；未核准身分，已停止排隊');
            $job['updatedAt'] = date('c');
            $counts['unverified']++;
            continue;
        }
        if (fb_overseas_text($dest)) {
            $job['status'] = 'blocked';
            $job['note'] = trim($note . '；海外／非台灣社團，已停止排隊');
            $job['updatedAt'] = date('c');
            $counts['overseas']++;
            continue;
        }
        if ($day !== '' && $day < $today) {
            $job['status'] = 'cancelled';
            $job['note'] = trim($note . '；過期未發佈佇列已取消');
            $job['updatedAt'] = date('c');
            $counts['stale']++;
        }
    }
    unset($job);
    return $counts;
}

function fb_disable_overseas_groups(array &$groups): int {
    $updated = 0;
    foreach ($groups as &$group) {
        $hay = fb_text($group['name'] ?? '') . ' ' . fb_text($group['url'] ?? '');
        if (!fb_overseas_text($hay)) {
            continue;
        }
        if (($group['enabled'] ?? true) === false) {
            continue;
        }
        $group['enabled'] = false;
        $group['updatedAt'] = date('c');
        $group['note'] = trim(fb_text($group['note'] ?? '') . '；海外社團已停用');
        $updated++;
    }
    unset($group);
    return $updated;
}

function fb_used_case_ids(array $jobs, string $targetDate, int $recentDays = 14): array {
    $sameDay = [];
    $recent = [];
    $since = strtotime($targetDate . ' -' . $recentDays . ' days');
    foreach ($jobs as $job) {
        $caseId = fb_id($job['caseId'] ?? '');
        if ($caseId === '' || in_array($job['status'] ?? '', ['cancelled', 'failed', 'blocked'], true)) {
            continue;
        }
        $day = fb_job_day($job);
        if ($day === $targetDate) {
            $sameDay[$caseId] = true;
        }
        $ts = strtotime(fb_text($job['scheduledAt'] ?? $job['updatedAt'] ?? ''));
        if ($ts && $since && $ts >= $since && in_array($job['status'] ?? '', ['published', 'pending_review', 'queued', 'running'], true)) {
            $recent[$caseId] = true;
        }
    }
    return [$sameDay, $recent];
}

function fb_page_score(array $item): int {
    $score = 0;
    $district = fb_text($item['district'] ?? '');
    $hay = fb_text($item['kind'] ?? '') . ' ' . fb_text($item['title'] ?? '');
    if ($district === '羅東鎮') {
        $score += 50;
    } elseif (in_array($district, ['五結鄉', '冬山鄉'], true)) {
        $score += 28;
    } elseif (in_array($district, ['三星鄉', '礁溪鄉', '頭城鎮', '員山鄉', '壯圍鄉'], true)) {
        $score += 12;
    }
    if (mb_strpos($hay, '農舍') !== false) {
        $score += 30;
    } elseif (preg_match('/透天|別墅/u', $hay)) {
        $score += 24;
    } elseif (preg_match('/華廈|公寓/u', $hay)) {
        $score += 12;
    }
    if (count($item['imageUrls'] ?? []) >= 6) {
        $score += 5;
    }
    return $score;
}

function fb_pick_items(array $items, array $sameDay, array $recent, int $limit, bool $preferLuodongFarmhouse = false): array {
    $eligible = array_values(array_filter($items, fn($item) => empty($sameDay[$item['id']]) && empty($recent[$item['id']])));
    if (count($eligible) < $limit) {
        $eligible = array_values(array_filter($items, fn($item) => empty($sameDay[$item['id']])));
    }
    if ($preferLuodongFarmhouse) {
        usort($eligible, fn($a, $b) => fb_page_score($b) <=> fb_page_score($a) ?: strcmp($a['id'], $b['id']));
    }
    return array_slice($eligible, 0, $limit);
}

function fb_upsert_schedule(array &$schedules, array $schedule): void {
    foreach ($schedules as &$row) {
        if (($row['id'] ?? '') === ($schedule['id'] ?? '')) {
            $row = array_merge($row, $schedule, ['updatedAt' => date('c')]);
            unset($row);
            return;
        }
    }
    unset($row);
    $schedules[] = $schedule;
}

function fb_append_jobs(array &$jobs, array $selected, array $meta): int {
    $created = 0;
    $existing = [];
    foreach ($jobs as $job) {
        $existing[fb_text($job['id'] ?? '')] = true;
        if (in_array($job['status'] ?? '', ['cancelled', 'failed', 'blocked'], true)) {
            continue;
        }
        $hour = substr(fb_text($job['scheduledAt'] ?? ''), 11, 2);
        $existing[fb_job_day($job) . '|' . fb_id($job['caseId'] ?? '') . '|' . fb_text($job['destinationUrl'] ?? '') . '|' . fb_text($job['account'] ?? '') . '|' . fb_text($job['lane'] ?? '') . '|' . $hour] = true;
    }
    foreach ($selected as $index => $item) {
        $run = $meta['times'][$index] ?? null;
        if (!$run) {
            continue;
        }
        $jobId = $meta['idPrefix'] . date('Ymd-Hi', $run) . '-' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT);
        $dedupe = date('Y-m-d', $run) . '|' . $item['id'] . '|' . $meta['destinationUrl'] . '|' . $meta['account'] . '|' . fb_text($meta['lane'] ?? '') . '|' . date('H', $run);
        if (isset($existing[$jobId]) || isset($existing[$dedupe])) {
            continue;
        }
        $jobs[] = [
            'id' => $jobId,
            'scheduleId' => $meta['scheduleId'],
            'caseId' => $item['id'],
            'title' => $item['title'],
            'sourceCategory' => '同店',
            'city' => $item['city'],
            'district' => $item['district'],
            'kind' => $item['kind'] ?? '',
            'floor' => $item['floor'] ?? '',
            'houseAge' => $item['houseAge'] ?? '',
            'areaText' => $item['areaText'],
            'mainBuildArea' => $item['mainBuildArea'],
            'landArea' => $item['landArea'],
            'totalArea' => $item['totalArea'] ?? '',
            'price' => $item['price'],
            'unitPrice' => $item['unitPrice'],
            'layout' => $item['layout'],
            'imageUrls' => $item['imageUrls'],
            'businessCardUrl' => FB_CARD_URL,
            'postText' => fb_post_text($item, $meta['headlinePrefix'] . $item['city'] . $item['district']),
            'brokerDisclosureLine' => FB_BROKER_LINE,
            'postType' => '出售文',
            'account' => $meta['account'],
            'accountKey' => fb_text($meta['accountKey'] ?? ''),
            'groupId' => $meta['groupId'],
            'destinationName' => $meta['destinationName'],
            'destinationUrl' => $meta['destinationUrl'],
            'lane' => fb_text($meta['lane'] ?? ''),
            'region' => fb_text($meta['region'] ?? ''),
            'pageId' => fb_text($meta['pageId'] ?? ''),
            'pageLabel' => fb_text($meta['pageLabel'] ?? ''),
            'scheduledAt' => date('c', $run),
            'status' => 'queued',
            'postUrl' => '',
            'note' => $meta['note'],
            'createdAt' => date('c'),
            'updatedAt' => date('c'),
        ];
        $existing[$jobId] = true;
        $existing[$dedupe] = true;
        $created++;
    }
    return $created;
}

function fb_best_personal_group(array $groups, array $item, array $usedUrls): ?array {
    $best = null;
    $bestScore = PHP_INT_MIN;
    foreach ($groups as $group) {
        $url = fb_text($group['url'] ?? '');
        $name = fb_text($group['name'] ?? '');
        $areas = array_map('strval', is_array($group['areas'] ?? null) ? $group['areas'] : []);
        $score = 10;
        if (($item['district'] ?? '') !== '' && (in_array($item['district'], $areas, true) || mb_strpos($name, $item['district']) !== false)) {
            $score += 30;
        }
        if (($item['city'] ?? '') !== '' && in_array($item['city'], $areas, true)) {
            $score += 20;
        }
        if (isset($usedUrls[$url])) {
            $score -= 8 * $usedUrls[$url];
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $group;
        }
    }
    return $best;
}

function fb_yilan_personal_groups(array $groups): array {
    $out = [];
    foreach ($groups as $group) {
        if (($group['enabled'] ?? true) === false) {
            continue;
        }
        if (fb_text($group['account'] ?? '') !== FB_PERSONAL_ACCOUNT) {
            continue;
        }
        $name = fb_text($group['name'] ?? '');
        $url = fb_text($group['url'] ?? '');
        if ($url === FB_TRUEHAPPINESS_URL || mb_strpos($name, '真幸福') !== false) {
            continue;
        }
        if (fb_excluded_group_name($name . ' ' . $url)) {
            continue;
        }
        $areas = array_map('strval', is_array($group['areas'] ?? null) ? $group['areas'] : []);
        $hay = $name . ' ' . implode(' ', $areas);
        if (!preg_match('/宜蘭|羅東|五結|冬山|頭城|礁溪|三星|員山|壯圍|蘇澳/u', $hay)) {
            continue;
        }
        $out[] = $group;
    }
    return $out;
}

function fb_count_today(array $jobs, string $today, string $key, string $value): array {
    $out = ['queued' => 0, 'published' => 0, 'pending_review' => 0, 'blocked' => 0, 'failed' => 0, 'running' => 0, 'total' => 0];
    foreach ($jobs as $job) {
        if (fb_job_day($job) !== $today) {
            continue;
        }
        if (fb_text($job[$key] ?? '') !== $value && $value !== '') {
            continue;
        }
        $status = fb_text($job['status'] ?? 'unknown');
        $out['total']++;
        if (isset($out[$status])) {
            $out[$status]++;
        }
    }
    return $out;
}

function fb_dashboard(array $publish, array $shared = []): array {
    $jobs = fb_rows($publish['facebookPublishJobs'] ?? []);
    $today = date('Y-m-d');
    $status = [];
    $todayStatus = [];
    foreach ($jobs as $job) {
        $st = fb_text($job['status'] ?? 'unknown') ?: 'unknown';
        $status[$st] = ($status[$st] ?? 0) + 1;
        if (fb_job_day($job) === $today) {
            $todayStatus[$st] = ($todayStatus[$st] ?? 0) + 1;
        }
    }
    $queued = array_values(array_filter($jobs, fn($job) => ($job['status'] ?? '') === 'queued' && !fb_unverified_account(fb_text($job['account'] ?? '')) && !fb_overseas_text(fb_text($job['destinationName'] ?? '') . ' ' . fb_text($job['destinationUrl'] ?? ''))));
    usort($queued, fn($a, $b) => (strtotime(fb_text($a['scheduledAt'] ?? '')) ?: PHP_INT_MAX) <=> (strtotime(fb_text($b['scheduledAt'] ?? '')) ?: PHP_INT_MAX));
    $next = array_map(fn($job) => [
        'id' => $job['id'] ?? '',
        'title' => $job['title'] ?? '',
        'account' => $job['account'] ?? '',
        'destinationName' => $job['destinationName'] ?? '',
        'scheduledAt' => $job['scheduledAt'] ?? '',
        'city' => $job['city'] ?? '',
        'district' => $job['district'] ?? '',
        'price' => $job['price'] ?? '',
        'photos' => count(is_array($job['imageUrls'] ?? null) ? $job['imageUrls'] : []),
        'postText' => $job['postText'] ?? '',
        'businessCardUrl' => $job['businessCardUrl'] ?? FB_CARD_URL,
        'imageUrls' => array_slice(is_array($job['imageUrls'] ?? null) ? $job['imageUrls'] : [], 0, 4),
        'sourceUrl' => $job['sourceUrl'] ?? '',
    ], array_slice($queued, 0, 3));
    $done = ($todayStatus['published'] ?? 0) + ($todayStatus['pending_review'] ?? 0);
    $policy = fb_ad_policy($publish);
    $pageChannels = [];
    foreach ($policy['pages'] as $page) {
        $pageId = $page['id'];
        $pageChannels[] = [
            'id' => $pageId,
            'label' => $page['label'],
            'account' => $page['account'],
            'personal' => fb_count_jobs_matching($jobs, $today, fn($job) => fb_text($job['pageId'] ?? '') === $pageId && fb_text($job['lane'] ?? '') === 'personal'),
            'regions' => [
                'north' => fb_count_jobs_matching($jobs, $today, fn($job) => fb_text($job['pageId'] ?? '') === $pageId && fb_text($job['lane'] ?? '') === 'north'),
                'central' => fb_count_jobs_matching($jobs, $today, fn($job) => fb_text($job['pageId'] ?? '') === $pageId && fb_text($job['lane'] ?? '') === 'central'),
                'south' => fb_count_jobs_matching($jobs, $today, fn($job) => fb_text($job['pageId'] ?? '') === $pageId && fb_text($job['lane'] ?? '') === 'south'),
                'east' => fb_count_jobs_matching($jobs, $today, fn($job) => fb_text($job['pageId'] ?? '') === $pageId && fb_text($job['lane'] ?? '') === 'east'),
            ],
        ];
    }
    return [
        'ok' => true,
        'today' => $today,
        'serverTime' => date('c'),
        'sameStoreCount' => count(fb_same_store_items($shared)),
        'workerLastClaimAt' => fb_text($publish['facebookPublishWorkerLastClaimAt'] ?? ''),
        'dailyPolicy' => $publish['facebookDailyPublishPolicy'] ?? [],
        'trueHappinessPolicy' => $publish['facebookTrueHappinessDailyPublishPolicy'] ?? [],
        'personalPolicy' => $publish['facebookPersonalDailyPublishPolicy'] ?? [],
        'adPolicy' => $policy,
        'fanPages' => $pageChannels,
        'allStatus' => $status,
        'todayStatus' => $todayStatus,
        'todayDone' => $done,
        'todayTarget' => $policy['dailyTarget'],
        'channels' => [
            'page' => fb_count_jobs_matching($jobs, $today, fn($job) => fb_text($job['lane'] ?? '') !== '' && fb_text($job['lane'] ?? '') !== 'personal' && fb_text($job['pageId'] ?? '') !== ''),
            'truehappiness' => fb_count_today($jobs, $today, 'scheduleId', 'SCH-TRUEHAPPINESS-DAILY20-' . str_replace('-', '', $today)),
            'personal' => fb_count_jobs_matching($jobs, $today, fn($job) => fb_text($job['lane'] ?? '') === 'personal'),
        ],
        'nextJobs' => $next,
        'queuedReady' => count($queued),
    ];
}

function fb_personal_times(string $targetDate): array {
    $slots = ['12:10', '12:25', '12:40', '12:55', '18:10', '18:25', '18:40', '18:55', '19:10', '19:25'];
    return array_map(fn($time) => strtotime($targetDate . ' ' . $time), $slots);
}

function fb_cap_schedule_times(array $times, int $intervalMinutes, ?int $notBefore = null, ?int $notAfter = null): array {
    $notBefore = $notBefore ?? (time() + 90);
    $notAfter = $notAfter ?? strtotime(date('Y-m-d', $notBefore) . ' 21:30:00');
    $out = [];
    $next = $notBefore;
    foreach ($times as $t) {
        $t = (int)$t;
        if ($t < $next) {
            $t = $next;
        }
        if ($t > $notAfter) {
            break;
        }
        $out[] = $t;
        $next = $t + ($intervalMinutes * 60);
    }
    return $out;
}

function fb_retire_legacy_page_jobs(array &$jobs, string $targetDate): int {
    $legacyId = 'SCH-PAGE-DAILY20-' . str_replace('-', '', $targetDate);
    $retired = 0;
    foreach ($jobs as &$job) {
        if (($job['scheduleId'] ?? '') !== $legacyId) {
            continue;
        }
        if (!in_array($job['status'] ?? '', ['queued', 'running'], true)) {
            continue;
        }
        $job['status'] = 'cancelled';
        $job['note'] = trim(fb_text($job['note'] ?? '') . '；已改為粉絲團北中南東／個人廣告文規則');
        $job['updatedAt'] = date('c');
        $retired++;
    }
    unset($job);
    return $retired;
}

function fb_generate_page_personal_jobs(array &$jobs, array &$schedules, array $page, array $items, array $groups, string $targetDate, array $policy, int $pageIndex, array &$sameDay): int {
    $pageId = fb_text($page['id'] ?? '');
    $scheduleId = 'SCH-AD-' . $pageId . '-PERSONAL-' . str_replace('-', '', $targetDate);
    $existing = count(array_filter($jobs, fn($job) => ($job['scheduleId'] ?? '') === $scheduleId && fb_active_job($job)));
    $need = max(0, (int)$policy['personalPerPage'] - $existing);
    if ($need <= 0 || !$items) {
        return 0;
    }
    $personalGroups = !empty($page['personalViaGroups']) ? fb_yilan_personal_groups($groups) : [];
    $pageUrl = fb_text($page['pageUrl'] ?? '');
    if (!$personalGroups && $pageUrl === '') {
        fb_upsert_schedule($schedules, [
            'id' => $scheduleId,
            'name' => $page['label'] . '｜個人每日' . $policy['personalPerPage'] . '件｜' . $targetDate,
            'enabled' => true,
            'lane' => 'personal',
            'pageId' => $pageId,
            'itemsPerDay' => (int)$policy['personalPerPage'],
            'accounts' => [$page['account']],
            'sourceMode' => 'same_store_only',
            'postType' => '出售文',
            'note' => '尚未設定粉絲團網址或個人社團，佇列為 0',
            'createdAt' => date('c'),
        ]);
        return 0;
    }
    $selected = fb_pick_items($items, $sameDay, [], $need, true);
    $times = fb_cap_schedule_times(
        fb_personal_slot_times($targetDate, $need, $pageIndex * 2),
        1,
        time() + 90,
        strtotime($targetDate . ' 21:59:00')
    );
    $selected = array_slice($selected, 0, count($times));
    fb_upsert_schedule($schedules, [
        'id' => $scheduleId,
        'name' => $page['label'] . '｜個人每日' . $policy['personalPerPage'] . '件｜' . $targetDate,
        'enabled' => true,
        'lane' => 'personal',
        'pageId' => $pageId,
        'itemsPerDay' => (int)$policy['personalPerPage'],
        'startTime' => '12:10',
        'intervalMinutes' => 15,
        'accounts' => [$page['account']],
        'sourceMode' => 'same_store_only',
        'postType' => '出售文',
        'createdAt' => date('c'),
    ]);
    $created = 0;
    $usedUrls = [];
    foreach ($selected as $index => $item) {
        $run = $times[$index] ?? null;
        if (!$run) {
            continue;
        }
        $destName = fb_text($page['pageName'] ?? $page['label']);
        $destUrl = $pageUrl;
        $groupId = fb_id($page['groupId'] ?? '');
        if ($personalGroups) {
            $group = fb_best_personal_group($personalGroups, $item, $usedUrls);
            if (!$group) {
                continue;
            }
            $destName = fb_text($group['name'] ?? '');
            $destUrl = fb_text($group['url'] ?? '');
            $groupId = fb_id($group['id'] ?? '');
            $usedUrls[$destUrl] = ($usedUrls[$destUrl] ?? 0) + 1;
        }
        if ($destUrl === '') {
            continue;
        }
        $created += fb_append_jobs($jobs, [$item], [
            'scheduleId' => $scheduleId,
            'idPrefix' => 'JOB-AD-' . $pageId . '-PERSONAL-',
            'account' => $page['account'],
            'accountKey' => $page['accountKey'] ?? '',
            'groupId' => $groupId,
            'destinationName' => $destName,
            'destinationUrl' => $destUrl,
            'headlinePrefix' => '🏡 ' . $page['label'] . '個人精選｜',
            'note' => $page['label'] . '｜個人每日' . $policy['personalPerPage'] . '件',
            'lane' => 'personal',
            'region' => '個人',
            'pageId' => $pageId,
            'pageLabel' => $page['label'],
            'times' => [$run],
        ]);
        $sameDay[$item['id']] = true;
    }
    return $created;
}

function fb_generate_page_region_jobs(array &$jobs, array &$schedules, array $page, array $items, array $groups, string $targetDate, array $policy, int $pageIndex): int {
    $pageId = fb_text($page['id'] ?? '');
    $ymd = str_replace('-', '', $targetDate);
    $created = 0;
    $regionKeys = array_keys(fb_taiwan_regions());
    $endTs = strtotime($targetDate . sprintf(' %02d:59:00', (int)$policy['hourEnd']));
    foreach ($regionKeys as $regionOffset => $regionKey) {
        $region = fb_taiwan_regions()[$regionKey];
        $scheduleId = 'SCH-AD-' . $pageId . '-' . strtoupper($regionKey) . '-' . $ymd;
        $existingHours = [];
        foreach ($jobs as $job) {
            if (($job['scheduleId'] ?? '') !== $scheduleId || !fb_active_job($job)) {
                continue;
            }
            $hour = substr(fb_text($job['scheduledAt'] ?? ''), 11, 2);
            if ($hour !== '') {
                $existingHours[$hour] = true;
            }
        }
        $minute = ($regionOffset * 12) + ($pageIndex * 3);
        $times = fb_hourly_times($targetDate, (int)$policy['hourStart'], (int)$policy['hourEnd'], $minute);
        $times = array_values(array_filter($times, function ($t) use ($existingHours, $endTs) {
            if ($t > $endTs) {
                return false;
            }
            if ($t < time() + 60) {
                return false;
            }
            $hour = date('H', $t);
            return empty($existingHours[$hour]);
        }));
        fb_upsert_schedule($schedules, [
            'id' => $scheduleId,
            'name' => $page['label'] . '｜' . $region['label'] . '部每小時1則｜' . $targetDate,
            'enabled' => true,
            'lane' => $regionKey,
            'pageId' => $pageId,
            'itemsPerDay' => (int)$policy['hoursPerDay'],
            'startTime' => sprintf('%02d:%02d', (int)$policy['hourStart'], $minute),
            'intervalMinutes' => 60,
            'accounts' => [$page['account']],
            'sourceMode' => 'same_store_only',
            'postType' => '出售文',
            'createdAt' => date('c'),
        ]);
        if (!$times) {
            continue;
        }
        $usedUrls = [];
        foreach ($times as $run) {
            $hourUsed = [];
            $hourKey = date('H', $run);
            foreach ($jobs as $job) {
                if (fb_job_day($job) !== $targetDate || !fb_active_job($job)) {
                    continue;
                }
                if (fb_text($job['pageId'] ?? '') !== $pageId) {
                    continue;
                }
                if (substr(fb_text($job['scheduledAt'] ?? ''), 11, 2) === $hourKey) {
                    $hourUsed[fb_id($job['caseId'] ?? '')] = true;
                }
            }
            $picked = fb_pick_region_items($items, $regionKey, $hourUsed, max(1, (int)($policy['regionsPerHour'] ?? 1)));
            $item = $picked[0] ?? null;
            $dest = fb_page_region_destination($page, $regionKey, $groups, $usedUrls);
            if (!$item || !$dest) {
                continue;
            }
            $usedUrls[$dest['destinationUrl']] = ($usedUrls[$dest['destinationUrl']] ?? 0) + 1;
            $created += fb_append_jobs($jobs, [$item], [
                'scheduleId' => $scheduleId,
                'idPrefix' => 'JOB-AD-' . $pageId . '-' . strtoupper($regionKey) . '-',
                'account' => $page['account'],
                'accountKey' => $page['accountKey'] ?? '',
                'groupId' => $dest['groupId'],
                'destinationName' => $dest['destinationName'],
                'destinationUrl' => $dest['destinationUrl'],
                'headlinePrefix' => '🔥 ' . $page['label'] . $region['label'] . '部廣告｜',
                'note' => $page['label'] . '｜北中南東｜' . $region['label'] . '部每小時1則',
                'lane' => $regionKey,
                'region' => $region['label'],
                'pageId' => $pageId,
                'pageLabel' => $page['label'],
                'times' => [$run],
            ]);
        }
    }
    return $created;
}

function fb_generate_today_bundle(array $shared, array $publish, string $targetDate, bool $commit): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
        $targetDate = date('Y-m-d');
    }
    $jobs = fb_rows($publish['facebookPublishJobs'] ?? []);
    $schedules = fb_rows($publish['facebookPublishSchedules'] ?? []);
    $groups = fb_rows($publish['facebookGroupRegistry'] ?? []);
    $cancelled = fb_cancel_stale_jobs($jobs, $targetDate);
    $cancelled['legacyPageDaily20'] = fb_retire_legacy_page_jobs($jobs, $targetDate);
    $disabledGroups = fb_disable_overseas_groups($groups);
    $items = fb_same_store_items($shared);
    $policy = fb_ad_policy($publish);
    $personalGroups = fb_yilan_personal_groups($groups);
    $created = ['page' => 0, 'truehappiness' => 0, 'personal' => 0];
    $scheduleIds = [];

    if ($policy['enabled']) {
        foreach (fb_fan_pages() as $pageIndex => $page) {
            $pageSameDay = [];
            foreach ($jobs as $job) {
                if (fb_job_day($job) !== $targetDate || !fb_active_job($job)) {
                    continue;
                }
                if (fb_text($job['pageId'] ?? '') === $page['id'] && fb_text($job['lane'] ?? '') === 'personal') {
                    $pageSameDay[fb_id($job['caseId'] ?? '')] = true;
                }
            }
            $personalMade = fb_generate_page_personal_jobs($jobs, $schedules, $page, $items, $groups, $targetDate, $policy, $pageIndex, $pageSameDay);
            $regionMade = fb_generate_page_region_jobs($jobs, $schedules, $page, $items, $groups, $targetDate, $policy, $pageIndex);
            $created['personal'] += $personalMade;
            $created['page'] += $regionMade;
            $created[$page['id'] . '_personal'] = $personalMade;
            $created[$page['id'] . '_regions'] = $regionMade;
            $scheduleIds[] = 'SCH-AD-' . $page['id'] . '-PERSONAL-' . str_replace('-', '', $targetDate);
            foreach (array_keys(fb_taiwan_regions()) as $regionKey) {
                $scheduleIds[] = 'SCH-AD-' . $page['id'] . '-' . strtoupper($regionKey) . '-' . str_replace('-', '', $targetDate);
            }
        }
    }

    $trueId = 'SCH-TRUEHAPPINESS-DAILY20-' . str_replace('-', '', $targetDate);
    $scheduleIds[] = $trueId;
    [$sameDay, $recent] = fb_used_case_ids($jobs, $targetDate, 14);
    $trueExisting = count(array_filter($jobs, fn($job) => ($job['scheduleId'] ?? '') === $trueId && fb_active_job($job)));
    $trueNeed = max(0, 20 - $trueExisting);
    if ($trueNeed > 0) {
        $selected = fb_pick_items($items, $sameDay, $recent, $trueNeed, true);
        fb_upsert_schedule($schedules, [
            'id' => $trueId,
            'name' => '店內物件真幸福社團每日20件｜' . $targetDate,
            'enabled' => true,
            'itemsPerDay' => 20,
            'startTime' => '12:18',
            'intervalMinutes' => 36,
            'accounts' => [FB_PERSONAL_ACCOUNT],
            'cities' => ['宜蘭縣'],
            'sourceMode' => 'same_store_only',
            'postType' => '出售文',
            'createdAt' => date('c'),
        ]);
        $times = [];
        for ($i = 0; $i < count($selected); $i++) {
            $times[] = strtotime($targetDate . ' 12:18') + $i * 36 * 60;
        }
        $times = fb_cap_schedule_times($times, 36, time() + 180, strtotime($targetDate . ' 21:30:00'));
        $selected = array_slice($selected, 0, count($times));
        $created['truehappiness'] = fb_append_jobs($jobs, $selected, [
            'scheduleId' => $trueId,
            'idPrefix' => 'JOB-TRUEHAPPINESS-DAILY20-',
            'account' => FB_PERSONAL_ACCOUNT,
            'groupId' => FB_TRUEHAPPINESS_GROUP_ID,
            'destinationName' => FB_TRUEHAPPINESS_NAME,
            'destinationUrl' => FB_TRUEHAPPINESS_URL,
            'headlinePrefix' => '🔥 真幸福社團精選｜',
            'note' => '店內物件真幸福社團每日20件；每天 12:00／18:00 更新補件',
            'times' => $times,
        ]);
    }

    $adPolicy = array_merge($policy, ['updatedAt' => date('c')]);
    $publish = fb_merge_publish($publish, [
        'facebookPublishJobs' => json_encode($jobs, JSON_UNESCAPED_UNICODE),
        'facebookPublishSchedules' => json_encode($schedules, JSON_UNESCAPED_UNICODE),
        'facebookGroupRegistry' => json_encode($groups, JSON_UNESCAPED_UNICODE),
        'facebookBrokerDisclosure' => [
            'required' => true,
            'line' => FB_BROKER_LINE,
            'brokerName' => '馮雋引',
            'license' => '（113）000395字號',
            'updatedAt' => date('c'),
        ],
        'facebookFanPageAdPolicy' => $adPolicy,
        'facebookDailyPublishPolicy' => [
            'enabled' => $policy['enabled'],
            'name' => '三粉絲團北中南東每小時各一',
            'source' => 'sameStoreItems',
            'accounts' => fb_allowed_accounts(),
            'hourStart' => $policy['hourStart'],
            'hourEnd' => $policy['hourEnd'],
            'regionsPerHour' => $policy['regionsPerHour'],
            'regionJobsPerHour' => $policy['regionJobsPerHour'],
            'refreshAt' => ['12:00', '18:00'],
            'updatedAt' => date('c'),
        ],
        'facebookTrueHappinessDailyPublishPolicy' => [
            'enabled' => true,
            'name' => '店內物件真幸福社團每日20件',
            'source' => 'sameStoreItems',
            'account' => FB_PERSONAL_ACCOUNT,
            'dailyLimit' => 20,
            'startTime' => '12:18',
            'intervalMinutes' => 36,
            'refreshAt' => ['12:00', '18:00'],
            'updatedAt' => date('c'),
        ],
        'facebookPersonalDailyPublishPolicy' => [
            'enabled' => $policy['enabled'],
            'name' => '三粉絲團個人每日各' . $policy['personalPerPage'] . '件',
            'source' => 'sameStoreItems',
            'accounts' => fb_allowed_accounts(),
            'dailyLimit' => $policy['personalPerPage'],
            'refreshAt' => ['12:00', '18:00'],
            'updatedAt' => date('c'),
        ],
    ]);

    $numericCreated = [
        'page' => (int)($created['page'] ?? 0),
        'truehappiness' => (int)($created['truehappiness'] ?? 0),
        'personal' => (int)($created['personal'] ?? 0),
    ];
    return [
        'publish' => $publish,
        'result' => [
            'ok' => true,
            'committed' => $commit,
            'targetDate' => $targetDate,
            'availableSameStoreItems' => count($items),
            'createdJobs' => $created,
            'createdTotal' => $numericCreated['page'] + $numericCreated['truehappiness'] + $numericCreated['personal'],
            'cancelled' => $cancelled,
            'disabledOverseasGroups' => $disabledGroups,
            'personalGroups' => count($personalGroups),
            'adPolicy' => $adPolicy,
            'scheduleIds' => $scheduleIds,
            'dashboard' => fb_dashboard($publish, $shared),
        ],
    ];
}
