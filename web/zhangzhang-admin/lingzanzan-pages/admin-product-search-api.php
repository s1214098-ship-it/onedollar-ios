<?php
declare(strict_types=1);

/**
 * 產品管理搜尋：正式商品找不到時，改查集運預報與客戶詢問。
 * 只回編號／品名／貨態，不回電話。
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function ps_out(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ps_text($value, int $limit = 160): string {
    $text = trim((string)($value ?? ''));
    if ($text === '') return '';
    return function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit);
}

function ps_loose($value): string {
    $text = strtolower(trim((string)($value ?? '')));
    $text = (string)preg_replace('/[\s\-()（）_+＋]/u', '', $text);
    return $text;
}

function ps_hit(string $hay, string $needle): bool {
    if ($needle === '' || $hay === '') return false;
    return strpos($hay, $needle) !== false;
}

function ps_read(string $file) {
    if (!is_file($file)) return null;
    $raw = file_get_contents($file);
    if ($raw === false || trim((string)$raw) === '') return null;
    $decoded = json_decode((string)preg_replace('/^\xEF\xBB\xBF/', '', (string)$raw), true);
    return $decoded;
}

function ps_rows($payload): array {
    if (is_array($payload)) {
        if (array_is_list($payload)) return $payload;
        foreach (['items', 'products', 'skus', 'inquiries', 'orders', 'rows'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) return array_values($payload[$key]);
        }
    }
    return [];
}

function ps_blob(array $row, array $keys): string {
    $parts = [];
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') continue;
        if (is_array($row[$key])) {
            $parts[] = ps_loose(implode(' ', array_map('strval', $row[$key])));
            continue;
        }
        $parts[] = ps_loose($row[$key]);
    }
    return implode(' ', $parts);
}

$q = ps_text($_GET['q'] ?? ($_POST['q'] ?? ''), 80);
$needle = ps_loose($q);
if ($needle === '' || (function_exists('mb_strlen') ? mb_strlen($needle, 'UTF-8') : strlen($needle)) < 2) {
    ps_out(['ok' => true, 'query' => $q, 'products' => [], 'skus' => [], 'freight' => [], 'inquiries' => []]);
}

$dataDir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
$products = ps_rows(ps_read($dataDir . DIRECTORY_SEPARATOR . 'products.json'));
$skus = ps_rows(ps_read($dataDir . DIRECTORY_SEPARATOR . 'skus.json'));
$freight = ps_rows(ps_read($dataDir . DIRECTORY_SEPARATOR . 'freight-forwarding-tracking.json'));
$inquiries = ps_rows(ps_read($dataDir . DIRECTORY_SEPARATOR . 'inquiries.json'));

$productHits = [];
foreach ($products as $row) {
    if (!is_array($row)) continue;
    $hay = ps_blob($row, ['id', 'code', 'productCode', 'barcode', 'title', 'frontTitle', 'name', 'englishName', 'nameEn', 'brand', 'brandName', 'spec', 'specification']);
    if (!ps_hit($hay, $needle)) continue;
    $productHits[] = [
        'id' => ps_text($row['id'] ?? '', 80),
        'code' => ps_text($row['code'] ?? ($row['productCode'] ?? ''), 80),
        'title' => ps_text($row['title'] ?? ($row['name'] ?? ''), 120),
    ];
    if (count($productHits) >= 20) break;
}

$skuHits = [];
foreach ($skus as $row) {
    if (!is_array($row)) continue;
    $hay = ps_blob($row, ['id', 'sku', 'barcode', 'companyBarcode', 'legacyBarcode', 'mappingCode', 'productId', 'colorName', 'sizeName']);
    if (!ps_hit($hay, $needle)) continue;
    $skuHits[] = [
        'id' => ps_text($row['id'] ?? ($row['sku'] ?? ''), 80),
        'sku' => ps_text($row['sku'] ?? ($row['id'] ?? ''), 80),
        'barcode' => ps_text($row['barcode'] ?? '', 80),
        'productId' => ps_text($row['productId'] ?? '', 80),
    ];
    if (count($skuHits) >= 20) break;
}

$freightHits = [];
foreach ($freight as $row) {
    if (!is_array($row)) continue;
    $hay = ps_blob($row, [
        'customerSupplyBarcode', 'sampleBarcode', 'taiwanBarcode', 'productFiledBarcode',
        'productCode', 'catalogProductCode', 'productFiledProductCode', 'productId',
        'productName', 'title', 'name', 'skuId', 'productFiledSkuId',
    ]);
    if (!ps_hit($hay, $needle)) continue;
    $freightHits[] = [
        'id' => ps_text($row['id'] ?? '', 80),
        'barcode' => ps_text($row['customerSupplyBarcode'] ?? ($row['sampleBarcode'] ?? ($row['taiwanBarcode'] ?? '')), 80),
        'catalogCode' => ps_text($row['catalogProductCode'] ?? ($row['productCode'] ?? ''), 80),
        'title' => ps_text($row['productName'] ?? ($row['title'] ?? ''), 120),
        'progress' => ps_text($row['progress'] ?? '', 80),
        'haohong' => ps_text($row['haohongPackageStatus'] ?? '', 80),
        'qty' => ps_text($row['quantity'] ?? ($row['sellableQty'] ?? ''), 20),
        'filed' => ps_text($row['productFiledStatus'] ?? '', 80),
    ];
    if (count($freightHits) >= 20) break;
}

$inquiryHits = [];
foreach ($inquiries as $order) {
    if (!is_array($order)) continue;
    $orderId = ps_text($order['id'] ?? ($order['orderId'] ?? ''), 80);
    $status = ps_text($order['status'] ?? '', 40);
    $lines = [];
    foreach (['items', 'lines', 'products'] as $key) {
        if (isset($order[$key]) && is_array($order[$key])) {
            $lines = $order[$key];
            break;
        }
    }
    if (!$lines) {
        $blob = ps_loose(json_encode($order, JSON_UNESCAPED_UNICODE) ?: '');
        if (!ps_hit($blob, $needle)) continue;
        $inquiryHits[] = [
            'orderId' => $orderId,
            'code' => '',
            'sku' => '',
            'title' => '',
            'status' => $status,
        ];
        if (count($inquiryHits) >= 20) break;
        continue;
    }
    foreach ($lines as $line) {
        if (!is_array($line)) continue;
        $hay = ps_blob($line, ['productId', 'code', 'sku', 'barcode', 'title', 'name', 'productName']);
        if (!ps_hit($hay, $needle)) continue;
        $inquiryHits[] = [
            'orderId' => $orderId,
            'code' => ps_text($line['code'] ?? '', 80),
            'sku' => ps_text($line['sku'] ?? ($line['barcode'] ?? ''), 80),
            'title' => ps_text($line['title'] ?? ($line['name'] ?? ($line['productName'] ?? '')), 120),
            'status' => $status,
        ];
        if (count($inquiryHits) >= 20) break 2;
    }
}

ps_out([
    'ok' => true,
    'query' => $q,
    'productCount' => count($productHits),
    'skuCount' => count($skuHits),
    'freightCount' => count($freightHits),
    'inquiryCount' => count($inquiryHits),
    'products' => $productHits,
    'skus' => $skuHits,
    'freight' => $freightHits,
    'inquiries' => $inquiryHits,
]);
