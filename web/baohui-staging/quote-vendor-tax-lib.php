<?php
declare(strict_types=1);

/** Tax mode applied when 捷元 / 原價屋 cost is pulled into a quotation. */
function quote_vendor_cost_tax_mode(): string
{
    return 'external';
}

function quote_item_is_baohui_service_line(array $it): bool
{
    if (!empty($it['optional'])) return true;
    $brand = trim((string)($it['brand'] ?? ''));
    $text = (string)($it['name'] ?? '') . (string)($it['spec'] ?? '') . (string)($it['warranty'] ?? '');
    if (mb_strpos($text, '可加可不加') !== false) return true;
    if ($brand === '寶輝科技') return true;
    if (preg_match('/組裝費用|硬體維護|保固一年服務費|軟體安裝工資|工資費用/u', $text)) return true;
    return false;
}

function quote_item_is_vendor_cost_line(array $it): bool
{
    if (quote_item_is_baohui_service_line($it)) return false;
    $source = strtolower(trim((string)($it['productSource'] ?? $it['source'] ?? '')));
    if (in_array($source, ['coolpc', 'genb2b', 'jieyuan', '原價屋', '捷元'], true)) return true;
    $name = (string)($it['name'] ?? '');
    $spec = (string)($it['spec'] ?? '');
    if (preg_match('/^J\d+/', $spec) || preg_match('/^J\d+/', $name)) return true;
    if (str_contains($name, '｛') || str_contains($name, '｝')) return true;
    $coolpcSpecs = [
        '處理器 CPU', '主機板 MB', '記憶體 RAM', '筆電｜平板｜穿戴配件',
        '固態硬碟 M.2｜SSD', '作業系統', '標準 機殼', '電源供應器', '顯示卡 VGA',
    ];
    if (in_array($spec, $coolpcSpecs, true)) return true;
    if (trim((string)($it['warranty'] ?? '')) === '依產品或原廠保固條件辦理') return true;
    return false;
}

function quote_apply_vendor_cost_tax(array $quote): array
{
    $changed = 0;
    $items = is_array($quote['items'] ?? null) ? $quote['items'] : [];
    foreach ($items as $i => $it) {
        if (!is_array($it) || !quote_item_is_vendor_cost_line($it)) continue;
        $mode = (string)($it['taxMode'] ?? 'none');
        if ($mode === quote_vendor_cost_tax_mode()) continue;
        $items[$i]['taxMode'] = quote_vendor_cost_tax_mode();
        $changed++;
    }
    $quote['items'] = $items;
    return ['quote' => $quote, 'changed' => $changed];
}

function quote_is_on_day(array $quote, string $day): bool
{
    $compact = str_replace('-', '', $day);
    if (str_contains((string)($quote['no'] ?? ''), $compact)) return true;
    foreach (['date', 'createdAt', 'updatedAt'] as $key) {
        if (str_contains((string)($quote[$key] ?? ''), $day)) return true;
    }
    return false;
}

function quote_patch_vendor_tax_in_payload(array $data, string $day): array
{
    $quotes = is_array($data['quotations'] ?? null) ? $data['quotations'] : [];
    $quoteCount = 0;
    $itemCount = 0;
    $nos = [];
    foreach ($quotes as $i => $quote) {
        if (!is_array($quote) || !quote_is_on_day($quote, $day)) continue;
        $result = quote_apply_vendor_cost_tax($quote);
        if ($result['changed'] <= 0) continue;
        $quotes[$i] = $result['quote'];
        if (!empty($quotes[$i]['updatedAt'])) $quotes[$i]['updatedAt'] = date('Y-m-d H:i:s');
        $quoteCount++;
        $itemCount += $result['changed'];
        $nos[] = (string)($quote['no'] ?? $quote['id'] ?? '');
    }
    $data['quotations'] = $quotes;
    return ['data' => $data, 'quotes' => $quoteCount, 'items' => $itemCount, 'nos' => $nos];
}

function quote_encode_app_data(array $data): string
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') throw new RuntimeException('估價單 JSON 無法寫回');
    return $json;
}

function quote_patch_vendor_tax_json_text(string $json, string $day): array
{
    $data = json_decode($json, true);
    if (!is_array($data)) throw new RuntimeException('估價單 JSON 無法讀取');
    $result = quote_patch_vendor_tax_in_payload($data, $day);
    $result['json'] = quote_encode_app_data($result['data']);
    return $result;
}

function quote_patch_vendor_tax_json_file(string $inPath, string $outPath, string $day): array
{
    $raw = @file_get_contents($inPath);
    if (!is_string($raw) || $raw === '') throw new RuntimeException('找不到估價單 JSON');
    $result = quote_patch_vendor_tax_json_text($raw, $day);
    if (file_put_contents($outPath, $result['json']) === false) throw new RuntimeException('估價單 JSON 無法寫出');
    return ['quotes' => $result['quotes'], 'items' => $result['items'], 'nos' => $result['nos']];
}

function quote_patch_vendor_tax_sqlite(string $path, string $day): array
{
    if (!is_file($path)) throw new RuntimeException('找不到估價單資料庫');
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('PHP 沒有 sqlite PDO');
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $row = $pdo->query('SELECT json_data FROM app_data WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new RuntimeException('估價單資料庫沒有 app_data');
    $result = quote_patch_vendor_tax_json_text((string)($row['json_data'] ?? ''), $day);
    $stmt = $pdo->prepare('UPDATE app_data SET json_data = ? WHERE id = 1');
    $stmt->execute([$result['json']]);
    return ['quotes' => $result['quotes'], 'items' => $result['items'], 'nos' => $result['nos']];
}
