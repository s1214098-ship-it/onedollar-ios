<?php
declare(strict_types=1);

function hm_intel_today(): string
{
    $tz = new DateTimeZone('Asia/Taipei');
    return (new DateTimeImmutable('now', $tz))->format('Y-m-d');
}

function hm_intel_now_label(): string
{
    $tz = new DateTimeZone('Asia/Taipei');
    return (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i');
}

function hm_intel_cache_path(): string
{
    $configured = trim((string)getenv('BAOHUI_ACCOUNTING_DATA_DIR'));
    $root = $configured !== '' ? rtrim($configured, "\\/") : ('F:' . DIRECTORY_SEPARATOR . 'Data' . DIRECTORY_SEPARATOR . 'BaohuiAccounting');
    if (!is_dir($root)) $root = __DIR__ . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($root)) @mkdir($root, 0770, true);
    return $root . DIRECTORY_SEPARATOR . 'hardware-market-intel-cache.json';
}

function hm_intel_cafile(): string
{
    foreach ([
        __DIR__ . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . 'cacert.pem',
        'F:' . DIRECTORY_SEPARATOR . 'Data' . DIRECTORY_SEPARATOR . 'BaohuiAccounting' . DIRECTORY_SEPARATOR . 'cacert.pem',
    ] as $path) {
        if (is_file($path)) return $path;
    }
    return '';
}

function hm_intel_http_get(string $url): string
{
    if (!function_exists('curl_init')) {
        $raw = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => 8, 'header' => "User-Agent: BaohuiHardwareMarketIntel/1.0\r\n"],
        ]));
        return is_string($raw) ? $raw : '';
    }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_USERAGENT => 'BaohuiHardwareMarketIntel/1.0',
    ];
    $ca = hm_intel_cafile();
    if ($ca !== '') $opts[CURLOPT_CAINFO] = $ca;
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    curl_close($ch);
    return is_string($raw) ? $raw : '';
}

function hm_intel_parse_spot(string $html): array
{
    $rows = [];
    if (preg_match_all('/Daily Express Agu\.(\d{1,2}),2026.{0,1200}?DDR4 8G \(1Gx8\) 3200 (rises to|stays at|falls to) USD ([0-9.]+)/is', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $verb = strtolower($m[2]);
            $move = $verb === 'rises to' ? '小漲' : ($verb === 'falls to' ? '小跌' : '持平');
            $rows['2026-08-' . $day] = [
                'date' => '2026-08-' . $day,
                'item' => 'DDR4 8G (1Gx8) 3200 現貨均價',
                'usd' => $m[3],
                'move' => $move,
            ];
        }
    }
    krsort($rows);
    return array_values($rows);
}

function hm_intel_parse_news(string $html): array
{
    $items = [];
    if (!preg_match_all('/<h2>\s*<a[^>]+href="(\/presscenter\/news\/(\d{8})-\d+\.html)"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
        return [];
    }
    $seen = [];
    foreach ($matches as $m) {
        $ymd = $m[2];
        $href = $m[1];
        $title = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;
        $key = $ymd . '|' . $title;
        if (isset($seen[$key]) || $title === '') continue;
        $hay = strtoupper($title);
        if (!preg_match('/DRAM|NAND|SSD|HDD|HBM|FLASH|MEMORY|記憶體|硬碟/', $hay)) continue;
        $seen[$key] = true;
        $items[] = [
            'date' => substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2),
            'title' => $title,
            'url' => 'https://www.trendforce.com' . $href,
        ];
    }
    return $items;
}

function hm_intel_load_live(bool $force = false): array
{
    $path = hm_intel_cache_path();
    if (!$force && is_file($path)) {
        $cached = json_decode((string)file_get_contents($path), true);
        $age = time() - (int)($cached['fetchedAtUnix'] ?? 0);
        if (is_array($cached) && $age >= 0 && $age < 10800) return $cached;
    }
    $html = hm_intel_http_get('https://www.trendforce.com/presscenter');
    $payload = [
        'ok' => $html !== '',
        'fetchedAt' => hm_intel_now_label(),
        'fetchedAtUnix' => time(),
        'news' => $html !== '' ? hm_intel_parse_news($html) : [],
        'spot' => $html !== '' ? hm_intel_parse_spot($html) : [],
        'source' => 'https://www.trendforce.com/presscenter',
    ];
    @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $payload;
}

function hm_intel_curated_cards(): array
{
    return [
        [
            'label' => '上漲壓力仍在，現貨小漲',
            'tone' => 'up',
            'title' => 'DRAM 記憶體',
            'signal' => '偏漲',
            'detail' => '今天已對到 8/19 TrendForce DRAM 快訊與 DRAMeXchange 現貨：合約價漲幅仍穩，現貨成交清淡但小幅走揚。第三季一般型 DRAM 合約價仍估季增 13–18%（部分談判延後、漲幅可能更收斂）。HBM 持續排擠一般型產能，DDR4／DDR5 不要當會回跌來備貨。',
        ],
        [
            'label' => '合約還漲、消費端偏弱',
            'tone' => 'watch',
            'title' => 'SSD / NAND Flash',
            'signal' => '漲勢收斂',
            'detail' => '8/18 TrendForce：前五大 NAND 品牌 2Q26 營收季增 77% 至 687.7 億美元，主因企業級 SSD 缺貨、ASP 大漲，Micron 升到第三。第三季手機／PC 需求仍弱，消費型 SSD 現貨不一定跟合約走；原廠資本支出偏向 DRAM／HBM，NAND 新產能有限。2027 下半年 NAND 仍可能比 DRAM 先鬆。',
        ],
        [
            'label' => '企業級交期要先問',
            'tone' => 'watch',
            'title' => 'HDD 機械硬碟',
            'signal' => '偏緊',
            'detail' => 'Seagate、WD 近線／企業級 2026 產能大致已配完，HAMR／大容量長約多鎖到 2028。NAS、監控、大容量型號交期仍要先問；消費型不一定跟漲，但不能假設隨時有貨。',
        ],
    ];
}

