<?php
declare(strict_types=1);

if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int
    {
        return strlen($string);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string
    {
        return $length === null ? substr($string, $start) : substr($string, $start, $length);
    }
}
if (!function_exists('mb_stripos')) {
    function mb_stripos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null): int|false
    {
        return stripos($haystack, $needle, $offset);
    }
}

function coolpc_quote_source_url(): string
{
    return 'https://coolpc.com.tw/evaluate.php';
}

function coolpc_quote_cache_path(): string
{
    $configured = trim((string)getenv('BAOHUI_ACCOUNTING_DATA_DIR'));
    $root = $configured !== '' ? rtrim($configured, "\\/") : ('F:' . DIRECTORY_SEPARATOR . 'Data' . DIRECTORY_SEPARATOR . 'BaohuiAccounting');
    if (!is_dir($root)) $root = __DIR__ . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($root)) @mkdir($root, 0770, true);
    return $root . DIRECTORY_SEPARATOR . 'coolpc-quote-cache.json';
}

function coolpc_quote_cafile(): string
{
    foreach ([
        __DIR__ . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . 'cacert.pem',
        'F:' . DIRECTORY_SEPARATOR . 'Data' . DIRECTORY_SEPARATOR . 'BaohuiAccounting' . DIRECTORY_SEPARATOR . 'cacert.pem',
    ] as $path) {
        if (is_file($path)) return $path;
    }
    return '';
}

function coolpc_quote_decode_html(string $raw): string
{
    if ($raw === '') return '';
    $head = strtolower(substr($raw, 0, 1200));
    if (str_contains($head, 'charset=utf-8') || str_contains($head, 'charset="utf-8"')) {
        return $raw;
    }
    foreach (['CP950', 'BIG-5', 'BIG5'] as $enc) {
        if (function_exists('mb_convert_encoding')) {
            $out = @mb_convert_encoding($raw, 'UTF-8', $enc);
            if (is_string($out) && $out !== '' && stripos($out, '<select') !== false) return $out;
        }
        if (function_exists('iconv')) {
            $out = @iconv($enc, 'UTF-8//IGNORE', $raw);
            if (is_string($out) && $out !== '' && stripos($out, '<select') !== false) return $out;
        }
    }
    return $raw;
}

function coolpc_quote_strip_text(string $html): string
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function coolpc_quote_parse_price(string $text): array
{
    $list = 0;
    $price = 0;
    if (preg_match('/\$([0-9,]+)\s*↘\s*\$?([0-9,]+)/u', $text, $m)) {
        $list = (int)str_replace(',', '', $m[1]);
        $price = (int)str_replace(',', '', $m[2]);
    } elseif (preg_match('/,\s*\$([0-9,]+)/u', $text, $m)) {
        $price = (int)str_replace(',', '', $m[1]);
        $list = $price;
    }
    return ['price' => $price, 'list_price' => $list];
}

function coolpc_quote_clean_name(string $text): string
{
    $name = preg_replace('/,\s*\$[0-9,]+(?:\s*↘\s*\$?[0-9,]+)?.*/u', '', $text) ?? $text;
    $name = preg_replace('/[◆★]+/u', ' ', $name) ?? $name;
    $name = preg_replace('/\s*熱賣\s*$/u', '', $name) ?? $name;
    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
    return trim($name);
}

function coolpc_quote_brand_prefixes(): array
{
    $brands = [
        'GIGABYTE', 'Gigabyte', '技嘉',
        'SilverStone', '銀欣',
        'Cooler Master', '酷碼',
        'Thermaltake', '曜越',
        'Fractal Design', 'Phanteks',
        'ViewSonic', 'Viewsonic',
        'Kingston', '金士頓',
        'Seagate', 'Toshiba', 'Western Digital', 'WD',
        'Corsair', '海盜船',
        'Seasonic', '海韻',
        'ASRock', '華擎',
        'ASUS', 'Asus', '華碩',
        'MSI', '微星',
        'Intel', 'AMD', 'NVIDIA',
        'ADATA', '威剛',
        'Micron', '美光',
        'Samsung', '三星',
        'Logitech', '羅技',
        'TP-LINK', 'TP-Link', 'TP-link',
        'QNAP', 'Synology', '群暉',
        'Lenovo', 'Acer', 'ASUS', 'Dell', 'HP', 'LG',
        'BenQ', 'Philips', 'AOC',
        'Lian Li', '聯力',
        'Antec', '安鈦克',
        'NZXT', 'InWin', '迎廣',
        'Jonsbo', '喬思伯',
        'FSP', '全漢',
        'SuperFlower', '振華',
        'KLEVV', '十銓', 'Team',
        'UMAX', 'Biwin', 'ZhiTai', '致態',
        'ZOTAC', 'INNO3D', '麗臺', '撼訊', '藍寶石',
        'Qualcomm', 'HTC', 'TCL',
        'Microsoft', 'Windows',
    ];
    usort($brands, static function ($a, $b) {
        return mb_strlen((string)$b) <=> mb_strlen((string)$a);
    });
    return $brands;
}

