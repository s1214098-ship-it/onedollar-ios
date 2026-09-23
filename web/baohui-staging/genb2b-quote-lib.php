<?php
declare(strict_types=1);

function genb2b_quote_source_url(string $query): string
{
    return 'https://www.genb2b.com/search/' . rawurlencode($query);
}

function genb2b_quote_text(DOMNode $node): string
{
    return trim((string)preg_replace('/\s+/u', ' ', html_entity_decode($node->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

function genb2b_quote_fetch(string $url): string
{
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('無法建立捷元連線');
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 22,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) BaoHui-Quotation/1.0',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: zh-TW,zh;q=0.9'],
    ];
    foreach ([
        __DIR__ . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . 'cacert.pem',
        'F:' . DIRECTORY_SEPARATOR . 'Data' . DIRECTORY_SEPARATOR . 'BaohuiAccounting' . DIRECTORY_SEPARATOR . 'cacert.pem',
    ] as $caFile) {
        if (is_file($caFile)) { $options[CURLOPT_CAINFO] = $caFile; break; }
    }
    curl_setopt_array($ch, $options);
    $html = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if (!is_string($html) || $status < 200 || $status >= 400) {
        throw new RuntimeException($error !== '' ? $error : '捷元官網目前無法讀取');
    }
    return $html;
}

function genb2b_quote_guess_brand(string $name): string
{
    $brands = ['ASUS' => '華碩 ASUS', 'MSI' => '微星 MSI', 'GIGABYTE' => '技嘉 GIGABYTE', 'AORUS' => '技嘉 AORUS', 'INTEL' => 'Intel', 'AMD' => 'AMD', 'KINGSTON' => '金士頓 Kingston', 'SAMSUNG' => '三星 Samsung', 'ACER' => '宏碁 Acer', 'BENQ' => 'BenQ', 'LOGITECH' => '羅技 Logitech', 'TP-LINK' => 'TP-Link'];
    $upper = strtoupper($name);
    foreach ($brands as $needle => $brand) if (strpos($upper, $needle) !== false) return $brand;
    if (preg_match('/^(華碩|微星|技嘉|宏碁|三星|金士頓|羅技)/u', $name, $m)) return $m[1];
    return '';
}

function genb2b_quote_parse(string $html): array
{
    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xp = new DOMXPath($dom);
    $items = [];
    foreach ($xp->query('//article[.//a[contains(@href,"/product/J")]]') ?: [] as $article) {
        $link = $xp->query('.//a[contains(@href,"/product/J")]', $article)->item(0);
        if (!$link instanceof DOMElement || !preg_match('~/product/(J\d+)~', $link->getAttribute('href'), $m)) continue;
        $id = $m[1];
        if (isset($items[$id])) continue;
        $nameNode = $xp->query('.//span[contains(concat(" ",normalize-space(@class)," ")," font-weight-bold ")]', $article)->item(0);
        $priceNode = $xp->query('.//span[contains(concat(" ",normalize-space(@class)," ")," price ")]', $article)->item(0);
        if (!$nameNode || !$priceNode) continue;
        $name = genb2b_quote_text($nameNode);
        $price = (int)preg_replace('/\D+/', '', genb2b_quote_text($priceNode));
        if ($name === '' || $price <= 0) continue;
        $descNode = $xp->query('.//*[contains(@class,"product-description")]', $article)->item(0);
        $allText = genb2b_quote_text($article);
        $availability = '供貨狀態請見捷元官網';
        if (preg_match('/(供貨中|貨況不足|貨到通知|貨況請洽業務|預計[^。；]{0,20}(?:到貨|交期))/u', $allText, $a)) $availability = $a[1];
        $items[$id] = [
            'id' => $id,
            'name' => $name,
            'brand' => genb2b_quote_guess_brand($name),
            'spec' => $descNode ? genb2b_quote_text($descNode) : '',
            'price' => $price,
            'availability' => $availability,
            'url' => 'https://www.genb2b.com/product/' . $id,
        ];
        if (count($items) >= 80) break;
    }
    return array_values($items);
}

function genb2b_quote_search(string $query, bool $force = false): array
{
    $query = trim($query);
    if (mb_strlen($query, 'UTF-8') < 2) throw new InvalidArgumentException('請輸入至少 2 個字元');
    $cacheDir = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'genb2b-quote-cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    $cache = $cacheDir . DIRECTORY_SEPARATOR . hash('sha256', mb_strtolower($query, 'UTF-8')) . '.json';
    if (!$force && is_file($cache) && filemtime($cache) >= time() - 3600) {
        $saved = json_decode((string)file_get_contents($cache), true);
        if (is_array($saved)) return $saved;
    }
    $source = genb2b_quote_source_url($query);
    $result = ['ok' => true, 'query' => $query, 'source' => $source, 'fetchedAt' => date('Y-m-d H:i:s'), 'items' => genb2b_quote_parse(genb2b_quote_fetch($source))];
    $result['itemCount'] = count($result['items']);
    @file_put_contents($cache, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $result;
}