function hm_intel_quote_actions(): array
{
    return [
        ['title' => '記憶體：報價有效期縮短', 'detail' => 'DDR4、DDR5 不建議長期鎖固定售價。一般報價可設定 1 天有效；專案或大量採購先向供應商確認庫存與交期。現貨有在動，不能用兩天前的數字報。'],
        ['title' => 'SSD：先問當日成本再報', 'detail' => '企業級／資料中心 SSD 仍受 AI 支撐；消費型 NVMe／SATA 現貨不一定跟合約價。報價單註明依下單當日供應成本與庫存為準。'],
        ['title' => 'HDD：大容量先問交期', 'detail' => 'NAS、監控、企業級與大容量 HDD 先確認到貨日。一般消費型硬碟可維持常態備貨，但不建議假設價格或交期一定不變。'],
    ];
}

function hm_intel_fallback_news(): array
{
    return [
        [
            'date' => '2026-08-19',
            'title' => 'TrendForce DRAM 快訊：合約穩健漲、現貨淡、DDR4 8G 3200 約 43.07 美元',
            'url' => 'https://www.trendforce.com.tw/research/download/RP260819OU',
            'summary' => '8/19 快訊：合約價漲幅維持預期，現貨因高價成交清淡、價格小幅上漲。三大原廠持續規劃新廠，但近月一般型供給仍緊。',
            'read' => '判讀：記憶體還在漲，但現貨不是爆量追價；報價仍要當日問。',
        ],
        [
            'date' => '2026-08-18',
            'title' => 'TrendForce：前五大 NAND 品牌 2Q26 營收季增 77%，Micron 升第三',
            'url' => 'https://www.trendforce.com/presscenter/news/20260818-13186.html',
            'summary' => '企業級 SSD 缺貨讓 ASP 大漲，前五大合計 687.7 億美元。第三季手機／PC 需求仍弱，但 AI 伺服器 SSD 支撐產業營收。',
            'read' => '判讀：NAND 原廠賺的是企業級；消費型 SSD 不要用同一套暴漲邏輯備貨。',
        ],
        [
            'date' => '2026-08-12',
            'title' => 'TrendForce DRAM 快訊：消費型仍缺、漲幅收斂，現貨成交偏淡',
            'url' => 'https://www.trendforce.com/research/download/RP260812LF',
            'summary' => '消費型 DRAM 仍供給不足，合約續漲但客戶成本耐受度下降；雲端需求撐短線，長線擴產會帶來壓力。',
            'read' => '判讀：第三季還漲，但不是第二季那種暴漲。',
        ],
        [
            'date' => '2026-07-03',
            'title' => 'TrendForce：第三季 DRAM 季增 13–18%、NAND 10–15%',
            'url' => 'https://www.trendforce.com/presscenter/news/20260703-13134.html',
            'summary' => 'AI 伺服器支撐合約價，但消費型 PC／手機已接近成本上限，漲幅比第二季大幅收斂。',
            'read' => '判讀：這份季增幅度仍是目前報價的主軸。',
        ],
    ];
}

function hm_intel_merge_news(array $liveNews, array $spot): array
{
    $out = [];
    if ($spot) {
        $latest = $spot[0];
        $out[] = [
            'date' => $latest['date'],
            'title' => $latest['item'] . ' ' . $latest['usd'] . ' 美元（' . $latest['move'] . '）',
            'url' => 'https://www.trendforce.com/presscenter',
            'summary' => 'TrendForce Daily Express 公開摘錄。這是現貨均價參考，不是我們進貨價。',
            'read' => '判讀：現貨有在動，報價不要沿用兩天前數字。',
        ];
    }
    foreach ($liveNews as $row) {
        $out[] = [
            'date' => $row['date'],
            'title' => $row['title'],
            'url' => $row['url'],
            'summary' => 'TrendForce 新聞中心即時抓取。',
            'read' => '判讀：先看原廠／研究機構當日說法，再對供應商。',
        ];
    }
    foreach (hm_intel_fallback_news() as $row) {
        $dup = false;
        foreach ($out as $exist) {
            if (($exist['url'] ?? '') === ($row['url'] ?? '') || ($exist['title'] ?? '') === ($row['title'] ?? '')) {
                $dup = true;
                break;
            }
        }
        if (!$dup) $out[] = $row;
    }
    return array_slice($out, 0, 6);
}

function hm_intel_payload(bool $force = false): array
{
    $live = hm_intel_load_live($force);
    return [
        'asOf' => hm_intel_today(),
        'generatedAt' => hm_intel_now_label(),
        'liveOk' => !empty($live['ok']),
        'fetchedAt' => (string)($live['fetchedAt'] ?? ''),
        'spot' => $live['spot'] ?? [],
        'cards' => hm_intel_curated_cards(),
        'actions' => hm_intel_quote_actions(),
        'news' => hm_intel_merge_news($live['news'] ?? [], $live['spot'] ?? []),
    ];
}

function hm_intel_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