function coolpc_quote_guess_brand(string $name): string
{
    $trimmed = preg_replace('/^[｛{\s]+/u', '', $name) ?? $name;
    foreach (coolpc_quote_brand_prefixes() as $brand) {
        $len = mb_strlen($brand);
        if (mb_substr($trimmed, 0, $len) === $brand) return $brand;
        if (strncasecmp($trimmed, $brand, strlen($brand)) === 0) return $brand;
    }
    return '';
}

function coolpc_quote_category_label(string $prefixHtml): string
{
    if (preg_match_all('/<td[^>]*\sclass=["\']?t["\']?[^>]*>([^<]+)/i', $prefixHtml, $matches)) {
        $labels = $matches[1];
        $label = coolpc_quote_strip_text((string)end($labels));
        if ($label !== '') return $label;
    }
    $text = coolpc_quote_strip_text($prefixHtml);
    if (preg_match('/(?:^|[\s])(\d{1,2})\s+([^\d]{2,80})$/u', $text, $m)) {
        return trim((string)$m[2]);
    }
    return '';
}

function coolpc_quote_parse_evaluate_html(string $html): array
{
    $categories = [];
    $items = [];
    if ($html === '') return ['categories' => [], 'items' => []];
    if (!preg_match_all('/<select\b([^>]*)>(.*?)<\/select>/is', $html, $selects, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        return ['categories' => [], 'items' => []];
    }
    foreach ($selects as $select) {
        $attrs = $select[1][0];
        $body = $select[2][0];
        $offset = (int)$select[0][1];
        if (!preg_match('/name\s*=\s*["\']?(n\d+)/i', $attrs, $nameMatch)) continue;
        $catId = strtolower($nameMatch[1]);
        $prefixStart = max(0, $offset - 900);
        $prefix = substr($html, $prefixStart, $offset - $prefixStart);
        $label = coolpc_quote_category_label($prefix);
        if ($label === '') $label = $catId;
        $countBefore = count($items);
        if (!preg_match_all('/<option\b([^>]*)>(.*?)<\/option>/is', $body, $options, PREG_SET_ORDER)) {
            $categories[] = ['id' => $catId, 'label' => $label, 'count' => 0];
            continue;
        }
        foreach ($options as $option) {
            $optAttrs = $option[1];
            $value = '';
            if (preg_match('/value\s*=\s*["\']?([^"\'\s>]+)/i', $optAttrs, $vm)) $value = trim($vm[1]);
            if ($value === '' || $value === '0') continue;
            $rawText = coolpc_quote_strip_text($option[2]);
            if ($rawText === '' || str_starts_with($rawText, '共有商品')) continue;
            $parsed = coolpc_quote_parse_price($rawText);
            if (($parsed['price'] ?? 0) <= 0) continue;
            $name = coolpc_quote_clean_name($rawText);
            if ($name === '') continue;
            $items[] = [
                'id' => $catId . '-' . $value,
                'cat' => $catId,
                'category' => $label,
                'name' => $name,
                'brand' => coolpc_quote_guess_brand($name),
                'spec' => $label,
                'price' => (int)$parsed['price'],
                'list_price' => (int)($parsed['list_price'] ?? $parsed['price']),
            ];
        }
        $categories[] = [
            'id' => $catId,
            'label' => $label,
            'count' => count($items) - $countBefore,
        ];
    }
    return ['categories' => $categories, 'items' => $items];
}

function coolpc_quote_http_get(string $url): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; BaohuiQuote/1.0)',
            CURLOPT_HTTPHEADER => ['Accept: text/html', 'Accept-Language: zh-TW,zh;q=0.9'],
        ];
        $ca = coolpc_quote_cafile();
        if ($ca !== '') $opts[CURLOPT_CAINFO] = $ca;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        curl_close($ch);
        return is_string($raw) ? $raw : '';
    }
    $raw = @file_get_contents($url, false, stream_context_create([
        'http' => [
            'timeout' => 25,
            'header' => "User-Agent: Mozilla/5.0 (compatible; BaohuiQuote/1.0)\r\nAccept: text/html\r\n",
        ],
    ]));
    return is_string($raw) ? $raw : '';
}

function coolpc_quote_now_label(): string
{
    $tz = new DateTimeZone('Asia/Taipei');
    return (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i');
}

function coolpc_quote_load_live(bool $force = false): array
{
    $path = coolpc_quote_cache_path();
    if (!$force && is_file($path)) {
        $cached = json_decode((string)file_get_contents($path), true);
        $age = time() - (int)($cached['fetchedAtUnix'] ?? 0);
        if (is_array($cached) && !empty($cached['ok']) && $age >= 0 && $age < 900) {
            return $cached;
        }
    }
    $raw = coolpc_quote_http_get(coolpc_quote_source_url());
    $html = coolpc_quote_decode_html($raw);
    $parsed = coolpc_quote_parse_evaluate_html($html);
    $ok = $raw !== '' && !empty($parsed['items']);
    $payload = [
        'ok' => $ok,
        'fetchedAt' => coolpc_quote_now_label(),
        'fetchedAtUnix' => time(),
        'source' => coolpc_quote_source_url(),
        'itemCount' => count($parsed['items']),
        'categories' => $parsed['categories'],
        'items' => $parsed['items'],
        'error' => $ok ? '' : '原價屋報價暫時讀不到，請稍後再整理。',
    ];
    @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $payload;
}
