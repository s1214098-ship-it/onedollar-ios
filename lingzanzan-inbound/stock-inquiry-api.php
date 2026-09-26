<?php
declare(strict_types=1);
// parse-ok 20260817-save-fix-1
// LZ_PHOTO_VIEWS_20260926
// LZ_REPLACE_IMGS_20260926

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@ini_set('log_errors', '1');
@ini_set('memory_limit', '1024M');
@ini_set('max_execution_time', '180');
if (ob_get_level() === 0) ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$dataDir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
$productsFile = $dataDir . DIRECTORY_SEPARATOR . 'products.json';
$skusFile = $dataDir . DIRECTORY_SEPARATOR . 'skus.json';
$stateFile = $dataDir . DIRECTORY_SEPARATOR . 'admin-state.json';
$inquiriesFile = $dataDir . DIRECTORY_SEPARATOR . 'inquiries.json';
$ordersFile = $dataDir . DIRECTORY_SEPARATOR . 'orders.json';
$customersFile = $dataDir . DIRECTORY_SEPARATOR . 'customers.json';
$membersFile = $dataDir . DIRECTORY_SEPARATOR . 'members.json';
$salesCustomersFile = $dataDir . DIRECTORY_SEPARATOR . 'sales-customers.json';
$salesSessionsFile = $dataDir . DIRECTORY_SEPARATOR . 'sales-sessions.json';
$adminSessionsFile = $dataDir . DIRECTORY_SEPARATOR . 'admin-sessions.json';
$orderIssuesFile = $dataDir . DIRECTORY_SEPARATOR . 'order-issues.json';
$tempProductsFile = $dataDir . DIRECTORY_SEPARATOR . 'temp-products.json';
$freightTrackingFile = $dataDir . DIRECTORY_SEPARATOR . 'freight-forwarding-tracking.json';
$purchaseReceiptsFile = $dataDir . DIRECTORY_SEPARATOR . 'purchase-receiving-documents.json';
$preorderPurchaseLedgerFile = $dataDir . DIRECTORY_SEPARATOR . 'preorder-purchase-ledger.json';
$orderPaymentsFile = $dataDir . DIRECTORY_SEPARATOR . 'order-payments.json';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'image-storage.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'customer-member-photo-sync.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'preorder-purchase-ledger.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'member-risk-lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'sales-scope.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'zhangzhang-pricing.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'barcode-format-lib.php';

function respond($payload, int $status = 200): void {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

set_exception_handler(static function (Throwable $error): void {
    respond(['ok' => false, 'error' => '正式入庫中斷：' . $error->getMessage()], 500);
});
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if (!is_array($err)) return;
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int)($err['type'] ?? 0), $fatal, true)) return;
    if (headers_sent()) return;
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => '後台程式中斷：' . trim((string)($err['message'] ?? 'unknown')),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

function read_json(string $file): array {
    static $cache = [];
    if (!is_file($file)) return [];
    $load = static function (string $path) {
        $raw = @file_get_contents($path);
        if ($raw === false) return [null, false];
        // Tolerate trailing NUL padding left by an interrupted NAS/SMB replace.
        $raw = rtrim($raw, "\0 \t\n\r\x0B");
        if ($raw === '') return [[], true];
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $data = json_decode($raw, true);
        return [is_array($data) ? $data : null, true];
    };
    $mtime = @filemtime($file);
    $size = @filesize($file);
    $key = $file . "\0" . (string)$mtime . "\0" . (string)$size;
    if (isset($cache[$key])) return $cache[$key];
    [$data, $ok] = $load($file);
    if (!$ok || !is_array($data)) {
        usleep(180000);
        clearstatcache(true, $file);
        $mtime = @filemtime($file);
        $size = @filesize($file);
        $key = $file . "\0" . (string)$mtime . "\0" . (string)$size;
        if (isset($cache[$key])) return $cache[$key];
        [$data] = $load($file);
    }
    return $cache[$key] = is_array($data) ? $data : [];
}

function request_bearer_token(): string {
    $custom = trim((string)($_SERVER['HTTP_X_LINGZANZAN_ADMIN_SESSION'] ?? ''));
    if (preg_match('/^[A-Za-z0-9_-]{32,160}$/', $custom)) return $custom;
    $header = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^Bearer\s+([A-Za-z0-9_-]{32,160})$/i', $header, $match)) return (string)$match[1];
    $cookie = trim((string)($_COOKIE['lz_admin_session'] ?? ''));
    return preg_match('/^[A-Za-z0-9_-]{32,160}$/', $cookie) ? $cookie : '';
}

function require_purchase_operator(string $sessionsFile, string $stateFile, array $allowedStaffPermissions = []): array {
    $token = request_bearer_token();
    if ($token === '') respond(['ok' => false, 'error' => '請重新登入後台後再查看採購統計'], 401);
    $targetHash = hash('sha256', $token);
    $now = time();
    foreach (read_json($sessionsFile) as $session) {
        if (!is_array($session) || (int)($session['expiresAt'] ?? 0) <= $now) continue;
        $savedHash = trim((string)($session['tokenHash'] ?? ''));
        if ($savedHash === '' || !hash_equals($savedHash, $targetHash)) continue;
        $role = strtolower(trim((string)($session['role'] ?? '')));
        $state = read_json($stateFile);
        if ($role === 'admin') {
            $sessionAccount = strtolower(trim((string)($session['account'] ?? '')));
            $currentAccount = strtolower(trim((string)($state['adminAccount'] ?? 'admin')));
            if ($sessionAccount === $currentAccount || ($currentAccount === 'admin' && $sessionAccount === '管理者')) {
                $fingerprint = hash('sha256', 'admin' . "\0" . $sessionAccount . "\0" . (string)($state['adminPasswordHash'] ?? ''));
                if ((string)($session['credentialFingerprint'] ?? '') !== '' && hash_equals((string)$session['credentialFingerprint'], $fingerprint)) return $session;
                respond(['ok' => false, 'error' => '管理者密碼已更新，請重新登入'], 401);
            }
            respond(['ok' => false, 'error' => '管理者帳號已更新，請重新登入'], 401);
        }
        if ($role === 'staff') {
            // Administrative staff may perform purchasing and receiving when
            // the manager has granted the matching operational permission.
            // Final finance/profit reporting is enforced by its own API/page
            // and is intentionally not granted here.
            if (!$allowedStaffPermissions) $allowedStaffPermissions = ['採購區', '採購進貨建檔'];
            $sessionAccount = strtolower(trim((string)($session['account'] ?? '')));
            foreach (is_array($state['staff'] ?? null) ? $state['staff'] : [] as $person) {
                if (!is_array($person) || strtolower(trim((string)($person['account'] ?? ''))) !== $sessionAccount) continue;
                $fingerprint = hash('sha256', 'staff' . "\0" . $sessionAccount . "\0" . (string)($person['password'] ?? ''));
                if ((string)($session['credentialFingerprint'] ?? '') === '' || !hash_equals((string)$session['credentialFingerprint'], $fingerprint)) {
                    respond(['ok' => false, 'error' => '員工密碼已更新，請重新登入'], 401);
                }
                $permissions = is_array($person['permissions'] ?? null) ? array_values($person['permissions']) : [];
                if (!array_intersect($allowedStaffPermissions, $permissions)) {
                    respond(['ok' => false, 'error' => '行政人員需要採購區、採購進貨建檔或本項作業權限'], 403);
                }
                return array_merge($session, [
                    'name' => trim((string)($person['name'] ?? ($session['name'] ?? ''))),
                    'permissions' => $permissions,
                ]);
            }
            respond(['ok' => false, 'error' => '員工帳號已停用，請重新登入'], 401);
        }
        respond(['ok' => false, 'error' => '目前帳號沒有採購統計權限'], 403);
    }
    respond(['ok' => false, 'error' => '登入已逾時，請重新登入後台'], 401);
}

function operator_is_admin(array $operator): bool {
    return strtolower(trim((string)($operator['role'] ?? ''))) === 'admin';
}

function reject_unless_admin_for_china_taiwan_live(array $operator): void {
    if (operator_is_admin($operator)) return;
    respond(['ok' => false, 'error' => '中國倉轉台灣現貨單只有管理者可以操作'], 403);
}

function externalize_embedded_data_images_only($value, string $baseDir) {
    if (is_string($value)) {
        return strpos($value, 'data:image/') === 0 ? externalize_data_images($value, $baseDir) : $value;
    }
    if (is_array($value)) {
        foreach ($value as $key => $item) $value[$key] = externalize_embedded_data_images_only($item, $baseDir);
    }
    return $value;
}

function receipt_color_match_key(string $value): string
{
    /* LZ_SPEC_COLOR_20260925: 粉紅色 vs 粉紅(PINK)、深藍色 vs 深藍(biru tua) 是同一色。
       LZ_SPEC_COLOR_MERGE_20260925: 進貨存檔合併到既有 SKU，不可新增第二個顏色列或條碼。
       LZ_COLOR_UNIFY_20260925: 咖啡/卡其/淺藍 also one family; never 白藍≡白色. */
    $raw = canonical_bilingual_color_name($value);
    if (preg_match('/白藍|白綠|白黑|白紅|史努比|小熊維尼|老花|粉底/u', $raw) === 1) {
        $raw = preg_replace('/\([^)]*\)/u', '', $raw) ?? $raw;
        $raw = function_exists('mb_strtolower') ? mb_strtolower(trim($raw), 'UTF-8') : strtolower(trim($raw));
        $raw = preg_replace('/\s+/u', '', $raw) ?? $raw;
        return $raw;
    }
    if (preg_match('/粉紅|粉色|pink/iu', $raw) === 1) return 'pink';
    if (preg_match('/深藍|藏青|biru\s*tua/iu', $raw) === 1) return 'navy';
    if (preg_match('/卡其|khaki|dril/iu', $raw) === 1) return 'khaki';
    if (preg_match('/咖啡|咖色|棕|cokelat/iu', $raw) === 1) return 'brown';
    if (preg_match('/淺藍|biru\s*muda/iu', $raw) === 1) return 'ltblue';
    $raw = preg_replace('/\([^)]*\)/u', '', $raw) ?? $raw;
    $raw = trim($raw);
    $raw = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
    $raw = preg_replace('/色$/u', '', $raw) ?? $raw;
    $raw = preg_replace('/\s+/u', '', $raw) ?? $raw;
    return $raw;
}

function canonicalize_color_tree($value, string $parentKey = '') {
    if (!is_array($value)) return $value;
    $result = [];
    foreach ($value as $key => $item) {
        $keyText = (string)$key;
        $nextKey = $parentKey === 'colorImages' ? canonical_bilingual_color_name($keyText) : $key;
        if (in_array($keyText, ['color', 'colorName'], true)) {
            $nextValue = canonical_bilingual_color_name($item);
        } else {
            $nextValue = canonicalize_color_tree($item, $keyText);
        }
        if (!array_key_exists($nextKey, $result) || $result[$nextKey] === '' || $result[$nextKey] === null) {
            $result[$nextKey] = $nextValue;
        }
    }
    return $result;
}

function write_json(string $file, array $payload): void {
    if (in_array(basename($file), ['inquiries.json', 'products.json', 'skus.json'], true)) {
        $payload = canonicalize_color_tree($payload);
    }
    // Final safeguard for every inquiry create/update path. Member-photo sync
    // and legacy edits can merge an older base64 image back into a row after
    // the request payload was already cleaned. Never let inquiries.json be
    // written with embedded images again.
    if (basename($file) === 'inquiries.json') {
        $imageProbe = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (is_string($imageProbe) && strpos($imageProbe, 'data:image/') !== false) {
            $payload = externalize_embedded_data_images_only($payload, __DIR__);
        }
    }
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    // freight-forwarding-tracking.json is ~10–20MB. Pretty-print makes delete/unbind
    // rewrite the whole ledger slowly enough that the admin UI times out and looks
    // like 刪除此預購單 failed even though the inquiry splice might still run.
    if (basename($file) !== 'freight-forwarding-tracking.json') {
        $flags |= JSON_PRETTY_PRINT;
    }
    $json = json_encode($payload, $flags);
    if ($json === false) respond(['ok' => false, 'error' => 'Gagal membuat data JSON'], 500);
    if (file_put_contents($tmp, $json, LOCK_EX) === false) respond(['ok' => false, 'error' => 'Gagal menulis file sementara NAS'], 500);
    if (!rename($tmp, $file)) {
        @unlink($tmp);
        respond(['ok' => false, 'error' => 'Gagal memperbarui file NAS'], 500);
    }
}

function freight_item_is_internal_use(array $item): bool {
    return freight_item_resolved_goods_purpose($item) === 'internal_use';
}

function freight_variant_rows(array $item): array {
    foreach (['variants', 'inventoryLines', 'lines'] as $key) {
        if (isset($item[$key]) && is_array($item[$key]) && $item[$key]) return array_values($item[$key]);
    }
    return [];
}

function freight_line_internal_use_qty(array $line, array $item = []): int {
    $qty = max(1, (int)($line['quantity'] ?? $line['qty'] ?? 1));
    $stored = max(0, (int)($line['internalUseQty'] ?? 0));
    if ($stored > 0) return min($qty, $stored);
    if (strtolower(trim((string)($item['goodsPurpose'] ?? ''))) === 'internal_use') return $qty;
    return 0;
}

function freight_item_internal_use_qty(array $item): int {
    $rows = freight_variant_rows($item);
    if ($rows) {
        $total = 0;
        foreach ($rows as $line) {
            if (!is_array($line)) continue;
            $total += freight_line_internal_use_qty($line, $item);
        }
        return $total;
    }
    return strtolower(trim((string)($item['goodsPurpose'] ?? ''))) === 'internal_use'
        ? max(1, (int)($item['quantity'] ?? $item['qty'] ?? 1))
        : 0;
}

function freight_item_sellable_qty(array $item): int {
    $rows = freight_variant_rows($item);
    if ($rows) {
        $total = 0;
        foreach ($rows as $line) {
            if (!is_array($line)) continue;
            $qty = max(1, (int)($line['quantity'] ?? $line['qty'] ?? 1));
            $total += max(0, $qty - freight_line_internal_use_qty($line, $item));
        }
        return $total;
    }
    $qty = max(1, (int)($item['quantity'] ?? $item['qty'] ?? 1));
    return max(0, $qty - freight_item_internal_use_qty($item));
}

function freight_item_resolved_goods_purpose(array $item): string {
    $internal = freight_item_internal_use_qty($item);
    $sellable = freight_item_sellable_qty($item);
    if ($internal > 0 && $sellable > 0) return 'mixed';
    if ($internal > 0) return 'internal_use';
    $saved = strtolower(trim((string)($item['goodsPurpose'] ?? '')));
    return $saved === 'internal_use' ? 'internal_use' : 'sellable';
}

function freight_receive_line_internal_use_qty(array $line): int {
    $qty = max(0, (int)($line['qty'] ?? $line['quantity'] ?? 0));
    return min($qty, max(0, (int)($line['internalUseQty'] ?? 0)));
}

function freight_receive_line_sellable_qty(array $line): int {
    $qty = max(0, (int)($line['qty'] ?? $line['quantity'] ?? 0));
    return max(0, $qty - freight_receive_line_internal_use_qty($line));
}

function freight_internal_use_expense_total(array $freightItem, array $receiptLog, int $actualQty): float {
    $total = 0.0;
    $internalQty = 0;
    foreach ($receiptLog as $line) {
        if (!is_array($line)) continue;
        $qty = max(0, (int)($line['qty'] ?? 0));
        $internal = min($qty, max(0, (int)($line['internalUseQty'] ?? 0)));
        $unit = max(0.0, (float)($line['unitCostTwd'] ?? 0));
        $total += $unit * $internal;
        $internalQty += $internal;
    }
    if ($total > 0) return round($total, 2);
    if ($internalQty <= 0) $internalQty = freight_item_internal_use_qty($freightItem);
    if ($internalQty <= 0) return 0.0;
    $fixed = !empty($freightItem['fixedTwdCost']) ? max(0.0, (float)($freightItem['existingTwdCost'] ?? 0)) : 0.0;
    $base = $fixed > 0 ? $fixed : round(max(0.0, (float)($freightItem['costRmb'] ?? 0)) * 5, 2);
    $handling = max(0.0, (float)($freightItem['handlingPerItemTwd'] ?? 0));
    $average = max(0.0, (float)($freightItem['costAverageTwd'] ?? 0));
    $unit = $average > 0 ? $average : ($base + $handling);
    return round($unit * $internalQty, 2);
}

function freight_record_inbound_internal_use_expense(string $dataDir, array $freightItem, array $receiptLog, int $actualQty, string $operationId, string $receivedBy, string $receivedAt, string $warehouse): array {
    $ledgerFile = $dataDir . DIRECTORY_SEPARATOR . 'finance-ledger.json';
    $recordsFile = $dataDir . DIRECTORY_SEPARATOR . 'company-self-use.json';
    $amount = freight_internal_use_expense_total($freightItem, $receiptLog, $actualQty);
    if ($amount <= 0) {
        return ['ok' => false, 'skipped' => true, 'error' => '尚無有效成本，未列入公司自用開銷'];
    }
    $ledgerLock = @fopen($ledgerFile . '.file.lock', 'c+');
    $recordsLock = @fopen($recordsFile . '.file.lock', 'c+');
    if ($ledgerLock === false || $recordsLock === false || !flock($ledgerLock, LOCK_EX) || !flock($recordsLock, LOCK_EX)) {
        if (is_resource($ledgerLock)) { flock($ledgerLock, LOCK_UN); fclose($ledgerLock); }
        if (is_resource($recordsLock)) { flock($recordsLock, LOCK_UN); fclose($recordsLock); }
        return ['ok' => false, 'error' => '目前無法鎖定財務資料，公司自用成本稍後再入帳'];
    }
    $ledger = read_json($ledgerFile);
    $records = read_json($recordsFile);
    foreach ($ledger as $row) {
        if (is_array($row) && (string)($row['sourceOperationId'] ?? '') === $operationId && (string)($row['sourceType'] ?? '') === 'company_self_use') {
            flock($ledgerLock, LOCK_UN); fclose($ledgerLock);
            flock($recordsLock, LOCK_UN); fclose($recordsLock);
            return ['ok' => true, 'duplicate' => true, 'id' => (string)($row['id'] ?? ''), 'amount' => (float)($row['amount'] ?? $amount)];
        }
    }
    $now = date(DATE_ATOM);
    $date = preg_match('/^\d{4}-\d{2}-\d{2}/', $receivedAt) ? substr($receivedAt, 0, 10) : date('Y-m-d');
    $productName = trim((string)($freightItem['productName'] ?? $freightItem['productFiledProductTitle'] ?? ''));
    $productCode = trim((string)($freightItem['productCode'] ?? $freightItem['productFiledProductCode'] ?? ''));
    $color = trim((string)($freightItem['color'] ?? ''));
    $size = trim((string)($freightItem['size'] ?? ''));
    $qty = 0;
    foreach ($receiptLog as $line) {
        if (!is_array($line)) continue;
        $qty += freight_receive_line_internal_use_qty($line);
    }
    if ($qty <= 0) $qty = freight_item_internal_use_qty($freightItem);
    $qty = max(1, $qty);
    $unit = round($amount / $qty, 2);
    $suffix = strtoupper(substr(hash('sha256', $operationId), 0, 12));
    $ledgerId = 'FIN-SELF-FRT-' . $suffix;
    $recordId = 'SELF-FRT-' . $suffix;
    $warehouseLabel = $warehouse === 'CN' ? '中國倉' : ($warehouse === 'ID' ? '印尼倉' : '台灣倉');
    $note = sprintf('物流進貨公司自用｜%s %s｜%s %s｜%d 件 × NT$%s｜總成本 NT$%s｜%s', $productCode, $productName, $color, $size, $qty, $unit, $amount, trim((string)($freightItem['trackingNo'] ?? $freightItem['freightLinkNo'] ?? '')));
    $ledger[] = [
        'id' => $ledgerId,
        'date' => $date,
        'type' => 'expense',
        'category' => '公司自用',
        'amount' => $amount,
        'paidAmount' => $amount,
        'outstanding' => 0,
        'note' => $note,
        'sourceType' => 'company_self_use',
        'sourceOperationId' => $operationId,
        'warehouseCode' => $warehouse,
        'warehouse' => $warehouseLabel,
        'productId' => trim((string)($freightItem['productId'] ?? $freightItem['productFiledProductId'] ?? '')),
        'skuId' => trim((string)($freightItem['skuId'] ?? $freightItem['productFiledSkuId'] ?? '')),
        'quantity' => $qty,
        'unitCostTwd' => $unit,
        'totalInventoryCostTwd' => $amount,
        'companyShareTwd' => $amount,
        'salesShareTwd' => 0,
        'freightItemId' => trim((string)($freightItem['id'] ?? '')),
        'createdAt' => $now,
        'createdBy' => $receivedBy,
    ];
    $records[] = [
        'id' => $recordId,
        'operationId' => $operationId,
        'type' => 'company_self_use',
        'status' => 'inbound_expensed',
        'reason' => '物流進貨公司自用',
        'dispositionType' => 'company_use',
        'dispositionLabel' => '公司自用',
        'productId' => trim((string)($freightItem['productId'] ?? $freightItem['productFiledProductId'] ?? '')),
        'productCode' => $productCode,
        'productName' => $productName,
        'skuId' => trim((string)($freightItem['skuId'] ?? $freightItem['productFiledSkuId'] ?? '')),
        'color' => $color,
        'size' => $size,
        'warehouseCode' => $warehouse,
        'warehouse' => $warehouseLabel,
        'quantity' => $qty,
        'unitCostTwd' => $unit,
        'totalInventoryCostTwd' => $amount,
        'totalExpenseTwd' => $amount,
        'companyShareTwd' => $amount,
        'salesShareTwd' => 0,
        'freightItemId' => trim((string)($freightItem['id'] ?? '')),
        'receivedDate' => $date,
        'receivedAt' => $receivedAt !== '' ? $receivedAt : $now,
        'handledBy' => $receivedBy,
        'note' => $note,
    ];
    $writeQuiet = static function (string $file, array $payload): bool {
        $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    };
    $ok = $writeQuiet($ledgerFile, array_slice($ledger, -10000)) && $writeQuiet($recordsFile, array_slice($records, -10000));
    flock($ledgerLock, LOCK_UN); fclose($ledgerLock);
    flock($recordsLock, LOCK_UN); fclose($recordsLock);
    if (!$ok) return ['ok' => false, 'error' => '公司自用開銷寫入失敗'];
    return ['ok' => true, 'id' => $ledgerId, 'recordId' => $recordId, 'amount' => $amount];
}

function valid_sales(string $value): string {
    $value = strtolower(trim($value));
    return in_array($value, ['lingzanzan', 'any'], true) ? $value : 'lingzanzan';
}

function unique_inquiry_line_id($preferred, array &$seen): string {
    $lineId = trim((string)$preferred);
    if ($lineId === '' || isset($seen[$lineId])) {
        do {
            $lineId = 'INQL-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
        } while (isset($seen[$lineId]));
    }
    $seen[$lineId] = true;
    return $lineId;
}

function sales_label(string $sales): string {
    return $sales === 'any' ? 'ANY' : 'LINGZANZAN';
}

function temp_product_rows(string $file, string $day = ''): array {
    $day = $day !== '' ? $day : date('Y-m-d');
    return array_values(array_filter(read_json($file), function ($row) use ($day) {
        return is_array($row) && (string)($row['businessDate'] ?? '') === $day;
    }));
}

function next_temp_product_code(array $rows): string {
    $prefix = 'TMP-' . date('Ymd') . '-';
    $max = 0;
    foreach ($rows as $row) {
        $code = (string)($row['code'] ?? '');
        if (strpos($code, $prefix) !== 0) continue;
        $max = max($max, (int)substr($code, strlen($prefix)));
    }
    return $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}

function catalog_id_from_code(string $code): string {
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', trim($code)) ?? '');
    return 'p-' . trim($slug, '-');
}

function sales_accounts(string $salesCustomersFile, string $stateFile): array {
    $rows = read_json($salesCustomersFile);
    if ($rows) return $rows;
    $state = read_json($stateFile);
    return is_array($state['salesCustomers'] ?? null) ? $state['salesCustomers'] : [];
}

function find_sales_account(string $account, string $salesCustomersFile, string $stateFile): ?array {
    $account = strtolower(trim($account));
    if ($account === '') return null;
    foreach (sales_accounts($salesCustomersFile, $stateFile) as $person) {
        if (!is_array($person)) continue;
        if (strtolower(trim((string)($person['account'] ?? ''))) === $account) return $person;
    }
    return null;
}

function trusted_admin_business_login(?array $login, string $stateFile, string $salesSessionsFile): ?array {
    global $adminSessionsFile;
    if (!is_array($login) || strtolower(trim((string)($login['role'] ?? ''))) !== 'admin') return null;
    $account = strtolower(trim((string)($login['account'] ?? '')));
    $token = trim((string)($login['token'] ?? ''));
    if ($account === '' || $token === '') return null;

    $now = time();
    $bridge = null;
    foreach (read_json($salesSessionsFile) as $session) {
        if (!is_array($session) || strtolower(trim((string)($session['role'] ?? ''))) !== 'admin') continue;
        if ((int)($session['expiresAt'] ?? 0) <= $now) continue;
        if (strtolower(trim((string)($session['account'] ?? ''))) !== $account) continue;
        if (!hash_equals((string)($session['token'] ?? ''), $token)) continue;
        $bridge = $session;
        break;
    }
    if (!$bridge) return null;

    $sourceHash = trim((string)($bridge['sourceAdminSessionHash'] ?? ''));
    $bridgeFingerprint = trim((string)($bridge['credentialFingerprint'] ?? ''));
    if ($sourceHash === '' || $bridgeFingerprint === '') return null;

    $state = read_json($stateFile);
    $currentAccount = strtolower(trim((string)($state['adminAccount'] ?? 'admin')));
    foreach (read_json($adminSessionsFile) as $adminSession) {
        if (!is_array($adminSession) || (int)($adminSession['expiresAt'] ?? 0) <= $now) continue;
        if (strtolower(trim((string)($adminSession['role'] ?? ''))) !== 'admin') continue;
        $savedHash = trim((string)($adminSession['tokenHash'] ?? ''));
        if ($savedHash === '' || !hash_equals($savedHash, $sourceHash)) continue;
        $sessionAccount = strtolower(trim((string)($adminSession['account'] ?? '')));
        if ($sessionAccount !== $currentAccount && !($currentAccount === 'admin' && $sessionAccount === '管理者')) return null;
        $fingerprint = hash('sha256', 'admin' . "\0" . $sessionAccount . "\0" . (string)($state['adminPasswordHash'] ?? ''));
        if (!hash_equals($bridgeFingerprint, $fingerprint)) return null;
        if (!hash_equals((string)($adminSession['credentialFingerprint'] ?? ''), $fingerprint)) return null;
        return [
            'role' => 'admin',
            'account' => trim((string)($bridge['account'] ?? $adminSession['account'] ?? 'admin')),
            'name' => trim((string)($bridge['name'] ?? $adminSession['name'] ?? '管理者')),
        ];
    }
    return null;
}

function trusted_business_login(?array $login, string $salesCustomersFile, string $stateFile, string $salesSessionsFile): ?array {
    if (!is_array($login)) return null;
    if (strtolower(trim((string)($login['role'] ?? ''))) === 'admin') {
        return trusted_admin_business_login($login, $stateFile, $salesSessionsFile);
    }
    if (strtolower(trim((string)($login['role'] ?? ''))) !== 'sales') return null;
    $account = strtolower(trim((string)($login['account'] ?? '')));
    $token = trim((string)($login['token'] ?? ''));
    if ($account === '' || $token === '') return null;
    $serverAccount = find_sales_account($account, $salesCustomersFile, $stateFile);
    if (!$serverAccount) return null;
    $now = time();
    foreach (read_json($salesSessionsFile) as $session) {
        if (!is_array($session) || strtolower(trim((string)($session['role'] ?? 'sales'))) !== 'sales') continue;
        if (strtolower(trim((string)($session['account'] ?? ''))) !== $account) continue;
        if (!hash_equals((string)($session['token'] ?? ''), $token)) continue;
        if ((int)($session['expiresAt'] ?? 0) <= $now) continue;
        return [
            'role' => 'sales',
            'account' => trim((string)($serverAccount['account'] ?? $account)),
            'name' => trim((string)($serverAccount['name'] ?? $serverAccount['account'] ?? $account)),
        ];
    }
    return null;
}

function valid_sales_account(?array $login, string $salesCustomersFile, string $stateFile, string $salesSessionsFile): bool {
    return trusted_business_login($login, $salesCustomersFile, $stateFile, $salesSessionsFile) !== null;
}

function normalized_sales_owner($value): string {
    return strtolower(trim((string)($value ?? '')));
}

function normalized_order_phone($value): string {
    $digits = preg_replace('/\D+/', '', (string)$value) ?? '';
    if (strpos($digits, '00886') === 0) {
        $local = substr($digits, 5);
        if (strpos($local, '0') === 0) $local = substr($local, 1);
        $digits = $local !== '' ? ('0' . $local) : '';
    } elseif (strpos($digits, '886') === 0) {
        $local = substr($digits, 3);
        if (strpos($local, '0') === 0) $local = substr($local, 1);
        $digits = $local !== '' ? ('0' . $local) : '';
    } elseif (strlen($digits) === 9 && strpos($digits, '9') === 0) {
        $digits = '0' . $digits;
    }
    return $digits;
}

function same_day_order_date(array $row, DateTimeZone $timezone): string {
    // Server creation time is authoritative for "entered today". orderDate is
    // only a fallback for old records that predate createdAt.
    $value = trim((string)($row['createdAt'] ?? ''));
    if ($value !== '') {
        try {
            return (new DateTimeImmutable($value))->setTimezone($timezone)->format('Y-m-d');
        } catch (Throwable $error) {
            // Continue to the legacy orderDate fallback below.
        }
    }
    $orderDate = trim((string)($row['orderDate'] ?? ''));
    if (preg_match('/^(\d{4})[-\/]?(\d{2})[-\/]?(\d{2})/', $orderDate, $match)) {
        return $match[1] . '-' . $match[2] . '-' . $match[3];
    }
    return '';
}

function same_day_order_kind(array $row, bool $inquiry): string {
    $id = strtoupper(trim((string)($row['id'] ?? '')));
    $source = strtolower(trim((string)($row['source'] ?? '')));
    $type = strtolower(trim((string)($row['orderType'] ?? $row['type'] ?? '')));
    $status = strtolower(trim((string)($row['status'] ?? '')));
    if (strpos($id, 'LIVE-') === 0 || $source === 'live-order' || strpos($type, 'live') !== false) return 'live';
    if ($inquiry && ($type === 'preorder' || strpos($status, 'preorder') !== false || strpos($id, 'BYORDER-') === 0)) return 'preorder';
    return 'ready';
}

function receipt_row_is_live_order(array $row): bool {
    $id = strtoupper(trim((string)($row['id'] ?? '')));
    $source = strtolower(trim((string)($row['source'] ?? $row['orderSource'] ?? '')));
    $type = strtolower(trim((string)($row['orderType'] ?? $row['type'] ?? $row['sourceOrderType'] ?? '')));
    return strpos($id, 'LIVE-') === 0
        || strpos($source, 'live') !== false
        || strpos($type, 'live') !== false
        || trim((string)($row['liveSource'] ?? '')) !== ''
        || trim((string)($row['liveSession'] ?? '')) !== '';
}

function receipt_row_is_preorder_order(array $row): bool {
    $id = trim((string)($row['id'] ?? ''));
    $type = strtolower(trim((string)($row['orderType'] ?? $row['type'] ?? '')));
    $status = strtolower(trim((string)($row['status'] ?? '')));
    return $type === 'preorder'
        || strpos($status, 'preorder') !== false
        || preg_match('/^BYORDER-/i', $id) === 1;
}

function receipt_should_auto_wait_notify_after_arrival(array $row, string $warehouse): bool {
    $warehouse = strtoupper(trim($warehouse));
    if (receipt_row_is_live_order($row)) return in_array($warehouse, ['TW', 'CN'], true);
    if (receipt_row_is_preorder_order($row)) return false;
    return $warehouse === 'TW';
}

function same_day_order_owner(array $row): string {
    $customer = is_array($row['customer'] ?? null) ? $row['customer'] : [];
    return trim((string)($row['salesName'] ?? $row['salesAccount'] ?? $row['sales'] ?? $customer['salesName'] ?? ''));
}

function same_day_order_belongs_to_login(array $row, array $trustedLogin): bool {
    $loginOwners = array_values(array_unique(array_filter([
        normalized_sales_owner($trustedLogin['name'] ?? ''),
        normalized_sales_owner($trustedLogin['account'] ?? ''),
    ], static fn($value) => $value !== '')));
    $customer = is_array($row['customer'] ?? null) ? $row['customer'] : [];
    $rowOwners = array_values(array_unique(array_filter([
        normalized_sales_owner($row['salesName'] ?? ''),
        normalized_sales_owner($row['salesAccount'] ?? ''),
        normalized_sales_owner($row['sales'] ?? ''),
        normalized_sales_owner($customer['salesName'] ?? ''),
        normalized_sales_owner($customer['salesAccount'] ?? ''),
        normalized_sales_owner($customer['sales'] ?? ''),
    ], static fn($value) => $value !== '')));
    return $loginOwners && $rowOwners && count(array_intersect($loginOwners, $rowOwners)) > 0;
}

function same_day_order_summary(array $row, string $kind, string $date, array $trustedLogin): array {
    $customer = is_array($row['customer'] ?? null) ? $row['customer'] : [];
    $items = [];
    foreach (is_array($row['items'] ?? null) ? $row['items'] : [] as $item) {
        if (!is_array($item)) continue;
        $items[] = [
            'code' => trim((string)($item['code'] ?? $item['productCode'] ?? '')),
            'title' => trim((string)($item['title'] ?? $item['name'] ?? '')),
            'color' => trim((string)($item['color'] ?? '')),
            'size' => trim((string)($item['size'] ?? '')),
            'qty' => max(0, (int)($item['qty'] ?? $item['quantity'] ?? 0)),
            'price' => max(0, (float)($item['price'] ?? 0)),
            'image' => trim((string)($item['image'] ?? '')),
        ];
    }
    return [
        'id' => trim((string)($row['id'] ?? '')),
        'kind' => $kind,
        'date' => $date,
        'createdAt' => trim((string)($row['createdAt'] ?? '')),
        'source' => trim((string)($row['source'] ?? '')),
        'orderType' => trim((string)($row['orderType'] ?? '')),
        'customerName' => trim((string)($customer['name'] ?? '')),
        'phone' => normalized_order_phone($customer['phone'] ?? ''),
        'address' => trim((string)($customer['address'] ?? '')),
        'deliveryLabel' => trim((string)($customer['deliveryLabel'] ?? $customer['deliveryType'] ?? '')),
        'customerRecordId' => trim((string)($customer['customerRecordId'] ?? $row['customerRecordId'] ?? '')),
        'salesName' => same_day_order_owner($row),
        'status' => trim((string)($row['status'] ?? '')),
        'statusLabel' => trim((string)($row['statusLabel'] ?? '')),
        'shippingFee' => max(0, (float)($row['shippingFee'] ?? $customer['shippingFee'] ?? 0)),
        'subtotal' => max(0, (float)($row['subtotal'] ?? 0)),
        'total' => max(0, (float)($row['total'] ?? 0)),
        'items' => $items,
        'sameSales' => same_day_order_belongs_to_login($row, $trustedLogin),
    ];
}

function sales_inquiry_belongs_to_login(array $inquiry, array $login): bool {
    $loginOwners = array_values(array_unique(array_filter([
        normalized_sales_owner($login['name'] ?? ''),
        normalized_sales_owner($login['account'] ?? ''),
    ], static fn($value) => $value !== '')));
    $inquiryOwners = array_values(array_unique(array_filter([
        normalized_sales_owner($inquiry['salesName'] ?? ''),
        normalized_sales_owner($inquiry['sales'] ?? ''),
        normalized_sales_owner($inquiry['salesAccount'] ?? ''),
    ], static fn($value) => $value !== '')));
    return $loginOwners && $inquiryOwners && count(array_intersect($loginOwners, $inquiryOwners)) > 0;
}

function require_sales_inquiry_login(array $payload, array $inquiry, string $salesCustomersFile, string $stateFile, string $salesSessionsFile): array {
    $login = is_array($payload['salesLogin'] ?? null) ? $payload['salesLogin'] : [];
    $trustedLogin = trusted_business_login($login, $salesCustomersFile, $stateFile, $salesSessionsFile);
    if (!$trustedLogin) respond(['ok' => false, 'error' => 'Sales login diperlukan'], 403);
    if (($trustedLogin['role'] ?? '') !== 'admin' && !sales_inquiry_belongs_to_login($inquiry, $trustedLogin)) {
        respond(['ok' => false, 'error' => '此訂單不屬於目前登入的業務'], 403);
    }
    return $trustedLogin;
}

function sales_inquiry_is_preorder(array $inquiry): bool {
    return (string)($inquiry['orderType'] ?? '') === 'preorder' || in_array((string)($inquiry['status'] ?? ''), ['preorder_pending', 'preorder_draft'], true);
}

function inquiry_item_wanted_qty(array $item): int {
    return max(1, (int)($item['qty'] ?? $item['quantity'] ?? $item['requestedQty'] ?? 1));
}

function inquiry_item_is_customer_reserved_receipt(array $item): bool {
    $wantedQty = inquiry_item_wanted_qty($item);
    $receivedQty = max(0, (int)($item['freightReceivedQty'] ?? 0));
    if ($receivedQty < $wantedQty) return false;
    $receivedWarehouse = strtoupper(trim((string)($item['freightReceivedWarehouse'] ?? '')));
    $allocationStatus = strtolower(trim((string)($item['priorityAllocationStatus'] ?? '')));
    return in_array($receivedWarehouse, ['TW', 'CN', 'ID'], true)
        || $allocationStatus === 'warehouse_reserved';
}

function inquiry_item_needs_physical_stock(array $item): bool {
    return !inquiry_item_is_customer_reserved_receipt($item)
        && max(0, (int)($item['freightReceivedQty'] ?? 0)) < inquiry_item_wanted_qty($item);
}

function inquiry_item_fulfill_warehouse(array $item): string {
    $direct = strtoupper(trim((string)(
        $item['freightReceivedWarehouse']
        ?? $item['allocationSourceWarehouse']
        ?? $item['sourceWarehouseCode']
        ?? ''
    )));
    if (in_array($direct, ['TW', 'CN', 'ID'], true)) return $direct;
    $skuId = trim((string)($item['skuId'] ?? $item['sku'] ?? ''));
    if (preg_match('/-(TW|CN|ID)$/i', $skuId, $suffixMatch)) return strtoupper($suffixMatch[1]);
    if (preg_match('/-(TW|CN|ID)(?:-|$)/i', $skuId, $midMatch)) return strtoupper($midMatch[1]);
    return '';
}

function sales_inquiry_items_are_locked(array $inquiry): bool {
    // 出貨核對要能隨時刪除、換品、改價。轉正式作業的 completed、
    // 已取消後要改回商品，都不能再擋管理者。
    return false;
}

function sales_item_sku_matches(string $incoming, string $current): bool {
    $incoming = strtoupper(trim($incoming));
    $current = strtoupper(trim($current));
    if ($incoming === '' || $current === '') return true;
    if ($incoming === $current) return true;
    $incomingBase = preg_replace('/P\d+.*$/', '', $incoming) ?? $incoming;
    $currentBase = preg_replace('/P\d+.*$/', '', $current) ?? $current;
    if ($incomingBase !== '' && $incomingBase === $currentBase) return true;
    return str_starts_with($current, $incoming) || str_starts_with($incoming, $current);
}

function inquiry_converted_order_id(array $inquiry): string {
    foreach (['convertedOrderId', 'convertedToOrderId', 'formalOrderId', 'orderId'] as $field) {
        $id = trim((string)($inquiry[$field] ?? ''));
        if ($id !== '') return $id;
    }
    return '';
}

function order_closed_for_convert(array $order): bool {
    $status = strtolower(trim((string)($order['status'] ?? '')));
    $delivery = strtolower(trim((string)($order['deliveryState'] ?? '')));
    if (!empty($order['revertedToWaitingAt'])) return true;
    return in_array($status, ['cancelled', 'returned', 'refunded', 'reverted_to_waiting'], true)
        || in_array($delivery, ['cancelled', 'returned'], true);
}

function order_is_closed_shipment(array $order): bool {
    $status = strtolower(trim((string)($order['status'] ?? '')));
    $delivery = strtolower(trim((string)($order['deliveryState'] ?? '')));
    return in_array($status, ['shipped', 'in_transit', 'delivered', 'completed'], true)
        || in_array($delivery, ['in_transit', 'delivered'], true);
}

function next_unused_convert_order_id(array $orders, string $baseOrderId): string {
    $baseOrderId = trim($baseOrderId);
    if ($baseOrderId === '') return $baseOrderId;
    $used = [];
    foreach ($orders as $order) {
        if (!is_array($order)) continue;
        $id = trim((string)($order['id'] ?? ''));
        if ($id !== '') $used[$id] = true;
    }
    if (!isset($used[$baseOrderId])) return $baseOrderId;
    $n = 2;
    while (isset($used[$baseOrderId . '-' . $n])) $n++;
    return $baseOrderId . '-' . $n;
}

function inquiry_item_already_shipped_on_formal(array $orders, string $inquiryId, array $item): bool {
    $lineId = trim((string)($item['lineId'] ?? ''));
    if ($lineId === '' || $inquiryId === '') return false;
    $appendedAt = trim((string)($item['appendedAt'] ?? ''));
    $appendedTs = $appendedAt !== '' ? strtotime($appendedAt) : false;
    foreach ($orders as $order) {
        if (!is_array($order)) continue;
        $sourceOk = trim((string)($order['id'] ?? '')) === $inquiryId;
        foreach (['sourceInquiryId', 'inquiryId', 'convertedFromInquiryId'] as $field) {
            if (trim((string)($order[$field] ?? '')) === $inquiryId) {
                $sourceOk = true;
                break;
            }
        }
        if (!$sourceOk || !order_is_closed_shipment($order)) continue;
        $orderAt = trim((string)($order['preorderConvertedAt'] ?? $order['createdAt'] ?? ''));
        $orderTs = $orderAt !== '' ? strtotime($orderAt) : false;
        // 轉正式之後才加進預購單的商品，不算已出過。
        if ($appendedTs && $orderTs && $appendedTs > $orderTs) continue;
        foreach ((array)($order['items'] ?? []) as $orderItem) {
            if (!is_array($orderItem)) continue;
            if (trim((string)($orderItem['lineId'] ?? '')) === $lineId) return true;
        }
    }
    return false;
}

function find_converted_order_index(array $orders, array $inquiry): int {
    $ids = [];
    foreach (['convertedOrderId', 'convertedToOrderId', 'formalOrderId', 'orderId'] as $field) {
        $id = trim((string)($inquiry[$field] ?? ''));
        if ($id !== '') $ids[$id] = true;
    }
    $inquiryId = trim((string)($inquiry['id'] ?? ''));
    foreach ($orders as $index => $order) {
        if (!is_array($order)) continue;
        if (order_closed_for_convert($order)) continue;
        $orderId = trim((string)($order['id'] ?? ''));
        if ($orderId !== '' && isset($ids[$orderId])) return (int)$index;
        if ($inquiryId === '') continue;
        foreach (['sourceInquiryId', 'convertedFromInquiryId', 'inquiryId'] as $field) {
            if (trim((string)($order[$field] ?? '')) === $inquiryId) return (int)$index;
        }
    }
    return -1;
}

function apply_converted_order_stock_delta(array $order, ?array $oldItem, ?array $newItem, string $skusFile, string $stateFile): void {
    if (empty($order['inventoryDeducted'])) return;
    if (is_array($oldItem) && !is_array($newItem)) {
        adjust_stock([$oldItem], 1, $skusFile, $stateFile);
        return;
    }
    if (!is_array($oldItem) || !is_array($newItem)) return;
    $oldSku = trim((string)($oldItem['skuId'] ?? $oldItem['sku'] ?? $oldItem['code'] ?? ''));
    $newSku = trim((string)($newItem['skuId'] ?? $newItem['sku'] ?? $newItem['code'] ?? ''));
    $oldQty = max(0, (int)($oldItem['qty'] ?? $oldItem['quantity'] ?? $oldItem['requestedQty'] ?? 0));
    $newQty = max(0, (int)($newItem['qty'] ?? $newItem['quantity'] ?? $newItem['requestedQty'] ?? 0));
    if ($newSku !== '' && $oldSku !== '' && $newSku !== $oldSku) {
        adjust_stock([$oldItem], 1, $skusFile, $stateFile);
        adjust_stock([$newItem], -1, $skusFile, $stateFile);
        return;
    }
    if ($newQty > $oldQty) {
        $delta = $newItem;
        $delta['qty'] = $newQty - $oldQty;
        $delta['quantity'] = $newQty - $oldQty;
        adjust_stock([$delta], -1, $skusFile, $stateFile);
        return;
    }
    if ($newQty < $oldQty) {
        $delta = $oldItem;
        $delta['qty'] = $oldQty - $newQty;
        $delta['quantity'] = $oldQty - $newQty;
        adjust_stock([$delta], 1, $skusFile, $stateFile);
    }
}

function assert_converted_order_items_editable(string $ordersFile, array $inquiry): int {
    $converted = inquiry_converted_order_id($inquiry) !== ''
        || strtolower(trim((string)($inquiry['status'] ?? ''))) === 'converted';
    if (!$converted) return -1;
    $orders = read_json($ordersFile);
    $orderIndex = find_converted_order_index($orders, $inquiry);
    if ($orderIndex < 0) return -1;
    $order = $orders[$orderIndex];
    return $orderIndex;
}

function sync_converted_formal_order_items(
    string $ordersFile,
    string $stateFile,
    string $skusFile,
    array $inquiry,
    array $newItems,
    ?array $oldItem,
    ?array $newItem,
    bool $applyStock,
    string $operator,
    string $reason
): ?array {
    $orders = read_json($ordersFile);
    $orderIndex = find_converted_order_index($orders, $inquiry);
    if ($orderIndex < 0) return null;
    $order = $orders[$orderIndex];
    if ($applyStock) {
        apply_converted_order_stock_delta($order, $oldItem, $newItem, $skusFile, $stateFile);
    }
    $subtotal = 0.0;
    foreach ($newItems as $line) {
        if (!is_array($line)) continue;
        $subtotal += max(0, (float)($line['price'] ?? $line['unitPrice'] ?? 0)) * max(0, (int)($line['qty'] ?? $line['quantity'] ?? 0));
    }
    $subtotal = round($subtotal, 2);
    $shippingFee = round(max(0, (float)($order['shippingFee'] ?? ($order['customer']['shippingFee'] ?? 0))), 2);
    $newTotal = round($subtotal + $shippingFee, 2);
    $now = date(DATE_ATOM);
    $orders[$orderIndex]['items'] = array_values($newItems);
    $orders[$orderIndex]['subtotal'] = $subtotal;
    $orders[$orderIndex]['total'] = $newTotal;
    $orders[$orderIndex]['updatedAt'] = $now;
    $history = is_array($orders[$orderIndex]['itemChangeHistory'] ?? null) ? $orders[$orderIndex]['itemChangeHistory'] : [];
    array_unshift($history, [
        'action' => 'sync-from-inquiry',
        'at' => $now,
        'by' => $operator,
        'reason' => $reason,
        'before' => $oldItem,
        'after' => $newItem,
        'newTotal' => $newTotal,
    ]);
    $orders[$orderIndex]['itemChangeHistory'] = array_slice($history, 0, 100);
    write_json($ordersFile, $orders);
    $state = read_json($stateFile);
    $state['orders'] = $orders;
    $state['updatedAt'] = $now;
    write_json($stateFile, $state);
    return $orders[$orderIndex];
}

function customer_history_record_id(array $row): string {
    $customer = is_array($row['customer'] ?? null) ? $row['customer'] : [];
    foreach ([
        $customer['customerRecordId'] ?? '', $customer['customerId'] ?? '', $customer['customerRef'] ?? '',
        $row['customerRecordId'] ?? '', $row['customerId'] ?? '', $row['customerRef'] ?? ''
    ] as $value) {
        $value = trim((string)$value);
        if ($value !== '') return $value;
    }
    return '';
}

function customer_history_is_unfinished_preorder(array $row): bool {
    $status = strtolower(trim((string)($row['status'] ?? '')));
    if ($status === 'cancelled' || $status === 'converted') return false;
    if (!empty($row['convertedOrderId']) || !empty($row['convertedToOrderId'])) return false;
    return receipt_row_is_preorder_order($row);
}

function customer_history_is_recent(array $row, DateTimeImmutable $cutoff, DateTimeZone $timezone): bool {
    $value = trim((string)($row['createdAt'] ?? $row['orderDate'] ?? ''));
    if ($value === '') return false;
    try {
        return (new DateTimeImmutable($value, $timezone))->setTimezone($timezone) >= $cutoff;
    } catch (Throwable $error) {
        return false;
    }
}

function require_sales_inquiry_editable(array $inquiry, bool $expectPreorder): void {
    $isPreorder = sales_inquiry_is_preorder($inquiry);
    $status = (string)($inquiry['status'] ?? '');
    $converted = !empty($inquiry['convertedToOrderId']) || !empty($inquiry['convertedOrderId']) || !empty($inquiry['orderId']) || $status === 'converted';
    if ($expectPreorder) {
        if (!$isPreorder || $status !== 'preorder_pending' || !empty($inquiry['inventoryReceived']) || $converted) {
            respond(['ok' => false, 'error' => '只有尚未完成到貨或轉出貨的預購單可以修改'], 409);
        }
        return;
    }
    if ($isPreorder || !in_array($status, ['pending_approval', 'reserved'], true) || $converted) {
        respond(['ok' => false, 'error' => '只有尚未完成的現貨詢價單可以修改售價'], 409);
    }
}

function reconcile_unshipped_inquiry_payment(array &$inquiry, array &$paymentRows, string $inquiryId, float $newTotal, string $orderType, float $oldInquiryTotal, array $login): array {
    $summary = is_array($inquiry['paymentSummary'] ?? null) ? $inquiry['paymentSummary'] : [];
    $stored = is_array($paymentRows[$inquiryId] ?? null) ? $paymentRows[$inquiryId] : [];
    // order-payments.json is authoritative, while paymentSummary is the fallback for
    // older inquiries that were saved before a finance row existed.
    $payment = array_merge($summary, $stored);
    $paid = max(0, (float)($payment['paidAmount'] ?? 0));
    $deposit = max(0, (float)($payment['depositAmount'] ?? 0));
    $received = max($paid, $deposit);
    if ($newTotal + 0.00001 < $received) {
        respond(['ok' => false, 'error' => '新總額不可低於累計已收或訂金 NT$' . number_format($received, 0)], 409);
    }

    $now = date(DATE_ATOM);
    $balance = max(0, $newTotal - $paid);
    $customer = is_array($inquiry['customer'] ?? null) ? $inquiry['customer'] : [];
    $history = is_array($stored['history'] ?? null) && count($stored['history']) > 0
        ? $stored['history']
        : (is_array($summary['history'] ?? null) ? $summary['history'] : []);
    $proofImage = trim((string)($stored['proofImage'] ?? ''));
    if ($proofImage === '') $proofImage = trim((string)($summary['proofImage'] ?? ''));
    if ($proofImage === '') $proofImage = trim((string)($customer['paymentProofImage'] ?? ''));
    $oldPaymentTotal = array_key_exists('orderTotal', $payment) ? (float)$payment['orderTotal'] : $oldInquiryTotal;
    $totalChanged = abs($newTotal - $oldInquiryTotal) > 0.00001 || abs($newTotal - $oldPaymentTotal) > 0.00001;
    $reviewStatus = trim((string)($payment['reviewStatus'] ?? ''));
    $hasPayment = $paid > 0 || $deposit > 0 || $proofImage !== '' || count($history) > 0;
    $hasReview = $reviewStatus !== ''
        || trim((string)($payment['reviewSubmittedAt'] ?? '')) !== ''
        || trim((string)($payment['reviewedAt'] ?? '')) !== ''
        || trim((string)($payment['reviewedBy'] ?? '')) !== '';

    $payment['orderId'] = $inquiryId;
    $payment['orderType'] = $orderType;
    $payment['orderTotal'] = $newTotal;
    $payment['depositAmount'] = $deposit;
    $payment['paidAmount'] = $paid;
    $payment['balance'] = $balance;
    $payment['receivableBalance'] = 0;
    $payment['unshippedBalance'] = $balance;
    $payment['status'] = $paid <= 0 ? 'unpaid' : ($balance > 0 ? 'partial' : 'paid');
    $payment['customerName'] = trim((string)($customer['name'] ?? '')) ?: trim((string)($payment['customerName'] ?? ''));
    $payment['customerPhone'] = trim((string)($customer['phone'] ?? '')) ?: trim((string)($payment['customerPhone'] ?? ''));
    $payment['salesName'] = trim((string)($inquiry['salesName'] ?? '')) ?: trim((string)($login['name'] ?? ($payment['salesName'] ?? '')));
    $payment['proofImage'] = $proofImage;
    $payment['history'] = $history;
    $payment['updatedAt'] = $now;

    if ($totalChanged && ($hasPayment || $hasReview)) {
        $payment['reviewStatus'] = 'pending';
        $payment['reviewSubmittedAt'] = $now;
        $payment['reviewSubmittedBy'] = trim((string)($login['name'] ?? $login['account'] ?? ''));
        $payment['reviewRetentionUntil'] = date(DATE_ATOM, time() + (7 * 86400));
        $payment['reviewedAt'] = '';
        $payment['reviewedBy'] = '';
        $payment['reviewNote'] = '';
    }

    $paymentRows[$inquiryId] = $payment;
    $inquiry['paymentSummary'] = $payment;
    return $payment;
}

function is_pending_inquiry_status($status): bool {
    return in_array((string)$status, ['pending_approval', 'reserved', 'preorder_pending'], true);
}

function is_taiwan_sku(array $sku): bool {
    $found = false;
    foreach (['warehouseCode', 'warehouse', 'warehouseName'] as $field) {
        $warehouse = strtolower(trim((string)($sku[$field] ?? '')));
        if ($warehouse === '') continue;
        if (preg_match('/preorder|預購|预购/u', $warehouse)) return false;
        if ($warehouse === 'cn' || preg_match('/中國|中国|大陸|大陆|china|東莞|东莞/u', $warehouse)) return false;
        if ($warehouse === 'id' || preg_match('/印尼|indonesia|indo/u', $warehouse)) return false;
        if (!($warehouse === 'tw' || preg_match('/台灣|台湾|taiwan|寶輝|宝辉/u', $warehouse))) return false;
        $found = true;
    }
    return $found;
}

function adjust_stock(array $items, int $direction, string $skusFile, string $stateFile, bool $commit = true): void {
    // skus.json is rewritten as a whole file.  Use the same inventory lock as the
    // preorder receive/convert transaction helpers so an ordinary reserve/cancel
    // cannot overwrite a concurrently committed preorder quantity and marker.
    $inventoryLock = acquire_inventory_file_lock($skusFile);
    try {
    $delta = [];
    foreach ($items as $item) {
        $skuId = (string)($item['skuId'] ?? '');
        $qty = max(0, (int)($item['qty'] ?? 0));
        if ($skuId !== '' && $qty > 0) $delta[$skuId] = ($delta[$skuId] ?? 0) + $qty;
    }
    if (!$delta) return;
    $skus = read_json($skusFile);
    if ($direction < 0) {
        foreach ($delta as $skuId => $qty) {
            $found = false;
            foreach ($skus as $sku) {
                if ((string)($sku['id'] ?? '') === $skuId) {
                    $found = true;
                    if ((int)($sku['stock'] ?? 0) < $qty) respond(['ok' => false, 'error' => 'Stok tidak cukup: ' . $skuId], 409);
                    break;
                }
            }
            if (!$found) respond(['ok' => false, 'error' => 'Stok produk tidak ditemukan: ' . $skuId], 400);
        }
    }
    if (!$commit) return;
    foreach ($skus as &$sku) {
        $id = (string)($sku['id'] ?? '');
        if ($id !== '' && isset($delta[$id])) {
            $sku['stock'] = max(0, (int)($sku['stock'] ?? 0) + ($direction * $delta[$id]));
        }
    }
    unset($sku);
    write_json($skusFile, $skus);

    $state = read_json($stateFile);
    if (isset($state['skus']) && is_array($state['skus'])) {
        foreach ($state['skus'] as &$sku) {
            $id = (string)($sku['id'] ?? '');
            if ($id !== '' && isset($delta[$id])) {
                $sku['stock'] = max(0, (int)($sku['stock'] ?? 0) + ($direction * $delta[$id]));
            }
        }
        unset($sku);
        $state['updatedAt'] = date(DATE_ATOM);
        write_json($stateFile, $state);
    }
    @unlink($GLOBALS['dataDir'] . DIRECTORY_SEPARATOR . 'public-catalog.json');
    } finally {
        if (is_resource($inventoryLock)) {
            @flock($inventoryLock, LOCK_UN);
            @fclose($inventoryLock);
        }
    }
}

function priority_identity_key($value): string {
    $value = strtoupper(trim((string)$value));
    $value = preg_replace('/[^A-Z0-9]+/', '', $value) ?? '';
    return preg_replace('/P\d+$/', '', $value) ?? $value;
}

function priority_item_identity_keys(array $item): array {
    return array_values(array_filter(array_map('priority_identity_key', [
        $item['productId'] ?? '', $item['code'] ?? '', $item['productCode'] ?? '',
        $item['skuId'] ?? '', $item['sku'] ?? '', $item['barcode'] ?? '',
        $item['sampleBarcode'] ?? '', $item['taiwanBarcode'] ?? ''
    ])));
}

function priority_items_match_variant(array $left, array $right): bool {
    $leftIds = priority_item_identity_keys($left);
    $rightIds = priority_item_identity_keys($right);
    if (!$leftIds || !$rightIds || !array_intersect($leftIds, $rightIds)) return false;
    $leftColor = mb_strtoupper(trim((string)($left['color'] ?? $left['colorName'] ?? '')), 'UTF-8');
    $rightColor = mb_strtoupper(trim((string)($right['color'] ?? $right['colorName'] ?? '')), 'UTF-8');
    $leftSize = mb_strtoupper(trim((string)($left['size'] ?? $left['sizeName'] ?? 'NO SIZE')), 'UTF-8');
    $rightSize = mb_strtoupper(trim((string)($right['size'] ?? $right['sizeName'] ?? 'NO SIZE')), 'UTF-8');
    if ($leftColor !== '' && $rightColor !== '' && $leftColor !== $rightColor) return false;
    if ($leftSize !== '' && $rightSize !== '' && $leftSize !== $rightSize) return false;
    return true;
}

function priority_credit_tw_received_on_order(
    array &$orders,
    int $orderIndex,
    int $preferredItemIndex,
    array $identityItem,
    int $moveQty,
    string $now,
    string $reason,
    string $operatorName
): array {
    $items = is_array($orders[$orderIndex]['items'] ?? null) ? array_values($orders[$orderIndex]['items']) : [];
    $matchIndex = -1;
    if ($preferredItemIndex >= 0 && isset($items[$preferredItemIndex]) && is_array($items[$preferredItemIndex])) {
        if (priority_items_match_variant($items[$preferredItemIndex], $identityItem) || $identityItem === $items[$preferredItemIndex]) {
            $matchIndex = $preferredItemIndex;
        }
    }
    if ($matchIndex < 0) {
        foreach ($items as $index => $item) {
            if (!is_array($item) || !priority_items_match_variant($item, $identityItem)) continue;
            $requested = max(1, (int)($item['qty'] ?? $item['quantity'] ?? $item['requestedQty'] ?? 1));
            $received = max(0, (int)($item['freightReceivedQty'] ?? 0));
            if ($received >= $requested) continue;
            $matchIndex = (int)$index;
            break;
        }
    }
    if ($matchIndex < 0 || !isset($items[$matchIndex]) || !is_array($items[$matchIndex])) {
        return ['creditedQty' => 0, 'itemIndex' => -1, 'item' => []];
    }
    $item = $items[$matchIndex];
    $requested = max(1, (int)($item['qty'] ?? $item['quantity'] ?? $item['requestedQty'] ?? 1));
    $received = max(0, (int)($item['freightReceivedQty'] ?? 0));
    $take = min($moveQty, max(0, $requested - $received));
    if ($take <= 0) return ['creditedQty' => 0, 'itemIndex' => $matchIndex, 'item' => $item];
    $item['freightReceivedQty'] = $received + $take;
    $item['allocationSourceWarehouse'] = 'TW';
    $item['freightReceivedWarehouse'] = 'TW';
    $item['manualPriorityAllocation'] = true;
    $item['priorityAllocationStatus'] = 'allocated';
    $item['manualQueuePriorityAt'] = $now;
    $item['manualQueuePriorityBy'] = $operatorName;
    $item['manualQueuePriorityReason'] = $reason;
    $items[$matchIndex] = $item;
    $orders[$orderIndex]['items'] = $items;
    $orders[$orderIndex]['updatedAt'] = $now;
    $orders[$orderIndex]['priorityStockAllocatedAt'] = $now;
    $orders[$orderIndex]['priorityStockAllocatedBy'] = $operatorName;
    $orders[$orderIndex]['priorityStockAllocatedReason'] = $reason;
    return ['creditedQty' => $take, 'itemIndex' => $matchIndex, 'item' => $item];
}

function preorder_inventory_operation_id(string $kind, string $inquiryId): string {
    return 'preorder-' . $kind . ':' . $inquiryId;
}

function acquire_preorder_inventory_lock(string $inquiriesFile, string $inquiryId) {
    $lockFile = $inquiriesFile . '.preorder-operation-' . hash('sha256', $inquiryId) . '.lock';
    $handle = fopen($lockFile, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        respond(['ok' => false, 'error' => '目前無法鎖定這張預購單，請稍後再試'], 503);
    }
    return $handle;
}

function acquire_inventory_file_lock(string $skusFile) {
    $handle = fopen($skusFile . '.inventory.lock', 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        respond(['ok' => false, 'error' => '目前無法鎖定庫存資料，請稍後再試'], 503);
    }
    return $handle;
}

function acquire_purchase_receipts_file_lock(string $receiptsFile) {
    $handle = fopen($receiptsFile . '.file.lock', 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        respond(['ok' => false, 'error' => '目前無法鎖定採購進貨單資料，請稍後再試'], 503);
    }
    return $handle;
}

function acquire_purchase_receipt_lock(string $receiptsFile, string $documentId) {
    $identity = mb_strtolower(trim($documentId), 'UTF-8');
    $lockFile = $receiptsFile . '.operation-' . hash('sha256', $identity) . '.lock';
    $handle = fopen($lockFile, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        respond(['ok' => false, 'error' => '目前無法鎖定這張採購進貨單，請稍後再試'], 503);
    }
    return $handle;
}

function acquire_freight_file_lock(string $freightFile, bool $fatal = true) {
    // Use the same lock file as freight-tracking-api.php so receiving and
    // customer-order matching cannot read while the freight file is replaced.
    $handle = fopen($freightFile . '.lock', 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) fclose($handle);
        if ($fatal) respond(['ok' => false, 'error' => '目前無法鎖定物流集運資料，請稍後再試'], 503);
        return false;
    }
    return $handle;
}

function release_preorder_freight_links(string $freightFile, array $inquiryIds, bool $fatal = true): int {
    $idMap = array_fill_keys(array_values(array_filter(array_map('strval', $inquiryIds))), true);
    if (!$idMap) return 0;
    $lock = acquire_freight_file_lock($freightFile, $fatal);
    if ($lock === false) return 0;
    try {
        $data = read_json($freightFile);
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $released = 0;
        $changed = false;
        foreach ($items as $index => $item) {
            if (!is_array($item)) continue;
            $links = is_array($item['customerLinks'] ?? null) ? $item['customerLinks'] : [];
            $kept = [];
            foreach ($links as $link) {
                $linkedId = is_array($link) ? (string)($link['inquiryId'] ?? $link['customerOrderNo'] ?? '') : '';
                if ($linkedId !== '' && isset($idMap[$linkedId])) {
                    $released += max(0, (int)($link['qty'] ?? 0));
                    $changed = true;
                    continue;
                }
                $kept[] = $link;
            }
            if (count($kept) === count($links)) continue;
            $items[$index]['customerLinks'] = array_values($kept);
            $items[$index]['purchaseOffsetQty'] = array_reduce($kept, static fn($sum, $link) => $sum + max(0, (int)($link['qty'] ?? 0)), 0);
            $items[$index]['inventoryOwnership'] = $kept ? 'customer_allocated' : 'company_stock';
            $items[$index]['updatedAt'] = date(DATE_ATOM);
            $changed = true;
        }
        if ($changed) {
            $data['items'] = $items;
            $data['revision'] = max(0, (int)($data['revision'] ?? 0)) + 1;
            $data['updatedAt'] = date(DATE_ATOM);
            write_json($freightFile, $data);
        }
        return $released;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function release_preorder_freight_item_links(string $freightFile, string $inquiryId, int $itemIndex): int {
    if ($inquiryId === '' || $itemIndex < 0) return 0;
    $lock = acquire_freight_file_lock($freightFile);
    try {
        $data = read_json($freightFile);
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $released = 0;
        $changed = false;
        foreach ($items as $index => $item) {
            if (!is_array($item)) continue;
            $links = is_array($item['customerLinks'] ?? null) ? $item['customerLinks'] : [];
            $kept = [];
            $itemChanged = false;
            foreach ($links as $link) {
                if (!is_array($link)) {
                    $kept[] = $link;
                    continue;
                }
                $linkedId = (string)($link['inquiryId'] ?? $link['customerOrderNo'] ?? '');
                $linkedItemIndex = (int)($link['itemIndex'] ?? -1);
                if ($linkedId === $inquiryId && $linkedItemIndex === $itemIndex) {
                    $released += max(0, (int)($link['qty'] ?? 0));
                    $itemChanged = true;
                    $changed = true;
                    continue;
                }
                if ($linkedId === $inquiryId && $linkedItemIndex > $itemIndex) {
                    $link['itemIndex'] = $linkedItemIndex - 1;
                    $itemChanged = true;
                    $changed = true;
                }
                $kept[] = $link;
            }
            if (!$itemChanged) continue;
            $items[$index]['customerLinks'] = array_values($kept);
            $items[$index]['purchaseOffsetQty'] = array_reduce($kept, static fn($sum, $link) => $sum + (is_array($link) ? max(0, (int)($link['qty'] ?? 0)) : 0), 0);
            $items[$index]['inventoryOwnership'] = $kept ? 'customer_allocated' : 'company_stock';
            $items[$index]['updatedAt'] = date(DATE_ATOM);
        }
        if ($changed) {
            $data['items'] = $items;
            $data['revision'] = max(0, (int)($data['revision'] ?? 0)) + 1;
            $data['updatedAt'] = date(DATE_ATOM);
            write_json($freightFile, $data);
        }
        return $released;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function preorder_operation_payload_hash(array $payload): string {
    $normalize = static function ($value) use (&$normalize) {
        if (!is_array($value)) return $value;
        $isList = $value === [] || array_keys($value) === range(0, count($value) - 1);
        if ($isList) return array_map($normalize, $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $normalize($item);
        return $value;
    };
    return hash('sha256', json_encode($normalize($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function inventory_transaction_marker(array $row, string $operationId): ?array {
    $markers = isset($row['inventoryTransactionMarkers']) && is_array($row['inventoryTransactionMarkers'])
        ? $row['inventoryTransactionMarkers']
        : [];
    if (isset($markers[$operationId]) && is_array($markers[$operationId])) return $markers[$operationId];
    foreach ($markers as $marker) {
        if (is_array($marker) && (string)($marker['operationId'] ?? '') === $operationId) return $marker;
    }
    return null;
}

function set_inventory_transaction_marker(array &$row, string $operationId, array $marker): void {
    if (!isset($row['inventoryTransactionMarkers']) || !is_array($row['inventoryTransactionMarkers'])) {
        $row['inventoryTransactionMarkers'] = [];
    }
    $marker['operationId'] = $operationId;
    $row['inventoryTransactionMarkers'][$operationId] = $marker;
}

function inventory_transaction_marker_entries(array $skus, string $operationId): array {
    $entries = [];
    foreach ($skus as $sku) {
        if (!is_array($sku)) continue;
        $marker = inventory_transaction_marker($sku, $operationId);
        if (!$marker) continue;
        $lines = isset($marker['lines']) && is_array($marker['lines']) ? $marker['lines'] : [];
        foreach ($lines as $line) if (is_array($line)) $entries[] = $line;
    }
    usort($entries, static fn($a, $b) => ((int)($a['itemIndex'] ?? 0)) <=> ((int)($b['itemIndex'] ?? 0)));
    return $entries;
}

function sync_preorder_inventory_state(string $stateFile, array $skus, ?array $products, string $operationId, array $marker): void {
    // admin-state.json is a 30MB+ browser snapshot. Rewriting it (and walking
    // every color field) after 轉倉／正式入庫 makes IIS return HTTP 500 while
    // skus.json already committed. Markers live on the SKU row; keep a small
    // sidecar so retries can finish freight status without touching the snapshot.
    unset($skus, $products);
    $markerFile = dirname($stateFile) . DIRECTORY_SEPARATOR . 'inventory-transaction-markers.json';
    $markers = read_json($markerFile);
    if (!isset($markers['markers']) || !is_array($markers['markers'])) {
        $existing = $markers;
        $markers = ['markers' => is_array($existing) && !array_is_list($existing) ? $existing : []];
    }
    $marker['operationId'] = $operationId;
    $marker['updatedAt'] = date(DATE_ATOM);
    $markers['markers'][$operationId] = $marker;
    $markers['updatedAt'] = $marker['updatedAt'];
    write_json($markerFile, $markers);
}

function adjust_stock_once_for_preorder_operation(array $items, int $direction, string $skusFile, string $stateFile, string $operationId, string $inquiryId, string $payloadHash, array $operationContext = []): array {
    $inventoryLock = acquire_inventory_file_lock($skusFile);
    $skus = read_json($skusFile);
    $existingEntries = inventory_transaction_marker_entries($skus, $operationId);
    $markerMeta = [
        'kind' => $direction < 0 ? 'preorder_convert_out' : 'preorder_inventory_in',
        'inquiryId' => $inquiryId,
        'appliedAt' => date(DATE_ATOM),
    ];
    if ($existingEntries) {
        foreach ($existingEntries as $entry) {
            $existingHash = (string)($entry['payloadHash'] ?? '');
            if ($existingHash !== '' && !hash_equals($existingHash, $payloadHash)) {
                respond(['ok' => false, 'error' => '這張預購單已有不同內容的庫存轉換紀錄，已停止重複扣庫存'], 409);
            }
        }
        $markerMeta['appliedAt'] = (string)($existingEntries[0]['appliedAt'] ?? $markerMeta['appliedAt']);
        sync_preorder_inventory_state($stateFile, $skus, null, $operationId, $markerMeta + ['payloadHash' => $payloadHash, 'recoveredAt' => date(DATE_ATOM)]);
        return ['applied' => false, 'operationId' => $operationId, 'lines' => $existingEntries];
    }

    $delta = [];
    $lineIndexes = [];
    foreach ($items as $itemIndex => $item) {
        if (!is_array($item)) continue;
        $skuId = (string)($item['skuId'] ?? '');
        $qty = max(0, (int)($item['qty'] ?? 0));
        if ($skuId === '' || $qty <= 0) continue;
        $delta[$skuId] = ($delta[$skuId] ?? 0) + $qty;
        $lineIndexes[$skuId][] = ['itemIndex' => $itemIndex, 'qty' => $qty];
    }
    if (!$delta) return ['applied' => false, 'operationId' => $operationId, 'lines' => []];

    if ($direction < 0) {
        foreach ($delta as $skuId => $qty) {
            $found = false;
            foreach ($skus as $sku) {
                if (!is_array($sku) || (string)($sku['id'] ?? '') !== $skuId) continue;
                $found = true;
                if ((int)($sku['stock'] ?? 0) < $qty) respond(['ok' => false, 'error' => 'Stok tidak cukup: ' . $skuId], 409);
                break;
            }
            if (!$found) respond(['ok' => false, 'error' => 'Stok produk tidak ditemukan: ' . $skuId], 400);
        }
    }

    $appliedAt = date(DATE_ATOM);
    $allLines = [];
    foreach ($skus as &$sku) {
        if (!is_array($sku)) continue;
        $skuId = (string)($sku['id'] ?? '');
        if ($skuId === '' || !isset($delta[$skuId])) continue;
        $qtyDelta = $direction * $delta[$skuId];
        $sku['stock'] = max(0, (int)($sku['stock'] ?? 0) + $qtyDelta);
        $lines = array_map(static function ($line) use ($skuId, $direction, $appliedAt, $payloadHash, $operationContext): array {
            return [
                'itemIndex' => (int)$line['itemIndex'],
                'skuId' => $skuId,
                'qty' => (int)$line['qty'],
                'qtyDelta' => $direction * (int)$line['qty'],
                'appliedAt' => $appliedAt,
                'payloadHash' => $payloadHash,
                'operationContext' => $operationContext,
            ];
        }, $lineIndexes[$skuId] ?? []);
        $allLines = array_merge($allLines, $lines);
        set_inventory_transaction_marker($sku, $operationId, [
            'kind' => $direction < 0 ? 'preorder_convert_out' : 'preorder_inventory_in',
            'inquiryId' => $inquiryId,
            'payloadHash' => $payloadHash,
            'operationContext' => $operationContext,
            'qtyDelta' => $qtyDelta,
            'appliedAt' => $appliedAt,
            'lines' => $lines,
        ]);
        $sku['updatedAt'] = $appliedAt;
    }
    unset($sku);

    // Quantity and its marker are committed together in the same canonical JSON file.
    write_json($skusFile, $skus);
    sync_preorder_inventory_state($stateFile, $skus, null, $operationId, $markerMeta + [
        'appliedAt' => $appliedAt,
        'payloadHash' => $payloadHash,
        'skuIds' => array_keys($delta),
    ]);
    @unlink($GLOBALS['dataDir'] . DIRECTORY_SEPARATOR . 'public-catalog.json');
    return ['applied' => true, 'operationId' => $operationId, 'lines' => $allLines];
}

function receipt_text($value): string {
    return trim((string)($value ?? ''));
}

function receipt_first_text(array $values): string {
    foreach ($values as $value) {
        $candidate = receipt_text($value);
        if ($candidate !== '') return $candidate;
    }
    return '';
}

function lz_norm_tracking($value): string {
    return strtoupper((string)preg_replace('/[\s\x{200B}-\x{200D}\x{FEFF}]+/u', '', receipt_text($value)));
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'outbound-parcels.php';

function lz_order_is_cancelled(array $order): bool {
    if (receipt_text($order['cancelledAt'] ?? '') !== '') return true;
    $status = strtolower(receipt_text($order['status'] ?? ($order['internalStatus'] ?? '')));
    return in_array($status, ['cancelled', 'canceled', 'customer_cancelled'], true);
}

function lz_find_tracking_owner(array $orders, string $trackingNo, string $exceptOrderId = ''): ?array {
    $wanted = lz_norm_tracking($trackingNo);
    if ($wanted === '') return null;
    foreach ($orders as $order) {
        if (!is_array($order)) continue;
        $id = receipt_text($order['id'] ?? '');
        if ($id === '' || ($exceptOrderId !== '' && $id === $exceptOrderId)) continue;
        if (lz_order_is_cancelled($order)) continue;
        if (lz_order_uses_tracking($order, $wanted)) return $order;
    }
    return null;
}

// 本機249：正式出貨以最後收件地址的明確關鍵字重算運費。
// 地址比舊的配送下拉選項更接近實際出貨方式；沒有明確關鍵字時仍保留原值。
function order_shipping_rule_from_keywords(string $value): ?array {
    $text = trim($value);
    if ($text === '') return null;
    if (preg_match('/自取|面交|不收運費|不需物流|ambil\\s*sendiri|pickup/iu', $text)) {
        return ['deliveryType' => 'self', 'deliveryLabel' => '自取 / 不收運費', 'shippingCarrier' => '自取／不需物流', 'fee' => 0.0];
    }
    if (preg_match('/7\\s*-?\\s*11|711|全家|family(?:mart)?|超商|店到店|minimarket/iu', $text)) {
        $family = preg_match('/全家|family(?:mart)?/iu', $text) === 1;
        return ['deliveryType' => 'store', 'deliveryLabel' => '超商取貨 NT$70', 'shippingCarrier' => $family ? '全家' : '7-11', 'fee' => 70.0];
    }
    if (preg_match('/post|郵局|邮局/iu', $text)) {
        return ['deliveryType' => 'home', 'deliveryLabel' => '郵局寄送 NT$100', 'shippingCarrier' => 'POST 郵局', 'fee' => 100.0];
    }
    if (preg_match('/ktj|大榮|大荣/iu', $text)) {
        return ['deliveryType' => 'home', 'deliveryLabel' => '住家宅配 NT$100', 'shippingCarrier' => 'KTJ 大榮', 'fee' => 100.0];
    }
    if (preg_match('/hct|新竹/iu', $text)) {
        return ['deliveryType' => 'home', 'deliveryLabel' => '住家宅配 NT$100', 'shippingCarrier' => 'HCT 新竹', 'fee' => 100.0];
    }
    if (preg_match('/便利帶|便利带/iu', $text)) {
        return ['deliveryType' => 'home', 'deliveryLabel' => '住家宅配 NT$100', 'shippingCarrier' => '便利帶', 'fee' => 100.0];
    }
    if (preg_match('/宅配|住家|家裡|家里|alamat|address|kirim\\s*rumah|home/iu', $text)) {
        return ['deliveryType' => 'home', 'deliveryLabel' => '住家宅配 NT$100', 'shippingCarrier' => '住家宅配', 'fee' => 100.0];
    }
    return null;
}

function receipt_warehouse_profile(string $warehouse): array {
    $warehouse = strtoupper(trim($warehouse));
    if ($warehouse === 'ID' || strpos($warehouse, '印尼') !== false || strpos($warehouse, 'INDONESIA') !== false) return ['code' => 'ID', 'name' => '印尼倉'];
    if ($warehouse === 'CN' || strpos($warehouse, '中國') !== false || strpos($warehouse, 'CHINA') !== false) return ['code' => 'CN', 'name' => '中國倉'];
    return ['code' => 'TW', 'name' => '台灣倉'];
}

function receipt_new_product_record(string $productId, string $productCode, string $productName, string $category, string $color, string $warehouse, string $createdAt): array {
    $profile = receipt_warehouse_profile($warehouse);
    return [
        'id' => $productId,
        'code' => $productCode,
        'productLine' => $productCode,
        'title' => $productName !== '' ? $productName : $productCode,
        'brand' => '',
        'brandName' => '',
        'spec' => '',
        'specification' => '',
        'price' => 0,
        'cost' => 0,
        'mainImage' => '',
        'images' => [],
        'colors' => [['code' => '', 'name' => $color, 'image' => '']],
        'description' => '由採購進貨建檔建立；請到產品管理補齊售價、圖片與顏色色碼。',
        'category' => $category,
        'showOnWebsite' => $profile['code'] === 'TW',
        'storefrontManualHidden' => false,
        'warehouseName' => $profile['name'],
        'warehouse' => $profile['name'],
        'shelves' => [],
        'layers' => [],
        'shelf' => '',
        'layer' => '',
        'productMode' => 'ready',
        'preorderOnly' => false,
        'createdAt' => $createdAt,
        'updatedAt' => $createdAt,
    ];
}

function receipt_sku_is_warehouse(array $sku, array $profile): bool {
    $warehouseCode = strtoupper(receipt_text($sku['warehouseCode'] ?? ''));
    if ($warehouseCode !== '') {
        if ($profile['code'] === 'TW') return in_array($warehouseCode, ['TW', 'TAIWAN'], true)
            || strpos($warehouseCode, '台灣') !== false || strpos($warehouseCode, '台湾') !== false;
        if ($profile['code'] === 'CN') return in_array($warehouseCode, ['CN', 'CHINA'], true)
            || strpos($warehouseCode, '中國') !== false || strpos($warehouseCode, '中国') !== false;
        return in_array($warehouseCode, ['ID', 'INDONESIA'], true)
            || strpos($warehouseCode, '印尼') !== false;
    }
    $raw = strtoupper(receipt_first_text([$sku['warehouse'] ?? '', $sku['warehouseName'] ?? '']));
    if ($profile['code'] === 'TW') return $raw === '' || strpos($raw, 'TW') !== false || strpos($raw, '台灣') !== false || strpos($raw, 'TAIWAN') !== false;
    if ($profile['code'] === 'ID') return strpos($raw, '印尼') !== false || strpos($raw, 'INDONESIA') !== false || preg_match('/(^|\s)ID($|\s)/', $raw) === 1;
    return strpos($raw, '中國') !== false || strpos($raw, 'CHINA') !== false || preg_match('/(^|\s)CN($|\s)/', $raw) === 1;
}

function receipt_same_variant(array $sku, string $productId, string $color, string $size): bool {
    if ((string)($sku['productId'] ?? '') !== $productId) return false;
    $skuColor = receipt_color_match_key(receipt_first_text([$sku['colorName'] ?? '', $sku['color'] ?? '']));
    $wantedColor = receipt_color_match_key($color);
    // FREE／均碼／無尺寸／未帶尺寸／NO SIZE all describe the same
    // sellable variant.  Older catalog rows use several of these labels, so
    // comparing their raw text incorrectly reports the existing barcode as a
    // different specification and blocks an otherwise valid receipt.
    $skuSize = freight_receive_variant_token(receipt_first_text([$sku['sizeName'] ?? '', $sku['size'] ?? '', 'NO SIZE']), true);
    $wantedSize = freight_receive_variant_token($size !== '' ? $size : 'NO SIZE', true);
    return $skuColor !== '' && $wantedColor !== '' && $skuColor === $wantedColor && $skuSize === $wantedSize;
}

function receipt_sku_barcode_text(array $sku): string {
    return receipt_first_text([
        $sku['companyBarcode'] ?? '', $sku['barcode'] ?? '', $sku['officialBarcode'] ?? '',
        $sku['legacyBarcode'] ?? '', $sku['mappingCode'] ?? '',
    ]);
}

function receipt_color_field_text($row): string {
    if (is_string($row) || is_numeric($row)) return trim((string)$row);
    if (!is_array($row)) return '';
    return receipt_first_text([$row['name'] ?? '', $row['colorName'] ?? '', $row['color'] ?? '']);
}

function receipt_colors_are_same_family($a, $b): bool {
    $ka = receipt_color_match_key(trim((string)$a));
    $kb = receipt_color_match_key(trim((string)$b));
    return $ka !== '' && $kb !== '' && $ka === $kb;
}

function receipt_find_receipt_target_sku_index(array $skus, array $profile, string $productId, string $color, string $size, string $barcode): int {
    foreach ($skus as $skuIndex => $sku) {
        if (!is_array($sku) || !receipt_sku_is_warehouse($sku, $profile)) continue;
        if (receipt_same_variant($sku, $productId, $color, $size)) return (int)$skuIndex;
    }
    /* LZ_SPEC_COLOR_MERGE_20260925: 同一公司條碼 + 同一款 + 同一尺碼必須 UPDATE 既有 SKU。
       409 迴圈的 same-size skip 不能再長出第二個 sku.id / 第二個 companyBarcode。 */
    $wantedSize = freight_receive_variant_token($size !== '' ? $size : 'NO SIZE', true);
    $wantedBarcode = strtoupper(trim($barcode));
    if ($productId === '' || $wantedBarcode === '') return -1;
    foreach ($skus as $skuIndex => $sku) {
        if (!is_array($sku) || !receipt_sku_is_warehouse($sku, $profile)) continue;
        if (receipt_text($sku['productId'] ?? '') !== $productId) continue;
        $skuSize = freight_receive_variant_token(receipt_first_text([$sku['sizeName'] ?? '', $sku['size'] ?? '', 'NO SIZE']), true);
        if ($skuSize !== $wantedSize) continue;
        $candidateBarcode = strtoupper(receipt_sku_barcode_text($sku));
        if ($candidateBarcode !== '' && $candidateBarcode === $wantedBarcode) return (int)$skuIndex;
        foreach (array_merge(
            is_array($sku['barcodeAliases'] ?? null) ? $sku['barcodeAliases'] : [],
            is_array($sku['linkedBarcodes'] ?? null) ? $sku['linkedBarcodes'] : []
        ) as $alias) {
            if (strtoupper(trim((string)$alias)) === $wantedBarcode) return (int)$skuIndex;
        }
    }
    return -1;
}

function receipt_merge_product_color_entry(array &$product, string $receivedColor, string $receivedImage = ''): bool {
    $canonical = canonical_bilingual_color_name($receivedColor);
    if ($canonical === '') $canonical = trim($receivedColor);
    if ($canonical === '') return false;
    if (!isset($product['colors']) || !is_array($product['colors'])) $product['colors'] = [];
    $changed = false;
    $matchedIndex = -1;
    foreach ($product['colors'] as $colorIndex => $colorRow) {
        if (!is_array($colorRow)) continue;
        $existing = receipt_color_field_text($colorRow);
        if ($existing === '' || !receipt_colors_are_same_family($existing, $canonical)) continue;
        $matchedIndex = (int)$colorIndex;
        $product['colors'][$colorIndex]['name'] = $canonical;
        if (array_key_exists('colorName', $colorRow) || $existing !== '') $product['colors'][$colorIndex]['colorName'] = $canonical;
        if (array_key_exists('color', $colorRow) || $existing !== '') $product['colors'][$colorIndex]['color'] = $canonical;
        if ($receivedImage !== '') $product['colors'][$colorIndex]['image'] = $receivedImage;
        if ($product['colors'][$colorIndex] !== $colorRow) $changed = true;
        break;
    }
    if ($matchedIndex < 0) {
        $product['colors'][] = ['code' => '', 'name' => $canonical, 'image' => $receivedImage];
        return true;
    }
    $filtered = [];
    foreach ($product['colors'] as $colorIndex => $colorRow) {
        if ((int)$colorIndex !== $matchedIndex && is_array($colorRow) && receipt_colors_are_same_family(receipt_color_field_text($colorRow), $canonical)) {
            if ($receivedImage === '' && receipt_text($product['colors'][$matchedIndex]['image'] ?? '') === '' && receipt_text($colorRow['image'] ?? '') !== '') {
                $product['colors'][$matchedIndex]['image'] = $colorRow['image'];
            }
            $changed = true;
            continue;
        }
        $filtered[] = $colorRow;
    }
    if (count($filtered) !== count($product['colors'])) {
        $product['colors'] = array_values($filtered);
        $changed = true;
    }
    return $changed;
}

function receipt_color_images_from_product(array $product): array {
    $out = [];
    foreach (($product['colors'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $img = receipt_text($row['image'] ?? $row['imageUrl'] ?? $row['photo'] ?? '');
        if ($img !== '' && !in_array($img, $out, true)) $out[] = $img;
    }
    return $out;
}

function receipt_apply_inbound_replacement_images(array &$product, string $receivedColor, string $frontImage, array $altImages): bool {
    /* LZ_REPLACE_IMGS_20260926: 前＝顏色圖／主圖；後左右＝替代更換圖，蓋掉舊的其他照片。 */
    $changed = false;
    if ($receivedColor !== '') {
        if (receipt_merge_product_color_entry($product, $receivedColor, $frontImage)) $changed = true;
    }
    $colorImages = receipt_color_images_from_product($product);
    $firstColor = $colorImages[0] ?? '';
    if ($frontImage !== '') {
        $product['mainImage'] = $frontImage;
        $changed = true;
        if (isset($product['colorImages']) && is_array($product['colorImages']) && $receivedColor !== '') {
            $product['colorImages'][$receivedColor] = $frontImage;
            $canon = canonical_bilingual_color_name($receivedColor);
            if ($canon !== '' && $canon !== $receivedColor) $product['colorImages'][$canon] = $frontImage;
        }
    } elseif ($firstColor !== '' && receipt_text($product['mainImage'] ?? '') === '') {
        $product['mainImage'] = $firstColor;
        $changed = true;
    }
    $main = receipt_text($product['mainImage'] ?? '');
    $keep = [];
    foreach (array_merge([$main], $colorImages) as $img) {
        $img = receipt_text($img);
        if ($img !== '' && !in_array($img, $keep, true)) $keep[] = $img;
    }
    $alts = [];
    foreach ($altImages as $img) {
        $img = receipt_text($img);
        if ($img === '' || in_array($img, $keep, true) || in_array($img, $alts, true)) continue;
        $alts[] = $img;
    }
    if ($alts) {
        $product['images'] = array_slice(array_merge($keep, $alts), 0, 30);
        $changed = true;
    } elseif ($frontImage !== '') {
        $images = isset($product['images']) && is_array($product['images']) ? array_values($product['images']) : [];
        $next = $keep;
        foreach ($images as $img) {
            $img = receipt_text($img);
            if ($img === '' || in_array($img, $next, true)) continue;
            $next[] = $img;
        }
        $product['images'] = array_slice($next, 0, 30);
        $changed = true;
    }
    return $changed;
}

function receipt_line_ref(int $index, string $productCode, string $barcode): string {
    $label = '第' . ($index + 1) . '項';
    if ($productCode !== '') $label .= ' 貨號 ' . $productCode;
    if ($barcode !== '') $label .= ' 條碼 ' . $barcode;
    return $label;
}

function receipt_normalize_product_code($value): string {
    return strtoupper((string)preg_replace('/[^A-Z0-9]/i', '', (string)($value ?? '')));
}

function receipt_is_placeholder_inbound_color(string $color): bool {
    $raw = trim($color);
    if ($raw === '') return true;
    $key = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
    $key = preg_replace('/\s+/u', '', $key) ?? $key;
    if (in_array($key, ['未填顏色', '未選顏色', '未選擇顏色', '請選擇顏色', '未填', '-', 'nocolor', 'none'], true)) return true;
    return preg_match('/^(未填|未選)/u', $key) === 1;
}

function receipt_find_product_by_id(array $products, string $productId): ?array {
    $productId = receipt_text($productId);
    if ($productId === '' || stripos($productId, 'freight-') === 0) return null;
    foreach ($products as $candidate) {
        if (is_array($candidate) && receipt_text($candidate['id'] ?? '') === $productId) return $candidate;
    }
    return null;
}

function receipt_find_product_by_code(array $products, string $productCode): ?array {
    $normalized = receipt_normalize_product_code($productCode);
    if ($normalized === '') return null;
    $fallback = null;
    foreach ($products as $candidate) {
        if (!is_array($candidate)) continue;
        $candidateCode = receipt_normalize_product_code(receipt_first_text([$candidate['code'] ?? '', $candidate['productLine'] ?? '']));
        if ($candidateCode === $normalized) return $candidate;
        $idCode = receipt_normalize_product_code((string)preg_replace('/^p-/i', '', receipt_text($candidate['id'] ?? '')));
        if ($idCode === $normalized && $fallback === null) $fallback = $candidate;
    }
    return $fallback;
}

function receipt_sku_barcode_haystack(array $sku): array {
    $values = [
        $sku['companyBarcode'] ?? '', $sku['barcode'] ?? '', $sku['officialBarcode'] ?? '',
        $sku['legacyBarcode'] ?? '', $sku['mappingCode'] ?? '', $sku['id'] ?? '', $sku['sku'] ?? '',
    ];
    if (is_array($sku['barcodeAliases'] ?? null)) $values = array_merge($values, $sku['barcodeAliases']);
    if (is_array($sku['linkedBarcodes'] ?? null)) $values = array_merge($values, $sku['linkedBarcodes']);
    $out = [];
    foreach ($values as $value) {
        $text = strtoupper(trim((string)$value));
        if ($text !== '') $out[] = $text;
    }
    return array_values(array_unique($out));
}

function receipt_find_product_by_barcode(array $products, array $skus, string $barcode): ?array {
    $wanted = strtoupper(trim($barcode));
    if ($wanted === '') return null;
    $wantedBase = (string)(preg_replace('/P\d+$/u', '', $wanted) ?? $wanted);
    foreach ($skus as $sku) {
        if (!is_array($sku)) continue;
        $hit = false;
        foreach (receipt_sku_barcode_haystack($sku) as $saved) {
            if ($saved === $wanted) { $hit = true; break; }
            $savedBase = (string)(preg_replace('/P\d+$/u', '', $saved) ?? $saved);
            if ($savedBase !== '' && $wantedBase !== '' && $savedBase === $wantedBase) { $hit = true; break; }
        }
        if (!$hit) continue;
        $found = receipt_find_product_by_id($products, receipt_text($sku['productId'] ?? ''));
        if (is_array($found)) return $found;
    }
    $best = null;
    $bestLen = 0;
    foreach ($products as $candidate) {
        if (!is_array($candidate)) continue;
        $code = receipt_normalize_product_code(receipt_first_text([$candidate['code'] ?? '', $candidate['productLine'] ?? '']));
        if ($code === '' || strlen($code) < 4) continue;
        if (strpos($wanted, $code) === 0 && strlen($code) > $bestLen) {
            $best = $candidate;
            $bestLen = strlen($code);
        }
    }
    return $best;
}

function receipt_resolve_master_product(array $products, array $skus, string $productId, string $productCode, string $barcode, string $skuId = ''): ?array {
    /* LZ_RECV_RESOLVE_20260925: 目錄改號後草稿 productId 可能過期（OLAN73 仍帶 p-olan72）。
       用貨號／公司條碼／別名回找既有產品，不要整張 400、也不要憑空新建。 */
    $found = receipt_find_product_by_id($products, $productId);
    if (is_array($found)) return $found;
    $found = receipt_find_product_by_code($products, $productCode);
    if (is_array($found)) return $found;
    if ($skuId !== '') {
        foreach ($skus as $sku) {
            if (!is_array($sku)) continue;
            if (receipt_first_text([$sku['id'] ?? '', $sku['sku'] ?? '']) !== $skuId) continue;
            $found = receipt_find_product_by_id($products, receipt_text($sku['productId'] ?? ''));
            if (is_array($found)) return $found;
            $found = receipt_find_product_by_code($products, receipt_first_text([$sku['productCode'] ?? '', $sku['code'] ?? '']));
            if (is_array($found)) return $found;
            break;
        }
    }
    return receipt_find_product_by_barcode($products, $skus, $barcode);
}

function receipt_unique_sku_id(array $skus, string $base): string {
    $base = strtoupper(preg_replace('/[^A-Z0-9_-]+/i', '-', trim($base)) ?? '');
    $base = trim($base, '-_');
    if ($base === '') $base = 'SKU-' . date('YmdHis');
    $ids = [];
    foreach ($skus as $sku) if (is_array($sku)) $ids[strtoupper(receipt_first_text([$sku['id'] ?? '', $sku['sku'] ?? '']))] = true;
    if (!isset($ids[$base])) return $base;
    $suffix = 2;
    while (isset($ids[$base . '-' . $suffix])) $suffix++;
    return $base . '-' . $suffix;
}

function receipt_barcode_initial_cost($value): ?float {
    $barcode = strtoupper(trim((string)$value));
    if ($barcode === '') return null;
    // V2 identity: PRODUCT + P first cost + C colour + S size.
    if (preg_match('/P(\d+(?:\.\d+)?)C[A-Z0-9]+S[A-Z0-9]+$/', $barcode, $match) === 1) {
        $cost = (float)$match[1];
        return is_finite($cost) && $cost > 0 ? $cost : null;
    }
    // Legacy mashed identity: PRODUCT + P cost + colour[+size], e.g. CK95BIGP50091.
    if (preg_match('/P(\d+)9\d/', $barcode, $match) === 1) {
        $cost = (float)$match[1];
        return is_finite($cost) && $cost > 0 ? $cost : null;
    }
    return null;
}

function receipt_is_v2_label_barcode($value): bool {
    $barcode = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$value) ?? '');
    return $barcode !== '' && preg_match('/^[A-Z0-9]+P\d+C[A-Z0-9]+S[A-Z0-9]+$/', $barcode) === 1;
}

function receipt_is_canonical_company_barcode($value): bool {
    /* LZ_BARCODE_FMT_20260924 / LZ_COLOR_UNIFY_20260925: 編號+色碼+尺碼+P成本. */
    $raw = strtoupper(trim((string)$value));
    if ($raw === '' || strpos($raw, '-') !== false) return false;
    if (preg_match('/NO-?SIZE/', $raw) === 1) return false;
    $barcode = strtoupper(preg_replace('/[^A-Z0-9]/', '', $raw) ?? '');
    if ($barcode === '' || receipt_is_v2_label_barcode($barcode)) return false;
    if (function_exists('lz_barcode_is_unified') && lz_barcode_is_unified($barcode)) return true;
    return preg_match('/^[A-Z]+\d+P\d+$/', $barcode) === 1;
}

function receipt_merge_sku_barcode_aliases(array $sku, array $extra = []): array {
    $values = [];
    foreach (['barcode', 'companyBarcode', 'officialBarcode', 'legacyBarcode', 'mappingCode', 'labelBarcode'] as $field) {
        $text = trim((string)($sku[$field] ?? ''));
        if ($text !== '') $values[] = $text;
    }
    foreach (is_array($sku['linkedBarcodes'] ?? null) ? $sku['linkedBarcodes'] : [] as $value) {
        $text = trim((string)$value);
        if ($text !== '') $values[] = $text;
    }
    foreach ($extra as $value) {
        $text = trim((string)$value);
        if ($text !== '') $values[] = $text;
    }
    return array_values(array_unique($values));
}

function freight_destination_from_receiving_status(array $item, array $batch = []): string {
    $statuses = [
        $item['progress'] ?? '',
        $item['freightReceivingStatus'] ?? '',
        $item['receivingStatus'] ?? '',
        $batch['status'] ?? '',
        $batch['freightReceivingStatus'] ?? '',
        $batch['receivingStatus'] ?? '',
    ];
    foreach ($statuses as $status) {
        $token = preg_replace('/\s+/u', '', receipt_text($status)) ?? '';
        if ($token === '' || preg_match('/收到|收貨|收货|到貨|到货|驗收|验收|入庫|入库/u', $token) !== 1) continue;
        if (preg_match('/中國|中国|東莞|东莞/u', $token) === 1) return 'CN_DONGGUAN';
        if (preg_match('/台灣|台湾|寶輝|宝辉/u', $token) === 1) return 'TW_BAOHUI';
        if (mb_strpos($token, '印尼', 0, 'UTF-8') !== false) return 'ID_DIRECT';
    }
    return '';
}

function freight_destination_site(string $destinationWarehouse): string {
    if ($destinationWarehouse === 'CN_DONGGUAN') return '東莞據點';
    if ($destinationWarehouse === 'TW_BAOHUI') return '寶輝據點';
    if ($destinationWarehouse === 'ID_DIRECT') return '印尼收貨據點';
    return '待補據點';
}

function freight_destination_handling(string $destinationWarehouse): float {
    if ($destinationWarehouse === 'CN_DONGGUAN' || $destinationWarehouse === 'CN') return 30.0;
    if ($destinationWarehouse === 'TW_BAOHUI' || $destinationWarehouse === 'TW') return 30.0;
    if ($destinationWarehouse === 'ID_DIRECT' || $destinationWarehouse === 'ID') return 0.0;
    return 0.0;
}

function freight_receive_inquiry_rows($inquiries): array {
    if (!is_array($inquiries)) return [];
    if (isset($inquiries['inquiries']) && is_array($inquiries['inquiries'])) return array_values($inquiries['inquiries']);
    return $inquiries;
}

function freight_receive_fifo_expand_links_safe(array $freightData, array $freightItem, array $cleanLines, $inquiries): array {
    try {
        return freight_receive_fifo_expand_links(
            $freightData,
            $freightItem,
            $cleanLines,
            freight_receive_inquiry_rows($inquiries)
        );
    } catch (Throwable $error) {
        return [
            'links' => freight_product_receive_linked_orders($freightItem),
            'addedLinks' => [],
            'addedQty' => 0,
            'fifoError' => $error->getMessage(),
        ];
    }
}

function freight_receiving_status_is_negative($value): bool {
    $token = mb_strtolower(preg_replace('/\s+/u', '', receipt_text($value)) ?? '', 'UTF-8');
    if ($token === '') return false;
    return preg_match('/尚未|未收到|未收貨|未收货|未到貨|未到货|未完成|待驗收|待验收|待入庫|待入库|待點貨|待点货|點貨中|点货中|缺件|部分到貨|部分到货|partial|pending|not_received|not_completed|awaiting/u', $token) === 1;
}

function freight_receiving_status_is_received($value): bool {
    $token = mb_strtolower(preg_replace('/\s+/u', '', receipt_text($value)) ?? '', 'UTF-8');
    if ($token === '' || freight_receiving_status_is_negative($token)) return false;
    return preg_match('/已驗收進貨|已验收进货|已驗收|已验收|驗收完成|验收完成|已入庫|已入库|已到貨入庫|已到货入库|收到|收貨|收货|到貨|到货|inventory_received|received|completed|inbound_completed/u', $token) === 1;
}

function freight_package_row_for_item(array $item, array $batch): ?array {
    $itemIds = array_values(array_unique(array_filter(array_map('receipt_text', [
        $item['sourceItemId'] ?? '',
        $item['freightItemId'] ?? '',
        $item['id'] ?? '',
    ]))));
    if (!$itemIds) return null;
    foreach (is_array($batch['packageRows'] ?? null) ? $batch['packageRows'] : [] as $row) {
        if (!is_array($row)) continue;
        $rowIds = array_values(array_unique(array_filter(array_map('receipt_text', [
            $row['sourceItemId'] ?? '',
            $row['freightItemId'] ?? '',
            $row['id'] ?? '',
        ]))));
        foreach ($rowIds as $rowId) if (in_array($rowId, $itemIds, true)) return $row;
    }
    return null;
}

function freight_package_row_is_fully_received(?array $row): bool {
    if (!is_array($row)) return false;
    $statuses = [
        $row['receiptStatus'] ?? '',
        $row['inventoryStatus'] ?? '',
        $row['receivingStatus'] ?? '',
        $row['progress'] ?? '',
    ];
    foreach ($statuses as $status) if (freight_receiving_status_is_negative($status)) return false;
    if (receipt_first_text([$row['inventoryReceivedAt'] ?? '', $row['receivedAt'] ?? '']) !== '') return true;
    foreach ($statuses as $status) if (freight_receiving_status_is_received($status)) return true;
    $expectedQty = max(0, (int)($row['quantity'] ?? $row['qty'] ?? 0));
    $receivedQty = max(0, (int)($row['receivedQty'] ?? 0));
    return $expectedQty > 0 && $receivedQty >= $expectedQty;
}

function freight_batch_is_fully_received(array $batch): bool {
    $statuses = [
        $batch['receivingStatus'] ?? '',
        $batch['status'] ?? '',
        $batch['freightReceivingStatus'] ?? '',
        $batch['inventoryStatus'] ?? '',
        $batch['receivingStage'] ?? '',
    ];
    foreach ($statuses as $status) if (freight_receiving_status_is_negative($status) || preg_match('/partial|部分|缺件/ui', receipt_text($status)) === 1) return false;
    $pendingCount = $batch['inventoryPendingItemCount'] ?? null;
    if (is_numeric($pendingCount) && (int)$pendingCount > 0) return false;
    $receiving = isset($batch['receiving']) && is_array($batch['receiving']) ? $batch['receiving'] : [];
    $missingCount = $receiving['missingQty'] ?? $receiving['missingCount'] ?? null;
    if (is_numeric($missingCount) && (int)$missingCount > 0) return false;
    $packageRows = is_array($batch['packageRows'] ?? null) ? array_values(array_filter($batch['packageRows'], 'is_array')) : [];
    if ($packageRows) {
        foreach ($packageRows as $row) if (!freight_package_row_is_fully_received($row)) return false;
        return true;
    }
    if (receipt_first_text([$receiving['completedAt'] ?? '', $batch['receivingCompletedAt'] ?? '']) !== '') return true;
    $inventoryStatus = mb_strtolower(receipt_text($batch['inventoryStatus'] ?? ''), 'UTF-8');
    $receivingStage = mb_strtolower(receipt_text($batch['receivingStage'] ?? ''), 'UTF-8');
    if (in_array($inventoryStatus, ['received', 'inventory_received', 'completed', 'inbound_completed'], true)) return true;
    if (in_array($receivingStage, ['received', 'inventory_received', 'completed', 'inbound_completed'], true)) return true;
    $explicitStatus = preg_replace('/\s+/u', '', receipt_first_text([$batch['status'] ?? '', $batch['receivingStatus'] ?? ''])) ?? '';
    return in_array($explicitStatus, ['已入庫', '已入库', '已完成入庫', '已完成入库', '已驗收進貨', '已验收进货'], true);
}

function freight_item_has_receiving_evidence(array $item, array $batch): bool {
    if (receipt_first_text([$item['inventoryReceivedAt'] ?? '', $item['receivedAt'] ?? '']) !== '') return true;
    $statuses = [
        $item['receivingStatus'] ?? '',
        $item['progress'] ?? '',
        $item['freightReceivingStatus'] ?? '',
        $item['inventoryStatus'] ?? '',
        $item['receivingStage'] ?? '',
    ];
    $itemIsPending = false;
    foreach ($statuses as $status) {
        $token = mb_strtolower(preg_replace('/\s+/u', '', receipt_text($status)) ?? '', 'UTF-8');
        // An explicit item-level shortfall is authoritative even if an old
        // batch summary incorrectly says the whole batch is complete.
        if (preg_match('/未收到|未收貨|未收货|未到貨|未到货|缺件|部分到貨|部分到货/u', $token) === 1) return false;
        if (freight_receiving_status_is_negative($token)) $itemIsPending = true;
    }
    if (!$itemIsPending) {
        foreach ($statuses as $status) if (freight_receiving_status_is_received($status)) return true;
    }
    if (freight_package_row_is_fully_received(freight_package_row_for_item($item, $batch))) return true;
    return freight_batch_is_fully_received($batch);
}

function freight_row_billed_weight(array $row): float {
    return max(0.0,
        (float)($row['billedWeightKg'] ?? 0),
        (float)($row['shippingWeightKg'] ?? 0),
        (float)($row['actualWeightKg'] ?? 0),
        (float)($row['volumeWeightKg'] ?? 0),
        (float)($row['weightKg'] ?? 0)
    );
}

function freight_weight_group_key(array $row): string {
    foreach (['trackingNo', 'haohongTrackingNo', 'parcelId', 'sourceItemId', 'id'] as $field) {
        $value = strtoupper(preg_replace('/\s+/u', '', receipt_text($row[$field] ?? '')) ?? '');
        if ($value !== '') return $value;
    }
    return hash('sha256', implode('|', array_map('strval', [
        $row['productCode'] ?? '', $row['sampleBarcode'] ?? '', $row['color'] ?? '', $row['size'] ?? ''
    ])));
}

function freight_weighted_allocation(array $targetItem, array $batch, array $allFreightItems, float $amount): array {
    $rows = isset($batch['packageRows']) && is_array($batch['packageRows']) ? $batch['packageRows'] : [];
    if (!$rows) {
        $batchId = receipt_text($batch['id'] ?? '');
        foreach ($allFreightItems as $candidate) {
            if (is_array($candidate) && $batchId !== '' && receipt_text($candidate['batchId'] ?? '') === $batchId) $rows[] = $candidate;
        }
    }
    $groups = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $key = freight_weight_group_key($row);
        $qty = max(1, (int)($row['quantity'] ?? ($row['qty'] ?? 1)));
        if (!isset($groups[$key])) $groups[$key] = ['weight' => 0.0, 'qty' => 0];
        // The same package can be expanded into several colour/size rows. Its
        // package weight is counted once, while quantity is shared by all rows.
        $groups[$key]['weight'] = max($groups[$key]['weight'], freight_row_billed_weight($row));
        $groups[$key]['qty'] += $qty;
    }
    $targetKey = freight_weight_group_key($targetItem);
    $targetWeight = isset($groups[$targetKey]) ? (float)$groups[$targetKey]['weight'] : freight_row_billed_weight($targetItem);
    $targetQty = isset($groups[$targetKey]) ? max(1, (int)$groups[$targetKey]['qty']) : max(1, (int)($targetItem['quantity'] ?? 1));
    $totalWeight = array_reduce($groups, static function (float $sum, array $group): float { return $sum + max(0.0, (float)$group['weight']); }, 0.0);
    if ($totalWeight <= 0 || $targetWeight <= 0 || $amount <= 0) {
        return ['perUnitTwd' => 0.0, 'packageTwd' => 0.0, 'packageWeightKg' => $targetWeight, 'totalWeightKg' => $totalWeight, 'groupQty' => $targetQty];
    }
    $packageTwd = $amount * $targetWeight / $totalWeight;
    return [
        'perUnitTwd' => round($packageTwd / $targetQty, 2),
        'packageTwd' => round($packageTwd, 2),
        'packageWeightKg' => round($targetWeight, 3),
        'totalWeightKg' => round($totalWeight, 3),
        'groupQty' => $targetQty,
    ];
}

function freight_receipt_snapshot(array $payload, string $freightFile): array {
    $data = read_json($freightFile);
    $batches = isset($data['batches']) && is_array($data['batches']) ? $data['batches'] : [];
    $freightItems = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
    $batchId = receipt_text($payload['freightBatchId'] ?? '');
    $itemId = receipt_text($payload['freightItemId'] ?? '');
    $trackingNo = receipt_text($payload['freightTrackingNo'] ?? '');
    $item = null;
    foreach ($freightItems as $row) {
        if (!is_array($row)) continue;
        $values = array_map('strval', [$row['id'] ?? '', $row['trackingNo'] ?? '', $row['haohongTrackingNo'] ?? '']);
        if (($itemId !== '' && in_array($itemId, $values, true)) || ($trackingNo !== '' && in_array($trackingNo, $values, true))) { $item = $row; break; }
    }
    if ($batchId === '' && is_array($item)) {
        $batchId = receipt_text($item['batchId'] ?? '');
    }
    $batch = null;
    foreach ($batches as $row) {
        if (!is_array($row)) continue;
        $values = array_map('strval', [$row['id'] ?? '', $row['batchNo'] ?? '', $row['customsNo'] ?? '', $row['consolidationNo'] ?? '', $row['logisticsTrackingNo'] ?? '', $row['taiwanTrackingNo'] ?? '', $row['firstTrackingNo'] ?? '']);
        if (($batchId !== '' && in_array($batchId, $values, true)) || ($trackingNo !== '' && in_array($trackingNo, $values, true))) { $batch = $row; break; }
    }
    $words = implode(' ', array_map('strval', [is_array($item) ? ($item['progress'] ?? '') : '', is_array($item) ? ($item['haohongTrackingNo'] ?? '') : '', is_array($item) ? ($item['forwarder'] ?? '') : '']));
    if (!is_array($batch) && is_array($item) && mb_strpos($words, '豪鴻', 0, 'UTF-8') !== false) {
        $batch = ['id' => '', 'forwarder' => '豪鴻物流', 'costMode' => 'haohong_weight', 'warehouseTaxType' => 'tax_exempt', 'chargeType' => 'shipping', 'amount' => 0, 'itemCount' => 0];
    }
    if (!is_array($batch) && is_array($item) && mb_strpos($words, '自提', 0, 'UTF-8') !== false) {
        $batch = ['id' => '', 'forwarder' => '自提取回', 'costMode' => 'self_pickup', 'warehouseTaxType' => 'tax_exempt', 'chargeType' => 'none', 'amount' => 30, 'itemCount' => 1, 'allocationPerItem' => 30];
    }
    $costMode = receipt_text(is_array($batch) ? ($batch['costMode'] ?? '') : ($payload['freightCostMode'] ?? ''));
    $chargeType = receipt_text(is_array($batch) ? ($batch['chargeType'] ?? '') : ($payload['freightChargeType'] ?? ''));
    $amount = max(0, (float)(is_array($batch) ? ($batch['amount'] ?? 0) : ($payload['freightAmountTwd'] ?? 0)));
    $itemCount = max(0, (int)(is_array($batch) ? ($batch['itemCount'] ?? 0) : 0));
    $allocation = max(0, (float)(is_array($batch) ? ($batch['allocationPerItem'] ?? 0) : ($payload['freightAllocationTwd'] ?? 0)));
    $weightedAllocation = ['perUnitTwd' => 0.0, 'packageTwd' => 0.0, 'packageWeightKg' => 0.0, 'totalWeightKg' => 0.0, 'groupQty' => 1];
    $isHaohongWeightBatch = in_array($costMode, ['haohong_weight', 'haohong_batch', 'haohong_weight_batch'], true) || (is_array($batch) && receipt_text($batch['provider'] ?? '') === 'haohong');
    if ($isHaohongWeightBatch && is_array($item) && is_array($batch) && $amount > 0) {
        $weightedAllocation = freight_weighted_allocation($item, $batch, $freightItems, $amount);
        if ($weightedAllocation['perUnitTwd'] > 0) $allocation = $weightedAllocation['perUnitTwd'];
    }
    if ($allocation <= 0 && $itemCount > 0 && $chargeType !== 'none' && $costMode !== 'haohong_weight') $allocation = round($amount / $itemCount, 2);
    if ($chargeType === 'none' && $costMode !== 'self_pickup') $allocation = 0;
    if ($costMode === 'self_pickup') $allocation = 0;
    $inferredDestinationWarehouse = freight_destination_from_receiving_status(is_array($item) ? $item : [], is_array($batch) ? $batch : []);
    $itemDestinationWarehouse = receipt_text(is_array($item) ? ($item['destinationWarehouse'] ?? '') : '');
    $batchDestinationWarehouse = receipt_text(is_array($batch) ? ($batch['destinationWarehouse'] ?? '') : '');
    $destinationWarehouse = $inferredDestinationWarehouse !== '' ? $inferredDestinationWarehouse : ($itemDestinationWarehouse !== '' ? $itemDestinationWarehouse : ($batchDestinationWarehouse !== '' ? $batchDestinationWarehouse : receipt_text($payload['freightDestinationWarehouse'] ?? '')));
    $warehouseTaxType = receipt_text(is_array($batch) ? ($batch['warehouseTaxType'] ?? '') : ($payload['freightWarehouseTaxType'] ?? ''));
    if ($destinationWarehouse === 'CN_DONGGUAN') {
        $costMode = 'dongguan_handling';
        $warehouseTaxType = 'tax_exempt';
        $chargeType = 'none';
        $amount = 0.0;
        $allocation = 0.0;
    }
    $destinationSite = freight_destination_site($destinationWarehouse);
    $configuredHandling = freight_destination_handling($destinationWarehouse);
    $savedHandling = max(0, (float)(is_array($item) && array_key_exists('handlingPerItemTwd', $item) ? $item['handlingPerItemTwd'] : (is_array($batch) && array_key_exists('handlingPerItemTwd', $batch) ? $batch['handlingPerItemTwd'] : 0)));
    // Warehouse handling is an internal per-piece cost, not an alternative to
    // the saved product cost. Dongguan and Baohui therefore keep their minimum
    // NT$30 / NT$20 handling even when an old freight row saved zero here.
    $handlingPerItem = max($configuredHandling, $savedHandling);
    return [
        'trackingNo' => $trackingNo !== '' ? $trackingNo : receipt_text(is_array($item) ? ($item['trackingNo'] ?? '') : ''),
        'itemId' => receipt_text(is_array($item) ? ($item['id'] ?? '') : $itemId),
        'batchId' => receipt_text(is_array($batch) ? ($batch['id'] ?? '') : $batchId),
        'forwarder' => receipt_text(is_array($batch) ? ($batch['forwarder'] ?? '') : (is_array($item) ? ($item['forwarder'] ?? $item['progress'] ?? '') : ($payload['freightForwarder'] ?? ''))),
        'platform' => receipt_text(is_array($item) ? ($item['platform'] ?? $item['importSource'] ?? '') : ''),
        'ownerNote' => receipt_text(is_array($item) ? ($item['ownerNote'] ?? '') : ''),
        'costMode' => $costMode,
        'warehouseTaxType' => $warehouseTaxType,
        'chargeType' => $chargeType,
        'amountTwd' => round($amount, 2),
        'allocationPerItemTwd' => round($allocation, 2),
        'haohongPackageAllocationTwd' => round((float)$weightedAllocation['packageTwd'], 2),
        'haohongPackageWeightKg' => round((float)$weightedAllocation['packageWeightKg'], 3),
        'haohongBatchWeightKg' => round((float)$weightedAllocation['totalWeightKg'], 3),
        'destinationWarehouse' => $destinationWarehouse,
        'destinationSite' => $destinationSite,
        'handlingPerItemTwd' => round($handlingPerItem, 2),
        'costRmb' => max(0, (float)(is_array($item) ? ($item['costRmb'] ?? 0) : 0)),
        'existingTwdCost' => max(0, (float)(is_array($item) ? ($item['existingTwdCost'] ?? 0) : 0)),
        'fixedTwdCost' => !empty($item['fixedTwdCost']),
        'weightKg' => max(0, (float)(is_array($item) ? ($item['weightKg'] ?? 0) : 0)),
        'verifiedFromFreightData' => is_array($item) || is_array($batch),
        'capturedAt' => date(DATE_ATOM),
    ];
}

function receipt_catalog_merchandise_cost(array $product, float $receivedCost): float {
    $rmb = is_numeric($product['costRmb'] ?? null) ? max(0, (float)$product['costRmb']) : 0.0;
    if ($rmb > 0) return round($rmb * 5, 2);
    $existing = 0.0;
    foreach (['cost', 'currentCostTwd', 'finalCostTwd'] as $field) {
        if (isset($product[$field]) && is_numeric($product[$field]) && (float)$product[$field] > 0) {
            $existing = (float)$product[$field];
            break;
        }
    }
    if ($existing > 0) return round($existing, 2);
    return round(max(0, $receivedCost), 2);
}

function sync_preorder_receipt_products(array &$products, array $receiptLog, string $operationId): bool {
    $productsChanged = false;
    foreach ($receiptLog as $received) {
        if (!is_array($received)) continue;
        $receivedProductId = receipt_text($received['productId'] ?? '');
        $hasProduct = false;
        foreach ($products as $candidateProduct) {
            if (is_array($candidateProduct) && receipt_text($candidateProduct['id'] ?? '') === $receivedProductId) { $hasProduct = true; break; }
        }
        if (!$hasProduct && !empty($received['createdProduct']) && $receivedProductId !== '') {
            $products[] = receipt_new_product_record(
                $receivedProductId,
                receipt_text($received['productCode'] ?? ''),
                receipt_text($received['productName'] ?? ''),
                receipt_text($received['category'] ?? ''),
                receipt_text($received['color'] ?? ''),
                receipt_text($received['warehouse'] ?? 'TW'),
                receipt_text($received['appliedAt'] ?? '') ?: date(DATE_ATOM)
            );
            $productsChanged = true;
        }
        foreach ($products as &$product) {
            if (!is_array($product) || receipt_text($product['id'] ?? '') !== $receivedProductId) continue;
            $productAlreadyMarked = $operationId !== '' && inventory_transaction_marker($product, $operationId) !== null;
            $receivedWarehouse = strtoupper(receipt_text($received['warehouse'] ?? ''));
            $receivedStockPurpose = strtolower(receipt_text($received['stockPurpose'] ?? 'live_ready'));
            $receivedGoodsPurpose = strtolower(receipt_text($received['goodsPurpose'] ?? ''));
            if ($receivedGoodsPurpose === 'internal_use') $receivedStockPurpose = 'internal_use';
            $receivedColor = canonical_bilingual_color_name($received['color'] ?? '');
            $frontImage = receipt_text(($received['arrivalViews']['front'] ?? '') ?: ($received['arrivalImage'] ?? '') ?: ($received['colorImage'] ?? ''));
            $altImages = [];
            if (isset($received['replacementAlts']) && is_array($received['replacementAlts'])) {
                foreach ($received['replacementAlts'] as $alt) {
                    $alt = receipt_text($alt);
                    if ($alt !== '') $altImages[] = $alt;
                }
            }
            if (!$altImages && isset($received['arrivalViews']) && is_array($received['arrivalViews'])) {
                foreach (['back', 'left', 'right'] as $viewKey) {
                    $viewImg = receipt_text($received['arrivalViews'][$viewKey] ?? '');
                    if ($viewImg !== '') $altImages[] = $viewImg;
                }
            }
            if (receipt_apply_inbound_replacement_images($product, $receivedColor, $frontImage, $altImages)) {
                $product['updatedAt'] = date(DATE_ATOM);
                $productsChanged = true;
            }
            if (in_array($receivedWarehouse, ['TW', 'CN', 'ID'], true)) {
                $warehouseProfile = receipt_warehouse_profile($receivedWarehouse);
                $product['warehouse'] = $warehouseProfile['name'];
                $product['warehouseName'] = $warehouseProfile['name'];
                $product['warehouseCode'] = $warehouseProfile['code'];
                $product['preorderOnly'] = false;
                if ($receivedGoodsPurpose === 'internal_use') {
                    $product['showOnWebsite'] = false;
                    if (!empty($received['createdProduct'])) $product['goodsPurpose'] = 'internal_use';
                } elseif ($receivedStockPurpose === 'sample') {
                    $product['sampleStockAvailable'] = true;
                    if (!empty($received['createdProduct'])) {
                        $product['productMode'] = $receivedWarehouse === 'CN' ? 'china_sample' : 'warehouse_sample';
                        $product['chinaSample'] = $receivedWarehouse === 'CN';
                        $product['showOnWebsite'] = false;
                    }
                } elseif ($receivedWarehouse === 'CN') {
                    $product['productMode'] = 'china_sample';
                    $product['chinaSample'] = true;
                    $product['showOnWebsite'] = false;
                } else {
                    $product['productMode'] = 'ready';
                    $product['chinaSample'] = false;
                    $product['storefrontManualHidden'] = false;
                    $product['showOnWebsite'] = $receivedWarehouse === 'TW';
                }
                $product['inventoryReceivedAt'] = receipt_text($received['appliedAt'] ?? '') ?: date(DATE_ATOM);
                $product['inventoryReceivedWarehouse'] = $receivedWarehouse;
                $receivingDocumentNo = receipt_text($received['operationContext']['receivingDocument']['documentNo'] ?? '');
                $product['freightReceivingStatus'] = '已完成入庫';
                $product['inventoryStatus'] = 'received';
                $product['receivingStage'] = 'inventory_received';
                $product['productFiledInventoryReceived'] = true;
                $product['productFiledInventoryReceivedAt'] = $product['inventoryReceivedAt'];
                if ($receivingDocumentNo !== '') {
                    $product['receivingDocumentNo'] = $receivingDocumentNo;
                    $product['productFiledInventoryReceiptNo'] = $receivingDocumentNo;
                }
                $productsChanged = true;
            }
            if (!isset($product['colors']) || !is_array($product['colors'])) $product['colors'] = [];
            // LZ_SPEC_COLOR_MERGE_20260925: 粉紅色 ≡ 粉紅(PINK) 是整理顏色，不是新增一列。
            if ($receivedColor !== '' && receipt_merge_product_color_entry($product, $receivedColor, '')) {
                $product['updatedAt'] = date(DATE_ATOM);
                $productsChanged = true;
            }
            $receivedCost = max(0, (float)($received['unitCostTwd'] ?? 0));
            if ($receivedCost > 0) {
                $costEffectiveAt = receipt_text($received['costEffectiveAt'] ?? '') ?: date(DATE_ATOM);
                $catalogCost = receipt_catalog_merchandise_cost($product, $receivedCost);
                $hadCatalogCost = receipt_catalog_merchandise_cost($product, 0) > 0;
                $product['cost'] = $catalogCost;
                $product['currentCostTwd'] = $catalogCost;
                $product['finalCostTwd'] = $catalogCost;
                $product['costSource'] = 'purchase_receipt';
                $product['costEffectiveAt'] = $costEffectiveAt;
                $product['currentCostUpdatedAt'] = $costEffectiveAt;
                $product['costUpdatedAt'] = $costEffectiveAt;
                if (!$productAlreadyMarked) $product['costVersion'] = max(0, (int)($product['costVersion'] ?? 0)) + 1;
                $product['updatedAt'] = $costEffectiveAt;
                // Replenishment haohong/duty allocation must not rewrite the
                // product-card 成本 (RMB × 5) or auto-bump 售價 off that extra.
                if (!$hadCatalogCost) zhangzhang_apply_pricing($product, $catalogCost, $costEffectiveAt);
                $productsChanged = true;
            }
            if ($operationId !== '' && !$productAlreadyMarked) {
                set_inventory_transaction_marker($product, $operationId, [
                    'kind' => 'preorder_receive',
                    'inquiryId' => (string)($received['inquiryId'] ?? ''),
                    'payloadHash' => (string)($received['payloadHash'] ?? ''),
                    'appliedAt' => (string)($received['appliedAt'] ?? date(DATE_ATOM)),
                    'lines' => [$received],
                ]);
                $productsChanged = true;
            }
            break;
        }
        unset($product);
    }
    return $productsChanged;
}

function apply_preorder_receipt_inventory(array $inquiry, array $payload, string $warehouse, string $productsFile, string $skusFile, string $stateFile, string $freightFile, string $operationId = '', string $payloadHash = '', bool $inventoryLockHeld = false): array {
    // Standalone purchase receipt confirmation holds the catalog lock before
    // entering here so its fixed lock order remains receipts -> document ->
    // freight -> catalog. Other callers acquire the same lock locally.
    $inventoryLock = $inventoryLockHeld ? null : acquire_inventory_file_lock($skusFile);
    $profile = receipt_warehouse_profile($warehouse);
    $stockPurpose = strtolower(receipt_text($payload['stockPurpose'] ?? ''));
    if (!in_array($stockPurpose, ['sample', 'live_ready', 'internal_use'], true)) $stockPurpose = 'live_ready';
    $goodsPurpose = strtolower(receipt_text($payload['goodsPurpose'] ?? ''));
    if ($goodsPurpose === 'internal_use') $stockPurpose = 'internal_use';
    $items = isset($inquiry['items']) && is_array($inquiry['items']) ? array_values($inquiry['items']) : [];
    $incoming = isset($payload['receiptLines']) && is_array($payload['receiptLines']) ? array_values($payload['receiptLines']) : (isset($inquiry['freightReceiptLines']) && is_array($inquiry['freightReceiptLines']) ? array_values($inquiry['freightReceiptLines']) : []);
    $freight = freight_receipt_snapshot($payload + [
        'freightTrackingNo' => $inquiry['freightTrackingNo'] ?? '',
        'freightItemId' => $inquiry['freightItemId'] ?? '',
        'freightBatchId' => $inquiry['freightBatchId'] ?? '',
    ], $freightFile);
    $skus = read_json($skusFile);
    $products = read_json($productsFile);
    $productsCreated = false;
    $newProductIdsByCode = [];
    $newProductMetaByCode = [];
    $existingEntries = $operationId !== '' ? inventory_transaction_marker_entries($skus, $operationId) : [];
    if ($existingEntries) {
        foreach ($existingEntries as $entry) {
            $existingHash = (string)($entry['payloadHash'] ?? '');
            if ($existingHash !== '' && $payloadHash !== '' && !hash_equals($existingHash, $payloadHash)) {
                respond(['ok' => false, 'error' => '這張預購單已有不同內容的到貨入庫紀錄，已停止重複增加庫存'], 409);
            }
            $itemIndex = (int)($entry['itemIndex'] ?? -1);
            if ($itemIndex < 0 || !isset($items[$itemIndex]) || !is_array($items[$itemIndex])) continue;
            $items[$itemIndex]['skuId'] = receipt_text($entry['skuId'] ?? '');
            $items[$itemIndex]['sku'] = receipt_text($entry['skuId'] ?? '');
            $items[$itemIndex]['color'] = receipt_text($entry['color'] ?? '');
            $items[$itemIndex]['size'] = receipt_text($entry['size'] ?? 'NO SIZE') ?: 'NO SIZE';
            $items[$itemIndex]['qty'] = max(1, (int)($entry['qty'] ?? 1));
            $items[$itemIndex]['receivedBarcode'] = receipt_text($entry['barcode'] ?? '');
            $items[$itemIndex]['receivedWarehouse'] = receipt_text($entry['warehouse'] ?? $profile['code']);
            $items[$itemIndex]['receivedUnitCostTwd'] = max(0, (float)($entry['unitCostTwd'] ?? 0));
            if (isset($entry['freightCostSnapshot']) && is_array($entry['freightCostSnapshot'])) $items[$itemIndex]['freightCostSnapshot'] = $entry['freightCostSnapshot'];
            if (receipt_text($entry['arrivalImage'] ?? '') !== '') $items[$itemIndex]['arrivalImage'] = receipt_text($entry['arrivalImage']);
        }
        $productsChanged = sync_preorder_receipt_products($products, $existingEntries, $operationId);
        if ($productsChanged) write_json($productsFile, $products);
        sync_preorder_inventory_state($stateFile, $skus, $products, $operationId, [
            'kind' => 'preorder_receive',
            'inquiryId' => (string)($inquiry['id'] ?? ''),
            'payloadHash' => $payloadHash,
            'appliedAt' => (string)($existingEntries[0]['appliedAt'] ?? date(DATE_ATOM)),
            'productIds' => array_values(array_unique(array_filter(array_map(
                static fn($entry) => receipt_text($entry['productId'] ?? ''),
                $existingEntries
            )))),
            'recoveredAt' => date(DATE_ATOM),
        ]);
        @unlink($GLOBALS['dataDir'] . DIRECTORY_SEPARATOR . 'public-catalog.json');
        return ['items' => $items, 'freight' => $freight, 'receiptLog' => $existingEntries, 'warehouse' => $profile, 'operationId' => $operationId, 'idempotentReplay' => true];
    }
    $receiptLog = [];
    foreach ($items as $index => &$item) {
        if (!is_array($item)) continue;
        $line = isset($incoming[$index]) && is_array($incoming[$index]) ? $incoming[$index] : [];
        $createdThisLine = false;
        $sourceSkuId = receipt_first_text([$line['skuId'] ?? '', $item['skuId'] ?? '', $item['sku'] ?? '']);
        $sourceSku = null;
        foreach ($skus as $candidate) if (is_array($candidate) && receipt_first_text([$candidate['id'] ?? '', $candidate['sku'] ?? '']) === $sourceSkuId) { $sourceSku = $candidate; break; }
        $productId = receipt_text($line['productId'] ?? $item['productId'] ?? (is_array($sourceSku) ? ($sourceSku['productId'] ?? '') : ''));
        $productName = receipt_text($line['productName'] ?? $item['title'] ?? $item['productName'] ?? '');
        $category = receipt_text($line['category'] ?? '');
        $productCode = receipt_first_text([$line['productCode'] ?? '', $item['code'] ?? '']);
        $color = receipt_text($line['color'] ?? $item['color'] ?? (is_array($sourceSku) ? ($sourceSku['colorName'] ?? $sourceSku['color'] ?? '') : ''));
        $canonicalColor = canonical_bilingual_color_name($color);
        if ($canonicalColor !== '') $color = $canonicalColor;
        $size = receipt_text($line['size'] ?? $item['size'] ?? (is_array($sourceSku) ? ($sourceSku['sizeName'] ?? $sourceSku['size'] ?? 'NO SIZE') : 'NO SIZE')) ?: 'NO SIZE';
        $barcode = receipt_first_text([$line['barcode'] ?? '', is_array($sourceSku) ? ($sourceSku['companyBarcode'] ?? '') : '', is_array($sourceSku) ? ($sourceSku['barcode'] ?? '') : '', is_array($sourceSku) ? ($sourceSku['legacyBarcode'] ?? '') : '', $sourceSkuId]);
        $qty = max(1, (int)($line['qty'] ?? $item['qty'] ?? 1));
        $arrivalImage = receipt_text($line['arrivalImage'] ?? $item['arrivalImage'] ?? '');
        if ($arrivalImage !== '') {
            $storedArrivalImage = externalize_embedded_data_images_only($arrivalImage, __DIR__);
            if (is_string($storedArrivalImage) && $storedArrivalImage !== '') $arrivalImage = $storedArrivalImage;
        }
        $arrivalViews = [];
        if (isset($line['arrivalViews']) && is_array($line['arrivalViews'])) {
            foreach (['front', 'back', 'left', 'right'] as $viewKey) {
                $viewImg = receipt_text($line['arrivalViews'][$viewKey] ?? '');
                if ($viewImg === '') continue;
                $storedView = externalize_embedded_data_images_only($viewImg, __DIR__);
                if (is_string($storedView) && $storedView !== '') $viewImg = $storedView;
                $arrivalViews[$viewKey] = $viewImg;
            }
        }
        if ($arrivalImage === '' && $arrivalViews) {
            $arrivalImage = receipt_text($arrivalViews['front'] ?? '');
        }
        $colorImage = receipt_text($line['colorImage'] ?? (is_array($sourceSku) ? ($sourceSku['colorImage'] ?? '') : ''));
        if ($colorImage !== '') {
            $storedColorImage = externalize_embedded_data_images_only($colorImage, __DIR__);
            if (is_string($storedColorImage) && $storedColorImage !== '') $colorImage = $storedColorImage;
        }
        $proofWords = implode(' ', array_map('strval', [
            $inquiry['sales'] ?? '', $inquiry['salesName'] ?? '', $inquiry['purchasePlatform'] ?? '', $inquiry['platform'] ?? '',
            $freight['platform'] ?? '', $freight['ownerNote'] ?? ''
        ]));
        $proofRequired = preg_match('/拚張|拚A|張張/ui', $proofWords) === 1;
        $masterProduct = receipt_resolve_master_product($products, $skus, $productId, $productCode, $barcode, $sourceSkuId);
        if (is_array($masterProduct)) {
            $productId = receipt_text($masterProduct['id'] ?? $productId);
        }
        if ($productId === '') {
            if ($category === '' || $productCode === '') respond(['ok' => false, 'error' => '新產品入庫前必須先填產品分類與產品編號'], 400);
            $normalizedCode = strtoupper((string)preg_replace('/[^A-Z0-9]/i', '', $productCode));
            if ($normalizedCode !== '' && isset($newProductIdsByCode[$normalizedCode])) {
                $savedMeta = $newProductMetaByCode[$normalizedCode] ?? [];
                $sameCategory = mb_strtolower(receipt_text($savedMeta['category'] ?? ''), 'UTF-8') === mb_strtolower($category, 'UTF-8');
                $savedName = mb_strtolower(receipt_text($savedMeta['productName'] ?? ''), 'UTF-8');
                $currentName = mb_strtolower($productName, 'UTF-8');
                if (!$sameCategory || ($savedName !== '' && $currentName !== '' && $savedName !== $currentName)) {
                    respond(['ok' => false, 'error' => '同一張進貨單內的產品編號 ' . $productCode . ' 對應到不同分類或產品名稱，請重新依分類產生下一號'], 409);
                }
                $productId = $newProductIdsByCode[$normalizedCode];
                foreach ($products as $candidateProduct) {
                    if (is_array($candidateProduct) && receipt_text($candidateProduct['id'] ?? '') === $productId) { $masterProduct = $candidateProduct; break; }
                }
            }
            if ($productId === '') {
                $existingByCode = null;
                foreach ($products as $candidateProduct) {
                    if (!is_array($candidateProduct)) continue;
                    $candidateCode = strtoupper((string)preg_replace('/[^A-Z0-9]/i', '', receipt_first_text([$candidateProduct['code'] ?? '', $candidateProduct['productLine'] ?? '', $candidateProduct['id'] ?? ''])));
                    if ($candidateCode === '' || $candidateCode !== $normalizedCode) continue;
                    $existingByCode = $candidateProduct;
                    break;
                }
                if (is_array($existingByCode)) {
                    // Already filed product: inbound adds stock even when current stock is 0.
                    $productId = receipt_text($existingByCode['id'] ?? '');
                    $masterProduct = $existingByCode;
                } else {
                    if ($normalizedCode === '') respond(['ok' => false, 'error' => '產品編號必須包含英文字母或數字，請重新依分類產生下一號'], 400);
                    $baseId = 'p-' . strtolower($normalizedCode);
                    $productId = $baseId;
                    $suffix = 2;
                    while (array_filter($products, static fn($candidateProduct) => is_array($candidateProduct) && (string)($candidateProduct['id'] ?? '') === $productId)) {
                        $productId = $baseId . '-' . $suffix;
                        $suffix++;
                    }
                    $nowCreated = date(DATE_ATOM);
                    $masterProduct = receipt_new_product_record($productId, $productCode, $productName, $category, $color, $profile['code'], $nowCreated);
                    $products[] = $masterProduct;
                    $productsCreated = true;
                    $createdThisLine = true;
                    if ($normalizedCode !== '') {
                        $newProductIdsByCode[$normalizedCode] = $productId;
                        $newProductMetaByCode[$normalizedCode] = ['category' => $category, 'productName' => $productName];
                    }
                }
            }
        } elseif (!is_array($masterProduct)) {
            respond(['ok' => false, 'error' => receipt_line_ref($index, $productCode, $barcode) . ' 找不到對應產品資料（目錄貨號可能已改）。請核對貨號後再存，不用重打整張。'], 400);
        }
        if (is_array($masterProduct)) {
            $productCode = receipt_first_text([$masterProduct['code'] ?? '', $masterProduct['productLine'] ?? '', $productCode]);
            $productName = receipt_first_text([$masterProduct['title'] ?? '', $masterProduct['name'] ?? '', $productName]);
            $category = receipt_first_text([$masterProduct['category'] ?? '', $masterProduct['categoryName'] ?? '', $category]);
        }
        if (receipt_is_placeholder_inbound_color($color)) {
            respond(['ok' => false, 'error' => receipt_line_ref($index, $productCode, $barcode) . ' 顏色尚未填寫（目前是「未填顏色」）。請補上顏色後再按存檔／入庫，其他品項不用重打。'], 400);
        }
        if ($barcode === '' || $color === '' || $size === '') {
            respond(['ok' => false, 'error' => receipt_line_ref($index, $productCode, $barcode) . ' 請補齊公司條碼、顏色與尺寸。'], 400);
        }
        foreach ($skus as $candidateSku) {
            if (!is_array($candidateSku)) continue;
            if (($candidateSku['archived'] ?? false) === true || ($candidateSku['legacyMislinked'] ?? false) === true) continue;
            $status = strtolower(receipt_text($candidateSku['status'] ?? ''));
            if (in_array($status, ['inactive', 'deleted', 'archived', 'removed'], true)) continue;
            $candidateBarcode = receipt_first_text([$candidateSku['companyBarcode'] ?? '', $candidateSku['barcode'] ?? '', $candidateSku['legacyBarcode'] ?? '', $candidateSku['mappingCode'] ?? '']);
            if ($candidateBarcode === '' || strtoupper($candidateBarcode) !== strtoupper($barcode)) continue;
            if (receipt_same_variant($candidateSku, $productId, $color, $size)) continue;
            $candidateProductId = receipt_text($candidateSku['productId'] ?? '');
            if ($candidateProductId !== '' && $candidateProductId === $productId) {
                /* Same 款號 + 尺碼: TW/CN/PREORDER share unified barcode, and
                   粉紅色 vs 粉紅(PINK) used to fail receipt_same_variant. */
                $candidateSize = freight_receive_variant_token(receipt_first_text([$candidateSku['sizeName'] ?? '', $candidateSku['size'] ?? '', 'NO SIZE']), true);
                $wantedSize = freight_receive_variant_token($size !== '' ? $size : 'NO SIZE', true);
                if ($candidateSize === $wantedSize) continue;
            }
            respond(['ok' => false, 'error' => '公司條碼 ' . $barcode . ' 已被其他產品或規格使用，請重新核對條碼'], 409);
        }
        if ($proofRequired && $arrivalImage === '') respond(['ok' => false, 'error' => '拚張／拚A／張張的到貨品項必須附到貨照片'], 400);
        $targetIndex = receipt_find_receipt_target_sku_index($skus, $profile, $productId, $color, $size, $barcode);
        if ($targetIndex < 0) {
            // A warehouse variant must not inherit stock transaction markers or
            // warehouse/freight history from its source SKU. Copy only stable,
            // non-warehouse presentation and pricing fields when the source is
            // genuinely the same product variant.
            $newSku = [];
            $sourceIsSameVariant = is_array($sourceSku) && receipt_same_variant($sourceSku, $productId, $color, $size);
            if ($sourceIsSameVariant) {
                foreach (['price', 'cost', 'currentCostTwd', 'finalCostTwd', 'costSource', 'costEffectiveAt', 'currentCostUpdatedAt', 'costUpdatedAt', 'costVersion', 'barcodeCostTwd', 'initialBarcodeCostTwd', 'barcodeCostSource', 'barcodeCostRecordedAt', 'colorCode', 'colorImage', 'sizeCode', 'status', 'productMode', 'preorderOnly', 'pendingPricing'] as $copyField) {
                    if (array_key_exists($copyField, $sourceSku)) $newSku[$copyField] = $sourceSku[$copyField];
                }
            }
            $baseId = ($sourceIsSameVariant && $sourceSkuId !== '' ? $sourceSkuId : $barcode) . '-' . $profile['code'];
            $newId = receipt_unique_sku_id($skus, $baseId);
            $newSku['id'] = $newId;
            $newSku['sku'] = $newId;
            $newSku['productId'] = $productId;
            $newSku['stock'] = 0;
            $newSku['createdAt'] = date(DATE_ATOM);
            $skus[] = $newSku;
            $targetIndex = count($skus) - 1;
        }
        $incomingBarcode = $barcode;
        $stableBarcode = receipt_first_text([
            $skus[$targetIndex]['officialBarcode'] ?? '',
            $skus[$targetIndex]['companyBarcode'] ?? '',
            $skus[$targetIndex]['barcode'] ?? '',
            $skus[$targetIndex]['legacyBarcode'] ?? '',
        ]);
        if ($stableBarcode === '' && is_array($sourceSku) && receipt_same_variant($sourceSku, $productId, $color, $size)) {
            $stableBarcode = receipt_first_text([
                $sourceSku['officialBarcode'] ?? '',
                $sourceSku['companyBarcode'] ?? '',
                $sourceSku['barcode'] ?? '',
                $sourceSku['legacyBarcode'] ?? '',
            ]);
        }
        if (receipt_is_canonical_company_barcode($incomingBarcode) || receipt_is_v2_label_barcode($incomingBarcode)) $barcode = $incomingBarcode;
        elseif ($stableBarcode !== '') $barcode = $stableBarcode;
        $unitCost = max(0, (float)($line['unitCostTwd'] ?? 0));
        $handlingPerItemTwd = max(0, round((float)($freight['handlingPerItemTwd'] ?? 0), 2));
        if ($freight['fixedTwdCost']) {
            // existingTwdCost is the merchandise base only.  Required
            // warehouse labour remains an additional cost for every piece.
            $baseCostTwd = $freight['existingTwdCost'] > 0 ? round((float)$freight['existingTwdCost'], 2) : round($unitCost, 2);
            $extraCostTwd = $handlingPerItemTwd;
            $unitCost = round($baseCostTwd + $extraCostTwd, 2);
        } elseif ($freight['costRmb'] > 0) {
            $unitCost = round($freight['costRmb'] * 5, 2);
            if ($freight['costMode'] === 'haohong_weight' && $freight['amountTwd'] <= 0) $unitCost += round($freight['weightKg'] * 8 * 5, 2);
            elseif ($freight['costMode'] !== 'self_pickup') $unitCost += $freight['allocationPerItemTwd'];
            $unitCost += $handlingPerItemTwd;
        }
        if (!$freight['fixedTwdCost']) {
            $baseCostTwd = $unitCost;
            $extraCostTwd = 0.0;
            if ($freight['costMode'] === 'haohong_weight' && $freight['amountTwd'] <= 0) $extraCostTwd = round($freight['weightKg'] * 8 * 5, 2);
            elseif ($freight['costMode'] === 'self_pickup') $extraCostTwd = 0.0;
            else $extraCostTwd = round((float)$freight['allocationPerItemTwd'], 2);
            $extraCostTwd += $handlingPerItemTwd;
            if ($freight['costRmb'] > 0) $baseCostTwd = round($freight['costRmb'] * 5, 2);
            else $baseCostTwd = max(0, round($unitCost - $extraCostTwd, 2));
        }
        if ($unitCost <= 0) {
            $baseCostTwd = 0.0;
            $extraCostTwd = 0.0;
        } elseif ($baseCostTwd + $extraCostTwd > $unitCost + 0.01) {
            $extraCostTwd = max(0, round($unitCost - $baseCostTwd, 2));
        }
        $freightCostSnapshot = [
            'forwarder' => receipt_text($freight['forwarder'] ?? ''),
            'trackingNo' => receipt_text($freight['trackingNo'] ?? ''),
            'itemId' => receipt_text($freight['itemId'] ?? ''),
            'batchId' => receipt_text($freight['batchId'] ?? ''),
            'costMode' => receipt_text($freight['costMode'] ?? ''),
            'warehouseTaxType' => receipt_text($freight['warehouseTaxType'] ?? ''),
            'chargeType' => receipt_text($freight['chargeType'] ?? ''),
            'destinationWarehouse' => receipt_text($freight['destinationWarehouse'] ?? ''),
            'destinationSite' => receipt_text($freight['destinationSite'] ?? ''),
            'handlingPerItemTwd' => round((float)($freight['handlingPerItemTwd'] ?? 0), 2),
            'baseCostTwd' => round($baseCostTwd, 2),
            'extraCostTwd' => round($extraCostTwd, 2),
            'finalCostTwd' => round($unitCost, 2),
            'verifiedFromFreightData' => !empty($freight['verifiedFromFreightData']),
            'capturedAt' => date(DATE_ATOM),
        ];
        // Barcode cost is a permanent label identity. Replenishment cost below
        // may change currentCostTwd and the weighted-cost ledger, but it must
        // never rewrite the P segment or its first-cost fields.
        $fixedBarcodeCost = isset($skus[$targetIndex]['barcodeCostTwd']) && is_numeric($skus[$targetIndex]['barcodeCostTwd']) && (float)$skus[$targetIndex]['barcodeCostTwd'] > 0
            ? (float)$skus[$targetIndex]['barcodeCostTwd']
            : (isset($skus[$targetIndex]['initialBarcodeCostTwd']) && is_numeric($skus[$targetIndex]['initialBarcodeCostTwd']) && (float)$skus[$targetIndex]['initialBarcodeCostTwd'] > 0
                ? (float)$skus[$targetIndex]['initialBarcodeCostTwd']
                : null);
        if ($fixedBarcodeCost === null && is_array($sourceSku)) {
            foreach (['barcodeCostTwd', 'initialBarcodeCostTwd'] as $costField) {
                if (isset($sourceSku[$costField]) && is_numeric($sourceSku[$costField]) && (float)$sourceSku[$costField] > 0) {
                    $fixedBarcodeCost = (float)$sourceSku[$costField];
                    break;
                }
            }
        }
        if ($fixedBarcodeCost === null && isset($line['barcodeCostTwd']) && is_numeric($line['barcodeCostTwd']) && (float)$line['barcodeCostTwd'] > 0) {
            $fixedBarcodeCost = (float)$line['barcodeCostTwd'];
        }
        if ($fixedBarcodeCost === null) $fixedBarcodeCost = receipt_barcode_initial_cost($barcode);
        if ($fixedBarcodeCost === null && $unitCost > 0) $fixedBarcodeCost = round($unitCost, 2);
        if ($fixedBarcodeCost !== null && $fixedBarcodeCost > 0) {
            $skus[$targetIndex]['barcodeCostTwd'] = round($fixedBarcodeCost, 2);
            $skus[$targetIndex]['initialBarcodeCostTwd'] = round($fixedBarcodeCost, 2);
            if (receipt_text($skus[$targetIndex]['barcodeCostSource'] ?? '') === '') $skus[$targetIndex]['barcodeCostSource'] = 'first_barcode_cost_fixed';
            if (receipt_text($skus[$targetIndex]['barcodeCostRecordedAt'] ?? '') === '') $skus[$targetIndex]['barcodeCostRecordedAt'] = $freightCostSnapshot['capturedAt'];
        }
        $skus[$targetIndex]['barcode'] = $barcode;
        $skus[$targetIndex]['companyBarcode'] = $barcode;
        $skus[$targetIndex]['officialBarcode'] = $barcode;
        if ($stableBarcode !== '' && strtoupper((string)$stableBarcode) !== strtoupper((string)$barcode)
            && receipt_text($skus[$targetIndex]['legacyBarcode'] ?? '') === '') {
            $skus[$targetIndex]['legacyBarcode'] = $stableBarcode;
        }
        $skus[$targetIndex]['linkedBarcodes'] = receipt_merge_sku_barcode_aliases($skus[$targetIndex], [$barcode, $stableBarcode, $incomingBarcode, $line['legacyBarcode'] ?? '']);
        $aliasExtra = [$barcode, $stableBarcode, $incomingBarcode, $line['legacyBarcode'] ?? ''];
        if (is_array($line['barcodeAliases'] ?? null)) $aliasExtra = array_merge($aliasExtra, $line['barcodeAliases']);
        $existingAliases = is_array($skus[$targetIndex]['barcodeAliases'] ?? null) ? $skus[$targetIndex]['barcodeAliases'] : [];
        foreach ($aliasExtra as $aliasValue) {
            $aliasText = trim((string)$aliasValue);
            if ($aliasText !== '' && !in_array($aliasText, $existingAliases, true)) $existingAliases[] = $aliasText;
        }
        $skus[$targetIndex]['barcodeAliases'] = array_values($existingAliases);
        $skus[$targetIndex]['productId'] = $productId;
        $oldColor = receipt_first_text([$skus[$targetIndex]['colorName'] ?? '', $skus[$targetIndex]['color'] ?? '']);
        if ($oldColor !== '' && $oldColor !== $color && receipt_text($skus[$targetIndex]['legacyColorName'] ?? '') === '') {
            $skus[$targetIndex]['legacyColorName'] = $oldColor;
        }
        $skus[$targetIndex]['color'] = $color;
        $skus[$targetIndex]['colorName'] = $color;
        $skus[$targetIndex]['size'] = $size;
        $skus[$targetIndex]['sizeName'] = $size;
        if ($productCode !== '') $skus[$targetIndex]['productCode'] = $productCode;
        if (function_exists('lz_barcode_compose')) {
            $composeColor = receipt_first_text([
                $skus[$targetIndex]['colorCode'] ?? '',
                $skus[$targetIndex]['colorNo'] ?? '',
                $line['colorCode'] ?? '',
                function_exists('lz_barcode_color_from_name') ? lz_barcode_color_from_name($color) : '',
            ]);
            $composeCost = $fixedBarcodeCost !== null && $fixedBarcodeCost > 0 ? $fixedBarcodeCost : $unitCost;
            $canonicalBarcode = lz_barcode_compose($productCode, $composeColor, $size, $composeCost);
            if ($canonicalBarcode !== '') {
                if (strtoupper((string)$barcode) !== $canonicalBarcode) {
                    $existingAliases[] = $barcode;
                    if ($stableBarcode !== '') $existingAliases[] = $stableBarcode;
                    $skus[$targetIndex]['legacyBarcode'] = receipt_first_text([
                        $skus[$targetIndex]['legacyBarcode'] ?? '', $barcode, $stableBarcode,
                    ]);
                    $skus[$targetIndex]['barcodeAliases'] = array_values(array_unique(array_filter($existingAliases)));
                }
                $barcode = $canonicalBarcode;
                $skus[$targetIndex]['barcode'] = $canonicalBarcode;
                $skus[$targetIndex]['companyBarcode'] = $canonicalBarcode;
                $skus[$targetIndex]['officialBarcode'] = $canonicalBarcode;
                $skus[$targetIndex]['labelBarcode'] = $canonicalBarcode;
                if ($composeColor !== '') {
                    $skus[$targetIndex]['colorCode'] = $composeColor;
                    $skus[$targetIndex]['colorNo'] = $composeColor;
                }
                $skus[$targetIndex]['sizeCode'] = lz_barcode_pad_size($size);
            }
        }
        $skus[$targetIndex]['warehouse'] = $profile['name'];
        $skus[$targetIndex]['warehouseName'] = $profile['name'];
        $skus[$targetIndex]['warehouseCode'] = $profile['code'];
        $skus[$targetIndex]['preorderOnly'] = false;
        $internalQty = freight_receive_line_internal_use_qty($line + ['qty' => $qty]);
        // LZ_INTERNAL_USE_DEDUCT_20260924: 公司自用入庫立刻扣掉，不增加現貨／樣品庫存。
        $stockAdd = $goodsPurpose === 'internal_use' ? 0 : max(0, $qty - $internalQty);
        $lineStockPurpose = $goodsPurpose === 'internal_use' ? 'internal_use' : $stockPurpose;
        $isSampleStock = $lineStockPurpose === 'sample';
        if ($stockAdd > 0) {
            $skus[$targetIndex]['stockPurpose'] = $lineStockPurpose;
            $skus[$targetIndex]['sampleStock'] = $isSampleStock;
            $skus[$targetIndex]['productMode'] = $profile['code'] === 'CN' ? 'china_sample' : ($isSampleStock ? 'warehouse_sample' : 'ready');
            $skus[$targetIndex]['storefrontManualHidden'] = false;
            $skus[$targetIndex]['showOnWebsite'] = !$isSampleStock && $profile['code'] === 'TW';
        }
        $skus[$targetIndex]['chinaSample'] = $profile['code'] === 'CN';
        $skus[$targetIndex]['stock'] = max(0, (int)($skus[$targetIndex]['stock'] ?? 0)) + $stockAdd;
        if ($unitCost > 0) {
            $currentCost = round($unitCost, 2);
            $costEffectiveAt = $freightCostSnapshot['capturedAt'];
            /*
             * The P barcode and barcodeCostTwd remain the immutable first-cost
             * identity. Receiving is the authoritative current-cost event, so
             * every copy of the same variant must see the new effective cost
             * even when stock sits in another warehouse. Warehouse-specific
             * freight details remain on the received SKU below.
            */
            foreach ($skus as &$costSku) {
                if (!is_array($costSku) || receipt_text($costSku['productId'] ?? '') !== $productId) continue;
                if (receipt_same_variant($costSku, $productId, $color, $size)) {
                    $costSku['cost'] = $currentCost;
                    $costSku['currentCostTwd'] = $currentCost;
                    $costSku['costSource'] = 'purchase_receipt';
                    $costSku['costEffectiveAt'] = $costEffectiveAt;
                    $costSku['currentCostUpdatedAt'] = $costEffectiveAt;
                    $costSku['costUpdatedAt'] = $costEffectiveAt;
                    $costSku['costSyncedAt'] = $costEffectiveAt;
                    $costSku['costVersion'] = max(0, (int)($costSku['costVersion'] ?? 0)) + 1;
                }
                if (is_array($masterProduct) && !zhangzhang_is_computer_record($masterProduct) && is_array($costSku)) {
                    // Latest received cost is the selling-price basis for the
                    // whole product line, including older remaining stock.
                    zhangzhang_apply_pricing($costSku, $currentCost, $costEffectiveAt);
                }
            }
            unset($costSku);
        }
        $skus[$targetIndex]['freightForwarder'] = $freightCostSnapshot['forwarder'];
        $skus[$targetIndex]['freightTrackingNo'] = $freightCostSnapshot['trackingNo'];
        $skus[$targetIndex]['freightItemId'] = $freightCostSnapshot['itemId'];
        $skus[$targetIndex]['freightBatchId'] = $freightCostSnapshot['batchId'];
        $skus[$targetIndex]['freightCostMode'] = $freightCostSnapshot['costMode'];
        $skus[$targetIndex]['freightWarehouseTaxType'] = $freightCostSnapshot['warehouseTaxType'];
        $skus[$targetIndex]['freightChargeType'] = $freightCostSnapshot['chargeType'];
        $skus[$targetIndex]['freightBaseCostTwd'] = $freightCostSnapshot['baseCostTwd'];
        $skus[$targetIndex]['freightExtraCostTwd'] = $freightCostSnapshot['extraCostTwd'];
        $skus[$targetIndex]['freightFinalCostTwd'] = $freightCostSnapshot['finalCostTwd'];
        $skus[$targetIndex]['freightCostSourceStatus'] = $freightCostSnapshot['verifiedFromFreightData'] ? 'verified' : 'manual';
        $skus[$targetIndex]['freightCostUpdatedAt'] = $freightCostSnapshot['capturedAt'];
        if ($arrivalImage !== '') {
            $skus[$targetIndex]['lastArrivalImage'] = $arrivalImage;
            $skus[$targetIndex]['colorImage'] = $arrivalImage;
        } elseif ($colorImage !== '') {
            $skus[$targetIndex]['colorImage'] = $colorImage;
        }
        if (!isset($skus[$targetIndex]['freightCostLots']) || !is_array($skus[$targetIndex]['freightCostLots'])) $skus[$targetIndex]['freightCostLots'] = [];
        if ($stockAdd > 0) array_unshift($skus[$targetIndex]['freightCostLots'], $freightCostSnapshot + ['qty' => $stockAdd, 'warehouse' => $profile['code']]);
        $skus[$targetIndex]['freightCostLots'] = array_slice($skus[$targetIndex]['freightCostLots'], 0, 50);
        $skus[$targetIndex]['updatedAt'] = date(DATE_ATOM);
        $receivedSkuId = receipt_first_text([$skus[$targetIndex]['id'] ?? '', $skus[$targetIndex]['sku'] ?? '']);
        $item['skuId'] = $receivedSkuId;
        $item['sku'] = $receivedSkuId;
        $item['productId'] = $productId;
        $item['code'] = $productCode;
        $item['title'] = $productName !== '' ? $productName : $productCode;
        $item['category'] = $category;
        $item['color'] = $color;
        $item['size'] = $size;
        $item['qty'] = $qty;
        $item['receivedBarcode'] = $barcode;
        $item['receivedWarehouse'] = $profile['code'];
        $item['receivedUnitCostTwd'] = round($unitCost, 2);
        $item['freightCostSnapshot'] = $freightCostSnapshot;
        if ($arrivalImage !== '') $item['arrivalImage'] = $arrivalImage;
        if ($arrivalViews) $item['arrivalViews'] = $arrivalViews;
        $lineSellableQty = $goodsPurpose === 'internal_use' ? 0 : max(0, $qty - $internalQty);
        $lineGoodsPurpose = ($internalQty > 0 && $lineSellableQty > 0) ? 'mixed' : (($internalQty > 0 && $lineSellableQty <= 0) || $goodsPurpose === 'internal_use' ? 'internal_use' : 'sellable');
        $replacementAlts = [];
        foreach (['back', 'left', 'right'] as $altKey) {
            $altImg = receipt_text($arrivalViews[$altKey] ?? '');
            if ($altImg !== '') $replacementAlts[] = $altImg;
        }
        $receiptLine = ['itemIndex' => $index, 'skuId' => $receivedSkuId, 'productId' => $productId, 'productName' => $productName, 'category' => $category, 'productCode' => $productCode, 'createdProduct' => $createdThisLine, 'barcode' => $barcode, 'color' => $color, 'size' => $size, 'qty' => $qty, 'internalUseQty' => $internalQty, 'sellableQty' => $lineSellableQty, 'warehouse' => $profile['code'], 'stockPurpose' => $lineStockPurpose, 'goodsPurpose' => $lineGoodsPurpose, 'unitCostTwd' => round($unitCost, 2), 'costSource' => $unitCost > 0 ? 'purchase_receipt' : '', 'costEffectiveAt' => $unitCost > 0 ? $freightCostSnapshot['capturedAt'] : '', 'arrivalImage' => $arrivalImage, 'colorImage' => $colorImage, 'arrivalViews' => $arrivalViews, 'replacementAlts' => $replacementAlts, 'proofRequired' => $proofRequired, 'freightCostSnapshot' => $freightCostSnapshot, 'inquiryId' => (string)($inquiry['id'] ?? ''), 'payloadHash' => $payloadHash, 'appliedAt' => $freightCostSnapshot['capturedAt']];
        if ($operationId !== '') {
            $receiptLine['operationContext'] = [
                'receivedBy' => receipt_text($payload['receivedBy'] ?? $payload['employeeName'] ?? ''),
                'platformPublishAllowed' => !empty($payload['platformPublishAllowed']),
                'receivingDocument' => isset($payload['receivingDocument']) && is_array($payload['receivingDocument']) ? $payload['receivingDocument'] : [],
            ];
        }
        $receiptLog[] = $receiptLine;
        if ($operationId !== '') {
            $marker = inventory_transaction_marker($skus[$targetIndex], $operationId) ?? [
                'kind' => 'preorder_receive',
                'inquiryId' => (string)($inquiry['id'] ?? ''),
                'payloadHash' => $payloadHash,
                'appliedAt' => $freightCostSnapshot['capturedAt'],
                'qtyDelta' => 0,
                'lines' => [],
            ];
            $marker['qtyDelta'] = (int)($marker['qtyDelta'] ?? 0) + $stockAdd;
            $marker['lines'][] = $receiptLine;
            set_inventory_transaction_marker($skus[$targetIndex], $operationId, $marker);
        }
    }
    unset($item);
    $productsChanged = $productsCreated || sync_preorder_receipt_products($products, $receiptLog, $operationId);
    // The stock delta and its idempotency marker are the commit point and therefore
    // must be written before any derived product/admin-state mirrors.
    write_json($skusFile, $skus);
    if ($productsChanged) write_json($productsFile, $products);
    if ($operationId !== '') {
        sync_preorder_inventory_state($stateFile, $skus, $products, $operationId, [
            'kind' => 'preorder_receive',
            'inquiryId' => (string)($inquiry['id'] ?? ''),
            'payloadHash' => $payloadHash,
            'appliedAt' => (string)($receiptLog[0]['appliedAt'] ?? date(DATE_ATOM)),
            'skuIds' => array_values(array_unique(array_map(static fn($line) => (string)($line['skuId'] ?? ''), $receiptLog))),
            'productIds' => array_values(array_unique(array_filter(array_map(static fn($line) => (string)($line['productId'] ?? ''), $receiptLog)))),
        ]);
    } else {
        $state = read_json($stateFile);
        $state['skus'] = $skus;
        if ($productsChanged) $state['products'] = $products;
        $restoredProductIds = array_values(array_unique(array_filter(array_map(static fn($line) => (string)($line['productId'] ?? ''), $receiptLog))));
        if ($restoredProductIds && isset($state['deletedProductIds']) && is_array($state['deletedProductIds'])) {
            $state['deletedProductIds'] = array_values(array_filter(
                $state['deletedProductIds'],
                static fn($deletedId) => !in_array(trim((string)$deletedId), $restoredProductIds, true)
            ));
        }
        $state['updatedAt'] = date(DATE_ATOM);
        write_json($stateFile, $state);
    }
    @unlink($GLOBALS['dataDir'] . DIRECTORY_SEPARATOR . 'public-catalog.json');
    return ['items' => $items, 'freight' => $freight, 'receiptLog' => $receiptLog, 'warehouse' => $profile, 'operationId' => $operationId, 'idempotentReplay' => false];
}

function freight_product_receive_warehouse_code($value): string {
    $raw = strtoupper(trim((string)($value ?? '')));
    if (in_array($raw, ['TW', 'TAIWAN', 'TW_BAOHUI', 'BAOHUI'], true) || strpos($raw, '台灣') !== false || strpos($raw, '寶輝') !== false) return 'TW';
    if (in_array($raw, ['CN', 'CHINA', 'CN_DONGGUAN', 'DONGGUAN'], true) || strpos($raw, '中國') !== false || strpos($raw, '東莞') !== false) return 'CN';
    if (in_array($raw, ['ID', 'INDONESIA'], true) || strpos($raw, '印尼') !== false) return 'ID';
    return '';
}

function freight_product_receive_formal_product_id($value): string {
    $productId = trim((string)($value ?? ''));
    return stripos($productId, 'freight-forecast:') === 0 ? '' : $productId;
}

function freight_product_receive_item_index(array $freightData, string $freightItemId): int {
    $items = isset($freightData['items']) && is_array($freightData['items']) ? $freightData['items'] : [];
    foreach ($items as $index => $item) {
        if (!is_array($item)) continue;
        if (receipt_text($item['id'] ?? '') === $freightItemId || receipt_text($item['sourceItemId'] ?? '') === $freightItemId) return (int)$index;
    }
    return -1;
}

function freight_product_receive_linked_orders(array $freightItem): array {
    $candidates = isset($freightItem['customerLinks']) && is_array($freightItem['customerLinks'])
        ? array_values($freightItem['customerLinks'])
        : [];
    $legacyInquiryId = receipt_text($freightItem['inquiryId'] ?? '');
    $legacyOrderNo = receipt_first_text([$freightItem['customerOrderNo'] ?? '', $legacyInquiryId]);
    if (!$candidates && ($legacyInquiryId !== '' || $legacyOrderNo !== '')) {
        $candidates[] = [
            'inquiryId' => $legacyInquiryId,
            'customerOrderNo' => $legacyOrderNo,
            'lineId' => receipt_text($freightItem['inquiryLineId'] ?? ''),
            'itemIndex' => max(0, (int)($freightItem['inquiryItemIndex'] ?? 0)),
            'qty' => max(1, (int)($freightItem['purchaseOffsetQty'] ?? 1)),
        ];
    }
    $links = [];
    $seen = [];
    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) continue;
        $candidateStatus = mb_strtolower(receipt_text($candidate['status'] ?? ''), 'UTF-8');
        if ($candidateStatus !== '' && preg_match('/cancel|release|取消|解除/u', $candidateStatus)) continue;
        $inquiryId = receipt_first_text([$candidate['inquiryId'] ?? '', $candidate['orderId'] ?? '']);
        $orderNo = receipt_first_text([$candidate['customerOrderNo'] ?? '', $candidate['orderNo'] ?? '', $inquiryId]);
        if ($inquiryId === '' && $orderNo === '') continue;
        $lineId = receipt_text($candidate['lineId'] ?? '');
        $hasItemIndex = array_key_exists('itemIndex', $candidate);
        $itemIndex = $hasItemIndex ? max(0, (int)$candidate['itemIndex']) : null;
        $qty = max(1, (int)($candidate['qty'] ?? 1));
        $key = implode('|', [$inquiryId, $orderNo, $lineId, $itemIndex === null ? '' : (string)$itemIndex]);
        if (isset($seen[$key])) {
            $links[$seen[$key]]['qty'] += $qty;
            continue;
        }
        $seen[$key] = count($links);
        $links[] = [
            'inquiryId' => $inquiryId,
            'customerOrderNo' => $orderNo,
            'lineId' => $lineId,
            'itemIndex' => $itemIndex,
            'variantIndex' => array_key_exists('variantIndex', $candidate) ? max(0, (int)$candidate['variantIndex']) : null,
            'barcode' => receipt_first_text([$candidate['barcode'] ?? '', $candidate['sampleBarcode'] ?? '', $candidate['taiwanBarcode'] ?? '']),
            'productCode' => receipt_text($candidate['productCode'] ?? ''),
            'color' => receipt_first_text([$candidate['color'] ?? '', $candidate['colorName'] ?? '']),
            'size' => receipt_first_text([$candidate['size'] ?? '', $candidate['sizeName'] ?? 'NO SIZE']) ?: 'NO SIZE',
            'qty' => $qty,
            'customerName' => receipt_first_text([$candidate['customerName'] ?? '', $candidate['name'] ?? '']),
            'customerPhone' => receipt_first_text([$candidate['customerPhone'] ?? '', $candidate['phone'] ?? '']),
            'orderDay' => receipt_first_text([$candidate['orderDay'] ?? '', $candidate['purchaseDate'] ?? '']),
            'approvedAt' => receipt_text($candidate['approvedAt'] ?? ''),
        ];
    }
    usort($links, static function ($left, $right): int {
        $leftFifo = implode('|', [(string)($left['orderDay'] ?? ''), (string)($left['approvedAt'] ?? ''), (string)($left['inquiryId'] ?? ''), (string)($left['lineId'] ?? ''), (string)($left['itemIndex'] ?? '')]);
        $rightFifo = implode('|', [(string)($right['orderDay'] ?? ''), (string)($right['approvedAt'] ?? ''), (string)($right['inquiryId'] ?? ''), (string)($right['lineId'] ?? ''), (string)($right['itemIndex'] ?? '')]);
        return strcmp($leftFifo, $rightFifo);
    });
    return $links;
}

function freight_receive_variant_token($value, bool $size = false): string {
    $text = mb_strtoupper(trim((string)($value ?? '')), 'UTF-8');
    $text = preg_replace('/\s+/u', '', $text) ?? $text;
    if ($size && in_array($text, ['FREE', 'FREESIZE', '均碼', '無尺寸', '未帶尺寸', '未設定尺寸', '未设定尺寸', 'NONE', 'UNKNOWN', 'NOSIZE', 'NO-SIZE'], true)) return 'NOSIZE';
    return $text;
}

function freight_receive_color_token($value): string {
    $text = mb_strtoupper(trim((string)($value ?? '')), 'UTF-8');
    $text = str_replace(['（', '）'], ['(', ')'], $text);
    $compact = preg_replace('/[\s_\-\/()]+/u', '', $text) ?? $text;
    if ($compact === '') return '';

    // 同一顏色在舊訂單、正式 SKU 與物流資料中可能分別使用中文、
    // 印尼文或雙語名稱。FIFO 配貨只比較顏色意義，不比較顯示格式。
    $aliases = [
        'NAVY' => ['深藍', '深蓝', 'BIRUTUA', 'NAVY'],
        'PINK' => ['粉紅色', '粉红色', '粉紅', '粉红', 'PINK'],
        'PURPLE' => ['紫色', 'UNGU', 'PURPLE'],
        'BLACK' => ['黑色', 'HITEM', 'HITAM', 'BLACK'],
        'WHITE' => ['白色', 'PUTI', 'PUTIH', 'WHITE'],
        'RED' => ['紅色', '红色', 'MERAL', 'MERAH', 'RED'],
        'YELLOW' => ['黃色', '黄色', 'KUR', 'KUNING', 'YELLOW'],
        'GREEN' => ['綠色', '绿色', 'HIGAU', 'HIJAU', 'GREEN'],
        'BLUE' => ['藍色', '蓝色', 'BIRU', 'BLUE'],
        'GRAY' => ['灰色', 'ABU', 'GREY', 'GRAY'],
        'BROWN' => ['咖啡色', '咖色', '棕色', 'COKELAT', 'BROWN'],
        'KHAKI' => ['卡其色', '卡其', 'DRIL', 'KHAKI'],
    ];
    foreach ($aliases as $canonical => $needles) {
        foreach ($needles as $needle) {
            if (mb_strpos($compact, mb_strtoupper($needle, 'UTF-8')) !== false) return $canonical;
        }
    }
    return $compact;
}

function freight_receive_barcode_token($value): string {
    $text = mb_strtoupper(trim((string)($value ?? '')), 'UTF-8');
    return preg_replace('/[\s\-]+/u', '', $text) ?? $text;
}

function freight_receive_link_matches_line(array $link, array $line): bool {
    $linkCode = freight_receive_barcode_token($link['productCode'] ?? '');
    $lineCode = freight_receive_barcode_token($line['productCode'] ?? '');
    if ($linkCode !== '' && $lineCode !== '' && $linkCode !== $lineCode) return false;
    $linkBarcode = freight_receive_barcode_token($link['barcode'] ?? '');
    $lineBarcode = freight_receive_barcode_token($line['barcode'] ?? '');
    if ($linkBarcode !== '' && $lineBarcode !== '' && $linkBarcode !== $lineBarcode) return false;
    $linkColor = freight_receive_color_token($link['color'] ?? '');
    $lineColor = freight_receive_color_token($line['color'] ?? '');
    if ($linkColor !== '' && $lineColor !== '' && $linkColor !== $lineColor) return false;
    $linkSize = freight_receive_variant_token($link['size'] ?? 'NO SIZE', true);
    $lineSize = freight_receive_variant_token($line['size'] ?? 'NO SIZE', true);
    if ($linkSize !== '' && $lineSize !== '' && $linkSize !== $lineSize) return false;
    $variantMatches = $link['variantIndex'] !== null
        && (int)$link['variantIndex'] === (int)($line['sourceLineIndex'] ?? -1);
    return ($linkBarcode !== '' && $lineBarcode !== '')
        || ($linkColor !== '' && $lineColor !== '' && $linkSize !== '' && $lineSize !== '')
        || $variantMatches;
}

function freight_receive_customer_priority_key(array $link): string {
    return implode('|', [
        receipt_first_text([$link['inquiryId'] ?? '', $link['customerOrderNo'] ?? '']),
        receipt_text($link['lineId'] ?? ''),
        array_key_exists('itemIndex', $link) && $link['itemIndex'] !== null ? (string)max(0, (int)$link['itemIndex']) : '',
    ]);
}

function freight_receive_identity_base($value): string {
    $token = freight_receive_barcode_token($value);
    if ($token === '') return '';
    return preg_replace('/P\d+$/u', '', $token) ?? $token;
}

function freight_receive_demand_matches_line(array $demand, array $line): bool {
    $demandIdentities = array_values(array_unique(array_filter([
        freight_receive_barcode_token($demand['skuId'] ?? ''),
        freight_receive_barcode_token($demand['productId'] ?? ''),
        freight_receive_barcode_token($demand['code'] ?? ''),
        freight_receive_identity_base($demand['skuId'] ?? ''),
        freight_receive_identity_base($demand['code'] ?? ''),
    ])));
    $lineIdentities = array_values(array_unique(array_filter([
        freight_receive_barcode_token($line['skuId'] ?? ''),
        freight_receive_barcode_token($line['productId'] ?? ''),
        freight_receive_barcode_token($line['productCode'] ?? ''),
        freight_receive_barcode_token($line['barcode'] ?? ''),
        freight_receive_identity_base($line['productCode'] ?? ''),
        freight_receive_identity_base($line['barcode'] ?? ''),
    ])));
    if (!$demandIdentities || !$lineIdentities || !array_intersect($demandIdentities, $lineIdentities)) return false;
    $demandColor = freight_receive_color_token($demand['color'] ?? '');
    $lineColor = freight_receive_color_token($line['color'] ?? '');
    if ($demandColor === '' || $lineColor === '' || $demandColor !== $lineColor) return false;
    $demandSize = freight_receive_variant_token($demand['size'] ?? 'NO SIZE', true);
    $lineSize = freight_receive_variant_token($line['size'] ?? 'NO SIZE', true);
    return $demandSize !== '' && $lineSize !== '' && $demandSize === $lineSize;
}

function freight_receive_fifo_expand_links(array $freightData, array $freightItem, array $cleanLines, array $inquiries): array {
    $existingLinks = freight_product_receive_linked_orders($freightItem);
    $queueExcluded = [];
    $warehouseReservedByItem = [];
    foreach ($inquiries as $inquiry) {
        if (!is_array($inquiry)) continue;
        $inquiryId = receipt_text($inquiry['id'] ?? '');
        $decision = strtolower(receipt_text($inquiry['freightCustomerQueueDecision'] ?? ''));
        if ($inquiryId !== '' && in_array($decision, ['deferred', 'cancelled'], true)) $queueExcluded[$inquiryId] = true;
        foreach (is_array($inquiry['items'] ?? null) ? $inquiry['items'] : [] as $itemIndex => $reservedItem) {
            if (!is_array($reservedItem) || ($reservedItem['priorityAllocationStatus'] ?? '') !== 'warehouse_reserved') continue;
            $warehouseReservedByItem[$inquiryId . '|' . (int)$itemIndex] = max(0, (int)($reservedItem['freightReceivedQty'] ?? 0));
        }
    }
    $reservedByDemand = [];
    foreach (is_array($freightData['items'] ?? null) ? $freightData['items'] : [] as $candidateItem) {
        if (!is_array($candidateItem)) continue;
        foreach (freight_product_receive_linked_orders($candidateItem) as $link) {
            $key = implode('|', [
                receipt_first_text([$link['inquiryId'] ?? '', $link['customerOrderNo'] ?? '']),
                receipt_text($link['lineId'] ?? ''),
                array_key_exists('itemIndex', $link) && $link['itemIndex'] !== null ? (string)max(0, (int)$link['itemIndex']) : '',
            ]);
            $reservedByDemand[$key] = max(0, (int)($reservedByDemand[$key] ?? 0)) + max(0, (int)($link['qty'] ?? 0));
        }
    }
    $remainingByLine = [];
    foreach ($cleanLines as $lineIndex => $line) $remainingByLine[$lineIndex] = freight_receive_line_sellable_qty($line);
    foreach ($existingLinks as $link) {
        $wantedQty = max(0, (int)($link['qty'] ?? 0));
        foreach ($cleanLines as $lineIndex => $line) {
            if ($wantedQty <= 0) break;
            if (($remainingByLine[$lineIndex] ?? 0) <= 0 || !freight_receive_link_matches_line($link, $line)) continue;
            $take = min($wantedQty, $remainingByLine[$lineIndex]);
            $remainingByLine[$lineIndex] -= $take;
            $wantedQty -= $take;
        }
    }
    $added = [];
    foreach (lz_purchase_demand_lines($inquiries) as $demand) {
        if (!is_array($demand) || !freight_match_line_is_open($demand)) continue;
        if (isset($queueExcluded[receipt_text($demand['inquiryId'] ?? '')])) continue;
        $key = implode('|', [
            receipt_text($demand['inquiryId'] ?? ''),
            receipt_text($demand['lineId'] ?? ''),
            (string)max(0, (int)($demand['itemIndex'] ?? 0)),
        ]);
        $alreadyReserved = max(
            0,
            (int)($reservedByDemand[$key] ?? 0),
            (int)($warehouseReservedByItem[receipt_text($demand['inquiryId'] ?? '') . '|' . (int)($demand['itemIndex'] ?? 0)] ?? 0)
        );
        $pendingQty = max(0, (int)($demand['requestedQty'] ?? 0) - $alreadyReserved);
        if ($pendingQty <= 0) continue;
        foreach ($cleanLines as $lineIndex => $line) {
            if ($pendingQty <= 0) break;
            if (($remainingByLine[$lineIndex] ?? 0) <= 0 || !freight_receive_demand_matches_line($demand, $line)) continue;
            $take = min($pendingQty, $remainingByLine[$lineIndex]);
            $added[] = [
                'inquiryId' => receipt_text($demand['inquiryId'] ?? ''),
                'customerOrderNo' => receipt_text($demand['inquiryId'] ?? ''),
                'lineId' => receipt_text($demand['lineId'] ?? ''),
                'itemIndex' => max(0, (int)($demand['itemIndex'] ?? 0)),
                'variantIndex' => max(0, (int)($line['sourceLineIndex'] ?? $lineIndex)),
                'barcode' => receipt_text($line['barcode'] ?? ''),
                'productCode' => receipt_first_text([$line['productCode'] ?? '', $demand['code'] ?? '']),
                'color' => receipt_text($line['color'] ?? ''),
                'size' => receipt_first_text([$line['size'] ?? '', 'NO SIZE']),
                'qty' => $take,
                'customerName' => receipt_text($demand['customerName'] ?? ''),
                'customerPhone' => receipt_text($demand['customerPhone'] ?? ''),
                'orderDay' => receipt_text($demand['orderDay'] ?? ''),
                'approvedAt' => date(DATE_ATOM),
                'status' => 'reserved',
                'allocationMode' => 'receiving_fifo_actual_quantity',
            ];
            $remainingByLine[$lineIndex] -= $take;
            $pendingQty -= $take;
            $reservedByDemand[$key] = max(0, (int)($reservedByDemand[$key] ?? 0)) + $take;
        }
        if (array_sum($remainingByLine) <= 0) break;
    }
    return [
        'links' => array_merge($existingLinks, $added),
        'addedLinks' => $added,
        'addedQty' => array_reduce($added, static fn($sum, $link) => $sum + max(0, (int)($link['qty'] ?? 0)), 0),
    ];
}

function freight_receive_merge_selected_links(array $existing, array $selected): array {
    $merged = freight_product_receive_linked_orders(['customerLinks' => $existing]);
    $indexByKey = [];
    foreach ($merged as $index => $link) $indexByKey[freight_receive_customer_priority_key($link)] = $index;
    foreach ($selected as $link) {
        if (!is_array($link)) continue;
        $key = freight_receive_customer_priority_key($link);
        if ($key === '') continue;
        if (!array_key_exists($key, $indexByKey)) {
            $indexByKey[$key] = count($merged);
            $merged[] = $link;
            continue;
        }
        $index = $indexByKey[$key];
        if (max(0, (int)($link['qty'] ?? 0)) > max(0, (int)($merged[$index]['qty'] ?? 0))) {
            $merged[$index] = array_merge($merged[$index], $link);
        }
    }
    return $merged;
}

function freight_receive_allocate_linked_orders(array $freightItem, array $cleanLines, string $allocationMode, bool $confirmReleaseCustomerReservations = false, string $priorityCustomerKey = ''): array {
    $allLinks = freight_product_receive_linked_orders($freightItem);
    $priorityCustomerKey = trim($priorityCustomerKey);
    if ($priorityCustomerKey !== '') {
        $priorityIndex = null;
        foreach ($allLinks as $index => $link) {
            if (freight_receive_customer_priority_key($link) === $priorityCustomerKey) {
                $priorityIndex = $index;
                break;
            }
        }
        if ($priorityIndex === null) {
            throw new InvalidArgumentException('選擇的優先客戶已不在這筆物流的精準配對名單；請重新開啟並核對');
        }
        $priorityLink = $allLinks[$priorityIndex];
        array_splice($allLinks, $priorityIndex, 1);
        array_unshift($allLinks, $priorityLink);
    }
    $totalActual = array_reduce($cleanLines, static fn($sum, $line) => $sum + max(0, (int)($line['qty'] ?? 0)), 0);
    $totalDemand = array_reduce($allLinks, static fn($sum, $link) => $sum + max(0, (int)($link['qty'] ?? 0)), 0);
    if ($allocationMode === 'company_stock') {
        if ($totalDemand > 0 && !$confirmReleaseCustomerReservations) throw new InvalidArgumentException('這筆已有預購客戶鎖定；若確定改為公司現貨／樣品，必須確認釋放原客戶保留');
        return ['links' => [], 'releasedLinks' => $allLinks, 'allocatedQty' => 0, 'companyQty' => $totalActual, 'demandQty' => $totalDemand];
    }
    $remainingByLine = [];
    foreach ($cleanLines as $lineIndex => $line) $remainingByLine[$lineIndex] = freight_receive_line_sellable_qty($line);
    $selected = [];
    foreach ($allLinks as $link) {
        $wantedQty = max(0, (int)($link['qty'] ?? 0));
        if ($wantedQty <= 0) continue;
        foreach ($cleanLines as $lineIndex => $line) {
            if ($wantedQty <= 0) break;
            if (($remainingByLine[$lineIndex] ?? 0) <= 0 || !freight_receive_link_matches_line($link, $line)) continue;
            $take = min($wantedQty, $remainingByLine[$lineIndex]);
            $allocated = $link;
            $allocated['qty'] = $take;
            $allocated['receivedSourceLineIndex'] = (int)($line['sourceLineIndex'] ?? $lineIndex);
            $selected[] = $allocated;
            $remainingByLine[$lineIndex] -= $take;
            $wantedQty -= $take;
        }
    }
    $allocatedQty = array_reduce($selected, static fn($sum, $link) => $sum + max(0, (int)($link['qty'] ?? 0)), 0);
    $sellableTotal = array_reduce($cleanLines, static fn($sum, $line) => $sum + freight_receive_line_sellable_qty($line), 0);
    if ($allocationMode === 'preorder_only' && $allocatedQty !== $sellableTotal) {
        throw new InvalidArgumentException('全配預購失敗：可賣 ' . $sellableTotal . ' 件，但只有 ' . $allocatedQty . ' 件能依產品編號＋條碼＋顏色＋尺寸精準對到客戶');
    }
    return [
        'links' => $selected,
        'allocatedQty' => $allocatedQty,
        'companyQty' => max(0, $sellableTotal - $allocatedQty),
        'demandQty' => $totalDemand,
        'releasedLinks' => [],
    ];
}

function freight_product_receive_mark_order_arrivals(
    string $inquiriesFile,
    array $linkedOrders,
    string $operationId,
    string $receivedAt,
    string $freightItemId,
    string $trackingNo,
    string $warehouse,
    array $receivedLines = [],
    string $receivingDocumentNo = '',
    array $customerDispatchChoices = []
): array {
    if (!$linkedOrders) return ['updatedOrderCount' => 0, 'fullyReceivedOrderCount' => 0, 'partialOrderCount' => 0, 'readyForConfirmation' => []];
    $lock = fopen($inquiriesFile . '.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) return ['updatedOrderCount' => 0, 'fullyReceivedOrderCount' => 0, 'partialOrderCount' => 0, 'readyForConfirmation' => [], 'warning' => '客戶訂單到貨連結暫時無法鎖定'];
    $rows = read_json($inquiriesFile);
    $dispatchByInquiry = [];
    foreach ($customerDispatchChoices as $choice) {
        if (!is_array($choice)) continue;
        $choiceInquiryId = receipt_text($choice['inquiryId'] ?? '');
        $choiceDecision = strtolower(receipt_text($choice['decision'] ?? ''));
        if ($choiceInquiryId === '' || !in_array($choiceDecision, ['wait_all', 'ship_now', 'ask_sales'], true)) continue;
        $dispatchByInquiry[$choiceInquiryId] = [
            'decision' => $choiceDecision,
            'customerName' => receipt_text($choice['customerName'] ?? ''),
            'otherItemCount' => max(0, (int)($choice['otherItemCount'] ?? 0)),
            'pendingOtherItemCount' => max(0, (int)($choice['pendingOtherItemCount'] ?? 0)),
        ];
    }
    $updated = [];
    $fully = [];
    $partial = [];
    foreach ($linkedOrders as $link) {
        if (!is_array($link)) continue;
        $inquiryId = receipt_first_text([$link['inquiryId'] ?? '', $link['customerOrderNo'] ?? '']);
        if ($inquiryId === '') continue;
        foreach ($rows as $rowIndex => &$row) {
            if (!is_array($row) || receipt_text($row['id'] ?? '') !== $inquiryId) continue;
            $allocationKey = implode('|', [$operationId, receipt_text($link['lineId'] ?? ''), (string)($link['itemIndex'] ?? '')]);
            $logs = isset($row['freightArrivalAllocations']) && is_array($row['freightArrivalAllocations']) ? array_values($row['freightArrivalAllocations']) : [];
            $alreadyApplied = false;
            foreach ($logs as $log) if (is_array($log) && receipt_text($log['allocationKey'] ?? '') === $allocationKey) { $alreadyApplied = true; break; }
            if (!$alreadyApplied) {
                $items = isset($row['items']) && is_array($row['items']) ? array_values($row['items']) : [];
                $targetIndex = null;
                $wantedLineId = receipt_text($link['lineId'] ?? '');
                foreach ($items as $candidateIndex => $candidate) {
                    if (!is_array($candidate)) continue;
                    if ($wantedLineId !== '' && receipt_text($candidate['lineId'] ?? '') === $wantedLineId) { $targetIndex = (int)$candidateIndex; break; }
                }
                if ($targetIndex === null && array_key_exists('itemIndex', $link) && isset($items[max(0, (int)$link['itemIndex'])])) $targetIndex = max(0, (int)$link['itemIndex']);
                if ($targetIndex !== null && is_array($items[$targetIndex] ?? null)) {
                    $qty = max(1, (int)($link['qty'] ?? 1));
                    $requested = max(1, (int)($items[$targetIndex]['requestedQty'] ?? $items[$targetIndex]['qty'] ?? 1));
                    $receivedSourceLineIndex = array_key_exists('receivedSourceLineIndex', $link)
                        ? max(0, (int)$link['receivedSourceLineIndex'])
                        : null;
                    $receivedLine = $receivedSourceLineIndex !== null && is_array($receivedLines[$receivedSourceLineIndex] ?? null)
                        ? $receivedLines[$receivedSourceLineIndex]
                        : [];
                    if (!$receivedLine) {
                        foreach ($receivedLines as $candidateLine) {
                            if (!is_array($candidateLine) || !freight_receive_link_matches_line($link, $candidateLine)) continue;
                            $receivedLine = $candidateLine;
                            break;
                        }
                    }
                    $items[$targetIndex]['freightReceivedQty'] = min($requested, max(0, (int)($items[$targetIndex]['freightReceivedQty'] ?? 0)) + $qty);
                    $items[$targetIndex]['freightArrivalStatus'] = $items[$targetIndex]['freightReceivedQty'] >= $requested ? 'received' : 'partial';
                    $items[$targetIndex]['freightLastReceivedAt'] = $receivedAt;
                    $items[$targetIndex]['freightLastTrackingNo'] = $trackingNo;
                    $items[$targetIndex]['freightReceivedWarehouse'] = $warehouse;
                    $items[$targetIndex]['freightReceivingDocumentNo'] = $receivingDocumentNo;
                    foreach ([
                        'skuId' => 'skuId',
                        'productId' => 'productId',
                        'code' => 'productCode',
                        'barcode' => 'barcode',
                        'color' => 'color',
                        'size' => 'size',
                    ] as $targetField => $sourceField) {
                        $verifiedValue = receipt_text($receivedLine[$sourceField] ?? '');
                        if ($verifiedValue !== '') $items[$targetIndex][$targetField] = $verifiedValue;
                    }
                    if (receipt_text($items[$targetIndex]['skuId'] ?? '') !== '') {
                        $items[$targetIndex]['sku'] = $items[$targetIndex]['skuId'];
                    }
                    $row['items'] = $items;
                    $logs[] = [
                        'allocationKey' => $allocationKey,
                        'operationId' => $operationId,
                        'freightItemId' => $freightItemId,
                        'trackingNo' => $trackingNo,
                        'lineId' => $wantedLineId,
                        'itemIndex' => $targetIndex,
                        'qty' => $qty,
                        'receivedAt' => $receivedAt,
                        'warehouse' => $warehouse,
                        'receivingDocumentNo' => $receivingDocumentNo,
                        'skuId' => receipt_text($receivedLine['skuId'] ?? ''),
                    ];
                    $row['freightArrivalAllocations'] = array_slice($logs, -500);
                }
            }
            $orderItems = isset($row['items']) && is_array($row['items']) ? array_values($row['items']) : [];
            $allReceived = !empty($orderItems);
            foreach ($orderItems as $orderItem) {
                if (!is_array($orderItem)) continue;
                $requestedQty = max(1, (int)($orderItem['requestedQty'] ?? $orderItem['qty'] ?? 1));
                if (max(0, (int)($orderItem['freightReceivedQty'] ?? 0)) < $requestedQty) { $allReceived = false; break; }
            }
            $dispatchChoice = $dispatchByInquiry[$inquiryId] ?? null;
            if (is_array($dispatchChoice)) {
                $decision = $dispatchChoice['decision'];
                $decisionLabels = [
                    'wait_all' => '等其他款到齊，再一起出貨',
                    'ship_now' => '本商品先出（分批寄）',
                    'ask_sales' => '先詢問業務，暫不出貨',
                ];
                $row['freightDispatchDecision'] = $decision;
                $row['freightDispatchDecisionLabel'] = $decisionLabels[$decision];
                $row['freightDispatchDecisionAt'] = $receivedAt;
                $row['freightDispatchDecisionOperationId'] = $operationId;
                $row['freightDispatchNeedsSalesReview'] = $decision === 'ask_sales';
                $row['freightPartialShipmentRequested'] = $decision === 'ship_now' && !$allReceived;
                $row['freightDispatchOtherItemCount'] = $dispatchChoice['otherItemCount'];
                $row['freightDispatchPendingOtherItemCount'] = $dispatchChoice['pendingOtherItemCount'];
                $decisionHistory = isset($row['freightDispatchDecisionHistory']) && is_array($row['freightDispatchDecisionHistory'])
                    ? array_values($row['freightDispatchDecisionHistory'])
                    : [];
                $decisionAlreadySaved = false;
                foreach ($decisionHistory as $decisionEntry) {
                    if (is_array($decisionEntry) && receipt_text($decisionEntry['operationId'] ?? '') === $operationId) {
                        $decisionAlreadySaved = true;
                        break;
                    }
                }
                if (!$decisionAlreadySaved) $decisionHistory[] = [
                    'operationId' => $operationId,
                    'at' => $receivedAt,
                    'decision' => $decision,
                    'decisionLabel' => $decisionLabels[$decision],
                    'otherItemCount' => $dispatchChoice['otherItemCount'],
                    'pendingOtherItemCount' => $dispatchChoice['pendingOtherItemCount'],
                    'freightItemId' => $freightItemId,
                    'trackingNo' => $trackingNo,
                ];
                $row['freightDispatchDecisionHistory'] = array_slice($decisionHistory, -100);
            }
            $row['purchaseStatus'] = $allReceived ? 'received' : 'arrived';
            $row['purchaseStatusLabel'] = $allReceived ? '已到貨入庫／等待確認出貨' : '部分到貨／等待其餘商品';
            $row['freightAllocationUpdatedAt'] = $receivedAt;
            $row['updatedAt'] = $receivedAt;
            if ($allReceived) {
                $row['inventoryReceived'] = true;
                $row['preorderReceivedAt'] = receipt_first_text([$row['preorderReceivedAt'] ?? '', $receivedAt]);
                $receivedWarehouses = [];
                foreach ($orderItems as $orderItem) {
                    if (!is_array($orderItem)) continue;
                    $itemWarehouse = strtoupper(receipt_text($orderItem['freightReceivedWarehouse'] ?? ''));
                    if (in_array($itemWarehouse, ['TW', 'CN', 'ID'], true)) $receivedWarehouses[$itemWarehouse] = true;
                }
                if (count($receivedWarehouses) === 1) {
                    $verifiedWarehouse = (string)array_key_first($receivedWarehouses);
                    $warehouseLabels = ['TW' => '台灣倉 / Gudang Taiwan', 'CN' => '中國倉 / Gudang China', 'ID' => '印尼倉 / Gudang Indonesia'];
                    $row['arrivalWarehouse'] = $verifiedWarehouse;
                    $row['arrivalWarehouseLabel'] = $warehouseLabels[$verifiedWarehouse];
                    $row['preorderFulfillWarehouse'] = $verifiedWarehouse;
                    $row['preorderFulfillWarehouseLabel'] = $warehouseLabels[$verifiedWarehouse];
                    $row['warehouseVerificationStatus'] = 'verified';
                } elseif (count($receivedWarehouses) > 1) {
                    $row['warehouseVerificationStatus'] = 'mixed';
                    $row['receivedWarehouses'] = array_keys($receivedWarehouses);
                }
                $receivedSingleWarehouse = count($receivedWarehouses) === 1 ? (string)array_key_first($receivedWarehouses) : '';
                $explicitDispatchDecision = is_array($dispatchChoice) ? (string)$dispatchChoice['decision'] : '';
                $autoWaitNotify = $explicitDispatchDecision === '' && $receivedSingleWarehouse !== '' && receipt_should_auto_wait_notify_after_arrival($row, $receivedSingleWarehouse);
                $row['preorderWaitStatus'] = ($explicitDispatchDecision === 'ship_now' || $autoWaitNotify)
                    ? 'ready_to_ship'
                    : (receipt_text($row['preorderWaitStatus'] ?? '') ?: 'waiting_other_items');
                $row['preorderWaitStatusLabel'] = $row['preorderWaitStatus'] === 'ready_to_ship' ? '商品已齊／准許出貨' : '累計訂單／等待業務確認出貨';
                if ($autoWaitNotify && empty($row['waitNotify'])) {
                    $autoNotifyNote = receipt_row_is_live_order($row)
                        ? ($receivedSingleWarehouse === 'CN'
                            ? '中國直播已到貨建檔，先進直播打單通知區，待安排轉台灣或業務通知後再出貨'
                            : '直播現貨已到台灣倉，等待業務通知後再出貨')
                        : '台灣現貨已到，等待業務通知後再出貨';
                    apply_wait_notify_fields(
                        $row,
                        true,
                        '物流驗收入庫',
                        $receivedAt,
                        $autoNotifyNote,
                        'admin',
                        true,
                        false
                    );
                }
                $fully[$inquiryId] = true;
            } else {
                $partial[$inquiryId] = true;
            }
            $history = isset($row['preorderProgressHistory']) && is_array($row['preorderProgressHistory']) ? array_values($row['preorderProgressHistory']) : [];
            $hasHistory = false;
            foreach ($history as $entry) if (is_array($entry) && receipt_text($entry['operationId'] ?? '') === $operationId) { $hasHistory = true; break; }
            if (!$hasHistory) $history[] = [
                'operationId' => $operationId,
                'at' => $receivedAt,
                'purchaseStatus' => $row['purchaseStatus'],
                'purchaseStatusLabel' => $row['purchaseStatusLabel'],
                'supplierTrackingNo' => $trackingNo,
                'arrivalWarehouse' => $warehouse,
                'receivingDocumentNo' => $receivingDocumentNo,
                'note' => $allReceived ? '物流互聯品項已全部驗收入庫並完成倉別配對，請逐單確認是否准許出貨' : '物流互聯品項部分入庫，繼續等待其餘貨物',
            ];
            $row['preorderProgressHistory'] = array_slice($history, -200);
            $updated[$inquiryId] = true;
            break;
        }
        unset($row);
    }
    if ($updated) write_json($inquiriesFile, array_values($rows));
    flock($lock, LOCK_UN);
    fclose($lock);
    return ['updatedOrderCount' => count($updated), 'fullyReceivedOrderCount' => count($fully), 'partialOrderCount' => count($partial), 'readyForConfirmation' => array_keys($fully)];
}

function hydrate_preorder_items_from_freight_arrivals(array $inquiry, string $freightTrackingFile): array {
    $items = isset($inquiry['items']) && is_array($inquiry['items']) ? array_values($inquiry['items']) : [];
    $arrivalLogs = isset($inquiry['freightArrivalAllocations']) && is_array($inquiry['freightArrivalAllocations'])
        ? array_values($inquiry['freightArrivalAllocations'])
        : [];
    if (!$items || !$arrivalLogs || !is_file($freightTrackingFile)) return $inquiry;
    $freightData = read_json($freightTrackingFile);
    $freightById = [];
    foreach (is_array($freightData['items'] ?? null) ? $freightData['items'] : [] as $freightItem) {
        if (!is_array($freightItem)) continue;
        $freightId = receipt_text($freightItem['id'] ?? $freightItem['itemId'] ?? '');
        if ($freightId !== '') $freightById[$freightId] = $freightItem;
    }
    $verifiedWarehouses = [];
    foreach ($arrivalLogs as &$arrivalLog) {
        if (!is_array($arrivalLog)) continue;
        // Keep historical allocation rows for audit, but never let a released
        // or explicitly invalidated link overwrite the order's current SKU.
        // Before this guard, an old wrong-product arrival could silently turn
        // OLAN82 back into OLAN61 every time the operator pressed Save.
        $arrivalStatus = strtolower(receipt_text($arrivalLog['status'] ?? ''));
        if (($arrivalLog['active'] ?? true) === false
            || in_array($arrivalStatus, ['released', 'void', 'cancelled', 'inactive', 'rejected', 'wrong_product'], true)) {
            continue;
        }
        $freightItemId = receipt_text($arrivalLog['freightItemId'] ?? '');
        $freightItem = $freightById[$freightItemId] ?? [];
        if (!$freightItem) continue;
        $targetIndex = array_key_exists('itemIndex', $arrivalLog) ? max(0, (int)$arrivalLog['itemIndex']) : null;
        $wantedLineId = receipt_text($arrivalLog['lineId'] ?? '');
        if ($wantedLineId !== '') {
            foreach ($items as $candidateIndex => $candidateItem) {
                if (is_array($candidateItem) && receipt_text($candidateItem['lineId'] ?? '') === $wantedLineId) {
                    $targetIndex = (int)$candidateIndex;
                    break;
                }
            }
        }
        if ($targetIndex === null || !is_array($items[$targetIndex] ?? null)) continue;
        $currentSku = receipt_first_text([$items[$targetIndex]['skuId'] ?? '', $items[$targetIndex]['sku'] ?? '']);
        $arrivalSku = receipt_text($arrivalLog['skuId'] ?? '');
        $currentCode = strtoupper(receipt_first_text([$items[$targetIndex]['code'] ?? '', $items[$targetIndex]['productCode'] ?? '']));
        $arrivalCode = strtoupper(receipt_first_text([
            $arrivalLog['productCode'] ?? '',
            $freightItem['productCode'] ?? '',
            $freightItem['catalogProductCode'] ?? '',
        ]));
        if ($currentSku !== '' && $arrivalSku !== '' && $currentSku !== $arrivalSku) continue;
        if ($currentCode !== '' && $arrivalCode !== '' && $currentCode !== $arrivalCode) continue;
        $claim = isset($freightItem['inventoryReceiptClaim']) && is_array($freightItem['inventoryReceiptClaim'])
            ? $freightItem['inventoryReceiptClaim']
            : [];
        $receiptLines = isset($claim['lines']) && is_array($claim['lines']) ? array_values($claim['lines']) : [];
        $wantedSkuId = receipt_text($arrivalLog['skuId'] ?? '');
        $receivedLine = [];
        foreach ($receiptLines as $candidateLine) {
            if (!is_array($candidateLine)) continue;
            if ($wantedSkuId !== '' && receipt_text($candidateLine['skuId'] ?? '') === $wantedSkuId) {
                $receivedLine = $candidateLine;
                break;
            }
            if (!$receivedLine && freight_receive_link_matches_line([
                'productCode' => $items[$targetIndex]['code'] ?? $items[$targetIndex]['productCode'] ?? '',
                'barcode' => $items[$targetIndex]['barcode'] ?? '',
                'color' => $items[$targetIndex]['color'] ?? '',
                'size' => $items[$targetIndex]['size'] ?? 'NO SIZE',
                'variantIndex' => null,
            ], $candidateLine)) {
                $receivedLine = $candidateLine;
            }
        }
        $warehouse = strtoupper(receipt_first_text([
            $receivedLine['warehouse'] ?? '',
            $arrivalLog['warehouse'] ?? '',
            $claim['warehouse'] ?? '',
            $freightItem['inventoryReceivedWarehouse'] ?? '',
        ]));
        if (in_array($warehouse, ['TW', 'CN', 'ID'], true)) {
            $items[$targetIndex]['freightReceivedWarehouse'] = $warehouse;
            $verifiedWarehouses[$warehouse] = true;
            $arrivalLog['warehouse'] = $warehouse;
        }
        foreach ([
            'skuId' => 'skuId',
            'productId' => 'productId',
            'code' => 'productCode',
            'barcode' => 'barcode',
            'color' => 'color',
            'size' => 'size',
        ] as $targetField => $sourceField) {
            $verifiedValue = receipt_text($receivedLine[$sourceField] ?? '');
            if ($verifiedValue !== '') $items[$targetIndex][$targetField] = $verifiedValue;
        }
        if (receipt_text($items[$targetIndex]['skuId'] ?? '') !== '') $items[$targetIndex]['sku'] = $items[$targetIndex]['skuId'];
        $trackingNo = receipt_first_text([$arrivalLog['trackingNo'] ?? '', $freightItem['trackingNo'] ?? '', $freightItem['haohongTrackingNo'] ?? '']);
        $receivingDocumentNo = receipt_first_text([$arrivalLog['receivingDocumentNo'] ?? '', $freightItem['receivingDocumentNo'] ?? '', $freightItem['productFiledInventoryReceiptNo'] ?? '']);
        if ($trackingNo !== '') {
            $items[$targetIndex]['freightLastTrackingNo'] = $trackingNo;
            $arrivalLog['trackingNo'] = $trackingNo;
        }
        if ($receivingDocumentNo !== '') {
            $items[$targetIndex]['freightReceivingDocumentNo'] = $receivingDocumentNo;
            $arrivalLog['receivingDocumentNo'] = $receivingDocumentNo;
        }
    }
    unset($arrivalLog);
    $inquiry['items'] = $items;
    $inquiry['freightArrivalAllocations'] = $arrivalLogs;
    if (count($verifiedWarehouses) === 1) {
        $verifiedWarehouse = (string)array_key_first($verifiedWarehouses);
        $warehouseLabels = ['TW' => '台灣倉 / Gudang Taiwan', 'CN' => '中國倉 / Gudang China', 'ID' => '印尼倉 / Gudang Indonesia'];
        $inquiry['arrivalWarehouse'] = $verifiedWarehouse;
        $inquiry['arrivalWarehouseLabel'] = $warehouseLabels[$verifiedWarehouse];
        $inquiry['preorderFulfillWarehouse'] = $verifiedWarehouse;
        $inquiry['preorderFulfillWarehouseLabel'] = $warehouseLabels[$verifiedWarehouse];
        $inquiry['warehouseVerificationStatus'] = 'verified';
    } elseif (count($verifiedWarehouses) > 1) {
        $inquiry['warehouseVerificationStatus'] = 'mixed';
        $inquiry['receivedWarehouses'] = array_keys($verifiedWarehouses);
    }
    return $inquiry;
}

function freight_product_receive_release_customer_reservations(string $inquiriesFile, array $freightData, string $freightItemId, array $releasedLinks, string $operationId, string $releasedAt, string $trackingNo, string $warehouse): array {
    if (!$releasedLinks) return ['releasedOrderCount' => 0, 'releasedQty' => 0, 'orders' => []];
    $activeInquiryIds = [];
    foreach (is_array($freightData['items'] ?? null) ? $freightData['items'] : [] as $freightItem) {
        if (!is_array($freightItem) || receipt_text($freightItem['id'] ?? '') === $freightItemId) continue;
        foreach (is_array($freightItem['customerLinks'] ?? null) ? $freightItem['customerLinks'] : [] as $link) {
            if (!is_array($link)) continue;
            $status = mb_strtolower(receipt_text($link['status'] ?? ''), 'UTF-8');
            if ($status !== '' && preg_match('/cancel|release|取消|解除/u', $status)) continue;
            $inquiryId = receipt_first_text([$link['inquiryId'] ?? '', $link['customerOrderNo'] ?? '']);
            if ($inquiryId !== '') $activeInquiryIds[$inquiryId] = true;
        }
    }
    $lock = fopen($inquiriesFile . '.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('無法鎖定預購訂單，未釋放客戶保留');
    try {
        $rows = read_json($inquiriesFile);
        $releasedOrders = [];
        $releasedQty = 0;
        foreach ($releasedLinks as $link) {
            if (!is_array($link)) continue;
            $inquiryId = receipt_first_text([$link['inquiryId'] ?? '', $link['customerOrderNo'] ?? '']);
            $qty = max(0, (int)($link['qty'] ?? 0));
            if ($inquiryId === '' || $qty <= 0) continue;
            foreach ($rows as $rowIndex => $row) {
                if (!is_array($row) || receipt_text($row['id'] ?? '') !== $inquiryId) continue;
                $releaseKey = implode('|', [$operationId, receipt_text($link['lineId'] ?? ''), (string)($link['itemIndex'] ?? ''), (string)($link['variantIndex'] ?? '')]);
                $logs = is_array($row['freightReservationReleases'] ?? null) ? array_values($row['freightReservationReleases']) : [];
                $alreadyLogged = false;
                foreach ($logs as $log) if (is_array($log) && receipt_text($log['releaseKey'] ?? '') === $releaseKey) { $alreadyLogged = true; break; }
                if (!$alreadyLogged) {
                    $logs[] = [
                        'releaseKey' => $releaseKey,
                        'operationId' => $operationId,
                        'freightItemId' => $freightItemId,
                        'trackingNo' => $trackingNo,
                        'lineId' => receipt_text($link['lineId'] ?? ''),
                        'itemIndex' => array_key_exists('itemIndex', $link) ? max(0, (int)$link['itemIndex']) : null,
                        'variantIndex' => array_key_exists('variantIndex', $link) ? max(0, (int)$link['variantIndex']) : null,
                        'qty' => $qty,
                        'warehouse' => $warehouse,
                        'reason' => '管理者驗收到貨時改為公司現貨／樣品',
                        'releasedAt' => $releasedAt,
                    ];
                    $rows[$rowIndex]['freightReservationReleases'] = array_slice($logs, -500);
                    $releasedQty += $qty;
                }
                $hasOtherArrival = !empty($rows[$rowIndex]['freightArrivalAllocations']);
                if (!isset($activeInquiryIds[$inquiryId]) && !$hasOtherArrival) {
                    $rows[$rowIndex]['purchaseStatus'] = 'preparing';
                    $rows[$rowIndex]['purchaseStatusLabel'] = '原物流改公司現貨／待重新配貨';
                    $rows[$rowIndex]['preorderWaitStatus'] = 'waiting_reallocation';
                    $rows[$rowIndex]['preorderWaitStatusLabel'] = '客戶保留已釋放／待重新採購';
                }
                $history = is_array($row['preorderProgressHistory'] ?? null) ? array_values($row['preorderProgressHistory']) : [];
                $history[] = [
                    'operationId' => $operationId . ':release',
                    'at' => $releasedAt,
                    'purchaseStatus' => $rows[$rowIndex]['purchaseStatus'] ?? 'preparing',
                    'purchaseStatusLabel' => $rows[$rowIndex]['purchaseStatusLabel'] ?? '待重新配貨',
                    'supplierTrackingNo' => $trackingNo,
                    'note' => '此物流 ' . $qty . ' 件改為 ' . $warehouse . ' 公司現貨／樣品；客戶需求保留並回到尚欠提醒',
                ];
                $rows[$rowIndex]['preorderProgressHistory'] = array_slice($history, -200);
                $rows[$rowIndex]['updatedAt'] = $releasedAt;
                $releasedOrders[$inquiryId] = true;
                break;
            }
        }
        if ($releasedOrders) write_json($inquiriesFile, array_values($rows));
        return ['releasedOrderCount' => count($releasedOrders), 'releasedQty' => $releasedQty, 'orders' => array_keys($releasedOrders)];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function freight_product_receive_receipt_conflict(array $receipts, array $freightItem, string $allowedOperationId = ''): ?array {
    $freightItemId = receipt_text($freightItem['id'] ?? '');
    $freightBatchId = receipt_text($freightItem['batchId'] ?? '');
    $trackingNo = receipt_text($freightItem['trackingNo'] ?? '');
    foreach ($receipts as $receipt) {
        if (!is_array($receipt)) continue;
        if ($allowedOperationId !== '' && receipt_text($receipt['operationId'] ?? '') === $allowedOperationId) continue;
        $status = strtolower(receipt_text($receipt['status'] ?? ''));
        if (!in_array($status, ['confirming', 'received', 'reconciliation_required'], true) && empty($receipt['reconciliationRequired'])) continue;
        $receiptItemId = receipt_text($receipt['freightItemId'] ?? '');
        $matches = $freightItemId !== '' && $receiptItemId === $freightItemId;
        // A receipt with an explicit item id owns only that item.  Several
        // different products can share one batch or parcel tracking number,
        // so the broader references are conflicts only for a legacy/formal
        // receipt that did not identify an individual freight item.
        if (!$matches && $receiptItemId === '' && $freightBatchId !== '' && receipt_text($receipt['freightBatchId'] ?? '') === $freightBatchId) $matches = true;
        if (!$matches && $receiptItemId === '' && $trackingNo !== '' && receipt_text($receipt['freightTrackingNo'] ?? '') === $trackingNo) $matches = true;
        foreach (is_array($receipt['receiptLines'] ?? null) ? $receipt['receiptLines'] : [] as $line) {
            if (!is_array($line)) continue;
            $snapshot = isset($line['freightCostSnapshot']) && is_array($line['freightCostSnapshot']) ? $line['freightCostSnapshot'] : [];
            if ($freightItemId !== '' && receipt_text($snapshot['itemId'] ?? '') === $freightItemId) { $matches = true; break; }
        }
        if (!$matches) continue;
        return [
            'documentId' => receipt_text($receipt['id'] ?? ''),
            'status' => $status !== '' ? $status : 'reconciliation_required',
            'operationId' => receipt_text($receipt['operationId'] ?? ''),
        ];
    }
    return null;
}

function freight_product_receive_batch_conflict(array $freightData, array $freightItem, string $operationId): ?array {
    $batchId = receipt_text($freightItem['batchId'] ?? '');
    if ($batchId === '') return null;
    foreach (is_array($freightData['batches'] ?? null) ? $freightData['batches'] : [] as $batch) {
        if (!is_array($batch) || receipt_text($batch['id'] ?? '') !== $batchId) continue;
        $claim = isset($batch['inventoryReceiptClaim']) && is_array($batch['inventoryReceiptClaim']) ? $batch['inventoryReceiptClaim'] : [];
        $claimOperationId = receipt_text($claim['operationId'] ?? '');
        $received = !empty($batch['inventoryReceivedAt']) || receipt_text($batch['status'] ?? '') === '已入庫' || receipt_text($batch['receivingDocumentNo'] ?? '') !== '';
        if ($claimOperationId !== '' && $claimOperationId !== $operationId) {
            return ['batchId' => $batchId, 'operationId' => $claimOperationId, 'status' => $received ? 'received' : receipt_text($claim['status'] ?? 'claimed')];
        }
        // New direct-product receipts claim individual items, not the whole
        // batch.  A partial batch therefore must not make the first product
        // block every other product in that same consolidation batch.
        $itemClaim = isset($freightItem['inventoryReceiptClaim']) && is_array($freightItem['inventoryReceiptClaim']) ? $freightItem['inventoryReceiptClaim'] : [];
        $itemClaimOperationId = receipt_text($itemClaim['operationId'] ?? '');
        if ($itemClaimOperationId === $operationId) return null;

        $targetPackageFound = false;
        $targetPackageReceived = false;
        $targetPackageOperationId = '';
        $freightItemId = receipt_text($freightItem['id'] ?? '');
        foreach (is_array($batch['packageRows'] ?? null) ? $batch['packageRows'] : [] as $packageRow) {
            if (!is_array($packageRow)) continue;
            $packageItemId = receipt_first_text([$packageRow['sourceItemId'] ?? '', $packageRow['freightItemId'] ?? '', $packageRow['id'] ?? '']);
            if ($freightItemId === '' || $packageItemId !== $freightItemId) continue;
            $targetPackageFound = true;
            $targetPackageReceived = receipt_text($packageRow['inventoryStatus'] ?? '') === 'received' || !empty($packageRow['inventoryReceivedAt']);
            $targetPackageOperationId = receipt_text($packageRow['inventoryOperationId'] ?? '');
            break;
        }
        if ($targetPackageReceived && $targetPackageOperationId !== $operationId) {
            return ['batchId' => $batchId, 'operationId' => $targetPackageOperationId, 'status' => 'received'];
        }

        $hasPerItemReceiptEvidence = !empty($batch['inventoryReceiptDocuments'])
            || in_array(receipt_text($batch['inventoryStatus'] ?? ''), ['partial', 'received'], true)
            || in_array(receipt_text($batch['receivingStage'] ?? ''), ['partial_inventory_received', 'inventory_received'], true);
        if ($received && !$hasPerItemReceiptEvidence) {
            return ['batchId' => $batchId, 'operationId' => $claimOperationId, 'status' => 'received'];
        }
        if (receipt_text($batch['inventoryStatus'] ?? '') === 'received' && $targetPackageFound && !$targetPackageReceived) {
            return ['batchId' => $batchId, 'operationId' => $claimOperationId, 'status' => 'reconciliation_required'];
        }
        return null;
    }
    return null;
}


function receipt_status_is_inactive(string $status): bool {
    return in_array(strtolower(trim($status)), ['reversed', 'voided', 'cancelled', 'canceled', 'deleted', 'abandoned', 'void'], true);
}

// LZ_RECV_DEL_20260924
// LZ_PO_VOID_20260924

function write_json_compact(string $file, array $payload): void {
    // LZ_RECV_DEL_2_20260924: skip pretty-print + color canonicalize so delete does not hang the UI.
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) respond(['ok' => false, 'error' => '無法寫入庫存資料'], 500);
    if (file_put_contents($tmp, $json, LOCK_EX) === false) respond(['ok' => false, 'error' => '無法寫入暫存庫存檔'], 500);
    if (!rename($tmp, $file)) {
        @unlink($tmp);
        respond(['ok' => false, 'error' => '無法更新庫存檔'], 500);
    }
}

function purchase_receipt_client_view(array $receipt): array {
    $lines = [];
    foreach (isset($receipt['receiptLines']) && is_array($receipt['receiptLines']) ? $receipt['receiptLines'] : [] as $line) {
        if (!is_array($line)) continue;
        $image = receipt_text($line['productImage'] ?? $line['image'] ?? '');
        if (strpos($image, 'data:image/') === 0) $image = '';
        $lines[] = [
            'skuId' => receipt_first_text([$line['skuId'] ?? '', $line['sku'] ?? '']),
            'productId' => receipt_text($line['productId'] ?? ''),
            'productName' => receipt_text($line['productName'] ?? ''),
            'productCode' => receipt_text($line['productCode'] ?? ''),
            'barcode' => receipt_first_text([$line['barcode'] ?? '', $line['companyBarcode'] ?? '']),
            'companyBarcode' => receipt_first_text([$line['companyBarcode'] ?? '', $line['barcode'] ?? '']),
            'color' => receipt_text($line['color'] ?? $line['colorName'] ?? ''),
            'size' => receipt_text($line['size'] ?? $line['sizeName'] ?? 'NO SIZE') ?: 'NO SIZE',
            'qty' => max(0, (int)($line['qty'] ?? 0)),
            'unitCostTwd' => max(0, (float)($line['unitCostTwd'] ?? 0)),
            'warehouse' => receipt_text($line['warehouse'] ?? ''),
            'sellableQty' => array_key_exists('sellableQty', $line) && is_numeric($line['sellableQty']) ? (int)$line['sellableQty'] : null,
            'internalUseQty' => max(0, (int)($line['internalUseQty'] ?? 0)),
            'productImage' => $image,
        ];
    }
    $doc = is_array($receipt['receivingDocument'] ?? null) ? $receipt['receivingDocument'] : [];
    return [
        'id' => receipt_text($receipt['id'] ?? ''),
        'status' => receipt_text($receipt['status'] ?? ''),
        'receivingDocument' => [
            'documentNo' => receipt_text($doc['documentNo'] ?? ''),
            'documentDate' => receipt_text($doc['documentDate'] ?? ''),
            'sourceOrderNo' => receipt_text($doc['sourceOrderNo'] ?? ''),
            'supplier' => receipt_text($doc['supplier'] ?? ''),
            'purchasePlatform' => receipt_text($doc['purchasePlatform'] ?? ''),
            'operatorName' => receipt_text($doc['operatorName'] ?? ''),
            'arrivalWarehouse' => receipt_text($doc['arrivalWarehouse'] ?? ''),
            'note' => receipt_text($doc['note'] ?? ''),
            'status' => receipt_text($doc['status'] ?? ''),
        ],
        'receiptLines' => $lines,
        'lineCount' => count($lines),
        'totalQty' => max(0, (int)($receipt['totalQty'] ?? 0)),
        'totalCostTwd' => max(0, (float)($receipt['totalCostTwd'] ?? 0)),
        'voidedAt' => receipt_text($receipt['voidedAt'] ?? ''),
        'updatedAt' => receipt_text($receipt['updatedAt'] ?? ''),
    ];
}

function purchase_receipt_line_sellable_qty(array $line): int {
    $qty = max(0, (int)($line['qty'] ?? $line['quantity'] ?? 0));
    $internal = max(0, (int)($line['internalUseQty'] ?? 0));
    $purpose = strtolower(receipt_text($line['goodsPurpose'] ?? $line['stockPurpose'] ?? ''));
    if ($purpose === 'internal_use') return 0;
    if (array_key_exists('sellableQty', $line) && is_numeric($line['sellableQty'])) {
        return max(0, (int)$line['sellableQty']);
    }
    return max(0, $qty - $internal);
}

function purchase_receipt_find_sku_index(array $skus, array $line, array $profile): int {
    $skuId = receipt_first_text([$line['skuId'] ?? '', $line['sku'] ?? '']);
    if ($skuId !== '') {
        $warehouseMatch = -1;
        $anyMatch = -1;
        foreach ($skus as $index => $sku) {
            if (!is_array($sku)) continue;
            if (receipt_first_text([$sku['id'] ?? '', $sku['sku'] ?? '']) !== $skuId) continue;
            if ($anyMatch < 0) $anyMatch = (int)$index;
            if (receipt_sku_is_warehouse($sku, $profile)) {
                $warehouseMatch = (int)$index;
                break;
            }
        }
        if ($warehouseMatch >= 0) return $warehouseMatch;
        if ($anyMatch >= 0) return $anyMatch;
    }
    $productId = receipt_text($line['productId'] ?? '');
    $color = receipt_text($line['color'] ?? $line['colorName'] ?? '');
    $size = receipt_text($line['size'] ?? $line['sizeName'] ?? 'NO SIZE') ?: 'NO SIZE';
    $barcode = strtoupper(receipt_first_text([$line['barcode'] ?? '', $line['companyBarcode'] ?? '']));
    if ($productId !== '' && $color !== '') {
        foreach ($skus as $index => $sku) {
            if (!is_array($sku) || !receipt_sku_is_warehouse($sku, $profile)) continue;
            if (receipt_same_variant($sku, $productId, $color, $size)) return (int)$index;
        }
    }
    if ($barcode !== '') {
        foreach ($skus as $index => $sku) {
            if (!is_array($sku) || !receipt_sku_is_warehouse($sku, $profile)) continue;
            $candidate = strtoupper(receipt_first_text([
                $sku['companyBarcode'] ?? '', $sku['barcode'] ?? '', $sku['officialBarcode'] ?? '', $sku['legacyBarcode'] ?? '',
            ]));
            if ($candidate !== '' && $candidate === $barcode) return (int)$index;
        }
    }
    return -1;
}

function purchase_receipt_line_identity_key(array $line, int $lineIndex): string {
    return strtolower(implode('|', [
        (string)$lineIndex,
        receipt_first_text([$line['skuId'] ?? '', $line['sku'] ?? '']),
        receipt_first_text([$line['barcode'] ?? '', $line['companyBarcode'] ?? '']),
        receipt_text($line['color'] ?? $line['colorName'] ?? ''),
        receipt_text($line['size'] ?? $line['sizeName'] ?? 'NO SIZE') ?: 'NO SIZE',
    ]));
}

function purchase_receipt_release_matching_freight_claim(array &$freightData, array $receipt, string $now): bool {
    $documentId = receipt_text($receipt['id'] ?? '');
    $operationId = receipt_text($receipt['operationId'] ?? $receipt['inventoryOperationId'] ?? '');
    $documentNo = receipt_first_text([
        is_array($receipt['receivingDocument'] ?? null) ? ($receipt['receivingDocument']['documentNo'] ?? '') : '',
        $receipt['documentNo'] ?? '',
    ]);
    if ($documentId === '' && $operationId === '') return false;
    $changed = false;
    $voidItemIds = [];
    if (isset($freightData['items']) && is_array($freightData['items'])) {
        foreach ($freightData['items'] as &$item) {
            if (!is_array($item)) continue;
            $claim = is_array($item['inventoryReceiptClaim'] ?? null) ? $item['inventoryReceiptClaim'] : [];
            $claimDocumentId = receipt_text($claim['documentId'] ?? $item['receivingDocumentId'] ?? '');
            $claimOperationId = receipt_text($claim['operationId'] ?? $item['inventoryOperationId'] ?? '');
            $matches = ($documentId !== '' && $claimDocumentId === $documentId)
                || ($operationId !== '' && $claimOperationId === $operationId);
            if (!$matches) continue;
            $itemId = receipt_text($item['id'] ?? '');
            if ($itemId !== '') $voidItemIds[$itemId] = true;
            $item['inventoryReceiptClaim'] = $claim + [
                'status' => 'voided',
                'voidedAt' => $now,
                'voidedDocumentId' => $documentId,
                'updatedAt' => $now,
            ];
            $item['inventoryStatus'] = 'voided';
            $item['receivingStage'] = 'voided';
            $item['receivingStatus'] = '進貨單已作廢';
            $item['freightReceivingStatus'] = '進貨單已作廢';
            $item['progress'] = '進貨單已作廢';
            $item['inventoryReceivedAt'] = '';
            $item['inventoryOperationId'] = '';
            $item['receivingDocumentId'] = '';
            $item['receivingDocumentNo'] = '';
            $item['updatedAt'] = $now;
            $history = isset($item['statusHistory']) && is_array($item['statusHistory']) ? array_values($item['statusHistory']) : [];
            $history[] = ['operationId' => $operationId, 'status' => '進貨單已作廢', 'at' => $now, 'receivingDocumentNo' => $documentNo];
            $item['statusHistory'] = array_slice($history, -50);
            $changed = true;
        }
        unset($item);
    }
    if (isset($freightData['batches']) && is_array($freightData['batches'])) {
        foreach ($freightData['batches'] as &$batch) {
            if (!is_array($batch)) continue;
            $batchChanged = false;
            $claim = is_array($batch['inventoryReceiptClaim'] ?? null) ? $batch['inventoryReceiptClaim'] : [];
            $claimDocumentId = receipt_text($claim['documentId'] ?? '');
            $claimOperationId = receipt_text($claim['operationId'] ?? '');
            if (($documentId !== '' && $claimDocumentId === $documentId) || ($operationId !== '' && $claimOperationId === $operationId)) {
                $batch['inventoryReceiptClaim'] = $claim + [
                    'status' => 'voided',
                    'voidedAt' => $now,
                    'updatedAt' => $now,
                ];
                if (receipt_text($batch['receivingDocumentNo'] ?? '') === $documentNo) {
                    $batch['receivingDocumentNo'] = '';
                    $batch['inventoryReceivedAt'] = '';
                    $batch['inventoryStatus'] = '';
                    $batch['receivingStage'] = '';
                    if (receipt_text($batch['status'] ?? '') === '已入庫') $batch['status'] = '部分入庫';
                }
                $batchChanged = true;
            }
            $packageRows = isset($batch['packageRows']) && is_array($batch['packageRows']) ? array_values($batch['packageRows']) : [];
            foreach ($packageRows as &$packageRow) {
                if (!is_array($packageRow)) continue;
                $packageItemId = receipt_first_text([$packageRow['sourceItemId'] ?? '', $packageRow['freightItemId'] ?? '', $packageRow['id'] ?? '']);
                $packageDoc = receipt_text($packageRow['receivingDocumentId'] ?? '');
                $packageOp = receipt_text($packageRow['inventoryOperationId'] ?? '');
                $matchesPackage = ($packageItemId !== '' && isset($voidItemIds[$packageItemId]))
                    || ($documentId !== '' && $packageDoc === $documentId)
                    || ($operationId !== '' && $packageOp === $operationId);
                if (!$matchesPackage) continue;
                $packageRow['inventoryStatus'] = 'voided';
                $packageRow['receiptStatus'] = '進貨單已作廢';
                $packageRow['inventoryReceivedAt'] = '';
                $packageRow['inventoryOperationId'] = '';
                $packageRow['receivingDocumentId'] = '';
                $packageRow['receivingDocumentNo'] = '';
                $packageRow['updatedAt'] = $now;
                $batchChanged = true;
            }
            unset($packageRow);
            if (!$batchChanged) continue;
            $batch['packageRows'] = $packageRows;
            $docs = isset($batch['inventoryReceiptDocuments']) && is_array($batch['inventoryReceiptDocuments'])
                ? array_values($batch['inventoryReceiptDocuments'])
                : [];
            $batch['inventoryReceiptDocuments'] = array_values(array_filter($docs, static function ($entry) use ($documentId, $operationId) {
                if (!is_array($entry)) return false;
                if ($documentId !== '' && receipt_text($entry['documentId'] ?? '') === $documentId) return false;
                if ($operationId !== '' && receipt_text($entry['operationId'] ?? '') === $operationId) return false;
                return true;
            }));
            $batch['receivingDocumentNos'] = array_values(array_unique(array_filter(array_map(
                static fn($entry) => is_array($entry) ? receipt_text($entry['documentNo'] ?? '') : '',
                $batch['inventoryReceiptDocuments']
            ))));
            $batch['receivingDocumentNo'] = implode('、', $batch['receivingDocumentNos']);
            $receivedRowCount = 0;
            foreach ($packageRows as $packageRow) {
                if (is_array($packageRow) && (receipt_text($packageRow['inventoryStatus'] ?? '') === 'received' || !empty($packageRow['inventoryReceivedAt']))) $receivedRowCount++;
            }
            $batch['inventoryReceivedItemCount'] = $receivedRowCount;
            $batch['inventoryPendingItemCount'] = max(0, count($packageRows) - $receivedRowCount);
            if ($packageRows && $receivedRowCount === count($packageRows)) {
                $batch['inventoryStatus'] = 'received';
                $batch['status'] = '已入庫';
            } elseif ($receivedRowCount > 0) {
                $batch['inventoryStatus'] = 'partial';
                $batch['status'] = '部分入庫';
            } else {
                $batch['inventoryStatus'] = '';
                if (in_array(receipt_text($batch['status'] ?? ''), ['已入庫', '部分入庫'], true)) $batch['status'] = '待入庫';
            }
            $history = isset($batch['statusHistory']) && is_array($batch['statusHistory']) ? array_values($batch['statusHistory']) : [];
            $history[] = ['operationId' => $operationId, 'status' => '進貨單已作廢', 'at' => $now, 'receivingDocumentNo' => $documentNo];
            $batch['statusHistory'] = array_slice($history, -50);
            $batch['updatedAt'] = $now;
            $changed = true;
        }
        unset($batch);
    }
    return $changed;
}


function freight_product_receive_line_key(array $line): string {
    $skuId = strtolower(receipt_text($line['skuId'] ?? ''));
    if ($skuId !== '') return 'sku:' . $skuId;
    return strtolower(implode('|', [
        receipt_text($line['productId'] ?? ''),
        receipt_text($line['barcode'] ?? ''),
        receipt_text($line['color'] ?? ''),
        receipt_text($line['size'] ?? 'NO SIZE') ?: 'NO SIZE',
    ]));
}

function freight_product_receive_committed_qty_by_key(array $receipts, string $freightItemId): array {
    $qty = [];
    if ($freightItemId === '') return $qty;
    foreach ($receipts as $receipt) {
        if (!is_array($receipt)) continue;
        if (receipt_text($receipt['freightItemId'] ?? '') !== $freightItemId) continue;
        $status = strtolower(receipt_text($receipt['status'] ?? ''));
        if (receipt_status_is_inactive($status)) continue;
        if (!in_array($status, ['confirming', 'received', 'reconciliation_required'], true) && empty($receipt['reconciliationRequired'])) continue;
        foreach (is_array($receipt['receiptLines'] ?? null) ? $receipt['receiptLines'] : [] as $line) {
            if (!is_array($line)) continue;
            $n = max(0, (int)($line['qty'] ?? 0));
            if ($n <= 0) continue;
            $key = freight_product_receive_line_key($line);
            $qty[$key] = ($qty[$key] ?? 0) + $n;
        }
    }
    return $qty;
}

function clear_inventory_transaction_markers_for_operation(array &$skus, string $operationId): int {
    if ($operationId === '') return 0;
    $cleared = 0;
    foreach ($skus as &$sku) {
        if (!is_array($sku)) continue;
        if (!isset($sku['inventoryTransactionMarkers']) || !is_array($sku['inventoryTransactionMarkers'])) continue;
        if (!isset($sku['inventoryTransactionMarkers'][$operationId])) continue;
        unset($sku['inventoryTransactionMarkers'][$operationId]);
        $cleared++;
        if (!$sku['inventoryTransactionMarkers']) unset($sku['inventoryTransactionMarkers']);
    }
    unset($sku);
    return $cleared;
}

function formal_purchase_receipt_reference_conflict(
    array $receipts,
    string $currentDocumentId,
    string $currentOperationId,
    string $freightItemId,
    string $freightBatchId,
    string $trackingNo
): ?array {
    if ($freightItemId === '' && $freightBatchId === '' && $trackingNo === '') return null;
    foreach ($receipts as $receipt) {
        if (!is_array($receipt)) continue;
        $receiptId = receipt_text($receipt['id'] ?? '');
        $receiptOperationId = receipt_text($receipt['operationId'] ?? '');
        if (($currentDocumentId !== '' && $receiptId === $currentDocumentId) || ($currentOperationId !== '' && $receiptOperationId === $currentOperationId)) continue;
        $status = strtolower(receipt_text($receipt['status'] ?? ''));
        if (!in_array($status, ['confirming', 'received', 'reconciliation_required'], true) && empty($receipt['reconciliationRequired'])) continue;

        $receiptItemId = receipt_text($receipt['freightItemId'] ?? '');
        $matches = $freightItemId !== '' && $receiptItemId === $freightItemId;
        foreach (is_array($receipt['receiptLines'] ?? null) ? $receipt['receiptLines'] : [] as $line) {
            if (!is_array($line)) continue;
            $snapshot = isset($line['freightCostSnapshot']) && is_array($line['freightCostSnapshot']) ? $line['freightCostSnapshot'] : [];
            if ($freightItemId !== '' && receipt_text($snapshot['itemId'] ?? '') === $freightItemId) { $matches = true; break; }
        }
        $receiptBatchId = receipt_text($receipt['freightBatchId'] ?? '');
        $receiptTrackingNo = receipt_text($receipt['freightTrackingNo'] ?? '');
        if (!$matches && $freightItemId === '') {
            // The new receipt is broad (batch/tracking only), so it overlaps
            // every earlier item-specific receipt under that reference too.
            if ($freightBatchId !== '' && $receiptBatchId === $freightBatchId) $matches = true;
            if (!$matches && $trackingNo !== '' && $receiptTrackingNo === $trackingNo) $matches = true;
        } elseif (!$matches && $receiptItemId === '') {
            // The new receipt is item-specific, while an older broad receipt
            // already owned the whole batch/parcel.
            if ($freightBatchId !== '' && $receiptBatchId === $freightBatchId) $matches = true;
            if (!$matches && $trackingNo !== '' && $receiptTrackingNo === $trackingNo) $matches = true;
        }
        if (!$matches) continue;
        return [
            'documentId' => $receiptId,
            'documentNo' => receipt_first_text([
                is_array($receipt['receivingDocument'] ?? null) ? ($receipt['receivingDocument']['documentNo'] ?? '') : '',
                $receipt['documentNo'] ?? '',
            ]),
            'operationId' => $receiptOperationId,
            'status' => $status !== '' ? $status : 'reconciliation_required',
        ];
    }
    return null;
}

function freight_product_receive_claim_blocks_formal_receipt(array $claim, string $formalOperationId): bool {
    $claimOperationId = receipt_text($claim['operationId'] ?? '');
    $status = strtolower(receipt_text($claim['status'] ?? ''));
    if ($claimOperationId === '' || $claimOperationId === $formalOperationId) return false;
    if (!in_array($status, ['claimed', 'claiming', 'received', 'reconciliation_required'], true)) return false;
    return receipt_text($claim['claimType'] ?? '') === 'freight_product_receive';
}

function freight_product_receive_formal_claim_conflict(array $freightData, string $explicitItemId, string $batchId, string $trackingNo, string $formalOperationId): ?array {
    foreach (is_array($freightData['items'] ?? null) ? $freightData['items'] : [] as $item) {
        if (!is_array($item)) continue;
        $matches = false;
        if ($explicitItemId !== '') {
            $matches = receipt_text($item['id'] ?? '') === $explicitItemId;
        } elseif ($batchId !== '') {
            $matches = receipt_text($item['batchId'] ?? '') === $batchId;
        } elseif ($trackingNo !== '') {
            $matches = in_array($trackingNo, array_map('strval', [
                $item['trackingNo'] ?? '',
                $item['haohongTrackingNo'] ?? '',
            ]), true);
        }
        if (!$matches) continue;
        $claim = isset($item['inventoryReceiptClaim']) && is_array($item['inventoryReceiptClaim']) ? $item['inventoryReceiptClaim'] : [];
        if (!freight_product_receive_claim_blocks_formal_receipt($claim, $formalOperationId)) continue;
        return [
            'freightItemId' => receipt_text($item['id'] ?? ''),
            'operationId' => receipt_text($claim['operationId'] ?? ''),
            'status' => receipt_text($claim['status'] ?? ''),
        ];
    }
    return null;
}

function mark_preorder_items_as_ready(array $items, string $productsFile, string $skusFile, string $stateFile): void {
    $productIds = [];
    $skuIds = [];
    foreach ($items as $item) {
        $productId = trim((string)($item['productId'] ?? ''));
        $skuId = trim((string)($item['skuId'] ?? ($item['sku'] ?? '')));
        if ($productId !== '') $productIds[$productId] = true;
        if ($skuId !== '') $skuIds[$skuId] = true;
    }
    if (!$productIds && !$skuIds) return;
    $now = date(DATE_ATOM);

    $products = read_json($productsFile);
    $productChanged = false;
    foreach ($products as &$product) {
        if (!is_array($product)) continue;
        $id = (string)($product['id'] ?? '');
        $code = (string)($product['code'] ?? ($product['productLine'] ?? ''));
        if (($id !== '' && isset($productIds[$id])) || ($code !== '' && isset($productIds[$code]))) {
            $product['productMode'] = 'ready';
            $product['preorderOnly'] = false;
            $product['updatedAt'] = $now;
            $productChanged = true;
        }
    }
    unset($product);
    $skus = read_json($skusFile);
    $skuChanged = false;
    foreach ($skus as &$sku) {
        if (!is_array($sku)) continue;
        $id = (string)($sku['id'] ?? ($sku['sku'] ?? ''));
        $productId = (string)($sku['productId'] ?? '');
        if (($id !== '' && isset($skuIds[$id])) || ($productId !== '' && isset($productIds[$productId]))) {
            $sku['productMode'] = 'ready';
            $sku['preorderOnly'] = false;
            if (!isset($sku['status']) || (string)$sku['status'] === '') $sku['status'] = 'active';
            $sku['updatedAt'] = $now;
            $skuChanged = true;
        }
    }
    unset($sku);
    if ($skuChanged) write_json($skusFile, $skus);

    $readyTaiwanProductIds = [];
    foreach ($skus as $sku) {
        if (!is_array($sku) || !is_taiwan_sku($sku)) continue;
        if ((string)($sku['status'] ?? 'active') !== 'active' || (int)($sku['stock'] ?? 0) <= 0) continue;
        if ((string)($sku['productMode'] ?? '') === 'preorder' || ($sku['preorderOnly'] ?? false) === true) continue;
        $readyProductId = (string)($sku['productId'] ?? '');
        if ($readyProductId !== '') $readyTaiwanProductIds[$readyProductId] = true;
    }
    foreach ($products as &$product) {
        if (!is_array($product)) continue;
        $id = (string)($product['id'] ?? '');
        $code = (string)($product['code'] ?? ($product['productLine'] ?? ''));
        if (!(($id !== '' && isset($productIds[$id])) || ($code !== '' && isset($productIds[$code])))) continue;
        $product['storefrontManualHidden'] = false;
        $product['showOnWebsite'] = $id !== '' && isset($readyTaiwanProductIds[$id]);
        $productChanged = true;
    }
    unset($product);
    if ($productChanged) write_json($productsFile, $products);

    $state = read_json($stateFile);
    $stateChanged = false;
    if (isset($state['products']) && is_array($state['products'])) {
        foreach ($state['products'] as &$product) {
            if (!is_array($product)) continue;
            $id = (string)($product['id'] ?? '');
            $code = (string)($product['code'] ?? ($product['productLine'] ?? ''));
            if (($id !== '' && isset($productIds[$id])) || ($code !== '' && isset($productIds[$code]))) {
                $product['productMode'] = 'ready';
                $product['preorderOnly'] = false;
                $product['storefrontManualHidden'] = false;
                $product['showOnWebsite'] = $id !== '' && isset($readyTaiwanProductIds[$id]);
                $product['updatedAt'] = $now;
                $stateChanged = true;
            }
        }
        unset($product);
    }
    if (isset($state['skus']) && is_array($state['skus'])) {
        foreach ($state['skus'] as &$sku) {
            if (!is_array($sku)) continue;
            $id = (string)($sku['id'] ?? ($sku['sku'] ?? ''));
            $productId = (string)($sku['productId'] ?? '');
            if (($id !== '' && isset($skuIds[$id])) || ($productId !== '' && isset($productIds[$productId]))) {
                $sku['productMode'] = 'ready';
                $sku['preorderOnly'] = false;
                if (!isset($sku['status']) || (string)$sku['status'] === '') $sku['status'] = 'active';
                $sku['updatedAt'] = $now;
                $stateChanged = true;
            }
        }
        unset($sku);
    }
    if ($stateChanged) {
        $state['updatedAt'] = $now;
        write_json($stateFile, $state);
    }
    if ($productChanged || $skuChanged || $stateChanged) {
        @unlink($GLOBALS['dataDir'] . DIRECTORY_SEPARATOR . 'public-catalog.json');
    }
}

function public_catalog(string $productsFile, string $skusFile, bool $includeSalesFields = false): array {
    $products = read_json($productsFile);
    $skus = read_json($skusFile);
    $skuGroups = [];
    foreach ($skus as $sku) {
        if (!is_array($sku) || (string)($sku['status'] ?? 'active') !== 'active') continue;
        if (!$includeSalesFields && (
            (string)($sku['productMode'] ?? '') === 'china_sample'
            || ($sku['chinaSample'] ?? false) === true
            || (string)($sku['productMode'] ?? '') === 'preorder'
            || ($sku['preorderOnly'] ?? false) === true
            || !is_taiwan_sku($sku)
        )) continue;
        $productId = (string)($sku['productId'] ?? '');
        if ($productId === '' || (!$includeSalesFields && (int)($sku['stock'] ?? 0) <= 0)) continue;
        $skuGroups[$productId][] = $sku;
    }
    $rows = [];
    foreach ($products as $product) {
        if (!is_array($product)) continue;
        if (!$includeSalesFields && (
            (string)($product['productMode'] ?? '') === 'china_sample'
            || ($product['chinaSample'] ?? false) === true
            || (string)($product['productMode'] ?? '') === 'preorder'
            || ($product['preorderOnly'] ?? false) === true
        )) continue;
        $productId = (string)($product['id'] ?? '');
        if ($productId === '' || (!$includeSalesFields && empty($skuGroups[$productId]))) continue;
        $images = isset($product['images']) && is_array($product['images']) ? $product['images'] : [];
        $colors = isset($product['colors']) && is_array($product['colors']) ? $product['colors'] : [];
        $colorImageByName = [];
        foreach ($colors as $color) {
            if (!is_array($color)) continue;
            $image = (string)($color['image'] ?? $color['imageUrl'] ?? $color['photo'] ?? '');
            foreach ([$color['name'] ?? '', $color['code'] ?? ''] as $name) {
                $key = strtolower(trim((string)$name));
                if ($key !== '' && $image !== '') $colorImageByName[$key] = $image;
            }
        }
        $mainImage = (string)($product['mainImage'] ?? ($images[0] ?? ''));
        $productRow = [
            'id' => $productId,
            'code' => $product['code'] ?? $product['productLine'] ?? '',
            'barcode' => $product['barcode'] ?? $product['code'] ?? $product['productLine'] ?? '',
            'title' => $product['title'] ?? '',
            'frontTitle' => $product['frontTitle'] ?? '',
            'category' => $product['category'] ?? '',
            'price' => (float)($product['price'] ?? 0),
            'mainImage' => $mainImage,
            'images' => array_values(array_filter(array_unique(array_merge([$mainImage], $images, array_values($colorImageByName))))),
            'description' => $product['description'] ?? '',
            'skus' => array_values(array_map(function ($sku) use ($colorImageByName, $mainImage, $includeSalesFields) {
                $color = (string)($sku['colorName'] ?? $sku['color'] ?? '');
                $colorKey = strtolower(trim($color));
                $codeKey = strtolower(trim((string)($sku['colorCode'] ?? '')));
                $row = [
                    'id' => $sku['id'] ?? '',
                    'sku' => $sku['sku'] ?? $sku['id'] ?? '',
                    'barcode' => $sku['barcode'] ?? $sku['sku'] ?? $sku['id'] ?? '',
                    'color' => $color,
                    'colorCode' => $sku['colorCode'] ?? '',
                    'rawColor' => $sku['colorName'] ?? $sku['color'] ?? '',
                    'colorImage' => $sku['colorImage'] ?? $colorImageByName[$colorKey] ?? $colorImageByName[$codeKey] ?? $mainImage,
                    'size' => $sku['sizeName'] ?? $sku['size'] ?? 'NO SIZE',
                    'stock' => (int)($sku['stock'] ?? 0),
                    'price' => (float)($sku['price'] ?? 0),
                ];
                if ($includeSalesFields) {
                    $row = array_merge($row, [
                        'companyBarcode' => $sku['companyBarcode'] ?? '',
                        'officialBarcode' => $sku['officialBarcode'] ?? '',
                        'legacyBarcode' => $sku['legacyBarcode'] ?? '',
                        'mappingCode' => $sku['mappingCode'] ?? '',
                        'linkedBarcodes' => is_array($sku['linkedBarcodes'] ?? null) ? array_values($sku['linkedBarcodes']) : [],
                        'colorName' => $sku['colorName'] ?? $sku['color'] ?? '',
                        'colorNo' => $sku['colorNo'] ?? '',
                        'sizeName' => $sku['sizeName'] ?? $sku['size'] ?? 'NO SIZE',
                        'sizeCode' => $sku['sizeCode'] ?? '',
                        'sizeNo' => $sku['sizeNo'] ?? '',
                        'warehouse' => $sku['warehouse'] ?? '',
                        'warehouseName' => $sku['warehouseName'] ?? '',
                        'warehouseCode' => $sku['warehouseCode'] ?? '',
                        'status' => $sku['status'] ?? 'active',
                        'productMode' => $sku['productMode'] ?? '',
                        'preorderOnly' => !empty($sku['preorderOnly']),
                        'chinaSample' => !empty($sku['chinaSample']),
                        'cost' => (float)($sku['cost'] ?? 0),
                    ]);
                }
                return $row;
            }, $skuGroups[$productId] ?? [])),
        ];
        if ($includeSalesFields) {
            $productRow = array_merge($productRow, [
                'productCode' => $product['productCode'] ?? $product['code'] ?? '',
                'productLine' => $product['productLine'] ?? '',
                'companyBarcode' => $product['companyBarcode'] ?? '',
                'brand' => $product['brand'] ?? '',
                'brandName' => $product['brandName'] ?? '',
                'colors' => $colors,
                'sizes' => is_array($product['sizes'] ?? null) ? array_values($product['sizes']) : [],
                'warehouse' => $product['warehouse'] ?? '',
                'warehouseName' => $product['warehouseName'] ?? '',
                'warehouseCode' => $product['warehouseCode'] ?? '',
                'status' => $product['status'] ?? 'active',
                'productMode' => $product['productMode'] ?? '',
                'preorderOnly' => !empty($product['preorderOnly']),
                'chinaSample' => !empty($product['chinaSample']),
                'showOnWebsite' => $product['showOnWebsite'] ?? true,
                'cost' => (float)($product['cost'] ?? 0),
            ]);
        }
        $rows[] = $productRow;
    }
    return $rows;
}

function find_index(array $rows, string $id): int {
    foreach ($rows as $i => $row) {
        if ((string)($row['id'] ?? '') === $id) return $i;
    }
    return -1;
}

function row_is_wait_notify(array $row): bool {
    if (!empty($row['waitNotify'])) return true;
    if (strtolower(trim((string)($row['freightDispatchDecision'] ?? ''))) === 'ask_sales') return true;
    foreach (is_array($row['salesIssueReports'] ?? null) ? $row['salesIssueReports'] : [] as $report) {
        if (!is_array($report)) continue;
        if (strtolower(trim((string)($report['type'] ?? ''))) !== 'hold') continue;
        $status = strtolower(trim((string)($report['status'] ?? 'pending')));
        if ($status === '' || $status === 'pending') return true;
    }
    return false;
}

function find_inquiry_index_for_wait_notify(array $inquiries, string $inquiryId, string $orderId): int {
    $inquiryId = receipt_text($inquiryId);
    $orderId = receipt_text($orderId);
    if ($inquiryId !== '') {
        foreach ($inquiries as $index => $row) {
            if (is_array($row) && receipt_text($row['id'] ?? '') === $inquiryId) return (int)$index;
        }
    }
    if ($orderId !== '') {
        foreach ($inquiries as $index => $row) {
            if (!is_array($row)) continue;
            $converted = receipt_first_text([
                $row['convertedOrderId'] ?? '',
                $row['orderId'] ?? '',
                $row['formalOrderId'] ?? '',
            ]);
            if ($converted === $orderId) return (int)$index;
        }
    }
    return -1;
}

function find_order_index_for_wait_notify(array $orders, string $orderId, string $inquiryId): int {
    $orderId = receipt_text($orderId);
    $inquiryId = receipt_text($inquiryId);
    if ($orderId !== '') {
        $index = find_index($orders, $orderId);
        if ($index >= 0) return $index;
    }
    if ($inquiryId !== '') {
        foreach ($orders as $index => $order) {
            if (!is_array($order)) continue;
            $source = receipt_first_text([
                $order['sourceInquiryId'] ?? '',
                $order['convertedFromInquiryId'] ?? '',
                $order['inquiryId'] ?? '',
                $order['id'] ?? '',
            ]);
            if ($source === $inquiryId) return (int)$index;
        }
    }
    return -1;
}

function apply_wait_notify_fields(
    array &$row,
    bool $waitNotify,
    string $operatorName,
    string $now,
    string $note,
    string $source,
    bool $noteProvided = false,
    bool $noteOnly = false
): void {
    if ($waitNotify) {
        $already = !empty($row['waitNotify']);
        $row['waitNotify'] = true;
        if (!$noteOnly) {
            if (!$already || receipt_text($row['waitNotifyAt'] ?? '') === '') $row['waitNotifyAt'] = $now;
            $row['waitNotifyBy'] = $operatorName;
            if (!$already || receipt_text($row['waitNotifySource'] ?? '') === '') {
                $row['waitNotifySource'] = $source !== '' ? $source : 'admin';
            }
            $row['waitNotifyReleasedAt'] = '';
            $row['waitNotifyReleasedBy'] = '';
            $row['freightShippingSelected'] = false;
        }
        if ($noteProvided) $row['waitNotifyNote'] = $note;
        elseif ($note !== '') $row['waitNotifyNote'] = $note;
        elseif (!$already && !$noteOnly) $row['waitNotifyNote'] = '';
    } else {
        $row['waitNotify'] = false;
        $row['waitNotifyReleasedAt'] = $now;
        $row['waitNotifyReleasedBy'] = $operatorName;
        if (strtolower(receipt_text($row['freightDispatchDecision'] ?? '')) === 'ask_sales') {
            $row['freightDispatchDecision'] = '';
            $row['freightDispatchDecisionLabel'] = '';
            $row['freightDispatchNeedsSalesReview'] = false;
        }
        $reports = is_array($row['salesIssueReports'] ?? null) ? array_values($row['salesIssueReports']) : [];
        foreach ($reports as $reportIndex => $report) {
            if (!is_array($report)) continue;
            if (strtolower(receipt_text($report['type'] ?? '')) !== 'hold') continue;
            $status = strtolower(receipt_text($report['status'] ?? 'pending'));
            if ($status !== '' && $status !== 'pending') continue;
            $reports[$reportIndex]['status'] = 'resolved';
            $reports[$reportIndex]['resolvedAt'] = $now;
            $reports[$reportIndex]['resolvedBy'] = $operatorName;
            $reports[$reportIndex]['resolvedNote'] = '業務已通知，可以出貨';
        }
        $row['salesIssueReports'] = $reports;
    }
    $history = is_array($row['waitNotifyHistory'] ?? null) ? array_values($row['waitNotifyHistory']) : [];
    $history[] = [
        'waitNotify' => $waitNotify,
        'at' => $now,
        'by' => $operatorName,
        'note' => $note,
        'source' => $source,
    ];
    $row['waitNotifyHistory'] = array_slice($history, -50);
    $row['updatedAt'] = $now;
}

function row_is_wait_notify_hold(array $row): bool {
    if (!empty($row['waitNotify'])) return true;
    if (strtolower(receipt_text($row['freightDispatchDecision'] ?? '')) === 'ask_sales') return true;
    $reports = is_array($row['salesIssueReports'] ?? null) ? $row['salesIssueReports'] : [];
    foreach ($reports as $report) {
        if (!is_array($report)) continue;
        if (strtolower(receipt_text($report['type'] ?? '')) !== 'hold') continue;
        $status = strtolower(receipt_text($report['status'] ?? 'pending'));
        if ($status === '' || $status === 'pending') return true;
    }
    return false;
}

function apply_sales_fifo_confirm_fields(array &$row, string $operatorName, string $now): void {
    if (receipt_text($row['salesReleasedAt'] ?? '') === '') {
        $row['salesReleasedAt'] = $now;
        $row['salesReleasedBy'] = $operatorName;
    }
    $row['salesFifoConfirmAt'] = $now;
    $row['salesFifoConfirmBy'] = $operatorName;
    $row['shipmentApprovalStatus'] = 'sales_confirmed';
    $row['shipmentApprovalStatusLabel'] = '業務已確認可出／等待行政逐一處理';
    $row['updatedAt'] = $now;
}

function sales_fifo_confirm_allowed(array $row, array $trustedLogin): bool {
    $viewer = sales_scope_key(trim((string)($trustedLogin['account'] ?? '') . ' ' . (string)($trustedLogin['name'] ?? '')));
    $rowScope = sales_scope_from_row($row);
    if ($viewer === 'any') return $rowScope === 'any';
    return $rowScope !== 'any';
}

function require_sales_fifo_confirm_login(array $payload, array $row, string $salesCustomersFile, string $stateFile, string $salesSessionsFile): array {
    $login = is_array($payload['salesLogin'] ?? null) ? $payload['salesLogin'] : [];
    $trustedLogin = trusted_business_login($login, $salesCustomersFile, $stateFile, $salesSessionsFile);
    if (!$trustedLogin) respond(['ok' => false, 'error' => '業務登入已失效，請重新登入 / Sesi login sales sudah tidak berlaku.'], 403);
    if (($trustedLogin['role'] ?? '') !== 'admin' && !sales_fifo_confirm_allowed($row, $trustedLogin)) {
        respond(['ok' => false, 'error' => '這張客戶不屬於目前登入的業務，不能代按可以出貨'], 403);
    }
    return $trustedLogin;
}

function resolve_wait_notify_hold_reports(string $orderIssuesFile, array $orderIds, string $operatorName, string $now): void {
    $orderIds = array_values(array_unique(array_filter(array_map('strval', $orderIds), static function ($id) {
        return trim($id) !== '';
    })));
    if (!$orderIds) return;
    $idMap = array_fill_keys($orderIds, true);
    $allReports = read_json($orderIssuesFile);
    $changed = false;
    foreach ($allReports as $index => $report) {
        if (!is_array($report)) continue;
        if (strtolower(trim((string)($report['type'] ?? ''))) !== 'hold') continue;
        $status = strtolower(trim((string)($report['status'] ?? 'pending')));
        if ($status !== '' && $status !== 'pending') continue;
        if (!isset($idMap[trim((string)($report['orderId'] ?? ''))])) continue;
        $allReports[$index]['status'] = 'resolved';
        $allReports[$index]['resolvedAt'] = $now;
        $allReports[$index]['resolvedBy'] = $operatorName;
        $allReports[$index]['resolvedNote'] = '業務已通知，可以出貨';
        $changed = true;
    }
    if ($changed) write_json($orderIssuesFile, $allReports);
}

function item_image_from_catalog(array $item, array $products, array $skus): string {
    $given = (string)($item['image'] ?? '');
    if ($given !== '' && $given !== './assets/brand-logo.png') return $given;
    $skuKey = (string)($item['skuId'] ?? $item['sku'] ?? '');
    $productKey = (string)($item['productId'] ?? $item['code'] ?? '');
    $matchedSku = null;
    foreach ($skus as $sku) {
        if (!is_array($sku)) continue;
        foreach ([$sku['id'] ?? '', $sku['sku'] ?? '', $sku['barcode'] ?? '', $sku['companyBarcode'] ?? ''] as $value) {
            if ($skuKey !== '' && (string)$value === $skuKey) {
                $matchedSku = $sku;
                break 2;
            }
        }
    }
    $matchedProduct = null;
    $matchedProductId = (string)($matchedSku['productId'] ?? '');
    foreach ($products as $product) {
        if (!is_array($product)) continue;
        $productIds = [$product['id'] ?? '', $product['code'] ?? '', $product['productLine'] ?? '', $product['barcode'] ?? ''];
        if (($matchedProductId !== '' && (string)($product['id'] ?? '') === $matchedProductId) || ($productKey !== '' && in_array($productKey, array_map('strval', $productIds), true))) {
            $matchedProduct = $product;
            break;
        }
    }
    $colorName = trim((string)($item['color'] ?? $matchedSku['colorName'] ?? $matchedSku['color'] ?? ''));
    if ($matchedProduct && is_array($matchedProduct['colors'] ?? null)) {
        foreach ($matchedProduct['colors'] as $color) {
            if (!is_array($color)) continue;
            $names = array_map('strval', [$color['name'] ?? '', $color['color'] ?? '', $color['code'] ?? '', $color['colorCode'] ?? '']);
            if ($colorName !== '' && in_array($colorName, $names, true)) {
                $image = (string)($color['image'] ?? $color['imageUrl'] ?? $color['photo'] ?? '');
                if ($image !== '') return $image;
            }
        }
    }
    $skuImage = (string)($matchedSku['colorImage'] ?? $matchedSku['image'] ?? '');
    if ($skuImage !== '') return $skuImage;
    if ($matchedProduct) {
        $images = is_array($matchedProduct['images'] ?? null) ? $matchedProduct['images'] : [];
        return (string)($matchedProduct['mainImage'] ?? ($images[0] ?? ''));
    }
    return '';
}

function digits_only_value(string $value): string {
    return preg_replace('/\D+/', '', $value) ?? '';
}

function upsert_customer_contact_rows(array $rows, array $customer, string $prefix, string $now, string $target = 'customer'): array {
    return member_photo_sync_upsert_contact_rows($rows, $customer, $prefix, $now, $target);
}

function sync_customer_contact_to_members(array $customer, string $customersFile, string $membersFile, string $stateFile, string $now): array {
    return member_photo_sync_contact_to_files($customer, $customersFile, $membersFile, $stateFile, $now, 'LIVE');
}

function lower_text(string $value): string {
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function member_public_address(array $member): string {
    $sources = [$member];
    foreach (['member', 'customer', 'shipping', 'delivery', 'recipient', 'profile'] as $key) {
        if (isset($member[$key]) && is_array($member[$key])) $sources[] = $member[$key];
    }
    $primary = '';
    $storeAddress = '';
    $homeAddress = '';
    foreach ($sources as $source) {
        if ($primary === '') {
            $primary = member_photo_sync_address_primary($source['addressPrimary'] ?? $source['deliveryType'] ?? $source['deliveryLabel'] ?? '');
        }
        if ($storeAddress === '') $storeAddress = trim((string)($source['storeAddress'] ?? ''));
        if ($homeAddress === '') $homeAddress = trim((string)($source['homeAddress'] ?? ''));
    }
    if ($primary === 'store' && $storeAddress !== '') return $storeAddress;
    if ($primary === 'home' && $homeAddress !== '') return $homeAddress;
    $keys = [
        'address', 'shippingAddress', 'deliveryAddress', 'storeAddress', 'shipAddress',
        'recipientAddress', 'customerAddress', 'addressText', 'addressLine',
        'shipping_address', 'delivery_address', 'store_address', 'companyAddress', 'company_address'
    ];
    foreach ($sources as $source) {
        foreach ($keys as $key) {
            $value = trim((string)($source[$key] ?? ''));
            if ($value === '') continue;
            $compact = preg_replace('/\s+/u', '', $value) ?? $value;
            if (preg_match('/^(?:尚無地址|無地址|尚未輸入地址或店號|尚未輸入地址|地址未填|未提供|無|-|—)$/u', $compact)) continue;
            return $value;
        }
    }
    return '';
}

function member_public_match(array $member, string $sourceType = '', bool $lightweight = false): array {
    $identity = member_photo_sync_identity($member);
    $profileOnly = member_photo_sync_truthy($member['customerProfileOnly'] ?? $member['profileOnly'] ?? false);
    $refType = $sourceType !== '' ? $sourceType : trim((string)($member['customerRefType'] ?? ''));
    $photo = member_photo_sync_customer_photo($member);
    if ($lightweight && (stripos($photo, 'data:') === 0 || strlen($photo) > 2048)) {
        $photo = '';
    }
    return [
        'name' => $member['name'] ?? '',
        'phone' => $member['phone'] ?? '',
        'address' => member_public_address($member),
        'storeAddress' => trim((string)($member['storeAddress'] ?? '')),
        'homeAddress' => trim((string)($member['homeAddress'] ?? '')),
        'addressPrimary' => member_photo_sync_address_primary($member['addressPrimary'] ?? $member['deliveryType'] ?? $member['deliveryLabel'] ?? ''),
        'deliveryType' => $member['deliveryType'] ?? '',
        'deliveryLabel' => $member['deliveryLabel'] ?? '',
        'shippingFee' => $member['shippingFee'] ?? '',
        'customerTypeLabel' => $member['customerTypeLabel'] ?? '',
        'note' => $member['note'] ?? '',
        'salesName' => $member['salesName'] ?? '',
        'sales' => sales_scope_from_row($member),
        'code' => $member['code'] ?? $member['id'] ?? '',
        'customerRecordId' => $identity,
        'customerId' => $identity,
        'customerRef' => $identity,
        'customerCode' => $member['customerCode'] ?? $member['code'] ?? $member['sourceCode'] ?? $identity,
        'customerRefType' => $refType !== '' ? $refType : 'customer',
        'customerProfileOnly' => $profileOnly,
        'profileOnly' => $profileOnly,
        'photo' => $photo,
        'image' => $photo,
        'photoUpdatedAt' => $member['photoUpdatedAt'] ?? '',
        'updatedAt' => $member['updatedAt'] ?? '',
    ];
}

function member_match_score(array $member, string $name, string $phoneDigits, string $lastDigits, bool $loose = false): int {
    $rowName = lower_text((string)($member['name'] ?? $member['contact'] ?? $member['unitName'] ?? ''));
    $rowPhone = digits_only_value((string)($member['phone'] ?? ''));
    $score = 0;
    if ($phoneDigits !== '' && $rowPhone !== '') {
        if ($rowPhone === $phoneDigits) $score += 120;
        if ($loose && strlen($phoneDigits) >= 1 && strpos($rowPhone, $phoneDigits) !== false) $score += 45;
        if (strlen($phoneDigits) >= 5 && strpos($rowPhone, $phoneDigits) !== false) $score += 90;
        if (strlen($phoneDigits) >= 5 && substr($rowPhone, -strlen($phoneDigits)) === $phoneDigits) $score += 100;
    }
    if (strlen($lastDigits) >= 5 && strlen($rowPhone) >= strlen($lastDigits) && substr($rowPhone, -strlen($lastDigits)) === $lastDigits) $score += 80;
    if ($name !== '' && $rowName !== '') {
        if ($rowName === $name) $score += 90;
        if (strpos($rowName, $name) !== false || strpos($name, $rowName) !== false) $score += 65;
    }
    return $score;
}

function customer_match_compact_name(string $name): string {
    $name = lower_text(trim($name));
    $compact = preg_replace('/[\s\-_.(),，。／\/\\\\]+/u', '', $name);
    return is_string($compact) ? $compact : $name;
}

function customer_match_merge_public(array $existing, array $public): array {
    $existingUpdated = (string)($existing['updatedAt'] ?? '');
    $publicUpdated = (string)($public['updatedAt'] ?? '');
    $publicPhone = trim((string)($public['phone'] ?? ''));
    if ($publicUpdated !== '' && ($existingUpdated === '' || strcmp($publicUpdated, $existingUpdated) > 0)) {
        if ($publicPhone !== '') $existing['phone'] = $public['phone'];
        $existing['updatedAt'] = $publicUpdated;
        foreach (['address', 'storeAddress', 'homeAddress', 'addressPrimary', 'deliveryType', 'deliveryLabel'] as $field) {
            $publicValue = trim((string)($public[$field] ?? ''));
            if ($publicValue !== '') $existing[$field] = $public[$field];
        }
    }
    foreach (['address', 'storeAddress', 'homeAddress', 'addressPrimary', 'deliveryType', 'deliveryLabel', 'shippingFee', 'customerTypeLabel', 'salesName', 'photo', 'image', 'photoUpdatedAt', 'customerRecordId', 'customerId', 'customerRef', 'customerCode', 'code'] as $field) {
        $existingValue = trim((string)($existing[$field] ?? ''));
        $publicValue = trim((string)($public[$field] ?? ''));
        if ($existingValue === '' && $publicValue !== '') {
            $existing[$field] = $public[$field];
        }
    }
    if (!empty($public['customerProfileOnly']) || !empty($public['profileOnly'])) {
        $existing['customerProfileOnly'] = true;
        $existing['profileOnly'] = true;
    }
    $existing['_score'] = max((int)($existing['_score'] ?? 0), (int)($public['_score'] ?? 0));
    return $existing;
}

function find_customer_matches(string $name, string $phone, string $last6, string $customersFile, string $membersFile, int $limit = 8, bool $loose = false, bool $lightweight = false): array {
    $name = lower_text(trim($name));
    $phoneDigits = digits_only_value($phone);
    $lastDigits = digits_only_value($last6);
    if (strlen($phoneDigits) >= 5 && $lastDigits === '') $lastDigits = substr($phoneDigits, -min(6, strlen($phoneDigits)));
    if (!$loose && $name === '' && strlen($phoneDigits) < 5 && strlen($lastDigits) < 5) return [];
    if ($loose && $name === '' && $phoneDigits === '' && $lastDigits === '') return [];
    $matches = [];
    $seen = [];
    foreach ([
        ['type' => 'customer', 'rows' => read_json($customersFile)],
        ['type' => 'member', 'rows' => read_json($membersFile)],
    ] as $source) {
        $rows = $source['rows'];
        foreach ($rows as $member) {
            if (!is_array($member)) continue;
            // A complete phone number is an exact customer identity. Do not
            // let a similar name pull a different customer's phone into the
            // result list when the operator has already supplied that key.
            if (strlen($phoneDigits) >= 8) {
                $rowPhone = digits_only_value((string)($member['phone'] ?? ''));
                if ($rowPhone !== $phoneDigits) continue;
            }
            $score = member_match_score($member, $name, $phoneDigits, $lastDigits, $loose);
            if ($score <= 0) continue;
            $public = member_public_match($member, (string)$source['type'], $lightweight);
            $publicPhone = digits_only_value((string)($public['phone'] ?? ''));
            $identity = trim((string)($public['customerRecordId'] ?? ''));
            $keys = [];
            if ($identity !== '') $keys[] = 'identity:' . $identity;
            if (strlen($publicPhone) >= 6) $keys[] = 'phone:' . $publicPhone;
            if (!$keys) {
                $publicName = customer_match_compact_name((string)($public['name'] ?? ''));
                $keys[] = 'legacy:' . $publicName . '|' . lower_text((string)($public['address'] ?? '')) . '|' . (string)($public['photo'] ?? '');
            }
            $existingIndex = null;
            foreach ($keys as $key) {
                if (isset($seen[$key])) {
                    $existingIndex = (int)$seen[$key];
                    break;
                }
            }
            if ($existingIndex !== null) {
                $matches[$existingIndex] = customer_match_merge_public($matches[$existingIndex], $public);
                foreach ($keys as $key) $seen[$key] = $existingIndex;
                continue;
            }
            $newIndex = count($matches);
            foreach ($keys as $key) $seen[$key] = $newIndex;
            $public['_score'] = $score;
            $matches[] = $public;
        }
    }
    $nameGroups = [];
    foreach ($matches as $index => $row) {
        $compactName = customer_match_compact_name((string)($row['name'] ?? ''));
        if ($compactName === '') continue;
        $nameGroups[$compactName][] = $index;
    }
    $drop = [];
    foreach ($nameGroups as $indexes) {
        if (count($indexes) < 2) continue;
        $primary = $indexes[0];
        foreach ($indexes as $index) {
            if (!empty($matches[$index]['customerProfileOnly']) || !empty($matches[$index]['profileOnly'])) {
                $primary = $index;
                break;
            }
        }
        $primaryAddress = trim((string)($matches[$primary]['address'] ?? ''));
        foreach ($indexes as $index) {
            if ($index === $primary) continue;
            $otherAddress = trim((string)($matches[$index]['address'] ?? ''));
            if ($otherAddress !== '' && $primaryAddress !== '' && $otherAddress !== $primaryAddress) continue;
            $matches[$primary] = customer_match_merge_public($matches[$primary], $matches[$index]);
            $drop[$index] = true;
        }
    }
    if ($drop) {
        $matches = array_values(array_filter($matches, static function ($row, $index) use ($drop) {
            return empty($drop[$index]);
        }, ARRAY_FILTER_USE_BOTH));
    }
    usort($matches, fn($a, $b) => (int)($b['_score'] ?? 0) <=> (int)($a['_score'] ?? 0));
    $matches = array_slice($matches, 0, max(1, $limit));
    return array_map(function ($item) {
        unset($item['_score']);
        return $item;
    }, $matches);
}

function find_customer_match(string $name, string $phone, string $last6, string $customersFile, string $membersFile): ?array {
    $matches = find_customer_matches($name, $phone, $last6, $customersFile, $membersFile, 1);
    return $matches[0] ?? null;
}

function find_member_by_last6(string $last6, string $membersFile): ?array {
    $digits = digits_only_value($last6);
    if (strlen($digits) !== 6) return null;
    foreach (read_json($membersFile) as $member) {
        $phone = digits_only_value((string)($member['phone'] ?? ''));
        if (strlen($phone) >= 6 && substr($phone, -6) === $digits) return member_public_match($member, 'member');
    }
    return null;
}

function freight_match_line_coverage(array $ledger, array $lines): array {
    $ledger = lz_purchase_recompute($ledger, $lines);
    $lineGroups = [];
    foreach ($lines as $line) {
        if (!is_array($line)) continue;
        $key = lz_purchase_line_key($line['inquiryId'] ?? '', $line['lineId'] ?? '');
        $lineGroups[$key] = (string)($line['groupKey'] ?? '');
    }
    $covered = [];
    foreach ($ledger['allocations'] ?? [] as $allocation) {
        if (!is_array($allocation)) continue;
        $lineId = trim((string)($allocation['lineId'] ?? ''));
        $inquiryId = trim((string)($allocation['inquiryId'] ?? ''));
        if ($lineId === '' || $inquiryId === '') continue;
        $key = lz_purchase_line_key($inquiryId, $lineId);
        if (!isset($lineGroups[$key]) || (string)($allocation['groupKey'] ?? '') !== $lineGroups[$key]) continue;
        $covered[$key] = max(0, (int)($covered[$key] ?? 0)) + max(0, (int)($allocation['qty'] ?? 0));
    }
    return [$ledger, $covered];
}

function freight_match_line_is_open(array $line): bool {
    $status = strtolower(trim((string)($line['orderStatus'] ?? '')));
    if (in_array($status, ['cancelled', 'canceled', 'deleted', 'removed', 'converted', 'shipped', 'completed'], true)) return false;
    return trim((string)($line['convertedOrderId'] ?? '')) === '';
}

function freight_match_candidate_payload(array $line, int $coveredQty): array {
    $requestedQty = max(0, (int)($line['requestedQty'] ?? 0));
    $coveredQty = min($requestedQty, max(0, $coveredQty));
    return [
        'inquiryId' => (string)($line['inquiryId'] ?? ''),
        'customerOrderNo' => (string)($line['inquiryId'] ?? ''),
        'lineId' => (string)($line['lineId'] ?? ''),
        'itemIndex' => max(0, (int)($line['itemIndex'] ?? 0)),
        'groupKey' => (string)($line['groupKey'] ?? ''),
        'orderDay' => (string)($line['orderDay'] ?? ''),
        'customerName' => (string)($line['customerName'] ?? ''),
        'customerPhone' => (string)($line['customerPhone'] ?? ''),
        'orderType' => (string)($line['orderType'] ?? 'preorder'),
        'liveSource' => (string)($line['liveSource'] ?? ''),
        'liveSession' => (string)($line['liveSession'] ?? ''),
        'productId' => (string)($line['productId'] ?? ''),
        'skuId' => (string)($line['skuId'] ?? ''),
        'productCode' => (string)($line['code'] ?? ''),
        'productName' => (string)($line['title'] ?? ''),
        'productImage' => (string)($line['image'] ?? ''),
        'color' => (string)($line['color'] ?? ''),
        'size' => (string)($line['size'] ?? ''),
        'warehouse' => (string)($line['warehouse'] ?? ''),
        'warehouseLabel' => (string)($line['warehouseLabel'] ?? ''),
        'requestedQty' => $requestedQty,
        'coveredQty' => $coveredQty,
        'pendingQty' => max(0, $requestedQty - $coveredQty),
    ];
}

function freight_match_candidates(array $inquiries, array $ledger, string $trackingNo): array {
    $wanted = lz_purchase_normalize($trackingNo);
    if ($wanted === '') return [];
    $lines = lz_purchase_demand_lines($inquiries);
    [$ledger, $covered] = freight_match_line_coverage($ledger, $lines);
    $matches = [];
    foreach ($lines as $line) {
        if (!is_array($line) || !freight_match_line_is_open($line)) continue;
        $trackingNumbers = array_map('lz_purchase_normalize', is_array($line['supplierTrackingNos'] ?? null) ? $line['supplierTrackingNos'] : []);
        if (!in_array($wanted, $trackingNumbers, true)) continue;
        $lineKey = lz_purchase_line_key($line['inquiryId'] ?? '', $line['lineId'] ?? '');
        $candidate = freight_match_candidate_payload($line, (int)($covered[$lineKey] ?? 0));
        if ((int)$candidate['pendingQty'] > 0) $matches[] = $candidate;
    }
    return $matches;
}

function freight_barcode_key($value): string {
    $text = strtoupper(trim((string)$value));
    return preg_replace('/[^A-Z0-9]/', '', $text) ?? '';
}

function freight_sku_barcode_keys(array $sku): array {
    $keys = [];
    foreach (['id', 'sku', 'barcode', 'companyBarcode', 'officialBarcode', 'legacyBarcode', 'mappingCode', 'labelBarcode'] as $field) {
        $key = freight_barcode_key($sku[$field] ?? '');
        if ($key !== '') $keys[$key] = true;
    }
    foreach (is_array($sku['linkedBarcodes'] ?? null) ? $sku['linkedBarcodes'] : [] as $value) {
        $key = freight_barcode_key($value);
        if ($key !== '') $keys[$key] = true;
    }
    return array_keys($keys);
}

function freight_skus_for_identity(array $skus, string $identity): array {
    $wanted = freight_barcode_key($identity);
    if ($wanted === '') return [];
    $matches = [];
    foreach ($skus as $sku) {
        if (!is_array($sku)) continue;
        if (in_array($wanted, freight_sku_barcode_keys($sku), true)) $matches[] = $sku;
    }
    return $matches;
}

function freight_line_matches_barcode(array $line, array $skus, string $barcode): bool {
    $wanted = freight_barcode_key($barcode);
    if ($wanted === '') return false;
    foreach (['skuId', 'code'] as $field) {
        $identity = freight_barcode_key($line[$field] ?? '');
        if ($identity === '') continue;
        if ($identity === $wanted) return true;
        foreach (freight_skus_for_identity($skus, $identity) as $sku) {
            if (in_array($wanted, freight_sku_barcode_keys($sku), true)) return true;
        }
    }
    return false;
}

function freight_barcode_match_candidates(array $inquiries, array $ledger, array $skus, string $barcode): array {
    $wanted = freight_barcode_key($barcode);
    if ($wanted === '') return [];
    $lines = lz_purchase_demand_lines($inquiries);
    [$ledger, $covered] = freight_match_line_coverage($ledger, $lines);
    $matches = [];
    foreach ($lines as $line) {
        if (!is_array($line) || !freight_match_line_is_open($line) || !freight_line_matches_barcode($line, $skus, $wanted)) continue;
        $lineKey = lz_purchase_line_key($line['inquiryId'] ?? '', $line['lineId'] ?? '');
        $candidate = freight_match_candidate_payload($line, (int)($covered[$lineKey] ?? 0));
        if ((int)$candidate['pendingQty'] <= 0) continue;
        $candidate['matchedBarcode'] = $barcode;
        $matches[] = $candidate;
    }
    return $matches;
}

function freight_product_code_match_candidates(array $inquiries, array $ledger, string $productCode): array {
    $wanted = lz_purchase_normalize($productCode);
    if ($wanted === '') return [];
    $lines = lz_purchase_demand_lines($inquiries);
    [$ledger, $covered] = freight_match_line_coverage($ledger, $lines);
    $matches = [];
    foreach ($lines as $line) {
        if (!is_array($line) || !freight_match_line_is_open($line)) continue;
        if (lz_purchase_normalize($line['code'] ?? '') !== $wanted) continue;
        $lineKey = lz_purchase_line_key($line['inquiryId'] ?? '', $line['lineId'] ?? '');
        $candidate = freight_match_candidate_payload($line, (int)($covered[$lineKey] ?? 0));
        if ((int)$candidate['pendingQty'] <= 0) continue;
        $matches[] = $candidate;
    }
    return $matches;
}

function freight_product_code_inventory(array $products, array $skus, string $productCode): array {
    $wanted = lz_purchase_normalize($productCode);
    if ($wanted === '') return ['products' => [], 'stockRows' => [], 'totalStockQty' => 0];
    $matchedProducts = [];
    $productIds = [];
    foreach ($products as $product) {
        if (!is_array($product)) continue;
        $identities = [$product['code'] ?? '', $product['productCode'] ?? '', $product['id'] ?? ''];
        $matched = false;
        foreach ($identities as $identity) {
            if (lz_purchase_normalize($identity) === $wanted) { $matched = true; break; }
        }
        if (!$matched) continue;
        $id = trim((string)($product['id'] ?? ''));
        if ($id !== '') $productIds[lz_purchase_normalize($id)] = true;
        $matchedProducts[] = [
            'id' => $id,
            'code' => trim((string)($product['code'] ?? ($product['productCode'] ?? ''))),
            'title' => trim((string)($product['title'] ?? ($product['name'] ?? ''))),
            'category' => trim((string)($product['category'] ?? ($product['categoryName'] ?? ''))),
            'brand' => trim((string)($product['brand'] ?? ($product['brandName'] ?? ''))),
            'spec' => trim((string)($product['spec'] ?? ($product['specification'] ?? ''))),
        ];
    }
    $stockRows = [];
    $totalStockQty = 0;
    foreach ($skus as $sku) {
        if (!is_array($sku)) continue;
        $skuProductId = lz_purchase_normalize($sku['productId'] ?? '');
        $skuCode = lz_purchase_normalize($sku['productCode'] ?? '');
        if (!isset($productIds[$skuProductId]) && $skuCode !== $wanted) continue;
        if (strtolower(trim((string)($sku['status'] ?? 'active'))) !== 'active') continue;
        $stock = max(0, (int)($sku['stock'] ?? 0));
        if ($stock <= 0) continue;
        $warehouseCode = strtoupper(trim((string)($sku['warehouseCode'] ?? '')));
        $warehouse = trim((string)($sku['warehouse'] ?? ($sku['warehouseName'] ?? '')));
        if ($warehouse === '') $warehouse = $warehouseCode === 'CN' ? '中國倉' : ($warehouseCode === 'ID' ? '印尼倉' : '台灣倉');
        $stockRows[] = [
            'productId' => trim((string)($sku['productId'] ?? '')),
            'productCode' => trim((string)($sku['productCode'] ?? ($matchedProducts[0]['code'] ?? $productCode))),
            'skuId' => trim((string)($sku['id'] ?? ($sku['sku'] ?? ''))),
            'barcode' => trim((string)($sku['companyBarcode'] ?? ($sku['barcode'] ?? ($sku['sku'] ?? ($sku['id'] ?? ''))))),
            'warehouseCode' => $warehouseCode,
            'warehouse' => $warehouse,
            'color' => trim((string)($sku['colorName'] ?? ($sku['color'] ?? ''))),
            'size' => trim((string)($sku['sizeName'] ?? ($sku['size'] ?? ''))),
            'category' => trim((string)($sku['category'] ?? ($matchedProducts[0]['category'] ?? ''))),
            'brand' => trim((string)($sku['brand'] ?? ($matchedProducts[0]['brand'] ?? ''))),
            'spec' => trim((string)($sku['spec'] ?? ($matchedProducts[0]['spec'] ?? ''))),
            'title' => trim((string)($matchedProducts[0]['title'] ?? '')),
            'stock' => $stock,
        ];
        $totalStockQty += $stock;
    }
    return ['products' => $matchedProducts, 'stockRows' => $stockRows, 'totalStockQty' => $totalStockQty];
}

function freight_item_barcode_quantity(array $item, string $barcode): int {
    $wanted = freight_barcode_key($barcode);
    if ($wanted === '') return 0;
    $variantMatched = false;
    $quantity = 0;
    foreach (is_array($item['variants'] ?? null) ? $item['variants'] : [] as $variant) {
        if (!is_array($variant)) continue;
        $matched = false;
        foreach (['sampleBarcode', 'taiwanBarcode', 'barcode', 'companyBarcode', 'skuId'] as $field) {
            if (freight_barcode_key($variant[$field] ?? '') === $wanted) { $matched = true; break; }
        }
        if (!$matched) continue;
        $variantMatched = true;
        $quantity += max(1, (int)($variant['quantity'] ?? $variant['qty'] ?? 1));
    }
    if ($variantMatched) return $quantity;
    foreach (['sampleBarcode', 'taiwanBarcode', 'barcode', 'companyBarcode', 'productFiledBarcode', 'skuId', 'productFiledSkuId'] as $field) {
        if (freight_barcode_key($item[$field] ?? '') === $wanted) return max(1, (int)($item['quantity'] ?? $item['qty'] ?? 1));
    }
    return 0;
}

function freight_item_is_formally_stocked(array $item): bool {
    if (!empty($item['productFiledInventoryReceived'])) return true;
    if (strtolower(trim((string)($item['inventoryStatus'] ?? ''))) === 'received') return true;
    $status = trim((string)($item['progress'] ?? $item['freightReceivingStatus'] ?? ''));
    return preg_match('/已完成正式入庫|已帶入產品完成入庫|已完成入庫/u', $status) === 1;
}

function freight_barcode_availability(array $skus, array $freightData, string $barcode): array {
    $wanted = freight_barcode_key($barcode);
    $readyQty = 0;
    $readySkuIds = [];
    foreach ($skus as $sku) {
        if (!is_array($sku) || !in_array($wanted, freight_sku_barcode_keys($sku), true) || !is_taiwan_sku($sku)) continue;
        if (strtolower(trim((string)($sku['status'] ?? 'active'))) !== 'active') continue;
        $stock = max(0, (int)($sku['stock'] ?? 0));
        if ($stock <= 0) continue;
        $readyQty += $stock;
        $readySkuIds[] = (string)($sku['id'] ?? $sku['sku'] ?? '');
    }
    $forecastQty = 0;
    $forecastItemIds = [];
    foreach (is_array($freightData['items'] ?? null) ? $freightData['items'] : [] as $item) {
        if (!is_array($item) || freight_item_is_formally_stocked($item)) continue;
        $qty = freight_item_barcode_quantity($item, $wanted);
        if ($qty <= 0) continue;
        $forecastQty += $qty;
        $forecastItemIds[] = (string)($item['id'] ?? '');
    }
    $mode = $readyQty > 0 ? 'ready_stock' : ($forecastQty > 0 ? 'freight_forecast' : 'no_supply');
    return [
        'mode' => $mode,
        'readyStockQty' => $readyQty,
        'forecastQty' => $forecastQty,
        'readySkuIds' => array_values(array_filter(array_unique($readySkuIds))),
        'forecastItemIds' => array_values(array_filter(array_unique($forecastItemIds))),
    ];
}

function freight_match_saved_item(array $freightData, string $freightItemId): ?array {
    $items = is_array($freightData['items'] ?? null) ? $freightData['items'] : [];
    foreach ($items as $item) {
        if (is_array($item) && trim((string)($item['id'] ?? '')) === $freightItemId) return $item;
    }
    return null;
}

function freight_match_covered_qty(array $ledger, array $lines, array $line): int {
    [, $covered] = freight_match_line_coverage($ledger, $lines);
    $lineKey = lz_purchase_line_key($line['inquiryId'] ?? '', $line['lineId'] ?? '');
    $requested = max(0, (int)($line['requestedQty'] ?? 0));
    return min($requested, max(0, (int)($covered[$lineKey] ?? 0)));
}

function freight_match_purchase_by_id(array $ledger, string $purchaseId): ?array {
    if ($purchaseId === '') return null;
    foreach ($ledger['purchases'] ?? [] as $purchase) {
        if (is_array($purchase) && (string)($purchase['purchaseId'] ?? '') === $purchaseId) return $purchase;
    }
    return null;
}

function freight_match_purchase_identity(array $purchase): array {
    $allocationRows = is_array($purchase['allocationRows'] ?? null) ? $purchase['allocationRows'] : [];
    $allocation = is_array($allocationRows[0] ?? null) ? $allocationRows[0] : [];
    return [
        'inquiryId' => trim((string)($purchase['inquiryId'] ?? $allocation['inquiryId'] ?? $purchase['surplusAnchorInquiryId'] ?? '')),
        'lineId' => trim((string)($purchase['inquiryLineId'] ?? $allocation['lineId'] ?? $purchase['surplusAnchorLineId'] ?? '')),
        'itemIndex' => array_key_exists('inquiryItemIndex', $purchase) ? max(0, (int)$purchase['inquiryItemIndex']) : null,
    ];
}

function freight_match_duplicate_mismatch(
    array $purchase,
    string $freightItemId,
    string $trackingNo,
    string $inquiryId,
    string $lineId,
    int $itemIndex,
    bool $manualOverrideConfirmed,
    string $manualOverrideReason
): string {
    if ((string)($purchase['sourceType'] ?? '') !== 'freight-customer-match') return '相同操作編號已用於其他採購操作';
    if ((string)($purchase['status'] ?? 'active') !== 'active') return '這筆物流配對已取消，不能當作成功重送';
    if (!hash_equals((string)($purchase['freightItemId'] ?? ''), $freightItemId)) return '相同操作編號的物流明細不同';
    $savedTracking = lz_purchase_normalize($purchase['trackingNo'] ?? '');
    if ($savedTracking === '' || !hash_equals($savedTracking, lz_purchase_normalize($trackingNo))) return '這筆物流明細已用不同的物流單號配對';

    $identity = freight_match_purchase_identity($purchase);
    if ($identity['inquiryId'] === '' || !hash_equals($identity['inquiryId'], $inquiryId)) return '這筆物流明細已配對其他客戶訂單';
    if ($lineId !== '' && ($identity['lineId'] === '' || !hash_equals($identity['lineId'], $lineId))) return '這筆物流明細已配對同客戶訂單的其他品項';
    if ($lineId === '' && $identity['itemIndex'] !== null && (int)$identity['itemIndex'] !== $itemIndex) return '這筆物流明細已配對同客戶訂單的其他品項';

    if ((string)($purchase['matchMode'] ?? '') === 'manual-order') {
        if (!$manualOverrideConfirmed) return '這筆配對原為人工訂單備援，重送時必須保留明確確認';
        $savedReason = trim((string)($purchase['manualOverrideReason'] ?? ''));
        if ($savedReason === '' || !hash_equals($savedReason, $manualOverrideReason)) return '同一筆人工訂單備援的原因不一致';
    }
    return '';
}

function freight_match_duplicate_line_payload(array $purchase, array $ledger, array $lines): array {
    $identity = freight_match_purchase_identity($purchase);
    foreach ($lines as $line) {
        if (!is_array($line)) continue;
        if ((string)($line['inquiryId'] ?? '') !== $identity['inquiryId']) continue;
        if ($identity['lineId'] !== '' && (string)($line['lineId'] ?? '') !== $identity['lineId']) continue;
        if ($identity['lineId'] === '' && $identity['itemIndex'] !== null && (int)($line['itemIndex'] ?? -1) !== (int)$identity['itemIndex']) continue;
        return freight_match_candidate_payload($line, freight_match_covered_qty($ledger, $lines, $line));
    }

    $requestedQty = max(1, (int)($purchase['requestedQtyAtMatch'] ?? $purchase['purchasedQty'] ?? 1));
    $coveredQty = min($requestedQty, max(1, (int)($purchase['allocatedQty'] ?? $purchase['purchasedQty'] ?? 1)));
    return [
        'inquiryId' => $identity['inquiryId'],
        'customerOrderNo' => $identity['inquiryId'],
        'lineId' => $identity['lineId'],
        'itemIndex' => $identity['itemIndex'] !== null ? (int)$identity['itemIndex'] : 0,
        'groupKey' => (string)($purchase['groupKey'] ?? ''),
        'orderDay' => (string)($purchase['scopeFrom'] ?? $purchase['surplusAnchorDay'] ?? ''),
        'customerName' => (string)($purchase['customerName'] ?? ''),
        'customerPhone' => (string)($purchase['customerPhone'] ?? ''),
        'productId' => (string)($purchase['productId'] ?? ''),
        'skuId' => (string)($purchase['skuId'] ?? ''),
        'productCode' => (string)($purchase['code'] ?? ''),
        'productName' => (string)($purchase['title'] ?? ''),
        'productImage' => (string)($purchase['productImage'] ?? ''),
        'color' => (string)($purchase['color'] ?? ''),
        'size' => (string)($purchase['size'] ?? ''),
        'warehouse' => (string)($purchase['warehouse'] ?? ''),
        'warehouseLabel' => (string)($purchase['warehouseLabel'] ?? ''),
        'requestedQty' => $requestedQty,
        'coveredQty' => $coveredQty,
        'pendingQty' => max(0, $requestedQty - $coveredQty),
    ];
}

if (!is_dir($dataDir) && !mkdir($dataDir, 0775, true)) respond(['ok' => false, 'error' => 'Folder data tidak bisa dibuat'], 500);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = (string)($_GET['action'] ?? 'catalog');
    if ($action === 'catalog') {
        $login = null;
        $loginRaw = (string)($_GET['salesLogin'] ?? '');
        if ($loginRaw !== '') {
            $decoded = json_decode($loginRaw, true);
            if (is_array($decoded)) $login = $decoded;
        }
        $includeSalesFields = valid_sales_account($login, $salesCustomersFile, $stateFile, $salesSessionsFile);
        respond(['ok' => true, 'salesMode' => $includeSalesFields, 'products' => public_catalog($productsFile, $skusFile, $includeSalesFields)]);
    }
    if ($action === 'purchase-summary') {
        require_purchase_operator($adminSessionsFile, $stateFile);
        [$from, $to] = lz_purchase_date_range($_GET['from'] ?? '', $_GET['to'] ?? '');
        $summary = lz_purchase_summary(read_json($inquiriesFile), lz_purchase_ledger_read($preorderPurchaseLedgerFile), $from, $to);
        respond(['ok' => true, 'summary' => $summary]);
    }
    if ($action === 'shortage-confirmations') {
        require_purchase_operator($adminSessionsFile, $stateFile, ['物流集運', '預購訂單', '訂單管理']);
        $ledger = lz_purchase_ledger_read($preorderPurchaseLedgerFile);
        respond([
            'ok' => true,
            'confirmations' => $ledger['shortageConfirmations'] ?? [],
            'ledgerRevision' => max(0, (int)($ledger['revision'] ?? 0)),
        ]);
    }
    if ($action === 'freight-match-candidates') {
        require_purchase_operator($adminSessionsFile, $stateFile, ['採購區', '採購進貨建檔', '物流集運']);
        $trackingNo = trim((string)($_GET['trackingNo'] ?? ''));
        if ($trackingNo === '') respond(['ok' => false, 'error' => '請先輸入正式物流單號'], 400);
        $candidates = freight_match_candidates(read_json($inquiriesFile), lz_purchase_ledger_read($preorderPurchaseLedgerFile), $trackingNo);
        $groupKeys = array_values(array_unique(array_filter(array_map(static fn($row) => (string)($row['groupKey'] ?? ''), $candidates))));
        respond([
            'ok' => true,
            'trackingNo' => $trackingNo,
            'candidates' => $candidates,
            'candidateCount' => count($candidates),
            'productGroupCount' => count($groupKeys),
            // The same tracking number may cover several customers ordering
            // the same product group. Only a single exact customer line is
            // safe to auto-select; all multi-candidate results require an
            // explicit operator choice.
            'autoCandidate' => count($candidates) === 1 ? $candidates[0] : null,
            'remainingQty' => array_reduce($candidates, static fn($sum, $row) => $sum + max(0, (int)($row['pendingQty'] ?? 0)), 0),
        ]);
    }
    if ($action === 'barcode-match-candidates') {
        require_purchase_operator($adminSessionsFile, $stateFile);
        $barcode = trim((string)($_GET['barcode'] ?? ''));
        if ($barcode === '') respond(['ok' => false, 'error' => '請先輸入或產生完整產品條碼'], 400);
        $skus = read_json($skusFile);
        $freightData = read_json($freightTrackingFile);
        $candidates = freight_barcode_match_candidates(read_json($inquiriesFile), lz_purchase_ledger_read($preorderPurchaseLedgerFile), $skus, $barcode);
        $availability = freight_barcode_availability($skus, $freightData, $barcode);
        respond([
            'ok' => true,
            'barcode' => $barcode,
            'candidates' => $candidates,
            'candidateCount' => count($candidates),
            'autoCandidate' => count($candidates) === 1 ? $candidates[0] : null,
            'remainingQty' => array_reduce($candidates, static fn($sum, $row) => $sum + max(0, (int)($row['pendingQty'] ?? 0)), 0),
            'availability' => $availability,
        ]);
    }
    if ($action === 'product-code-link-candidates') {
        require_purchase_operator($adminSessionsFile, $stateFile);
        $productCode = trim((string)($_GET['productCode'] ?? ''));
        if ($productCode === '') respond(['ok' => false, 'error' => '請先輸入或選擇產品編號'], 400);
        $products = read_json($productsFile);
        $skus = read_json($skusFile);
        $candidates = freight_product_code_match_candidates(read_json($inquiriesFile), lz_purchase_ledger_read($preorderPurchaseLedgerFile), $productCode);
        $inventory = freight_product_code_inventory($products, $skus, $productCode);
        $liveCount = count(array_filter($candidates, static fn($row) => trim((string)($row['liveSource'] ?? '')) !== ''));
        respond([
            'ok' => true,
            'productCode' => $productCode,
            'inventory' => $inventory,
            'candidates' => $candidates,
            'candidateCount' => count($candidates),
            'liveCandidateCount' => $liveCount,
            'preorderCandidateCount' => max(0, count($candidates) - $liveCount),
            'remainingQty' => array_reduce($candidates, static fn($sum, $row) => $sum + max(0, (int)($row['pendingQty'] ?? 0)), 0),
        ]);
    }
    if ($action === 'list') {
        $sales = valid_sales((string)($_GET['sales'] ?? ''));
        $role = strtolower((string)($_GET['role'] ?? 'sales'));
        $rows = read_json($inquiriesFile);
        if ($role !== 'admin') {
            $viewer = sales_scope_key((string)($_GET['sales'] ?? ''));
            if ($viewer === 'any') {
                $rows = sales_scope_filter_rows($rows, 'any');
            } else {
                $rows = array_values(array_filter($rows, fn($row) => valid_sales((string)($row['sales'] ?? '')) === $sales));
            }
        }
        respond(['ok' => true, 'inquiries' => $rows]);
    }
    if ($action === 'purchase-receipts') {
        $rows = read_json($purchaseReceiptsFile);
        usort($rows, static function ($a, $b): int {
            return strcmp((string)($b['updatedAt'] ?? $b['createdAt'] ?? ''), (string)($a['updatedAt'] ?? $a['createdAt'] ?? ''));
        });
        respond(['ok' => true, 'receipts' => $rows]);
    }
    if ($action === 'member') {
        $salesLookup = (string)($_GET['salesLookup'] ?? '') === '1';
        $members = find_customer_matches((string)($_GET['name'] ?? ''), (string)($_GET['phone'] ?? ''), (string)($_GET['last6'] ?? ''), $customersFile, $membersFile, 8, $salesLookup, $salesLookup);
        $viewer = sales_scope_key((string)($_GET['sales'] ?? ''));
        if ($viewer === 'any') {
            $members = sales_scope_filter_rows($members, 'any');
        }
        respond(['ok' => true, 'member' => $members[0] ?? null, 'members' => $members]);
    }
    if ($action === 'temp-products') {
        $login = null;
        $loginRaw = (string)($_GET['salesLogin'] ?? '');
        if ($loginRaw !== '') {
            $decoded = json_decode($loginRaw, true);
            if (is_array($decoded)) $login = $decoded;
        }
        $role = strtolower((string)($_GET['role'] ?? 'sales'));
        $rows = temp_product_rows($tempProductsFile);
        if ($role !== 'admin') {
            if (!valid_sales_account($login, $salesCustomersFile, $stateFile, $salesSessionsFile)) {
                respond(['ok' => false, 'error' => 'Sales login diperlukan'], 403);
            }
            $account = strtolower(trim((string)($login['account'] ?? '')));
            $rows = array_values(array_filter($rows, fn($row) => strtolower(trim((string)($row['salesAccount'] ?? ''))) === $account));
        }
        respond(['ok' => true, 'businessDate' => date('Y-m-d'), 'tempProducts' => $rows]);
    }
    respond(['ok' => false, 'error' => 'Method not allowed'], 405);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['ok' => false, 'error' => 'Method not allowed'], 405);

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) respond(['ok' => false, 'error' => 'Format data salah'], 400);
$action = (string)($payload['action'] ?? 'create');

if ($action === 'confirm-shortage') {
    $operator = require_purchase_operator($adminSessionsFile, $stateFile, ['物流集運', '預購訂單', '訂單管理']);
    $inquiryId = trim((string)($payload['inquiryId'] ?? ''));
    $lineId = trim((string)($payload['lineId'] ?? ''));
    $signature = trim((string)($payload['signature'] ?? ''));
    if ($inquiryId === '' || $lineId === '') respond(['ok' => false, 'error' => '缺少欠貨客戶訂單或品項識別'], 400);
    if ($signature === '') respond(['ok' => false, 'error' => '請由承辦人員親自輸入署名'], 400);
    if (function_exists('mb_strlen') ? mb_strlen($signature, 'UTF-8') > 40 : strlen($signature) > 120) respond(['ok' => false, 'error' => '署名過長，請輸入承辦人姓名'], 400);

    $targetLine = null;
    foreach (lz_purchase_demand_lines(read_json($inquiriesFile)) as $line) {
        if ((string)($line['inquiryId'] ?? '') === $inquiryId && (string)($line['lineId'] ?? '') === $lineId) {
            $targetLine = $line;
            break;
        }
    }
    if (!$targetLine) respond(['ok' => false, 'error' => '找不到這筆尚欠客戶品項，請重新整理'], 404);

    $lock = lz_purchase_ledger_lock($preorderPurchaseLedgerFile);
    $ledger = lz_purchase_ledger_read($preorderPurchaseLedgerFile);
    $key = lz_purchase_shortage_confirmation_key($inquiryId, $lineId);
    $now = date(DATE_ATOM);
    $confirmedBy = trim((string)($operator['name'] ?? $operator['account'] ?? '')) ?: '管理者';
    $confirmation = [
        'confirmationKey' => $key,
        'inquiryId' => $inquiryId,
        'lineId' => $lineId,
        'groupKey' => (string)($targetLine['groupKey'] ?? ''),
        'customerName' => (string)($targetLine['customerName'] ?? ''),
        'customerPhone' => (string)($targetLine['customerPhone'] ?? ''),
        'productCode' => (string)($targetLine['code'] ?? ''),
        'productName' => (string)($targetLine['title'] ?? ''),
        'color' => (string)($targetLine['color'] ?? ''),
        'size' => (string)($targetLine['size'] ?? ''),
        'requestedQty' => max(0, (int)($targetLine['requestedQty'] ?? 0)),
        'signature' => $signature,
        'confirmedBy' => $confirmedBy,
        'confirmedAt' => $now,
    ];
    $ledger['shortageConfirmations'][$key] = $confirmation;
    $ledger['revision'] = max(0, (int)($ledger['revision'] ?? 0)) + 1;
    $ledger['updatedAt'] = $now;
    $ledger['history'][] = ['event' => 'shortage-confirmed', 'confirmationKey' => $key, 'inquiryId' => $inquiryId, 'lineId' => $lineId, 'signature' => $signature, 'at' => $now, 'by' => $confirmedBy];
    $ledger['history'] = array_slice($ledger['history'], -1000);
    write_json($preorderPurchaseLedgerFile, $ledger);
    flock($lock, LOCK_UN);
    fclose($lock);
    respond(['ok' => true, 'confirmation' => $confirmation, 'ledgerRevision' => $ledger['revision']]);
}

if ($action === 'priority-reassign-formal-item') {
    $operator = require_purchase_operator($adminSessionsFile, $stateFile, ['物流集運', '預購訂單', '訂單管理']);
    $targetInquiryId = trim((string)($payload['targetInquiryId'] ?? ''));
    $targetOrderId = trim((string)($payload['targetOrderId'] ?? ''));
    $sourceOrderId = trim((string)($payload['sourceOrderId'] ?? ''));
    $sourceItemIndex = (int)($payload['sourceItemIndex'] ?? -1);
    $requestedQty = max(1, (int)($payload['qty'] ?? 1));
    $reason = trim((string)($payload['reason'] ?? ''));
    $operationId = trim((string)($payload['operationId'] ?? ''));
    if ($sourceOrderId === '' || $sourceItemIndex < 0 || $reason === '' || $operationId === '' || ($targetInquiryId === '' && $targetOrderId === '')) {
        respond(['ok' => false, 'error' => '缺少插隊對象、原正式單品項或插隊原因'], 400);
    }

    $operationLock = acquire_preorder_inventory_lock($inquiriesFile, 'priority-reassign-' . $sourceOrderId . '-' . ($targetInquiryId !== '' ? $targetInquiryId : $targetOrderId));
    $orders = read_json($ordersFile);
    $inquiries = read_json($inquiriesFile);
    $sourceOrderIndex = find_index($orders, $sourceOrderId);
    $targetInquiryIndex = $targetInquiryId !== '' ? find_index($inquiries, $targetInquiryId) : -1;
    $targetOrderIndex = $targetOrderId !== '' ? find_index($orders, $targetOrderId) : -1;
    if ($sourceOrderIndex < 0) respond(['ok' => false, 'error' => '找不到要退讓的正式出貨單'], 404);
    if ($targetInquiryIndex < 0 && $targetOrderIndex < 0) respond(['ok' => false, 'error' => '找不到要插隊的客戶訂單'], 404);
    $sourceOrder = $orders[$sourceOrderIndex];
    $targetInquiry = $targetInquiryIndex >= 0 ? $inquiries[$targetInquiryIndex] : [];
    foreach ((array)($sourceOrder['priorityReassignmentHistory'] ?? []) as $historyRow) {
        if (is_array($historyRow) && hash_equals((string)($historyRow['operationId'] ?? ''), $operationId)) {
            respond(['ok' => true, 'idempotentReplay' => true, 'sourceOrderId' => $sourceOrderId, 'targetInquiryId' => $targetInquiryId, 'releasedQty' => (int)($historyRow['qty'] ?? 0)]);
        }
    }
    $sourceStatus = strtolower(trim((string)($sourceOrder['status'] ?? '')));
    $sourceDelivery = strtolower(trim((string)($sourceOrder['deliveryState'] ?? '')));
    if (in_array($sourceStatus, ['shipped', 'in_transit', 'delivered', 'completed', 'cancelled', 'returned'], true)
        || in_array($sourceDelivery, ['in_transit', 'delivered', 'returned'], true)) {
        respond(['ok' => false, 'error' => '這張正式單已配送、完成、取消或退貨，不能再拿走配貨'], 409);
    }
    $sourceTracking = strtoupper(trim((string)($sourceOrder['trackingNo'] ?? $sourceOrder['logisticsNo'] ?? $sourceOrder['freightTrackingNo'] ?? '')));
    if ($sourceTracking !== '' && preg_match('/[0-9]/', $sourceTracking) && preg_match('/^[A-Z0-9-]{8,40}$/', $sourceTracking)) {
        respond(['ok' => false, 'error' => '這張已有物流條碼，不能抽走配貨'], 409);
    }
    if ((string)($targetInquiry['status'] ?? '') === 'cancelled') {
        respond(['ok' => false, 'error' => '插隊客戶已取消，不能重複調撥'], 409);
    }
    if ($targetOrderIndex < 0 && (
        (string)($targetInquiry['status'] ?? '') === 'converted'
        || receipt_text($targetInquiry['convertedOrderId'] ?? '') !== ''
        || receipt_text($targetInquiry['preorderConvertedAt'] ?? '') !== ''
    )) {
        $targetOrderId = receipt_text($targetInquiry['convertedOrderId'] ?? $targetInquiry['convertedToOrderId'] ?? $targetInquiry['formalOrderId'] ?? $targetInquiry['orderId'] ?? '');
        $targetOrderIndex = $targetOrderId !== '' ? find_index($orders, $targetOrderId) : -1;
        if ($targetOrderIndex < 0) respond(['ok' => false, 'error' => '插隊客戶已轉正式單，但找不到對應出貨單'], 404);
    }
    $sourceItems = is_array($sourceOrder['items'] ?? null) ? array_values($sourceOrder['items']) : [];
    if (!isset($sourceItems[$sourceItemIndex]) || !is_array($sourceItems[$sourceItemIndex])) {
        respond(['ok' => false, 'error' => '正式出貨單內找不到選取的商品'], 404);
    }
    $sourceItem = $sourceItems[$sourceItemIndex];
    $sourceQty = max(0, (int)($sourceItem['qty'] ?? $sourceItem['quantity'] ?? 0));
    $moveQty = min($requestedQty, $sourceQty);
    if ($moveQty <= 0) respond(['ok' => false, 'error' => '這個正式單品項已沒有可退讓數量'], 409);
    $sourceSkuId = trim((string)($sourceItem['skuId'] ?? $sourceItem['sku'] ?? ''));
    $sourceSku = null;
    foreach (read_json($skusFile) as $catalogSku) {
        if (is_array($catalogSku) && trim((string)($catalogSku['id'] ?? $catalogSku['sku'] ?? '')) === $sourceSkuId) {
            $sourceSku = $catalogSku;
            break;
        }
    }
    if (!is_array($sourceSku) || !receipt_sku_is_warehouse($sourceSku, receipt_warehouse_profile('TW'))) {
        respond(['ok' => false, 'error' => '只能退讓台灣倉正式配貨；這筆商品不是台灣倉 SKU'], 409);
    }

    $normalizeIdentity = static function ($value): string {
        $value = strtoupper(trim((string)$value));
        $value = preg_replace('/[^A-Z0-9]+/', '', $value) ?? '';
        return preg_replace('/P\d+$/', '', $value) ?? $value;
    };
    $sourceIds = array_filter(array_map($normalizeIdentity, [
        $sourceItem['productId'] ?? '', $sourceItem['code'] ?? '', $sourceItem['productCode'] ?? '',
        $sourceItem['skuId'] ?? '', $sourceItem['sku'] ?? '', $sourceItem['barcode'] ?? ''
    ]));
    $sourceColor = mb_strtoupper(trim((string)($sourceItem['color'] ?? $sourceItem['colorName'] ?? '')), 'UTF-8');
    $sourceSize = mb_strtoupper(trim((string)($sourceItem['size'] ?? $sourceItem['sizeName'] ?? 'NO SIZE')), 'UTF-8');
    $targetMatch = false;
    $targetMatchItems = $targetOrderIndex >= 0
        ? (array)($orders[$targetOrderIndex]['items'] ?? [])
        : (array)($targetInquiry['items'] ?? []);
    foreach ($targetMatchItems as $targetItem) {
        if (!is_array($targetItem)) continue;
        $targetIds = array_filter(array_map($normalizeIdentity, [
            $targetItem['productId'] ?? '', $targetItem['code'] ?? '', $targetItem['productCode'] ?? '',
            $targetItem['skuId'] ?? '', $targetItem['sku'] ?? '', $targetItem['barcode'] ?? ''
        ]));
        $identityMatch = (bool)array_intersect($sourceIds, $targetIds);
        $targetColor = mb_strtoupper(trim((string)($targetItem['color'] ?? $targetItem['colorName'] ?? '')), 'UTF-8');
        $targetSize = mb_strtoupper(trim((string)($targetItem['size'] ?? $targetItem['sizeName'] ?? 'NO SIZE')), 'UTF-8');
        if ($identityMatch && ($sourceColor === '' || $targetColor === '' || $sourceColor === $targetColor)
            && ($sourceSize === '' || $targetSize === '' || $sourceSize === $targetSize)) {
            $targetMatch = true;
            break;
        }
    }
    if (!$targetMatch) respond(['ok' => false, 'error' => '所選正式單商品與插隊客戶的產品／顏色／尺寸不一致'], 409);

    $now = date(DATE_ATOM);
    $operatorName = trim((string)($operator['name'] ?? $operator['account'] ?? '管理者'));
    $waitingItem = $sourceItem;
    $waitingItem['qty'] = $moveQty;
    $waitingItem['quantity'] = $moveQty;
    $waitingItem['requestedQty'] = $moveQty;
    $waitingItem['freightReceivedQty'] = 0;
    $waitingItem['priorityAllocationStatus'] = 'waiting_next_batch';
    $waitingItem['manualQueueDisplaced'] = true;
    $waitingItem['subtotal'] = round(max(0, (float)($waitingItem['price'] ?? 0)) * $moveQty, 2);

    if ($moveQty >= $sourceQty) array_splice($sourceItems, $sourceItemIndex, 1);
    else {
        $sourceItems[$sourceItemIndex]['qty'] = $sourceQty - $moveQty;
        $sourceItems[$sourceItemIndex]['quantity'] = $sourceQty - $moveQty;
        $sourceItems[$sourceItemIndex]['subtotal'] = round(max(0, (float)($sourceItem['price'] ?? 0)) * ($sourceQty - $moveQty), 2);
    }
    $sourceSubtotal = round(array_reduce($sourceItems, static fn($sum, $item) => $sum + max(0, (float)($item['price'] ?? 0)) * max(0, (int)($item['qty'] ?? $item['quantity'] ?? 0)), 0.0), 2);
    $sourceShipping = $sourceItems ? max(0, (float)($sourceOrder['shippingFee'] ?? 0)) : 0.0;
    $orders[$sourceOrderIndex]['items'] = $sourceItems;
    $orders[$sourceOrderIndex]['subtotal'] = $sourceSubtotal;
    $orders[$sourceOrderIndex]['shippingFee'] = $sourceShipping;
    $orders[$sourceOrderIndex]['total'] = round($sourceSubtotal + $sourceShipping, 2);
    $orders[$sourceOrderIndex]['updatedAt'] = $now;
    $orders[$sourceOrderIndex]['priorityReassignedQty'] = max(0, (int)($sourceOrder['priorityReassignedQty'] ?? 0)) + $moveQty;
    $orders[$sourceOrderIndex]['priorityReassignedAt'] = $now;
    $orders[$sourceOrderIndex]['priorityReassignedToInquiryId'] = $targetInquiryId;
    $orders[$sourceOrderIndex]['priorityReassignedReason'] = $reason;
    $orders[$sourceOrderIndex]['paymentAdjustmentRequired'] = true;
    $priorityHistory = is_array($sourceOrder['priorityReassignmentHistory'] ?? null) ? $sourceOrder['priorityReassignmentHistory'] : [];
    $priorityHistory[] = ['operationId' => $operationId, 'at' => $now, 'by' => $operatorName, 'targetInquiryId' => $targetInquiryId, 'sourceItemIndex' => $sourceItemIndex, 'qty' => $moveQty, 'reason' => $reason];
    $orders[$sourceOrderIndex]['priorityReassignmentHistory'] = array_slice($priorityHistory, -50);
    if (!$sourceItems) {
        $orders[$sourceOrderIndex]['status'] = 'reassigned_waiting_stock';
        $orders[$sourceOrderIndex]['statusLabel'] = '配貨已退讓／客戶等待下一批';
    }

    $sourceInquiryId = trim((string)($sourceOrder['sourceInquiryId'] ?? $sourceOrder['convertedFromInquiryId'] ?? $sourceOrder['inquiryId'] ?? ''));
    $sourceInquiryIndex = $sourceInquiryId !== '' ? find_index($inquiries, $sourceInquiryId) : -1;
    $baseWaiting = $sourceInquiryIndex >= 0 ? $inquiries[$sourceInquiryIndex] : [
        'customer' => $sourceOrder['customer'] ?? [], 'sales' => $sourceOrder['sales'] ?? '', 'salesName' => $sourceOrder['salesName'] ?? ''
    ];
    $waitingId = 'WAIT-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    foreach (['orderId', 'convertedOrderId', 'convertedToOrderId', 'formalOrderId', 'preorderConvertedAt', 'convertedAt', 'convertOperation', 'preorderConvertOperationId', 'preorderConvertPayloadHash'] as $field) unset($baseWaiting[$field]);
    $baseWaiting['id'] = $waitingId;
    $baseWaiting['items'] = [$waitingItem];
    $baseWaiting['orderType'] = 'preorder';
    $baseWaiting['status'] = 'preorder_pending';
    $baseWaiting['statusLabel'] = '插隊退讓／等待下一批';
    $baseWaiting['purchaseStatus'] = 'ordered';
    $baseWaiting['purchaseStatusLabel'] = '原配貨已退讓，等待下一批';
    $baseWaiting['reserved'] = false;
    $baseWaiting['inventoryReceived'] = false;
    $baseWaiting['manualQueueDisplaced'] = true;
    $baseWaiting['manualQueueDisplacedByInquiryId'] = $targetInquiryId;
    $baseWaiting['manualQueueDisplacedQty'] = $moveQty;
    $baseWaiting['manualQueueDisplacedReason'] = $reason;
    $baseWaiting['displacedFromFormalOrderId'] = $sourceOrderId;
    $baseWaiting['createdAt'] = $now;
    $baseWaiting['updatedAt'] = $now;
    $baseWaiting['subtotal'] = round(max(0, (float)($waitingItem['price'] ?? 0)) * $moveQty, 2);
    $baseWaiting['shippingFee'] = 0;
    $baseWaiting['total'] = $baseWaiting['subtotal'];
    $baseWaiting['paymentAdjustmentRequired'] = true;
    $baseWaiting['manualPriorityAudit'] = [[
        'at' => $now, 'by' => $operatorName, 'action' => '正式單退讓名額', 'sourceOrderId' => $sourceOrderId,
        'targetInquiryId' => $targetInquiryId, 'qty' => $moveQty, 'reason' => $reason
    ]];
    array_unshift($inquiries, $baseWaiting);

    $creditedQty = 0;
    $creditedOrderId = '';
    if ($targetOrderIndex >= 0) {
        $credit = priority_credit_tw_received_on_order(
            $orders,
            $targetOrderIndex,
            -1,
            $sourceItem,
            $moveQty,
            $now,
            $reason,
            $operatorName
        );
        $creditedQty = max(0, (int)($credit['creditedQty'] ?? 0));
        if ($creditedQty <= 0) {
            respond(['ok' => false, 'error' => '正式單上找不到相同產品可接收這次抽貨'], 409);
        }
        $creditedOrderId = trim((string)($orders[$targetOrderIndex]['id'] ?? $targetOrderId));
    }

    adjust_stock([array_merge($sourceItem, ['qty' => $moveQty])], 1, $skusFile, $stateFile);
    if ($creditedQty > 0 && isset($credit['item']) && is_array($credit['item'])) {
        adjust_stock([array_merge($credit['item'], [
            'skuId' => trim((string)($credit['item']['skuId'] ?? $credit['item']['sku'] ?? $sourceItem['skuId'] ?? $sourceItem['sku'] ?? '')),
            'qty' => $creditedQty
        ])], -1, $skusFile, $stateFile);
    }
    write_json($ordersFile, array_values($orders));
    write_json($inquiriesFile, array_values($inquiries));
    $state = read_json($stateFile);
    $state['orders'] = array_values($orders);
    $state['updatedAt'] = $now;
    write_json($stateFile, $state);
    respond([
        'ok' => true, 'sourceOrderId' => $sourceOrderId, 'waitingInquiryId' => $waitingId,
        'targetInquiryId' => $targetInquiryId, 'targetOrderId' => $creditedOrderId,
        'releasedQty' => $moveQty, 'creditedQty' => $creditedQty,
        'message' => $creditedQty > 0
            ? '已從原客戶抽貨並配到這張正式單'
            : '已退回台灣倉並建立原客戶下一批候補；請再確認插隊客戶轉正式出貨'
    ]);
}

if ($action === 'allocate-order-item-tw-stock') {
    $operator = require_purchase_operator($adminSessionsFile, $stateFile, ['物流集運', '預購訂單', '訂單管理']);
    $orderId = trim((string)($payload['orderId'] ?? ''));
    $itemIndex = (int)($payload['itemIndex'] ?? -1);
    $requestedQty = max(1, (int)($payload['qty'] ?? 1));
    $reason = trim((string)($payload['reason'] ?? ''));
    $operationId = trim((string)($payload['operationId'] ?? ''));
    if ($orderId === '' || $itemIndex < 0 || $reason === '' || $operationId === '') {
        respond(['ok' => false, 'error' => '缺少正式單、品項或抽貨原因'], 400);
    }
    $operationLock = acquire_preorder_inventory_lock($inquiriesFile, 'allocate-order-tw-' . $orderId . '-' . $itemIndex);
    $orders = read_json($ordersFile);
    $orderIndex = find_index($orders, $orderId);
    if ($orderIndex < 0) respond(['ok' => false, 'error' => '找不到這張正式出貨單'], 404);
    $order = $orders[$orderIndex];
    foreach ((array)($order['priorityStockAllocationHistory'] ?? []) as $historyRow) {
        if (is_array($historyRow) && hash_equals((string)($historyRow['operationId'] ?? ''), $operationId)) {
            respond(['ok' => true, 'idempotentReplay' => true, 'orderId' => $orderId, 'creditedQty' => (int)($historyRow['qty'] ?? 0), 'order' => $order]);
        }
    }
    $status = strtolower(trim((string)($order['status'] ?? '')));
    $delivery = strtolower(trim((string)($order['deliveryState'] ?? '')));
    if (in_array($status, ['shipped', 'in_transit', 'delivered', 'completed', 'cancelled', 'returned'], true)
        || in_array($delivery, ['in_transit', 'delivered', 'returned'], true)) {
        respond(['ok' => false, 'error' => '這張正式單已配送、完成、取消或退貨，不能再抽貨入單'], 409);
    }
    $items = is_array($order['items'] ?? null) ? array_values($order['items']) : [];
    if (!isset($items[$itemIndex]) || !is_array($items[$itemIndex])) {
        respond(['ok' => false, 'error' => '正式單內找不到這個商品'], 404);
    }
    $item = $items[$itemIndex];
    $skuId = trim((string)($item['skuId'] ?? $item['sku'] ?? ''));
    $sku = null;
    foreach (read_json($skusFile) as $catalogSku) {
        if (is_array($catalogSku) && trim((string)($catalogSku['id'] ?? $catalogSku['sku'] ?? '')) === $skuId) {
            $sku = $catalogSku;
            break;
        }
    }
    if ($skuId === '' || !is_array($sku) || !receipt_sku_is_warehouse($sku, receipt_warehouse_profile('TW'))) {
        respond(['ok' => false, 'error' => '這件不是台灣倉正式 SKU，不能直接抽台灣現貨入單'], 409);
    }
    $now = date(DATE_ATOM);
    $operatorName = trim((string)($operator['name'] ?? $operator['account'] ?? '管理者'));
    $credit = priority_credit_tw_received_on_order($orders, $orderIndex, $itemIndex, $item, $requestedQty, $now, $reason, $operatorName);
    $creditedQty = max(0, (int)($credit['creditedQty'] ?? 0));
    if ($creditedQty <= 0) {
        respond(['ok' => true, 'orderId' => $orderId, 'creditedQty' => 0, 'order' => $orders[$orderIndex], 'message' => '這件已夠數，未再扣台灣倉']);
    }
    $history = is_array($orders[$orderIndex]['priorityStockAllocationHistory'] ?? null)
        ? $orders[$orderIndex]['priorityStockAllocationHistory']
        : [];
    $history[] = ['operationId' => $operationId, 'at' => $now, 'by' => $operatorName, 'itemIndex' => $itemIndex, 'qty' => $creditedQty, 'reason' => $reason];
    $orders[$orderIndex]['priorityStockAllocationHistory'] = array_slice($history, -50);
    adjust_stock([array_merge($credit['item'], ['skuId' => $skuId, 'qty' => $creditedQty])], -1, $skusFile, $stateFile);
    write_json($ordersFile, array_values($orders));
    $state = read_json($stateFile);
    $state['orders'] = array_values($orders);
    $state['updatedAt'] = $now;
    write_json($stateFile, $state);
    respond([
        'ok' => true,
        'orderId' => $orderId,
        'creditedQty' => $creditedQty,
        'order' => $orders[$orderIndex],
        'message' => '已把台灣現貨配到這張正式單'
    ]);
}

if ($action === 'reconcile-freight-batch-cost') {
    $purchaseOperator = require_purchase_operator($adminSessionsFile, $stateFile, ['物流集運', '採購區', '採購進貨建檔']);
    $batchId = receipt_text($payload['freightBatchId'] ?? $payload['batchId'] ?? '');
    if ($batchId === '') respond(['ok' => false, 'error' => '缺少要回溯成本的集運批次'], 400);
    $operatorName = receipt_first_text([$purchaseOperator['name'] ?? '', $purchaseOperator['account'] ?? '', '管理者']);
    $now = date(DATE_ATOM);

    // Keep the same lock order as formal receiving: receipts -> freight -> catalog.
    // This action is cost-only. It must never add, subtract or rewrite stock quantity.
    $receiptsFileLock = acquire_purchase_receipts_file_lock($purchaseReceiptsFile);
    $freightFileLock = acquire_freight_file_lock($freightTrackingFile);
    $catalogLock = acquire_inventory_file_lock($skusFile);
    $purchaseReceipts = read_json($purchaseReceiptsFile);
    $freightData = read_json($freightTrackingFile);
    $skus = read_json($skusFile);
    $products = read_json($productsFile);
    $stockBefore = array_reduce($skus, static fn($sum, $sku) => $sum + max(0, (int)(is_array($sku) ? ($sku['stock'] ?? 0) : 0)), 0);

    $batchIndex = -1;
    $batch = [];
    foreach (is_array($freightData['batches'] ?? null) ? $freightData['batches'] : [] as $index => $candidate) {
        if (!is_array($candidate) || receipt_text($candidate['id'] ?? '') !== $batchId) continue;
        $batchIndex = (int)$index;
        $batch = $candidate;
        break;
    }
    if ($batchIndex < 0) respond(['ok' => false, 'error' => '找不到指定的集運批次，未調整任何成本'], 404);

    $costIdentity = [
        'batchId' => $batchId,
        'provider' => receipt_text($batch['provider'] ?? ''),
        'costMode' => receipt_text($batch['costMode'] ?? ''),
        'chargeType' => receipt_text($batch['chargeType'] ?? ''),
        'amount' => round((float)($batch['amount'] ?? 0), 2),
        'chargeTotalTwd' => round((float)($batch['chargeTotalTwd'] ?? 0), 2),
        'dutyTwd' => round((float)($batch['dutyTwd'] ?? 0), 2),
        'taiwanCourierFeeTwd' => round((float)($batch['taiwanCourierFeeTwd'] ?? $batch['handlingFeeTwd'] ?? 0), 2),
        'costBasisQty' => max(0, (int)($batch['costBasisQty'] ?? $batch['customsPackageCount'] ?? $batch['itemCount'] ?? 0)),
        'allocationPerItem' => round((float)($batch['allocationPerItem'] ?? $batch['costAverageTwd'] ?? 0), 2),
        'customsNo' => receipt_text($batch['customsNo'] ?? ''),
        'destinationWarehouse' => receipt_text($batch['destinationWarehouse'] ?? ''),
    ];
    $batchSignature = preorder_operation_payload_hash($costIdentity);
    $receiptIndexByItem = [];
    foreach ($purchaseReceipts as $receiptIndex => $receiptRow) {
        if (!is_array($receiptRow)) continue;
        $receiptItemId = receipt_text($receiptRow['freightItemId'] ?? '');
        if ($receiptItemId !== '') $receiptIndexByItem[$receiptItemId] = (int)$receiptIndex;
    }

    $adjustedItems = 0;
    $adjustedLines = 0;
    $unchangedItems = 0;
    $totalQty = 0;
    $totalDeltaTwd = 0.0;
    $warnings = [];
    $touchedProductIds = [];
    $touchedSkuIds = [];
    $batchLineHistory = [];

    foreach (is_array($freightData['items'] ?? null) ? $freightData['items'] : [] as $itemIndex => &$item) {
        if (!is_array($item) || receipt_text($item['batchId'] ?? '') !== $batchId) continue;
        $itemId = receipt_text($item['id'] ?? '');
        $claim = isset($item['inventoryReceiptClaim']) && is_array($item['inventoryReceiptClaim']) ? $item['inventoryReceiptClaim'] : [];
        $received = strtolower(receipt_text($claim['status'] ?? '')) === 'received'
            || receipt_text($item['inventoryStatus'] ?? '') === 'received'
            || !empty($item['inventoryReceivedAt']);
        if (!$received || $itemId === '') continue;
        $receiptIndex = $receiptIndexByItem[$itemId] ?? -1;
        $receiptRow = $receiptIndex >= 0 && is_array($purchaseReceipts[$receiptIndex] ?? null) ? $purchaseReceipts[$receiptIndex] : [];
        $receiptLines = isset($receiptRow['receiptLines']) && is_array($receiptRow['receiptLines'])
            ? array_values($receiptRow['receiptLines'])
            : (isset($claim['lines']) && is_array($claim['lines']) ? array_values($claim['lines']) : []);
        if (!$receiptLines) {
            $warnings[] = ($item['trackingNo'] ?? $itemId) . ' 找不到正式入庫規格明細';
            continue;
        }
        $actualItemQty = array_reduce($receiptLines, static fn($sum, $line) => $sum + max(0, (int)(is_array($line) ? ($line['qty'] ?? 0) : 0)), 0);
        if ($actualItemQty <= 0) continue;
        $itemSignature = preorder_operation_payload_hash([
            'batchSignature' => $batchSignature,
            'itemId' => $itemId,
            'actualQty' => $actualItemQty,
            'lines' => array_map(static fn($line) => [
                'skuId' => receipt_text(is_array($line) ? ($line['skuId'] ?? '') : ''),
                'qty' => max(0, (int)(is_array($line) ? ($line['qty'] ?? 0) : 0)),
                'baseCostTwd' => round((float)(is_array($line) ? ($line['freightCostSnapshot']['baseCostTwd'] ?? 0) : 0), 2),
            ], $receiptLines),
        ]);
        $previousReconciliation = isset($item['freightCostReconciliation']) && is_array($item['freightCostReconciliation'])
            ? $item['freightCostReconciliation']
            : [];
        if (receipt_text($previousReconciliation['signature'] ?? '') === $itemSignature) {
            $unchangedItems++;
            continue;
        }

        $costSnapshot = freight_receipt_snapshot([
            'freightItemId' => $itemId,
            'freightBatchId' => $batchId,
            'freightTrackingNo' => receipt_text($item['trackingNo'] ?? ''),
            'freightDestinationWarehouse' => receipt_text($item['destinationWarehouse'] ?? $batch['destinationWarehouse'] ?? ''),
        ], $freightTrackingFile);
        $allocationPerUnit = max(0, round((float)($costSnapshot['allocationPerItemTwd'] ?? 0), 2));
        if (in_array(receipt_text($costSnapshot['costMode'] ?? ''), ['haohong_weight', 'haohong_batch', 'haohong_weight_batch'], true)
            && (float)($costSnapshot['haohongPackageAllocationTwd'] ?? 0) > 0) {
            // A Haohong package cost is divided by the actual received quantity,
            // including all colours/sizes added during inspection.
            $allocationPerUnit = round((float)$costSnapshot['haohongPackageAllocationTwd'] / $actualItemQty, 2);
        }
        $handlingPerUnit = max(0, round((float)($costSnapshot['handlingPerItemTwd'] ?? 0), 2));
        $newExtraPerUnit = round($allocationPerUnit + $handlingPerUnit, 2);
        $operationId = 'freight-cost-reconcile:' . hash('sha256', $batchId . '|' . $itemId . '|' . $itemSignature);
        $itemDeltaTwd = 0.0;
        $updatedReceiptLines = [];
        $updatedClaimLines = isset($claim['lines']) && is_array($claim['lines']) ? array_values($claim['lines']) : [];

        foreach ($receiptLines as $lineIndex => $line) {
            if (!is_array($line)) continue;
            $qty = max(0, (int)($line['qty'] ?? 0));
            if ($qty <= 0) continue;
            $oldSnapshot = isset($line['freightCostSnapshot']) && is_array($line['freightCostSnapshot']) ? $line['freightCostSnapshot'] : [];
            $oldFinal = max(0, round((float)($line['unitCostTwd'] ?? $oldSnapshot['finalCostTwd'] ?? 0), 2));
            $oldExtra = max(0, round((float)($oldSnapshot['extraCostTwd'] ?? 0), 2));
            $baseCost = max(0, round((float)($oldSnapshot['baseCostTwd'] ?? max(0, $oldFinal - $oldExtra)), 2));
            $newFinal = round($baseCost + $newExtraPerUnit, 2);
            $deltaPerUnit = round($newFinal - $oldFinal, 2);
            $deltaTotal = round($deltaPerUnit * $qty, 2);
            $itemDeltaTwd += $deltaTotal;
            $skuId = receipt_text($line['skuId'] ?? '');
            $productId = receipt_text($line['productId'] ?? '');
            $receivedAt = receipt_first_text([$receiptRow['receivedAt'] ?? '', $line['appliedAt'] ?? '', $item['inventoryReceivedAt'] ?? '', $now]);
            $newSnapshot = array_merge($oldSnapshot, [
                'batchId' => $batchId,
                'itemId' => $itemId,
                'costMode' => receipt_text($costSnapshot['costMode'] ?? ''),
                'baseCostTwd' => $baseCost,
                'extraCostTwd' => $newExtraPerUnit,
                'allocationPerItemTwd' => $allocationPerUnit,
                'handlingPerItemTwd' => $handlingPerUnit,
                'finalCostTwd' => $newFinal,
                'costReferenceNo' => receipt_first_text([$batch['customsNo'] ?? '', $batch['costReferenceNo'] ?? '', $batchId]),
                'reconciledAt' => $now,
                'reconciledBy' => $operatorName,
                'reconciliationOperationId' => $operationId,
            ]);
            $line['unitCostTwd'] = $newFinal;
            $line['costSource'] = 'freight_cost_reconciliation';
            $line['costEffectiveAt'] = $receivedAt;
            $line['freightCostSnapshot'] = $newSnapshot;
            $updatedReceiptLines[] = $line;
            if (isset($updatedClaimLines[$lineIndex]) && is_array($updatedClaimLines[$lineIndex])) {
                $updatedClaimLines[$lineIndex]['unitCostTwd'] = $newFinal;
                $updatedClaimLines[$lineIndex]['freightCostSnapshot'] = $newSnapshot;
            }

            $targetSkuIndex = -1;
            foreach ($skus as $skuIndex => $candidateSku) {
                if (!is_array($candidateSku)) continue;
                $candidateSkuId = receipt_first_text([$candidateSku['id'] ?? '', $candidateSku['sku'] ?? '']);
                if ($skuId !== '' && $candidateSkuId === $skuId) { $targetSkuIndex = (int)$skuIndex; break; }
            }
            if ($targetSkuIndex < 0) {
                $warnings[] = ($item['trackingNo'] ?? $itemId) . '／' . ($line['barcode'] ?? $skuId) . ' 找不到正式庫存 SKU';
                continue;
            }
            $costEvent = [
                'operationId' => $operationId,
                'batchId' => $batchId,
                'freightItemId' => $itemId,
                'trackingNo' => receipt_text($item['trackingNo'] ?? ''),
                'skuId' => $skuId,
                'qty' => $qty,
                'stockQtyDelta' => 0,
                'previousUnitCostTwd' => $oldFinal,
                'revisedUnitCostTwd' => $newFinal,
                'deltaPerUnitTwd' => $deltaPerUnit,
                'deltaTotalTwd' => $deltaTotal,
                'costReferenceNo' => receipt_first_text([$batch['customsNo'] ?? '', $batch['costReferenceNo'] ?? '', $batchId]),
                'at' => $now,
                'by' => $operatorName,
            ];
            $history = isset($skus[$targetSkuIndex]['freightCostAdjustmentHistory']) && is_array($skus[$targetSkuIndex]['freightCostAdjustmentHistory'])
                ? array_values($skus[$targetSkuIndex]['freightCostAdjustmentHistory'])
                : [];
            $alreadyLogged = false;
            foreach ($history as $entry) {
                if (is_array($entry) && receipt_text($entry['operationId'] ?? '') === $operationId) { $alreadyLogged = true; break; }
            }
            if (!$alreadyLogged) array_unshift($history, $costEvent);
            $skus[$targetSkuIndex]['freightCostAdjustmentHistory'] = array_slice($history, 0, 100);
            $lots = isset($skus[$targetSkuIndex]['freightCostLots']) && is_array($skus[$targetSkuIndex]['freightCostLots'])
                ? array_values($skus[$targetSkuIndex]['freightCostLots'])
                : [];
            $lotUpdated = false;
            foreach ($lots as $lotIndex => $lot) {
                if (!is_array($lot) || receipt_text($lot['itemId'] ?? '') !== $itemId) continue;
                $lots[$lotIndex] = array_merge($lot, $newSnapshot, ['qty' => $qty]);
                $lotUpdated = true;
                break;
            }
            if (!$lotUpdated) array_unshift($lots, $newSnapshot + ['qty' => $qty, 'warehouse' => receipt_text($line['warehouse'] ?? '')]);
            $skus[$targetSkuIndex]['freightCostLots'] = array_slice($lots, 0, 50);
            set_inventory_transaction_marker($skus[$targetSkuIndex], $operationId, [
                'kind' => 'freight_cost_reconciliation',
                'payloadHash' => $itemSignature,
                'qtyDelta' => 0,
                'costDeltaTwd' => $deltaTotal,
                'appliedAt' => $now,
                'lines' => [$costEvent],
            ]);

            // Do not let an older corrected lot overwrite a later receiving cost.
            $currentCostAt = receipt_text($skus[$targetSkuIndex]['currentCostUpdatedAt'] ?? $skus[$targetSkuIndex]['costEffectiveAt'] ?? '');
            $isLatestCostEvent = $currentCostAt === '' || $currentCostAt <= $receivedAt
                || receipt_text($skus[$targetSkuIndex]['freightItemId'] ?? '') === $itemId;
            if ($isLatestCostEvent) {
                foreach ($skus as &$costSku) {
                    if (!is_array($costSku) || receipt_text($costSku['productId'] ?? '') !== $productId) continue;
                    if (receipt_same_variant($costSku, $productId, receipt_text($line['color'] ?? ''), receipt_text($line['size'] ?? 'NO SIZE'))) {
                        $costSku['cost'] = $newFinal;
                        $costSku['currentCostTwd'] = $newFinal;
                        $costSku['finalCostTwd'] = $newFinal;
                        $costSku['costSource'] = 'freight_cost_reconciliation';
                        $costSku['costEffectiveAt'] = $receivedAt;
                        $costSku['currentCostUpdatedAt'] = $now;
                        $costSku['costUpdatedAt'] = $now;
                        $costSku['costVersion'] = max(0, (int)($costSku['costVersion'] ?? 0)) + 1;
                        $costSku['updatedAt'] = $now;
                    }
                    // Reconciliation of the latest received batch must also
                    // move the price of older colours/sizes still in stock.
                    zhangzhang_apply_pricing($costSku, $newFinal, $now);
                }
                unset($costSku);
                foreach ($products as &$product) {
                    if (!is_array($product) || receipt_text($product['id'] ?? '') !== $productId) continue;
                    $catalogCost = receipt_catalog_merchandise_cost($product, (float)$newFinal);
                    $product['cost'] = $catalogCost;
                    $product['currentCostTwd'] = $catalogCost;
                    $product['finalCostTwd'] = $catalogCost;
                    $product['costSource'] = ((float)($product['costRmb'] ?? 0) > 0) ? 'manual-rmb-x5' : 'freight_cost_reconciliation';
                    $product['costEffectiveAt'] = $receivedAt;
                    $product['currentCostUpdatedAt'] = $now;
                    $product['costUpdatedAt'] = $now;
                    $product['costVersion'] = max(0, (int)($product['costVersion'] ?? 0)) + 1;
                    $product['updatedAt'] = $now;
                    if ((float)($product['costRmb'] ?? 0) <= 0) zhangzhang_apply_pricing($product, $catalogCost, $now);
                    break;
                }
                unset($product);
            }
            $touchedSkuIds[$skuId] = true;
            if ($productId !== '') $touchedProductIds[$productId] = true;
            $adjustedLines++;
            $totalQty += $qty;
            $batchLineHistory[] = $costEvent;
        }

        if (!$updatedReceiptLines) continue;
        $totalDeltaTwd += $itemDeltaTwd;
        if ($receiptIndex >= 0) {
            $purchaseReceipts[$receiptIndex]['receiptLines'] = $updatedReceiptLines;
            $purchaseReceipts[$receiptIndex]['totalCostTwd'] = round(array_reduce($updatedReceiptLines, static fn($sum, $line) => $sum + max(0, (int)($line['qty'] ?? 0)) * max(0, (float)($line['unitCostTwd'] ?? 0)), 0.0), 2);
            $purchaseReceipts[$receiptIndex]['freightCostReconciliation'] = [
                'signature' => $itemSignature,
                'operationId' => $operationId,
                'batchId' => $batchId,
                'qty' => $actualItemQty,
                'deltaTwd' => round($itemDeltaTwd, 2),
                'stockQtyDelta' => 0,
                'updatedAt' => $now,
                'updatedBy' => $operatorName,
            ];
            $purchaseReceipts[$receiptIndex]['updatedAt'] = $now;
        }
        $item['inventoryReceiptClaim']['lines'] = $updatedClaimLines;
        $item['freightCostReconciliation'] = [
            'signature' => $itemSignature,
            'operationId' => $operationId,
            'batchId' => $batchId,
            'qty' => $actualItemQty,
            'allocationPerItemTwd' => $allocationPerUnit,
            'handlingPerItemTwd' => $handlingPerUnit,
            'deltaTwd' => round($itemDeltaTwd, 2),
            'stockQtyDelta' => 0,
            'updatedAt' => $now,
            'updatedBy' => $operatorName,
        ];
        $itemHistory = isset($item['freightCostReconciliationHistory']) && is_array($item['freightCostReconciliationHistory'])
            ? array_values($item['freightCostReconciliationHistory'])
            : [];
        array_unshift($itemHistory, $item['freightCostReconciliation']);
        $item['freightCostReconciliationHistory'] = array_slice($itemHistory, 0, 100);
        $item['updatedAt'] = $now;
        $adjustedItems++;
    }
    unset($item);

    $stockAfter = array_reduce($skus, static fn($sum, $sku) => $sum + max(0, (int)(is_array($sku) ? ($sku['stock'] ?? 0) : 0)), 0);
    if ($stockAfter !== $stockBefore) respond(['ok' => false, 'error' => '成本回溯安全檢查失敗：偵測到庫存數量改變，已停止寫入'], 409);

    $batchReconciliation = [
        'signature' => $batchSignature,
        'batchId' => $batchId,
        'adjustedItemCount' => $adjustedItems,
        'adjustedLineCount' => $adjustedLines,
        'unchangedItemCount' => $unchangedItems,
        'qty' => $totalQty,
        'deltaTwd' => round($totalDeltaTwd, 2),
        'stockQtyDelta' => 0,
        'updatedAt' => $now,
        'updatedBy' => $operatorName,
    ];
    $previousBatchReconciliation = isset($freightData['batches'][$batchIndex]['freightCostReconciliation']) && is_array($freightData['batches'][$batchIndex]['freightCostReconciliation'])
        ? $freightData['batches'][$batchIndex]['freightCostReconciliation']
        : [];
    if ($adjustedItems === 0 && $unchangedItems > 0 && $previousBatchReconciliation) {
        $batchReconciliation = array_merge($previousBatchReconciliation, [
            'signature' => $batchSignature,
            'unchangedItemCount' => $unchangedItems,
            'lastCheckedAt' => $now,
            'lastCheckedBy' => $operatorName,
            'stockQtyDelta' => 0,
        ]);
    }
    $freightData['batches'][$batchIndex]['freightCostReconciliation'] = $batchReconciliation;
    $batchHistory = isset($freightData['batches'][$batchIndex]['freightCostReconciliationHistory']) && is_array($freightData['batches'][$batchIndex]['freightCostReconciliationHistory'])
        ? array_values($freightData['batches'][$batchIndex]['freightCostReconciliationHistory'])
        : [];
    if ($adjustedItems > 0) array_unshift($batchHistory, $batchReconciliation + ['lines' => $batchLineHistory]);
    $freightData['batches'][$batchIndex]['freightCostReconciliationHistory'] = array_slice($batchHistory, 0, 50);
    $freightData['batches'][$batchIndex]['updatedAt'] = $now;
    $freightData['revision'] = max(0, (int)($freightData['revision'] ?? 0)) + 1;
    $freightData['updatedAt'] = $now;

    // Canonical inventory cost first, then mirrors and audit documents.
    write_json($skusFile, $skus);
    write_json($productsFile, $products);
    sync_preorder_inventory_state($stateFile, $skus, $products, 'freight-cost-reconcile-batch:' . $batchId, [
        'kind' => 'freight_cost_reconciliation',
        'batchId' => $batchId,
        'payloadHash' => $batchSignature,
        'qtyDelta' => 0,
        'costDeltaTwd' => round($totalDeltaTwd, 2),
        'appliedAt' => $now,
        'skuIds' => array_keys($touchedSkuIds),
        'productIds' => array_keys($touchedProductIds),
    ]);
    write_json($purchaseReceiptsFile, array_values($purchaseReceipts));
    write_json($freightTrackingFile, $freightData);
    @unlink($dataDir . DIRECTORY_SEPARATOR . 'public-catalog.json');
    flock($catalogLock, LOCK_UN);
    fclose($catalogLock);
    flock($freightFileLock, LOCK_UN);
    fclose($freightFileLock);
    flock($receiptsFileLock, LOCK_UN);
    fclose($receiptsFileLock);
    respond([
        'ok' => true,
        'batchId' => $batchId,
        'reconciliation' => $batchReconciliation,
        'warnings' => array_slice($warnings, 0, 20),
        'message' => $adjustedItems > 0
            ? '已依實收顏色／尺寸數量回溯 ' . $adjustedItems . ' 筆已入庫物流；只調整成本差額，庫存數量未變'
            : ($unchangedItems > 0 ? '本批已入庫成本已是最新，沒有重複扣款' : '本批目前沒有已正式入庫品項；之後入庫會直接使用最新集運成本'),
    ]);
}

if ($action === 'correct-freight-product-receipt-quantity') {
    $freightItemId = receipt_text($payload['freightItemId'] ?? '');
    $correctedActualQty = max(0, (int)($payload['correctedActualQty'] ?? -1));
    $reason = receipt_text($payload['reason'] ?? '');
    if ($freightItemId === '') respond(['ok' => false, 'error' => '缺少物流商品識別碼'], 400);
    if (!array_key_exists('correctedActualQty', $payload)) respond(['ok' => false, 'error' => '缺少更正後實收數量'], 400);
    if ($correctedActualQty <= 0) respond(['ok' => false, 'error' => '更正後實收數量至少要有 1 件'], 400);
    if ($reason === '') respond(['ok' => false, 'error' => '請填寫入庫數量更正原因'], 400);

    $maintenancePermitFile = $dataDir . DIRECTORY_SEPARATOR . 'maintenance-freight-receipt-correction-20260810.json';
    $maintenancePermit = read_json($maintenancePermitFile);
    $providedPermit = receipt_text($payload['maintenancePermit'] ?? '');
    $maintenanceAuthorized = $providedPermit !== ''
        && empty($maintenancePermit['consumedAt'])
        && receipt_text($maintenancePermit['freightItemId'] ?? '') === $freightItemId
        && (int)($maintenancePermit['correctedActualQty'] ?? -1) === $correctedActualQty
        && receipt_text($maintenancePermit['tokenHash'] ?? '') !== ''
        && hash_equals(receipt_text($maintenancePermit['tokenHash']), hash('sha256', $providedPermit));
    $purchaseOperator = $maintenanceAuthorized
        ? ['name' => 'Codex 維護更正', 'account' => 'codex-maintenance', 'role' => 'maintenance']
        : require_purchase_operator($adminSessionsFile, $stateFile, ['物流集運', '採購區', '採購進貨建檔']);

    $operatorName = receipt_first_text([$purchaseOperator['name'] ?? '', $purchaseOperator['account'] ?? '', '管理者']);
    $now = date(DATE_ATOM);
    $correctionOperationId = 'freight-receipt-qty-correction:' . hash('sha256', $freightItemId . '|' . $correctedActualQty);

    // Keep the formal receiving lock order: receipts -> freight -> catalog.
    $receiptsFileLock = acquire_purchase_receipts_file_lock($purchaseReceiptsFile);
    $freightFileLock = acquire_freight_file_lock($freightTrackingFile);
    $catalogLock = acquire_inventory_file_lock($skusFile);
    $purchaseReceipts = read_json($purchaseReceiptsFile);
    $freightData = read_json($freightTrackingFile);
    $skus = read_json($skusFile);
    $products = read_json($productsFile);

    $freightItemIndex = freight_product_receive_item_index($freightData, $freightItemId);
    if ($freightItemIndex < 0) respond(['ok' => false, 'error' => '找不到指定的物流商品'], 404);
    $freightItem = $freightData['items'][$freightItemIndex];
    $claim = is_array($freightItem['inventoryReceiptClaim'] ?? null) ? $freightItem['inventoryReceiptClaim'] : [];
    $receiveOperationId = receipt_text($claim['operationId'] ?? $freightItem['inventoryOperationId'] ?? '');
    if ($receiveOperationId === '' || strtolower(receipt_text($claim['status'] ?? '')) !== 'received') {
        respond(['ok' => false, 'error' => '這筆物流商品尚未完成正式入庫，不能使用入庫更正'], 409);
    }

    $receiptIndex = -1;
    foreach ($purchaseReceipts as $index => $candidate) {
        if (!is_array($candidate)) continue;
        if (receipt_text($candidate['freightItemId'] ?? '') === $freightItemId
            || receipt_text($candidate['operationId'] ?? '') === $receiveOperationId) {
            $receiptIndex = (int)$index;
            break;
        }
    }
    if ($receiptIndex < 0) respond(['ok' => false, 'error' => '找不到這筆正式進貨單，已停止更正'], 409);
    $receipt = $purchaseReceipts[$receiptIndex];
    $oldActualQty = max(0, (int)($receipt['actualQty'] ?? $freightItem['receivingActualQty'] ?? 0));
    if ($oldActualQty <= 0) respond(['ok' => false, 'error' => '正式進貨單沒有可更正的實收數量'], 409);

    if ($oldActualQty === $correctedActualQty) {
        $existingCorrection = inventory_transaction_marker_entries($skus, $correctionOperationId);
        respond([
            'ok' => true,
            'idempotentReplay' => !empty($existingCorrection),
            'freightItemId' => $freightItemId,
            'oldActualQty' => $oldActualQty,
            'correctedActualQty' => $correctedActualQty,
            'deductedQty' => 0,
            'message' => '這筆正式入庫數量已經是 ' . $correctedActualQty . ' 件',
        ]);
    }
    if ($correctedActualQty > $oldActualQty) {
        respond(['ok' => false, 'error' => '此更正功能只允許扣回誤多入庫；增加數量請重新走正式驗收入庫'], 409);
    }

    $linkedQty = array_reduce(
        is_array($claim['linkedOrders'] ?? null) ? $claim['linkedOrders'] : [],
        static fn($sum, $row) => $sum + max(0, (int)(is_array($row) ? ($row['qty'] ?? 0) : 0)),
        0
    );
    if ($linkedQty > $correctedActualQty) {
        respond(['ok' => false, 'error' => '更正後數量小於已配給客戶的數量，請先人工核對配貨'], 409);
    }
    $deductQty = $oldActualQty - $correctedActualQty;
    $oldCompanyQty = max(0, (int)($claim['companyStockQty'] ?? $freightItem['companyStockQty'] ?? ($oldActualQty - $linkedQty)));
    if ($deductQty > $oldCompanyQty) {
        respond(['ok' => false, 'error' => '要扣回的數量已超過這筆入庫的公司現貨，請先人工核對'], 409);
    }

    $receiptLines = is_array($receipt['receiptLines'] ?? null) ? array_values($receipt['receiptLines']) : [];
    $claimLines = is_array($claim['lines'] ?? null) ? array_values($claim['lines']) : [];
    if (count($receiptLines) !== 1 || count($claimLines) !== 1) {
        respond(['ok' => false, 'error' => '這筆進貨單包含多個規格，請逐規格人工核對後再更正'], 409);
    }
    $skuId = receipt_first_text([$receiptLines[0]['skuId'] ?? '', $claimLines[0]['skuId'] ?? '', $freightItem['skuId'] ?? '']);
    if ($skuId === '') respond(['ok' => false, 'error' => '正式進貨單缺少 SKU，已停止更正'], 409);

    $targetSkuIndex = -1;
    foreach ($skus as $index => $sku) {
        if (!is_array($sku)) continue;
        if (receipt_first_text([$sku['id'] ?? '', $sku['sku'] ?? '']) === $skuId) {
            $targetSkuIndex = (int)$index;
            break;
        }
    }
    if ($targetSkuIndex < 0) respond(['ok' => false, 'error' => '找不到正式庫存 SKU：' . $skuId], 409);
    if ((int)($skus[$targetSkuIndex]['stock'] ?? 0) < $deductQty) {
        respond(['ok' => false, 'error' => '目前台灣庫存不足以扣回誤多入庫的 ' . $deductQty . ' 件'], 409);
    }
    $originalMarker = inventory_transaction_marker($skus[$targetSkuIndex], $receiveOperationId);
    if (!$originalMarker) respond(['ok' => false, 'error' => '找不到原始正式入庫交易記號，已停止更正'], 409);

    $existingCorrection = inventory_transaction_marker($skus[$targetSkuIndex], $correctionOperationId);
    if ($existingCorrection) {
        respond([
            'ok' => true,
            'idempotentReplay' => true,
            'freightItemId' => $freightItemId,
            'skuId' => $skuId,
            'correctedActualQty' => $correctedActualQty,
            'stock' => (int)($skus[$targetSkuIndex]['stock'] ?? 0),
            'message' => '這筆入庫數量更正已完成，沒有重複扣庫存',
        ]);
    }

    $stockBefore = (int)($skus[$targetSkuIndex]['stock'] ?? 0);
    $stockAfter = $stockBefore - $deductQty;
    $correctionLine = [
        'skuId' => $skuId,
        'freightItemId' => $freightItemId,
        'receiptId' => receipt_text($receipt['id'] ?? ''),
        'originalOperationId' => $receiveOperationId,
        'oldActualQty' => $oldActualQty,
        'correctedActualQty' => $correctedActualQty,
        'qty' => $deductQty,
        'qtyDelta' => -$deductQty,
        'stockBefore' => $stockBefore,
        'stockAfter' => $stockAfter,
        'reason' => $reason,
        'correctedAt' => $now,
        'correctedBy' => $operatorName,
    ];
    $skus[$targetSkuIndex]['stock'] = $stockAfter;
    $skus[$targetSkuIndex]['updatedAt'] = $now;
    set_inventory_transaction_marker($skus[$targetSkuIndex], $correctionOperationId, [
        'kind' => 'freight_receipt_quantity_correction',
        'freightItemId' => $freightItemId,
        'originalOperationId' => $receiveOperationId,
        'qtyDelta' => -$deductQty,
        'appliedAt' => $now,
        'lines' => [$correctionLine],
    ]);
    $originalHistory = is_array($originalMarker['quantityCorrectionHistory'] ?? null)
        ? array_values($originalMarker['quantityCorrectionHistory'])
        : [];
    $originalHistory[] = $correctionLine + ['correctionOperationId' => $correctionOperationId];
    $originalMarker['originalQtyDelta'] = max((int)($originalMarker['originalQtyDelta'] ?? 0), $oldActualQty);
    $originalMarker['qtyDelta'] = $correctedActualQty;
    if (is_array($originalMarker['lines'] ?? null) && isset($originalMarker['lines'][0]) && is_array($originalMarker['lines'][0])) {
        $originalMarker['lines'][0]['originalQty'] = max((int)($originalMarker['lines'][0]['originalQty'] ?? 0), $oldActualQty);
        $originalMarker['lines'][0]['qty'] = $correctedActualQty;
        $originalMarker['lines'][0]['quantityCorrectedAt'] = $now;
        $originalMarker['lines'][0]['quantityCorrectionOperationId'] = $correctionOperationId;
    }
    $originalMarker['quantityCorrectionHistory'] = array_slice($originalHistory, -50);
    set_inventory_transaction_marker($skus[$targetSkuIndex], $receiveOperationId, $originalMarker);
    if (is_array($skus[$targetSkuIndex]['freightCostLots'] ?? null)) {
        foreach ($skus[$targetSkuIndex]['freightCostLots'] as $lotIndex => $lot) {
            if (!is_array($lot) || receipt_text($lot['itemId'] ?? '') !== $freightItemId) continue;
            $skus[$targetSkuIndex]['freightCostLots'][$lotIndex]['originalQty'] = max((int)($lot['originalQty'] ?? 0), $oldActualQty);
            $skus[$targetSkuIndex]['freightCostLots'][$lotIndex]['qty'] = $correctedActualQty;
            $skus[$targetSkuIndex]['freightCostLots'][$lotIndex]['quantityCorrectedAt'] = $now;
            $skus[$targetSkuIndex]['freightCostLots'][$lotIndex]['quantityCorrectionOperationId'] = $correctionOperationId;
        }
    }

    $newDifference = $correctedActualQty - max(0, (int)($receipt['expectedQty'] ?? $freightItem['receivingExpectedQty'] ?? 0));
    $unitCost = max(0, (float)($receiptLines[0]['unitCostTwd'] ?? 0));
    $receiptLines[0]['originalQty'] = max((int)($receiptLines[0]['originalQty'] ?? 0), $oldActualQty);
    $receiptLines[0]['qty'] = $correctedActualQty;
    $receiptLines[0]['quantityCorrectedAt'] = $now;
    $receiptLines[0]['quantityCorrectionOperationId'] = $correctionOperationId;
    $purchaseReceipts[$receiptIndex]['receiptLines'] = $receiptLines;
    $purchaseReceipts[$receiptIndex]['originalActualQty'] = max((int)($receipt['originalActualQty'] ?? 0), $oldActualQty);
    $purchaseReceipts[$receiptIndex]['originalTotalQty'] = max((int)($receipt['originalTotalQty'] ?? 0), (int)($receipt['totalQty'] ?? $oldActualQty));
    $purchaseReceipts[$receiptIndex]['actualQty'] = $correctedActualQty;
    $purchaseReceipts[$receiptIndex]['totalQty'] = $correctedActualQty;
    $purchaseReceipts[$receiptIndex]['totalCostTwd'] = round($correctedActualQty * $unitCost, 2);
    $purchaseReceipts[$receiptIndex]['quantityDifference'] = $newDifference;
    $purchaseReceipts[$receiptIndex]['receivingVarianceReason'] = $reason;
    $purchaseReceipts[$receiptIndex]['quantityReconciliationRequired'] = $newDifference !== 0;
    $purchaseReceipts[$receiptIndex]['companyStockQty'] = max(0, $correctedActualQty - $linkedQty);
    if (is_array($purchaseReceipts[$receiptIndex]['receivingDocument'] ?? null)) {
        $purchaseReceipts[$receiptIndex]['receivingDocument']['companyStockQty'] = max(0, $correctedActualQty - $linkedQty);
        $purchaseReceipts[$receiptIndex]['receivingDocument']['note'] = receipt_text($purchaseReceipts[$receiptIndex]['receivingDocument']['note'] ?? '') . '；更正實收 ' . $oldActualQty . ' → ' . $correctedActualQty . ' 件：' . $reason;
    }
    $receiptCorrectionHistory = is_array($purchaseReceipts[$receiptIndex]['quantityCorrectionHistory'] ?? null)
        ? array_values($purchaseReceipts[$receiptIndex]['quantityCorrectionHistory'])
        : [];
    $receiptCorrectionHistory[] = $correctionLine + ['correctionOperationId' => $correctionOperationId];
    $purchaseReceipts[$receiptIndex]['quantityCorrectionHistory'] = array_slice($receiptCorrectionHistory, -50);
    $purchaseReceipts[$receiptIndex]['updatedAt'] = $now;

    $claimLines[0]['originalQty'] = max((int)($claimLines[0]['originalQty'] ?? 0), $oldActualQty);
    $claimLines[0]['qty'] = $correctedActualQty;
    $claimLines[0]['quantityCorrectedAt'] = $now;
    $claimLines[0]['quantityCorrectionOperationId'] = $correctionOperationId;
    $freightData['items'][$freightItemIndex]['inventoryReceiptClaim']['lines'] = $claimLines;
    $freightData['items'][$freightItemIndex]['inventoryReceiptClaim']['companyStockQty'] = max(0, $correctedActualQty - $linkedQty);
    $freightData['items'][$freightItemIndex]['inventoryReceiptClaim']['updatedAt'] = $now;
    $freightData['items'][$freightItemIndex]['companyStockQty'] = max(0, $correctedActualQty - $linkedQty);
    $freightData['items'][$freightItemIndex]['companyStock'] = $correctedActualQty > $linkedQty;
    $freightData['items'][$freightItemIndex]['receivingActualQty'] = $correctedActualQty;
    $freightData['items'][$freightItemIndex]['arrivalReceivedQty'] = $correctedActualQty;
    $freightData['items'][$freightItemIndex]['arrivalRemainingQty'] = max(0, (int)($freightItem['receivingExpectedQty'] ?? $receipt['expectedQty'] ?? 0) - $correctedActualQty);
    $freightData['items'][$freightItemIndex]['receivingQuantityDifference'] = $newDifference;
    $freightData['items'][$freightItemIndex]['receivingVarianceReason'] = $reason;
    $freightData['items'][$freightItemIndex]['quantityReconciliationRequired'] = $newDifference !== 0;
    $freightData['items'][$freightItemIndex]['quantityReconciliationStatus'] = $newDifference !== 0 ? 'corrected_pending_followup' : 'resolved';
    $freightData['items'][$freightItemIndex]['quantityReconciliationNote'] = $reason;
    $freightData['items'][$freightItemIndex]['quantityReconciliationUpdatedAt'] = $now;
    $freightData['items'][$freightItemIndex]['receivingStatus'] = $newDifference !== 0 ? '已入庫／數量差異已更正' : '已完成入庫';
    $freightData['items'][$freightItemIndex]['freightReceivingStatus'] = $freightData['items'][$freightItemIndex]['receivingStatus'];
    $freightData['items'][$freightItemIndex]['progress'] = $freightData['items'][$freightItemIndex]['receivingStatus'];
    if (is_array($freightData['items'][$freightItemIndex]['receivingInspection'] ?? null)) {
        $freightData['items'][$freightItemIndex]['receivingInspection']['originalActualQty'] = max((int)($freightData['items'][$freightItemIndex]['receivingInspection']['originalActualQty'] ?? 0), $oldActualQty);
        $freightData['items'][$freightItemIndex]['receivingInspection']['actualQty'] = $correctedActualQty;
        $freightData['items'][$freightItemIndex]['receivingInspection']['remainingQty'] = max(0, (int)($freightData['items'][$freightItemIndex]['receivingInspection']['expectedQty'] ?? 0) - $correctedActualQty);
        $freightData['items'][$freightItemIndex]['receivingInspection']['differenceQty'] = $newDifference;
        $freightData['items'][$freightItemIndex]['receivingInspection']['varianceReason'] = $reason;
        $freightData['items'][$freightItemIndex]['receivingInspection']['costBasisQty'] = $correctedActualQty;
        $freightData['items'][$freightItemIndex]['receivingInspection']['updatedAt'] = $now;
        if (is_array($freightData['items'][$freightItemIndex]['receivingInspection']['lines'] ?? null)
            && isset($freightData['items'][$freightItemIndex]['receivingInspection']['lines'][0])) {
            $freightData['items'][$freightItemIndex]['receivingInspection']['lines'][0]['originalActualQty'] = $oldActualQty;
            $freightData['items'][$freightItemIndex]['receivingInspection']['lines'][0]['actualQty'] = $correctedActualQty;
            $freightData['items'][$freightItemIndex]['receivingInspection']['lines'][0]['differenceQty'] = $newDifference;
        }
    }
    $freightCorrectionHistory = is_array($freightData['items'][$freightItemIndex]['quantityCorrectionHistory'] ?? null)
        ? array_values($freightData['items'][$freightItemIndex]['quantityCorrectionHistory'])
        : [];
    $freightCorrectionHistory[] = $correctionLine + ['correctionOperationId' => $correctionOperationId];
    $freightData['items'][$freightItemIndex]['quantityCorrectionHistory'] = array_slice($freightCorrectionHistory, -50);
    $statusHistory = is_array($freightData['items'][$freightItemIndex]['statusHistory'] ?? null)
        ? array_values($freightData['items'][$freightItemIndex]['statusHistory'])
        : [];
    $statusHistory[] = [
        'operationId' => $correctionOperationId,
        'status' => '正式入庫數量更正',
        'at' => $now,
        'by' => $operatorName,
        'oldActualQty' => $oldActualQty,
        'actualQty' => $correctedActualQty,
        'deductedQty' => $deductQty,
        'reason' => $reason,
    ];
    $freightData['items'][$freightItemIndex]['statusHistory'] = array_slice($statusHistory, -300);
    $freightData['items'][$freightItemIndex]['updatedAt'] = $now;
    $freightData['revision'] = max(0, (int)($freightData['revision'] ?? 0)) + 1;
    $freightData['updatedAt'] = $now;

    // Canonical stock and marker are committed together, followed by mirrors and audit documents.
    write_json($skusFile, $skus);
    sync_preorder_inventory_state($stateFile, $skus, $products, $correctionOperationId, [
        'kind' => 'freight_receipt_quantity_correction',
        'freightItemId' => $freightItemId,
        'originalOperationId' => $receiveOperationId,
        'qtyDelta' => -$deductQty,
        'appliedAt' => $now,
        'skuIds' => [$skuId],
        'productIds' => array_values(array_filter([receipt_text($receiptLines[0]['productId'] ?? $freightItem['productId'] ?? '')])),
    ]);
    write_json($purchaseReceiptsFile, array_values($purchaseReceipts));
    write_json($freightTrackingFile, $freightData);
    if ($maintenanceAuthorized) {
        $maintenancePermit['consumedAt'] = $now;
        $maintenancePermit['consumedBy'] = $operatorName;
        $maintenancePermit['correctionOperationId'] = $correctionOperationId;
        write_json($maintenancePermitFile, $maintenancePermit);
    }
    @unlink($dataDir . DIRECTORY_SEPARATOR . 'public-catalog.json');

    flock($catalogLock, LOCK_UN); fclose($catalogLock);
    flock($freightFileLock, LOCK_UN); fclose($freightFileLock);
    flock($receiptsFileLock, LOCK_UN); fclose($receiptsFileLock);
    respond([
        'ok' => true,
        'freightItemId' => $freightItemId,
        'receiptId' => receipt_text($receipt['id'] ?? ''),
        'skuId' => $skuId,
        'oldActualQty' => $oldActualQty,
        'correctedActualQty' => $correctedActualQty,
        'deductedQty' => $deductQty,
        'stockBefore' => $stockBefore,
        'stockAfter' => $stockAfter,
        'correctionOperationId' => $correctionOperationId,
        'message' => '已把正式入庫從 ' . $oldActualQty . ' 件更正為 ' . $correctedActualQty . ' 件，並扣回誤多入庫 ' . $deductQty . ' 件',
    ]);
}

if ($action === 'record-freight-match') {
    $purchaseOperator = require_purchase_operator($adminSessionsFile, $stateFile, ['物流集運', '採購區', '採購進貨建檔']);
    $freightItemId = trim((string)($payload['freightItemId'] ?? ''));
    $trackingNo = trim((string)($payload['trackingNo'] ?? ''));
    $fullBarcode = trim((string)($payload['barcode'] ?? ''));
    $inquiryId = trim((string)($payload['inquiryId'] ?? ''));
    $lineId = trim((string)($payload['lineId'] ?? ''));
    $itemIndex = max(0, (int)($payload['itemIndex'] ?? 0));
    $quantity = (int)($payload['quantity'] ?? 1);
    $mutationId = trim((string)($payload['clientMutationId'] ?? ''));
    $manualOverrideConfirmed = !empty($payload['manualOverrideConfirmed']);
    $manualOverrideReason = trim((string)($payload['manualOverrideReason'] ?? ''));
    $createdBy = trim((string)($purchaseOperator['name'] ?? $purchaseOperator['account'] ?? '')) ?: '管理者';
    if ($freightItemId === '' || $trackingNo === '' || $inquiryId === '') respond(['ok' => false, 'error' => '物流配對缺少物流明細、正式物流號或客戶訂單號'], 400);
    if ($quantity !== 1) respond(['ok' => false, 'error' => '每筆產品物流一次只對應一個客戶名額'], 400);
    if ($mutationId === '' || strlen($mutationId) > 120) respond(['ok' => false, 'error' => '缺少防重複配對編號，請重新整理後再試'], 400);
    $requestPayloadHash = hash('sha256', json_encode([
        'action' => 'record-freight-match',
        'freightItemId' => $freightItemId,
        'trackingNo' => lz_purchase_normalize($trackingNo),
        'barcode' => freight_barcode_key($fullBarcode),
        'inquiryId' => $inquiryId,
        'lineId' => $lineId,
        'itemIndex' => $lineId === '' ? $itemIndex : null,
        'quantity' => 1,
        'manualOverrideConfirmed' => $manualOverrideConfirmed,
        'manualOverrideReason' => $manualOverrideReason,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $freightLock = fopen($freightTrackingFile . '.lock', 'c+');
    if ($freightLock === false || !flock($freightLock, LOCK_SH)) respond(['ok' => false, 'error' => '目前無法鎖定物流資料，請稍後再試'], 503);
    $ledgerLock = lz_purchase_ledger_lock($preorderPurchaseLedgerFile);

    // Resolve idempotent retries from the append-only ledger before checking
    // mutable order, freight or product state. A successful first request must
    // remain repeatable even if the order was subsequently converted or the
    // freight record was edited by another screen.
    $ledger = lz_purchase_ledger_read($preorderPurchaseLedgerFile);
    $inquiryRows = read_json($inquiriesFile);
    $lines = lz_purchase_demand_lines($inquiryRows);
    $existingMutation = lz_purchase_mutation_lookup($ledger, $mutationId);
    $duplicatePurchase = null;
    if ($existingMutation) {
        if ((string)($existingMutation['action'] ?? '') !== 'record-freight-match') {
            flock($ledgerLock, LOCK_UN); fclose($ledgerLock); flock($freightLock, LOCK_UN); fclose($freightLock);
            respond(['ok' => false, 'error' => '相同操作編號已用於其他動作，已拒絕重複送出'], 409);
        }
        $duplicatePurchase = freight_match_purchase_by_id($ledger, (string)($existingMutation['result']['purchaseId'] ?? ''));
        if (!$duplicatePurchase) {
            foreach ($ledger['purchases'] ?? [] as $purchase) {
                if (is_array($purchase) && (string)($purchase['clientMutationId'] ?? '') === $mutationId) { $duplicatePurchase = $purchase; break; }
            }
        }
    }
    if (!$duplicatePurchase) {
        foreach ($ledger['purchases'] ?? [] as $purchase) {
            if (!is_array($purchase) || (string)($purchase['sourceType'] ?? '') !== 'freight-customer-match') continue;
            if ((string)($purchase['freightItemId'] ?? '') === $freightItemId) { $duplicatePurchase = $purchase; break; }
        }
    }
    if ($existingMutation && !$duplicatePurchase) {
        flock($ledgerLock, LOCK_UN); fclose($ledgerLock); flock($freightLock, LOCK_UN); fclose($freightLock);
        respond(['ok' => false, 'error' => '找不到相同操作編號的既有配對結果，請勿重新扣名額'], 409);
    }
    if ($duplicatePurchase) {
        $mismatch = freight_match_duplicate_mismatch($duplicatePurchase, $freightItemId, $trackingNo, $inquiryId, $lineId, $itemIndex, $manualOverrideConfirmed, $manualOverrideReason);
        if ($mismatch !== '') {
            flock($ledgerLock, LOCK_UN); fclose($ledgerLock); flock($freightLock, LOCK_UN); fclose($freightLock);
            respond(['ok' => false, 'error' => $mismatch], 409);
        }
        $duplicateLine = freight_match_duplicate_line_payload($duplicatePurchase, $ledger, $lines);
        $orderDay = (string)($duplicateLine['orderDay'] ?? $duplicatePurchase['scopeFrom'] ?? date('Y-m-d'));
        if ($orderDay === '') $orderDay = date('Y-m-d');
        $summary = lz_purchase_summary($inquiryRows, $ledger, $orderDay, $orderDay);
        flock($ledgerLock, LOCK_UN); fclose($ledgerLock); flock($freightLock, LOCK_UN); fclose($freightLock);
        respond(['ok' => true, 'duplicate' => true, 'purchaseId' => (string)($duplicatePurchase['purchaseId'] ?? ''), 'line' => $duplicateLine, 'summary' => $summary]);
    }

    $freightData = read_json($freightTrackingFile);
    $freightItem = freight_match_saved_item($freightData, $freightItemId);
    if (!$freightItem) {
        flock($ledgerLock, LOCK_UN); fclose($ledgerLock); flock($freightLock, LOCK_UN); fclose($freightLock);
        respond(['ok' => false, 'error' => '找不到剛儲存的物流明細，未扣客戶名額'], 404);
    }
    if (lz_purchase_normalize($freightItem['trackingNo'] ?? '') !== lz_purchase_normalize($trackingNo)) {
        flock($ledgerLock, LOCK_UN); fclose($ledgerLock); flock($freightLock, LOCK_UN); fclose($freightLock);
        respond(['ok' => false, 'error' => '物流單號已被修改，未扣客戶名額'], 409);
    }
    $savedInquiryId = trim((string)($freightItem['inquiryId'] ?? ''));
    $savedItemIndex = isset($freightItem['inquiryItemIndex']) ? max(0, (int)$freightItem['inquiryItemIndex']) : null;
    $savedLineId = trim((string)($freightItem['inquiryLineId'] ?? ''));
    $hasSavedLink = $savedInquiryId === $inquiryId
        && (($savedLineId !== '' && $lineId !== '' && hash_equals($savedLineId, $lineId)) || ($savedLineId === '' && $savedItemIndex !== null && $savedItemIndex === $itemIndex));
    foreach (is_array($freightItem['customerLinks'] ?? null) ? $freightItem['customerLinks'] : [] as $link) {
        if (!is_array($link)) continue;
        $linkLineId = trim((string)($link['lineId'] ?? ''));
        $sameLine = $lineId !== '' && $linkLineId !== ''
            ? hash_equals($linkLineId, $lineId)
            : ($linkLineId === '' && isset($link['itemIndex']) && max(0, (int)$link['itemIndex']) === $itemIndex);
        if (trim((string)($link['inquiryId'] ?? '')) === $inquiryId && $sameLine) $hasSavedLink = true;
    }
    if (!$hasSavedLink) {
        flock($ledgerLock, LOCK_UN); fclose($ledgerLock); flock($freightLock, LOCK_UN); fclose($freightLock);
        respond(['ok' => false, 'error' => '物流明細與客戶訂單連結不一致，未扣客戶名額'], 409);
    }

    $targetLine = null;
    foreach ($lines as $line) {
        if ((string)($line['inquiryId'] ?? '') !== $inquiryId) continue;
        if ($lineId !== '' && (string)($line['lineId'] ?? '') === $lineId) { $targetLine = $line; break; }
        if ($lineId === '' && (int)($line['itemIndex'] ?? -1) === $itemIndex) { $targetLine = $line; break; }
    }
    if (!$targetLine || empty($targetLine['matchable'])) {
        flock($ledgerLock, LOCK_UN); fclose($ledgerLock); flock($freightLock, LOCK_UN); fclose($freightLock);
        respond(['ok' => false, 'error' => '客戶訂單產品缺少可比對的 SKU／產品編號，未扣客戶名額'], 409);
    }
    if (!freight_match_line_is_open($targetLine)) {
        flock($ledgerLock, LOCK_UN); fclose($ledgerLock); flock($freightLock, LOCK_UN); fclose($freightLock);
        respond(['ok' => false, 'error' => '這筆客戶訂單已取消、轉單或完成，不能再扣名額'], 409);
    }
    $trackingMatchesLine = in_array(
        lz_purchase_normalize($trackingNo),
        array_map('lz_purchase_normalize', is_array($targetLine['supplierTrackingNos'] ?? null) ? $targetLine['supplierTrackingNos'] : []),
        true
    );
    $matchMode = trim((string)($freightItem['customerMatchMode'] ?? ''));
    $barcodeMatchesLine = $fullBarcode !== '' && freight_line_matches_barcode($targetLine, read_json($skusFile), $fullBarcode);
    if (!$trackingMatchesLine && !$barcodeMatchesLine && ($matchMode !== 'manual-order' || !$manualOverrideConfirmed || $manualOverrideReason === '')) {
        flock($ledgerLock, LOCK_UN); fclose($ledgerLock); flock($freightLock, LOCK_UN); fclose($freightLock);
        respond(['ok' => false, 'error' => '正式物流單號與完整條碼都未對上這筆客戶訂單；請重新查詢，或明確使用人工訂單備援'], 409);
    }
    $savedProductCode = lz_purchase_normalize($freightItem['productCode'] ?? '');
    $targetProductCode = lz_purchase_normalize($targetLine['code'] ?? '');
    if ($savedProductCode !== '' && $targetProductCode !== '' && !hash_equals($savedProductCode, $targetProductCode)) {
        flock($ledgerLock, LOCK_UN); fclose($ledgerLock); flock($freightLock, LOCK_UN); fclose($freightLock);
        respond(['ok' => false, 'error' => '物流明細的正式產品編號與客戶訂單不一致，未扣名額'], 409);
    }
    $resolvedLineId = (string)$targetLine['lineId'];
    $businessKey = hash('sha256', implode("\0", [$freightItemId, lz_purchase_normalize($trackingNo), $inquiryId, $resolvedLineId, '1']));

    [$ledger, $covered] = freight_match_line_coverage($ledger, $lines);
    $lineKey = lz_purchase_line_key($inquiryId, $resolvedLineId);
    $requestedQty = max(0, (int)($targetLine['requestedQty'] ?? 0));
    $coveredQty = min($requestedQty, max(0, (int)($covered[$lineKey] ?? 0)));
    if ($coveredQty >= $requestedQty) {
        flock($ledgerLock, LOCK_UN); fclose($ledgerLock); flock($freightLock, LOCK_UN); fclose($freightLock);
        respond(['ok' => false, 'error' => '這位客戶的這個品項已全數抵銷，沒有可再扣的名額'], 409);
    }

    $now = date(DATE_ATOM);
    $purchaseId = 'PUR-FRT-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $orderDay = (string)($targetLine['orderDay'] ?? date('Y-m-d'));
    $ledger['purchases'][] = [
        'purchaseId' => $purchaseId,
        'sourceType' => 'freight-customer-match',
        'sourceId' => $businessKey,
        'sourcePayloadHash' => $requestPayloadHash,
        'requestPayloadHash' => $requestPayloadHash,
        'clientMutationId' => $mutationId,
        'freightItemId' => $freightItemId,
        'trackingNo' => $trackingNo,
        'matchMode' => $trackingMatchesLine ? 'tracking-exact' : ($barcodeMatchesLine ? 'barcode-exact' : 'manual-order'),
        'matchedBarcode' => $barcodeMatchesLine ? $fullBarcode : '',
        'manualOverrideReason' => ($trackingMatchesLine || $barcodeMatchesLine) ? '' : $manualOverrideReason,
        'inquiryId' => $inquiryId,
        'inquiryLineId' => $resolvedLineId,
        'inquiryItemIndex' => (int)($targetLine['itemIndex'] ?? $itemIndex),
        'requestedQtyAtMatch' => $requestedQty,
        'customerName' => (string)($targetLine['customerName'] ?? ''),
        'customerPhone' => (string)($targetLine['customerPhone'] ?? ''),
        'groupKey' => (string)$targetLine['groupKey'],
        'warehouse' => (string)($targetLine['warehouse'] ?? 'TW'),
        'warehouseLabel' => (string)($targetLine['warehouseLabel'] ?? ''),
        'skuId' => (string)($targetLine['skuId'] ?? ''),
        'productId' => (string)($targetLine['productId'] ?? ''),
        'code' => (string)($targetLine['code'] ?? ''),
        'title' => (string)($targetLine['title'] ?? ''),
        'color' => (string)($targetLine['color'] ?? ''),
        'size' => (string)($targetLine['size'] ?? ''),
        'purchasedQty' => 1,
        'scopeFrom' => $orderDay,
        'scopeTo' => $orderDay,
        'scopeLineIds' => [$resolvedLineId],
        'allocationRows' => [[
            'lineId' => $resolvedLineId,
            'inquiryId' => $inquiryId,
            'customerKey' => (string)($targetLine['customerKey'] ?? ''),
            'orderDay' => $orderDay,
            'qty' => 1,
        ]],
        'surplusQty' => 0,
        'surplusAnchorLineId' => $resolvedLineId,
        'surplusAnchorInquiryId' => $inquiryId,
        'surplusAnchorDay' => $orderDay,
        'committedAt' => $now,
        'createdBy' => $createdBy,
        'status' => 'active',
    ];
    $ledger = lz_purchase_recompute($ledger, $lines);
    $ledger['revision'] = max(0, (int)($ledger['revision'] ?? 0)) + 1;
    $ledger['updatedAt'] = $now;
    $resolvedMatchMode = $trackingMatchesLine ? 'tracking-exact' : ($barcodeMatchesLine ? 'barcode-exact' : 'manual-order');
    $ledger['history'][] = ['event' => 'freight-customer-matched', 'purchaseId' => $purchaseId, 'freightItemId' => $freightItemId, 'trackingNo' => $trackingNo, 'barcode' => $barcodeMatchesLine ? $fullBarcode : '', 'inquiryId' => $inquiryId, 'lineId' => $resolvedLineId, 'qty' => 1, 'matchMode' => $resolvedMatchMode, 'manualOverrideReason' => $resolvedMatchMode === 'manual-order' ? $manualOverrideReason : '', 'at' => $now, 'by' => $createdBy, 'clientMutationId' => $mutationId];
    $ledger['history'] = array_slice($ledger['history'], -1000);
    $ledger = lz_purchase_remember_mutation($ledger, $mutationId, $action, $requestPayloadHash, $now, ['purchaseId' => $purchaseId]);
    write_json($preorderPurchaseLedgerFile, $ledger);
    $summary = lz_purchase_summary($inquiryRows, $ledger, $orderDay, $orderDay);
    $linePayload = freight_match_candidate_payload($targetLine, $coveredQty + 1);
    flock($ledgerLock, LOCK_UN); fclose($ledgerLock); flock($freightLock, LOCK_UN); fclose($freightLock);
    respond(['ok' => true, 'purchaseId' => $purchaseId, 'line' => $linePayload, 'summary' => $summary]);
}

if ($action === 'record-purchase') {
    $purchaseOperator = require_purchase_operator($adminSessionsFile, $stateFile);
    [$from, $to] = lz_purchase_date_range($payload['from'] ?? '', $payload['to'] ?? '');
    $groupKey = trim((string)($payload['groupKey'] ?? ''));
    $quantity = max(0, (int)($payload['quantity'] ?? 0));
    $mutationId = trim((string)($payload['clientMutationId'] ?? ''));
    $freightItemId = trim((string)($payload['freightItemId'] ?? ''));
    $selectedConfirmationKeys = is_array($payload['selectedConfirmationKeys'] ?? null)
        ? array_values(array_unique(array_filter(array_map('strval', $payload['selectedConfirmationKeys']))))
        : [];
    $createdBy = trim((string)($purchaseOperator['name'] ?? $purchaseOperator['account'] ?? '')) ?: '管理者';
    if (!preg_match('/^[a-f0-9]{64}$/i', $groupKey)) respond(['ok' => false, 'error' => '採購品項識別不正確，請重新整理'], 400);
    if ($quantity <= 0 || $quantity > 100000) respond(['ok' => false, 'error' => '本次實際買到數量必須大於 0'], 400);
    if ($mutationId === '' || strlen($mutationId) > 120) respond(['ok' => false, 'error' => '缺少防重複操作編號，請重新整理後再試'], 400);
    $payloadHash = hash('sha256', json_encode([
        'action' => 'record-purchase',
        'from' => $from,
        'to' => $to,
        'groupKey' => $groupKey,
        'quantity' => $quantity,
        'freightItemId' => $freightItemId,
        'selectedConfirmationKeys' => $selectedConfirmationKeys,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $lock = lz_purchase_ledger_lock($preorderPurchaseLedgerFile);
    $ledger = lz_purchase_ledger_read($preorderPurchaseLedgerFile);
    $inquiryRows = read_json($inquiriesFile);
    $existingMutation = lz_purchase_mutation_lookup($ledger, $mutationId);
    if ($existingMutation) {
        $existingPayloadHash = (string)($existingMutation['payloadHash'] ?? '');
        if ($existingPayloadHash === '' || !hash_equals($existingPayloadHash, $payloadHash)) {
            flock($lock, LOCK_UN);
            fclose($lock);
            respond(['ok' => false, 'error' => '相同操作編號的採購內容不同，已拒絕重複送出'], 409);
        }
        $summary = lz_purchase_summary($inquiryRows, $ledger, $from, $to);
        flock($lock, LOCK_UN);
        fclose($lock);
        $existingPurchaseId = (string)($existingMutation['result']['purchaseId'] ?? '');
        $existingPurchase = null;
        foreach ($ledger['purchases'] ?? [] as $purchaseRow) {
            if (is_array($purchaseRow) && (string)($purchaseRow['purchaseId'] ?? '') === $existingPurchaseId) { $existingPurchase = $purchaseRow; break; }
        }
        respond(['ok' => true, 'duplicate' => true, 'purchaseId' => $existingPurchaseId, 'purchase' => $existingPurchase, 'allocationRows' => is_array($existingPurchase['allocationRows'] ?? null) ? array_values($existingPurchase['allocationRows']) : [], 'summary' => $summary]);
    }
    $allDemandLines = lz_purchase_demand_lines($inquiryRows);
    $ledger = lz_purchase_recompute($ledger, $allDemandLines);
    $matchingLines = array_values(array_filter($allDemandLines, static function ($line) use ($groupKey, $from, $to) {
        return (string)($line['groupKey'] ?? '') === $groupKey && !empty($line['matchable']) && (string)($line['orderDay'] ?? '') >= $from && (string)($line['orderDay'] ?? '') <= $to;
    }));
    if (!$matchingLines) {
        flock($lock, LOCK_UN);
        fclose($lock);
        respond(['ok' => false, 'error' => '這個日期範圍找不到可抵銷的相同產品、顏色、尺寸與倉別'], 409);
    }
    if ($selectedConfirmationKeys) {
        $matchingKeyMap = [];
        foreach ($matchingLines as $matchingLine) {
            $matchingKeyMap[lz_purchase_shortage_confirmation_key($matchingLine['inquiryId'] ?? '', $matchingLine['lineId'] ?? '')] = true;
        }
        foreach ($selectedConfirmationKeys as $confirmationKey) {
            $confirmation = $ledger['shortageConfirmations'][$confirmationKey] ?? null;
            if (!is_array($confirmation) || !isset($matchingKeyMap[$confirmationKey])) {
                flock($lock, LOCK_UN);
                fclose($lock);
                respond(['ok' => false, 'error' => '所選欠貨客戶尚未由承辦人署名確認，或品項已變更，請重新檢查'], 409);
            }
        }
    }
    $variant = $matchingLines[0];
    $allocationSnapshot = lz_purchase_snapshot_selected_allocations($ledger, $matchingLines, $quantity, $selectedConfirmationKeys);
    if ($selectedConfirmationKeys && !$allocationSnapshot['allocationRows']) {
        flock($lock, LOCK_UN);
        fclose($lock);
        respond(['ok' => false, 'error' => '所選客戶目前已沒有尚欠數量，請重新整理'], 409);
    }
    $now = date(DATE_ATOM);
    $purchaseId = 'PUR-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $purchaseRecord = [
        'purchaseId' => $purchaseId,
        'sourceType' => $freightItemId !== '' ? 'freight-preallocation' : 'manual-purchase-summary',
        'sourceId' => $mutationId,
        'sourcePayloadHash' => $payloadHash,
        'clientMutationId' => $mutationId,
        'freightItemId' => $freightItemId,
        'selectedConfirmationKeys' => $selectedConfirmationKeys,
        'groupKey' => $groupKey,
        'warehouse' => (string)($variant['warehouse'] ?? 'TW'),
        'warehouseLabel' => (string)($variant['warehouseLabel'] ?? ''),
        'skuId' => (string)($variant['skuId'] ?? ''),
        'productId' => (string)($variant['productId'] ?? ''),
        'code' => (string)($variant['code'] ?? ''),
        'title' => (string)($variant['title'] ?? ''),
        'color' => (string)($variant['color'] ?? ''),
        'size' => (string)($variant['size'] ?? ''),
        'purchasedQty' => $quantity,
        'scopeFrom' => $from,
        'scopeTo' => $to,
        'scopeLineIds' => $allocationSnapshot['scopeLineIds'],
        'allocationRows' => $allocationSnapshot['allocationRows'],
        'surplusQty' => $allocationSnapshot['surplusQty'],
        'surplusAnchorLineId' => $allocationSnapshot['surplusAnchorLineId'],
        'surplusAnchorInquiryId' => $allocationSnapshot['surplusAnchorInquiryId'],
        'surplusAnchorDay' => $allocationSnapshot['surplusAnchorDay'],
        'committedAt' => $now,
        'createdBy' => $createdBy,
        'status' => 'active',
    ];
    $ledger['purchases'][] = $purchaseRecord;
    $ledger = lz_purchase_recompute($ledger, $allDemandLines);
    $ledger['revision'] = max(0, (int)($ledger['revision'] ?? 0)) + 1;
    $ledger['updatedAt'] = $now;
    $ledger['history'][] = ['event' => 'purchase-recorded', 'purchaseId' => $purchaseId, 'groupKey' => $groupKey, 'qty' => $quantity, 'from' => $from, 'to' => $to, 'at' => $now, 'by' => $createdBy, 'clientMutationId' => $mutationId];
    $ledger['history'] = array_slice($ledger['history'], -1000);
    $ledger = lz_purchase_remember_mutation($ledger, $mutationId, $action, $payloadHash, $now, ['purchaseId' => $purchaseId]);
    write_json($preorderPurchaseLedgerFile, $ledger);
    $summary = lz_purchase_summary($inquiryRows, $ledger, $from, $to);
    flock($lock, LOCK_UN);
    fclose($lock);
    respond(['ok' => true, 'purchaseId' => $purchaseId, 'purchase' => $purchaseRecord, 'allocationRows' => $allocationSnapshot['allocationRows'], 'summary' => $summary]);
}

if ($action === 'cancel-purchase') {
    $purchaseOperator = require_purchase_operator($adminSessionsFile, $stateFile);
    $purchaseId = trim((string)($payload['purchaseId'] ?? ''));
    $mutationId = trim((string)($payload['clientMutationId'] ?? ''));
    $cancelledBy = trim((string)($purchaseOperator['name'] ?? $purchaseOperator['account'] ?? '')) ?: '管理者';
    if ($purchaseId === '') respond(['ok' => false, 'error' => '缺少要撤銷的採購紀錄'], 400);
    if ($mutationId === '' || strlen($mutationId) > 120) respond(['ok' => false, 'error' => '缺少防重複操作編號，請重新整理後再試'], 400);
    $lock = lz_purchase_ledger_lock($preorderPurchaseLedgerFile);
    $ledger = lz_purchase_ledger_read($preorderPurchaseLedgerFile);
    $inquiryRows = read_json($inquiriesFile);
    $targetIndex = -1;
    foreach ($ledger['purchases'] as $index => $purchase) {
        if ((string)($purchase['purchaseId'] ?? '') === $purchaseId) { $targetIndex = (int)$index; break; }
    }
    if ($targetIndex < 0) {
        flock($lock, LOCK_UN);
        fclose($lock);
        respond(['ok' => false, 'error' => '找不到這筆採購紀錄'], 404);
    }
    $target = $ledger['purchases'][$targetIndex];
    [$from, $to] = lz_purchase_date_range($payload['from'] ?? ($target['scopeFrom'] ?? ''), $payload['to'] ?? ($target['scopeTo'] ?? ''));
    $payloadHash = hash('sha256', json_encode([
        'action' => 'cancel-purchase',
        'purchaseId' => $purchaseId,
        'from' => $from,
        'to' => $to,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $existingMutation = lz_purchase_mutation_lookup($ledger, $mutationId);
    if ($existingMutation) {
        $existingPayloadHash = (string)($existingMutation['payloadHash'] ?? '');
        if ($existingPayloadHash === '' || !hash_equals($existingPayloadHash, $payloadHash)) {
            flock($lock, LOCK_UN);
            fclose($lock);
            respond(['ok' => false, 'error' => '相同操作編號的撤銷內容不同，已拒絕重複送出'], 409);
        }
        $summary = lz_purchase_summary($inquiryRows, $ledger, $from, $to);
        flock($lock, LOCK_UN);
        fclose($lock);
        respond(['ok' => true, 'duplicate' => true, 'cancelledPurchaseId' => (string)($existingMutation['result']['cancelledPurchaseId'] ?? ''), 'summary' => $summary]);
    }
    if ((string)($target['status'] ?? 'active') !== 'active') {
        flock($lock, LOCK_UN);
        fclose($lock);
        respond(['ok' => false, 'error' => '這筆採購紀錄已撤銷，不能重複撤銷'], 409);
    }
    $now = date(DATE_ATOM);
    $ledger['purchases'][$targetIndex]['status'] = 'cancelled';
    $ledger['purchases'][$targetIndex]['cancelledAt'] = $now;
    $ledger['purchases'][$targetIndex]['cancelledBy'] = $cancelledBy;
    $ledger['purchases'][$targetIndex]['cancelMutationId'] = $mutationId;
    $ledger['purchases'][$targetIndex]['cancelPayloadHash'] = $payloadHash;
    $ledger = lz_purchase_recompute($ledger, lz_purchase_demand_lines($inquiryRows));
    $ledger['revision'] = max(0, (int)($ledger['revision'] ?? 0)) + 1;
    $ledger['updatedAt'] = $now;
    $ledger['history'][] = ['event' => 'purchase-cancelled', 'purchaseId' => $purchaseId, 'groupKey' => (string)($target['groupKey'] ?? ''), 'qty' => max(0, (int)($target['purchasedQty'] ?? 0)), 'at' => $now, 'by' => $cancelledBy, 'clientMutationId' => $mutationId];
    $ledger['history'] = array_slice($ledger['history'], -1000);
    $ledger = lz_purchase_remember_mutation($ledger, $mutationId, $action, $payloadHash, $now, ['cancelledPurchaseId' => $purchaseId]);
    write_json($preorderPurchaseLedgerFile, $ledger);
    $summary = lz_purchase_summary($inquiryRows, $ledger, $from, $to);
    flock($lock, LOCK_UN);
    fclose($lock);
    respond(['ok' => true, 'cancelledPurchaseId' => $purchaseId, 'summary' => $summary]);
}

if ($action === 'admin-sales-login') {
    $operator = require_purchase_operator($adminSessionsFile, $stateFile);
    if (!operator_is_admin($operator)) {
        respond(['ok' => false, 'error' => '只有管理者可以直接進入業務後台'], 403);
    }
    $sourceHash = trim((string)($operator['tokenHash'] ?? ''));
    $fingerprint = trim((string)($operator['credentialFingerprint'] ?? ''));
    if ($sourceHash === '' || $fingerprint === '') {
        respond(['ok' => false, 'error' => '管理者登入資料不完整，請重新登入後台'], 401);
    }
    $token = bin2hex(random_bytes(24));
    $expiresAt = min((int)($operator['expiresAt'] ?? 0), time() + (24 * 60 * 60));
    if ($expiresAt <= time()) respond(['ok' => false, 'error' => '管理者登入已逾時，請重新登入後台'], 401);
    $account = trim((string)($operator['account'] ?? 'admin'));
    $name = trim((string)($operator['name'] ?? $account ?? '管理者'));
    if ($name === '') $name = '管理者';
    $sessions = array_values(array_filter(read_json($salesSessionsFile), static function ($session) use ($sourceHash) {
        if (!is_array($session) || (int)($session['expiresAt'] ?? 0) <= time()) return false;
        return strtolower(trim((string)($session['role'] ?? 'sales'))) !== 'admin'
            || !hash_equals((string)($session['sourceAdminSessionHash'] ?? ''), $sourceHash);
    }));
    $sessions[] = [
        'role' => 'admin',
        'account' => $account,
        'name' => $name,
        'token' => $token,
        'expiresAt' => $expiresAt,
        'sourceAdminSessionHash' => $sourceHash,
        'credentialFingerprint' => $fingerprint,
        'createdAt' => date(DATE_ATOM),
    ];
    write_json($salesSessionsFile, $sessions);
    respond(['ok' => true, 'login' => [
        'until' => $expiresAt * 1000,
        'role' => 'admin',
        'name' => $name,
        'account' => $account,
        'token' => $token,
        'permissions' => ['*'],
        'serverSession' => true,
    ]]);
}

if ($action === 'sales-login') {
    $account = trim((string)($payload['account'] ?? ''));
    $password = trim((string)($payload['password'] ?? ''));
    $person = find_sales_account($account, $salesCustomersFile, $stateFile);
    if (!$person || $password === '' || (string)($person['password'] ?? '') !== $password) {
        respond(['ok' => false, 'error' => 'Akun sales atau password salah'], 403);
    }
    $token = bin2hex(random_bytes(24));
    $expiresAt = time() + (24 * 60 * 60);
    $sessions = array_values(array_filter(read_json($salesSessionsFile), function ($session) {
        return is_array($session) && (int)($session['expiresAt'] ?? 0) > time();
    }));
    $sessions[] = [
        'account' => (string)($person['account'] ?? $account),
        'name' => (string)($person['name'] ?? $account),
        'token' => $token,
        'expiresAt' => $expiresAt,
        'createdAt' => date(DATE_ATOM),
    ];
    write_json($salesSessionsFile, $sessions);
    respond(['ok' => true, 'login' => [
        'until' => $expiresAt * 1000,
        'role' => 'sales',
        'name' => (string)($person['name'] ?? $account),
        'account' => (string)($person['account'] ?? $account),
        'token' => $token,
    ]]);
}

if ($action === 'customer-phone-order-history') {
    $login = is_array($payload['salesLogin'] ?? null) ? $payload['salesLogin'] : null;
    $trustedLogin = trusted_business_login($login, $salesCustomersFile, $stateFile, $salesSessionsFile);
    if (!$trustedLogin) respond(['ok' => false, 'error' => 'Sales login diperlukan'], 403);
    $phone = normalized_order_phone($payload['phone'] ?? '');
    if (strlen($phone) < 6) respond(['ok' => false, 'error' => 'Nomor telepon belum lengkap'], 400);
    $timezone = new DateTimeZone('Asia/Taipei');
    $today = (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
    $days = min(365, max(1, (int)($payload['days'] ?? 90)));
    $cutoff = (new DateTimeImmutable('today', $timezone))->modify('-' . $days . ' days');

    $customerIds = [];
    $requestedCustomerId = trim((string)($payload['customerRecordId'] ?? ''));
    if ($requestedCustomerId !== '') $customerIds[$requestedCustomerId] = true;
    foreach (read_json($customersFile) as $customerRow) {
        if (!is_array($customerRow) || normalized_order_phone($customerRow['phone'] ?? '') !== $phone) continue;
        $id = customer_history_record_id($customerRow);
        if ($id !== '') $customerIds[$id] = true;
    }

    $state = read_json($stateFile);
    $formalOrders = [];
    $seenFormal = [];
    foreach ([read_json($ordersFile), (is_array($state['orders'] ?? null) ? $state['orders'] : [])] as $group) {
        foreach ($group as $row) {
            if (!is_array($row)) continue;
            $id = trim((string)($row['id'] ?? ''));
            if ($id === '' || isset($seenFormal[$id])) continue;
            $seenFormal[$id] = true;
            $formalOrders[] = $row;
        }
    }

    $matches = [];
    $coveredInquiryIds = [];
    foreach ($formalOrders as $row) {
        foreach ([$row['id'] ?? '', $row['sourceInquiryId'] ?? '', $row['inquiryId'] ?? '', $row['convertedFromInquiryId'] ?? ''] as $linkedId) {
            $linkedId = trim((string)$linkedId);
            if ($linkedId !== '') $coveredInquiryIds[$linkedId] = true;
        }
        if (strtolower(trim((string)($row['status'] ?? ''))) === 'cancelled') continue;
        $customer = is_array($row['customer'] ?? null) ? $row['customer'] : [];
        $rowCustomerId = customer_history_record_id($row);
        $identityMatch = normalized_order_phone($customer['phone'] ?? '') === $phone || ($rowCustomerId !== '' && isset($customerIds[$rowCustomerId]));
        if (!$identityMatch || !customer_history_is_recent($row, $cutoff, $timezone)) continue;
        $rowDate = same_day_order_date($row, $timezone);
        $summary = same_day_order_summary($row, same_day_order_kind($row, false), $rowDate, $trustedLogin);
        $summary['period'] = $rowDate === $today ? 'today' : 'history';
        $matches[] = $summary;
    }
    foreach (read_json($inquiriesFile) as $row) {
        if (!is_array($row)) continue;
        $id = trim((string)($row['id'] ?? ''));
        if ($id !== '' && isset($coveredInquiryIds[$id])) continue;
        if (strtolower(trim((string)($row['status'] ?? ''))) === 'cancelled') continue;
        $customer = is_array($row['customer'] ?? null) ? $row['customer'] : [];
        $rowCustomerId = customer_history_record_id($row);
        $identityMatch = normalized_order_phone($customer['phone'] ?? '') === $phone || ($rowCustomerId !== '' && isset($customerIds[$rowCustomerId]));
        if (!$identityMatch) continue;
        $unfinished = customer_history_is_unfinished_preorder($row);
        if (!$unfinished && !customer_history_is_recent($row, $cutoff, $timezone)) continue;
        if (!empty($row['convertedOrderId']) || !empty($row['convertedToOrderId']) || strtolower(trim((string)($row['status'] ?? ''))) === 'converted') continue;
        $rowDate = same_day_order_date($row, $timezone);
        $summary = same_day_order_summary($row, same_day_order_kind($row, true), $rowDate, $trustedLogin);
        $summary['period'] = $rowDate === $today ? 'today' : 'history';
        $summary['unfinished'] = $unfinished;
        $matches[] = $summary;
    }
    usort($matches, static function ($a, $b): int {
        return strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''));
    });
    $matches = array_slice($matches, 0, 20);
    respond([
        'ok' => true,
        'phone' => $phone,
        'days' => $days,
        'count' => count($matches),
        'todayCount' => count(array_filter($matches, static fn($row) => ($row['period'] ?? '') === 'today')),
        'historyCount' => count(array_filter($matches, static fn($row) => ($row['period'] ?? '') === 'history')),
        'orders' => $matches,
    ]);
}

if ($action === 'same-day-phone-orders') {
    $login = is_array($payload['salesLogin'] ?? null) ? $payload['salesLogin'] : null;
    $trustedLogin = trusted_business_login($login, $salesCustomersFile, $stateFile, $salesSessionsFile);
    if (!$trustedLogin) respond(['ok' => false, 'error' => 'Sales login diperlukan'], 403);
    $phone = normalized_order_phone($payload['phone'] ?? '');
    if (strlen($phone) < 6) respond(['ok' => false, 'error' => 'Nomor telepon belum lengkap'], 400);
    $timezone = new DateTimeZone('Asia/Taipei');
    $date = trim((string)($payload['date'] ?? ''));
    if ($date === '') $date = (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) respond(['ok' => false, 'error' => 'Format tanggal salah'], 400);

    $state = read_json($stateFile);
    $formalOrders = [];
    $seenFormal = [];
    foreach ([read_json($ordersFile), (is_array($state['orders'] ?? null) ? $state['orders'] : [])] as $group) {
        foreach ($group as $row) {
            if (!is_array($row)) continue;
            $id = trim((string)($row['id'] ?? ''));
            if ($id === '' || isset($seenFormal[$id])) continue;
            $seenFormal[$id] = true;
            $formalOrders[] = $row;
        }
    }

    $matches = [];
    $coveredInquiryIds = [];
    foreach ($formalOrders as $row) {
        foreach ([$row['id'] ?? '', $row['sourceInquiryId'] ?? '', $row['inquiryId'] ?? '', $row['convertedFromInquiryId'] ?? ''] as $linkedId) {
            $linkedId = trim((string)$linkedId);
            if ($linkedId !== '') $coveredInquiryIds[$linkedId] = true;
        }
        $customer = is_array($row['customer'] ?? null) ? $row['customer'] : [];
        if (normalized_order_phone($customer['phone'] ?? '') !== $phone) continue;
        $rowDate = same_day_order_date($row, $timezone);
        if ($rowDate !== $date) continue;
        $matches[] = same_day_order_summary($row, same_day_order_kind($row, false), $rowDate, $trustedLogin);
    }
    foreach (read_json($inquiriesFile) as $row) {
        if (!is_array($row)) continue;
        $id = trim((string)($row['id'] ?? ''));
        if ($id !== '' && isset($coveredInquiryIds[$id])) continue;
        if (!empty($row['convertedOrderId']) || !empty($row['convertedToOrderId']) || strtolower(trim((string)($row['status'] ?? ''))) === 'converted') continue;
        $customer = is_array($row['customer'] ?? null) ? $row['customer'] : [];
        if (normalized_order_phone($customer['phone'] ?? '') !== $phone) continue;
        $rowDate = same_day_order_date($row, $timezone);
        if ($rowDate !== $date) continue;
        $matches[] = same_day_order_summary($row, same_day_order_kind($row, true), $rowDate, $trustedLogin);
    }
    usort($matches, static function ($a, $b): int {
        return strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''));
    });
    respond([
        'ok' => true,
        'phone' => $phone,
        'date' => $date,
        'count' => count($matches),
        'orders' => array_slice($matches, 0, 20),
    ]);
}

if ($action === 'update-product-sales') {
    if (!valid_sales_account(is_array($payload['salesLogin'] ?? null) ? $payload['salesLogin'] : null, $salesCustomersFile, $stateFile, $salesSessionsFile)) {
        respond(['ok' => false, 'error' => 'Sales login diperlukan'], 403);
    }
    $productId = (string)($payload['productId'] ?? '');
    if ($productId === '') respond(['ok' => false, 'error' => 'Produk tidak ditemukan'], 400);
    $title = trim((string)($payload['frontTitle'] ?? ''));
    $category = trim((string)($payload['category'] ?? ''));
    $hasPrice = array_key_exists('price', $payload);
    $price = max(0, (float)($payload['price'] ?? 0));

    // Product and SKU catalog files are rewritten as whole JSON documents.
    // Share the receipt inventory lock so a sales-side price/category edit
    // cannot overwrite a concurrently committed receipt, cost, or stock marker.
    $catalogLock = acquire_inventory_file_lock($skusFile);
    $products = read_json($productsFile);
    $productIndex = find_index($products, $productId);
    if ($productIndex < 0) respond(['ok' => false, 'error' => 'Produk tidak ditemukan'], 404);
    if ($title !== '') $products[$productIndex]['frontTitle'] = $title;
    if ($category !== '') $products[$productIndex]['category'] = $category;
    if ($hasPrice) $products[$productIndex]['price'] = $price;
    $products[$productIndex]['updatedAt'] = date(DATE_ATOM);
    write_json($productsFile, $products);

    $skus = read_json($skusFile);
    if ($hasPrice) {
        foreach ($skus as &$sku) {
            if ((string)($sku['productId'] ?? '') === $productId) $sku['price'] = $price;
        }
        unset($sku);
        write_json($skusFile, $skus);
    }

    $state = read_json($stateFile);
    if (isset($state['products']) && is_array($state['products'])) {
        foreach ($state['products'] as &$product) {
            if ((string)($product['id'] ?? '') === $productId) {
                if ($title !== '') $product['frontTitle'] = $title;
                if ($category !== '') $product['category'] = $category;
                if ($hasPrice) $product['price'] = $price;
            }
        }
        unset($product);
    }
    if ($hasPrice && isset($state['skus']) && is_array($state['skus'])) {
        foreach ($state['skus'] as &$sku) {
            if ((string)($sku['productId'] ?? '') === $productId) $sku['price'] = $price;
        }
        unset($sku);
    }
    $state['updatedAt'] = date(DATE_ATOM);
    write_json($stateFile, $state);
    @unlink($dataDir . DIRECTORY_SEPARATOR . 'public-catalog.json');
    respond(['ok' => true, 'product' => public_catalog($productsFile, $skusFile, true)[$productIndex] ?? null]);
}

if ($action === 'save-customer-profile') {
    $login = is_array($payload['salesLogin'] ?? null) ? $payload['salesLogin'] : null;
    $isSales = valid_sales_account($login, $salesCustomersFile, $stateFile, $salesSessionsFile);
    if (!$isSales) require_purchase_operator($adminSessionsFile, $stateFile, ['會員管理', '訂單管理', '直播打單', '預購打單', '行政出貨單']);

    $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
    $customer = externalize_data_images($customer, __DIR__);
    $name = trim((string)($customer['name'] ?? ''));
    $phone = digits_only_value((string)($customer['phone'] ?? ''));
    if ($name === '') respond(['ok' => false, 'error' => '請先填客戶姓名'], 400);
    if (strlen($phone) < 6) respond(['ok' => false, 'error' => '電話至少需要 6 碼，才能串聯同一位客戶'], 400);

    $customer['name'] = $name;
    $customer['phone'] = $phone;
    $customer['customerProfileOnly'] = true;
    $customer['profileOnly'] = true;
    if ($isSales) {
        $customer['sales'] = valid_sales((string)($login['account'] ?? $login['name'] ?? ''));
        $customer['salesName'] = sales_label($customer['sales']);
        $customer['savedBy'] = trim((string)($login['account'] ?? ''));
    } elseif (trim((string)($payload['sales'] ?? '')) !== '') {
        $customer['sales'] = valid_sales((string)$payload['sales']);
        $customer['salesName'] = sales_label($customer['sales']);
        $customer['salesKey'] = $customer['sales'];
    }
    $customerRows = read_json($customersFile);
    $memberRows = read_json($membersFile);
    $customer = member_photo_sync_prepare_customer($customer, $customerRows, $memberRows);
    $customer = externalize_data_images($customer, __DIR__);
    $now = date(DATE_ATOM);
    $sync = sync_customer_contact_to_members($customer, $customersFile, $membersFile, $stateFile, $now);
    if (empty($sync['ok'])) respond(['ok' => false, 'error' => '客戶資料格式不完整，無法儲存'], 400);
    // Contact sync intentionally updates text fields only. When the operator
    // pasted or cropped a customer screenshot, persist that image as well;
    // otherwise the UI reports success while the customer profile has no
    // photo after the page is reloaded.
    $photoSync = sync_customer_member_photo($customer, __DIR__, 'customer-profile-upload');
    if (member_photo_sync_customer_photo($customer) !== '' && empty($photoSync['photoStored'])) {
        respond(['ok' => false, 'error' => '客戶資料已收到，但截圖未能儲存，請再試一次'], 500);
    }
    $matches = find_customer_matches($name, $phone, substr($phone, -6), $customersFile, $membersFile, 2, true);
    respond([
        'ok' => true,
        'saved' => true,
        'photoStored' => !empty($photoSync['photoStored']),
        'customer' => $matches[0] ?? member_public_match($customer, 'customer'),
        'message' => '客戶資料已儲存，可繼續找產品或切換打單頁',
    ]);
}

if ($action === 'create-temp-product') {
    $login = is_array($payload['salesLogin'] ?? null) ? $payload['salesLogin'] : null;
    if (!valid_sales_account($login, $salesCustomersFile, $stateFile, $salesSessionsFile)) {
        respond(['ok' => false, 'error' => 'Sales login diperlukan'], 403);
    }
    $title = trim((string)($payload['title'] ?? $payload['name'] ?? ''));
    $color = trim((string)($payload['color'] ?? ''));
    $size = trim((string)($payload['size'] ?? ''));
    $image = trim((string)($payload['image'] ?? ''));
    if ($title === '') respond(['ok' => false, 'error' => '請輸入臨時商品名稱或說明'], 400);
    if ($image === '') respond(['ok' => false, 'error' => '臨時商品必須先上傳一張產品照片'], 400);
    $imageRecord = externalize_data_images(['image' => $image], __DIR__);
    $rows = read_json($tempProductsFile);
    $todayRows = temp_product_rows($tempProductsFile);
    $code = next_temp_product_code($todayRows);
    $now = date(DATE_ATOM);
    $row = [
        'id' => $code,
        'code' => $code,
        'title' => $title,
        'color' => $color,
        'size' => $size !== '' ? $size : 'NO SIZE',
        'image' => (string)($imageRecord['image'] ?? ''),
        'sales' => valid_sales((string)($payload['sales'] ?? '')),
        'salesAccount' => strtolower(trim((string)($login['account'] ?? ''))),
        'salesName' => trim((string)($login['name'] ?? $login['account'] ?? '')),
        'businessDate' => date('Y-m-d'),
        'status' => 'temporary',
        'stock' => 0,
        'cost' => null,
        'price' => null,
        'createdAt' => $now,
        'updatedAt' => $now,
    ];
    array_unshift($rows, $row);
    write_json($tempProductsFile, $rows);
    respond(['ok' => true, 'tempProduct' => $row]);
}

if ($action === 'create') {
    $sales = valid_sales((string)($payload['sales'] ?? ''));
    $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
    $customer = externalize_data_images($customer, __DIR__);
    foreach (['forceNewCustomer', 'customerRecordId', 'customerId', 'customerRef', 'customerCode', 'customerRefType', 'customerProfileOnly'] as $field) {
        if (array_key_exists($field, $payload) && !array_key_exists($field, $customer)) $customer[$field] = $payload[$field];
    }
    $customerRowsForSync = read_json($customersFile);
    $memberRowsForSync = read_json($membersFile);
    $customer = member_photo_sync_prepare_customer($customer, $customerRowsForSync, $memberRowsForSync);
    $customer['sales'] = $sales;
    $customer['salesName'] = sales_label($sales);
    $customer['salesKey'] = $sales;
    // A matching member record can re-introduce an older embedded customer
    // photo during the sync above, so externalize once more after the merge.
    $customer = externalize_data_images($customer, __DIR__);
    $paymentSummary = externalize_data_images(is_array($payload['paymentSummary'] ?? null) ? $payload['paymentSummary'] : [], __DIR__);
    $name = trim((string)($customer['name'] ?? ''));
    $phone = trim((string)($customer['phone'] ?? ''));
    $blacklistGate = member_risk_gate(__DIR__, $phone, $name, $payload, '下單');
    if (!empty($blacklistGate['blocked'])) {
        respond($blacklistGate['response'], 409);
    }
    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    // Images uploaded by sales must live as files on the NAS. Keeping base64
    // strings in inquiries.json makes every purchase-stat request parse many
    // megabytes and can prevent today's orders from loading at all.
    $items = externalize_data_images($items, __DIR__);
    $orderType = ((string)($payload['orderType'] ?? 'normal')) === 'preorder' ? 'preorder' : 'normal';
    $isPreorder = $orderType === 'preorder';
    $saveAsDraft = $isPreorder && !empty($payload['saveAsDraft']);
    $salesPurchaseOnly = $isPreorder && !empty($payload['salesPurchaseOnly']);
    // A preorder submitted by sales for a named customer is a customer order,
    // not public ready stock. It may only become a formal shipment.
    $customerOrderOnly = $isPreorder && $sales !== '' && !empty($payload['businessPreorder']) && $name !== '';
    // Preorders live in a purchase queue. The chosen value is the final arrival warehouse,
    // not a current-stock location and never publishes stock before receipt.
    $warehouseMap = ['TW' => '採購倉 → 台灣倉', 'ID' => '採購倉 → 印尼倉', 'CN' => '採購倉 → 中國倉（舊資料）'];
    $preorderWarehouse = strtoupper((string)($payload['preorderWarehouse'] ?? 'TW'));
    if (!isset($warehouseMap[$preorderWarehouse])) $preorderWarehouse = 'TW';
    $preorderWarehouseLabel = $warehouseMap[$preorderWarehouse];
    if ($name === '') respond(['ok' => false, 'error' => 'Nama pelanggan wajib diisi'], 400);
    if (!$items) respond(['ok' => false, 'error' => 'Daftar inquiry belum ada produk'], 400);
    if (!$isPreorder) {
        // LZ_ORDER_SAVE_ATOMIC_20260924: only check stock here; deduct after the inquiry row exists.
        adjust_stock($items, -1, $skusFile, $stateFile, false);
    }
    $now = date(DATE_ATOM);
    // IDs reveal the business flow at a glance: BYORDER for preorder purchase requests,
    // READY for ordinary ready-stock requests. Live orders are created by cart-order-api.php.
    $orderPrefix = $isPreorder ? 'BYORDER' : 'READY';
    $id = $orderPrefix . '-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $cleanItems = [];
    $seenLineIds = [];
    $productsForImages = read_json($productsFile);
    $skusForImages = read_json($skusFile);
    foreach ($items as $itemIndex => $item) {
        $qty = max(1, (int)($item['qty'] ?? 1));
        $price = max(0, (float)($item['price'] ?? 0));
        $cleanItem = [
            'lineId' => unique_inquiry_line_id($item['lineId'] ?? '', $seenLineIds),
            'requestedQty' => $qty,
            'skuId' => (string)($item['skuId'] ?? ''),
            'sku' => (string)($item['sku'] ?? $item['skuId'] ?? ''),
            'productId' => (string)($item['productId'] ?? ''),
            'code' => (string)($item['code'] ?? ''),
            'title' => (string)($item['title'] ?? ''),
            'color' => (string)($item['color'] ?? ''),
            'size' => (string)($item['size'] ?? ''),
            'qty' => $qty,
            'price' => $price,
            'image' => (string)($item['image'] ?? item_image_from_catalog($item, $productsForImages, $skusForImages)),
            'sourceWarehouse' => (string)($item['sourceWarehouse'] ?? ''),
            'sourceWarehouseCode' => (string)($item['sourceWarehouseCode'] ?? ''),
            'sourceStockAtOrder' => max(0, (int)($item['sourceStockAtOrder'] ?? 0)),
            'preorderNoStockChange' => $isPreorder,
            'temporaryProduct' => !empty($item['temporaryProduct']),
            'temporaryProductId' => (string)($item['temporaryProductId'] ?? ''),
            'temporaryProductCode' => (string)($item['temporaryProductCode'] ?? ''),
            'requiresManagerProductCode' => !empty($item['temporaryProduct']) || !empty($item['requiresManagerProductCode']),
        ];
        $capturedCost = isset($item['cost']) && is_numeric($item['cost']) ? round((float)$item['cost'], 2) : 0.0;
        if ($capturedCost > 0) {
            // Freeze the effective unit cost on the order line. Later receipts,
            // spreadsheet imports, or manual product edits must not rewrite the
            // historical cost used by this order.
            $cleanItem['cost'] = $capturedCost;
            $cleanItem['costSource'] = trim((string)($item['costSource'] ?? 'order_snapshot'));
            $cleanItem['costEffectiveAt'] = trim((string)($item['costEffectiveAt'] ?? ''));
            $cleanItem['costVersion'] = max(0, (int)($item['costVersion'] ?? 0));
        }
        $cleanItems[] = $cleanItem;
    }
    $subtotal = array_reduce($cleanItems, fn($sum, $item) => $sum + ((float)$item['price'] * (int)$item['qty']), 0);
    $regularShippingFee = max(0, (float)($customer['regularShippingFee'] ?? ($customer['shippingFee'] ?? 0)));
    $freeShipping = $isPreorder && $subtotal >= 1200;
    $shippingFee = $freeShipping ? 0.0 : $regularShippingFee;
    $orderTotal = $subtotal + $shippingFee;
    $paidAmount = max(0, min($orderTotal, (float)($paymentSummary['paidAmount'] ?? 0)));
    $paymentSummary['orderTotal'] = $orderTotal;
    $paymentSummary['paidAmount'] = $paidAmount;
    $paymentSummary['balance'] = max(0, $orderTotal - $paidAmount);
    $paymentSummary['receivableBalance'] = 0;
    $paymentSummary['unshippedBalance'] = $paymentSummary['balance'];
    $paymentSummary['status'] = $paidAmount <= 0 ? 'unpaid' : ($paymentSummary['balance'] > 0 ? 'partial' : 'paid');
    $matchedCustomer = [];
    if (!member_photo_sync_truthy($customer['forceNewCustomer'] ?? false)) {
        if (is_array($customer['member'] ?? null)) {
            $matchedCustomer = $customer['member'];
        } else {
            $customerMatchIndex = member_photo_sync_find_identity_index($customerRowsForSync, $customer);
            $memberMatchIndex = member_photo_sync_find_identity_index($memberRowsForSync, $customer);
            if ($customerMatchIndex >= 0) {
                $matchedCustomer = member_public_match($customerRowsForSync[$customerMatchIndex], 'customer');
            } elseif ($memberMatchIndex >= 0) {
                $matchedCustomer = member_public_match($memberRowsForSync[$memberMatchIndex], 'member');
            } elseif ((string)($customer['customerRefType'] ?? '') === 'legacy-phone') {
                $matchedCustomer = find_customer_match($name, $phone, (string)($customer['phoneLast6'] ?? ''), $customersFile, $membersFile) ?? [];
            }
        }
    }
    if ($matchedCustomer) {
        $matchedCustomer['name'] = $name;
        $matchedCustomer['phone'] = $phone;
    }
    $row = [
        'id' => $id,
        'createdAt' => $now,
        'updatedAt' => $now,
        'sales' => $sales,
        'salesName' => sales_label($sales),
        'orderType' => $orderType,
        'orderTypeLabel' => $isPreorder ? '預購訂單' : '正常訂單',
        'preorderWarehouse' => $isPreorder ? $preorderWarehouse : '',
        'preorderWarehouseLabel' => $isPreorder ? $preorderWarehouseLabel : '',
        // 預購打單的目的地同時是預設實際出貨倉；後續只有人工改選才覆寫。
        'preorderFulfillWarehouse' => $isPreorder ? $preorderWarehouse : '',
        'preorderFulfillWarehouseLabel' => $isPreorder ? $preorderWarehouseLabel : '',
        'trackingNo' => trim((string)($payload['trackingNo'] ?? '')),
        'liveSource' => trim((string)($payload['liveSource'] ?? '')),
        'liveSession' => trim((string)($payload['liveSession'] ?? '')),
        'orderDate' => trim((string)($payload['orderDate'] ?? '')),
        'shipMode' => trim((string)($payload['shipMode'] ?? '')),
        'shipModeLabel' => trim((string)($payload['shipModeLabel'] ?? '')),
        'warehouse' => trim((string)($payload['warehouse'] ?? '')),
        'warehouseCode' => strtoupper(trim((string)($payload['warehouseCode'] ?? ''))),
        // Store the arrival target at creation time. Receipt uses this target unless
        // a manager deliberately changes it during the arrival workflow.
        'arrivalWarehouse' => $isPreorder ? $preorderWarehouse : '',
        'arrivalWarehouseLabel' => $isPreorder ? $preorderWarehouseLabel : '',
        'customerOrderOnly' => $customerOrderOnly,
        'salesPurchaseOnly' => $salesPurchaseOnly,
        'blacklistHit' => !empty($blacklistGate['hit']),
        'blacklistMatchType' => (string)(($blacklistGate['hit']['matchType'] ?? '')),
        'blacklistReason' => (string)(($blacklistGate['hit']['reason'] ?? '')),
        'blacklistConfirmedAt' => !empty($blacklistGate['hit']) ? $now : '',
        'blacklistAskSales' => !empty($blacklistGate['hit']),
        'blacklistConfirmedBy' => !empty($blacklistGate['hit']) ? trim((string)($payload['employeeName'] ?? $sales ?? '')) : '',
        'waitNotify' => false,
        'waitNotifyNote' => '',
        'waitNotifySource' => '',
        'freightDispatchDecision' => '',
        'freightDispatchDecisionLabel' => '',
        'purchaseStatus' => $isPreorder ? ($saveAsDraft ? 'draft' : 'not_ordered') : '',
        'purchaseStatusLabel' => $isPreorder ? ($saveAsDraft ? '業務草稿／尚未送採購' : '待管理者採購') : '',
        'platformPublishAllowed' => $isPreorder ? !$customerOrderOnly : true,
        'status' => $isPreorder ? ($saveAsDraft ? 'preorder_draft' : 'preorder_pending') : 'pending_approval',
        'statusLabel' => $isPreorder
            ? ($saveAsDraft ? '預購草稿已儲存' : '預購訂單已建立／尚未扣現貨')
            : '詢問單等待審核',
        'savedAsDraft' => $saveAsDraft,
        // New preorder sheets always begin in the waiting-delivery lane.
        // Physical stock similarity must not silently push a newly keyed
        // preorder into shipping preparation before an operator releases it.
        'preorderQueueStage' => $isPreorder ? 'waiting_delivery' : '',
        'preorderWaitStatus' => $isPreorder ? 'waiting_other_items' : '',
        'preorderWaitStatusLabel' => $isPreorder ? '等待配送／等待到貨' : '',
        'freightShippingSelected' => false,
        'customer' => [
            'name' => $name,
            'phone' => $phone,
            'customerRecordId' => (string)($customer['customerRecordId'] ?? ''),
            'customerId' => (string)($customer['customerId'] ?? $customer['customerRecordId'] ?? ''),
            'customerRef' => (string)($customer['customerRef'] ?? $customer['customerRecordId'] ?? ''),
            'customerCode' => (string)($customer['customerCode'] ?? ''),
            'customerRefType' => (string)($customer['customerRefType'] ?? 'customer'),
            'customerProfileOnly' => member_photo_sync_truthy($customer['customerProfileOnly'] ?? false),
            'profileOnly' => member_photo_sync_truthy($customer['customerProfileOnly'] ?? false),
            'note' => trim((string)($customer['note'] ?? '')),
            'channel' => trim((string)($customer['channel'] ?? '')),
            'phoneLast6' => digits_only_value((string)($customer['phoneLast6'] ?? '')),
            'image' => $customer['image'] ?? ($customer['screenshot'] ?? ''),
            'screenshot' => $customer['screenshot'] ?? ($customer['image'] ?? ''),
            'paymentProofImage' => $customer['paymentProofImage'] ?? ($paymentSummary['proofImage'] ?? ''),
            'member' => $matchedCustomer ?: null,
            'address' => trim((string)(
                ($customer['address'] ?? '') !== '' ? $customer['address'] :
                (($customer['deliveryAddress'] ?? '') !== '' ? $customer['deliveryAddress'] :
                (($customer['storeAddress'] ?? '') !== '' ? $customer['storeAddress'] :
                (($matchedCustomer['address'] ?? '') !== '' ? $matchedCustomer['address'] : '')))
            )),
            'storeAddress' => trim((string)($customer['storeAddress'] ?? ($matchedCustomer['storeAddress'] ?? ''))),
            'homeAddress' => trim((string)($customer['homeAddress'] ?? ($matchedCustomer['homeAddress'] ?? ''))),
            'addressPrimary' => member_photo_sync_address_primary(
                $customer['addressPrimary'] ?? $customer['deliveryType'] ?? $customer['deliveryLabel'] ?? ($matchedCustomer['addressPrimary'] ?? '')
            ),
            'deliveryType' => trim((string)($customer['deliveryType'] ?? ($matchedCustomer['deliveryType'] ?? ''))),
            'deliveryLabel' => trim((string)($customer['deliveryLabel'] ?? ($matchedCustomer['deliveryLabel'] ?? ''))),
            'shippingFee' => $shippingFee,
            'regularShippingFee' => $regularShippingFee,
            'freeShipping' => $freeShipping,
            'freeShippingThreshold' => 1200,
            'freeShippingBusinessRate' => $freeShipping ? 0.3 : 0,
            'freeShippingCompanyRate' => $freeShipping ? 0.7 : 0,
            'customerTypeLabel' => trim((string)($customer['customerTypeLabel'] ?? ($matchedCustomer['customerTypeLabel'] ?? ''))),
            'sales' => $sales,
            'salesName' => sales_label($sales),
            'salesKey' => $sales,
        ],
        'items' => $cleanItems,
        'subtotal' => $subtotal,
        'shippingFee' => $shippingFee,
        'regularShippingFee' => $regularShippingFee,
        'freeShipping' => $freeShipping,
        'freeShippingThreshold' => 1200,
        'freeShippingBusinessRate' => $freeShipping ? 0.3 : 0,
        'freeShippingCompanyRate' => $freeShipping ? 0.7 : 0,
        'total' => $orderTotal,
        'paymentSummary' => $paymentSummary,
        'reserved' => !$isPreorder,
        'inventoryDeducted' => !$isPreorder,
        'inventoryDeductedAt' => $isPreorder ? '' : $now,
        'source' => 'stock-inquiry',
    ];
    $rows = read_json($inquiriesFile);
    array_unshift($rows, $row);
    write_json($inquiriesFile, $rows);
    if (!$isPreorder) {
        adjust_stock($items, -1, $skusFile, $stateFile, true);
    }
    $contactSync = sync_customer_contact_to_members($row['customer'], $customersFile, $membersFile, $stateFile, $now);
    $memberPhotoSync = ['ok' => true, 'synced' => false, 'reason' => 'not-run'];
    try {
        $memberPhotoSync = sync_customer_member_photo($row['customer'], __DIR__, 'inquiry-' . $orderType);
    } catch (Throwable $error) {
        $memberPhotoSync = ['ok' => false, 'synced' => false, 'error' => $error->getMessage()];
    }
    respond(['ok' => true, 'inquiry' => $row, 'contactSync' => $contactSync, 'memberPhotoSync' => $memberPhotoSync]);
}

if ($action === 'set-wait-notify') {
    $purchaseOperator = require_purchase_operator($adminSessionsFile, $stateFile, [
        '物流集運', '採購區', '採購進貨建檔', '訂單管理', '訂單與行政出貨', '現貨訂單', '現貨打單', '預購訂單', '預購打單', '直播訂單', '直播打單',
    ]);
    $inquiryId = receipt_text($payload['inquiryId'] ?? '');
    $orderId = receipt_text($payload['orderId'] ?? '');
    if ($inquiryId === '' && $orderId === '') respond(['ok' => false, 'error' => '缺少預購單或正式訂單編號'], 400);
    $waitNotify = filter_var($payload['waitNotify'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $source = strtolower(receipt_text($payload['source'] ?? 'admin'));
    if (!in_array($source, ['admin', 'sales_hold', 'ask_sales'], true)) $source = 'admin';
    $noteProvided = array_key_exists('note', $payload);
    $noteOnly = filter_var($payload['noteOnly'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $note = receipt_text($payload['note'] ?? '');
    if (function_exists('mb_substr')) $note = mb_substr($note, 0, 1000);
    else $note = substr($note, 0, 1000);
    $operatorName = receipt_first_text([
        $purchaseOperator['name'] ?? '',
        $purchaseOperator['account'] ?? '',
        $payload['employeeName'] ?? '',
        '管理者',
    ]);
    $now = date(DATE_ATOM);
    $inquiryFileLock = fopen($inquiriesFile . '.lock', 'c+');
    if ($inquiryFileLock === false || !flock($inquiryFileLock, LOCK_EX)) {
        respond(['ok' => false, 'error' => '目前無法鎖定客戶預購資料，請稍後再試'], 503);
    }
    $orderFileLock = fopen($ordersFile . '.lock', 'c+');
    if ($orderFileLock === false || !flock($orderFileLock, LOCK_EX)) {
        flock($inquiryFileLock, LOCK_UN); fclose($inquiryFileLock);
        respond(['ok' => false, 'error' => '目前無法鎖定正式訂單資料，請稍後再試'], 503);
    }
    try {
        $inquiries = read_json($inquiriesFile);
        $orders = read_json($ordersFile);
        $inquiryIndex = find_inquiry_index_for_wait_notify($inquiries, $inquiryId, $orderId);
        $orderIndex = find_order_index_for_wait_notify($orders, $orderId, $inquiryId);
        if ($inquiryIndex < 0 && $inquiryId !== '') {
            $orderIndexIfMislabelled = find_order_index_for_wait_notify($orders, $inquiryId, '');
            if ($orderIndexIfMislabelled >= 0) {
                $orderIndex = $orderIndexIfMislabelled;
                $orderId = receipt_text($orders[$orderIndex]['id'] ?? $inquiryId);
                $inquiryId = receipt_first_text([
                    $orders[$orderIndex]['sourceInquiryId'] ?? '',
                    $orders[$orderIndex]['convertedFromInquiryId'] ?? '',
                    $orders[$orderIndex]['inquiryId'] ?? '',
                ]);
                $inquiryIndex = find_inquiry_index_for_wait_notify($inquiries, $inquiryId, $orderId);
            }
        }
        if ($inquiryIndex < 0 && $orderIndex < 0) {
            respond(['ok' => false, 'error' => '找不到這張等待通知的訂單'], 404);
        }
        if ($inquiryIndex >= 0) {
            $inquiryStatus = strtolower(receipt_text($inquiries[$inquiryIndex]['status'] ?? ''));
            if ($inquiryStatus === 'cancelled') {
                respond(['ok' => false, 'error' => '這張已取消，不能再設等待通知'], 409);
            }
            apply_wait_notify_fields($inquiries[$inquiryIndex], $waitNotify, $operatorName, $now, $note, $source, $noteProvided, $noteOnly);
            if ($orderId === '') {
                $orderId = receipt_first_text([
                    $inquiries[$inquiryIndex]['convertedOrderId'] ?? '',
                    $inquiries[$inquiryIndex]['orderId'] ?? '',
                    $inquiries[$inquiryIndex]['formalOrderId'] ?? '',
                ]);
            }
            if ($inquiryId === '') $inquiryId = receipt_text($inquiries[$inquiryIndex]['id'] ?? '');
            if ($orderIndex < 0 && $orderId !== '') {
                $orderIndex = find_order_index_for_wait_notify($orders, $orderId, $inquiryId);
            }
        }
        if ($orderIndex >= 0) {
            $orderStatus = strtolower(receipt_text($orders[$orderIndex]['status'] ?? ''));
            $delivery = strtolower(receipt_text($orders[$orderIndex]['deliveryState'] ?? ''));
            if (in_array($orderStatus, ['cancelled', 'returned', 'delivered', 'completed'], true)
                || in_array($delivery, ['returned', 'delivered', 'completed'], true)) {
                respond(['ok' => false, 'error' => '這張已完成或取消，不能再設等待通知'], 409);
            }
            if (in_array($orderStatus, ['shipped', 'in_transit'], true) || $delivery === 'in_transit') {
                respond(['ok' => false, 'error' => '這張已在配送中，不能改成等待通知'], 409);
            }
            apply_wait_notify_fields($orders[$orderIndex], $waitNotify, $operatorName, $now, $note, $source, $noteProvided, $noteOnly);
            if ($inquiryId === '') {
                $inquiryId = receipt_first_text([
                    $orders[$orderIndex]['sourceInquiryId'] ?? '',
                    $orders[$orderIndex]['convertedFromInquiryId'] ?? '',
                    $orders[$orderIndex]['inquiryId'] ?? '',
                ]);
            }
            if ($orderId === '') $orderId = receipt_text($orders[$orderIndex]['id'] ?? '');
        }
        if ($inquiryIndex >= 0) write_json($inquiriesFile, array_values($inquiries));
        if ($orderIndex >= 0) write_json($ordersFile, array_values($orders));
        if (!$waitNotify) {
            resolve_wait_notify_hold_reports($orderIssuesFile, array_filter([$orderId]), $operatorName, $now);
        }
        respond([
            'ok' => true,
            'inquiryId' => $inquiryId,
            'orderId' => $orderId,
            'waitNotify' => $waitNotify,
            'inquiry' => $inquiryIndex >= 0 ? $inquiries[$inquiryIndex] : null,
            'order' => $orderIndex >= 0 ? $orders[$orderIndex] : null,
            'stockQuantityChanged' => false,
            'message' => $noteOnly
                ? '已儲存等待通知內容'
                : ($waitNotify
                ? '已標記等待通知；庫存仍保留，先不出貨'
                : '業務已通知，可以回到出貨工作列'),
        ]);
    } finally {
        flock($orderFileLock, LOCK_UN); fclose($orderFileLock);
        flock($inquiryFileLock, LOCK_UN); fclose($inquiryFileLock);
    }
}

if ($action === 'sales-confirm-can-ship') {
    $inquiryId = receipt_text($payload['inquiryId'] ?? '');
    $orderId = receipt_text($payload['orderId'] ?? '');
    if ($inquiryId === '' && $orderId === '') respond(['ok' => false, 'error' => '缺少預購單或正式訂單編號'], 400);
    $inquiryFileLock = fopen($inquiriesFile . '.lock', 'c+');
    if ($inquiryFileLock === false || !flock($inquiryFileLock, LOCK_EX)) {
        respond(['ok' => false, 'error' => '目前無法鎖定客戶預購資料，請稍後再試'], 503);
    }
    $orderFileLock = fopen($ordersFile . '.lock', 'c+');
    if ($orderFileLock === false || !flock($orderFileLock, LOCK_EX)) {
        flock($inquiryFileLock, LOCK_UN); fclose($inquiryFileLock);
        respond(['ok' => false, 'error' => '目前無法鎖定正式訂單資料，請稍後再試'], 503);
    }
    try {
        $inquiries = read_json($inquiriesFile);
        $orders = read_json($ordersFile);
        $inquiryIndex = find_inquiry_index_for_wait_notify($inquiries, $inquiryId, $orderId);
        $orderIndex = find_order_index_for_wait_notify($orders, $orderId, $inquiryId);
        if ($inquiryIndex < 0 && $inquiryId !== '') {
            $orderIndexIfMislabelled = find_order_index_for_wait_notify($orders, $inquiryId, '');
            if ($orderIndexIfMislabelled >= 0) {
                $orderIndex = $orderIndexIfMislabelled;
                $orderId = receipt_text($orders[$orderIndex]['id'] ?? $inquiryId);
                $inquiryId = receipt_first_text([
                    $orders[$orderIndex]['sourceInquiryId'] ?? '',
                    $orders[$orderIndex]['convertedFromInquiryId'] ?? '',
                    $orders[$orderIndex]['inquiryId'] ?? '',
                ]);
                $inquiryIndex = find_inquiry_index_for_wait_notify($inquiries, $inquiryId, $orderId);
            }
        }
        if ($inquiryIndex < 0 && $orderIndex < 0) {
            respond(['ok' => false, 'error' => '找不到這張要確認出貨的客戶'], 404);
        }
        $authRow = $inquiryIndex >= 0 ? $inquiries[$inquiryIndex] : $orders[$orderIndex];
        $trustedLogin = require_sales_fifo_confirm_login($payload, $authRow, $salesCustomersFile, $stateFile, $salesSessionsFile);
        $operatorName = receipt_first_text([
            $trustedLogin['name'] ?? '',
            $trustedLogin['account'] ?? '',
            $payload['releasedBy'] ?? '',
            '業務',
        ]);
        $now = date(DATE_ATOM);
        $note = '業務確定可以出貨';
        if ($inquiryIndex >= 0) {
            $inquiryStatus = strtolower(receipt_text($inquiries[$inquiryIndex]['status'] ?? ''));
            if ($inquiryStatus === 'cancelled') {
                respond(['ok' => false, 'error' => '這張已取消，不能再確認出貨'], 409);
            }
            if (row_is_wait_notify_hold($inquiries[$inquiryIndex])) {
                apply_wait_notify_fields($inquiries[$inquiryIndex], false, $operatorName, $now, $note, 'sales_confirm', true, false);
            }
            apply_sales_fifo_confirm_fields($inquiries[$inquiryIndex], $operatorName, $now);
            if ($orderId === '') {
                $orderId = receipt_first_text([
                    $inquiries[$inquiryIndex]['convertedOrderId'] ?? '',
                    $inquiries[$inquiryIndex]['orderId'] ?? '',
                    $inquiries[$inquiryIndex]['formalOrderId'] ?? '',
                ]);
            }
            if ($inquiryId === '') $inquiryId = receipt_text($inquiries[$inquiryIndex]['id'] ?? '');
            if ($orderIndex < 0 && $orderId !== '') {
                $orderIndex = find_order_index_for_wait_notify($orders, $orderId, $inquiryId);
            }
        }
        if ($orderIndex >= 0) {
            $orderStatus = strtolower(receipt_text($orders[$orderIndex]['status'] ?? ''));
            $delivery = strtolower(receipt_text($orders[$orderIndex]['deliveryState'] ?? ''));
            if (in_array($orderStatus, ['cancelled', 'returned', 'delivered', 'completed'], true)
                || in_array($delivery, ['returned', 'delivered', 'completed'], true)) {
                respond(['ok' => false, 'error' => '這張已完成或取消，不能再確認出貨'], 409);
            }
            if (in_array($orderStatus, ['shipped', 'in_transit'], true) || $delivery === 'in_transit') {
                respond(['ok' => false, 'error' => '這張已在配送中，不用再按可以出貨'], 409);
            }
            if (row_is_wait_notify_hold($orders[$orderIndex])) {
                apply_wait_notify_fields($orders[$orderIndex], false, $operatorName, $now, $note, 'sales_confirm', true, false);
            }
            apply_sales_fifo_confirm_fields($orders[$orderIndex], $operatorName, $now);
            if ($inquiryId === '') {
                $inquiryId = receipt_first_text([
                    $orders[$orderIndex]['sourceInquiryId'] ?? '',
                    $orders[$orderIndex]['convertedFromInquiryId'] ?? '',
                    $orders[$orderIndex]['inquiryId'] ?? '',
                ]);
            }
            if ($orderId === '') $orderId = receipt_text($orders[$orderIndex]['id'] ?? '');
        }
        if ($inquiryIndex >= 0) write_json($inquiriesFile, array_values($inquiries));
        if ($orderIndex >= 0) write_json($ordersFile, array_values($orders));
        resolve_wait_notify_hold_reports($orderIssuesFile, array_filter([$orderId]), $operatorName, $now);
        respond([
            'ok' => true,
            'inquiryId' => $inquiryId,
            'orderId' => $orderId,
            'waitNotify' => false,
            'autoSelected' => false,
            'inquiry' => $inquiryIndex >= 0 ? $inquiries[$inquiryIndex] : null,
            'order' => $orderIndex >= 0 ? $orders[$orderIndex] : null,
            'message' => '行政出貨工作列已收到，會逐一處理，不會自動加入本次配送',
        ]);
    } finally {
        flock($orderFileLock, LOCK_UN); fclose($orderFileLock);
        flock($inquiryFileLock, LOCK_UN); fclose($inquiryFileLock);
    }
}

if ($action === 'freight-customer-queue-decision') {
    $purchaseOperator = require_purchase_operator($adminSessionsFile, $stateFile, [
        '物流集運', '採購區', '採購進貨建檔', '訂單管理', '訂單與行政出貨', '現貨訂單', '現貨打單', '預購訂單', '預購打單', '直播訂單', '直播打單',
    ]);
    $inquiryId = receipt_text($payload['inquiryId'] ?? '');
    $decision = strtolower(receipt_text($payload['decision'] ?? ''));
    if ($inquiryId === '') respond(['ok' => false, 'error' => '缺少客戶預購單號'], 400);
    if (!in_array($decision, ['active', 'deferred', 'cancelled'], true)) {
        respond(['ok' => false, 'error' => '客戶配貨決定不正確'], 400);
    }
    $operatorName = receipt_first_text([
        $purchaseOperator['name'] ?? '',
        $purchaseOperator['account'] ?? '',
        $payload['employeeName'] ?? '',
        '管理者',
    ]);
    $now = date(DATE_ATOM);
    $freightFileLock = acquire_freight_file_lock($freightTrackingFile);
    $inquiryFileLock = fopen($inquiriesFile . '.lock', 'c+');
    if ($inquiryFileLock === false || !flock($inquiryFileLock, LOCK_EX)) {
        flock($freightFileLock, LOCK_UN); fclose($freightFileLock);
        respond(['ok' => false, 'error' => '目前無法鎖定客戶預購資料，請稍後再試'], 503);
    }
    try {
        $inquiries = read_json($inquiriesFile);
        $inquiryIndex = -1;
        foreach ($inquiries as $index => $inquiry) {
            if (is_array($inquiry) && receipt_text($inquiry['id'] ?? '') === $inquiryId) {
                $inquiryIndex = (int)$index;
                break;
            }
        }
        if ($inquiryIndex < 0) {
            respond(['ok' => false, 'error' => '找不到這位客戶的預購單。若已轉正式出貨，請到出貨核對取消'], 404);
        }

        $currentStatus = strtolower(receipt_text($inquiries[$inquiryIndex]['status'] ?? ''));
        if ($decision === 'cancelled' && $currentStatus === 'cancelled') {
            respond([
                'ok' => true,
                'inquiryId' => $inquiryId,
                'decision' => 'cancelled',
                'decisionLabel' => '客戶取消／釋放配貨',
                'alreadyCancelled' => true,
                'releasedQty' => 0,
                'releasedFreightItemIds' => [],
                'stockQuantityChanged' => false,
                'message' => '這張已經取消',
            ]);
        }
        if ($decision === 'cancelled') {
            $convertedOrderId = receipt_first_text([
                $inquiries[$inquiryIndex]['convertedOrderId'] ?? '',
                $inquiries[$inquiryIndex]['convertedToOrderId'] ?? '',
                $inquiries[$inquiryIndex]['formalOrderId'] ?? '',
                $currentStatus === 'converted' ? ($inquiries[$inquiryIndex]['orderId'] ?? '') : '',
            ]);
            if ($currentStatus === 'converted' || $convertedOrderId !== '') {
                respond([
                    'ok' => false,
                    'error' => '這張已轉正式出貨單，請到出貨核對取消',
                    'convertedOrderId' => $convertedOrderId,
                ], 409);
            }
        }

        $freightData = read_json($freightTrackingFile);
        $releasedQty = 0;
        $releasedFreightItemIds = [];
        if ($decision !== 'active') {
            foreach (is_array($freightData['items'] ?? null) ? $freightData['items'] : [] as $itemIndex => $freightItem) {
                if (!is_array($freightItem)) continue;
                $sourceLinks = is_array($freightItem['customerLinks'] ?? null)
                    ? array_values($freightItem['customerLinks'])
                    : (is_array($freightItem['inventoryLinkedOrders'] ?? null) ? array_values($freightItem['inventoryLinkedOrders']) : []);
                if (!$sourceLinks) continue;
                $keptLinks = [];
                $releasedLinks = [];
                foreach ($sourceLinks as $link) {
                    $linkedId = is_array($link) ? receipt_first_text([$link['inquiryId'] ?? '', $link['customerOrderNo'] ?? '']) : '';
                    if ($linkedId === $inquiryId) $releasedLinks[] = $link;
                    else $keptLinks[] = $link;
                }
                if (!$releasedLinks) continue;
                $itemReleasedQty = array_reduce($releasedLinks, static fn($sum, $link) => $sum + max(0, (int)($link['qty'] ?? 0)), 0);
                $releasedQty += $itemReleasedQty;
                $freightItemId = receipt_text($freightItem['id'] ?? '');
                if ($freightItemId !== '') $releasedFreightItemIds[] = $freightItemId;
                $actualQty = max(
                    0,
                    (int)($freightItem['receivingActualQty'] ?? 0),
                    (int)($freightItem['quantity'] ?? 0),
                    (int)($freightItem['customerAllocatedQty'] ?? 0) + (int)($freightItem['companyStockQty'] ?? 0)
                );
                $claim = is_array($freightItem['inventoryReceiptClaim'] ?? null) ? $freightItem['inventoryReceiptClaim'] : [];
                $actualQty = max($actualQty, (int)($claim['actualQty'] ?? $claim['receivedQty'] ?? 0));
                if (is_array($claim['lines'] ?? null) && $claim['lines']) {
                    $actualQty = array_reduce($claim['lines'], static fn($sum, $line) => $sum + max(0, (int)($line['qty'] ?? $line['quantity'] ?? 0)), 0);
                }
                $allocatedQty = min($actualQty, array_reduce($keptLinks, static fn($sum, $link) => $sum + max(0, (int)($link['qty'] ?? 0)), 0));
                $companyQty = max(0, $actualQty - $allocatedQty);
                $freightData['items'][$itemIndex]['customerLinks'] = array_values($keptLinks);
                $freightData['items'][$itemIndex]['inventoryLinkedOrders'] = array_values($keptLinks);
                $freightData['items'][$itemIndex]['customerAllocatedQty'] = $allocatedQty;
                $freightData['items'][$itemIndex]['purchaseOffsetQty'] = $allocatedQty;
                $freightData['items'][$itemIndex]['companyStockQty'] = $companyQty;
                $freightData['items'][$itemIndex]['companyStock'] = $companyQty > 0;
                $freightData['items'][$itemIndex]['inventoryOwnership'] = $allocatedQty > 0 ? ($companyQty > 0 ? 'mixed' : 'customer_order') : 'company_stock';
                $freightData['items'][$itemIndex]['updatedAt'] = $now;
                if (is_array($freightData['items'][$itemIndex]['inventoryReceiptClaim'] ?? null)) {
                    $freightData['items'][$itemIndex]['inventoryReceiptClaim']['linkedOrders'] = array_values($keptLinks);
                    $freightData['items'][$itemIndex]['inventoryReceiptClaim']['customerAllocatedQty'] = $allocatedQty;
                    $freightData['items'][$itemIndex]['inventoryReceiptClaim']['companyStockQty'] = $companyQty;
                    $freightData['items'][$itemIndex]['inventoryReceiptClaim']['updatedAt'] = $now;
                }
                $statusHistory = is_array($freightData['items'][$itemIndex]['statusHistory'] ?? null)
                    ? array_values($freightData['items'][$itemIndex]['statusHistory'])
                    : [];
                $statusHistory[] = [
                    'operationId' => 'customer-queue-' . $decision . ':' . hash('sha256', $inquiryId . '|' . $freightItemId . '|' . $now),
                    'status' => $decision === 'cancelled' ? '客戶取消／釋放配貨' : '客戶先等／跳過本批',
                    'at' => $now,
                    'by' => $operatorName,
                    'inquiryId' => $inquiryId,
                    'releasedQty' => $itemReleasedQty,
                    'note' => '只釋放客戶配貨歸屬；實體庫存總數不變，數量可依 FIFO 補給下一位。',
                ];
                $freightData['items'][$itemIndex]['statusHistory'] = array_slice($statusHistory, -300);

                foreach ($releasedLinks as $releasedLink) {
                    $orderItemIndex = array_key_exists('itemIndex', $releasedLink) && $releasedLink['itemIndex'] !== null
                        ? max(0, (int)$releasedLink['itemIndex'])
                        : -1;
                    $qty = max(0, (int)($releasedLink['qty'] ?? 0));
                    if ($orderItemIndex < 0 || !isset($inquiries[$inquiryIndex]['items'][$orderItemIndex]) || !is_array($inquiries[$inquiryIndex]['items'][$orderItemIndex])) continue;
                    $oldReceived = max(0, (int)($inquiries[$inquiryIndex]['items'][$orderItemIndex]['freightReceivedQty'] ?? 0));
                    $newReceived = max(0, $oldReceived - $qty);
                    $requested = max(1, (int)($inquiries[$inquiryIndex]['items'][$orderItemIndex]['requestedQty'] ?? $inquiries[$inquiryIndex]['items'][$orderItemIndex]['qty'] ?? 1));
                    $inquiries[$inquiryIndex]['items'][$orderItemIndex]['freightReceivedQty'] = $newReceived;
                    $inquiries[$inquiryIndex]['items'][$orderItemIndex]['freightArrivalStatus'] = $newReceived <= 0 ? 'waiting' : ($newReceived < $requested ? 'partial' : 'received');
                    $inquiries[$inquiryIndex]['items'][$orderItemIndex]['freightArrivalStatusLabel'] = $newReceived <= 0 ? '尚未到貨／等待重新配貨' : ($newReceived < $requested ? '部分到貨' : '已到貨');
                }
            }
        }

        $labels = [
            'active' => '恢復原始下單順位',
            'deferred' => '業務指定先等／跳過本批',
            'cancelled' => '客戶取消／釋放配貨',
        ];
        $inquiries[$inquiryIndex]['freightCustomerQueueDecision'] = $decision;
        $inquiries[$inquiryIndex]['freightCustomerQueueDecisionLabel'] = $labels[$decision];
        $inquiries[$inquiryIndex]['freightCustomerQueueDecisionAt'] = $now;
        $inquiries[$inquiryIndex]['freightCustomerQueueDecisionBy'] = $operatorName;
        $inquiries[$inquiryIndex]['freightShippingSelected'] = false;
        $inquiries[$inquiryIndex]['preorderWaitStatus'] = 'waiting_other_items';
        $inquiries[$inquiryIndex]['preorderWaitStatusLabel'] = '累計訂單／等待其他預購商品';
        $mergeGroupId = receipt_text($inquiries[$inquiryIndex]['plannedShipmentMergeGroupId'] ?? '');
        $inquiries[$inquiryIndex]['plannedShipmentMergeGroupId'] = '';
        $inquiries[$inquiryIndex]['plannedShipmentMasterInquiryId'] = '';
        $inquiries[$inquiryIndex]['plannedShipmentMergedInquiryIds'] = [];
        if ($decision === 'cancelled') {
            $inquiries[$inquiryIndex]['status'] = 'cancelled';
            $inquiries[$inquiryIndex]['statusLabel'] = '客戶取消';
            $inquiries[$inquiryIndex]['cancelledAt'] = $now;
            $inquiries[$inquiryIndex]['cancelledBy'] = $operatorName;
            $inquiries[$inquiryIndex]['manualQueuePriority'] = false;
        }
        if ($mergeGroupId !== '') {
            foreach ($inquiries as $otherIndex => $other) {
                if (!is_array($other) || (int)$otherIndex === $inquiryIndex) continue;
                if (receipt_text($other['plannedShipmentMergeGroupId'] ?? '') !== $mergeGroupId) continue;
                $ids = is_array($other['plannedShipmentMergedInquiryIds'] ?? null) ? array_values($other['plannedShipmentMergedInquiryIds']) : [];
                $ids = array_values(array_filter($ids, static function ($id) use ($inquiryId) {
                    return receipt_text($id) !== $inquiryId;
                }));
                if (count($ids) < 2) {
                    $inquiries[$otherIndex]['plannedShipmentMergeGroupId'] = '';
                    $inquiries[$otherIndex]['plannedShipmentMasterInquiryId'] = '';
                    $inquiries[$otherIndex]['plannedShipmentMergedInquiryIds'] = [];
                } else {
                    $inquiries[$otherIndex]['plannedShipmentMergedInquiryIds'] = $ids;
                }
            }
        }
        if ($decision === 'active') {
            $inquiries[$inquiryIndex]['freightCustomerQueueResumedAt'] = $now;
            $inquiries[$inquiryIndex]['freightCustomerQueueResumedBy'] = $operatorName;
        }
        $decisionHistory = is_array($inquiries[$inquiryIndex]['freightCustomerQueueHistory'] ?? null)
            ? array_values($inquiries[$inquiryIndex]['freightCustomerQueueHistory'])
            : [];
        $decisionHistory[] = [
            'decision' => $decision,
            'label' => $labels[$decision],
            'at' => $now,
            'by' => $operatorName,
            'releasedQty' => $releasedQty,
            'originalOrderAt' => receipt_first_text([$inquiries[$inquiryIndex]['createdAt'] ?? '', $inquiries[$inquiryIndex]['orderDate'] ?? '']),
        ];
        $inquiries[$inquiryIndex]['freightCustomerQueueHistory'] = array_slice($decisionHistory, -100);
        $inquiries[$inquiryIndex]['updatedAt'] = $now;

        if ($releasedQty > 0) {
            $freightData['revision'] = max(0, (int)($freightData['revision'] ?? 0)) + 1;
            $freightData['updatedAt'] = $now;
            write_json($freightTrackingFile, $freightData);
        }
        write_json($inquiriesFile, array_values($inquiries));
        respond([
            'ok' => true,
            'inquiryId' => $inquiryId,
            'decision' => $decision,
            'decisionLabel' => $labels[$decision],
            'releasedQty' => $releasedQty,
            'releasedFreightItemIds' => array_values(array_unique(array_filter($releasedFreightItemIds))),
            'stockQuantityChanged' => false,
            'message' => $decision === 'active'
                ? '已恢復原始下單順位；下一批符合商品到貨時會依原時間排序'
                : ('已釋放 ' . $releasedQty . ' 件，可依 FIFO 補給下一位客戶'),
        ]);
    } finally {
        flock($inquiryFileLock, LOCK_UN); fclose($inquiryFileLock);
        flock($freightFileLock, LOCK_UN); fclose($freightFileLock);
    }
}

if ($action === 'freight-received-customer-match-preview' || $action === 'freight-received-customer-match') {
    $purchaseOperator = require_purchase_operator($adminSessionsFile, $stateFile);
    $freightItemId = trim((string)($payload['freightItemId'] ?? ''));
    if ($freightItemId === '') respond(['ok' => false, 'error' => '缺少已入庫物流產品 freightItemId'], 400);
    $previewOnly = $action === 'freight-received-customer-match-preview';
    $manualCustomerAllocation = !empty($payload['manualCustomerAllocation']);
    $selectedInquiryId = receipt_text($payload['selectedInquiryId'] ?? '');

    $receiptsFileLock = acquire_purchase_receipts_file_lock($purchaseReceiptsFile);
    $freightFileLock = acquire_freight_file_lock($freightTrackingFile);
    $freightData = read_json($freightTrackingFile);
    $freightItemIndex = freight_product_receive_item_index($freightData, $freightItemId);
    if ($freightItemIndex < 0) {
        flock($freightFileLock, LOCK_UN); fclose($freightFileLock);
        flock($receiptsFileLock, LOCK_UN); fclose($receiptsFileLock);
        respond(['ok' => false, 'error' => '找不到這筆已入庫物流產品'], 404);
    }
    $freightItem = $freightData['items'][$freightItemIndex];
    if (strtolower(receipt_text($freightItem['inventoryStatus'] ?? '')) !== 'received' && receipt_text($freightItem['inventoryReceivedAt'] ?? '') === '') {
        flock($freightFileLock, LOCK_UN); fclose($freightFileLock);
        flock($receiptsFileLock, LOCK_UN); fclose($receiptsFileLock);
        respond(['ok' => false, 'error' => '這筆物流尚未正式入庫，請先完成驗收入庫'], 409);
    }
    $claim = is_array($freightItem['inventoryReceiptClaim'] ?? null) ? $freightItem['inventoryReceiptClaim'] : [];
    $cleanLines = [];
    foreach (is_array($claim['lines'] ?? null) ? array_values($claim['lines']) : [] as $lineIndex => $line) {
        if (!is_array($line)) continue;
        $qty = max(0, (int)($line['qty'] ?? $line['quantity'] ?? 0));
        if ($qty <= 0) continue;
        $cleanLines[] = [
            'skuId' => receipt_text($line['skuId'] ?? ''),
            'productId' => receipt_text($line['productId'] ?? ''),
            'productCode' => receipt_first_text([$line['productCode'] ?? '', $freightItem['productCode'] ?? '']),
            'barcode' => receipt_first_text([$line['barcode'] ?? '', $freightItem['productFiledBarcode'] ?? '', $freightItem['sampleBarcode'] ?? '']),
            'color' => receipt_first_text([$line['color'] ?? '', $freightItem['color'] ?? '']),
            'size' => receipt_first_text([$line['size'] ?? '', $freightItem['size'] ?? '', 'NO SIZE']),
            'qty' => $qty,
            'internalUseQty' => min($qty, max(0, (int)($line['internalUseQty'] ?? 0))),
            'sourceLineIndex' => max(0, (int)($line['sourceLineIndex'] ?? $lineIndex)),
        ];
    }
    if (!$cleanLines) {
        $sourceLines = [];
        foreach (['inventoryLines', 'receiptLines', 'variants', 'lines'] as $lineKey) {
            if (is_array($freightItem[$lineKey] ?? null) && $freightItem[$lineKey]) {
                $sourceLines = array_values($freightItem[$lineKey]);
                break;
            }
        }
        foreach ($sourceLines as $lineIndex => $line) {
            if (!is_array($line)) continue;
            $cleanLines[] = [
                'skuId' => receipt_text($line['skuId'] ?? ''),
                'productId' => receipt_text($line['productId'] ?? ''),
                'productCode' => receipt_first_text([$line['productCode'] ?? '', $freightItem['productCode'] ?? '']),
                'barcode' => receipt_first_text([$line['barcode'] ?? '', $line['sampleBarcode'] ?? '', $line['taiwanBarcode'] ?? '', $freightItem['productFiledBarcode'] ?? '']),
                'color' => receipt_first_text([$line['color'] ?? '', $freightItem['color'] ?? '']),
                'size' => receipt_first_text([$line['size'] ?? '', $freightItem['size'] ?? '', 'NO SIZE']),
                'qty' => max(0, (int)($line['qty'] ?? $line['quantity'] ?? 0)),
                'internalUseQty' => freight_line_internal_use_qty(is_array($line) ? $line : [], $freightItem),
                'sourceLineIndex' => max(0, (int)$lineIndex),
            ];
        }
    }
    $actualQty = array_reduce($cleanLines, static fn($sum, $line) => $sum + max(0, (int)($line['qty'] ?? 0)), 0);
    if ($actualQty <= 0) {
        flock($freightFileLock, LOCK_UN); fclose($freightFileLock);
        flock($receiptsFileLock, LOCK_UN); fclose($receiptsFileLock);
        respond(['ok' => false, 'error' => '這筆入庫紀錄沒有可配對的實收數量'], 409);
    }
    if (!empty($freightItem['customerAllocationDisabled']) && !$manualCustomerAllocation) {
        $disabledReason = receipt_text($freightItem['customerAllocationDisabledReason'] ?? '') ?: '此批指定只留公司庫存';
        flock($freightFileLock, LOCK_UN); fclose($freightFileLock);
        flock($receiptsFileLock, LOCK_UN); fclose($receiptsFileLock);
        respond([
            'ok' => true,
            'previewOnly' => $previewOnly,
            'freightItemId' => $freightItemId,
            'trackingNo' => receipt_text($freightItem['trackingNo'] ?? ''),
            'receivedQty' => $actualQty,
            'alreadyAllocatedQty' => 0,
            'companyStockQty' => $actualQty,
            'matchQty' => 0,
            'customers' => [],
            'customerAllocationDisabled' => true,
            'message' => $disabledReason . '；不會再次自動配給客戶',
        ]);
    }

    $existingLinks = freight_product_receive_linked_orders($freightItem);
    $existingAllocatedQty = array_reduce($existingLinks, static fn($sum, $link) => $sum + max(0, (int)($link['qty'] ?? 0)), 0);
    $savedCompanyQty = max(0, (int)($freightItem['companyStockQty'] ?? ($actualQty - $existingAllocatedQty)));
    $fifoExpansion = freight_receive_fifo_expand_links_safe($freightData, $freightItem, $cleanLines, read_json($inquiriesFile));
    $addedLinks = is_array($fifoExpansion['addedLinks'] ?? null) ? array_values($fifoExpansion['addedLinks']) : [];
    if ($selectedInquiryId !== '') {
        $addedLinks = array_values(array_filter($addedLinks, static function ($link) use ($selectedInquiryId): bool {
            return receipt_first_text([$link['inquiryId'] ?? '', $link['customerOrderNo'] ?? '']) === $selectedInquiryId;
        }));
    }
    $candidateAddedQty = array_reduce($addedLinks, static fn($sum, $link) => $sum + max(0, (int)($link['qty'] ?? 0)), 0);
    $addedQty = min($savedCompanyQty, $candidateAddedQty);
    if ($addedQty < (int)($fifoExpansion['addedQty'] ?? 0)) {
        $remaining = $addedQty;
        $capped = [];
        foreach ($addedLinks as $link) {
            if ($remaining <= 0) break;
            $take = min($remaining, max(0, (int)($link['qty'] ?? 0)));
            if ($take <= 0) continue;
            $link['qty'] = $take;
            $capped[] = $link;
            $remaining -= $take;
        }
        $addedLinks = $capped;
    }
    $customerRows = array_map(static function ($link): array {
        return [
            'inquiryId' => receipt_first_text([$link['inquiryId'] ?? '', $link['customerOrderNo'] ?? '']),
            'customerName' => receipt_text($link['customerName'] ?? ''),
            'orderDay' => receipt_text($link['orderDay'] ?? ''),
            'productCode' => receipt_text($link['productCode'] ?? ''),
            'color' => receipt_text($link['color'] ?? ''),
            'size' => receipt_first_text([$link['size'] ?? '', 'NO SIZE']),
            'qty' => max(0, (int)($link['qty'] ?? 0)),
        ];
    }, $addedLinks);

    if ($previewOnly || !$addedLinks) {
        flock($freightFileLock, LOCK_UN); fclose($freightFileLock);
        flock($receiptsFileLock, LOCK_UN); fclose($receiptsFileLock);
        respond([
            'ok' => true,
            'previewOnly' => $previewOnly,
            'freightItemId' => $freightItemId,
            'trackingNo' => receipt_text($freightItem['trackingNo'] ?? ''),
            'receivedQty' => $actualQty,
            'alreadyAllocatedQty' => $existingAllocatedQty,
            'companyStockQty' => $savedCompanyQty,
            'matchQty' => $addedQty,
            'customers' => $customerRows,
            'selectedInquiryId' => $selectedInquiryId,
            'message' => $addedLinks ? '' : '目前沒有產品編號、完整條碼、顏色與尺寸都相符的待配送客戶',
        ]);
    }

    $mergedLinks = freight_receive_merge_selected_links($existingLinks, $addedLinks);
    $allocatedQty = min($actualQty, array_reduce($mergedLinks, static fn($sum, $link) => $sum + max(0, (int)($link['qty'] ?? 0)), 0));
    $companyQty = max(0, $actualQty - $allocatedQty);
    $ownershipType = $allocatedQty > 0 ? ($companyQty > 0 ? 'mixed' : 'customer_order') : 'company_stock';
    $receivedAt = date(DATE_ATOM);
    $operationId = 'freight-post-receive-allocate:' . hash('sha256', mb_strtolower($freightItemId . '|' . implode('|', array_map(static fn($link) => freight_receive_customer_priority_key($link) . ':' . (string)($link['qty'] ?? 0), $addedLinks)), 'UTF-8'));
    $warehouse = freight_product_receive_warehouse_code(receipt_first_text([$freightItem['inventoryReceivedWarehouse'] ?? '', $claim['warehouse'] ?? '', $freightItem['destinationWarehouse'] ?? ''])) ?: 'TW';
    $receivingDocumentNo = receipt_first_text([$freightItem['receivingDocumentNo'] ?? '', $freightItem['productFiledInventoryReceiptNo'] ?? '']);
    $arrivalUpdate = freight_product_receive_mark_order_arrivals(
        $inquiriesFile,
        $addedLinks,
        $operationId,
        $receivedAt,
        $freightItemId,
        receipt_text($freightItem['trackingNo'] ?? ''),
        $warehouse,
        $cleanLines,
        $receivingDocumentNo,
        []
    );
    if (!empty($arrivalUpdate['warning'])) {
        flock($freightFileLock, LOCK_UN); fclose($freightFileLock);
        flock($receiptsFileLock, LOCK_UN); fclose($receiptsFileLock);
        respond(['ok' => false, 'error' => $arrivalUpdate['warning']], 503);
    }

    $freightData['items'][$freightItemIndex]['customerLinks'] = $mergedLinks;
    $freightData['items'][$freightItemIndex]['inventoryLinkedOrders'] = $mergedLinks;
    $freightData['items'][$freightItemIndex]['customerAllocatedQty'] = $allocatedQty;
    $freightData['items'][$freightItemIndex]['companyStockQty'] = $companyQty;
    $freightData['items'][$freightItemIndex]['companyStock'] = $companyQty > 0;
    $freightData['items'][$freightItemIndex]['inventoryOwnership'] = $ownershipType;
    if (receipt_text($freightData['items'][$freightItemIndex]['inventoryBusinessUnit'] ?? '') === 'baohui_computer') {
        $freightData['items'][$freightItemIndex]['baohuiTransferStatus'] = 'pending_transfer';
        $freightData['items'][$freightItemIndex]['baohuiTransferReadyAt'] = $receivedAt;
        $freightData['items'][$freightItemIndex]['baohuiTransferTarget'] = 'baohui_ecommerce';
    }
    $freightData['items'][$freightItemIndex]['purchaseOffsetQty'] = $allocatedQty;
    $freightData['items'][$freightItemIndex]['customerFulfillmentStatus'] = 'ready_to_fulfill';
    $freightData['items'][$freightItemIndex]['customerFulfillmentStatusLabel'] = '已依實收入庫數量配對客戶';
    $freightData['items'][$freightItemIndex]['customerFulfillmentReadyAt'] = $receivedAt;
    $freightData['items'][$freightItemIndex]['updatedAt'] = $receivedAt;
    $freightData['items'][$freightItemIndex]['inventoryReceiptClaim'] = array_merge($claim, [
        'ownership' => $ownershipType,
        'allocationMode' => 'post_receive_fifo',
        'linkedOrders' => $mergedLinks,
        'customerAllocatedQty' => $allocatedQty,
        'companyStockQty' => $companyQty,
        'updatedAt' => $receivedAt,
    ]);
    $history = is_array($freightData['items'][$freightItemIndex]['statusHistory'] ?? null) ? array_values($freightData['items'][$freightItemIndex]['statusHistory']) : [];
    $history[] = [
        'operationId' => $operationId,
        'status' => '已入庫／追加配對客戶',
        'at' => $receivedAt,
        'actualQty' => $actualQty,
        'newCustomerAllocatedQty' => $addedQty,
        'customerAllocatedQty' => $allocatedQty,
        'companyStockQty' => $companyQty,
        'note' => '未重複入庫；只依本物流實收入庫數量匹配最早客戶需求。',
    ];
    $freightData['items'][$freightItemIndex]['statusHistory'] = array_slice($history, -300);
    $freightData['revision'] = max(0, (int)($freightData['revision'] ?? 0)) + 1;
    $freightData['updatedAt'] = $receivedAt;

    $purchaseReceipts = read_json($purchaseReceiptsFile);
    foreach ($purchaseReceipts as $receiptIndex => $receipt) {
        if (!is_array($receipt)) continue;
        if (receipt_text($receipt['id'] ?? '') !== receipt_text($freightItem['receivingDocumentId'] ?? '')
            && receipt_text($receipt['freightItemId'] ?? '') !== $freightItemId) continue;
        $purchaseReceipts[$receiptIndex]['ownership'] = $ownershipType;
        $purchaseReceipts[$receiptIndex]['allocationMode'] = 'post_receive_fifo';
        $purchaseReceipts[$receiptIndex]['linkedOrders'] = $mergedLinks;
        $purchaseReceipts[$receiptIndex]['customerAllocatedQty'] = $allocatedQty;
        $purchaseReceipts[$receiptIndex]['companyStockQty'] = $companyQty;
        $purchaseReceipts[$receiptIndex]['updatedAt'] = $receivedAt;
        if (is_array($purchaseReceipts[$receiptIndex]['receivingDocument'] ?? null)) {
            $purchaseReceipts[$receiptIndex]['receivingDocument']['ownership'] = $ownershipType;
            $purchaseReceipts[$receiptIndex]['receivingDocument']['allocationMode'] = 'post_receive_fifo';
            $purchaseReceipts[$receiptIndex]['receivingDocument']['linkedOrders'] = $mergedLinks;
            $purchaseReceipts[$receiptIndex]['receivingDocument']['customerAllocatedQty'] = $allocatedQty;
            $purchaseReceipts[$receiptIndex]['receivingDocument']['companyStockQty'] = $companyQty;
            $purchaseReceipts[$receiptIndex]['receivingDocument']['note'] = '已入庫後依實收數量配對客戶；未重複增加庫存。';
        }
        foreach (is_array($purchaseReceipts[$receiptIndex]['receiptLines'] ?? null) ? $purchaseReceipts[$receiptIndex]['receiptLines'] : [] as $lineIndex => $receiptLine) {
            if (!is_array($receiptLine['operationContext']['receivingDocument'] ?? null)) continue;
            $purchaseReceipts[$receiptIndex]['receiptLines'][$lineIndex]['operationContext']['receivingDocument']['ownership'] = $ownershipType;
            $purchaseReceipts[$receiptIndex]['receiptLines'][$lineIndex]['operationContext']['receivingDocument']['allocationMode'] = 'post_receive_fifo';
            $purchaseReceipts[$receiptIndex]['receiptLines'][$lineIndex]['operationContext']['receivingDocument']['linkedOrders'] = $mergedLinks;
            $purchaseReceipts[$receiptIndex]['receiptLines'][$lineIndex]['operationContext']['receivingDocument']['customerAllocatedQty'] = $allocatedQty;
            $purchaseReceipts[$receiptIndex]['receiptLines'][$lineIndex]['operationContext']['receivingDocument']['companyStockQty'] = $companyQty;
        }
        break;
    }

    write_json($freightTrackingFile, $freightData);
    write_json($purchaseReceiptsFile, $purchaseReceipts);
    flock($freightFileLock, LOCK_UN); fclose($freightFileLock);
    flock($receiptsFileLock, LOCK_UN); fclose($receiptsFileLock);
    respond([
        'ok' => true,
        'freightItemId' => $freightItemId,
        'trackingNo' => receipt_text($freightItem['trackingNo'] ?? ''),
        'receivedQty' => $actualQty,
        'matchedQty' => $addedQty,
        'customerAllocatedQty' => $allocatedQty,
        'companyStockQty' => $companyQty,
        'customers' => $customerRows,
        'selectedInquiryId' => $selectedInquiryId,
        'arrivalUpdate' => $arrivalUpdate,
        'item' => $freightData['items'][$freightItemIndex],
        'stockQuantityChanged' => false,
    ]);
}

if ($action === 'freight-product-receive' || $action === 'freight-product-receive-preview') {
    $purchaseOperator = require_purchase_operator($adminSessionsFile, $stateFile);
    $freightItemId = trim((string)($payload['freightItemId'] ?? ''));
    if ($freightItemId === '') respond(['ok' => false, 'error' => '缺少物流產品明細 freightItemId'], 400);
    $warehouse = freight_product_receive_warehouse_code($payload['warehouseCode'] ?? $payload['arrivalWarehouse'] ?? '');
    $incomingLines = isset($payload['lines']) && is_array($payload['lines'])
        ? array_values($payload['lines'])
        : (isset($payload['receiptLines']) && is_array($payload['receiptLines']) ? array_values($payload['receiptLines']) : []);
    if (!$incomingLines) respond(['ok' => false, 'error' => '物流產品正式入庫至少需要一個 SKU 品項'], 400);
    $topProductId = freight_product_receive_formal_product_id($payload['productId'] ?? '');
    $topProductCode = trim((string)($payload['productCode'] ?? ''));
    $cleanLines = [];
    foreach ($incomingLines as $lineIndex => $line) {
        if (!is_array($line)) respond(['ok' => false, 'error' => '第 ' . ($lineIndex + 1) . ' 個入庫品項格式不正確'], 400);
        $rawQty = $line['qty'] ?? $line['quantity'] ?? null;
        if (!is_numeric($rawQty) || (float)$rawQty <= 0 || floor((float)$rawQty) !== (float)$rawQty) {
            respond(['ok' => false, 'error' => '第 ' . ($lineIndex + 1) . ' 個入庫品項數量必須是正整數'], 400);
        }
        $cleanLines[] = [
            'skuId' => receipt_first_text([$line['skuId'] ?? '', $line['sku'] ?? '']),
            'productId' => freight_product_receive_formal_product_id(receipt_first_text([$line['productId'] ?? '', $topProductId])),
            'productName' => trim((string)($line['productName'] ?? $payload['productName'] ?? '')),
            'category' => trim((string)($line['category'] ?? $payload['category'] ?? '')),
            'productCode' => receipt_first_text([$line['productCode'] ?? '', $topProductCode]),
            'barcode' => receipt_first_text([$line['barcode'] ?? '', $line['companyBarcode'] ?? '', $line['taiwanBarcode'] ?? '', $line['sampleBarcode'] ?? '']),
            'color' => trim((string)($line['color'] ?? $line['colorName'] ?? '')),
            'size' => trim((string)($line['size'] ?? $line['sizeName'] ?? 'NO SIZE')) ?: 'NO SIZE',
            'sourceLineIndex' => max(0, (int)($line['sourceLineIndex'] ?? $lineIndex)),
            'qty' => (int)$rawQty,
            'internalUseQty' => min((int)$rawQty, max(0, (int)($line['internalUseQty'] ?? 0))),
            'unitCostTwd' => max(0, round((float)($line['unitCostTwd'] ?? $line['currentCostTwd'] ?? $line['finalCostTwd'] ?? $line['cost'] ?? 0), 2)),
            'arrivalImage' => trim((string)($line['arrivalImage'] ?? '')),
            'proofRequired' => !empty($line['proofRequired']),
        ];
    }
    $formalProducts = read_json($productsFile);
    $formalSkus = read_json($skusFile);
    foreach ($cleanLines as $lineIndex => $line) {
        $formalProductId = receipt_text($line['productId'] ?? '');
        $formalSkuId = receipt_text($line['skuId'] ?? '');
        if ($formalProductId === '') {
            respond(['ok' => false, 'error' => '產品資料待確認，不能直接正式入庫：第 ' . ($lineIndex + 1) . ' 個品項缺少正式 productId；尚未產生任何入庫單'], 409);
        }
        $formalProductExists = false;
        foreach ($formalProducts as $formalProduct) {
            if (!is_array($formalProduct) || receipt_text($formalProduct['id'] ?? '') !== $formalProductId) continue;
            if (!empty($formalProduct['archived']) || (isset($formalProduct['active']) && !$formalProduct['active']) || strtolower(receipt_text($formalProduct['status'] ?? '')) === 'inactive') continue;
            $formalProductExists = true;
            break;
        }
        if (!$formalProductExists) {
            respond(['ok' => false, 'error' => '產品資料待確認，不能直接正式入庫：第 ' . ($lineIndex + 1) . ' 個 productId 不存在於正式產品表；尚未產生任何入庫單'], 409);
        }
        if ($formalSkuId === '') {
            respond(['ok' => false, 'error' => '產品資料待確認，不能直接正式入庫：第 ' . ($lineIndex + 1) . ' 個品項缺少正式 skuId；尚未產生任何入庫單'], 409);
        }
        $formalSkuExists = false;
        $matchedFormalSku = null;
        foreach ($formalSkus as $formalSku) {
            if (!is_array($formalSku)) continue;
            $candidateSkuId = receipt_first_text([$formalSku['id'] ?? '', $formalSku['sku'] ?? '']);
            if ($candidateSkuId !== $formalSkuId || receipt_text($formalSku['productId'] ?? '') !== $formalProductId) continue;
            if (!empty($formalSku['archived']) || (isset($formalSku['active']) && !$formalSku['active']) || strtolower(receipt_text($formalSku['status'] ?? '')) === 'inactive') continue;
            $formalSkuExists = true;
            $matchedFormalSku = $formalSku;
            break;
        }
        if (!$formalSkuExists) {
            respond(['ok' => false, 'error' => '產品資料待確認，不能直接正式入庫：第 ' . ($lineIndex + 1) . ' 個 skuId 不存在或不屬於此正式產品；尚未產生任何入庫單'], 409);
        }
        /*
         * 進貨標籤統一用新款 V2（產品+P成本+C色碼+S尺寸）。
         * 正式 skuId 仍決定顏色／尺寸；若前端已組成 V2 條碼就保留，
         * 不再被 SKU 上的舊款 companyBarcode 覆寫回去。
         */
        if (is_array($matchedFormalSku)) {
            $storedBarcode = receipt_first_text([
                $matchedFormalSku['officialBarcode'] ?? '',
                $matchedFormalSku['companyBarcode'] ?? '',
                $matchedFormalSku['barcode'] ?? '',
                $matchedFormalSku['legacyBarcode'] ?? '',
                $matchedFormalSku['mappingCode'] ?? '',
            ]);
            $incomingBarcode = receipt_text($cleanLines[$lineIndex]['barcode'] ?? '');
            $canonicalBarcode = (receipt_is_canonical_company_barcode($incomingBarcode) || receipt_is_v2_label_barcode($incomingBarcode)) ? $incomingBarcode : $storedBarcode;
            $canonicalColor = receipt_first_text([
                $matchedFormalSku['colorName'] ?? '',
                $matchedFormalSku['color'] ?? '',
            ]);
            $canonicalSize = receipt_first_text([
                $matchedFormalSku['sizeName'] ?? '',
                $matchedFormalSku['size'] ?? '',
                'NO SIZE',
            ]);
            if ($canonicalBarcode !== '') $cleanLines[$lineIndex]['barcode'] = $canonicalBarcode;
            if ($canonicalColor !== '') $cleanLines[$lineIndex]['color'] = $canonicalColor;
            $cleanLines[$lineIndex]['size'] = $canonicalSize !== '' ? $canonicalSize : 'NO SIZE';
        }
    }
    $hashLines = $cleanLines;
    usort($hashLines, static function ($left, $right): int {
        return strcmp(
            implode('|', [(string)($left['skuId'] ?? ''), (string)($left['productId'] ?? ''), (string)($left['productCode'] ?? ''), (string)($left['barcode'] ?? ''), (string)($left['color'] ?? ''), (string)($left['size'] ?? '')]),
            implode('|', [(string)($right['skuId'] ?? ''), (string)($right['productId'] ?? ''), (string)($right['productCode'] ?? ''), (string)($right['barcode'] ?? ''), (string)($right['color'] ?? ''), (string)($right['size'] ?? '')])
        );
    });
    $operationId = 'freight-product-receive:' . hash('sha256', mb_strtolower($freightItemId, 'UTF-8'));
    $receivedBy = trim((string)($purchaseOperator['name'] ?? $purchaseOperator['account'] ?? '')) ?: '管理者';

    // Preview is read-only and must not wait behind an unrelated receiving
    // write. The final receive action still takes both exclusive locks.
    $isReceivePreview = $action === 'freight-product-receive-preview';
    $receiptsFileLock = null;
    $freightFileLock = null;
    if ($isReceivePreview) {
        $purchaseReceipts = read_json($purchaseReceiptsFile);
        $freightData = read_json($freightTrackingFile);
    } else {
        // Match the standalone receipt lock order so a freight product and a
        // formal purchase receipt can never both claim the same item concurrently.
        $receiptsFileLock = acquire_purchase_receipts_file_lock($purchaseReceiptsFile);
        $purchaseReceipts = read_json($purchaseReceiptsFile);
        $freightFileLock = acquire_freight_file_lock($freightTrackingFile);
        $freightData = read_json($freightTrackingFile);
    }
    $freightItemIndex = freight_product_receive_item_index($freightData, $freightItemId);
    if ($freightItemIndex < 0) respond(['ok' => false, 'error' => '找不到指定的物流產品明細，未增加庫存'], 404);
    $freightItem = $freightData['items'][$freightItemIndex];
    $savedTrackingNo = receipt_text($freightItem['trackingNo'] ?? '');
    $requestedTrackingNo = trim((string)($payload['freightTrackingNo'] ?? ''));
    if ($requestedTrackingNo !== '' && ($savedTrackingNo === '' || !hash_equals($savedTrackingNo, $requestedTrackingNo))) {
        respond(['ok' => false, 'error' => '物流單號與已儲存的物流產品明細不一致'], 409);
    }
    $savedBatchId = receipt_text($freightItem['batchId'] ?? '');
    $requestedBatchId = trim((string)($payload['freightBatchId'] ?? ''));
    if ($requestedBatchId !== '' && ($savedBatchId === '' || !hash_equals($savedBatchId, $requestedBatchId))) {
        respond(['ok' => false, 'error' => '物流批號與已儲存的物流產品明細不一致'], 409);
    }
    $freightBatch = [];
    foreach (is_array($freightData['batches'] ?? null) ? $freightData['batches'] : [] as $candidateBatch) {
        if (is_array($candidateBatch) && receipt_text($candidateBatch['id'] ?? '') === $savedBatchId) {
            $freightBatch = $candidateBatch;
            break;
        }
    }
    $confirmPhysicalReceipt = !empty($payload['confirmPhysicalReceipt']);
    if (!freight_item_has_receiving_evidence($freightItem, $freightBatch) && !$confirmPhysicalReceipt) {
        respond([
            'ok' => false,
            'error' => '這筆物流產品尚未有足夠的到貨證據；請先將該品項標示中國／台灣／印尼收到，或完成整批點貨，再建立正式進貨單與入庫',
        ], 409);
    }
    $variantCollections = [];
    foreach (['inventoryLines', 'receiptLines', 'variants', 'lines'] as $variantKey) {
        if (isset($freightItem[$variantKey]) && is_array($freightItem[$variantKey]) && $freightItem[$variantKey]) {
            $variantCollections = array_values($freightItem[$variantKey]);
            break;
        }
    }
    $expectedQty = 0;
    if ($variantCollections) {
        foreach ($variantCollections as $variantRow) {
            if (!is_array($variantRow)) continue;
            $variantQty = $variantRow['quantity'] ?? $variantRow['qty'] ?? 0;
            if (is_numeric($variantQty) && (float)$variantQty > 0) $expectedQty += (int)round((float)$variantQty);
        }
    }
    if ($expectedQty <= 0) {
        $savedExpectedQty = $freightItem['quantity'] ?? $freightItem['qty'] ?? $payload['expectedQty'] ?? 0;
        if (is_numeric($savedExpectedQty) && (float)$savedExpectedQty > 0) $expectedQty = (int)round((float)$savedExpectedQty);
    }
    $actualQty = array_reduce($cleanLines, static fn($sum, $line) => $sum + max(0, (int)($line['qty'] ?? 0)), 0);
    $allocationMode = strtolower(trim((string)($payload['allocationMode'] ?? 'auto_mixed')));
    if (!in_array($allocationMode, ['auto_mixed', 'preorder_only', 'company_stock'], true)) {
        respond(['ok' => false, 'error' => '入庫分流只接受自動分流、全配預購或全轉現貨'], 400);
    }
    $stockPurpose = strtolower(trim((string)($payload['stockPurpose'] ?? '')));
    $stockPurposeExplicit = in_array($stockPurpose, ['sample', 'live_ready', 'internal_use'], true);
    if (!$stockPurposeExplicit) $stockPurpose = 'live_ready';
    $confirmReleaseCustomerReservations = !empty($payload['confirmReleaseCustomerReservations']);
    $variantRows = freight_variant_rows($freightItem);
    require_once __DIR__ . '/freight-receipt-cost.php';
    $receiptCostBatch = $freightBatch;
    if (!$receiptCostBatch && !empty($freightItem['sourceFreightBatchId'])) {
        foreach ((array)($freightData['batches'] ?? []) as $candidateBatch) {
            if (is_array($candidateBatch) && (string)($candidateBatch['id'] ?? '') === (string)$freightItem['sourceFreightBatchId']) { $receiptCostBatch = $candidateBatch; break; }
        }
    }
    $receiptEqualCost = !preg_match('/haohong/', (string)($receiptCostBatch['costMode'] ?? '')) && ($receiptCostBatch['provider'] ?? '') !== 'haohong';
    foreach ($cleanLines as $lineIndex => &$cleanLine) {
        $sourceIndex = (int)($cleanLine['sourceLineIndex'] ?? $lineIndex);
        $variant = is_array($variantRows[$sourceIndex] ?? null) ? $variantRows[$sourceIndex] : [];
        if ($receiptEqualCost) {
            try {
                $costBreakdown = freight_receipt_unit_cost($freightItem, $variant, $receiptCostBatch);
                $cleanLine['unitCostTwd'] = $costBreakdown['unitCostTwd'];
                $cleanLine['receiptCostBreakdown'] = $costBreakdown;
            } catch (RuntimeException $costError) {
                respond(['ok' => false, 'error' => $costError->getMessage()], 400);
            }
        }
        $lineQty = max(0, (int)($cleanLine['qty'] ?? 0));
        $internal = min($lineQty, max(0, (int)($cleanLine['internalUseQty'] ?? $variant['internalUseQty'] ?? $payload['lines'][$lineIndex]['internalUseQty'] ?? 0)));
        if (strtolower(trim((string)($freightItem['goodsPurpose'] ?? ''))) === 'internal_use' && $internal <= 0) $internal = $lineQty;
        $cleanLine['internalUseQty'] = $internal;
        $cleanLine['sellableQty'] = max(0, $lineQty - $internal);
    }
    unset($cleanLine);
    $internalUseQtyTotal = 0;
    $sellableQtyTotal = 0;
    foreach ($cleanLines as $qtyLine) {
        if (!is_array($qtyLine)) continue;
        $internalUseQtyTotal += max(0, (int)($qtyLine['internalUseQty'] ?? 0));
        $sellableQtyTotal += max(0, (int)($qtyLine['sellableQty'] ?? 0));
    }
    $isInternalUse = $internalUseQtyTotal > 0 && $sellableQtyTotal <= 0;
    $isMixedUse = $internalUseQtyTotal > 0 && $sellableQtyTotal > 0;
    if ($isInternalUse) {
        $allocationMode = 'company_stock';
        $stockPurpose = 'internal_use';
        $stockPurposeExplicit = true;
        $confirmReleaseCustomerReservations = true;
        $payload['goodsPurpose'] = 'internal_use';
        $freightData['items'][$freightItemIndex]['goodsPurpose'] = 'internal_use';
        $freightItem = $freightData['items'][$freightItemIndex];
    } elseif ($isMixedUse) {
        $payload['goodsPurpose'] = 'mixed';
        $freightData['items'][$freightItemIndex]['goodsPurpose'] = 'mixed';
        $freightItem = $freightData['items'][$freightItemIndex];
    }
    // Sellable company stock must satisfy waiting customers too. Only explicit
    // samples/internal-use goods may bypass customer allocation.
    if (!$isInternalUse && $stockPurpose !== 'sample') {
        $allocationMode = 'auto_mixed';
        $confirmReleaseCustomerReservations = false;
    }
    // Always discover exact preorder demand before deciding ownership.  The
    // direct-stock path used to skip this lookup entirely, so an item with no
    // pre-written customerLinks could silently become company stock even when
    // an exact barcode + color + size customer was waiting.  Company-stock is
    // only exception is explicit samples/internal-use, never sellable stock.
    $fifoExpansion = freight_receive_fifo_expand_links_safe($freightData, $freightItem, $cleanLines, read_json($inquiriesFile));
    $freightItem['customerLinks'] = $fifoExpansion['links'];
    try {
        $allocationPlan = freight_receive_allocate_linked_orders(
            $freightItem,
            $cleanLines,
            $allocationMode,
            $confirmReleaseCustomerReservations,
            trim((string)($payload['priorityCustomerKey'] ?? ''))
        );
    } catch (InvalidArgumentException $allocationError) {
        respond(['ok' => false, 'error' => $allocationError->getMessage()], 409);
    }
    $linkedOrders = $allocationPlan['links'];
    $allowedDispatchInquiryIds = [];
    foreach ($linkedOrders as $linkedOrder) {
        if (!is_array($linkedOrder)) continue;
        $linkedInquiryId = receipt_first_text([$linkedOrder['inquiryId'] ?? '', $linkedOrder['customerOrderNo'] ?? '']);
        if ($linkedInquiryId !== '') $allowedDispatchInquiryIds[$linkedInquiryId] = true;
    }
    $customerDispatchChoices = [];
    foreach (is_array($payload['customerDispatchChoices'] ?? null) ? $payload['customerDispatchChoices'] : [] as $choice) {
        if (!is_array($choice)) continue;
        $choiceInquiryId = receipt_text($choice['inquiryId'] ?? '');
        $choiceDecision = strtolower(receipt_text($choice['decision'] ?? ''));
        if (!isset($allowedDispatchInquiryIds[$choiceInquiryId]) || !in_array($choiceDecision, ['wait_all', 'ship_now', 'ask_sales'], true)) continue;
        $customerDispatchChoices[] = [
            'inquiryId' => $choiceInquiryId,
            'decision' => $choiceDecision,
            'customerName' => receipt_text($choice['customerName'] ?? ''),
            'otherItemCount' => max(0, (int)($choice['otherItemCount'] ?? 0)),
            'pendingOtherItemCount' => max(0, (int)($choice['pendingOtherItemCount'] ?? 0)),
        ];
    }
    $quantityDifference = $expectedQty > 0 ? $actualQty - $expectedQty : 0;
    $varianceReason = trim((string)($payload['receivingVarianceReason'] ?? $payload['quantityDifferenceReason'] ?? ''));
    $costBasisQty = max(0, (int)round((float)($payload['costBasisQty'] ?? 0)));
    $costBasisSource = trim((string)($payload['costBasisSource'] ?? ''));
    $costReferenceNo = trim((string)($payload['costReferenceNo'] ?? ''));
    $costTotalTwd = max(0, round((float)($payload['costTotalTwd'] ?? 0), 2));
    $costAverageTwd = $costBasisQty > 0 ? round($costTotalTwd / $costBasisQty, 2) : max(0, round((float)($payload['costAverageTwd'] ?? 0), 2));
    $costMode = trim((string)($payload['costMode'] ?? ''));
    if ($receiptEqualCost && isset($costBreakdown)) {
        $costBasisQty = (int)($costBreakdown['basisQty'] ?? 0);
        $costTotalTwd = max(0, (float)($receiptCostBatch['chargeTotalTwd'] ?? $receiptCostBatch['amount'] ?? 0));
        $costAverageTwd = (float)$costBreakdown['allocationTwd'];
        $costBasisSource = $receiptCostBatch ? '原批次件數平均分攤（伺服器核算）' : '無批次直接入庫，每件加 NT$30';
        $costReferenceNo = (string)($receiptCostBatch['id'] ?? '');
        $costMode = (string)($receiptCostBatch['costMode'] ?? 'direct-no-batch-30');
    }
    if ($costTotalTwd > 0 && $costBasisQty <= 0) {
        respond(['ok' => false, 'error' => '關稅／豪鴻批次已有成本金額，但缺少登記件數；未建立進貨單或增加庫存'], 400);
    }
    if ($action === 'freight-product-receive' && $expectedQty > 0 && $actualQty !== $expectedQty && $varianceReason === '') {
        respond([
            'ok' => false,
            'error' => '物流預計 ' . $expectedQty . ' 件，本次點貨 ' . $actualQty . ' 件；數量不同時必須填寫差異原因',
            'expectedQty' => $expectedQty,
            'actualQty' => $actualQty,
            'differenceQty' => $quantityDifference,
        ], 400);
    }
    $requestedDestination = receipt_first_text([
        $payload['destinationWarehouse'] ?? '',
        $payload['arrivalWarehouse'] ?? '',
        $payload['warehouseCode'] ?? '',
    ]);
    $confirmDestinationOverride = !empty($payload['confirmDestinationOverride']);
    $inferredDestinationWarehouse = '';
    if ($confirmDestinationOverride && freight_product_receive_warehouse_code($requestedDestination) !== '') {
        $inferredDestinationWarehouse = $requestedDestination;
    } else {
        $inferredDestinationWarehouse = freight_destination_from_receiving_status($freightItem, $freightBatch);
    }
    if ($inferredDestinationWarehouse === '' && $confirmPhysicalReceipt) {
        if (freight_product_receive_warehouse_code($requestedDestination) !== '') $inferredDestinationWarehouse = $requestedDestination;
    }
    if ($inferredDestinationWarehouse === '') {
        $savedDestinationWarehouse = receipt_first_text([
            $freightItem['destinationWarehouse'] ?? '',
            $freightBatch['destinationWarehouse'] ?? '',
        ]);
        if (freight_product_receive_warehouse_code($savedDestinationWarehouse) !== '') {
            $inferredDestinationWarehouse = $savedDestinationWarehouse;
        }
    }
    if ($inferredDestinationWarehouse !== '') {
        $warehouse = freight_product_receive_warehouse_code($inferredDestinationWarehouse);
        $freightData['items'][$freightItemIndex]['destinationWarehouse'] = $inferredDestinationWarehouse;
        $freightData['items'][$freightItemIndex]['destinationSite'] = freight_destination_site($inferredDestinationWarehouse);
        $freightData['items'][$freightItemIndex]['handlingPerItemTwd'] = freight_destination_handling($inferredDestinationWarehouse);
        $freightItem = $freightData['items'][$freightItemIndex];
    }
    if ($warehouse === '') respond(['ok' => false, 'error' => '入庫倉別只接受 TW、CN 或 ID；也可先把物流進度改為中國收貨或台灣收貨，系統會自動判定'], 400);
    $effectiveDestinationWarehouse = $inferredDestinationWarehouse !== ''
        ? $inferredDestinationWarehouse
        : ($warehouse === 'CN' ? 'CN_DONGGUAN' : ($warehouse === 'TW' ? 'TW_BAOHUI' : 'ID_DIRECT'));
    if ($action === 'freight-product-receive-preview') {
        if (is_resource($freightFileLock)) {
            flock($freightFileLock, LOCK_UN);
            fclose($freightFileLock);
        }
        if (is_resource($receiptsFileLock)) {
            flock($receiptsFileLock, LOCK_UN);
            fclose($receiptsFileLock);
        }
        $previewOwnershipType = $isInternalUse
            ? 'internal_use'
            : ($allocationPlan['allocatedQty'] > 0
                ? ($allocationPlan['companyQty'] > 0 ? 'mixed' : 'customer_order')
                : 'company_stock');
        respond([
            'ok' => true,
            'previewOnly' => true,
            'freightItemId' => $freightItemId,
            'goodsPurpose' => $isInternalUse ? 'internal_use' : ($isMixedUse ? 'mixed' : receipt_text($payload['goodsPurpose'] ?? '')),
            'inventory' => ['warehouse' => $warehouse, 'destinationWarehouse' => $effectiveDestinationWarehouse, 'totalQty' => $actualQty, 'lines' => $cleanLines],
            'quantityCheck' => ['expectedQty' => $expectedQty, 'actualQty' => $actualQty, 'differenceQty' => $quantityDifference, 'reason' => $varianceReason],
            'ownership' => [
                'type' => $previewOwnershipType,
                'allocationMode' => $allocationMode,
                'linkedOrders' => $linkedOrders,
                'customerAllocatedQty' => $isInternalUse ? 0 : $allocationPlan['allocatedQty'],
                'companyStockQty' => $isInternalUse ? 0 : $allocationPlan['companyQty'],
                'internalUseQty' => $internalUseQtyTotal,
                'exactDemandQty' => $allocationPlan['demandQty'],
                'releasedCustomerQty' => array_reduce($allocationPlan['releasedLinks'] ?? [], static fn($sum, $link) => $sum + max(0, (int)($link['qty'] ?? 0)), 0),
                'fifoSupplementedQty' => max(0, (int)($fifoExpansion['addedQty'] ?? 0)),
            ],
        ]);
    }
    $operationPayloadForHash = [
        'freightItemId' => $freightItemId,
        'freightTrackingNo' => $savedTrackingNo,
        'freightBatchId' => $savedBatchId,
        'warehouse' => $warehouse,
        'destinationWarehouse' => $effectiveDestinationWarehouse,
        'productId' => $topProductId,
        'productCode' => $topProductCode,
        'productMode' => trim((string)($payload['productMode'] ?? '')),
        'allocationMode' => $allocationMode,
        'confirmReleaseCustomerReservations' => $confirmReleaseCustomerReservations,
        'linkedOrders' => $linkedOrders,
        'customerDispatchChoices' => $customerDispatchChoices,
        'expectedQty' => $expectedQty,
        'actualQty' => $actualQty,
        'quantityDifference' => $quantityDifference,
        'receivingVarianceReason' => $varianceReason,
        'costBasisQty' => $costBasisQty,
        'costBasisSource' => $costBasisSource,
        'costReferenceNo' => $costReferenceNo,
        'costTotalTwd' => $costTotalTwd,
        'costAverageTwd' => $costAverageTwd,
        'costMode' => $costMode,
        'lines' => $hashLines,
    ];
    // Preserve replay compatibility with receipts created before the
    // sample/live-purpose selector existed.  Only new explicit purpose choices
    // become part of the idempotency identity.
    if ($stockPurposeExplicit) $operationPayloadForHash['stockPurpose'] = $stockPurpose;
    $operationPayloadHash = preorder_operation_payload_hash($operationPayloadForHash);
    $directReceiptId = 'FREIGHT-REC-' . strtoupper(substr(hash('sha256', mb_strtolower($freightItemId, 'UTF-8')), 0, 20));
    // A failed pre-commit validation may have left a protocol-v3 confirming
    // intent/claim.  Inspect the atomic inventory marker before deciding
    // whether a corrected retry is safe to replace that uncommitted payload.
    $catalogLock = acquire_inventory_file_lock($skusFile);
    $skusForMarkers = read_json($skusFile);
    $existingOperationEntries = inventory_transaction_marker_entries($skusForMarkers, $operationId);
    $directReceiptIndex = -1;
    $allowAdditiveReceive = false;
    $inactiveDirectReceipt = false;
    foreach ($purchaseReceipts as $receiptIndex => $candidateReceipt) {
        if (!is_array($candidateReceipt)) continue;
        if (receipt_text($candidateReceipt['id'] ?? '') === $directReceiptId || receipt_text($candidateReceipt['operationId'] ?? '') === $operationId) {
            $directReceiptIndex = (int)$receiptIndex;
            $savedDirectHash = receipt_text($candidateReceipt['operationPayloadHash'] ?? '');
            $savedDirectStatus = strtolower(receipt_text($candidateReceipt['status'] ?? ''));
            $inactiveDirectReceipt = receipt_status_is_inactive($savedDirectStatus);
            // A reverse/void leaves the deterministic receive marker behind with
            // qtyDelta 0. That leftover must not block a later 增加配色 / 重新入庫.
            if ($inactiveDirectReceipt) {
                if (clear_inventory_transaction_markers_for_operation($skusForMarkers, $operationId) > 0) {
                    write_json($skusFile, $skusForMarkers);
                }
                $existingOperationEntries = [];
                $prior = is_array($purchaseReceipts[$directReceiptIndex]['previousReversals'] ?? null)
                    ? $purchaseReceipts[$directReceiptIndex]['previousReversals']
                    : [];
                $prior[] = [
                    'reversedAt' => receipt_first_text([$candidateReceipt['reversedAt'] ?? '', $candidateReceipt['updatedAt'] ?? '', date(DATE_ATOM)]),
                    'reverseReason' => receipt_text($candidateReceipt['reverseReason'] ?? $candidateReceipt['receivingVarianceReason'] ?? ''),
                    'originalPayloadHash' => $savedDirectHash,
                    'originalActualQty' => max(0, (int)($candidateReceipt['originalActualQty'] ?? $candidateReceipt['actualQty'] ?? 0)),
                ];
                $purchaseReceipts[$directReceiptIndex]['previousReversals'] = $prior;
                $purchaseReceipts[$directReceiptIndex]['status'] = 'confirming';
                unset($purchaseReceipts[$directReceiptIndex]['reconciliationRequired'], $purchaseReceipts[$directReceiptIndex]['reconciliationReason']);
            }
            $directPayloadIsCommitted = !$inactiveDirectReceipt && (
                !empty($existingOperationEntries)
                || in_array($savedDirectStatus, ['received', 'reconciliation_required'], true)
                || !empty($candidateReceipt['reconciliationRequired'])
            );
            if ($savedDirectHash !== '' && !hash_equals($savedDirectHash, $operationPayloadHash) && $directPayloadIsCommitted) {
                $committedQtyByKey = freight_product_receive_committed_qty_by_key($purchaseReceipts, $freightItemId);
                $deltaLines = [];
                $reducingCommitted = false;
                foreach ($cleanLines as $incomingLine) {
                    if (!is_array($incomingLine)) continue;
                    $lineKey = freight_product_receive_line_key($incomingLine);
                    $incomingQty = max(0, (int)($incomingLine['qty'] ?? 0));
                    $alreadyQty = max(0, (int)($committedQtyByKey[$lineKey] ?? 0));
                    if ($incomingQty < $alreadyQty) {
                        $reducingCommitted = true;
                        break;
                    }
                    $addQty = $incomingQty - $alreadyQty;
                    if ($addQty <= 0) continue;
                    $deltaLine = $incomingLine;
                    $deltaLine['qty'] = $addQty;
                    if ((int)($deltaLine['internalUseQty'] ?? 0) > $addQty) $deltaLine['internalUseQty'] = $addQty;
                    $deltaLine['sellableQty'] = max(0, $addQty - max(0, (int)($deltaLine['internalUseQty'] ?? 0)));
                    $deltaLines[] = $deltaLine;
                }
                if ($reducingCommitted || !$deltaLines) {
                    respond(['ok' => false, 'error' => '這筆物流產品已用不同產品、數量或倉別建立正式進貨單，請先人工核對'], 409);
                }
                $allowAdditiveReceive = true;
                $cleanLines = $deltaLines;
                $actualQty = array_reduce($cleanLines, static fn($sum, $line) => $sum + max(0, (int)($line['qty'] ?? 0)), 0);
                $internalUseQtyTotal = 0;
                $sellableQtyTotal = 0;
                foreach ($cleanLines as $qtyLine) {
                    if (!is_array($qtyLine)) continue;
                    $internalUseQtyTotal += max(0, (int)($qtyLine['internalUseQty'] ?? 0));
                    $sellableQtyTotal += max(0, (int)($qtyLine['sellableQty'] ?? 0));
                }
                $hashLines = $cleanLines;
                usort($hashLines, static function ($left, $right): int {
                    return strcmp(
                        implode('|', [(string)($left['skuId'] ?? ''), (string)($left['productId'] ?? ''), (string)($left['productCode'] ?? ''), (string)($left['barcode'] ?? ''), (string)($left['color'] ?? ''), (string)($left['size'] ?? '')]),
                        implode('|', [(string)($right['skuId'] ?? ''), (string)($right['productId'] ?? ''), (string)($right['productCode'] ?? ''), (string)($right['barcode'] ?? ''), (string)($right['color'] ?? ''), (string)($right['size'] ?? '')])
                    );
                });
                $supplementCount = 1;
                foreach ($purchaseReceipts as $existingReceipt) {
                    if (!is_array($existingReceipt)) continue;
                    if (receipt_text($existingReceipt['freightItemId'] ?? '') !== $freightItemId) continue;
                    $existingOp = receipt_text($existingReceipt['operationId'] ?? '');
                    if (strpos($existingOp, 'freight-product-receive-add:') === 0) $supplementCount++;
                }
                $operationId = 'freight-product-receive-add:' . hash('sha256', mb_strtolower($freightItemId . '|add|' . $supplementCount, 'UTF-8'));
                $directReceiptId = 'FREIGHT-REC-' . strtoupper(substr(hash('sha256', mb_strtolower($freightItemId . '|add|' . $supplementCount, 'UTF-8')), 0, 20));
                $directReceiptIndex = -1;
                $existingOperationEntries = inventory_transaction_marker_entries($skusForMarkers, $operationId);
                $operationPayloadForHash['allocationMode'] = $allocationMode;
                $operationPayloadForHash['linkedOrders'] = $linkedOrders;
                $operationPayloadForHash['expectedQty'] = $expectedQty;
                $operationPayloadForHash['actualQty'] = $actualQty;
                $operationPayloadForHash['quantityDifference'] = $expectedQty > 0 ? $actualQty - $expectedQty : 0;
                $operationPayloadForHash['lines'] = $hashLines;
                $operationPayloadHash = preorder_operation_payload_hash($operationPayloadForHash);
            }
            break;
        }
    }
    $receiptConflict = $allowAdditiveReceive || $inactiveDirectReceipt
        ? null
        : freight_product_receive_receipt_conflict($purchaseReceipts, $freightItem, $operationId);
    if ($receiptConflict) respond(['ok' => false, 'error' => '這筆物流產品已由正式採購進貨單入庫，不能再次增加庫存', 'conflict' => $receiptConflict], 409);
    $batchConflict = $allowAdditiveReceive || $inactiveDirectReceipt
        ? null
        : freight_product_receive_batch_conflict($freightData, $freightItem, $operationId);
    if ($batchConflict) respond(['ok' => false, 'error' => '這筆物流產品所屬批次已由正式進貨流程入庫，不能再次增加庫存', 'conflict' => $batchConflict], 409);

    $claim = isset($freightItem['inventoryReceiptClaim']) && is_array($freightItem['inventoryReceiptClaim']) ? $freightItem['inventoryReceiptClaim'] : [];
    $claimOperationId = receipt_text($claim['operationId'] ?? '');
    $claimHash = receipt_text($claim['payloadHash'] ?? '');
    if ($claimOperationId !== '' && $claimOperationId !== $operationId && !$allowAdditiveReceive && !$inactiveDirectReceipt) respond(['ok' => false, 'error' => '這筆物流產品已由另一個入庫操作領用'], 409);
    $claimStatus = strtolower(receipt_text($claim['status'] ?? ''));
    $claimPayloadIsCommitted = !$inactiveDirectReceipt && !$allowAdditiveReceive && (!empty($existingOperationEntries) || in_array($claimStatus, ['received', 'reconciliation_required'], true));
    if ($claimHash !== '' && !hash_equals($claimHash, $operationPayloadHash) && $claimPayloadIsCommitted) respond(['ok' => false, 'error' => '這筆物流產品已用不同品項、數量或倉別入庫，不能更改後重送'], 409);

    foreach ($existingOperationEntries as $entry) {
        $existingHash = receipt_text($entry['payloadHash'] ?? '');
        if ($existingHash !== '' && !hash_equals($existingHash, $operationPayloadHash)) {
            respond(['ok' => false, 'error' => '這筆物流產品已有不同內容的庫存交易，已停止重複增加'], 409);
        }
    }
    // LZ_RECV_ADD_AFTER_REVERSE_20260923
    if ($directReceiptIndex >= 0) {
        $directStatus = strtolower(receipt_text($purchaseReceipts[$directReceiptIndex]['status'] ?? ''));
        if ($directStatus === 'received' && !$existingOperationEntries) {
            $purchaseReceipts[$directReceiptIndex]['status'] = 'reconciliation_required';
            $purchaseReceipts[$directReceiptIndex]['reconciliationRequired'] = true;
            $purchaseReceipts[$directReceiptIndex]['reconciliationReason'] = 'received_without_inventory_marker';
            $purchaseReceipts[$directReceiptIndex]['reconciliationRequestedAt'] = date(DATE_ATOM);
            $purchaseReceipts[$directReceiptIndex]['updatedAt'] = date(DATE_ATOM);
            write_json($purchaseReceiptsFile, array_values($purchaseReceipts));
            respond(['ok' => false, 'error' => '正式進貨單顯示已入庫但找不到庫存交易記號；已轉人工核對，不會自動重複加庫存'], 409);
        }
    }
    if (strtolower(receipt_text($claim['status'] ?? '')) === 'received' && !$existingOperationEntries) {
        $freightData['items'][$freightItemIndex]['inventoryReceiptClaim']['status'] = 'reconciliation_required';
        $freightData['items'][$freightItemIndex]['inventoryReceiptClaim']['reconciliationReason'] = 'freight_received_without_inventory_marker';
        $freightData['items'][$freightItemIndex]['inventoryReceiptClaim']['updatedAt'] = date(DATE_ATOM);
        $freightData['updatedAt'] = date(DATE_ATOM);
        write_json($freightTrackingFile, $freightData);
        respond(['ok' => false, 'error' => '物流資料顯示已入庫但找不到庫存交易記號；已轉人工核對，不會自動重複加庫存'], 409);
    }

    $linkedOrderQty = array_reduce($linkedOrders, static fn($sum, $row) => $sum + max(0, (int)($row['qty'] ?? 0)), 0);
    $allocatableQty = $isInternalUse ? 0 : $sellableQtyTotal;
    $customerAllocatedQty = min(max(0, $allocatableQty), $linkedOrderQty);
    $companyStockQty = $isInternalUse ? 0 : max(0, $sellableQtyTotal - $customerAllocatedQty);
    $ownershipType = $isInternalUse
        ? 'internal_use'
        : ($customerAllocatedQty > 0
            ? ($companyStockQty > 0 ? 'mixed' : 'customer_order')
            : 'company_stock');
    $now = date(DATE_ATOM);
    $freightData['items'][$freightItemIndex]['inventoryReceiptClaim'] = [
        'claimType' => 'freight_product_receive',
        'operationId' => $operationId,
        'payloadHash' => $operationPayloadHash,
        'status' => $existingOperationEntries ? 'received' : 'claimed',
        'warehouse' => $warehouse,
        'ownership' => $ownershipType,
        'allocationMode' => $allocationMode,
        'linkedOrders' => $linkedOrders,
        'customerDispatchChoices' => $customerDispatchChoices,
        'customerAllocatedQty' => $customerAllocatedQty,
        'companyStockQty' => $companyStockQty,
        'customerAllocationRequired' => false,
        'claimedAt' => receipt_first_text([$claim['claimedAt'] ?? '', $now]),
        'receivedAt' => $existingOperationEntries ? receipt_first_text([$claim['receivedAt'] ?? '', $now]) : '',
        'updatedAt' => $now,
    ];
    $freightData['updatedAt'] = $now;
    write_json($freightTrackingFile, $freightData);

    $receivingDocument = [
        'documentNo' => 'FREIGHT-' . strtoupper(substr(hash('sha256', $freightItemId), 0, 16)),
        'documentDate' => date('Y-m-d'),
        'sourceOrderNo' => $savedTrackingNo !== '' ? $savedTrackingNo : $freightItemId,
        'supplier' => receipt_first_text([$freightItem['platform'] ?? '', $freightItem['importSource'] ?? '', '物流產品建檔']),
        'purchasePlatform' => receipt_first_text([$freightItem['platform'] ?? '', $freightItem['importSource'] ?? '']),
        'operatorName' => $receivedBy,
        'arrivalWarehouse' => $warehouse,
        'ownership' => $ownershipType,
        'allocationMode' => $allocationMode,
        'stockPurpose' => $stockPurpose,
        'linkedOrders' => $linkedOrders,
        'customerDispatchChoices' => $customerDispatchChoices,
        'customerAllocatedQty' => $customerAllocatedQty,
        'companyStockQty' => $companyStockQty,
        'costBasisQty' => $costBasisQty,
        'costBasisSource' => $costBasisSource,
        'costReferenceNo' => $costReferenceNo,
        'costTotalTwd' => $costTotalTwd,
        'costAverageTwd' => $costAverageTwd,
        'note' => $isInternalUse
            ? '物流進貨公司自用：入庫後扣掉庫存列入成本，不增加現貨／樣品'
            : ($ownershipType === 'company_stock'
            ? '物流產品直接轉公司現貨庫存'
            : ($ownershipType === 'mixed'
                ? '物流產品先配客戶訂單 ' . $customerAllocatedQty . ' 件；剩餘 ' . $companyStockQty . ' 件轉公司現貨'
                : '物流產品已保留客戶訂單連結後入庫')),
    ];
    if ($quantityDifference !== 0) $receivingDocument['note'] .= '；數量差異 ' . ($quantityDifference > 0 ? '+' : '') . $quantityDifference . ' 件：' . $varianceReason;
    $directReceiptNow = date(DATE_ATOM);
    $directReceiptTotalQty = array_reduce($cleanLines, static fn($sum, $line) => $sum + max(0, (int)($line['qty'] ?? 0)), 0);
    $directReceiptTotalCost = array_reduce($cleanLines, static fn($sum, $line) => $sum + (max(0, (int)($line['qty'] ?? 0)) * max(0, (float)($line['unitCostTwd'] ?? 0))), 0.0);
    $existingDirectReceipt = $directReceiptIndex >= 0 && is_array($purchaseReceipts[$directReceiptIndex] ?? null)
        ? $purchaseReceipts[$directReceiptIndex]
        : [];
    $directReceiptRecord = [
        'id' => $directReceiptId,
        'status' => strtolower(receipt_text($existingDirectReceipt['status'] ?? '')) === 'received' ? 'received' : 'confirming',
        'receivingDocument' => $receivingDocument + ['status' => 'confirming'],
        'receiptLines' => $cleanLines,
        'freightTrackingNo' => $savedTrackingNo,
        'freightItemId' => $freightItemId,
        'freightBatchId' => $savedBatchId,
        'freightForwarder' => receipt_first_text([$freightBatch['forwarder'] ?? '', $freightItem['forwarder'] ?? '', $freightItem['progress'] ?? '']),
        'freightCostMode' => receipt_text($freightBatch['costMode'] ?? ''),
        'freightWarehouseTaxType' => receipt_text($freightBatch['warehouseTaxType'] ?? ''),
        'freightChargeType' => receipt_text($freightBatch['chargeType'] ?? ''),
        'freightAmountTwd' => max(0, round((float)($freightBatch['amount'] ?? 0), 2)),
        'freightAllocationTwd' => max(0, round((float)($freightBatch['allocationPerItem'] ?? 0), 2)),
        'lineCount' => count($cleanLines),
        'totalQty' => $directReceiptTotalQty,
        'totalCostTwd' => round($directReceiptTotalCost, 2),
        'expectedQty' => $expectedQty,
        'actualQty' => $actualQty,
        'quantityDifference' => $quantityDifference,
        'receivingVarianceReason' => $varianceReason,
        'quantityReconciliationRequired' => $quantityDifference !== 0,
        'costBasisQty' => $costBasisQty,
        'costBasisSource' => $costBasisSource,
        'costReferenceNo' => $costReferenceNo,
        'costTotalTwd' => $costTotalTwd,
        'costAverageTwd' => $costAverageTwd,
        'costMode' => $costMode,
        'ownership' => $ownershipType,
        'allocationMode' => $allocationMode,
        'stockPurpose' => $stockPurpose,
        'linkedOrders' => $linkedOrders,
        'customerAllocatedQty' => $customerAllocatedQty,
        'companyStockQty' => $companyStockQty,
        'operationId' => $operationId,
        'operationPayloadHash' => $operationPayloadHash,
        'operationProtocolVersion' => 3,
        'createdAt' => receipt_first_text([$existingDirectReceipt['createdAt'] ?? '', $directReceiptNow]),
        'updatedAt' => $directReceiptNow,
    ];
    if ($directReceiptIndex >= 0) {
        $purchaseReceipts[$directReceiptIndex] = $directReceiptRecord;
    } else {
        array_unshift($purchaseReceipts, $directReceiptRecord);
        $directReceiptIndex = 0;
    }
    // Persist the formal receiving intent before changing stock.  A retry can
    // safely finish this same deterministic record after an interruption.
    write_json($purchaseReceiptsFile, array_values($purchaseReceipts));
    $pseudoInquiry = [
        'id' => $freightItemId,
        'purchasePlatform' => $receivingDocument['purchasePlatform'],
        'platform' => $receivingDocument['purchasePlatform'],
        'freightTrackingNo' => $savedTrackingNo,
        'freightItemId' => $freightItemId,
        'freightBatchId' => $savedBatchId,
        'items' => array_map(static function ($line): array {
            return [
                'skuId' => $line['skuId'], 'sku' => $line['skuId'], 'productId' => $line['productId'],
                'code' => $line['productCode'], 'title' => $line['productName'] !== '' ? $line['productName'] : $line['productCode'],
                'category' => $line['category'], 'color' => $line['color'], 'size' => $line['size'], 'qty' => $line['qty'],
                'arrivalImage' => $line['arrivalImage'],
            ];
        }, $cleanLines),
    ];
    $receivePayload = array_merge($payload, [
        'receivedBy' => $receivedBy,
        'receivingDocument' => $receivingDocument,
        'freightTrackingNo' => $savedTrackingNo,
        'freightItemId' => $freightItemId,
        'freightBatchId' => $savedBatchId,
        'freightDestinationWarehouse' => $effectiveDestinationWarehouse,
        'goodsPurpose' => $isInternalUse ? 'internal_use' : ($isMixedUse ? 'mixed' : receipt_text($payload['goodsPurpose'] ?? '')),
    ]);
    $receivePayload['receiptLines'] = $cleanLines;
    $receipt = apply_preorder_receipt_inventory($pseudoInquiry, $receivePayload, $warehouse, $productsFile, $skusFile, $stateFile, $freightTrackingFile, $operationId, $operationPayloadHash, true);

    $receivedAt = receipt_first_text([$claim['receivedAt'] ?? '', $receipt['receiptLog'][0]['appliedAt'] ?? '', date(DATE_ATOM)]);
    $lineSummary = array_map(static function ($line): array {
        return [
            'skuId' => receipt_text($line['skuId'] ?? ''),
            'productId' => receipt_text($line['productId'] ?? ''),
            'productCode' => receipt_text($line['productCode'] ?? ''),
            'barcode' => receipt_text($line['barcode'] ?? ''),
            'color' => receipt_text($line['color'] ?? ''),
            'size' => receipt_text($line['size'] ?? ''),
            'qty' => max(0, (int)($line['qty'] ?? 0)),
            'internalUseQty' => max(0, (int)($line['internalUseQty'] ?? 0)),
            'sellableQty' => max(0, (int)($line['sellableQty'] ?? freight_receive_line_sellable_qty($line))),
            'unitCostTwd' => max(0, round((float)($line['unitCostTwd'] ?? 0), 2)),
            'warehouse' => receipt_text($line['warehouse'] ?? ''),
        ];
    }, $receipt['receiptLog']);

    // The formal document and the stock marker describe the same committed
    // receiving operation.  Finalize the deterministic receipt before the
    // derived freight status so an interrupted retry can safely repair the
    // latter without adding stock again.
    $directReceiptRecord['status'] = 'received';
    $directReceiptRecord['receiptLines'] = $receipt['receiptLog'];
    $directReceiptRecord['receivingDocument']['status'] = 'received';
    $directReceiptRecord['receivingDocument']['receivedAt'] = $receivedAt;
    $directReceiptRecord['receivedAt'] = $receivedAt;
    $directReceiptRecord['inventoryOperationId'] = $operationId;
    $directReceiptRecord['totalQty'] = array_reduce($receipt['receiptLog'], static fn($sum, $line) => $sum + max(0, (int)($line['qty'] ?? 0)), 0);
    $directReceiptRecord['totalCostTwd'] = round(array_reduce($receipt['receiptLog'], static fn($sum, $line) => $sum + (max(0, (int)($line['qty'] ?? 0)) * max(0, (float)($line['unitCostTwd'] ?? 0))), 0.0), 2);
    $directReceiptRecord['updatedAt'] = date(DATE_ATOM);
    $purchaseReceipts[$directReceiptIndex] = $directReceiptRecord;
    write_json($purchaseReceiptsFile, array_values($purchaseReceipts));

    $freightData = read_json($freightTrackingFile);
    $freightItemIndex = freight_product_receive_item_index($freightData, $freightItemId);
    if ($freightItemIndex < 0) respond(['ok' => false, 'error' => '庫存已提交但物流明細遺失，請立即人工核對'], 409);
    $freightData['items'][$freightItemIndex]['inventoryReceiptClaim'] = [
        'claimType' => 'freight_product_receive',
        'operationId' => $operationId,
        'payloadHash' => $operationPayloadHash,
        'status' => 'received',
        'warehouse' => $warehouse,
        'ownership' => $ownershipType,
        'allocationMode' => $allocationMode,
        'linkedOrders' => $linkedOrders,
        'customerDispatchChoices' => $customerDispatchChoices,
        'customerAllocatedQty' => $customerAllocatedQty,
        'companyStockQty' => $companyStockQty,
        'customerAllocationRequired' => false,
        'claimedAt' => receipt_first_text([$claim['claimedAt'] ?? '', $receivedAt]),
        'receivedAt' => $receivedAt,
        'updatedAt' => date(DATE_ATOM),
        'lines' => $lineSummary,
    ];
    $freightData['items'][$freightItemIndex]['inventoryReceivedAt'] = $receivedAt;
    $freightData['items'][$freightItemIndex]['inventoryReceivedWarehouse'] = $warehouse;
    $freightData['items'][$freightItemIndex]['warehouseTransferredFrom'] = 'PREORDER';
    $freightData['items'][$freightItemIndex]['warehousePosition'] = $effectiveDestinationWarehouse;
    $freightData['items'][$freightItemIndex]['warehouseTransferredAt'] = $receivedAt;
    $freightData['items'][$freightItemIndex]['inventoryStatus'] = 'received';
    $freightData['items'][$freightItemIndex]['inventoryOperationId'] = $operationId;
    $freightData['items'][$freightItemIndex]['receivingDocumentId'] = $directReceiptId;
    $freightData['items'][$freightItemIndex]['receivingDocumentNo'] = $receivingDocument['documentNo'];
    $freightData['items'][$freightItemIndex]['receivingStage'] = 'inventory_received';
    $freightData['items'][$freightItemIndex]['receivingStatus'] = $quantityDifference !== 0 ? '已入庫／數量差異待追蹤' : '已完成入庫';
    $freightData['items'][$freightItemIndex]['freightReceivingStatus'] = $quantityDifference !== 0 ? '已入庫／數量差異待追蹤' : '已完成入庫';
    $freightData['items'][$freightItemIndex]['progress'] = $quantityDifference !== 0 ? '已入庫／數量差異待追蹤' : '已入庫';
    $freightData['items'][$freightItemIndex]['receivingExpectedQty'] = $expectedQty;
    $freightData['items'][$freightItemIndex]['receivingActualQty'] = $actualQty;
    $freightData['items'][$freightItemIndex]['receivingQuantityDifference'] = $quantityDifference;
    $freightData['items'][$freightItemIndex]['receivingVarianceReason'] = $varianceReason;
    $freightData['items'][$freightItemIndex]['quantityReconciliationRequired'] = $quantityDifference !== 0;
    $freightData['items'][$freightItemIndex]['costBasisQty'] = $costBasisQty;
    $freightData['items'][$freightItemIndex]['costBasisSource'] = $costBasisSource;
    $freightData['items'][$freightItemIndex]['costReferenceNo'] = $costReferenceNo;
    $freightData['items'][$freightItemIndex]['costTotalTwd'] = $costTotalTwd;
    $freightData['items'][$freightItemIndex]['costAverageTwd'] = $costAverageTwd;
    if ($quantityDifference !== 0) {
        $freightData['items'][$freightItemIndex]['quantityReconciliationStatus'] = 'pending';
        $freightData['items'][$freightItemIndex]['quantityReconciliationNote'] = $varianceReason;
        $freightData['items'][$freightItemIndex]['quantityReconciliationUpdatedAt'] = $receivedAt;
    }
    $freightData['items'][$freightItemIndex]['destinationWarehouse'] = $effectiveDestinationWarehouse;
    $freightData['items'][$freightItemIndex]['destinationSite'] = freight_destination_site($effectiveDestinationWarehouse);
    $freightData['items'][$freightItemIndex]['inventoryOwnership'] = $ownershipType;
    if ($allocationMode !== 'company_stock' && !empty($fifoExpansion['addedLinks'])) {
        $freightData['items'][$freightItemIndex]['customerLinks'] = freight_receive_merge_selected_links(
            is_array($freightData['items'][$freightItemIndex]['customerLinks'] ?? null) ? $freightData['items'][$freightItemIndex]['customerLinks'] : [],
            $linkedOrders
        );
    }
    $freightData['items'][$freightItemIndex]['inventoryLinkedOrders'] = $linkedOrders;
    $freightData['items'][$freightItemIndex]['customerAllocatedQty'] = $customerAllocatedQty;
    $freightData['items'][$freightItemIndex]['companyStockQty'] = $companyStockQty;
    $freightData['items'][$freightItemIndex]['companyStock'] = $companyStockQty > 0;
    $freightData['items'][$freightItemIndex]['directStockPurpose'] = $stockPurpose;
    $freightData['items'][$freightItemIndex]['directStockPurposeLabel'] = $isInternalUse
        ? '公司自用'
        : ($stockPurpose === 'sample' ? '現貨樣品' : '直播現貨');
    $releaseArchive = is_array($freightData['items'][$freightItemIndex]['releasedCustomerLinks'] ?? null)
        ? array_values($freightData['items'][$freightItemIndex]['releasedCustomerLinks'])
        : [];
    $releasedCustomerLinks = !empty($allocationPlan['releasedLinks']) ? array_values($allocationPlan['releasedLinks']) : [];
    if (!$releasedCustomerLinks && $allocationMode === 'company_stock' && $confirmReleaseCustomerReservations) {
        foreach ($releaseArchive as $archivedLink) {
            if (is_array($archivedLink) && receipt_text($archivedLink['releaseOperationId'] ?? '') === $operationId) $releasedCustomerLinks[] = $archivedLink;
        }
    }
    if ($allocationMode === 'company_stock' && $confirmReleaseCustomerReservations && $releasedCustomerLinks) {
        foreach ($releasedCustomerLinks as $releasedLink) {
            if (!is_array($releasedLink)) continue;
            $alreadyArchived = false;
            foreach ($releaseArchive as $archivedLink) {
                if (!is_array($archivedLink)) continue;
                if (receipt_text($archivedLink['releaseOperationId'] ?? '') === $operationId
                    && receipt_text($archivedLink['inquiryId'] ?? '') === receipt_text($releasedLink['inquiryId'] ?? '')
                    && receipt_text($archivedLink['lineId'] ?? '') === receipt_text($releasedLink['lineId'] ?? '')
                    && (int)($archivedLink['itemIndex'] ?? -1) === (int)($releasedLink['itemIndex'] ?? -1)) {
                    $alreadyArchived = true;
                    break;
                }
            }
            if ($alreadyArchived) continue;
            $releaseArchive[] = $releasedLink + [
                'releasedAt' => $receivedAt,
                'releasedBy' => $receivedBy,
                'releaseReason' => '驗收入庫時改為公司現貨／樣品',
                'releaseOperationId' => $operationId,
            ];
        }
        $freightData['items'][$freightItemIndex]['releasedCustomerLinks'] = array_slice($releaseArchive, -500);
        $freightData['items'][$freightItemIndex]['customerLinks'] = [];
        $freightData['items'][$freightItemIndex]['purchaseOffsetQty'] = 0;
        $freightData['items'][$freightItemIndex]['customerReservationReleasedAt'] = $receivedAt;
        $freightData['items'][$freightItemIndex]['customerReservationReleasedBy'] = $receivedBy;
        $freightData['items'][$freightItemIndex]['customerReservationReleaseReason'] = '改為 ' . $warehouse . ' 公司現貨／樣品';
    }
    $filedProductIds = array_values(array_unique(array_filter(array_map(static fn($line) => receipt_text($line['productId'] ?? ''), $receipt['receiptLog']))));
    $filedProductCodes = array_values(array_unique(array_filter(array_map(static fn($line) => receipt_text($line['productCode'] ?? ''), $receipt['receiptLog']))));
    $filedSkuIds = array_values(array_unique(array_filter(array_map(static fn($line) => receipt_text($line['skuId'] ?? ''), $receipt['receiptLog']))));
    $filedBarcodes = array_values(array_unique(array_filter(array_map(static function ($line): string {
        $skuId = receipt_text($line['skuId'] ?? '');
        $productCode = receipt_text($line['productCode'] ?? '');
        $barcode = receipt_text($line['barcode'] ?? '');
        if ($barcode === '' || strtoupper(preg_replace('/\s+/', '', $barcode) ?? '') === strtoupper(preg_replace('/\s+/', '', $productCode) ?? '')) {
            $barcode = preg_replace('/-(?:TW|CN|ID)$/i', '', $skuId) ?? $skuId;
        }
        return $barcode;
    }, $receipt['receiptLog']))));
    $firstReceivedLine = is_array($receipt['receiptLog'][0] ?? null) ? $receipt['receiptLog'][0] : [];
    $firstFiledSkuId = receipt_text($firstReceivedLine['skuId'] ?? '');
    $firstFiledProductCode = receipt_text($firstReceivedLine['productCode'] ?? '');
    $firstFiledBarcode = receipt_text($firstReceivedLine['barcode'] ?? '');
    if ($firstFiledBarcode === '' || strtoupper(preg_replace('/\s+/', '', $firstFiledBarcode) ?? '') === strtoupper(preg_replace('/\s+/', '', $firstFiledProductCode) ?? '')) {
        $firstFiledBarcode = preg_replace('/-(?:TW|CN|ID)$/i', '', $firstFiledSkuId) ?? $firstFiledSkuId;
    }
    $freightData['items'][$freightItemIndex]['productFiledStatus'] = 'filed';
    $freightData['items'][$freightItemIndex]['productFiledInventoryReceived'] = true;
    $freightData['items'][$freightItemIndex]['productFiledInventoryReceiptId'] = $directReceiptId;
    $freightData['items'][$freightItemIndex]['productFiledInventoryReceiptNo'] = $receivingDocument['documentNo'];
    $freightData['items'][$freightItemIndex]['productFiledProductIds'] = $filedProductIds;
    $freightData['items'][$freightItemIndex]['productFiledProductCodes'] = $filedProductCodes;
    $freightData['items'][$freightItemIndex]['productFiledSkuIds'] = $filedSkuIds;
    $freightData['items'][$freightItemIndex]['productFiledBarcodes'] = $filedBarcodes;
    $freightData['items'][$freightItemIndex]['productFiledProductId'] = receipt_text($firstReceivedLine['productId'] ?? '');
    $freightData['items'][$freightItemIndex]['productFiledProductCode'] = $firstFiledProductCode;
    $freightData['items'][$freightItemIndex]['productFiledProductTitle'] = receipt_text($firstReceivedLine['productName'] ?? '');
    $freightData['items'][$freightItemIndex]['productFiledSkuId'] = $firstFiledSkuId;
    $freightData['items'][$freightItemIndex]['productFiledBarcode'] = $firstFiledBarcode;
    $freightData['items'][$freightItemIndex]['productId'] = receipt_text($firstReceivedLine['productId'] ?? '');
    $freightData['items'][$freightItemIndex]['productCode'] = $firstFiledProductCode;
    $freightData['items'][$freightItemIndex]['skuId'] = $firstFiledSkuId;
    $freightData['items'][$freightItemIndex]['barcode'] = $firstFiledBarcode;
    $freightData['items'][$freightItemIndex]['companyBarcode'] = $firstFiledBarcode;
    $freightData['items'][$freightItemIndex]['productFiledAt'] = $receivedAt;
    $itemHistory = isset($freightData['items'][$freightItemIndex]['statusHistory']) && is_array($freightData['items'][$freightItemIndex]['statusHistory'])
        ? array_values($freightData['items'][$freightItemIndex]['statusHistory'])
        : [];
    $hasItemOperationHistory = false;
    foreach ($itemHistory as $historyEntry) {
        if (is_array($historyEntry) && receipt_text($historyEntry['operationId'] ?? '') === $operationId) { $hasItemOperationHistory = true; break; }
    }
    if (!$hasItemOperationHistory) {
        $itemHistory[] = [
            'operationId' => $operationId,
            'status' => $quantityDifference !== 0 ? '已入庫／數量差異待追蹤' : '已入庫',
            'at' => $receivedAt,
            'warehouse' => $warehouse,
            'receivingDocumentNo' => $receivingDocument['documentNo'],
            'expectedQty' => $expectedQty,
            'actualQty' => $actualQty,
            'differenceQty' => $quantityDifference,
            'reason' => $varianceReason,
            'costBasisQty' => $costBasisQty,
            'costBasisSource' => $costBasisSource,
            'costReferenceNo' => $costReferenceNo,
        ];
    }
    $freightData['items'][$freightItemIndex]['statusHistory'] = $itemHistory;
    $freightData['items'][$freightItemIndex]['updatedAt'] = date(DATE_ATOM);
    if ($internalUseQtyTotal > 0) {
        if (!empty($isInternalUse)) {
            $freightData['items'][$freightItemIndex]['goodsPurpose'] = 'internal_use';
            $freightData['items'][$freightItemIndex]['salePrice'] = 0;
            $freightData['items'][$freightItemIndex]['directStockPurpose'] = 'internal_use';
            $freightData['items'][$freightItemIndex]['directStockPurposeLabel'] = '公司自用';
        } else {
            $freightData['items'][$freightItemIndex]['goodsPurpose'] = 'mixed';
        }
        $freightData['items'][$freightItemIndex]['internalUseQty'] = $internalUseQtyTotal;
        $freightData['items'][$freightItemIndex]['sellableQty'] = $sellableQtyTotal;
        foreach (['variants', 'inventoryLines', 'lines'] as $variantKey) {
            if (!isset($freightData['items'][$freightItemIndex][$variantKey]) || !is_array($freightData['items'][$freightItemIndex][$variantKey])) continue;
            foreach ($freightData['items'][$freightItemIndex][$variantKey] as $variantIndex => &$variantRow) {
                if (!is_array($variantRow)) continue;
                foreach ($cleanLines as $receivedLine) {
                    if (!is_array($receivedLine) || (int)($receivedLine['sourceLineIndex'] ?? -1) !== (int)$variantIndex) continue;
                    $variantRow['internalUseQty'] = max(0, (int)($receivedLine['internalUseQty'] ?? 0));
                    $variantRow['sellableQty'] = max(0, (int)($receivedLine['sellableQty'] ?? 0));
                    break;
                }
            }
            unset($variantRow);
        }
        $expense = freight_record_inbound_internal_use_expense(
            $dataDir,
            $freightData['items'][$freightItemIndex],
            is_array($receipt['receiptLog'] ?? null) ? $receipt['receiptLog'] : [],
            $actualQty,
            $operationId,
            $receivedBy,
            $receivedAt,
            $warehouse
        );
        if (!empty($expense['ok'])) {
            $freightData['items'][$freightItemIndex]['internalUseExpensed'] = true;
            $freightData['items'][$freightItemIndex]['internalUseExpenseId'] = (string)($expense['id'] ?? '');
            $freightData['items'][$freightItemIndex]['internalUseExpenseTwd'] = (float)($expense['amount'] ?? 0);
            $freightData['items'][$freightItemIndex]['internalUseExpenseAt'] = $receivedAt;
            $freightData['items'][$freightItemIndex]['internalUseExpenseError'] = '';
        } else {
            $freightData['items'][$freightItemIndex]['internalUseExpensed'] = false;
            $freightData['items'][$freightItemIndex]['internalUseExpenseError'] = (string)($expense['error'] ?? '公司自用成本未入帳');
        }
    }

    if ($savedBatchId !== '' && isset($freightData['batches']) && is_array($freightData['batches'])) {
        foreach ($freightData['batches'] as $batchIndex => &$batchRow) {
            if (!is_array($batchRow) || receipt_text($batchRow['id'] ?? '') !== $savedBatchId) continue;
            $packageRows = isset($batchRow['packageRows']) && is_array($batchRow['packageRows']) ? array_values($batchRow['packageRows']) : [];
            foreach ($packageRows as &$packageRow) {
                if (!is_array($packageRow)) continue;
                $packageItemId = receipt_first_text([$packageRow['sourceItemId'] ?? '', $packageRow['freightItemId'] ?? '', $packageRow['id'] ?? '']);
                if ($packageItemId !== $freightItemId) continue;
                $packageRow['inventoryStatus'] = 'received';
                $packageRow['inventoryReceivedAt'] = $receivedAt;
                $packageRow['inventoryReceivedWarehouse'] = $warehouse;
                $packageRow['warehouseTransferredFrom'] = 'PREORDER';
                $packageRow['warehousePosition'] = $effectiveDestinationWarehouse;
                $packageRow['warehouseTransferredAt'] = $receivedAt;
                $packageRow['inventoryOperationId'] = $operationId;
                $packageRow['receivingDocumentId'] = $directReceiptId;
                $packageRow['receivingDocumentNo'] = $receivingDocument['documentNo'];
                $packageRow['receiptStatus'] = $quantityDifference !== 0 ? '已入庫／數量差異待追蹤' : '已入庫';
                $packageRow['receivedQty'] = $directReceiptRecord['totalQty'];
                $packageRow['expectedQty'] = $expectedQty;
                $packageRow['quantityDifference'] = $quantityDifference;
                $packageRow['receivingVarianceReason'] = $varianceReason;
                $packageRow['quantityReconciliationRequired'] = $quantityDifference !== 0;
                $packageRow['quantityReconciliationStatus'] = $quantityDifference !== 0 ? 'pending' : 'not_required';
                $packageRow['costBasisQty'] = $costBasisQty;
                $packageRow['costBasisSource'] = $costBasisSource;
                $packageRow['costReferenceNo'] = $costReferenceNo;
                $packageRow['costTotalTwd'] = $costTotalTwd;
                $packageRow['costAverageTwd'] = $costAverageTwd;
                $packageRow['productId'] = receipt_text($firstReceivedLine['productId'] ?? '');
                $packageRow['productCode'] = $firstFiledProductCode;
                $packageRow['skuId'] = $firstFiledSkuId;
                $packageRow['barcode'] = $firstFiledBarcode;
                $packageRow['companyBarcode'] = $firstFiledBarcode;
                $packageRow['productFiledProductId'] = receipt_text($firstReceivedLine['productId'] ?? '');
                $packageRow['productFiledProductCode'] = $firstFiledProductCode;
                $packageRow['productFiledProductTitle'] = receipt_text($firstReceivedLine['productName'] ?? '');
                $packageRow['productFiledSkuId'] = $firstFiledSkuId;
                $packageRow['productFiledBarcode'] = $firstFiledBarcode;
                $packageRow['productFiledStatus'] = 'filed';
                $packageRow['productFiledInventoryReceived'] = true;
                $packageRow['updatedAt'] = date(DATE_ATOM);
            }
            unset($packageRow);
            $batchRow['packageRows'] = $packageRows;

            $receivedItemIds = [];
            foreach (is_array($freightData['items'] ?? null) ? $freightData['items'] : [] as $candidateItem) {
                if (!is_array($candidateItem)) continue;
                $candidateClaim = isset($candidateItem['inventoryReceiptClaim']) && is_array($candidateItem['inventoryReceiptClaim']) ? $candidateItem['inventoryReceiptClaim'] : [];
                $candidateReceived = receipt_text($candidateItem['inventoryStatus'] ?? '') === 'received'
                    || !empty($candidateItem['inventoryReceivedAt'])
                    || strtolower(receipt_text($candidateClaim['status'] ?? '')) === 'received';
                if ($candidateReceived) $receivedItemIds[receipt_text($candidateItem['id'] ?? '')] = true;
            }
            $receivedRows = 0;
            $receivedQty = 0;
            foreach ($packageRows as $packageRow) {
                if (!is_array($packageRow)) continue;
                $packageItemId = receipt_first_text([$packageRow['sourceItemId'] ?? '', $packageRow['freightItemId'] ?? '', $packageRow['id'] ?? '']);
                $rowReceived = receipt_text($packageRow['inventoryStatus'] ?? '') === 'received'
                    || !empty($packageRow['inventoryReceivedAt'])
                    || ($packageItemId !== '' && isset($receivedItemIds[$packageItemId]));
                if (!$rowReceived) continue;
                $receivedRows++;
                $receivedQty += max(1, (int)($packageRow['receivedQty'] ?? $packageRow['quantity'] ?? $packageRow['qty'] ?? 1));
            }
            $allBatchItemsReceived = $packageRows && $receivedRows === count($packageRows);
            $batchRow['inventoryReceivedItemCount'] = $receivedRows;
            $batchRow['inventoryPendingItemCount'] = max(0, count($packageRows) - $receivedRows);
            $batchRow['inventoryReceivedQty'] = $receivedQty;
            $batchRow['inventoryStatus'] = $allBatchItemsReceived ? 'received' : 'partial';
            $batchRow['receivingStage'] = $allBatchItemsReceived ? 'inventory_received' : 'partial_inventory_received';
            $batchHasQuantityVariance = false;
            foreach ($packageRows as $candidatePackageRow) {
                if (is_array($candidatePackageRow) && !empty($candidatePackageRow['quantityReconciliationRequired'])) { $batchHasQuantityVariance = true; break; }
            }
            $batchRow['quantityReconciliationRequired'] = $batchHasQuantityVariance;
            $batchRow['status'] = $batchHasQuantityVariance ? '入庫數量差異待追蹤' : ($allBatchItemsReceived ? '已入庫' : '部分入庫');
            if ($costBasisQty > 0) {
                $batchRow['costBasisQty'] = $costBasisQty;
                $batchRow['costBasisSource'] = $costBasisSource;
                $batchRow['costReferenceNo'] = $costReferenceNo;
                $batchRow['costTotalTwd'] = $costTotalTwd;
                $batchRow['costAverageTwd'] = $costAverageTwd;
            }
            if ($batchHasQuantityVariance) {
                $batchRow['quantityReconciliationStatus'] = 'pending';
                $batchRow['quantityReconciliationUpdatedAt'] = $receivedAt;
            }
            $batchRow['lastInventoryReceivedAt'] = $receivedAt;
            if (!isset($batchRow['inventoryReceiptDocuments']) || !is_array($batchRow['inventoryReceiptDocuments'])) $batchRow['inventoryReceiptDocuments'] = [];
            $hasBatchDocument = false;
            foreach ($batchRow['inventoryReceiptDocuments'] as $documentEntry) {
                if (is_array($documentEntry) && receipt_text($documentEntry['operationId'] ?? '') === $operationId) { $hasBatchDocument = true; break; }
            }
            if (!$hasBatchDocument) {
                $batchRow['inventoryReceiptDocuments'][] = [
                    'freightItemId' => $freightItemId,
                    'documentId' => $directReceiptId,
                    'documentNo' => $receivingDocument['documentNo'],
                    'operationId' => $operationId,
                    'warehouse' => $warehouse,
                    'qty' => $directReceiptRecord['totalQty'],
                    'expectedQty' => $expectedQty,
                    'differenceQty' => $quantityDifference,
                    'costBasisQty' => $costBasisQty,
                    'costBasisSource' => $costBasisSource,
                    'costReferenceNo' => $costReferenceNo,
                    'receivedAt' => $receivedAt,
                ];
            }
            $batchDocumentNos = array_values(array_unique(array_filter(array_map(static fn($entry) => is_array($entry) ? receipt_text($entry['documentNo'] ?? '') : '', $batchRow['inventoryReceiptDocuments']))));
            $batchRow['receivingDocumentNos'] = $batchDocumentNos;
            $batchRow['receivingDocumentNo'] = implode('、', $batchDocumentNos);
            if ($allBatchItemsReceived) $batchRow['inventoryReceivedAt'] = $receivedAt;
            $batchHistory = isset($batchRow['statusHistory']) && is_array($batchRow['statusHistory']) ? array_values($batchRow['statusHistory']) : [];
            $hasBatchOperationHistory = false;
            foreach ($batchHistory as $historyEntry) {
                if (is_array($historyEntry) && receipt_text($historyEntry['operationId'] ?? '') === $operationId) { $hasBatchOperationHistory = true; break; }
            }
            if (!$hasBatchOperationHistory) {
                $batchHistory[] = [
                    'operationId' => $operationId,
                    'freightItemId' => $freightItemId,
                    'status' => $batchRow['status'],
                    'at' => $receivedAt,
                    'receivingDocumentNo' => $receivingDocument['documentNo'],
                    'expectedQty' => $expectedQty,
                    'actualQty' => $actualQty,
                    'differenceQty' => $quantityDifference,
                    'costBasisQty' => $costBasisQty,
                    'costBasisSource' => $costBasisSource,
                    'costReferenceNo' => $costReferenceNo,
                ];
            }
            $batchRow['statusHistory'] = $batchHistory;
            $batchRow['updatedAt'] = date(DATE_ATOM);
            break;
        }
        unset($batchRow);
    }
    $freightData['revision'] = max(0, (int)($freightData['revision'] ?? 0)) + 1;
    $freightData['updatedAt'] = date(DATE_ATOM);
    write_json($freightTrackingFile, $freightData);
    flock($freightFileLock, LOCK_UN);
    fclose($freightFileLock);
    flock($receiptsFileLock, LOCK_UN);
    fclose($receiptsFileLock);
    try {
        $reservationRelease = freight_product_receive_release_customer_reservations(
            $inquiriesFile,
            $freightData,
            $freightItemId,
            $releasedCustomerLinks,
            $operationId,
            $receivedAt,
            $savedTrackingNo,
            $warehouse
        );
    } catch (Throwable $releaseError) {
        $reservationRelease = ['releasedOrderCount' => 0, 'releasedQty' => 0, 'orders' => [], 'warning' => $releaseError->getMessage()];
    }
    $orderArrival = freight_product_receive_mark_order_arrivals(
        $inquiriesFile,
        $linkedOrders,
        $operationId,
        $receivedAt,
        $freightItemId,
        $savedTrackingNo,
        $warehouse,
        $receipt['receiptLog'],
        $receivingDocument['documentNo'],
        $customerDispatchChoices
    );
    $totalQty = array_reduce($lineSummary, static fn($sum, $line) => $sum + (int)($line['qty'] ?? 0), 0);
    respond([
        'ok' => true,
        'freightItemId' => $freightItemId,
        'operationId' => $operationId,
        'inventory' => ['warehouse' => $warehouse, 'lines' => $lineSummary, 'totalQty' => $totalQty],
        'quantityCheck' => ['expectedQty' => $expectedQty, 'actualQty' => $actualQty, 'differenceQty' => $quantityDifference, 'reason' => $varianceReason, 'requiresTracking' => $quantityDifference !== 0],
        'costBasis' => ['qty' => $costBasisQty, 'source' => $costBasisSource, 'referenceNo' => $costReferenceNo, 'totalTwd' => $costTotalTwd, 'averageTwd' => $costAverageTwd, 'usesFinalActualQty' => $quantityDifference !== 0 && $costBasisQty === $actualQty],
        'ownership' => ['type' => $ownershipType, 'allocationMode' => $allocationMode, 'linkedOrders' => $linkedOrders, 'customerAllocatedQty' => $customerAllocatedQty, 'companyStockQty' => $companyStockQty, 'internalUseQty' => $internalUseQtyTotal, 'customerDispatchChoices' => $customerDispatchChoices],
        'goodsPurpose' => $isInternalUse ? 'internal_use' : ($isMixedUse ? 'mixed' : receipt_text($payload['goodsPurpose'] ?? '')),
        'reservationRelease' => $reservationRelease,
        'orderArrival' => $orderArrival,
        'receipt' => $directReceiptRecord,
        'idempotentReplay' => !empty($receipt['idempotentReplay']) || !empty($existingOperationEntries),
        'receivedAt' => $receivedAt,
    ]);
}

if ($action === 'delete-purchase-receipt-line') {
    // LZ_RECV_DEL_20260924 / LZ_RECV_DEL_2_20260924: delete one inbound line and reverse posted stock. Never drop the line if reverse fails.
    // Match save/confirm-purchase-receipt: inbound clerks can post without a session header.
    ignore_user_abort(true);
    @set_time_limit(180);
    $operatorName = receipt_first_text([$payload['receivedBy'] ?? '', $payload['operatorName'] ?? '', '管理者']);
    $documentId = receipt_text($payload['documentId'] ?? '');
    $requestedIndex = array_key_exists('lineIndex', $payload) && is_numeric($payload['lineIndex']) ? (int)$payload['lineIndex'] : -1;
    $confirmVoid = !empty($payload['confirmVoid']);
    if ($documentId === '') respond(['ok' => false, 'error' => '缺少進貨單號，無法刪除品項'], 400);

    $now = date(DATE_ATOM);
    $receiptsFileLock = acquire_purchase_receipts_file_lock($purchaseReceiptsFile);
    $rows = read_json($purchaseReceiptsFile);
    $index = -1;
    foreach ($rows as $rowIndex => $row) {
        if (!is_array($row)) continue;
        $rowId = receipt_text($row['id'] ?? '');
        $rowDoc = is_array($row['receivingDocument'] ?? null) ? $row['receivingDocument'] : [];
        $rowNo = receipt_first_text([$rowDoc['documentNo'] ?? '', $row['documentNo'] ?? '']);
        if ($rowId === $documentId || ($rowNo !== '' && $rowNo === $documentId)) {
            $index = (int)$rowIndex;
            $documentId = $rowId !== '' ? $rowId : $documentId;
            break;
        }
    }
    if ($index < 0) respond(['ok' => false, 'error' => '找不到這張進貨單，請重新整理後再試'], 404);
    $receiptOperationLock = acquire_purchase_receipt_lock($purchaseReceiptsFile, $documentId);
    $receipt = is_array($rows[$index]) ? $rows[$index] : [];
    $status = strtolower(receipt_text($receipt['status'] ?? ''));
    if ($status === 'reconciliation_required' || !empty($receipt['reconciliationRequired'])) {
        respond(['ok' => false, 'error' => '這張進貨單正在人工核對，不能刪除品項'], 409);
    }
    if (receipt_status_is_inactive($status)) {
        respond(['ok' => true, 'idempotentReplay' => true, 'voided' => true, 'receipt' => $receipt, 'message' => '這張進貨單已經作廢']);
    }

    $lines = isset($receipt['receiptLines']) && is_array($receipt['receiptLines']) ? array_values($receipt['receiptLines']) : [];
    if (!$lines) respond(['ok' => false, 'error' => '這張進貨單沒有可刪除的品項'], 409);
    $matchSkuId = receipt_text($payload['skuId'] ?? $payload['sku'] ?? '');
    $matchBarcode = strtoupper(receipt_text($payload['barcode'] ?? ''));
    $matchColor = receipt_text($payload['color'] ?? $payload['colorName'] ?? '');
    $matchSize = receipt_text($payload['size'] ?? $payload['sizeName'] ?? '');
    $lineIndex = -1;
    if ($requestedIndex >= 0 && $requestedIndex < count($lines) && is_array($lines[$requestedIndex])) {
        $candidate = $lines[$requestedIndex];
        $candidateSku = receipt_first_text([$candidate['skuId'] ?? '', $candidate['sku'] ?? '']);
        $candidateBarcode = strtoupper(receipt_first_text([$candidate['barcode'] ?? '', $candidate['companyBarcode'] ?? '']));
        $candidateColor = receipt_text($candidate['color'] ?? $candidate['colorName'] ?? '');
        $candidateSize = receipt_text($candidate['size'] ?? $candidate['sizeName'] ?? '');
        $skuOk = $matchSkuId === '' || $candidateSku === '' || $candidateSku === $matchSkuId;
        $barcodeOk = $matchBarcode === '' || $candidateBarcode === '' || $candidateBarcode === $matchBarcode;
        $colorOk = $matchColor === '' || $candidateColor === '' || mb_strtolower($candidateColor, 'UTF-8') === mb_strtolower($matchColor, 'UTF-8');
        $sizeOk = $matchSize === '' || $candidateSize === '' || mb_strtolower($candidateSize, 'UTF-8') === mb_strtolower($matchSize, 'UTF-8');
        if ($skuOk && $barcodeOk && $colorOk && $sizeOk) $lineIndex = $requestedIndex;
    }
    if ($lineIndex < 0) {
        foreach ($lines as $candidateIndex => $candidate) {
            if (!is_array($candidate)) continue;
            $candidateSku = receipt_first_text([$candidate['skuId'] ?? '', $candidate['sku'] ?? '']);
            $candidateBarcode = strtoupper(receipt_first_text([$candidate['barcode'] ?? '', $candidate['companyBarcode'] ?? '']));
            $candidateColor = receipt_text($candidate['color'] ?? $candidate['colorName'] ?? '');
            $candidateSize = receipt_text($candidate['size'] ?? $candidate['sizeName'] ?? '');
            $skuOk = $matchSkuId === '' || $candidateSku === $matchSkuId;
            $barcodeOk = $matchBarcode === '' || $candidateBarcode === $matchBarcode;
            $colorOk = $matchColor === '' || mb_strtolower($candidateColor, 'UTF-8') === mb_strtolower($matchColor, 'UTF-8');
            $sizeOk = $matchSize === '' || mb_strtolower($candidateSize, 'UTF-8') === mb_strtolower($matchSize, 'UTF-8');
            if ($skuOk && $barcodeOk && $colorOk && $sizeOk) {
                $lineIndex = (int)$candidateIndex;
                break;
            }
        }
    }
    if ($lineIndex < 0 || !is_array($lines[$lineIndex] ?? null)) {
        respond(['ok' => false, 'error' => '找不到要刪除的進貨品項，品項仍留在單上'], 404);
    }
    $line = $lines[$lineIndex];
    $isLastLine = count($lines) === 1;
    if ($isLastLine && !$confirmVoid) {
        respond(['ok' => false, 'error' => '這是最後一個品項。刪除後整張進貨單會作廢，請確認後再刪', 'needsVoidConfirm' => true], 409);
    }

    $doc = is_array($receipt['receivingDocument'] ?? null) ? $receipt['receivingDocument'] : [];
    $warehouse = strtoupper(receipt_first_text([$line['warehouse'] ?? '', $doc['arrivalWarehouse'] ?? '', $receipt['arrivalWarehouse'] ?? '', 'TW']));
    if (!in_array($warehouse, ['TW', 'CN', 'ID'], true)) $warehouse = 'TW';
    $profile = receipt_warehouse_profile($warehouse);
    $reverseQty = purchase_receipt_line_sellable_qty($line);
    $stockPosted = in_array($status, ['received', 'confirming'], true);
    $hasFreightReference = receipt_text($receipt['freightItemId'] ?? '') !== ''
        || receipt_text($receipt['freightBatchId'] ?? '') !== ''
        || receipt_text($receipt['freightTrackingNo'] ?? '') !== '';
    $freightFileLock = null;
    $freightData = [];
    if ($isLastLine && $hasFreightReference) {
        $freightFileLock = acquire_freight_file_lock($freightTrackingFile);
        $freightData = read_json($freightTrackingFile);
    }

    $skuId = receipt_first_text([$line['skuId'] ?? '', $line['sku'] ?? '']);
    $stockBefore = null;
    $stockAfter = null;
    $reversed = false;
    $idempotentReplay = false;
    $skusChanged = false;
    $catalogLock = null;
    $skus = [];
    $operationId = receipt_text($receipt['operationId'] ?? $receipt['inventoryOperationId'] ?? '');
    $deleteOpId = 'purchase-receipt-line-delete:' . hash('sha256', purchase_receipt_line_identity_key($line, $lineIndex) . '|' . $documentId);

    if ($stockPosted && $reverseQty > 0) {
        $catalogLock = acquire_inventory_file_lock($skusFile);
        $skus = read_json($skusFile);
        $skuIndex = purchase_receipt_find_sku_index($skus, $line, $profile);
        if ($skuIndex < 0) {
            respond(['ok' => false, 'error' => '找不到此品項對應的倉庫 SKU，已停止刪除，品項仍留在進貨單上，庫存未改'], 409);
        }
        $skuId = receipt_first_text([$skus[$skuIndex]['id'] ?? '', $skus[$skuIndex]['sku'] ?? '', $skuId]);
        $existingDelete = inventory_transaction_marker($skus[$skuIndex], $deleteOpId);
        $stockBefore = (int)($skus[$skuIndex]['stock'] ?? 0);
        $originalMarker = $operationId !== '' ? inventory_transaction_marker($skus[$skuIndex], $operationId) : null;
        if (!$existingDelete && $status === 'confirming' && !is_array($originalMarker)) {
            $reverseQty = 0;
            $stockAfter = $stockBefore;
            $reversed = true;
        } elseif (!$existingDelete && $status === 'received' && !is_array($originalMarker)) {
            respond(['ok' => false, 'error' => '找不到此品項的入庫交易記號，已停止刪除，品項仍留在進貨單上，庫存未改'], 409);
        } elseif ($existingDelete) {
            $idempotentReplay = true;
            $stockAfter = $stockBefore;
            $reversed = true;
        } elseif ($reverseQty > 0) {
            if ($stockBefore < $reverseQty) {
                respond([
                    'ok' => false,
                    'error' => '無法刪除：' . $profile['name'] . '現有庫存不足以扣回此品項（SKU ' . $skuId . ' 需扣回 ' . $reverseQty . ' 件，目前僅 ' . $stockBefore . ' 件）。品項仍留在進貨單上，庫存未改',
                    'skuId' => $skuId,
                    'neededQty' => $reverseQty,
                    'stock' => $stockBefore,
                ], 409);
            }
            $stockAfter = $stockBefore - $reverseQty;
            $skus[$skuIndex]['stock'] = $stockAfter;
            $skus[$skuIndex]['updatedAt'] = $now;
            $reverseLine = [
                'kind' => 'purchase_receipt_line_delete',
                'documentId' => $documentId,
                'lineIndex' => $lineIndex,
                'skuId' => $skuId,
                'barcode' => receipt_first_text([$line['barcode'] ?? '', $skus[$skuIndex]['companyBarcode'] ?? '']),
                'color' => receipt_text($line['color'] ?? $line['colorName'] ?? ''),
                'size' => receipt_text($line['size'] ?? $line['sizeName'] ?? 'NO SIZE') ?: 'NO SIZE',
                'warehouse' => $profile['code'],
                'qty' => $reverseQty,
                'qtyDelta' => -$reverseQty,
                'stockBefore' => $stockBefore,
                'stockAfter' => $stockAfter,
                'originalOperationId' => $operationId,
                'deletedBy' => $operatorName,
                'deletedAt' => $now,
            ];
            set_inventory_transaction_marker($skus[$skuIndex], $deleteOpId, [
                'kind' => 'purchase_receipt_line_delete',
                'inquiryId' => $documentId,
                'payloadHash' => $deleteOpId,
                'qtyDelta' => -$reverseQty,
                'appliedAt' => $now,
                'lines' => [$reverseLine],
            ]);
            if ($operationId !== '') {
                $originalMarker = inventory_transaction_marker($skus[$skuIndex], $operationId);
                if (is_array($originalMarker)) {
                    $originalMarker['qtyDelta'] = max(0, (int)($originalMarker['qtyDelta'] ?? 0) - $reverseQty);
                    $originalLines = isset($originalMarker['lines']) && is_array($originalMarker['lines']) ? array_values($originalMarker['lines']) : [];
                    $kept = [];
                    foreach ($originalLines as $markerLine) {
                        if (!is_array($markerLine)) continue;
                        $sameSku = receipt_first_text([$markerLine['skuId'] ?? '']) === $skuId;
                        $sameIndex = (int)($markerLine['itemIndex'] ?? -1) === $lineIndex;
                        if ($sameSku && ($sameIndex || count($originalLines) === 1)) continue;
                        $kept[] = $markerLine;
                    }
                    $originalMarker['lines'] = $kept;
                    $history = is_array($originalMarker['lineDeleteHistory'] ?? null) ? array_values($originalMarker['lineDeleteHistory']) : [];
                    $history[] = $reverseLine + ['deleteOperationId' => $deleteOpId];
                    $originalMarker['lineDeleteHistory'] = array_slice($history, -50);
                    set_inventory_transaction_marker($skus[$skuIndex], $operationId, $originalMarker);
                }
            }
            if (is_array($skus[$skuIndex]['freightCostLots'] ?? null)) {
                $need = $reverseQty;
                foreach ($skus[$skuIndex]['freightCostLots'] as $lotIndex => $lot) {
                    if ($need <= 0 || !is_array($lot)) continue;
                    $lotQty = max(0, (int)($lot['qty'] ?? 0));
                    if ($lotQty <= 0) continue;
                    $take = min($lotQty, $need);
                    $skus[$skuIndex]['freightCostLots'][$lotIndex]['qty'] = $lotQty - $take;
                    $skus[$skuIndex]['freightCostLots'][$lotIndex]['lineDeletedAt'] = $now;
                    $need -= $take;
                }
            }
            $skusChanged = true;
            $reversed = true;
        }
    } elseif ($stockPosted && $reverseQty <= 0) {
        $reversed = true;
    } else {
        $reversed = true;
    }

    array_splice($lines, $lineIndex, 1);
    $totalQty = 0;
    $totalCost = 0.0;
    foreach ($lines as $remaining) {
        if (!is_array($remaining)) continue;
        $q = max(0, (int)($remaining['qty'] ?? 0));
        $totalQty += $q;
        $totalCost += $q * max(0, (float)($remaining['unitCostTwd'] ?? 0));
    }
    $voided = $isLastLine;
    $receipt['receiptLines'] = $lines;
    $receipt['lineCount'] = count($lines);
    $receipt['totalQty'] = $totalQty;
    $receipt['totalCostTwd'] = round($totalCost, 2);
    $receipt['updatedAt'] = $now;
    $deleteHistory = is_array($receipt['lineDeleteHistory'] ?? null) ? array_values($receipt['lineDeleteHistory']) : [];
    $deleteHistory[] = [
        'deleteOperationId' => $deleteOpId,
        'lineIndex' => $lineIndex,
        'skuId' => $skuId,
        'barcode' => receipt_text($line['barcode'] ?? ''),
        'productCode' => receipt_text($line['productCode'] ?? ''),
        'productName' => receipt_text($line['productName'] ?? ''),
        'color' => receipt_text($line['color'] ?? $line['colorName'] ?? ''),
        'size' => receipt_text($line['size'] ?? $line['sizeName'] ?? ''),
        'qty' => max(0, (int)($line['qty'] ?? 0)),
        'reversedQty' => $reverseQty,
        'warehouse' => $profile['code'],
        'deletedBy' => $operatorName,
        'deletedAt' => $now,
        'voidedDocument' => $voided,
    ];
    $receipt['lineDeleteHistory'] = array_slice($deleteHistory, -50);
    if ($voided) {
        $receipt['status'] = 'voided';
        $receipt['voidedAt'] = $now;
        $receipt['voidedBy'] = $operatorName;
        $receipt['voidReason'] = '最後一個進貨品項已刪除，整張進貨單作廢並扣回庫存';
        if (is_array($receipt['receivingDocument'] ?? null)) {
            $receipt['receivingDocument']['status'] = 'voided';
            $receipt['receivingDocument']['voidedAt'] = $now;
        }
    }

    $freightChanged = false;
    if ($voided && $hasFreightReference) {
        $freightChanged = purchase_receipt_release_matching_freight_claim($freightData, $receipt, $now);
    }

    if ($skusChanged) {
        write_json_compact($skusFile, $skus);
        sync_preorder_inventory_state($stateFile, $skus, null, $deleteOpId, [
            'kind' => 'purchase_receipt_line_delete',
            'inquiryId' => $documentId,
            'payloadHash' => $deleteOpId,
            'appliedAt' => $now,
            'skuIds' => array_values(array_filter([$skuId])),
        ]);
        @unlink($GLOBALS['dataDir'] . DIRECTORY_SEPARATOR . 'public-catalog.json');
    }
    $rows[$index] = $receipt;
    write_json_compact($purchaseReceiptsFile, array_values($rows));
    if ($freightChanged) {
        $freightData['revision'] = max(0, (int)($freightData['revision'] ?? 0)) + 1;
        $freightData['updatedAt'] = $now;
        write_json($freightTrackingFile, $freightData);
    }

    $label = receipt_first_text([$line['productCode'] ?? '', $line['productName'] ?? '', $skuId]);
    respond([
        'ok' => true,
        'receipt' => purchase_receipt_client_view($receipt),
        'voided' => $voided,
        'deletedLine' => [
            'skuId' => $skuId,
            'barcode' => receipt_text($line['barcode'] ?? ''),
            'productCode' => receipt_text($line['productCode'] ?? ''),
            'productName' => receipt_text($line['productName'] ?? ''),
            'color' => receipt_text($line['color'] ?? $line['colorName'] ?? ''),
            'size' => receipt_text($line['size'] ?? $line['sizeName'] ?? ''),
            'qty' => max(0, (int)($line['qty'] ?? 0)),
        ],
        'reversedQty' => $reverseQty,
        'skuId' => $skuId,
        'warehouse' => $profile['code'],
        'stockBefore' => $stockBefore,
        'stockAfter' => $stockAfter,
        'idempotentReplay' => $idempotentReplay,
        'message' => $voided
            ? ('已刪除最後一項「' . $label . '」，整張進貨單已作廢，並扣回 ' . $reverseQty . ' 件庫存')
            : ('已從進貨單刪除「' . $label . '」，並扣回 ' . $reverseQty . ' 件庫存'),
    ]);
}

if ($action === 'save-purchase-receipt' || $action === 'confirm-purchase-receipt') {
    $documentInput = isset($payload['receivingDocument']) && is_array($payload['receivingDocument']) ? $payload['receivingDocument'] : [];
    $documentNo = trim((string)($documentInput['documentNo'] ?? ''));
    $documentDate = trim((string)($documentInput['documentDate'] ?? date('Y-m-d')));
    $supplier = trim((string)($documentInput['supplier'] ?? ''));
    $operatorName = trim((string)($documentInput['operatorName'] ?? $payload['receivedBy'] ?? ''));
    $warehouse = strtoupper(trim((string)($documentInput['arrivalWarehouse'] ?? $payload['arrivalWarehouse'] ?? 'TW')));
    if (!in_array($warehouse, ['TW', 'CN', 'ID'], true)) $warehouse = 'TW';
    if ($documentNo === '' || $supplier === '' || $operatorName === '') respond(['ok' => false, 'error' => '請補齊進貨單號、廠商與經手人'], 400);
    $incomingLines = isset($payload['receiptLines']) && is_array($payload['receiptLines']) ? array_values($payload['receiptLines']) : [];
    if (!$incomingLines) respond(['ok' => false, 'error' => '請至少加入一個進貨項目'], 400);
    $cleanLines = [];
    foreach ($incomingLines as $lineIndex => $line) {
        $line = is_array($line) ? $line : [];
        $rawQty = $line['qty'] ?? $line['quantity'] ?? null;
        if ($action === 'confirm-purchase-receipt' && (!is_numeric($rawQty) || (float)$rawQty <= 0 || floor((float)$rawQty) !== (float)$rawQty)) {
            respond(['ok' => false, 'error' => '第 ' . ($lineIndex + 1) . ' 個進貨品項數量必須是正整數'], 400);
        }
        $cleanLines[] = [
            'skuId' => trim((string)($line['skuId'] ?? '')),
            'productId' => trim((string)($line['productId'] ?? '')),
            'productName' => trim((string)($line['productName'] ?? '')),
            'category' => trim((string)($line['category'] ?? '')),
            'productCode' => trim((string)($line['productCode'] ?? '')),
            'barcode' => trim((string)($line['barcode'] ?? '')),
            'color' => trim((string)($line['color'] ?? '')),
            'size' => trim((string)($line['size'] ?? 'NO SIZE')) ?: 'NO SIZE',
            'qty' => max(1, (int)($rawQty ?? 1)),
            'unitCostTwd' => max(0, round((float)($line['unitCostTwd'] ?? 0), 2)),
            'arrivalImage' => trim((string)($line['arrivalImage'] ?? '')),
            'proofRequired' => !empty($line['proofRequired']),
        ];
    }
    if ($action === 'confirm-purchase-receipt') {
        foreach ($cleanLines as $line) {
            $newProductMissingIdentity = $line['productId'] === '' && ($line['category'] === '' || $line['productCode'] === '');
            if ($newProductMissingIdentity || $line['barcode'] === '' || $line['color'] === '' || $line['size'] === '') {
                respond(['ok' => false, 'error' => '正式入庫前，每個品項都必須補齊產品分類、產品編號、公司條碼、顏色與尺寸'], 400);
            }
        }
    }
    $document = [
        'documentNo' => $documentNo,
        'documentDate' => $documentDate,
        'sourceOrderNo' => trim((string)($documentInput['sourceOrderNo'] ?? '')),
        'supplier' => $supplier,
        'purchasePlatform' => trim((string)($documentInput['purchasePlatform'] ?? '')),
        'operatorName' => $operatorName,
        'arrivalWarehouse' => $warehouse,
        'note' => trim((string)($documentInput['note'] ?? '')),
    ];
    // Lock order for a confirmed standalone receipt is always:
    // receipts file -> immutable document id -> freight file -> catalog/inventory.
    // The global receipts lock is required because every document shares one JSON file.
    $receiptsFileLock = acquire_purchase_receipts_file_lock($purchaseReceiptsFile);
    $rows = read_json($purchaseReceiptsFile);
    $requestedDocumentId = trim((string)($payload['documentId'] ?? ''));
    $canonicalDocumentNo = mb_strtolower($documentNo, 'UTF-8');
    $indexById = -1;
    $indexByNo = -1;
    foreach ($rows as $rowIndex => $row) {
        if (!is_array($row)) continue;
        $rowId = trim((string)($row['id'] ?? ''));
        $rowDocument = is_array($row['receivingDocument'] ?? null) ? $row['receivingDocument'] : [];
        $rowDocumentNo = receipt_first_text([$rowDocument['documentNo'] ?? '', $row['documentNo'] ?? '']);
        if ($requestedDocumentId !== '' && $rowId === $requestedDocumentId) $indexById = $rowIndex;
        if ($rowDocumentNo !== '' && mb_strtolower($rowDocumentNo, 'UTF-8') === $canonicalDocumentNo) $indexByNo = $rowIndex;
    }
    if ($requestedDocumentId !== '' && $indexById < 0) respond(['ok' => false, 'error' => '找不到這張採購進貨單，請重新載入後再試'], 404);
    if ($indexById >= 0 && $indexByNo >= 0 && $indexById !== $indexByNo) respond(['ok' => false, 'error' => '進貨單號 ' . $documentNo . ' 已被另一張進貨單使用'], 409);
    $index = $indexById >= 0 ? $indexById : $indexByNo;
    $now = date(DATE_ATOM);
    $documentId = $index >= 0 ? trim((string)($rows[$index]['id'] ?? '')) : '';
    if ($documentId === '') $documentId = 'PURCHASE-REC-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $receiptOperationLock = acquire_purchase_receipt_lock($purchaseReceiptsFile, $documentId);
    $currentStatus = $index >= 0 ? (string)($rows[$index]['status'] ?? '') : '';
    if ($currentStatus === 'reconciliation_required') respond(['ok' => false, 'error' => '這張進貨單正在人工核對，確認庫存前不能再儲存或入庫'], 409);
    $immutableOperationId = 'purchase-receipt-confirm:' . hash('sha256', mb_strtolower($documentId, 'UTF-8'));
    $savedOperationId = $index >= 0 ? trim((string)($rows[$index]['operationId'] ?? '')) : '';
    // Preserve a prior operation identity only for an in-flight/completed record,
    // so receipts committed by the immediately preceding protocol remain replayable.
    $operationId = in_array($currentStatus, ['confirming', 'received'], true) && $savedOperationId !== '' ? $savedOperationId : $immutableOperationId;
    $operationPayloadHash = preorder_operation_payload_hash([
        'document' => $document,
        'receiptLines' => $cleanLines,
        'freightTrackingNo' => trim((string)($payload['freightTrackingNo'] ?? '')),
        'freightItemId' => trim((string)($payload['freightItemId'] ?? '')),
        'freightBatchId' => trim((string)($payload['freightBatchId'] ?? '')),
        'freightForwarder' => trim((string)($payload['freightForwarder'] ?? '')),
        'freightCostMode' => trim((string)($payload['freightCostMode'] ?? '')),
        'freightWarehouseTaxType' => trim((string)($payload['freightWarehouseTaxType'] ?? '')),
        'freightChargeType' => trim((string)($payload['freightChargeType'] ?? '')),
        'freightAmountTwd' => max(0, round((float)($payload['freightAmountTwd'] ?? 0), 2)),
        'freightAllocationTwd' => max(0, round((float)($payload['freightAllocationTwd'] ?? 0), 2)),
    ]);
    if ($action === 'confirm-purchase-receipt') {
        $referenceConflict = formal_purchase_receipt_reference_conflict(
            $rows,
            $documentId,
            $operationId,
            trim((string)($payload['freightItemId'] ?? '')),
            trim((string)($payload['freightBatchId'] ?? '')),
            trim((string)($payload['freightTrackingNo'] ?? ''))
        );
        if ($referenceConflict) {
            respond([
                'ok' => false,
                'error' => '這筆物流產品已由另一張正式進貨單領用，不能再次增加庫存',
                'conflict' => $referenceConflict,
            ], 409);
        }
    }
    $idempotentReceived = false;
    if ($currentStatus === 'received') {
        $savedHash = (string)($rows[$index]['operationPayloadHash'] ?? '');
        if ($action === 'confirm-purchase-receipt' && $savedHash !== '' && hash_equals($savedHash, $operationPayloadHash)) {
            $idempotentReceived = true;
        } else {
            respond(['ok' => false, 'error' => '這張進貨單已完成入庫，不能再次增加庫存'], 409);
        }
    }
    if ($currentStatus === 'confirming' && $action === 'save-purchase-receipt') respond(['ok' => false, 'error' => '這張進貨單有未完成的入庫作業，請先重試確認或由管理者核對'], 409);
    $totalQty = array_reduce($cleanLines, static fn($sum, $line) => $sum + (int)$line['qty'], 0);
    $totalCost = array_reduce($cleanLines, static fn($sum, $line) => $sum + ((int)$line['qty'] * (float)$line['unitCostTwd']), 0.0);
    $record = [
        'id' => $documentId,
        'status' => $action === 'confirm-purchase-receipt' ? 'confirming' : 'draft',
        'receivingDocument' => $document,
        'receiptLines' => $cleanLines,
        'freightTrackingNo' => trim((string)($payload['freightTrackingNo'] ?? '')),
        'freightItemId' => trim((string)($payload['freightItemId'] ?? '')),
        'freightBatchId' => trim((string)($payload['freightBatchId'] ?? '')),
        'freightForwarder' => trim((string)($payload['freightForwarder'] ?? '')),
        'freightCostMode' => trim((string)($payload['freightCostMode'] ?? '')),
        'freightWarehouseTaxType' => trim((string)($payload['freightWarehouseTaxType'] ?? '')),
        'freightChargeType' => trim((string)($payload['freightChargeType'] ?? '')),
        'freightAmountTwd' => max(0, round((float)($payload['freightAmountTwd'] ?? 0), 2)),
        'freightAllocationTwd' => max(0, round((float)($payload['freightAllocationTwd'] ?? 0), 2)),
        'lineCount' => count($cleanLines),
        'totalQty' => $totalQty,
        'totalCostTwd' => round($totalCost, 2),
        'operationId' => $operationId,
        'operationPayloadHash' => $operationPayloadHash,
        'operationProtocolVersion' => 2,
        'createdAt' => $index >= 0 ? (string)($rows[$index]['createdAt'] ?? $now) : $now,
        'updatedAt' => $now,
    ];
    if ($action === 'save-purchase-receipt') {
        if ($index >= 0) $rows[$index] = $record; else array_unshift($rows, $record);
        write_json($purchaseReceiptsFile, array_values($rows));
        respond(['ok' => true, 'receipt' => $record]);
    }

    $batchId = $record['freightBatchId'];
    // freightBatchId is often carried as context together with an exact item.
    // Only a receipt without an exact item is allowed to claim/close the whole
    // consolidation batch.
    $claimsWholeBatch = receipt_text($record['freightItemId'] ?? '') === '' && $batchId !== '';
    $hasFreightReference = $batchId !== '' || $record['freightItemId'] !== '' || $record['freightTrackingNo'] !== '';
    $freightFileLock = $hasFreightReference ? acquire_freight_file_lock($freightTrackingFile) : null;
    $freightData = $hasFreightReference ? read_json($freightTrackingFile) : [];
    $legacyConfirmingCandidate = $currentStatus === 'confirming' && (int)($rows[$index]['operationProtocolVersion'] ?? 0) < 2;
    $lockedFreightSnapshot = $hasFreightReference ? freight_receipt_snapshot($payload, $freightTrackingFile) : [];
    // Do not silently promote an item/tracking-number receipt into a claim on
    // its whole consolidation batch.  A batch is claimed only when the form
    // explicitly submitted freightBatchId; otherwise the receipt owns the
    // selected item or parcel tracking reference.
    // An explicit item id is authoritative.  Older receipt forms may only
    // carry a batch or tracking number, so inspect every item under that
    // reference instead of trusting the first snapshot match.
    $formalClaimConflict = freight_product_receive_formal_claim_conflict(
        $freightData,
        receipt_text($record['freightItemId'] ?? ''),
        $batchId,
        receipt_first_text([$record['freightTrackingNo'] ?? '', $lockedFreightSnapshot['trackingNo'] ?? '']),
        $operationId
    );
    if ($formalClaimConflict) {
        respond(['ok' => false, 'error' => '這筆物流產品已由產品建檔正式入庫，不能再用採購進貨單增加庫存', 'conflict' => $formalClaimConflict], 409);
    }
    $freightBatchIndex = -1;
    if ($batchId !== '' && isset($freightData['batches']) && is_array($freightData['batches'])) {
        foreach ($freightData['batches'] as $batchIndex => $batch) {
            if (!is_array($batch) || (string)($batch['id'] ?? '') !== $batchId) continue;
            $freightBatchIndex = $batchIndex;
            if ($claimsWholeBatch && !$idempotentReceived && !$legacyConfirmingCandidate) {
                $packageRows = isset($batch['packageRows']) && is_array($batch['packageRows']) ? $batch['packageRows'] : [];
                $receiving = isset($batch['receiving']) && is_array($batch['receiving']) ? $batch['receiving'] : [];
                if (!$packageRows) respond(['ok' => false, 'error' => '這個集運批次沒有物流物件明細，請先重新綁入物流單號後再驗收入庫'], 409);
                if (empty($receiving['startedAt'])) respond(['ok' => false, 'error' => '這個集運批次尚未開始到台灣點貨，請先到物流集運完成點貨'], 409);
                if (empty($receiving['completedAt'])) respond(['ok' => false, 'error' => '這個集運批次仍在點貨中，尚不能正式入庫'], 409);
                if ((int)($receiving['missingQty'] ?? 0) > 0 || (string)($batch['status'] ?? '') === '缺件待處理') respond(['ok' => false, 'error' => '這個集運批次仍有缺件，補點完成前不能正式入庫'], 409);
            }
            break;
        }
    }
    if ($batchId !== '' && $freightBatchIndex < 0 && !$legacyConfirmingCandidate) {
        respond(['ok' => false, 'error' => '找不到這個物流集運批次，請重新帶入有效批號後再確認入庫'], 409);
    }

    // Hold the catalog lock before inspecting durable markers and keep it for
    // every products/skus mutation performed by this endpoint.
    $catalogLock = acquire_inventory_file_lock($skusFile);
    $existingOperationEntries = inventory_transaction_marker_entries(read_json($skusFile), $operationId);
    if ($currentStatus === 'confirming') {
        $savedHash = (string)($rows[$index]['operationPayloadHash'] ?? '');
        $protocolVersion = (int)($rows[$index]['operationProtocolVersion'] ?? 0);
        if (!$existingOperationEntries && $protocolVersion < 2) {
            $rows[$index]['status'] = 'reconciliation_required';
            $rows[$index]['reconciliationReason'] = 'legacy_confirming_without_inventory_marker';
            $rows[$index]['reconciliationRequestedAt'] = $now;
            $rows[$index]['updatedAt'] = $now;
            write_json($purchaseReceiptsFile, array_values($rows));
            respond(['ok' => false, 'error' => '這張舊進貨單曾中斷於確認中，無法判定庫存是否已增加；已轉人工核對，不會自動重複入庫'], 409);
        }
        if ($existingOperationEntries && ($savedHash === '' || !hash_equals($savedHash, $operationPayloadHash))) {
            respond(['ok' => false, 'error' => '這張進貨單已用另一組內容增加庫存，請由管理者先核對'], 409);
        }
        // Protocol v2 confirming rows without a marker are durable intents that
        // did not reach the atomic SKU commit; they may safely replace their payload.
    }
    if ($idempotentReceived && !$existingOperationEntries) {
        $rows[$index]['reconciliationRequired'] = true;
        $rows[$index]['reconciliationReason'] = 'received_without_inventory_marker';
        $rows[$index]['reconciliationRequestedAt'] = $now;
        $rows[$index]['updatedAt'] = $now;
        write_json($purchaseReceiptsFile, array_values($rows));
        respond(['ok' => false, 'error' => '這張進貨單已標示入庫，但找不到庫存交易記號；已轉人工核對，不會自動再加庫存'], 409);
    }

    // Persist the immutable identity before the freight claim. This closes the
    // crash window in which a brand-new request could otherwise leave a claim
    // whose generated document id was never discoverable on retry.
    if (!$idempotentReceived) {
        if ($index >= 0) $rows[$index] = $record; else { array_unshift($rows, $record); $index = 0; }
        write_json($purchaseReceiptsFile, array_values($rows));
    }

    if ($claimsWholeBatch && $freightBatchIndex >= 0) {
        $batch = $freightData['batches'][$freightBatchIndex];
        $claim = isset($batch['inventoryReceiptClaim']) && is_array($batch['inventoryReceiptClaim']) ? $batch['inventoryReceiptClaim'] : [];
        $claimOperationId = trim((string)($claim['operationId'] ?? ''));
        $claimDocumentId = trim((string)($claim['documentId'] ?? ''));
        $claimHash = trim((string)($claim['payloadHash'] ?? ''));
        $legacyReceived = !empty($batch['inventoryReceivedAt']) || (string)($batch['status'] ?? '') === '已入庫' || trim((string)($batch['receivingDocumentNo'] ?? '')) !== '';
        if ($claimOperationId !== '' && ($claimOperationId !== $operationId || ($claimDocumentId !== '' && $claimDocumentId !== $documentId))) {
            respond(['ok' => false, 'error' => '這個物流集運批次已被另一張進貨單領用，不能重複入庫'], 409);
        }
        if ($claimOperationId === '' && $legacyReceived) {
            $legacyDocumentNo = mb_strtolower(trim((string)($batch['receivingDocumentNo'] ?? '')), 'UTF-8');
            if (!$idempotentReceived || $legacyDocumentNo === '' || $legacyDocumentNo !== $canonicalDocumentNo) {
                respond(['ok' => false, 'error' => '這個物流集運批次已完成入庫，請先核對原進貨單'], 409);
            }
        }
        if ($claimHash !== '' && !hash_equals($claimHash, $operationPayloadHash) && $existingOperationEntries) {
            respond(['ok' => false, 'error' => '這個物流集運批次已用不同入庫內容完成庫存交易'], 409);
        }
        $freightData['batches'][$freightBatchIndex]['inventoryReceiptClaim'] = [
            'operationId' => $operationId,
            'documentId' => $documentId,
            'documentNo' => $documentNo,
            'payloadHash' => $operationPayloadHash,
            'status' => $idempotentReceived ? 'received' : 'claimed',
            'claimedAt' => receipt_first_text([$claim['claimedAt'] ?? '', $now]),
            'updatedAt' => $now,
        ];
        $freightData['updatedAt'] = $now;
        write_json($freightTrackingFile, $freightData);
    }

    $pseudoInquiry = [
        'id' => $documentId,
        'purchasePlatform' => $document['purchasePlatform'],
        'platform' => $document['purchasePlatform'],
        'items' => array_map(static function ($line): array {
            return [
                'skuId' => $line['skuId'], 'sku' => $line['skuId'], 'productId' => $line['productId'],
                'code' => $line['productCode'], 'title' => $line['productName'] !== '' ? $line['productName'] : $line['productCode'], 'category' => $line['category'], 'color' => $line['color'],
                'size' => $line['size'], 'qty' => $line['qty'], 'arrivalImage' => $line['arrivalImage'],
            ];
        }, $cleanLines),
    ];
    $receipt = apply_preorder_receipt_inventory($pseudoInquiry, $payload + ['receiptLines' => $cleanLines], $warehouse, $productsFile, $skusFile, $stateFile, $freightTrackingFile, $operationId, $operationPayloadHash, true);
    $record['status'] = 'received';
    $record['receiptLines'] = $receipt['receiptLog'];
    $record['receivingDocument']['status'] = 'received';
    $receivedAt = $idempotentReceived ? (string)($rows[$index]['receivedAt'] ?? $now) : $now;
    $record['receivingDocument']['receivedAt'] = $receivedAt;
    $record['receivedAt'] = $receivedAt;
    $record['inventoryOperationId'] = $operationId;
    $record['updatedAt'] = $now;
    if ($index >= 0) $rows[$index] = $record; else { array_unshift($rows, $record); $index = 0; }
    write_json($purchaseReceiptsFile, array_values($rows));
    $freightChanged = false;
    $receivedFreightItemIds = [];
    if ($hasFreightReference && isset($freightData['items']) && is_array($freightData['items'])) {
        $explicitFreightItemId = receipt_text($record['freightItemId'] ?? '');
        $receiptTrackingNo = receipt_first_text([$record['freightTrackingNo'] ?? '', $lockedFreightSnapshot['trackingNo'] ?? '']);
        foreach ($freightData['items'] as &$freightItemRow) {
            if (!is_array($freightItemRow)) continue;
            $matchesFreightReceipt = false;
            if ($explicitFreightItemId !== '') {
                $matchesFreightReceipt = receipt_text($freightItemRow['id'] ?? '') === $explicitFreightItemId;
            } elseif ($batchId !== '') {
                $matchesFreightReceipt = receipt_text($freightItemRow['batchId'] ?? '') === $batchId;
            } elseif ($receiptTrackingNo !== '') {
                $matchesFreightReceipt = in_array($receiptTrackingNo, array_map('strval', [
                    $freightItemRow['trackingNo'] ?? '',
                    $freightItemRow['haohongTrackingNo'] ?? '',
                ]), true);
            }
            if (!$matchesFreightReceipt) continue;
            $matchedFreightItemId = receipt_text($freightItemRow['id'] ?? '');
            if ($matchedFreightItemId !== '') $receivedFreightItemIds[$matchedFreightItemId] = true;
            $savedItemClaim = isset($freightItemRow['inventoryReceiptClaim']) && is_array($freightItemRow['inventoryReceiptClaim']) ? $freightItemRow['inventoryReceiptClaim'] : [];
            $freightItemRow['inventoryReceiptClaim'] = [
                'claimType' => 'formal_purchase_receipt',
                'operationId' => $operationId,
                'documentId' => $documentId,
                'documentNo' => $documentNo,
                'payloadHash' => $operationPayloadHash,
                'status' => 'received',
                'warehouse' => $warehouse,
                'claimedAt' => receipt_first_text([$savedItemClaim['claimedAt'] ?? '', $receivedAt]),
                'receivedAt' => $receivedAt,
                'updatedAt' => $now,
            ];
            $freightItemRow['inventoryReceivedAt'] = $receivedAt;
            $freightItemRow['inventoryReceivedWarehouse'] = $warehouse;
            $freightItemRow['inventoryStatus'] = 'received';
            $freightItemRow['inventoryOperationId'] = $operationId;
            $freightItemRow['receivingDocumentId'] = $documentId;
            $freightItemRow['receivingDocumentNo'] = $documentNo;
            $freightItemRow['receivingStage'] = 'inventory_received';
            $freightItemRow['receivingStatus'] = '已完成入庫';
            $freightItemRow['freightReceivingStatus'] = '已完成入庫';
            $freightItemRow['progress'] = '已入庫';
            $formalDestinationWarehouse = $warehouse === 'CN' ? 'CN_DONGGUAN' : ($warehouse === 'TW' ? 'TW_BAOHUI' : 'ID_DIRECT');
            $freightItemRow['destinationWarehouse'] = $formalDestinationWarehouse;
            $freightItemRow['destinationSite'] = freight_destination_site($formalDestinationWarehouse);
            $itemHistory = isset($freightItemRow['statusHistory']) && is_array($freightItemRow['statusHistory']) ? array_values($freightItemRow['statusHistory']) : [];
            $hasItemHistory = false;
            foreach ($itemHistory as $historyEntry) {
                if (is_array($historyEntry) && receipt_text($historyEntry['operationId'] ?? '') === $operationId) { $hasItemHistory = true; break; }
            }
            if (!$hasItemHistory) $itemHistory[] = ['operationId' => $operationId, 'status' => '已入庫', 'at' => $receivedAt, 'warehouse' => $warehouse, 'receivingDocumentNo' => $documentNo];
            $freightItemRow['statusHistory'] = $itemHistory;
            $freightItemRow['updatedAt'] = $now;
            $freightChanged = true;
        }
        unset($freightItemRow);
    }

    if ($receivedFreightItemIds && isset($freightData['batches']) && is_array($freightData['batches'])) {
        foreach ($freightData['batches'] as &$receiptBatchRow) {
            if (!is_array($receiptBatchRow)) continue;
            $packageRows = isset($receiptBatchRow['packageRows']) && is_array($receiptBatchRow['packageRows']) ? array_values($receiptBatchRow['packageRows']) : [];
            $batchTouched = false;
            foreach ($packageRows as &$packageRow) {
                if (!is_array($packageRow)) continue;
                $packageItemId = receipt_first_text([$packageRow['sourceItemId'] ?? '', $packageRow['freightItemId'] ?? '', $packageRow['id'] ?? '']);
                if ($packageItemId === '' || !isset($receivedFreightItemIds[$packageItemId])) continue;
                $packageRow['inventoryStatus'] = 'received';
                $packageRow['inventoryReceivedAt'] = $receivedAt;
                $packageRow['inventoryReceivedWarehouse'] = $warehouse;
                $packageRow['inventoryOperationId'] = $operationId;
                $packageRow['receivingDocumentId'] = $documentId;
                $packageRow['receivingDocumentNo'] = $documentNo;
                $packageRow['receiptStatus'] = '已入庫';
                $packageRow['updatedAt'] = $now;
                $batchTouched = true;
            }
            unset($packageRow);
            if (!$batchTouched) continue;
            $receiptBatchRow['packageRows'] = $packageRows;
            $receivedRowCount = 0;
            foreach ($packageRows as $packageRow) {
                if (is_array($packageRow) && (receipt_text($packageRow['inventoryStatus'] ?? '') === 'received' || !empty($packageRow['inventoryReceivedAt']))) $receivedRowCount++;
            }
            $allRowsReceived = $packageRows && $receivedRowCount === count($packageRows);
            $receiptBatchRow['inventoryReceivedItemCount'] = $receivedRowCount;
            $receiptBatchRow['inventoryPendingItemCount'] = max(0, count($packageRows) - $receivedRowCount);
            $receiptBatchRow['inventoryStatus'] = $allRowsReceived ? 'received' : 'partial';
            $receiptBatchRow['inventoryReceivedWarehouse'] = $warehouse;
            $receiptBatchRow['receivingStage'] = $allRowsReceived ? 'inventory_received' : 'partial_inventory_received';
            $receiptBatchRow['status'] = $allRowsReceived ? '已入庫' : '部分入庫';
            $receiptBatchRow['lastInventoryReceivedAt'] = $receivedAt;
            if ($allRowsReceived) $receiptBatchRow['inventoryReceivedAt'] = $receivedAt;
            if (!isset($receiptBatchRow['inventoryReceiptDocuments']) || !is_array($receiptBatchRow['inventoryReceiptDocuments'])) $receiptBatchRow['inventoryReceiptDocuments'] = [];
            $hasReceiptDocument = false;
            foreach ($receiptBatchRow['inventoryReceiptDocuments'] as $documentEntry) {
                if (is_array($documentEntry) && receipt_text($documentEntry['operationId'] ?? '') === $operationId) { $hasReceiptDocument = true; break; }
            }
            if (!$hasReceiptDocument) {
                $receiptBatchRow['inventoryReceiptDocuments'][] = [
                    'documentId' => $documentId,
                    'documentNo' => $documentNo,
                    'operationId' => $operationId,
                    'warehouse' => $warehouse,
                    'qty' => $totalQty,
                    'receivedAt' => $receivedAt,
                ];
            }
            $receiptBatchRow['receivingDocumentNos'] = array_values(array_unique(array_filter(array_map(
                static fn($entry) => is_array($entry) ? receipt_text($entry['documentNo'] ?? '') : '',
                $receiptBatchRow['inventoryReceiptDocuments']
            ))));
            $receiptBatchRow['receivingDocumentNo'] = implode('、', $receiptBatchRow['receivingDocumentNos']);
            $batchHistory = isset($receiptBatchRow['statusHistory']) && is_array($receiptBatchRow['statusHistory']) ? array_values($receiptBatchRow['statusHistory']) : [];
            $hasBatchHistory = false;
            foreach ($batchHistory as $historyEntry) {
                if (is_array($historyEntry) && receipt_text($historyEntry['operationId'] ?? '') === $operationId) { $hasBatchHistory = true; break; }
            }
            if (!$hasBatchHistory) $batchHistory[] = ['operationId' => $operationId, 'status' => $receiptBatchRow['status'], 'at' => $receivedAt, 'receivingDocumentNo' => $documentNo];
            $receiptBatchRow['statusHistory'] = $batchHistory;
            $receiptBatchRow['updatedAt'] = $now;
            $freightChanged = true;
        }
        unset($receiptBatchRow);
    }
    if ($claimsWholeBatch && $freightBatchIndex >= 0) {
            $freightData['batches'][$freightBatchIndex]['status'] = '已入庫';
            $freightData['batches'][$freightBatchIndex]['inventoryReceivedAt'] = $receivedAt;
            $freightData['batches'][$freightBatchIndex]['receivingDocumentNo'] = $documentNo;
            $freightData['batches'][$freightBatchIndex]['updatedAt'] = $now;
            $freightData['batches'][$freightBatchIndex]['inventoryReceiptClaim']['status'] = 'received';
            $freightData['batches'][$freightBatchIndex]['inventoryReceiptClaim']['receivedAt'] = $receivedAt;
            $freightData['batches'][$freightBatchIndex]['inventoryReceiptClaim']['updatedAt'] = $now;
            $history = isset($freightData['batches'][$freightBatchIndex]['statusHistory']) && is_array($freightData['batches'][$freightBatchIndex]['statusHistory']) ? $freightData['batches'][$freightBatchIndex]['statusHistory'] : [];
            $hasOperationHistory = false;
            foreach ($history as $historyEntry) {
                if (is_array($historyEntry) && (string)($historyEntry['operationId'] ?? '') === $operationId) { $hasOperationHistory = true; break; }
            }
            if (!$hasOperationHistory) $history[] = ['operationId' => $operationId, 'status' => '已入庫', 'at' => $receivedAt, 'receivingDocumentNo' => $documentNo];
            $freightData['batches'][$freightBatchIndex]['statusHistory'] = $history;
            $freightChanged = true;
    }
    if ($freightChanged) {
        $freightData['revision'] = max(0, (int)($freightData['revision'] ?? 0)) + 1;
        $freightData['updatedAt'] = $now;
        write_json($freightTrackingFile, $freightData);
    }
    respond(['ok' => true, 'receipt' => $record, 'inventory' => ['warehouse' => $warehouse, 'lines' => count($receipt['receiptLog']), 'totalQty' => $totalQty], 'idempotentReplay' => $idempotentReceived || !empty($receipt['idempotentReplay'])]);
}

if ($action === 'report-order-issue') {
    $orderId = trim((string)($payload['orderId'] ?? ''));
    $sales = valid_sales((string)($payload['sales'] ?? ''));
    $allowed = ['address' => '地址異常', 'quantity' => '數量問題', 'price' => '價格問題', 'color' => '顏色問題', 'hold' => '客戶先不出，等通知', 'fraud_return' => '疑似詐騙／惡意不取貨', 'name' => '姓名問題', 'phone' => '電話問題', 'product' => '商品問題', 'other' => '其他問題', 'unshipped_delay' => '逾期未出貨', 'not_received' => '還沒收到貨', 'abnormal_delivery' => '貨物配送異常'];
    $type = (string)($payload['issueType'] ?? '');
    if ($orderId === '' || !isset($allowed[$type])) respond(['ok' => false, 'error' => '請選擇問題原因'], 400);
    $orders = read_json($ordersFile);
    $orderIndex = find_index($orders, $orderId);
    if ($orderIndex < 0) respond(['ok' => false, 'error' => '找不到正式訂單'], 404);
    if (valid_sales((string)($orders[$orderIndex]['sales'] ?? '')) !== $sales) respond(['ok' => false, 'error' => '此訂單不屬於目前業務'], 403);
    $now = date(DATE_ATOM);
    $note = trim((string)($payload['note'] ?? ''));
    if (function_exists('mb_substr')) $note = mb_substr($note, 0, 1000);
    else $note = substr($note, 0, 1000);
    if ($type === 'other' && $note === '') respond(['ok' => false, 'error' => '其他問題請填寫說明'], 400);
    if ($type === 'fraud_return' && $note === '') $note = '業務回報疑似詐騙／惡意不取貨，請管理者核對退件與客戶後確認黑名單。';
    if ($type === 'unshipped_delay' && $note === '') $note = '業務回報近期待出貨仍未寄出，請管理者核對倉別與出貨進度。';
    if ($type === 'not_received' && $note === '') $note = '業務回報客戶還沒收到貨，請管理者核對物流單號與配送狀態。';
    if ($type === 'abnormal_delivery' && $note === '') $note = '業務回報貨物配送異常／退回，請管理者立刻處理。';
    $customer = is_array($orders[$orderIndex]['customer'] ?? null) ? $orders[$orderIndex]['customer'] : [];
    $report = ['id' => 'ISS-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)), 'orderId' => $orderId, 'sales' => $sales, 'salesName' => sales_label($sales), 'type' => $type, 'label' => $allowed[$type], 'note' => $note, 'customerName' => (string)($customer['name'] ?? ''), 'customerPhone' => (string)($customer['phone'] ?? ''), 'status' => 'pending', 'createdAt' => $now];
    $orderReports = is_array($orders[$orderIndex]['salesIssueReports'] ?? null) ? $orders[$orderIndex]['salesIssueReports'] : [];
    array_unshift($orderReports, $report);
    $orders[$orderIndex]['salesIssueReports'] = $orderReports;
    $orders[$orderIndex]['updatedAt'] = $now;
    if ($type === 'hold') {
        apply_wait_notify_fields(
            $orders[$orderIndex],
            true,
            sales_label($sales),
            $now,
            $note !== '' ? $note : '客戶先不出，等通知',
            'sales_hold'
        );
        $sourceInquiryId = receipt_first_text([
            $orders[$orderIndex]['sourceInquiryId'] ?? '',
            $orders[$orderIndex]['convertedFromInquiryId'] ?? '',
            $orders[$orderIndex]['inquiryId'] ?? '',
        ]);
        if ($sourceInquiryId !== '') {
            $inquiries = read_json($inquiriesFile);
            $inquiryIndex = find_inquiry_index_for_wait_notify($inquiries, $sourceInquiryId, $orderId);
            if ($inquiryIndex >= 0) {
                apply_wait_notify_fields(
                    $inquiries[$inquiryIndex],
                    true,
                    sales_label($sales),
                    $now,
                    $note !== '' ? $note : '客戶先不出，等通知',
                    'sales_hold'
                );
                write_json($inquiriesFile, array_values($inquiries));
            }
        }
    }
    write_json($ordersFile, $orders);
    $allReports = read_json($orderIssuesFile);
    array_unshift($allReports, $report);
    write_json($orderIssuesFile, $allReports);
    respond(['ok' => true, 'report' => $report]);
}

$inquiryId = (string)($payload['inquiryId'] ?? '');

if ($action === 'void-purchase-order') {
    // LZ_PO_VOID_20260924: cancel the 採購單 document, keep its number, never reverse inbound stock.
    $operator = require_purchase_operator(
        $adminSessionsFile,
        $stateFile,
        ['採購區', '採購進貨建檔', '預購訂單', '預購打單', '訂單管理']
    );
    $inquiryId = trim((string)($payload['inquiryId'] ?? $inquiryId));
    if ($inquiryId === '') respond(['ok' => false, 'error' => '缺少採購單號'], 400);
    $rows = read_json($inquiriesFile);
    $index = find_index($rows, $inquiryId);
    if ($index < 0) respond(['ok' => false, 'error' => '找不到這張採購單 ' . $inquiryId . '，請重新整理後再試'], 404);
    $inquiry = is_array($rows[$index]) ? $rows[$index] : [];
    $isPreorder = (string)($inquiry['orderType'] ?? '') === 'preorder'
        || in_array((string)($inquiry['status'] ?? ''), ['preorder_pending', 'preorder_draft', 'cancelled', 'voided'], true)
        || strpos((string)($inquiry['id'] ?? ''), 'BYORDER-') === 0;
    if (!$isPreorder) respond(['ok' => false, 'error' => '這張不是採購區的採購單'], 409);

    $linkedReceipts = [];
    $receiptRows = read_json($purchaseReceiptsFile);
    if (!is_array($receiptRows)) $receiptRows = [];
    foreach ($receiptRows as $receipt) {
        if (!is_array($receipt)) continue;
        $status = strtolower(trim((string)($receipt['status'] ?? '')));
        if (function_exists('receipt_status_is_inactive') && receipt_status_is_inactive($status)) continue;
        $doc = is_array($receipt['receivingDocument'] ?? null) ? $receipt['receivingDocument'] : [];
        $source = trim((string)($doc['sourceOrderNo'] ?? $receipt['sourceOrderNo'] ?? ''));
        $linkedInquiry = trim((string)($receipt['inquiryId'] ?? $doc['inquiryId'] ?? ''));
        $matched = ($source !== '' && strcasecmp($source, $inquiryId) === 0)
            || ($linkedInquiry !== '' && $linkedInquiry === $inquiryId);
        if (!$matched && isset($receipt['receiptLines']) && is_array($receipt['receiptLines'])) {
            foreach ($receipt['receiptLines'] as $line) {
                if (!is_array($line)) continue;
                $lineSource = trim((string)($line['sourceOrderNo'] ?? $line['inquiryId'] ?? $line['customerOrderNo'] ?? ''));
                if ($lineSource !== '' && strcasecmp($lineSource, $inquiryId) === 0) {
                    $matched = true;
                    break;
                }
            }
        }
        if (!$matched) continue;
        $linkedReceipts[] = [
            'id' => (string)($receipt['id'] ?? ''),
            'documentNo' => trim((string)($doc['documentNo'] ?? $receipt['documentNo'] ?? $receipt['id'] ?? '')),
            'status' => (string)($receipt['status'] ?? ''),
            'qty' => max(0, (int)($receipt['totalQty'] ?? 0)),
        ];
    }

    $now = date(DATE_ATOM);
    $operatorName = trim((string)($operator['name'] ?? $operator['account'] ?? '管理者')) ?: '管理者';
    $alreadyVoided = !empty($inquiry['voided'])
        || in_array(strtolower(trim((string)($inquiry['status'] ?? ''))), ['cancelled', 'canceled', 'voided'], true);
    if ($alreadyVoided) {
        respond([
            'ok' => true,
            'idempotentReplay' => true,
            'voided' => true,
            'inquiry' => $inquiry,
            'keptDocumentNo' => $inquiryId,
            'stockChanged' => false,
            'inboundReversed' => false,
            'linkedInboundReceipts' => $linkedReceipts,
            'message' => '這張採購單已經作廢，單號仍保留',
        ]);
    }

    $convertedOrderId = trim((string)($inquiry['convertedOrderId'] ?? $inquiry['convertedToOrderId'] ?? $inquiry['formalOrderId'] ?? $inquiry['orderId'] ?? ''));
    $convertedMarker = strtolower(trim((string)($inquiry['status'] ?? ''))) === 'converted'
        || trim((string)($inquiry['preorderConvertedAt'] ?? $inquiry['convertedAt'] ?? '')) !== ''
        || $convertedOrderId !== '';
    if ($convertedMarker) {
        $orders = read_json($ordersFile);
        $activeConverted = null;
        foreach (is_array($orders) ? $orders : [] as $order) {
            if (!is_array($order)) continue;
            $oid = trim((string)($order['id'] ?? ''));
            $source = trim((string)($order['sourceInquiryId'] ?? $order['convertedFromInquiryId'] ?? $order['inquiryId'] ?? ''));
            $match = ($convertedOrderId !== '' && $oid === $convertedOrderId)
                || ($source !== '' && $source === $inquiryId);
            if (!$match) continue;
            $orderStatus = strtolower(trim((string)($order['status'] ?? '')));
            if (in_array($orderStatus, ['cancelled', 'canceled', 'voided'], true)) continue;
            $activeConverted = $order;
            break;
        }
        if ($activeConverted) {
            respond([
                'ok' => false,
                'error' => '這張採購單已轉正式出貨單 ' . trim((string)($activeConverted['id'] ?? '')) . '。請到訂單頁取消；這裡作廢不會動出貨單庫存。',
            ], 409);
        }
    }

    $inquiriesBak = $inquiriesFile . '.bak-po-void-20260924';
    if (is_file($inquiriesFile) && !is_file($inquiriesBak)) {
        if (!@copy($inquiriesFile, $inquiriesBak)) {
            respond(['ok' => false, 'error' => '無法備份採購單資料，已中止作廢'], 500);
        }
    }
    $freightBak = $freightTrackingFile . '.bak-po-void-20260924';
    if (is_file($freightTrackingFile) && !is_file($freightBak)) {
        @copy($freightTrackingFile, $freightBak);
    }

    $wasReserved = !empty($inquiry['reserved']);
    $reservedRestocked = false;
    if ($wasReserved) {
        adjust_stock(is_array($inquiry['items'] ?? null) ? $inquiry['items'] : [], 1, $skusFile, $stateFile);
        $reservedRestocked = true;
    }
    $releasedFreightQty = release_preorder_freight_links($freightTrackingFile, [$inquiryId], false);

    $rows[$index]['status'] = 'cancelled';
    $rows[$index]['statusLabel'] = '已作廢';
    $rows[$index]['voided'] = true;
    $rows[$index]['voidedAt'] = $now;
    $rows[$index]['voidedBy'] = $operatorName;
    $rows[$index]['voidReason'] = '作廢後這張採購單取消，未入庫項目不會再進倉';
    $rows[$index]['cancelledAt'] = $now;
    $rows[$index]['cancelledBy'] = $operatorName;
    $rows[$index]['cancelledReason'] = '採購單作廢';
    $rows[$index]['reserved'] = false;
    $rows[$index]['updatedAt'] = $now;
    $history = isset($rows[$index]['statusHistory']) && is_array($rows[$index]['statusHistory']) ? array_values($rows[$index]['statusHistory']) : [];
    $history[] = [
        'status' => '已作廢',
        'at' => $now,
        'by' => $operatorName,
        'inboundReversed' => false,
        'linkedInboundCount' => count($linkedReceipts),
    ];
    $rows[$index]['statusHistory'] = array_slice($history, -50);
    write_json($inquiriesFile, $rows);

    $message = '採購單 ' . $inquiryId . ' 已作廢，單號保留備查';
    if ($linkedReceipts) {
        $nos = [];
        foreach ($linkedReceipts as $linked) {
            $no = trim((string)($linked['documentNo'] ?? ''));
            if ($no !== '') $nos[] = $no;
        }
        $message .= '。已入庫進貨單仍保留（' . implode('、', array_slice($nos, 0, 8)) . (count($nos) > 8 ? '…' : '') . '），庫存未倒扣；若要扣庫存請到進貨單刪除。';
    } else {
        $message .= '。未入庫，庫存未改。';
    }

    respond([
        'ok' => true,
        'voided' => true,
        'inquiry' => $rows[$index],
        'keptDocumentNo' => $inquiryId,
        'stockChanged' => $reservedRestocked,
        'reservedRestocked' => $reservedRestocked,
        'inboundReversed' => false,
        'releasedFreightQty' => $releasedFreightQty,
        'linkedInboundReceipts' => $linkedReceipts,
        'message' => $message,
    ]);
}

if ($action === 'bulk-delete-preorders') {
    $requestedIds = is_array($payload['inquiryIds'] ?? null) ? array_map('strval', $payload['inquiryIds']) : [];
    $requestedIds = array_values(array_unique(array_filter($requestedIds, fn($id) => trim($id) !== '')));
    if (!$requestedIds) respond(['ok' => false, 'error' => '請先選取要刪除的預購單'], 400);

    $requestedMap = array_fill_keys($requestedIds, true);
    $rows = read_json($inquiriesFile);
    $kept = [];
    $deletedIds = [];
    $restockItems = [];

    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $id = (string)($row['id'] ?? '');
        $isPreorder = (string)($row['orderType'] ?? '') === 'preorder' || (string)($row['status'] ?? '') === 'preorder_pending';
        if ($id !== '' && isset($requestedMap[$id]) && $isPreorder) {
            $deletedIds[] = $id;
            if (!empty($row['reserved']) && is_array($row['items'] ?? null)) {
                $restockItems = array_merge($restockItems, $row['items']);
            }
            continue;
        }
        $kept[] = $row;
    }

    if ($restockItems) adjust_stock($restockItems, 1, $skusFile, $stateFile);
    $releasedFreightQty = release_preorder_freight_links($freightTrackingFile, $deletedIds);
    write_json($inquiriesFile, array_values($kept));
    $deletedMap = array_fill_keys($deletedIds, true);
    $missingIds = array_values(array_filter($requestedIds, fn($id) => !isset($deletedMap[$id])));
    respond([
        'ok' => true,
        'deleted' => $deletedIds,
        'missing' => $missingIds,
        'remaining' => count($kept),
        'releasedFreightQty' => $releasedFreightQty,
    ]);
}

if ($action === 'plan-merge-shipment') {
    $operator = require_purchase_operator(
        $adminSessionsFile,
        $stateFile,
        ['預購訂單', '預購打單', '訂單管理', '採購區']
    );
    $requestedIds = is_array($payload['inquiryIds'] ?? null) ? array_map('strval', $payload['inquiryIds']) : [];
    $requestedIds = array_values(array_unique(array_filter($requestedIds, static fn($id) => trim($id) !== '')));
    if (count($requestedIds) < 2) respond(['ok' => false, 'error' => '合併等待單至少要勾選 2 張訂單'], 400);

    $rows = read_json($inquiriesFile);
    $orders = read_json($ordersFile);
    $indexes = [];
    $phones = [];
    foreach ($rows as $rowIndex => $row) {
        $id = trim((string)($row['id'] ?? ''));
        if ($id === '' || !in_array($id, $requestedIds, true)) continue;
        $converted = (string)($row['status'] ?? '') === 'converted'
            || trim((string)($row['orderId'] ?? $row['convertedOrderId'] ?? $row['formalOrderId'] ?? '')) !== '';
        if ((string)($row['status'] ?? '') === 'cancelled') {
            respond(['ok' => false, 'error' => '訂單 ' . $id . ' 已取消，不能加入合併等待單'], 409);
        }
        if ($converted) {
            $formalOpen = false;
            foreach ($orders as $order) {
                $sourceId = trim((string)($order['sourceInquiryId'] ?? $order['convertedFromInquiryId'] ?? $order['id'] ?? ''));
                if ($sourceId !== $id) continue;
                $status = strtolower(trim((string)($order['status'] ?? '')));
                $delivery = strtolower(trim((string)($order['deliveryState'] ?? '')));
                $formalOpen = !in_array($status, ['delivered', 'completed', 'returned', 'cancelled'], true)
                    && !in_array($delivery, ['delivered', 'completed', 'returned', 'cancelled'], true);
                break;
            }
            if (!$formalOpen) respond(['ok' => false, 'error' => '訂單 ' . $id . ' 已完成或找不到可合併的正式出貨單'], 409);
        }
        $customer = is_array($row['customer'] ?? null) ? $row['customer'] : [];
        $phone = preg_replace('/\D+/', '', (string)($customer['phone'] ?? $row['customerPhone'] ?? ''));
        if (strlen($phone) < 6) respond(['ok' => false, 'error' => '訂單 ' . $id . ' 沒有可核對的完整電話'], 409);
        $phones[$phone] = true;
        $indexes[] = $rowIndex;
    }
    if (count($indexes) !== count($requestedIds)) respond(['ok' => false, 'error' => '部分預購單已不存在，請重新整理後再選'], 409);
    if (count($phones) !== 1) respond(['ok' => false, 'error' => '只能合併同一支電話的訂單'], 409);

    usort($indexes, static function (int $left, int $right) use ($rows): int {
        $leftAt = trim((string)($rows[$left]['createdAt'] ?? $rows[$left]['orderDate'] ?? ''));
        $rightAt = trim((string)($rows[$right]['createdAt'] ?? $rows[$right]['orderDate'] ?? ''));
        return $leftAt === $rightAt
            ? strcmp((string)($rows[$left]['id'] ?? ''), (string)($rows[$right]['id'] ?? ''))
            : strcmp($leftAt, $rightAt);
    });
    $masterId = trim((string)($rows[$indexes[0]]['id'] ?? ''));
    $sortedIds = array_map(static fn(int $rowIndex): string => trim((string)($rows[$rowIndex]['id'] ?? '')), $indexes);
    $groupId = 'PREMERGE-' . date('Ymd-His') . '-' . strtoupper(substr(hash('sha256', implode('|', $sortedIds)), 0, 4));
    $now = date(DATE_ATOM);
    $operatorName = trim((string)($operator['name'] ?? $operator['account'] ?? '管理者'));
    $updated = [];
    foreach ($indexes as $rowIndex) {
        $rows[$rowIndex]['plannedShipmentMergeGroupId'] = $groupId;
        $rows[$rowIndex]['plannedShipmentMasterInquiryId'] = $masterId;
        $rows[$rowIndex]['plannedShipmentMergedInquiryIds'] = $sortedIds;
        $rows[$rowIndex]['plannedShipmentMergedAt'] = $now;
        $rows[$rowIndex]['plannedShipmentMergedBy'] = $operatorName;
        $rows[$rowIndex]['freightShippingSelected'] = true;
        $rows[$rowIndex]['freightShippingSelectedAt'] = $now;
        $rows[$rowIndex]['freightShippingSelectedBy'] = $operatorName;
        $rows[$rowIndex]['updatedAt'] = $now;
        $updated[] = $rows[$rowIndex];
    }
    write_json($inquiriesFile, $rows);
    respond([
        'ok' => true,
        'groupId' => $groupId,
        'masterInquiryId' => $masterId,
        'inquiryIds' => $sortedIds,
        'inquiries' => $updated,
    ]);
}

$rows = read_json($inquiriesFile);
$index = find_index($rows, $inquiryId);
if ($index < 0 && $action === 'delete') {
    // Delete is idempotent. A stale workstation may still show a row that was
    // already removed by another request; treating it as success lets the
    // client clear that stale card instead of appearing to resurrect it.
    respond(['ok' => true, 'deletedId' => $inquiryId, 'alreadyDeleted' => true, 'releasedFreightQty' => 0]);
}
if ($index < 0) respond(['ok' => false, 'error' => '找不到這筆預購單，請重新整理後再試'], 404);
$inquiry = $rows[$index];

if ($action === 'admin-append-preorder-items') {
    $operator = require_purchase_operator(
        $adminSessionsFile,
        $stateFile,
        ['預購訂單', '預購打單', '訂單管理', '採購區']
    );
    $isPreorder = (string)($inquiry['orderType'] ?? '') === 'preorder'
        || in_array((string)($inquiry['status'] ?? ''), ['preorder_pending', 'preorder_draft'], true);
    $hasConvertedMarker = (string)($inquiry['status'] ?? '') === 'converted'
        || trim((string)($inquiry['orderId'] ?? $inquiry['convertedOrderId'] ?? $inquiry['formalOrderId'] ?? '')) !== ''
        || trim((string)($inquiry['preorderConvertedAt'] ?? $inquiry['convertedAt'] ?? '')) !== '';
    // 只有真正存在對應正式出貨單才算已轉正式。舊流程可能在單一商品
    // 到貨或釋放為公司現貨時先留下 converted 狀態，但整張預購仍有
    // 等待商品；這種孤兒狀態不應擋住客戶追加現貨／預購商品。
    $formalOrders = $hasConvertedMarker ? read_json($ordersFile) : [];
    $convertedOrderIndex = $hasConvertedMarker ? find_converted_order_index($formalOrders, $inquiry) : -1;
    $isConverted = $convertedOrderIndex >= 0;
    $hasStaleConvertedMarker = $hasConvertedMarker && !$isConverted;
    $priorityAllocation = !empty($payload['priorityAllocation']);
    if (!$isPreorder) respond(['ok' => false, 'error' => '這筆不是預購訂單'], 409);
    if ($isConverted || (string)($inquiry['status'] ?? '') === 'cancelled' || (!empty($inquiry['inventoryReceived']) && !$priorityAllocation)) {
        respond(['ok' => false, 'error' => '已取消、完成入庫或轉正式出貨的預購單不能再直接新增商品'], 409);
    }

    $incomingItems = is_array($payload['newItems'] ?? null) ? $payload['newItems'] : [];
    $incomingItems = externalize_data_images($incomingItems, __DIR__);
    /*
     * 本機281：客戶出貨核對畫面可直接從預購倉／正式倉庫插隊加商品。
     * 正式倉庫必須重新由正式 SKU 驗證倉別與可用庫存；預購倉只建立
     * 等待到貨需求，不得偽裝成已入庫或提前扣除實體庫存。
     */
    if ($priorityAllocation) {
        $allocationSource = strtoupper(trim((string)($payload['allocationSourceWarehouse'] ?? 'PREORDER')));
        if (!in_array($allocationSource, ['PREORDER', 'TW', 'CN', 'ID'], true)) {
            respond(['ok' => false, 'error' => '插隊配貨來源只接受預購倉、台灣倉、中國倉或印尼倉'], 400);
        }
        if ($allocationSource === 'CN') reject_unless_admin_for_china_taiwan_live($operator);
        $catalogSkus = read_json($skusFile);
        $catalogProducts = read_json($productsFile);
        $requestedBySku = [];
        foreach ($incomingItems as $incomingIndex => &$incomingItem) {
            if (!is_array($incomingItem)) continue;
            $requestedSkuId = receipt_first_text([$incomingItem['skuId'] ?? '', $incomingItem['sku'] ?? '']);
            $requestedQty = max(1, (int)($incomingItem['qty'] ?? $incomingItem['quantity'] ?? 1));
            $baseSku = null;
            foreach ($catalogSkus as $catalogSku) {
                if (!is_array($catalogSku)) continue;
                if (receipt_first_text([$catalogSku['id'] ?? '', $catalogSku['sku'] ?? '']) !== $requestedSkuId) continue;
                if (!empty($catalogSku['archived']) || (isset($catalogSku['active']) && !$catalogSku['active'])) continue;
                $baseSku = $catalogSku;
                break;
            }
            if (!is_array($baseSku)) {
                $freightSourceId = receipt_text($incomingItem['freightSourceId'] ?? '');
                $temporaryFreight = !empty($incomingItem['temporaryFreightProduct'])
                    && !empty($incomingItem['temporaryFreightSku'])
                    && $allocationSource === 'PREORDER'
                    && $freightSourceId !== '';
                $freightSource = null;
                if ($temporaryFreight) {
                    $freightData = read_json($freightTrackingFile);
                    foreach (is_array($freightData['items'] ?? null) ? $freightData['items'] : [] as $freightItem) {
                        if (!is_array($freightItem) || receipt_text($freightItem['id'] ?? '') !== $freightSourceId) continue;
                        $freightSource = $freightItem;
                        break;
                    }
                }
                $freightName = is_array($freightSource) ? receipt_text($freightSource['productName'] ?? '') : '';
                $freightImages = is_array($freightSource) && is_array($freightSource['productImages'] ?? null)
                    ? array_values(array_filter($freightSource['productImages'], static function ($image) { return trim((string)$image) !== ''; }))
                    : [];
                if (!is_array($freightSource) || $freightName === '' || !$freightImages) {
                    respond(['ok' => false, 'error' => '第 ' . ($incomingIndex + 1) . ' 個商品尚未建立正式 SKU，且找不到可核對的豪鴻名稱與照片'], 404);
                }
                $incomingItem['skuId'] = $requestedSkuId;
                $incomingItem['sku'] = $requestedSkuId;
                $incomingItem['productId'] = 'freight-photo:' . $freightSourceId;
                $incomingItem['code'] = receipt_first_text([$incomingItem['code'] ?? '', '預購暫存']);
                $incomingItem['title'] = $freightName;
                $incomingItem['color'] = receipt_first_text([$incomingItem['color'] ?? '', $freightSource['color'] ?? '', '未選顏色']);
                $incomingItem['size'] = receipt_first_text([$incomingItem['size'] ?? '', $freightSource['size'] ?? '', 'NO SIZE']);
                $incomingItem['image'] = receipt_first_text([$incomingItem['image'] ?? '', $freightImages[0] ?? '']);
                $incomingItem['allocationSourceWarehouse'] = 'PREORDER';
                $incomingItem['manualPriorityAllocation'] = true;
                $incomingItem['priorityAllocationStatus'] = 'waiting_arrival';
                $incomingItem['priorityPending'] = true;
                $incomingItem['freightReceivedQty'] = 0;
                $incomingItem['freightSourceId'] = $freightSourceId;
                $incomingItem['temporaryFreightProduct'] = true;
                $incomingItem['temporaryFreightSku'] = true;
                continue;
            }
            $productId = receipt_text($baseSku['productId'] ?? '');
            $color = receipt_first_text([$baseSku['colorName'] ?? '', $baseSku['color'] ?? '']);
            $size = receipt_first_text([$baseSku['sizeName'] ?? '', $baseSku['size'] ?? '', 'NO SIZE']);
            $selectedSku = $baseSku;
            if ($allocationSource !== 'PREORDER') {
                $profile = receipt_warehouse_profile($allocationSource);
                $selectedSku = null;
                foreach ($catalogSkus as $candidateSku) {
                    if (!is_array($candidateSku) || !receipt_sku_is_warehouse($candidateSku, $profile)) continue;
                    if (!receipt_same_variant($candidateSku, $productId, $color, $size)) continue;
                    if (!empty($candidateSku['archived']) || (isset($candidateSku['active']) && !$candidateSku['active'])) continue;
                    $selectedSku = $candidateSku;
                    break;
                }
                if (!is_array($selectedSku)) {
                    respond(['ok' => false, 'error' => '第 ' . ($incomingIndex + 1) . ' 個商品在指定來源倉沒有這個顏色／尺寸'], 404);
                }
                $selectedSkuId = receipt_first_text([$selectedSku['id'] ?? '', $selectedSku['sku'] ?? '']);
                $requestedBySku[$selectedSkuId] = ($requestedBySku[$selectedSkuId] ?? 0) + $requestedQty;
                if ($requestedBySku[$selectedSkuId] > max(0, (int)($selectedSku['stock'] ?? 0))) {
                    respond([
                        'ok' => false,
                        'error' => '第 ' . ($incomingIndex + 1) . ' 個商品超過 ' . $profile['name'] . ' 可用庫存；目前只有 ' . max(0, (int)($selectedSku['stock'] ?? 0)) . ' 件',
                    ], 409);
                }
            }
            $selectedProduct = null;
            foreach ($catalogProducts as $catalogProduct) {
                if (is_array($catalogProduct) && receipt_text($catalogProduct['id'] ?? '') === $productId) {
                    $selectedProduct = $catalogProduct;
                    break;
                }
            }
            $selectedSkuId = receipt_first_text([$selectedSku['id'] ?? '', $selectedSku['sku'] ?? '']);
            $incomingItem['skuId'] = $selectedSkuId;
            $incomingItem['sku'] = $selectedSkuId;
            $incomingItem['productId'] = $productId;
            $incomingItem['code'] = receipt_first_text([
                is_array($selectedProduct) ? ($selectedProduct['code'] ?? '') : '',
                $incomingItem['code'] ?? '',
                $incomingItem['productCode'] ?? '',
            ]);
            $incomingItem['title'] = receipt_first_text([
                is_array($selectedProduct) ? ($selectedProduct['title'] ?? $selectedProduct['name'] ?? '') : '',
                $incomingItem['title'] ?? '',
                $incomingItem['productName'] ?? '',
            ]);
            $incomingItem['color'] = receipt_first_text([$selectedSku['colorName'] ?? '', $selectedSku['color'] ?? '', $color]);
            $incomingItem['size'] = receipt_first_text([$selectedSku['sizeName'] ?? '', $selectedSku['size'] ?? '', $size, 'NO SIZE']);
            $incomingItem['image'] = receipt_first_text([
                $selectedSku['colorImage'] ?? '',
                $selectedSku['image'] ?? '',
                is_array($selectedProduct) ? ($selectedProduct['image'] ?? $selectedProduct['cover'] ?? '') : '',
                $incomingItem['image'] ?? '',
            ]);
            $incomingItem['allocationSourceWarehouse'] = $allocationSource;
            $incomingItem['manualPriorityAllocation'] = true;
            $incomingItem['priorityAllocationStatus'] = $allocationSource === 'PREORDER' ? 'waiting_arrival' : 'warehouse_reserved';
            $incomingItem['priorityPending'] = $allocationSource === 'PREORDER';
            $incomingItem['freightReceivedQty'] = $allocationSource === 'PREORDER' ? 0 : $requestedQty;
            $incomingItem['freightReceivedWarehouse'] = $allocationSource === 'PREORDER' ? '' : $allocationSource;
        }
        unset($incomingItem);
    }
    $existingItems = is_array($rows[$index]['items'] ?? null) ? array_values($rows[$index]['items']) : [];
    $seenLineIds = [];
    foreach ($existingItems as $existingIndex => &$existingItem) {
        if (!is_array($existingItem)) $existingItem = [];
        $preferred = trim((string)($existingItem['lineId'] ?? ''));
        if ($preferred === '') {
            $legacyVariant = lz_purchase_variant($rows[$index], $existingItem, (int)$existingIndex);
            $preferred = lz_purchase_line_id($rows[$index], $existingItem, (int)$existingIndex, $legacyVariant);
        }
        if ($preferred !== '') $seenLineIds[$preferred] = true;
    }
    unset($existingItem);

    $newItems = [];
    foreach ($incomingItems as $item) {
        if (!is_array($item)) continue;
        $qty = max(1, (int)($item['qty'] ?? $item['quantity'] ?? 1));
        $price = max(0, (float)($item['price'] ?? $item['salePrice'] ?? 0));
        $code = trim((string)($item['code'] ?? $item['productCode'] ?? $item['sku'] ?? ''));
        $title = trim((string)($item['title'] ?? $item['productName'] ?? $item['name'] ?? ''));
        if ($code === '' && $title === '') continue;
        $lineId = unique_inquiry_line_id('', $seenLineIds);
        $newItems[] = [
            'lineId' => $lineId,
            'requestedQty' => $qty,
            'skuId' => trim((string)($item['skuId'] ?? '')),
            'sku' => trim((string)($item['sku'] ?? '')),
            'productId' => trim((string)($item['productId'] ?? '')),
            'code' => $code,
            'title' => $title,
            'color' => trim((string)($item['color'] ?? '')),
            'size' => trim((string)($item['size'] ?? 'NO SIZE')) ?: 'NO SIZE',
            'qty' => $qty,
            'price' => $price,
            'subtotal' => round($price * $qty, 2),
            'image' => trim((string)($item['image'] ?? '')),
            'cost' => max(0, (float)($item['cost'] ?? 0)),
            'costSource' => trim((string)($item['costSource'] ?? '')),
            'costEffectiveAt' => trim((string)($item['costEffectiveAt'] ?? '')),
            'costVersion' => trim((string)($item['costVersion'] ?? '')),
            'salesPurchaseImage' => !empty($item['salesPurchaseImage']),
            'appendedAt' => date(DATE_ATOM),
            'allocationSourceWarehouse' => trim((string)($item['allocationSourceWarehouse'] ?? '')),
            'manualPriorityAllocation' => !empty($item['manualPriorityAllocation']),
            'priorityAllocationStatus' => trim((string)($item['priorityAllocationStatus'] ?? '')),
            'priorityPending' => !empty($item['priorityPending']),
            'freightReceivedQty' => max(0, (int)($item['freightReceivedQty'] ?? 0)),
            'freightReceivedWarehouse' => trim((string)($item['freightReceivedWarehouse'] ?? '')),
            'freightSourceId' => trim((string)($item['freightSourceId'] ?? '')),
            'temporaryFreightProduct' => !empty($item['temporaryFreightProduct']),
            'temporaryFreightSku' => !empty($item['temporaryFreightSku']),
        ];
    }
    if (!$newItems) respond(['ok' => false, 'error' => '請至少加入一個新商品'], 400);

    $items = array_values(array_merge($existingItems, $newItems));
    $subtotal = round(array_reduce($items, static function ($sum, $item) {
        return $sum + max(0, (float)($item['price'] ?? 0)) * max(0, (int)($item['qty'] ?? $item['quantity'] ?? 0));
    }, 0.0), 2);
    $customer = is_array($rows[$index]['customer'] ?? null) ? $rows[$index]['customer'] : [];
    $regularShippingFee = max(0, (float)(
        $rows[$index]['regularShippingFee']
        ?? $customer['regularShippingFee']
        ?? $rows[$index]['shippingFee']
        ?? $customer['shippingFee']
        ?? 0
    ));
    $freeShipping = $subtotal >= 1200;
    $shippingFee = $freeShipping ? 0.0 : $regularShippingFee;
    $oldTotal = max(0, (float)($inquiry['total'] ?? 0));
    $newTotal = round($subtotal + $shippingFee, 2);
    $now = date(DATE_ATOM);
    $operatorName = trim((string)($operator['name'] ?? $operator['account'] ?? '管理者'));

    $rows[$index]['items'] = $items;
    $rows[$index]['subtotal'] = $subtotal;
    $rows[$index]['shippingFee'] = $shippingFee;
    $rows[$index]['regularShippingFee'] = $regularShippingFee;
    $rows[$index]['freeShipping'] = $freeShipping;
    $rows[$index]['freeShippingThreshold'] = 1200;
    $rows[$index]['freeShippingBusinessRate'] = $freeShipping ? 0.3 : 0;
    $rows[$index]['freeShippingCompanyRate'] = $freeShipping ? 0.7 : 0;
    $rows[$index]['total'] = $newTotal;
    if ($hasStaleConvertedMarker) {
        $rows[$index]['status'] = 'preorder_pending';
        $rows[$index]['statusLabel'] = '已下單／進入預購等待';
        unset(
            $rows[$index]['orderId'],
            $rows[$index]['convertedOrderId'],
            $rows[$index]['convertedToOrderId'],
            $rows[$index]['formalOrderId'],
            $rows[$index]['preorderConvertedAt'],
            $rows[$index]['convertedAt']
        );
        $repairHistory = is_array($rows[$index]['preorderProgressHistory'] ?? null) ? $rows[$index]['preorderProgressHistory'] : [];
        $repairHistory[] = [
            'at' => $now,
            'by' => $operatorName,
            'purchaseStatus' => trim((string)($rows[$index]['purchaseStatus'] ?? 'ordered')) ?: 'ordered',
            'purchaseStatusLabel' => trim((string)($rows[$index]['purchaseStatusLabel'] ?? '已下單／進入預購等待')) ?: '已下單／進入預購等待',
            'note' => '新增商品時自動修復：舊資料標成已轉正式，但查無正式出貨單，恢復預購等待',
        ];
        $rows[$index]['preorderProgressHistory'] = array_slice($repairHistory, -100);
    }
    if (!$priorityAllocation) {
        $rows[$index]['status'] = 'preorder_pending';
        $rows[$index]['purchaseStatus'] = 'not_ordered';
        $rows[$index]['purchaseStatusLabel'] = '新增商品待採購';
    } elseif (array_filter($newItems, static fn($item) => !empty($item['priorityPending']))) {
        $rows[$index]['preorderWaitStatus'] = 'waiting_other_items';
        $rows[$index]['freightDispatchDecision'] = 'wait_all';
        $rows[$index]['priorityAllocationStatus'] = 'waiting_arrival';
    }
    $rows[$index]['updatedAt'] = $now;
    $customer['shippingFee'] = $shippingFee;
    $customer['regularShippingFee'] = $regularShippingFee;
    $customer['freeShipping'] = $freeShipping;
    $rows[$index]['customer'] = $customer;
    $appendHistory = is_array($rows[$index]['preorderAppendHistory'] ?? null) ? $rows[$index]['preorderAppendHistory'] : [];
    $appendHistory[] = [
        'at' => $now,
        'by' => $operatorName,
        'addedLines' => count($newItems),
        'addedQty' => array_reduce($newItems, static fn($sum, $item) => $sum + (int)$item['qty'], 0),
        'priorityAllocation' => $priorityAllocation,
        'sourceWarehouse' => $priorityAllocation ? strtoupper(trim((string)($payload['allocationSourceWarehouse'] ?? 'PREORDER'))) : '',
        'reason' => trim((string)($payload['priorityReason'] ?? '')),
    ];
    $rows[$index]['preorderAppendHistory'] = $appendHistory;

    $paymentRows = read_json($orderPaymentsFile);
    $payment = reconcile_unshipped_inquiry_payment($rows[$index], $paymentRows, $inquiryId, $newTotal, 'preorder', $oldTotal, $operator);
    write_json($orderPaymentsFile, $paymentRows);
    write_json($inquiriesFile, $rows);
    respond([
        'ok' => true,
        'inquiry' => $rows[$index],
        'payment' => $payment,
        'appendedLines' => count($newItems),
    ]);
}

if ($action === 'submit-preorder-draft') {
    if ((string)($inquiry['orderType'] ?? '') !== 'preorder' || (string)($inquiry['status'] ?? '') !== 'preorder_draft') {
        respond(['ok' => false, 'error' => '這筆不是可送出的預購草稿'], 409);
    }
    $login = require_sales_inquiry_login($payload, $inquiry, $salesCustomersFile, $stateFile, $salesSessionsFile);
    $now = date(DATE_ATOM);
    $rows[$index]['status'] = 'preorder_pending';
    $rows[$index]['statusLabel'] = 'Preorder - belum potong stok';
    $rows[$index]['purchaseStatus'] = 'not_ordered';
    $rows[$index]['purchaseStatusLabel'] = '待管理者採購';
    $rows[$index]['savedAsDraft'] = false;
    $rows[$index]['submittedAt'] = $now;
    $rows[$index]['submittedBy'] = trim((string)($login['name'] ?? $login['account'] ?? ''));
    $rows[$index]['updatedAt'] = $now;
    write_json($inquiriesFile, $rows);
    respond(['ok' => true, 'inquiry' => $rows[$index], 'orderId' => $inquiryId]);
}

if ($action === 'approve-temp-product') {
    $itemIndex = (int)($payload['itemIndex'] ?? -1);
    $items = is_array($rows[$index]['items'] ?? null) ? $rows[$index]['items'] : [];
    if ($itemIndex < 0 || !isset($items[$itemIndex]) || !is_array($items[$itemIndex])) {
        respond(['ok' => false, 'error' => '找不到這筆臨時商品'], 404);
    }
    $candidateItem = $items[$itemIndex];
    $candidateCode = (string)($candidateItem['temporaryProductCode'] ?? $candidateItem['code'] ?? '');
    if (empty($candidateItem['temporaryProduct']) && empty($candidateItem['requiresManagerProductCode']) && strpos($candidateCode, 'TMP-') !== 0) {
        respond(['ok' => false, 'error' => '這筆不是業務臨時商品，請使用既有商品比對功能'], 400);
    }
    $formalCode = strtoupper(trim((string)($payload['formalCode'] ?? '')));
    if ($formalCode === '' || !preg_match('/^[A-Z0-9_-]{2,40}$/', $formalCode)) {
        respond(['ok' => false, 'error' => '正式商品編號只能使用英文字母、數字、底線或減號'], 400);
    }
    // Keep temporary-product approval in the same catalog critical section as
    // purchase receiving; otherwise either whole-file write can lose the other.
    $catalogLock = acquire_inventory_file_lock($skusFile);
    $products = read_json($productsFile);
    foreach ($products as $product) {
        if (strcasecmp((string)($product['code'] ?? $product['productLine'] ?? ''), $formalCode) === 0) {
            respond(['ok' => false, 'error' => '正式商品編號已存在，請改用圖片比對選擇既有商品'], 409);
        }
    }
    $item = $items[$itemIndex];
    $now = date(DATE_ATOM);
    $productId = catalog_id_from_code($formalCode);
    $title = trim((string)($payload['title'] ?? $item['title'] ?? ''));
    if ($title === '') $title = '待補資料商品 ' . $formalCode;
    $color = trim((string)($payload['color'] ?? $item['color'] ?? ''));
    $size = trim((string)($payload['size'] ?? $item['size'] ?? ''));
    if ($size === '') $size = 'NO SIZE';
    $image = trim((string)($item['image'] ?? ''));
    $product = [
        'id' => $productId,
        'code' => $formalCode,
        'productLine' => $formalCode,
        'title' => $title,
        'brand' => '',
        'spec' => '',
        'price' => 0,
        'cost' => 0,
        'mainImage' => $image,
        'images' => $image !== '' ? [$image] : [],
        'colors' => $color !== '' ? [['code' => '', 'name' => $color, 'image' => $image]] : [],
        'description' => '由業務當日臨時品轉為正式待補建檔；尚未設定成本、售價與庫存。',
        'category' => '預購待補建檔',
        'showOnWebsite' => false,
        'productMode' => 'preorder',
        'preorderOnly' => true,
        'pendingPricing' => true,
        'createdFromTemporaryProduct' => (string)($item['temporaryProductCode'] ?? $item['code'] ?? ''),
        'createdAt' => $now,
        'updatedAt' => $now,
    ];
    $skuId = $formalCode . '-PENDING';
    $sku = [
        'id' => $skuId,
        'sku' => $skuId,
        'barcode' => '',
        'productId' => $productId,
        'color' => $color,
        'colorName' => $color,
        'colorCode' => '',
        'colorImage' => $image,
        'size' => $size,
        'sizeName' => $size,
        'sizeCode' => '',
        'stock' => 0,
        'price' => 0,
        'cost' => 0,
        'warehouse' => '',
        'warehouseCode' => '',
        'warehouseName' => '',
        'status' => 'active',
        'preorderOnly' => true,
        'pendingPricing' => true,
        'createdAt' => $now,
        'updatedAt' => $now,
    ];
    $products[] = $product;
    write_json($productsFile, $products);
    $skus = read_json($skusFile);
    $skus[] = $sku;
    write_json($skusFile, $skus);
    $state = read_json($stateFile);
    if (!isset($state['products']) || !is_array($state['products'])) $state['products'] = [];
    if (!isset($state['skus']) || !is_array($state['skus'])) $state['skus'] = [];
    $state['products'][] = $product;
    $state['skus'][] = $sku;
    $state['updatedAt'] = $now;
    write_json($stateFile, $state);
    $temporaryCode = (string)($item['temporaryProductCode'] ?? $item['code'] ?? '');
    $items[$itemIndex] = array_merge($item, [
        'skuId' => $skuId,
        'sku' => $skuId,
        'productId' => $productId,
        'code' => $formalCode,
        'title' => $title,
        'temporaryProduct' => false,
        'temporaryProductCode' => $temporaryCode,
        'requiresManagerProductCode' => false,
        'formalProductCodeAssignedAt' => $now,
    ]);
    $rows[$index]['items'] = $items;
    $rows[$index]['updatedAt'] = $now;
    write_json($inquiriesFile, $rows);
    $tempRows = read_json($tempProductsFile);
    foreach ($tempRows as &$tempRow) {
        if ((string)($tempRow['code'] ?? '') !== $temporaryCode) continue;
        $tempRow['status'] = 'converted';
        $tempRow['formalCode'] = $formalCode;
        $tempRow['convertedAt'] = $now;
    }
    unset($tempRow);
    write_json($tempProductsFile, $tempRows);
    @unlink($dataDir . DIRECTORY_SEPARATOR . 'public-catalog.json');
    respond(['ok' => true, 'product' => $product, 'sku' => $sku, 'inquiry' => $rows[$index]]);
}

if ($action === 'review-preorder-item') {
    $itemIndex = (int)($payload['itemIndex'] ?? -1);
    $items = is_array($rows[$index]['items'] ?? null) ? $rows[$index]['items'] : [];
    if ($itemIndex < 0 || !isset($items[$itemIndex]) || !is_array($items[$itemIndex])) {
        respond(['ok' => false, 'error' => 'Preorder item not found'], 404);
    }
    $now = date(DATE_ATOM);
    $items[$itemIndex]['adminReviewMatch'] = [
        'productId' => trim((string)($payload['productId'] ?? '')),
        'productCode' => trim((string)($payload['productCode'] ?? '')),
        'productTitle' => trim((string)($payload['productTitle'] ?? '')),
        'reviewedAt' => $now,
    ];
    $rows[$index]['items'] = $items;
    $rows[$index]['updatedAt'] = $now;
    write_json($inquiriesFile, $rows);
    respond(['ok' => true, 'inquiry' => $rows[$index]]);
}

if ($action === 'update-preorder-meta') {
    $now = date(DATE_ATOM);
    $warehouseMap = ['TW' => '台灣倉 / Gudang Taiwan', 'ID' => '印尼倉 / Gudang Indonesia', 'CN' => '中國倉 / Gudang China'];
    // 「備貨中」已併入「已下單」。received 只代表實際完成驗收入庫，
    // update-preorder-meta 不可用它跳過 receive-preorder 的庫存與物流核對流程。
    $allowedPurchase = [
        'not_ordered' => '待下單',
        'ordered' => '已下單／進入預購等待',
        'arrived' => '物流運送中',
        'received' => '已到貨入庫',
    ];
    $allowedWait = [
        'waiting_other_items' => '累計訂單／等待其他預購商品',
        'ready_to_ship' => '商品已齊／准許出貨',
    ];
    $currentPurchaseStatus = trim((string)($rows[$index]['purchaseStatus'] ?? 'not_ordered'));
    if ($currentPurchaseStatus === 'preparing') $currentPurchaseStatus = 'ordered';
    if (!isset($allowedPurchase[$currentPurchaseStatus])) {
        // Legacy shipped/ready_to_ship/completed rows must never jump backwards to
        // the procurement queue merely because a newer metadata form is saved.
        $currentPurchaseStatus = !empty($rows[$index]['inventoryReceived']) ? 'received' : 'arrived';
    }
    if ($currentPurchaseStatus === 'received' && empty($rows[$index]['inventoryReceived'])) {
        // Old rows that only carried a display status did not actually pass receiving.
        $currentPurchaseStatus = 'arrived';
    }
    $currentWaitStatus = trim((string)($rows[$index]['preorderWaitStatus'] ?? ''));
    if (empty($rows[$index]['inventoryReceived'])) $currentWaitStatus = '';
    elseif (!isset($allowedWait[$currentWaitStatus])) $currentWaitStatus = 'waiting_other_items';
    $previousProgress = [
        'purchaseStatus' => $currentPurchaseStatus,
        'preorderWaitStatus' => $currentWaitStatus,
        'purchaseNote' => (string)($rows[$index]['purchaseNote'] ?? ''),
        'allocationNote' => (string)($rows[$index]['allocationNote'] ?? ''),
        'purchasePlatform' => (string)($rows[$index]['purchasePlatform'] ?? $rows[$index]['platform'] ?? ''),
        'supplierTrackingNo' => (string)($rows[$index]['supplierTrackingNo'] ?? ''),
        'supplierCarrier' => (string)($rows[$index]['supplierCarrier'] ?? ''),
        'arrivalWarehouse' => (string)($rows[$index]['arrivalWarehouse'] ?? $rows[$index]['preorderWarehouse'] ?? 'TW'),
    ];
    $purchaseStatus = $currentPurchaseStatus;
    if (array_key_exists('purchaseStatus', $payload)) {
        $requestedPurchaseStatus = trim((string)$payload['purchaseStatus']);
        if ($requestedPurchaseStatus === 'preparing') $requestedPurchaseStatus = 'ordered';
        if ($requestedPurchaseStatus === 'received') {
            if (empty($rows[$index]['inventoryReceived'])) {
                respond(['ok' => false, 'error' => '已到貨入庫只能由採購進貨驗收完成，不能直接修改狀態'], 409);
            }
            // A received row may submit its current disabled value, but this is a no-op.
            $purchaseStatus = 'received';
        } elseif (!isset($allowedPurchase[$requestedPurchaseStatus]) || $requestedPurchaseStatus === 'received') {
            respond(['ok' => false, 'error' => '預購採購狀態不正確'], 400);
        } elseif (!empty($rows[$index]['inventoryReceived'])) {
            respond(['ok' => false, 'error' => '這張預購單已驗收入庫，不能退回採購或物流狀態'], 409);
        } else {
            $purchaseStatus = $requestedPurchaseStatus;
        }
    } elseif (!empty($rows[$index]['inventoryReceived'])) {
        $purchaseStatus = 'received';
    }
    $rows[$index]['purchaseStatus'] = $purchaseStatus;
    $rows[$index]['purchaseStatusLabel'] = $allowedPurchase[$purchaseStatus];
    // 舊預購單可能仍留著建立時的印尼文 statusLabel。每次正式儲存採購
    // 狀態時一併同步成中文作業狀態，避免畫面看似沒有儲存成功。
    $preorderStatusLabels = [
        'not_ordered' => '預購待採購',
        'ordered' => '已下單／進入預購等待',
        'arrived' => '物流運送中／等待到貨',
        'received' => '已到貨入庫／等待確認出貨',
    ];
    $rows[$index]['statusLabel'] = $preorderStatusLabels[$purchaseStatus] ?? $allowedPurchase[$purchaseStatus];
    $waitStatus = $currentWaitStatus;
    if (array_key_exists('preorderWaitStatus', $payload)) {
        $requestedWaitStatus = trim((string)$payload['preorderWaitStatus']);
        if (!isset($allowedWait[$requestedWaitStatus])) {
            respond(['ok' => false, 'error' => '預購等待狀態不正確'], 400);
        }
        if (empty($rows[$index]['inventoryReceived'])) {
            respond(['ok' => false, 'error' => '商品尚未完成驗收入庫，不能設定等待其他貨物或准許出貨'], 409);
        }
        $waitStatus = $requestedWaitStatus;
    } elseif (!empty($rows[$index]['inventoryReceived']) && !isset($allowedWait[$waitStatus])) {
        $waitStatus = 'waiting_other_items';
    }
    $rows[$index]['preorderWaitStatus'] = $waitStatus;
    $rows[$index]['preorderWaitStatusLabel'] = $waitStatus !== '' ? $allowedWait[$waitStatus] : '';
    if (array_key_exists('resellerSettlement', $payload) && is_array($payload['resellerSettlement'])) {
        $draftReseller = $payload['resellerSettlement'];
        $resellerEnabled = !empty($draftReseller['enabled']) || strtolower(trim((string)($draftReseller['mode'] ?? ''))) === 'reseller';
        $basePrice = max(0, (float)($draftReseller['basePrice'] ?? 0));
        $customerPrice = max(0, (float)($draftReseller['customerPrice'] ?? 0));
        $cleanReseller = [
            'enabled' => $resellerEnabled,
            'mode' => $resellerEnabled ? 'reseller' : 'direct',
            'source' => 'freight-fifo-draft',
            'sales' => mb_substr(trim((string)($draftReseller['sales'] ?? '')), 0, 120, 'UTF-8'),
            'resellerName' => mb_substr(trim((string)($draftReseller['resellerName'] ?? '')), 0, 120, 'UTF-8'),
            'resellerPhone' => mb_substr(trim((string)($draftReseller['resellerPhone'] ?? '')), 0, 60, 'UTF-8'),
            'basePrice' => $basePrice,
            'customerPrice' => $customerPrice,
            'resellerMargin' => $customerPrice - $basePrice,
            'advancedBySales' => !array_key_exists('advancedBySales', $draftReseller) || !empty($draftReseller['advancedBySales']),
            'note' => mb_substr(trim((string)($draftReseller['note'] ?? '')), 0, 1000, 'UTF-8'),
            'updatedAt' => $now,
        ];
        $rows[$index]['resellerSettlement'] = $cleanReseller;
        $rows[$index]['salesChannel'] = $resellerEnabled ? 'reseller' : 'direct';
        if (!isset($rows[$index]['paymentSummary']) || !is_array($rows[$index]['paymentSummary'])) $rows[$index]['paymentSummary'] = [];
        $rows[$index]['paymentSummary']['resellerSettlement'] = $cleanReseller;
    }
    if (array_key_exists('customerAddress', $payload)) {
        $customerAddress = trim((string)$payload['customerAddress']);
        if (mb_strlen($customerAddress, 'UTF-8') > 1000) respond(['ok' => false, 'error' => '客戶地址內容過長'], 400);
        if (!isset($rows[$index]['customer']) || !is_array($rows[$index]['customer'])) $rows[$index]['customer'] = [];
        $rows[$index]['customer']['address'] = $customerAddress;
        $rows[$index]['address'] = $customerAddress;
        $rows[$index]['customerAddressUpdatedAt'] = $now;
        $rows[$index]['customerAddressUpdatedBy'] = trim((string)($payload['employeeName'] ?? $payload['updatedBy'] ?? '管理者'));
    }
    if (array_key_exists('storeAddress', $payload)) {
        $storeAddress = trim((string)$payload['storeAddress']);
        if (mb_strlen($storeAddress, 'UTF-8') > 1000) respond(['ok' => false, 'error' => '超商地址內容過長'], 400);
        if (!isset($rows[$index]['customer']) || !is_array($rows[$index]['customer'])) $rows[$index]['customer'] = [];
        $rows[$index]['customer']['storeAddress'] = $storeAddress;
        $rows[$index]['storeAddress'] = $storeAddress;
    }
    if (array_key_exists('homeAddress', $payload)) {
        $homeAddress = trim((string)$payload['homeAddress']);
        if (mb_strlen($homeAddress, 'UTF-8') > 1000) respond(['ok' => false, 'error' => '住家地址內容過長'], 400);
        if (!isset($rows[$index]['customer']) || !is_array($rows[$index]['customer'])) $rows[$index]['customer'] = [];
        $rows[$index]['customer']['homeAddress'] = $homeAddress;
        $rows[$index]['homeAddress'] = $homeAddress;
    }
    if (array_key_exists('addressPrimary', $payload)) {
        $addressPrimary = strtolower(trim((string)$payload['addressPrimary']));
        if ($addressPrimary === 'post') $addressPrimary = 'home';
        if ($addressPrimary !== '' && !in_array($addressPrimary, ['store', 'home'], true)) {
            respond(['ok' => false, 'error' => '本次出貨地址只能選超商或住家'], 400);
        }
        if (!isset($rows[$index]['customer']) || !is_array($rows[$index]['customer'])) $rows[$index]['customer'] = [];
        $rows[$index]['customer']['addressPrimary'] = $addressPrimary;
        $rows[$index]['addressPrimary'] = $addressPrimary;
    }
    if (array_key_exists('deliveryType', $payload)) {
        $deliveryType = strtolower(trim((string)$payload['deliveryType']));
        $deliveryLabel = trim((string)($payload['deliveryLabel'] ?? ''));
        if ($deliveryType === 'post') $deliveryType = 'home';
        if ($deliveryType !== '' && !in_array($deliveryType, ['store', 'home', 'self', 'indonesia_sales'], true)) {
            respond(['ok' => false, 'error' => '配送方式不正確'], 400);
        }
        if ($deliveryLabel === '') $deliveryLabel = $deliveryType === 'store' ? '超商取貨' : ($deliveryType === 'home' ? '住家宅配' : $deliveryLabel);
        if (!isset($rows[$index]['customer']) || !is_array($rows[$index]['customer'])) $rows[$index]['customer'] = [];
        $rows[$index]['customer']['deliveryType'] = $deliveryType;
        $rows[$index]['customer']['deliveryLabel'] = $deliveryLabel;
        $rows[$index]['deliveryType'] = $deliveryType;
        $rows[$index]['deliveryLabel'] = $deliveryLabel;
    }
    if (array_key_exists('customerName', $payload)) {
        $customerName = trim((string)$payload['customerName']);
        if ($customerName === '') respond(['ok' => false, 'error' => '客戶姓名不可留空'], 400);
        if (mb_strlen($customerName, 'UTF-8') > 100) respond(['ok' => false, 'error' => '客戶姓名內容過長'], 400);
        if (!isset($rows[$index]['customer']) || !is_array($rows[$index]['customer'])) $rows[$index]['customer'] = [];
        $rows[$index]['customer']['name'] = $customerName;
        $rows[$index]['customerName'] = $customerName;
        $rows[$index]['name'] = $customerName;
        $rows[$index]['customerNameUpdatedAt'] = $now;
        $rows[$index]['customerNameUpdatedBy'] = trim((string)($payload['employeeName'] ?? $payload['updatedBy'] ?? '管理者'));
    }
    if (array_key_exists('customerPhone', $payload)) {
        $customerPhone = trim((string)$payload['customerPhone']);
        if (mb_strlen($customerPhone, 'UTF-8') > 80) respond(['ok' => false, 'error' => '客戶電話內容過長'], 400);
        if (!isset($rows[$index]['customer']) || !is_array($rows[$index]['customer'])) $rows[$index]['customer'] = [];
        if ($customerPhone !== '') {
            $rows[$index]['customer']['phone'] = $customerPhone;
            $rows[$index]['customerPhone'] = $customerPhone;
            $rows[$index]['phone'] = $customerPhone;
        }
        $rows[$index]['customerPhoneUpdatedAt'] = $now;
        $rows[$index]['customerPhoneUpdatedBy'] = trim((string)($payload['employeeName'] ?? $payload['updatedBy'] ?? '管理者'));
    }
    if (array_key_exists('customerImages', $payload)) {
        $customerImages = is_array($payload['customerImages'] ?? null)
            ? array_values(array_filter(array_map('strval', $payload['customerImages'])))
            : [];
        $customerImages = array_slice(array_values(array_unique($customerImages)), 0, 12);
        foreach ($customerImages as $customerImage) {
            if (strlen($customerImage) > 1500000) respond(['ok' => false, 'error' => '單張客戶圖片容量過大，請重新選擇'], 413);
            if (!preg_match('/^(data:image\/(?:jpeg|png|webp);base64,|https?:\/\/|\/?(?:uploads|assets)\/|\.\.?\/)/i', $customerImage)) {
                respond(['ok' => false, 'error' => '客戶圖片格式不正確'], 400);
            }
        }
        if (!isset($rows[$index]['customer']) || !is_array($rows[$index]['customer'])) $rows[$index]['customer'] = [];
        $rows[$index]['customer']['images'] = $customerImages;
        $rows[$index]['customer']['latestImage'] = $customerImages[0] ?? '';
        $rows[$index]['customer']['image'] = $customerImages[0] ?? '';
        $rows[$index]['customer']['screenshot'] = $customerImages[0] ?? '';
        $rows[$index]['customer']['photo'] = $customerImages[0] ?? '';
        $rows[$index]['customerImages'] = $customerImages;
        $rows[$index]['customerImage'] = $customerImages[0] ?? '';
        $rows[$index]['customerPhoto'] = $customerImages[0] ?? '';
        $rows[$index]['customerImagesUpdatedAt'] = $now;
        $rows[$index]['customerImagesUpdatedBy'] = trim((string)($payload['employeeName'] ?? $payload['updatedBy'] ?? '管理者'));
    }
    if (array_key_exists('freightDispatchDecision', $payload)) {
        $dispatchDecision = strtolower(trim((string)$payload['freightDispatchDecision']));
        $dispatchLabels = [
            'wait_all' => '先保留庫存，等其他商品再出',
            'ship_now' => '現在寄出／一起出貨',
            'ask_sales' => '先詢問業務，暫不出貨',
        ];
        if ($dispatchDecision !== '' && !isset($dispatchLabels[$dispatchDecision])) {
            respond(['ok' => false, 'error' => '客戶配送決定不正確'], 400);
        }
        if ($dispatchDecision !== '') {
            $rows[$index]['freightDispatchDecision'] = $dispatchDecision;
            $rows[$index]['freightDispatchDecisionLabel'] = $dispatchLabels[$dispatchDecision];
            $rows[$index]['freightDispatchDecisionAt'] = $now;
            $rows[$index]['freightDispatchNeedsSalesReview'] = $dispatchDecision === 'ask_sales';
            $rows[$index]['freightPartialShipmentRequested'] = $dispatchDecision === 'ship_now' && empty($rows[$index]['inventoryReceived']);
            if ($dispatchDecision === 'ask_sales' && empty($rows[$index]['waitNotify'])) {
                apply_wait_notify_fields(
                    $rows[$index],
                    true,
                    trim((string)($payload['employeeName'] ?? $payload['updatedBy'] ?? '管理者')),
                    $now,
                    '先詢問業務，暫不出貨',
                    'ask_sales'
                );
            }
            if ($dispatchDecision === 'ship_now' && !empty($rows[$index]['waitNotify'])) {
                apply_wait_notify_fields(
                    $rows[$index],
                    false,
                    trim((string)($payload['employeeName'] ?? $payload['updatedBy'] ?? '管理者')),
                    $now,
                    '已輸入物流單號，改為現在出貨',
                    'tracking_ready'
                );
            }
        }
    }
    if (array_key_exists('shippingNote', $payload)) {
        $shippingNote = trim((string)$payload['shippingNote']);
        if (mb_strlen($shippingNote, 'UTF-8') > 2000) {
            respond(['ok' => false, 'error' => '出貨備註不可超過 2000 字'], 400);
        }
        $rows[$index]['shippingNote'] = $shippingNote;
        $rows[$index]['shippingNoteUpdatedAt'] = $now;
        $rows[$index]['shippingNoteUpdatedBy'] = trim((string)($payload['employeeName'] ?? $payload['updatedBy'] ?? '管理者'));
    }
    if (array_key_exists('shippingCarrier', $payload)) {
        $carrier = trim((string)$payload['shippingCarrier']);
        if (mb_strlen($carrier, 'UTF-8') > 80) respond(['ok' => false, 'error' => '物流公司名稱過長'], 400);
        $rows[$index]['shippingCarrier'] = $carrier;
        if (!isset($rows[$index]['customer']) || !is_array($rows[$index]['customer'])) $rows[$index]['customer'] = [];
        $rows[$index]['customer']['shippingCarrier'] = $carrier;
    }
    if (array_key_exists('trackingNo', $payload) || array_key_exists('outboundParcels', $payload)) {
        $parcels = lz_outbound_parcels_from_payload($payload, $rows[$index]);
        foreach ($parcels as $parcel) {
            $trackingNo = lz_norm_tracking((string)($parcel['trackingNo'] ?? ''));
            if ($trackingNo === '') continue;
            if (mb_strlen($trackingNo, 'UTF-8') > 80) respond(['ok' => false, 'error' => '物流單號過長'], 400);
        }
        $carrier = trim((string)($payload['shippingCarrier'] ?? ($rows[$index]['shippingCarrier'] ?? '')));
        lz_apply_outbound_parcels($rows[$index], $parcels, $carrier);
    }
    if (array_key_exists('shippingFeeMode', $payload) || array_key_exists('indonesiaSelfShip', $payload) || array_key_exists('shippingFee', $payload)) {
        $mode = strtolower(trim((string)($payload['shippingFeeMode'] ?? '')));
        $selfShip = $mode === 'id_self' || filter_var($payload['indonesiaSelfShip'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($selfShip) $mode = 'id_self';
        elseif ($mode !== 'free' && $mode !== 'standard') {
            $mode = filter_var($payload['freeShipping'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'free' : 'standard';
        }
        $regular = array_key_exists('regularShippingFee', $payload)
            ? max(0.0, (float)$payload['regularShippingFee'])
            : max(0.0, (float)($rows[$index]['regularShippingFee'] ?? ($rows[$index]['customer']['regularShippingFee'] ?? 0)));
        $free = $mode === 'free';
        $fee = ($free || $selfShip) ? 0.0 : (array_key_exists('shippingFee', $payload) ? max(0.0, (float)$payload['shippingFee']) : $regular);
        if (!isset($rows[$index]['customer']) || !is_array($rows[$index]['customer'])) $rows[$index]['customer'] = [];
        $rows[$index]['shippingFeeMode'] = $mode;
        $rows[$index]['indonesiaSelfShip'] = $selfShip;
        $rows[$index]['freeShipping'] = $free;
        $rows[$index]['shippingFee'] = $fee;
        $rows[$index]['regularShippingFee'] = $regular;
        $rows[$index]['customer']['shippingFeeMode'] = $mode;
        $rows[$index]['customer']['indonesiaSelfShip'] = $selfShip;
        $rows[$index]['customer']['freeShipping'] = $free;
        $rows[$index]['customer']['shippingFee'] = $fee;
        $rows[$index]['customer']['regularShippingFee'] = $regular;
        $subtotal = max(0.0, (float)($rows[$index]['subtotal'] ?? 0));
        $rows[$index]['total'] = $subtotal + $fee;
    }
    if (array_key_exists('freightShippingSelected', $payload)) {
        $shippingSelected = filter_var($payload['freightShippingSelected'], FILTER_VALIDATE_BOOLEAN);
        $shippingSelectedBy = trim((string)($payload['employeeName'] ?? $payload['updatedBy'] ?? '管理者'));
        $rows[$index]['freightShippingSelected'] = $shippingSelected;
        if ($shippingSelected) {
            $rows[$index]['freightShippingSelectedAt'] = $now;
            $rows[$index]['freightShippingSelectedBy'] = $shippingSelectedBy;
        } else {
            $rows[$index]['freightShippingRemovedAt'] = $now;
            $rows[$index]['freightShippingRemovedBy'] = $shippingSelectedBy;
        }
    }
    if (array_key_exists('manualQueuePriority', $payload)) {
        $manualQueuePriority = filter_var($payload['manualQueuePriority'], FILTER_VALIDATE_BOOLEAN);
        $priorityBy = trim((string)($payload['employeeName'] ?? $payload['updatedBy'] ?? '管理者'));
        $priorityReason = trim((string)($payload['manualQueuePriorityReason'] ?? ''));
        if ($manualQueuePriority) {
            $inquiryStatus = strtolower(receipt_text($rows[$index]['status'] ?? ''));
            if ($inquiryStatus === 'cancelled') {
                respond(['ok' => false, 'error' => '這張已客戶取消，不能再插隊'], 409);
            }
            if ($inquiryStatus === 'converted' || receipt_text($rows[$index]['convertedOrderId'] ?? '') !== '' || receipt_text($rows[$index]['preorderConvertedAt'] ?? '') !== '') {
                respond(['ok' => false, 'error' => '這張已轉出貨單，不能再插隊'], 409);
            }
        }
        if ($manualQueuePriority && $priorityReason === '') {
            respond(['ok' => false, 'error' => '人工插隊必須填寫原因'], 400);
        }
        if (mb_strlen($priorityReason, 'UTF-8') > 300) {
            respond(['ok' => false, 'error' => '插隊原因不可超過 300 字'], 400);
        }
        $hasPriorityItemIndex = array_key_exists('manualQueuePriorityItemIndex', $payload);
        $allocateTaiwanStock = filter_var($payload['allocateTaiwanStock'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $priorityItemIndex = $hasPriorityItemIndex ? max(0, (int)($payload['manualQueuePriorityItemIndex'] ?? 0)) : null;
        $priorityProductCode = trim((string)($payload['manualQueuePriorityProductCode'] ?? ''));
        if ($manualQueuePriority && $priorityItemIndex === null) {
            $pendingItems = [];
            $itemsForPriority = is_array($rows[$index]['items'] ?? null) ? $rows[$index]['items'] : [];
            foreach ($itemsForPriority as $pendingIndex => $pendingItem) {
                if (!is_array($pendingItem)) continue;
                $requestedQty = max(1, (int)($pendingItem['requestedQty'] ?? $pendingItem['qty'] ?? $pendingItem['quantity'] ?? 1));
                $receivedQty = max(0, (int)($pendingItem['freightReceivedQty'] ?? 0));
                if ($receivedQty >= $requestedQty) continue;
                $pendingItems[] = [
                    'index' => (int)$pendingIndex,
                    'code' => trim((string)($pendingItem['code'] ?? $pendingItem['productCode'] ?? $pendingItem['sku'] ?? '')),
                ];
            }
            if (count($pendingItems) === 1) {
                $priorityItemIndex = $pendingItems[0]['index'];
                if ($priorityProductCode === '') {
                    $priorityProductCode = $pendingItems[0]['code'];
                }
            }
        }
        $rows[$index]['manualQueuePriority'] = $manualQueuePriority;
        $rows[$index]['manualQueuePriorityReason'] = $manualQueuePriority ? $priorityReason : '';
        $rows[$index]['manualQueuePriorityAt'] = $manualQueuePriority ? $now : '';
        $rows[$index]['manualQueuePriorityBy'] = $priorityBy;
        $rows[$index]['manualQueuePriorityItemIndex'] = $manualQueuePriority ? $priorityItemIndex : null;
        $rows[$index]['manualQueuePriorityProductCode'] = $manualQueuePriority ? $priorityProductCode : '';
        if ($manualQueuePriority && $priorityItemIndex !== null && isset($rows[$index]['items'][$priorityItemIndex]) && is_array($rows[$index]['items'][$priorityItemIndex])) {
            $rows[$index]['items'][$priorityItemIndex]['manualQueuePriorityAt'] = $now;
            $rows[$index]['items'][$priorityItemIndex]['manualQueuePriorityBy'] = $priorityBy;
            $rows[$index]['items'][$priorityItemIndex]['manualQueuePriorityReason'] = $priorityReason;
            if ($allocateTaiwanStock) {
                $priorityQty = max(1, (int)($rows[$index]['items'][$priorityItemIndex]['qty'] ?? $rows[$index]['items'][$priorityItemIndex]['quantity'] ?? $rows[$index]['items'][$priorityItemIndex]['requestedQty'] ?? 1));
                $rows[$index]['items'][$priorityItemIndex]['allocationSourceWarehouse'] = 'TW';
                $rows[$index]['items'][$priorityItemIndex]['manualPriorityAllocation'] = true;
                $rows[$index]['items'][$priorityItemIndex]['priorityAllocationStatus'] = 'warehouse_reserved';
                $rows[$index]['items'][$priorityItemIndex]['priorityPending'] = false;
                $rows[$index]['items'][$priorityItemIndex]['freightReceivedQty'] = $priorityQty;
                $rows[$index]['items'][$priorityItemIndex]['freightReceivedWarehouse'] = 'TW';
            }
        }
        $priorityHistory = is_array($rows[$index]['manualQueuePriorityHistory'] ?? null)
            ? array_values($rows[$index]['manualQueuePriorityHistory'])
            : [];
        $priorityHistory[] = [
            'enabled' => $manualQueuePriority,
            'at' => $now,
            'by' => $priorityBy,
            'itemIndex' => $manualQueuePriority ? $priorityItemIndex : null,
            'productCode' => $manualQueuePriority ? $priorityProductCode : '',
            'sourceWarehouse' => ($manualQueuePriority && $allocateTaiwanStock) ? 'TW' : '',
            'reason' => $manualQueuePriority ? $priorityReason : '恢復原始下單順位',
        ];
        $rows[$index]['manualQueuePriorityHistory'] = array_slice($priorityHistory, -100);
    }
    if (array_key_exists('manualQueueDisplaced', $payload)) {
        $manualQueueDisplaced = filter_var($payload['manualQueueDisplaced'], FILTER_VALIDATE_BOOLEAN);
        $displacedBy = trim((string)($payload['employeeName'] ?? $payload['updatedBy'] ?? '管理者'));
        $displacedForInquiryId = trim((string)($payload['manualQueueDisplacedByInquiryId'] ?? ''));
        $displacedQty = max(0, (int)($payload['manualQueueDisplacedQty'] ?? 0));
        $displacedReason = trim((string)($payload['manualQueueDisplacedReason'] ?? ''));
        $rows[$index]['manualQueueDisplaced'] = $manualQueueDisplaced;
        $rows[$index]['manualQueueDisplacedAt'] = $manualQueueDisplaced ? $now : '';
        $rows[$index]['manualQueueDisplacedBy'] = $displacedBy;
        $rows[$index]['manualQueueDisplacedByInquiryId'] = $manualQueueDisplaced ? $displacedForInquiryId : '';
        $rows[$index]['manualQueueDisplacedQty'] = $manualQueueDisplaced ? $displacedQty : 0;
        $rows[$index]['manualQueueDisplacedReason'] = $manualQueueDisplaced ? $displacedReason : '';
        $displacedHistory = is_array($rows[$index]['manualQueueDisplacedHistory'] ?? null)
            ? array_values($rows[$index]['manualQueueDisplacedHistory'])
            : [];
        $displacedHistory[] = [
            'enabled' => $manualQueueDisplaced,
            'at' => $now,
            'by' => $displacedBy,
            'priorityInquiryId' => $displacedForInquiryId,
            'qty' => $displacedQty,
            'reason' => $displacedReason,
            'label' => $manualQueueDisplaced ? '插隊後順位遞補／等待下一批' : '已重新配貨',
        ];
        $rows[$index]['manualQueueDisplacedHistory'] = array_slice($displacedHistory, -100);
    }
    $rows[$index]['purchaseNote'] = trim((string)($payload['purchaseNote'] ?? ($rows[$index]['purchaseNote'] ?? '')));
    if (array_key_exists('allocationNote', $payload)) {
        $rows[$index]['allocationNote'] = trim((string)$payload['allocationNote']);
    }
    $rows[$index]['purchasePlatform'] = trim((string)($payload['purchasePlatform'] ?? ($rows[$index]['purchasePlatform'] ?? $rows[$index]['platform'] ?? '')));
    $rows[$index]['supplierTrackingNo'] = trim((string)($payload['supplierTrackingNo'] ?? ($rows[$index]['supplierTrackingNo'] ?? '')));
    $rows[$index]['supplierCarrier'] = trim((string)($payload['supplierCarrier'] ?? ($rows[$index]['supplierCarrier'] ?? '')));
    foreach (['freightTrackingNo', 'freightItemId', 'freightBatchId', 'freightForwarder', 'freightCostMode', 'freightWarehouseTaxType', 'freightChargeType'] as $field) {
        if (array_key_exists($field, $payload)) $rows[$index][$field] = trim((string)$payload[$field]);
    }
    foreach (['freightAmountTwd', 'freightAllocationTwd'] as $field) {
        if (array_key_exists($field, $payload)) $rows[$index][$field] = max(0, round((float)$payload[$field], 2));
    }
    if (isset($payload['receiptLines']) && is_array($payload['receiptLines'])) {
        $rows[$index]['freightReceiptLines'] = array_values(array_map(static function ($line): array {
            $line = is_array($line) ? $line : [];
            return [
                'skuId' => trim((string)($line['skuId'] ?? '')),
                'productId' => trim((string)($line['productId'] ?? '')),
                'barcode' => trim((string)($line['barcode'] ?? '')),
                'color' => trim((string)($line['color'] ?? '')),
                'size' => trim((string)($line['size'] ?? 'NO SIZE')) ?: 'NO SIZE',
                'qty' => max(1, (int)($line['qty'] ?? 1)),
                'unitCostTwd' => max(0, round((float)($line['unitCostTwd'] ?? 0), 2)),
                'arrivalImage' => trim((string)($line['arrivalImage'] ?? '')),
                'proofRequired' => !empty($line['proofRequired']),
            ];
        }, $payload['receiptLines']));
        $rows[$index]['freightLinkedAt'] = $now;
    }
    if (isset($payload['receivingDocument']) && is_array($payload['receivingDocument'])) {
        $document = $payload['receivingDocument'];
        $rows[$index]['receivingDocument'] = [
            'documentNo' => trim((string)($document['documentNo'] ?? '')),
            'documentDate' => trim((string)($document['documentDate'] ?? '')),
            'sourceOrderNo' => trim((string)($document['sourceOrderNo'] ?? ($rows[$index]['id'] ?? ''))),
            'supplier' => trim((string)($document['supplier'] ?? '')),
            'purchasePlatform' => trim((string)($document['purchasePlatform'] ?? '')),
            'operatorName' => trim((string)($document['operatorName'] ?? $payload['employeeName'] ?? $payload['staffName'] ?? $payload['operatorName'] ?? '')),
            'arrivalWarehouse' => strtoupper(trim((string)($document['arrivalWarehouse'] ?? 'TW'))),
            'note' => trim((string)($document['note'] ?? '')),
            'status' => 'draft',
            'savedAt' => $now,
        ];
    }
    $rows[$index]['arrivalWarehouse'] = strtoupper((string)($payload['arrivalWarehouse'] ?? ($rows[$index]['arrivalWarehouse'] ?? $rows[$index]['preorderWarehouse'] ?? 'TW')));
    if (!isset($warehouseMap[$rows[$index]['arrivalWarehouse']])) $rows[$index]['arrivalWarehouse'] = $rows[$index]['preorderWarehouse'] ?? 'TW';
    if (!empty($rows[$index]['customerOrderOnly'])) $rows[$index]['platformPublishAllowed'] = false;
    elseif (array_key_exists('platformPublishAllowed', $payload)) $rows[$index]['platformPublishAllowed'] = !empty($payload['platformPublishAllowed']);
    $rows[$index]['purchaseUpdatedAt'] = $now;
    $employeeName = trim((string)($payload['employeeName'] ?? $payload['staffName'] ?? $payload['operatorName'] ?? ''));
    if ($employeeName === '') $employeeName = '未記錄員工';
    $currentProgress = [
        'purchaseStatus' => $purchaseStatus,
        'preorderWaitStatus' => $waitStatus,
        'purchaseNote' => $rows[$index]['purchaseNote'],
        'allocationNote' => (string)($rows[$index]['allocationNote'] ?? ''),
        'purchasePlatform' => $rows[$index]['purchasePlatform'],
        'supplierTrackingNo' => $rows[$index]['supplierTrackingNo'],
        'supplierCarrier' => $rows[$index]['supplierCarrier'],
        'arrivalWarehouse' => $rows[$index]['arrivalWarehouse'],
    ];
    if (json_encode($previousProgress) !== json_encode($currentProgress)) {
        $progressHistory = is_array($rows[$index]['preorderProgressHistory'] ?? null) ? $rows[$index]['preorderProgressHistory'] : [];
        $progressHistory[] = [
            'at' => $now,
            'by' => $employeeName,
            'purchaseStatus' => $purchaseStatus,
            'purchaseStatusLabel' => $allowedPurchase[$purchaseStatus],
            'preorderWaitStatus' => $waitStatus,
            'preorderWaitStatusLabel' => $waitStatus !== '' ? $allowedWait[$waitStatus] : '',
            'purchasePlatform' => $rows[$index]['purchasePlatform'],
            'supplierCarrier' => $rows[$index]['supplierCarrier'],
            'supplierTrackingNo' => $rows[$index]['supplierTrackingNo'],
            'arrivalWarehouse' => $rows[$index]['arrivalWarehouse'],
            'arrivalWarehouseLabel' => $warehouseMap[$rows[$index]['arrivalWarehouse']] ?? $rows[$index]['arrivalWarehouse'],
            'note' => $rows[$index]['purchaseNote'],
        ];
        $rows[$index]['preorderProgressHistory'] = array_slice($progressHistory, -50);
    }
    if ((string)($payload['checkAction'] ?? '') === 'checked') {
        $rows[$index]['preorderCheckedAt'] = $now;
        $rows[$index]['preorderCheckedBy'] = $employeeName;
        $rows[$index]['preorderCheckStatus'] = 'checked';
        $checkLogs = is_array($rows[$index]['preorderCheckLogs'] ?? null) ? $rows[$index]['preorderCheckLogs'] : [];
        $checkLogs[] = [
            'at' => $now,
            'by' => $employeeName,
            'action' => '核對',
            'purchaseStatus' => $purchaseStatus,
            'purchaseStatusLabel' => $allowedPurchase[$purchaseStatus],
            'preorderWaitStatus' => $waitStatus,
            'preorderWaitStatusLabel' => $waitStatus !== '' ? $allowedWait[$waitStatus] : '',
            'purchasePlatform' => $rows[$index]['purchasePlatform'],
            'note' => $rows[$index]['purchaseNote'],
        ];
        $rows[$index]['preorderCheckLogs'] = $checkLogs;
    }
    if ((string)($payload['transferAction'] ?? '') === 'transferred') {
        $rows[$index]['preorderTransferOutAt'] = $now;
        $rows[$index]['preorderTransferOutBy'] = $employeeName;
        $transferLogs = is_array($rows[$index]['preorderTransferLogs'] ?? null) ? $rows[$index]['preorderTransferLogs'] : [];
        $transferLogs[] = [
            'at' => $now,
            'by' => $employeeName,
            'action' => '轉出',
            'purchaseStatus' => $purchaseStatus,
            'purchaseStatusLabel' => $allowedPurchase[$purchaseStatus],
            'preorderWaitStatus' => $waitStatus,
            'preorderWaitStatusLabel' => $waitStatus !== '' ? $allowedWait[$waitStatus] : '',
            'purchasePlatform' => $rows[$index]['purchasePlatform'],
            'note' => $rows[$index]['purchaseNote'],
        ];
        $rows[$index]['preorderTransferLogs'] = $transferLogs;
    }
    if (!empty($payload['cancelShippingReservation'])) {
        $rows[$index]['reservedShippingStatus'] = 'cancelled';
        $rows[$index]['reservedShippingCancelledAt'] = $now;
        $rows[$index]['reservedShippingCancelledBy'] = trim((string)($payload['employeeName'] ?? $payload['updatedBy'] ?? '行政人員'));
    } elseif (array_key_exists('reservedShippingStatus', $payload)
        || array_key_exists('reservedDate', $payload)
        || array_key_exists('reservedShippingDate', $payload)
        || array_key_exists('holdReason', $payload)
        || array_key_exists('reservedShippingHoldReason', $payload)
    ) {
        $reservedDate = trim((string)($payload['reservedDate'] ?? $payload['reservedShippingDate'] ?? ''));
        $holdReason = trim((string)($payload['holdReason'] ?? $payload['reservedShippingHoldReason'] ?? ''));
        $holdAllowed = ['scheduled_ship', 'wait_live', 'wait_other', 'hold_no_ship', 'sales_no_ship', 'hospital', 'no_money', 'no_contact', 'store_refuse_unclaimed', 'other'];
        if ($holdReason === '' && $reservedDate !== '') $holdReason = 'scheduled_ship';
        if ($reservedDate !== '') {
            if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $reservedDate)) {
                respond(['ok' => false, 'error' => '請選擇正確的預約出貨日期'], 400);
            }
            $dateValue = strtotime($reservedDate . ' 23:59:59');
            if ($dateValue === false || $dateValue < strtotime(date('Y-m-d') . ' 00:00:00')) {
                respond(['ok' => false, 'error' => '預約日期不能早於今天'], 400);
            }
        } elseif ($holdReason === '' || $holdReason === 'scheduled_ship') {
            respond(['ok' => false, 'error' => '預約排單請選出貨日期；寄庫請改選寄庫原因，日期可空白'], 400);
        }
        if (!in_array($holdReason, $holdAllowed, true)) {
            respond(['ok' => false, 'error' => '寄庫原因不正確'], 400);
        }
        $holdReasonText = trim((string)($payload['holdReasonText'] ?? $payload['reservedShippingHoldReasonText'] ?? ''));
        if ($holdReason === 'other' && $holdReasonText === '') {
            respond(['ok' => false, 'error' => '選「其他」時請填寫原因'], 400);
        }
        if ($holdReason !== 'other') {
            $holdLabels = [
                'scheduled_ship' => '預約指定出貨日',
                'wait_live' => '等待直播',
                'wait_other' => '等其他商品到齊',
                'hold_no_ship' => '先不出貨',
                'sales_no_ship' => '業務告知不出貨',
                'hospital' => '客人在醫院',
                'no_money' => '客戶沒錢',
                'no_contact' => '聯絡不上',
                'store_refuse_unclaimed' => '超商拒寄：多次未取被限制（電話問題）',
            ];
            $holdReasonText = $holdLabels[$holdReason] ?? $holdReasonText;
        }
        $operator = trim((string)($payload['reservedBy'] ?? $payload['employeeName'] ?? $payload['updatedBy'] ?? '')) ?: '行政人員';
        $items = is_array($rows[$index]['items'] ?? null) ? array_values($rows[$index]['items']) : [];
        $itemQty = 0;
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $itemQty += max(0, (int)($item['qty'] ?? $item['quantity'] ?? $item['requestedQty'] ?? 0));
        }
        $rows[$index]['reservedShippingStatus'] = 'reserved';
        $rows[$index]['reservedShippingDate'] = $reservedDate;
        $rows[$index]['reservedShippingNote'] = trim((string)($payload['note'] ?? $payload['reservedShippingNote'] ?? ''));
        $rows[$index]['reservedShippingHoldReason'] = $holdReason;
        $rows[$index]['reservedShippingHoldReasonText'] = $holdReasonText;
        $rows[$index]['reservedShippingAt'] = $now;
        $rows[$index]['reservedShippingBy'] = $operator;
        $rows[$index]['reservedShippingItemCount'] = count($items);
        $rows[$index]['reservedShippingItemQty'] = $itemQty;
        if ($holdReason !== 'scheduled_ship' && empty($rows[$index]['freightDispatchDecision'])) {
            $rows[$index]['freightDispatchDecision'] = 'wait_all';
            $rows[$index]['freightDispatchDecisionLabel'] = '先保留庫存，等其他商品再出';
            $rows[$index]['freightDispatchDecisionAt'] = $now;
        }
    }
    $rows[$index]['updatedAt'] = $now;
    write_json($inquiriesFile, $rows);
    $contactChanged = array_key_exists('customerAddress', $payload)
        || array_key_exists('storeAddress', $payload)
        || array_key_exists('homeAddress', $payload)
        || array_key_exists('addressPrimary', $payload)
        || array_key_exists('deliveryType', $payload)
        || array_key_exists('customerName', $payload)
        || array_key_exists('customerPhone', $payload);
    if ($contactChanged) {
        $syncCustomer = is_array($rows[$index]['customer'] ?? null) ? $rows[$index]['customer'] : [];
        if (trim((string)($syncCustomer['name'] ?? '')) === '') {
            $syncCustomer['name'] = trim((string)($rows[$index]['customerName'] ?? $rows[$index]['name'] ?? ''));
        }
        if (trim((string)($syncCustomer['phone'] ?? '')) === '') {
            $syncCustomer['phone'] = trim((string)($rows[$index]['customerPhone'] ?? $rows[$index]['phone'] ?? ''));
        }
        try {
            sync_customer_contact_to_members($syncCustomer, $customersFile, $membersFile, $stateFile, $now);
        } catch (Throwable $error) {
            // Keep the inquiry save even if the member roster cannot be updated.
        }
    }
    respond(['ok' => true, 'inquiry' => $rows[$index]]);
}

if ($action === 'sales-update-prices') {
    $login = require_sales_inquiry_login($payload, $inquiry, $salesCustomersFile, $stateFile, $salesSessionsFile);
    require_sales_inquiry_editable($inquiry, false);
    $priceRows = is_array($payload['priceRows'] ?? null) ? $payload['priceRows'] : [];
    if (!$priceRows) respond(['ok' => false, 'error' => '請提供要修改的商品售價'], 400);
    if (!isset($rows[$index]['items']) || !is_array($rows[$index]['items'])) $rows[$index]['items'] = [];
    $updatedCount = 0;
    foreach ($priceRows as $priceRow) {
        if (!is_array($priceRow) || !array_key_exists('index', $priceRow) || !array_key_exists('price', $priceRow)) {
            respond(['ok' => false, 'error' => '商品售價資料格式不正確'], 400);
        }
        $itemIndex = (int)$priceRow['index'];
        if ($itemIndex < 0 || !isset($rows[$index]['items'][$itemIndex]) || !is_array($rows[$index]['items'][$itemIndex])) {
            respond(['ok' => false, 'error' => '找不到要修改售價的商品明細'], 404);
        }
        $incomingSkuId = trim((string)($priceRow['skuId'] ?? ''));
        $currentSkuId = trim((string)($rows[$index]['items'][$itemIndex]['skuId'] ?? $rows[$index]['items'][$itemIndex]['sku'] ?? $rows[$index]['items'][$itemIndex]['code'] ?? ''));
        if ($incomingSkuId !== '' && $currentSkuId !== '' && $incomingSkuId !== $currentSkuId) {
            respond(['ok' => false, 'error' => '商品明細已更新，請重新整理後再改價'], 409);
        }
        if (!is_numeric($priceRow['price'])) respond(['ok' => false, 'error' => '商品售價必須是數字'], 400);
        $price = (float)$priceRow['price'];
        if (!is_finite($price) || $price < 0) respond(['ok' => false, 'error' => '商品售價不可小於 0'], 400);
        $rows[$index]['items'][$itemIndex]['price'] = round($price, 2);
        $updatedCount++;
    }
    if ($updatedCount < 1) respond(['ok' => false, 'error' => '沒有可更新的商品售價'], 400);

    $oldTotal = max(0, (float)($inquiry['total'] ?? 0));
    $subtotal = 0.0;
    foreach ($rows[$index]['items'] as &$item) {
        $qty = max(0, (int)($item['qty'] ?? 0));
        $lineSubtotal = round(max(0, (float)($item['price'] ?? 0)) * $qty, 2);
        $item['subtotal'] = $lineSubtotal;
        $subtotal += $lineSubtotal;
    }
    unset($item);
    $subtotal = round($subtotal, 2);
    $isPreorderPriceUpdate = strtolower(trim((string)($rows[$index]['orderType'] ?? ''))) === 'preorder';
    $regularShippingFee = max(0, (float)(
        $rows[$index]['regularShippingFee']
        ?? $rows[$index]['customer']['regularShippingFee']
        ?? $rows[$index]['shippingFee']
        ?? $rows[$index]['customer']['shippingFee']
        ?? 0
    ));
    $freeShipping = $isPreorderPriceUpdate && $subtotal >= 1200;
    $shippingFee = $freeShipping ? 0.0 : $regularShippingFee;
    $newTotal = round($subtotal + $shippingFee, 2);
    $rows[$index]['subtotal'] = $subtotal;
    $rows[$index]['shippingFee'] = $shippingFee;
    $rows[$index]['regularShippingFee'] = $regularShippingFee;
    $rows[$index]['freeShipping'] = $freeShipping;
    $rows[$index]['freeShippingThreshold'] = 1200;
    $rows[$index]['freeShippingBusinessRate'] = $freeShipping ? 0.3 : 0;
    $rows[$index]['freeShippingCompanyRate'] = $freeShipping ? 0.7 : 0;
    if (!isset($rows[$index]['customer']) || !is_array($rows[$index]['customer'])) $rows[$index]['customer'] = [];
    $rows[$index]['customer']['shippingFee'] = $shippingFee;
    $rows[$index]['customer']['regularShippingFee'] = $regularShippingFee;
    $rows[$index]['customer']['freeShipping'] = $freeShipping;
    $rows[$index]['total'] = $newTotal;
    $rows[$index]['updatedAt'] = date(DATE_ATOM);

    $paymentRows = read_json($orderPaymentsFile);
    $payment = reconcile_unshipped_inquiry_payment($rows[$index], $paymentRows, $inquiryId, $newTotal, 'ready-pending', $oldTotal, $login);
    write_json($orderPaymentsFile, $paymentRows);
    write_json($inquiriesFile, $rows);
    respond(['ok' => true, 'inquiry' => $rows[$index], 'payment' => $payment]);
}

if ($action === 'update-preorder-draft') {
    $login = require_sales_inquiry_login($payload, $inquiry, $salesCustomersFile, $stateFile, $salesSessionsFile);
    require_sales_inquiry_editable($inquiry, true);
    $incomingItems = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    $incomingItems = externalize_data_images($incomingItems, __DIR__);
    $existingDemandItems = is_array($rows[$index]['items'] ?? null) ? array_values($rows[$index]['items']) : [];
    $items = [];
    $seenLineIds = [];
    foreach ($incomingItems as $incomingIndex => $item) {
        if (!is_array($item)) continue;
        $qty = max(1, (int)($item['qty'] ?? 1));
        $price = max(0, (float)($item['price'] ?? 0));
        $title = trim((string)($item['title'] ?? $item['name'] ?? ''));
        $code = trim((string)($item['code'] ?? $item['sku'] ?? ''));
        if ($title === '' && $code === '') continue;
        $existingDemandItem = is_array($existingDemandItems[$incomingIndex] ?? null) ? $existingDemandItems[$incomingIndex] : [];
        $preferredLineId = trim((string)($item['lineId'] ?? $existingDemandItem['lineId'] ?? ''));
        if ($preferredLineId === '' && $existingDemandItem) {
            // Persist the exact deterministic ID already used by the purchase
            // ledger for an old row.  Generating a fresh INQL-* value here
            // would orphan earlier allocations on the first edit.
            $legacyVariant = lz_purchase_variant($rows[$index], $existingDemandItem, (int)$incomingIndex);
            $preferredLineId = lz_purchase_line_id($rows[$index], $existingDemandItem, (int)$incomingIndex, $legacyVariant);
        }
        $lineId = unique_inquiry_line_id($preferredLineId, $seenLineIds);
        $items[] = [
            'lineId' => $lineId,
            'requestedQty' => $qty,
            'skuId' => trim((string)($item['skuId'] ?? '')),
            'sku' => trim((string)($item['sku'] ?? '')),
            'productId' => trim((string)($item['productId'] ?? '')),
            'code' => $code,
            'title' => $title,
            'color' => trim((string)($item['color'] ?? '')),
            'size' => trim((string)($item['size'] ?? 'NO SIZE')) ?: 'NO SIZE',
            'qty' => $qty,
            'price' => $price,
            'subtotal' => round($price * $qty, 2),
            'image' => trim((string)($item['image'] ?? '')),
            'salesPurchaseImage' => !empty($item['salesPurchaseImage']),
        ];
    }
    if (!$items) respond(['ok' => false, 'error' => 'At least one product is required'], 400);
    $customerInput = externalize_data_images(is_array($payload['customer'] ?? null) ? $payload['customer'] : [], __DIR__);
    $customer = is_array($rows[$index]['customer'] ?? null) ? $rows[$index]['customer'] : [];
    foreach (['name', 'phone', 'address', 'note', 'deliveryType', 'deliveryLabel', 'channel', 'image', 'screenshot'] as $field) {
        if (array_key_exists($field, $customerInput) && $customerInput[$field] !== '') $customer[$field] = trim((string)$customerInput[$field]);
    }
    $customer = externalize_data_images($customer, __DIR__);
    $subtotal = round(array_reduce($items, fn($sum, $item) => $sum + ((float)$item['price'] * (int)$item['qty']), 0), 2);
    $regularShippingFee = max(0, (float)(
        $payload['regularShippingFee']
        ?? $customer['regularShippingFee']
        ?? $rows[$index]['regularShippingFee']
        ?? $payload['shippingFee']
        ?? $customer['shippingFee']
        ?? $rows[$index]['shippingFee']
        ?? 0
    ));
    $freeShipping = $subtotal >= 1200;
    $shippingFee = $freeShipping ? 0.0 : $regularShippingFee;
    $customer['shippingFee'] = $shippingFee;
    $customer['regularShippingFee'] = $regularShippingFee;
    $customer['freeShipping'] = $freeShipping;
    $customer['freeShippingThreshold'] = 1200;
    $customer['freeShippingBusinessRate'] = $freeShipping ? 0.3 : 0;
    $customer['freeShippingCompanyRate'] = $freeShipping ? 0.7 : 0;
    $oldTotal = max(0, (float)($inquiry['total'] ?? 0));
    $newTotal = round($subtotal + $shippingFee, 2);
    $rows[$index]['customer'] = $customer;
    $rows[$index]['items'] = $items;
    $rows[$index]['shippingFee'] = $shippingFee;
    $rows[$index]['regularShippingFee'] = $regularShippingFee;
    $rows[$index]['freeShipping'] = $freeShipping;
    $rows[$index]['freeShippingThreshold'] = 1200;
    $rows[$index]['freeShippingBusinessRate'] = $freeShipping ? 0.3 : 0;
    $rows[$index]['freeShippingCompanyRate'] = $freeShipping ? 0.7 : 0;
    $rows[$index]['subtotal'] = $subtotal;
    $rows[$index]['total'] = $newTotal;
    $warehouse = strtoupper((string)($payload['preorderWarehouse'] ?? $rows[$index]['preorderWarehouse'] ?? 'TW'));
    $rows[$index]['preorderWarehouse'] = in_array($warehouse, ['TW', 'ID', 'CN'], true) ? $warehouse : 'TW';
    $editWarehouseLabels = ['TW' => '採購倉 → 台灣倉', 'ID' => '採購倉 → 印尼倉', 'CN' => '採購倉 → 中國倉'];
    $rows[$index]['preorderWarehouseLabel'] = $editWarehouseLabels[$rows[$index]['preorderWarehouse']];
    if (array_key_exists('preorderWarehouse', $payload) || empty($rows[$index]['preorderFulfillWarehouse'])) {
        $rows[$index]['preorderFulfillWarehouse'] = $rows[$index]['preorderWarehouse'];
        $rows[$index]['preorderFulfillWarehouseLabel'] = $rows[$index]['preorderWarehouseLabel'];
    }
    $rows[$index]['trackingNo'] = trim((string)($payload['trackingNo'] ?? $rows[$index]['trackingNo'] ?? ''));
    $rows[$index]['updatedAt'] = date(DATE_ATOM);
    $paymentRows = read_json($orderPaymentsFile);
    $payment = reconcile_unshipped_inquiry_payment($rows[$index], $paymentRows, $inquiryId, $newTotal, 'preorder', $oldTotal, $login);
    write_json($orderPaymentsFile, $paymentRows);
    write_json($inquiriesFile, $rows);
    $memberPhotoSync = ['ok' => true, 'synced' => false, 'reason' => 'not-run'];
    try {
        $memberPhotoSync = sync_customer_member_photo($customer, __DIR__, 'inquiry-edit');
    } catch (Throwable $error) {
        $memberPhotoSync = ['ok' => false, 'synced' => false, 'error' => $error->getMessage()];
    }
    respond(['ok' => true, 'inquiry' => $rows[$index], 'payment' => $payment, 'memberPhotoSync' => $memberPhotoSync]);
}

if ($action === 'receive-preorder') {
    $operationLock = acquire_preorder_inventory_lock($inquiriesFile, $inquiryId);
    $rows = read_json($inquiriesFile);
    $index = find_index($rows, $inquiryId);
    if ($index < 0) respond(['ok' => false, 'error' => '找不到這筆預購單，請重新整理後再試'], 404);
    $inquiry = $rows[$index];
    $now = date(DATE_ATOM);
    $operationId = preorder_inventory_operation_id('receive', $inquiryId);
    $isPreorder = ((string)($inquiry['orderType'] ?? '') === 'preorder') || ((string)($inquiry['status'] ?? '') === 'preorder_pending');
    if (!$isPreorder) respond(['ok' => false, 'error' => 'Only preorder can be received'], 409);
    $savedReceiveOperationId = (string)($inquiry['preorderReceiveOperationId'] ?? ($inquiry['receiveOperation']['id'] ?? ''));
    $alreadyFinalized = !empty($inquiry['inventoryReceived']);
    if ($alreadyFinalized && $savedReceiveOperationId !== $operationId) {
        respond(['ok' => false, 'error' => '這張預購單已由舊流程完成入庫，不能再次增加庫存'], 409);
    }
    $items = is_array($inquiry['items'] ?? null) ? $inquiry['items'] : [];
    $requestedWarehouse = strtoupper((string)($payload['arrivalWarehouse'] ?? ($inquiry['preorderWarehouse'] ?? 'TW')));
    $warehouseMap = ['TW' => '台灣倉 / Gudang Taiwan', 'ID' => '印尼倉 / Gudang Indonesia', 'CN' => '中國倉 / Gudang China'];
    if (!isset($warehouseMap[$requestedWarehouse])) $requestedWarehouse = 'TW';
    $warehouse = $requestedWarehouse;
    $durableReceiptEntries = inventory_transaction_marker_entries(read_json($skusFile), $operationId);
    $durableReceiptPayloadHash = (string)($durableReceiptEntries[0]['payloadHash'] ?? '');
    if ($alreadyFinalized || $durableReceiptEntries) {
        $durableWarehouse = strtoupper((string)($durableReceiptEntries[0]['warehouse'] ?? ($inquiry['arrivalWarehouse'] ?? ($inquiry['receiveOperation']['warehouse'] ?? ($inquiry['preorderWarehouse'] ?? 'TW')))));
        if (!isset($warehouseMap[$durableWarehouse])) $durableWarehouse = 'TW';
        if (array_key_exists('arrivalWarehouse', $payload) && $requestedWarehouse !== $durableWarehouse) {
            respond(['ok' => false, 'error' => '這張預購單已完成入庫，不能用不同到貨倉重送'], 409);
        }
        $warehouse = $durableWarehouse;
    }
    $receivedBy = trim((string)($payload['receivedBy'] ?? $payload['employeeName'] ?? '')) ?: '未記錄員工';
    if ($alreadyFinalized) {
        $receivedBy = trim((string)($inquiry['preorderReceivedBy'] ?? $receivedBy)) ?: $receivedBy;
        $documentInput = is_array($inquiry['receivingDocument'] ?? null) ? $inquiry['receivingDocument'] : [];
    } else {
        $durableReceiveContext = isset($durableReceiptEntries[0]['operationContext']) && is_array($durableReceiptEntries[0]['operationContext'])
            ? $durableReceiptEntries[0]['operationContext']
            : [];
        if (!array_key_exists('receivedBy', $payload) && !array_key_exists('employeeName', $payload) && trim((string)($durableReceiveContext['receivedBy'] ?? '')) !== '') {
            $receivedBy = trim((string)$durableReceiveContext['receivedBy']);
        }
        $documentInput = isset($payload['receivingDocument']) && is_array($payload['receivingDocument'])
            ? $payload['receivingDocument']
            : (is_array($durableReceiveContext['receivingDocument'] ?? null) && $durableReceiveContext['receivingDocument']
                ? $durableReceiveContext['receivingDocument']
                : (is_array($rows[$index]['receivingDocument'] ?? null) ? $rows[$index]['receivingDocument'] : []));
    }
    $receivingDocumentNo = trim((string)($documentInput['documentNo'] ?? ''));
    if ($receivingDocumentNo === '') $receivingDocumentNo = 'REC-' . strtoupper(substr(hash('sha256', $operationId), 0, 12));

    // Freight-linked preorder receiving must follow the same physical receiving gate as
    // a standalone purchase receipt. A tracking number alone is not proof that the
    // package belongs to a checked batch, and a batch with missing pieces cannot enter stock.
    $preorderFreightInput = $payload;
    foreach (['freightTrackingNo', 'freightItemId', 'freightBatchId'] as $freightKey) {
        if (receipt_text($preorderFreightInput[$freightKey] ?? '') === '') {
            $preorderFreightInput[$freightKey] = $inquiry[$freightKey] ?? '';
        }
    }
    if ($durableReceiptEntries) {
        $durableFreightSnapshot = isset($durableReceiptEntries[0]['freightCostSnapshot']) && is_array($durableReceiptEntries[0]['freightCostSnapshot'])
            ? $durableReceiptEntries[0]['freightCostSnapshot']
            : [];
        $durableFreightMap = ['freightTrackingNo' => 'trackingNo', 'freightItemId' => 'itemId', 'freightBatchId' => 'batchId'];
        foreach ($durableFreightMap as $inputKey => $snapshotKey) {
            if (receipt_text($preorderFreightInput[$inputKey] ?? '') === '') {
                $preorderFreightInput[$inputKey] = $durableFreightSnapshot[$snapshotKey] ?? '';
            }
        }
    }
    // Match standalone receipt lock order: operation/document lock -> freight -> inventory.
    // Holding this lock through the SKU commit prevents either receiving endpoint
    // from overwriting the other's durable batch claim.
    $preorderHasFreightLink = receipt_text($preorderFreightInput['freightTrackingNo'] ?? '') !== ''
        || receipt_text($preorderFreightInput['freightItemId'] ?? '') !== ''
        || receipt_text($preorderFreightInput['freightBatchId'] ?? '') !== '';
    $preorderFreightFileLock = $preorderHasFreightLink ? acquire_freight_file_lock($freightTrackingFile) : null;
    $preorderFreight = freight_receipt_snapshot($preorderFreightInput, $freightTrackingFile);
    $preorderBatchId = receipt_text($preorderFreight['batchId'] ?? '');
    if ($preorderHasFreightLink && $preorderBatchId === '') {
        respond(['ok' => false, 'error' => '這張預購單的物流單號尚未對應有效集運批次，請先建立並綁定批次後再驗收入庫'], 409);
    }
    $preorderFreightData = read_json($freightTrackingFile);
    $preorderBatchIndex = -1;
    if ($preorderBatchId !== '' && isset($preorderFreightData['batches']) && is_array($preorderFreightData['batches'])) {
        foreach ($preorderFreightData['batches'] as $batchIndex => $batch) {
            if (!is_array($batch) || (string)($batch['id'] ?? '') !== $preorderBatchId) continue;
            $preorderBatchIndex = $batchIndex;
            $packageRows = isset($batch['packageRows']) && is_array($batch['packageRows']) ? $batch['packageRows'] : [];
            $receiving = isset($batch['receiving']) && is_array($batch['receiving']) ? $batch['receiving'] : [];
            if (!$packageRows) respond(['ok' => false, 'error' => '這個集運批次沒有物流物件明細，請先重新綁入物流單號後再驗收入庫'], 409);
            if (empty($receiving['startedAt'])) respond(['ok' => false, 'error' => '這個集運批次尚未開始到台灣點貨，請先到物流集運完成點貨'], 409);
            if (empty($receiving['completedAt'])) respond(['ok' => false, 'error' => '這個集運批次仍在點貨中，尚不能正式入庫'], 409);
            if ((int)($receiving['missingQty'] ?? 0) > 0 || (string)($batch['status'] ?? '') === '缺件待處理') respond(['ok' => false, 'error' => '這個集運批次仍有缺件，補點完成前不能正式入庫'], 409);
            break;
        }
    }
    if ($preorderBatchId !== '' && $preorderBatchIndex < 0) {
        respond(['ok' => false, 'error' => '找不到這個物流集運批次，請重新帶入有效批號後再確認入庫'], 409);
    }
    $effectiveReceiptLines = isset($preorderFreightInput['receiptLines']) && is_array($preorderFreightInput['receiptLines'])
        ? array_values($preorderFreightInput['receiptLines'])
        : (isset($inquiry['freightReceiptLines']) && is_array($inquiry['freightReceiptLines']) ? array_values($inquiry['freightReceiptLines']) : []);
    $payloadHash = preorder_operation_payload_hash([
        'warehouse' => $warehouse,
        'freightBatchId' => $preorderBatchId,
        'freightItemId' => receipt_text($preorderFreight['itemId'] ?? ''),
        'freightTrackingNo' => receipt_text($preorderFreight['trackingNo'] ?? ''),
        'receiptLines' => $effectiveReceiptLines,
        'items' => $effectiveReceiptLines || $alreadyFinalized ? [] : array_map(static fn($item) => is_array($item) ? [
            'skuId' => (string)($item['skuId'] ?? $item['sku'] ?? ''),
            'productId' => (string)($item['productId'] ?? ''),
            'color' => (string)($item['color'] ?? ''),
            'size' => (string)($item['size'] ?? ''),
            'qty' => max(0, (int)($item['qty'] ?? 0)),
        ] : [], $items),
    ]);
    $savedReceivePayloadHash = (string)($inquiry['preorderReceivePayloadHash'] ?? ($inquiry['receiveOperation']['payloadHash'] ?? ''));
    if ($durableReceiptPayloadHash !== '' && !array_key_exists('receiptLines', $payload)) {
        // The canonical SKU marker may exist even if the request stopped before the
        // inquiry was finalized. A reload can therefore resume without resending the
        // resolved form lines, while an explicit replacement is still hash-checked.
        $payloadHash = $durableReceiptPayloadHash;
    } elseif ($alreadyFinalized && $savedReceivePayloadHash !== '' && !array_key_exists('receiptLines', $payload)) {
        // A normal browser replay does not resend the original resolved receipt lines.
        // Reuse the durable hash; explicit replacement lines are still verified below.
        $payloadHash = $savedReceivePayloadHash;
    }
    if ($alreadyFinalized && $savedReceivePayloadHash !== '' && !hash_equals($savedReceivePayloadHash, $payloadHash)) {
        respond(['ok' => false, 'error' => '這張預購單已用不同到貨內容完成入庫，不能覆寫原收貨資料'], 409);
    }
    if ($preorderBatchIndex >= 0) {
        $batch = $preorderFreightData['batches'][$preorderBatchIndex];
        $claim = isset($batch['inventoryReceiptClaim']) && is_array($batch['inventoryReceiptClaim']) ? $batch['inventoryReceiptClaim'] : [];
        $claimOperationId = trim((string)($claim['operationId'] ?? ''));
        $claimDocumentId = trim((string)($claim['documentId'] ?? ''));
        $claimHash = trim((string)($claim['payloadHash'] ?? ''));
        $legacyReceived = !empty($batch['inventoryReceivedAt']) || (string)($batch['status'] ?? '') === '已入庫' || trim((string)($batch['receivingDocumentNo'] ?? '')) !== '';
        if ($claimOperationId !== '' && ($claimOperationId !== $operationId || ($claimDocumentId !== '' && $claimDocumentId !== $inquiryId))) {
            respond(['ok' => false, 'error' => '這個物流集運批次已被另一張進貨或預購單領用，不能重複入庫'], 409);
        }
        if ($claimOperationId === '' && $legacyReceived) {
            $legacyDocumentNo = mb_strtolower(trim((string)($batch['receivingDocumentNo'] ?? '')), 'UTF-8');
            if (!$alreadyFinalized || $legacyDocumentNo === '' || $legacyDocumentNo !== mb_strtolower($receivingDocumentNo, 'UTF-8')) {
                respond(['ok' => false, 'error' => '這個物流集運批次已完成入庫，請先核對原進貨單'], 409);
            }
        }
        if ($claimHash !== '' && !hash_equals($claimHash, $payloadHash) && $durableReceiptEntries) {
            respond(['ok' => false, 'error' => '這個物流集運批次已用不同入庫內容完成庫存交易'], 409);
        }
        $preorderFreightData['batches'][$preorderBatchIndex]['inventoryReceiptClaim'] = [
            'operationId' => $operationId,
            'documentId' => $inquiryId,
            'documentNo' => $receivingDocumentNo,
            'payloadHash' => $payloadHash,
            'status' => $alreadyFinalized ? 'received' : 'claimed',
            'claimedAt' => receipt_first_text([$claim['claimedAt'] ?? '', $now]),
            'updatedAt' => $now,
        ];
        $preorderFreightData['updatedAt'] = $now;
        write_json($freightTrackingFile, $preorderFreightData);
    }
    // Inventory only changes at confirmed receipt. Resolve the SKU inside the actual
    // arrival warehouse first, then create that warehouse variant only when missing.
    // This also keeps barcode, color, size and the verified freight cost snapshot together.
    $receipt = apply_preorder_receipt_inventory($inquiry, $preorderFreightInput, $warehouse, $productsFile, $skusFile, $stateFile, $freightTrackingFile, $operationId, $payloadHash);
    $items = $receipt['items'];
    $originalDemandItems = is_array($inquiry['items'] ?? null) ? array_values($inquiry['items']) : [];
    foreach ($items as $receivedIndex => &$receivedItem) {
        if (!is_array($receivedItem)) continue;
        $originalItem = is_array($originalDemandItems[$receivedIndex] ?? null) ? $originalDemandItems[$receivedIndex] : [];
        $receivedItem['lineId'] = trim((string)($receivedItem['lineId'] ?? $originalItem['lineId'] ?? ''))
            ?: ('LEGACY-LINE-' . strtoupper(substr(hash('sha256', $inquiryId . '|' . $receivedIndex), 0, 24)));
        $receivedItem['requestedQty'] = max(1, (int)($originalItem['requestedQty'] ?? $originalItem['qty'] ?? $receivedItem['requestedQty'] ?? $receivedItem['qty'] ?? 1));
        $receivedItem['receivedQty'] = max(0, (int)($receivedItem['qty'] ?? 0));
    }
    unset($receivedItem);
    $rows[$index]['items'] = $items;
    $durablePublishAllowed = !empty($durableReceiptEntries[0]['operationContext']['platformPublishAllowed']);
    $publishAllowed = $alreadyFinalized
        ? !empty($inquiry['platformPublishAllowed'])
        : (empty($inquiry['customerOrderOnly']) && ($durableReceiptEntries ? $durablePublishAllowed : !empty($payload['platformPublishAllowed'])));
    if ($publishAllowed) {
        $publishInventoryLock = acquire_inventory_file_lock($skusFile);
        mark_preorder_items_as_ready($items, $productsFile, $skusFile, $stateFile);
        flock($publishInventoryLock, LOCK_UN);
        fclose($publishInventoryLock);
    }
    $rows[$index]['inventoryReceived'] = true;
    $rows[$index]['preorderReceivedAt'] = $alreadyFinalized ? (string)($inquiry['preorderReceivedAt'] ?? $now) : $now;
    $rows[$index]['preorderReceivedBy'] = $alreadyFinalized ? (string)($inquiry['preorderReceivedBy'] ?? $receivedBy) : $receivedBy;
    $rows[$index]['arrivalWarehouse'] = $warehouse;
    $rows[$index]['arrivalWarehouseLabel'] = $warehouseMap[$warehouse];
    $rows[$index]['purchaseStatus'] = 'received';
    $rows[$index]['purchaseStatusLabel'] = '已到貨入庫';
    $autoWaitNotifyAfterArrival = receipt_should_auto_wait_notify_after_arrival($rows[$index], $warehouse);
    if ($alreadyFinalized) {
        $rows[$index]['preorderWaitStatus'] = (string)($inquiry['preorderWaitStatus'] ?? ($autoWaitNotifyAfterArrival ? 'ready_to_ship' : 'waiting_other_items'));
    } else {
        $rows[$index]['preorderWaitStatus'] = $autoWaitNotifyAfterArrival ? 'ready_to_ship' : 'waiting_other_items';
    }
    $rows[$index]['preorderWaitStatusLabel'] = $rows[$index]['preorderWaitStatus'] === 'ready_to_ship' ? '商品已齊／准許出貨' : '累計訂單／等待其他預購商品';
    if (!$alreadyFinalized && $autoWaitNotifyAfterArrival) {
        $autoNotifyNote = receipt_row_is_live_order($rows[$index])
            ? ($warehouse === 'CN'
                ? '中國直播已到貨建檔，先進直播打單通知區，待安排轉台灣或業務通知後再出貨'
                : '直播現貨已到台灣倉，等待業務通知後再出貨')
            : '台灣現貨已到，等待業務通知後再出貨';
        apply_wait_notify_fields(
            $rows[$index],
            true,
            $receivedBy,
            $now,
            $autoNotifyNote,
            'admin',
            true,
            false
        );
    }
    $rows[$index]['platformPublishAllowed'] = $publishAllowed;
    $rows[$index]['freightReceiptSnapshot'] = $receipt['freight'];
    $rows[$index]['freightReceiptLines'] = $receipt['receiptLog'];
    $rows[$index]['preorderReceiveOperationId'] = $operationId;
    $rows[$index]['preorderReceivePayloadHash'] = $payloadHash;
    $receiptTotalQty = array_reduce($receipt['receiptLog'], static fn($sum, $line) => $sum + max(0, (int)($line['qty'] ?? 0)), 0);
    $receiptTotalCost = array_reduce($receipt['receiptLog'], static fn($sum, $line) => $sum + (max(0, (int)($line['qty'] ?? 0)) * max(0, (float)($line['unitCostTwd'] ?? 0))), 0.0);
    $rows[$index]['receivingDocument'] = [
        'documentNo' => $receivingDocumentNo,
        'documentDate' => trim((string)($documentInput['documentDate'] ?? date('Y-m-d'))),
        'sourceOrderNo' => trim((string)($documentInput['sourceOrderNo'] ?? ($rows[$index]['id'] ?? ''))),
        'supplier' => trim((string)($documentInput['supplier'] ?? ($rows[$index]['supplierCarrier'] ?? ''))),
        'purchasePlatform' => trim((string)($documentInput['purchasePlatform'] ?? ($rows[$index]['purchasePlatform'] ?? ''))),
        'operatorName' => trim((string)($documentInput['operatorName'] ?? $receivedBy)) ?: $receivedBy,
        'arrivalWarehouse' => $warehouse,
        'note' => trim((string)($documentInput['note'] ?? '')),
        'status' => 'received',
        'lineCount' => count($receipt['receiptLog']),
        'totalQty' => $receiptTotalQty,
        'totalCostTwd' => round($receiptTotalCost, 2),
        'savedAt' => (string)($documentInput['savedAt'] ?? $now),
        'receivedAt' => $alreadyFinalized ? (string)($documentInput['receivedAt'] ?? ($inquiry['preorderReceivedAt'] ?? $now)) : $now,
    ];
    $rows[$index]['updatedAt'] = $now;
    $rows[$index]['preorderReceiveLog'] = ['operationId' => $operationId, 'at' => $alreadyFinalized ? (string)($inquiry['preorderReceivedAt'] ?? $now) : $now, 'by' => $receivedBy, 'warehouse' => $warehouse, 'qty' => array_reduce($items, fn($sum, $item) => $sum + max(0, (int)($item['qty'] ?? 0)), 0), 'freightBatchId' => $receipt['freight']['batchId'] ?? '', 'freightTrackingNo' => $receipt['freight']['trackingNo'] ?? '', 'lineCount' => count($receipt['receiptLog'])];
    $rows[$index]['receiveOperation'] = ['id' => $operationId, 'payloadHash' => $payloadHash, 'status' => 'completed', 'completedAt' => $alreadyFinalized ? (string)($inquiry['preorderReceivedAt'] ?? $now) : $now, 'warehouse' => $warehouse, 'receivingDocumentNo' => $receivingDocumentNo];
    $progressHistory = is_array($rows[$index]['preorderProgressHistory'] ?? null) ? $rows[$index]['preorderProgressHistory'] : [];
    $hasReceiveHistory = false;
    foreach ($progressHistory as $historyEntry) {
        if (is_array($historyEntry) && (string)($historyEntry['operationId'] ?? '') === $operationId) { $hasReceiveHistory = true; break; }
    }
    if (!$hasReceiveHistory) $progressHistory[] = ['operationId' => $operationId, 'at' => $now, 'by' => $receivedBy, 'purchaseStatus' => 'received', 'purchaseStatusLabel' => '已到貨入庫', 'preorderWaitStatus' => $rows[$index]['preorderWaitStatus'], 'preorderWaitStatusLabel' => $rows[$index]['preorderWaitStatusLabel'], 'supplierCarrier' => (string)($rows[$index]['supplierCarrier'] ?? ''), 'supplierTrackingNo' => (string)($rows[$index]['supplierTrackingNo'] ?? ''), 'arrivalWarehouse' => $warehouse, 'arrivalWarehouseLabel' => $warehouseMap[$warehouse], 'note' => $autoWaitNotifyAfterArrival ? '直播／現貨到貨入庫，已進入等待通知／先別出貨；業務通知後才出貨' : '到貨轉進貨，已增加所選倉庫庫存；預購先留在待配貨流程'];
    $rows[$index]['preorderProgressHistory'] = array_slice($progressHistory, -50);
    if ($preorderBatchIndex >= 0) {
        $preorderFreightData['batches'][$preorderBatchIndex]['status'] = '已入庫';
        $preorderFreightData['batches'][$preorderBatchIndex]['inventoryReceivedAt'] = $now;
        $preorderFreightData['batches'][$preorderBatchIndex]['receivingDocumentNo'] = $receivingDocumentNo;
        $preorderFreightData['batches'][$preorderBatchIndex]['updatedAt'] = $now;
        $preorderFreightData['batches'][$preorderBatchIndex]['inventoryReceiptClaim']['status'] = 'received';
        $preorderFreightData['batches'][$preorderBatchIndex]['inventoryReceiptClaim']['receivedAt'] = $now;
        $preorderFreightData['batches'][$preorderBatchIndex]['inventoryReceiptClaim']['updatedAt'] = $now;
        $history = isset($preorderFreightData['batches'][$preorderBatchIndex]['statusHistory']) && is_array($preorderFreightData['batches'][$preorderBatchIndex]['statusHistory'])
            ? $preorderFreightData['batches'][$preorderBatchIndex]['statusHistory']
            : [];
        $hasBatchHistory = false;
        foreach ($history as $historyEntry) {
            if (is_array($historyEntry) && (string)($historyEntry['operationId'] ?? '') === $operationId) { $hasBatchHistory = true; break; }
        }
        if (!$hasBatchHistory) $history[] = ['operationId' => $operationId, 'status' => '已入庫', 'at' => $now, 'receivingDocumentNo' => $receivingDocumentNo];
        $preorderFreightData['batches'][$preorderBatchIndex]['statusHistory'] = $history;
        $preorderFreightData['updatedAt'] = $now;
        write_json($freightTrackingFile, $preorderFreightData);
    }
    // Finalize the inquiry only after inventory mirrors and any freight batch have
    // been durably updated. A retry resumes here without changing stock again.
    write_json($inquiriesFile, $rows);
    respond(['ok' => true, 'inquiry' => $rows[$index], 'operationId' => $operationId, 'idempotentReplay' => $alreadyFinalized || !empty($receipt['idempotentReplay'])]);
}

if ($action === 'admin-adjust-preorder-item') {
    $operator = require_purchase_operator(
        $adminSessionsFile,
        $stateFile,
        ['預購訂單', '預購打單', '訂單管理', '採購區']
    );
    $operationLock = acquire_preorder_inventory_lock($inquiriesFile, $inquiryId);
    $rows = read_json($inquiriesFile);
    $index = find_index($rows, $inquiryId);
    if ($index < 0) respond(['ok' => false, 'error' => '找不到這張預購單，請重新整理後再試'], 404);
    $inquiry = $rows[$index];
    $status = strtolower(trim((string)($inquiry['status'] ?? '')));
    if (!sales_inquiry_is_preorder($inquiry) && inquiry_converted_order_id($inquiry) === '' && $status !== 'converted') {
        respond(['ok' => false, 'error' => '這筆不是預購訂單'], 409);
    }
    if (sales_inquiry_items_are_locked($inquiry)) {
        respond(['ok' => false, 'error' => '已取消、退回或退款的訂單不能直接調整商品'], 409);
    }
    assert_converted_order_items_editable($ordersFile, $inquiry);

    $items = is_array($inquiry['items'] ?? null) ? array_values($inquiry['items']) : [];
    $itemIndex = filter_var($payload['itemIndex'] ?? null, FILTER_VALIDATE_INT);
    if ($itemIndex === false || $itemIndex < 0 || !isset($items[$itemIndex]) || !is_array($items[$itemIndex])) {
        respond(['ok' => false, 'error' => '商品位置已變更，請重新整理後再調整'], 409);
    }
    $before = $items[$itemIndex];
    $oldSkuId = receipt_first_text([$before['skuId'] ?? '', $before['sku'] ?? '', $before['code'] ?? '']);
    $incomingSkuId = trim((string)($payload['oldSkuId'] ?? $payload['skuId'] ?? ''));
    if ($incomingSkuId !== '' && $oldSkuId !== '' && !sales_item_sku_matches($incomingSkuId, $oldSkuId)) {
        respond(['ok' => false, 'error' => '商品明細已更新，請重新整理後再調整'], 409);
    }
    $reason = trim((string)($payload['reason'] ?? ''));
    if ($reason === '') respond(['ok' => false, 'error' => '請填寫換品、改數量或贈品原因'], 400);
    $qty = max(1, (int)($payload['qty'] ?? $before['qty'] ?? $before['quantity'] ?? 1));
    $isGift = !empty($payload['isGift']);
    $price = $isGift ? 0.0 : max(0, (float)($payload['price'] ?? $before['price'] ?? $before['salePrice'] ?? 0));
    $replacement = is_array($payload['replacementItem'] ?? null) ? $payload['replacementItem'] : [];
    $replacementSkuId = receipt_first_text([$replacement['skuId'] ?? '', $replacement['sku'] ?? '']);
    $allocationSource = strtoupper(trim((string)($payload['allocationSourceWarehouse'] ?? 'PREORDER')));
    $allocatedWarehouseOnSwap = false;
    if (!in_array($allocationSource, ['PREORDER', 'TW', 'CN', 'ID'], true)) $allocationSource = 'PREORDER';
    if ($replacementSkuId !== '' && $allocationSource === 'CN') {
        reject_unless_admin_for_china_taiwan_live($operator);
    }

    $after = $before;
    if ($replacementSkuId !== '') {
        $oldImage = trim((string)($before['customerPurchaseImage'] ?? $before['purchaseImage'] ?? $before['image'] ?? ''));
        if ($oldImage !== '') {
            $savedImages = is_array($inquiry['customerImages'] ?? null) ? array_values($inquiry['customerImages']) : [];
            if (!in_array($oldImage, $savedImages, true)) $savedImages[] = $oldImage;
            $inquiry['customerImages'] = array_slice($savedImages, -12);
            $rows[$index]['customerImages'] = $inquiry['customerImages'];
        }
        $catalogSkus = read_json($skusFile);
        $catalogProducts = read_json($productsFile);
        $selectedSku = null;
        foreach ($catalogSkus as $catalogSku) {
            if (!is_array($catalogSku)) continue;
            if (receipt_first_text([$catalogSku['id'] ?? '', $catalogSku['sku'] ?? '']) !== $replacementSkuId) continue;
            if (!empty($catalogSku['archived']) || (isset($catalogSku['active']) && !$catalogSku['active'])) continue;
            $selectedSku = $catalogSku;
            break;
        }
        if (!is_array($selectedSku)) respond(['ok' => false, 'error' => '換入的正式 SKU 已不存在或已停用'], 404);
        $productId = receipt_text($selectedSku['productId'] ?? '');
        $selectedProduct = null;
        foreach ($catalogProducts as $catalogProduct) {
            if (is_array($catalogProduct) && receipt_text($catalogProduct['id'] ?? '') === $productId) {
                $selectedProduct = $catalogProduct;
                break;
            }
        }
        $after['skuId'] = $replacementSkuId;
        $after['sku'] = $replacementSkuId;
        $after['productId'] = $productId;
        $after['code'] = receipt_first_text([
            is_array($selectedProduct) ? ($selectedProduct['code'] ?? '') : '',
            $replacement['code'] ?? '',
            $replacementSkuId,
        ]);
        $after['title'] = receipt_first_text([
            is_array($selectedProduct) ? ($selectedProduct['title'] ?? $selectedProduct['name'] ?? '') : '',
            $replacement['title'] ?? '',
        ]);
        $after['color'] = receipt_first_text([$selectedSku['colorName'] ?? '', $selectedSku['color'] ?? '', $replacement['color'] ?? '']);
        $after['size'] = receipt_first_text([$selectedSku['sizeName'] ?? '', $selectedSku['size'] ?? '', $replacement['size'] ?? '', 'NO SIZE']);
        $after['image'] = receipt_first_text([
            $selectedSku['colorImage'] ?? '',
            $selectedSku['image'] ?? '',
            is_array($selectedProduct) ? ($selectedProduct['image'] ?? $selectedProduct['cover'] ?? '') : '',
            $replacement['image'] ?? '',
        ]);
        $beforeQty = max(1, (int)($before['qty'] ?? $before['quantity'] ?? $before['requestedQty'] ?? 1));
        $beforeReceivedQty = max(0, (int)($before['freightReceivedQty'] ?? 0));
        $beforeReceivedWarehouse = strtoupper(trim((string)($before['freightReceivedWarehouse'] ?? '')));
        $beforeAllocationStatus = trim((string)($before['priorityAllocationStatus'] ?? ''));
        $preserveSameSkuAllocation = $replacementSkuId === $oldSkuId
            && $qty === $beforeQty
            && (
                $beforeReceivedQty > 0
                || $beforeReceivedWarehouse !== ''
                || $beforeAllocationStatus === 'warehouse_reserved'
            );
        if ($preserveSameSkuAllocation) {
            // Editing price, gift status or product metadata must not erase an
            // already received/reserved allocation for the same SKU and quantity.
            $after['allocationSourceWarehouse'] = strtoupper(trim((string)($before['allocationSourceWarehouse'] ?? $allocationSource)));
        } else {
            $after['allocationSourceWarehouse'] = $allocationSource;
            $after['priorityAllocationStatus'] = $allocationSource === 'PREORDER' ? 'waiting_arrival' : 'warehouse_selected';
            $after['priorityPending'] = $allocationSource === 'PREORDER';
            $after['freightReceivedQty'] = 0;
            $after['freightReceivedWarehouse'] = '';
            release_preorder_freight_item_links($freightTrackingFile, $inquiryId, $itemIndex);
            $arrivalLogs = is_array($inquiry['freightArrivalAllocations'] ?? null) ? $inquiry['freightArrivalAllocations'] : [];
            foreach ($arrivalLogs as $arrivalIndex => $arrivalLog) {
                if (!is_array($arrivalLog)) continue;
                if ((int)($arrivalLog['itemIndex'] ?? -1) !== $itemIndex) continue;
                $arrivalLogs[$arrivalIndex]['active'] = false;
                $arrivalLogs[$arrivalIndex]['status'] = 'released';
            }
            $inquiry['freightArrivalAllocations'] = $arrivalLogs;
            $rows[$index]['freightArrivalAllocations'] = $arrivalLogs;
            if (in_array($allocationSource, ['TW', 'CN', 'ID'], true)) {
                $after['priorityAllocationStatus'] = 'warehouse_reserved';
                $after['priorityPending'] = false;
                $after['manualPriorityAllocation'] = true;
                $after['freightReceivedQty'] = $qty;
                $after['freightReceivedWarehouse'] = $allocationSource;
                $allocatedWarehouseOnSwap = true;
            }
        }
    }

    $oldQty = max(1, (int)($before['qty'] ?? $before['quantity'] ?? 1));
    $after['qty'] = $qty;
    $after['quantity'] = $qty;
    $after['requestedQty'] = $qty;
    $after['price'] = $price;
    $after['subtotal'] = round($price * $qty, 2);
    $after['isGift'] = $isGift;
    $after['lineType'] = $isGift ? 'gift' : 'sale';
    $after['giftReason'] = $isGift ? trim((string)($payload['giftReason'] ?? $reason)) : '';
    $after['adjustedAt'] = date(DATE_ATOM);
    $after['adjustedBy'] = trim((string)($operator['name'] ?? $operator['account'] ?? '管理者'));

    $items[$itemIndex] = $after;
    $subtotal = round(array_reduce($items, static function ($sum, $item) {
        return $sum + max(0, (float)($item['price'] ?? 0)) * max(0, (int)($item['qty'] ?? $item['quantity'] ?? 0));
    }, 0.0), 2);
    $customer = is_array($inquiry['customer'] ?? null) ? $inquiry['customer'] : [];
    $regularShippingFee = max(0, (float)($inquiry['regularShippingFee'] ?? $customer['regularShippingFee'] ?? $inquiry['shippingFee'] ?? $customer['shippingFee'] ?? 0));
    $freeShipping = $subtotal >= 1200;
    $shippingFee = $freeShipping ? 0.0 : $regularShippingFee;
    $newTotal = round($subtotal + $shippingFee, 2);
    $oldTotal = max(0, (float)($inquiry['total'] ?? 0));
    $paymentRows = read_json($orderPaymentsFile);
    $payment = reconcile_unshipped_inquiry_payment($inquiry, $paymentRows, $inquiryId, $newTotal, 'preorder', $oldTotal, $operator);
    // 付款金額驗證通過後才異動已保留的庫存，避免付款衝突時只改到庫存。
    if (!empty($inquiry['reserved'])) {
        $newSkuId = receipt_first_text([$after['skuId'] ?? '', $after['sku'] ?? '', $after['code'] ?? '']);
        if ($replacementSkuId !== '' && $newSkuId !== $oldSkuId) {
            adjust_stock([array_merge($after, ['qty' => $qty])], -1, $skusFile, $stateFile);
            adjust_stock([array_merge($before, ['qty' => $oldQty])], 1, $skusFile, $stateFile);
        } elseif ($qty > $oldQty) {
            adjust_stock([array_merge($after, ['qty' => $qty - $oldQty])], -1, $skusFile, $stateFile);
        } elseif ($qty < $oldQty) {
            adjust_stock([array_merge($before, ['qty' => $oldQty - $qty])], 1, $skusFile, $stateFile);
        }
    } elseif ($allocatedWarehouseOnSwap) {
        // 台灣／實體倉換品：立刻扣新商品現貨。舊品若只是預購到貨綁定、尚未轉正式，
        // 庫存本來就在公司倉，只解除綁定、不回加，避免灌水。
        adjust_stock([array_merge($after, ['qty' => $qty])], -1, $skusFile, $stateFile);
    }
    $now = date(DATE_ATOM);
    $rows[$index]['items'] = array_values($items);
    $rows[$index]['subtotal'] = $subtotal;
    $rows[$index]['shippingFee'] = $shippingFee;
    $rows[$index]['regularShippingFee'] = $regularShippingFee;
    $rows[$index]['freeShipping'] = $freeShipping;
    $rows[$index]['freeShippingThreshold'] = 1200;
    $rows[$index]['freeShippingBusinessRate'] = $freeShipping ? 0.3 : 0;
    $rows[$index]['freeShippingCompanyRate'] = $freeShipping ? 0.7 : 0;
    $rows[$index]['total'] = $newTotal;
    $rows[$index]['paymentSummary'] = $payment;
    $rows[$index]['updatedAt'] = $now;
    $customer['shippingFee'] = $shippingFee;
    $customer['regularShippingFee'] = $regularShippingFee;
    $customer['freeShipping'] = $freeShipping;
    $rows[$index]['customer'] = $customer;
    $history = is_array($rows[$index]['itemChangeHistory'] ?? null) ? $rows[$index]['itemChangeHistory'] : [];
    array_unshift($history, [
        'action' => 'adjust-item',
        'at' => $now,
        'by' => trim((string)($operator['name'] ?? $operator['account'] ?? '管理者')),
        'itemIndex' => $itemIndex,
        'reason' => $reason,
        'before' => $before,
        'after' => $after,
    ]);
    $rows[$index]['itemChangeHistory'] = array_slice($history, 0, 30);
    $updatedOrder = sync_converted_formal_order_items(
        $ordersFile,
        $stateFile,
        $skusFile,
        $inquiry,
        array_values($items),
        $before,
        $after,
        empty($inquiry['reserved']),
        trim((string)($operator['name'] ?? $operator['account'] ?? '管理者')),
        $reason
    );
    write_json($orderPaymentsFile, $paymentRows);
    write_json($inquiriesFile, $rows);
    respond(['ok' => true, 'inquiry' => $rows[$index], 'order' => $updatedOrder, 'adjustedItem' => $after]);
}

if ($action === 'remove-item') {
    $operator = require_purchase_operator(
        $adminSessionsFile,
        $stateFile,
        ['預購訂單', '預購打單', '訂單管理', '採購區']
    );
    $operationLock = acquire_preorder_inventory_lock($inquiriesFile, $inquiryId);
    $rows = read_json($inquiriesFile);
    $index = find_index($rows, $inquiryId);
    if ($index < 0) respond(['ok' => false, 'error' => '找不到這張預購單，請重新整理後再試'], 404);
    $inquiry = $rows[$index];
    $isPreorder = sales_inquiry_is_preorder($inquiry);
    $status = strtolower(trim((string)($inquiry['status'] ?? '')));
    if (!$isPreorder && inquiry_converted_order_id($inquiry) === '' && $status !== 'converted') {
        respond(['ok' => false, 'error' => '這筆不是預購訂單'], 409);
    }
    if (sales_inquiry_items_are_locked($inquiry)) {
        respond(['ok' => false, 'error' => '已取消、退回或退款的訂單不能刪除單項產品'], 409);
    }
    assert_converted_order_items_editable($ordersFile, $inquiry);
    $items = is_array($inquiry['items'] ?? null) ? array_values($inquiry['items']) : [];
    $cancelOrder = count($items) === 1 && !empty($payload['cancelOrder']);
    if (count($items) <= 1) {
        respond(['ok' => false, 'error' => '最後一項商品不可從商品編輯器刪除。若要換貨，請直接選取新商品後確認更換；若要取消客戶，請使用獨立的整單取消功能。'], 409);
    }
    $itemIndex = filter_var($payload['itemIndex'] ?? null, FILTER_VALIDATE_INT);
    if ($itemIndex === false || $itemIndex < 0 || !isset($items[$itemIndex]) || !is_array($items[$itemIndex])) {
        respond(['ok' => false, 'error' => '商品位置已變更，請重新整理後再刪除'], 409);
    }
    $removedItem = $items[$itemIndex];
    $removedImage = trim((string)($removedItem['customerPurchaseImage'] ?? $removedItem['purchaseImage'] ?? $removedItem['image'] ?? ''));
    if ($removedImage !== '') {
        $savedImages = is_array($inquiry['customerImages'] ?? null) ? array_values($inquiry['customerImages']) : [];
        if (!in_array($removedImage, $savedImages, true)) $savedImages[] = $removedImage;
        $inquiry['customerImages'] = array_slice($savedImages, -12);
        $rows[$index]['customerImages'] = $inquiry['customerImages'];
    }
    $incomingSkuId = trim((string)($payload['skuId'] ?? ''));
    $currentSkuId = trim((string)($removedItem['skuId'] ?? $removedItem['sku'] ?? $removedItem['code'] ?? ''));
    if ($incomingSkuId !== '' && $currentSkuId !== '' && !sales_item_sku_matches($incomingSkuId, $currentSkuId)) {
        respond(['ok' => false, 'error' => '商品明細已更新，請重新整理後再刪除'], 409);
    }
    $reason = trim((string)($payload['reason'] ?? ''));
    if ($reason === '') $reason = '管理者刪除預購單項產品';
    array_splice($items, $itemIndex, 1);
    $subtotal = 0.0;
    foreach ($items as &$remainingItem) {
        if (!is_array($remainingItem)) continue;
        $qty = max(0, (int)($remainingItem['qty'] ?? $remainingItem['quantity'] ?? 0));
        $price = max(0, (float)($remainingItem['price'] ?? $remainingItem['salePrice'] ?? $remainingItem['unitPrice'] ?? 0));
        $remainingItem['qty'] = $qty;
        $remainingItem['quantity'] = $qty;
        $remainingItem['subtotal'] = round($qty * $price, 2);
        $subtotal += $remainingItem['subtotal'];
    }
    unset($remainingItem);
    $subtotal = round($subtotal, 2);
    $customer = is_array($inquiry['customer'] ?? null) ? $inquiry['customer'] : [];
    $regularShippingFee = max(0, (float)(
        $inquiry['regularShippingFee']
        ?? $customer['regularShippingFee']
        ?? $inquiry['shippingFee']
        ?? $customer['shippingFee']
        ?? 0
    ));
    $freeShipping = $subtotal >= 1200;
    $shippingFee = $freeShipping ? 0.0 : $regularShippingFee;
    $newTotal = round($subtotal + $shippingFee, 2);
    $oldTotal = max(0, (float)($inquiry['total'] ?? 0));
    $paymentRows = read_json($orderPaymentsFile);
    $payment = reconcile_unshipped_inquiry_payment($inquiry, $paymentRows, $inquiryId, $newTotal, 'preorder', $oldTotal, $operator);

    $releasedFreightQty = release_preorder_freight_item_links($freightTrackingFile, $inquiryId, $itemIndex);
    if (!empty($inquiry['reserved'])) adjust_stock([$removedItem], 1, $skusFile, $stateFile);
    $now = date(DATE_ATOM);
    // Keep the inquiry's active arrival map aligned with the re-indexed item
    // array.  The freight file is fixed by release_preorder_freight_item_links;
    // without this mirror update the next item could inherit the deleted row's
    // receiving evidence after array_splice().
    $activeArrivals = is_array($inquiry['freightArrivalAllocations'] ?? null)
        ? array_values($inquiry['freightArrivalAllocations'])
        : [];
    $keptArrivals = [];
    $releasedArrivalQty = 0;
    foreach ($activeArrivals as $arrival) {
        if (!is_array($arrival)) continue;
        $arrivalItemIndex = array_key_exists('itemIndex', $arrival) ? (int)$arrival['itemIndex'] : -1;
        if ($arrivalItemIndex === $itemIndex) {
            $releasedArrivalQty += max(0, (int)($arrival['qty'] ?? 0));
            continue;
        }
        if ($arrivalItemIndex > $itemIndex) $arrival['itemIndex'] = $arrivalItemIndex - 1;
        $keptArrivals[] = $arrival;
    }

    // Deleting a missing line can make every remaining line fully received.
    // Recalculate the derived order-level state instead of leaving the card in
    // a permanent "partial arrival" state.
    $allRemainingReceived = count($items) > 0;
    $anyRemainingReceived = false;
    $remainingWarehouses = [];
    foreach ($items as $remainingItem) {
        if (!is_array($remainingItem)) continue;
        $requestedQty = max(1, (int)($remainingItem['requestedQty'] ?? $remainingItem['qty'] ?? $remainingItem['quantity'] ?? 1));
        $receivedQty = max(0, (int)($remainingItem['freightReceivedQty'] ?? 0));
        if ($receivedQty > 0) $anyRemainingReceived = true;
        if ($receivedQty < $requestedQty) $allRemainingReceived = false;
        $receivedWarehouse = strtoupper(trim((string)($remainingItem['freightReceivedWarehouse'] ?? $remainingItem['allocationSourceWarehouse'] ?? '')));
        if ($receivedQty > 0 && in_array($receivedWarehouse, ['TW', 'CN', 'ID'], true)) $remainingWarehouses[$receivedWarehouse] = true;
    }
    $rows[$index]['items'] = array_values($items);
    $rows[$index]['freightArrivalAllocations'] = array_values($keptArrivals);
    $rows[$index]['inventoryReceived'] = $allRemainingReceived;
    if ($allRemainingReceived) {
        $rows[$index]['purchaseStatus'] = 'received';
        $rows[$index]['purchaseStatusLabel'] = '已到貨入庫／等待確認出貨';
        $rows[$index]['preorderReceivedAt'] = trim((string)($rows[$index]['preorderReceivedAt'] ?? '')) ?: $now;
        if (count($remainingWarehouses) === 1) {
            $verifiedWarehouse = (string)array_key_first($remainingWarehouses);
            $warehouseLabels = ['TW' => '台灣倉 / Gudang Taiwan', 'CN' => '中國倉 / Gudang China', 'ID' => '印尼倉 / Gudang Indonesia'];
            $rows[$index]['arrivalWarehouse'] = $verifiedWarehouse;
            $rows[$index]['arrivalWarehouseLabel'] = $warehouseLabels[$verifiedWarehouse];
            $rows[$index]['preorderFulfillWarehouse'] = $verifiedWarehouse;
            $rows[$index]['preorderFulfillWarehouseLabel'] = $warehouseLabels[$verifiedWarehouse];
            $rows[$index]['warehouseVerificationStatus'] = 'verified';
            unset($rows[$index]['receivedWarehouses']);
        } elseif (count($remainingWarehouses) > 1) {
            $rows[$index]['warehouseVerificationStatus'] = 'mixed';
            $rows[$index]['receivedWarehouses'] = array_keys($remainingWarehouses);
        }
        if (trim((string)($rows[$index]['preorderWaitStatus'] ?? '')) === '') {
            $rows[$index]['preorderWaitStatus'] = 'waiting_other_items';
            $rows[$index]['preorderWaitStatusLabel'] = '累計訂單／等待業務確認出貨';
        }
    } else {
        $rows[$index]['purchaseStatus'] = $anyRemainingReceived ? 'arrived' : 'preparing';
        $rows[$index]['purchaseStatusLabel'] = $anyRemainingReceived ? '部分到貨／等待其餘商品' : '等待採購／尚未到貨';
        if (trim((string)($rows[$index]['preorderWaitStatus'] ?? '')) === 'ready_to_ship') {
            $rows[$index]['preorderWaitStatus'] = 'waiting_other_items';
            $rows[$index]['preorderWaitStatusLabel'] = '累計訂單／等待其他預購商品';
        }
    }
    $rows[$index]['subtotal'] = $subtotal;
    $rows[$index]['shippingFee'] = $shippingFee;
    $rows[$index]['regularShippingFee'] = $regularShippingFee;
    $rows[$index]['freeShipping'] = $freeShipping;
    $rows[$index]['freeShippingThreshold'] = 1200;
    $rows[$index]['freeShippingBusinessRate'] = $freeShipping ? 0.3 : 0;
    $rows[$index]['freeShippingCompanyRate'] = $freeShipping ? 0.7 : 0;
    $rows[$index]['total'] = $newTotal;
    $rows[$index]['paymentSummary'] = $payment;
    $rows[$index]['updatedAt'] = $now;
    $customer['shippingFee'] = $shippingFee;
    $customer['regularShippingFee'] = $regularShippingFee;
    $customer['freeShipping'] = $freeShipping;
    $rows[$index]['customer'] = $customer;
    $history = is_array($rows[$index]['itemChangeHistory'] ?? null) ? $rows[$index]['itemChangeHistory'] : [];
    array_unshift($history, [
        'action' => 'remove-item',
        'at' => $now,
        'by' => trim((string)($operator['name'] ?? $operator['account'] ?? '管理者')),
        'itemIndex' => $itemIndex,
        'reason' => $reason,
        'skuId' => $currentSkuId,
        'code' => (string)($removedItem['code'] ?? ''),
        'title' => (string)($removedItem['title'] ?? $removedItem['productName'] ?? ''),
        'color' => (string)($removedItem['color'] ?? ''),
        'size' => (string)($removedItem['size'] ?? ''),
        'qty' => max(0, (int)($removedItem['qty'] ?? $removedItem['quantity'] ?? 0)),
        'releasedFreightQty' => $releasedFreightQty,
        'releasedArrivalQty' => $releasedArrivalQty,
    ]);
    $rows[$index]['itemChangeHistory'] = array_slice($history, 0, 30);
    if ($cancelOrder) {
        $rows[$index]['status'] = 'cancelled';
        $rows[$index]['statusLabel'] = '已取消（最後商品已刪除）';
        $rows[$index]['cancelledAt'] = $now;
        $rows[$index]['cancelledBy'] = trim((string)($operator['name'] ?? $operator['account'] ?? '管理者'));
        $rows[$index]['cancelledReason'] = $reason;
        $rows[$index]['shippingFee'] = 0;
        $rows[$index]['total'] = 0;
    }
    sync_converted_formal_order_items(
        $ordersFile,
        $stateFile,
        $skusFile,
        $inquiry,
        array_values($items),
        $removedItem,
        null,
        empty($inquiry['reserved']),
        trim((string)($operator['name'] ?? $operator['account'] ?? '管理者')),
        $reason
    );
    if ($cancelOrder) {
        $orders = read_json($ordersFile);
        $orderIndex = find_converted_order_index($orders, $inquiry);
        if ($orderIndex >= 0) {
            $orders[$orderIndex]['status'] = 'cancelled';
            $orders[$orderIndex]['statusLabel'] = '已取消（最後商品已刪除並回補庫存）';
            $orders[$orderIndex]['deliveryState'] = 'cancelled';
            $orders[$orderIndex]['cancelledAt'] = $now;
            $orders[$orderIndex]['cancelledReason'] = $reason;
            $orders[$orderIndex]['inventoryDeducted'] = false;
            $orders[$orderIndex]['updatedAt'] = $now;
            write_json($ordersFile, $orders);
            if (function_exists('sync_state_orders')) {
                sync_state_orders($stateFile, $orders);
            }
        }
    }
    write_json($orderPaymentsFile, $paymentRows);
    write_json($inquiriesFile, $rows);
    respond([
        'ok' => true,
        'inquiry' => $rows[$index],
        'removedItem' => $removedItem,
        'releasedFreightQty' => $releasedFreightQty,
        'stockRestored' => !empty($inquiry['reserved']),
        'orderCancelled' => $cancelOrder,
    ]);
}

if ($action === 'delete') {
    if (array_key_exists('salesLogin', $payload)) {
        $login = require_sales_inquiry_login($payload, $inquiry, $salesCustomersFile, $stateFile, $salesSessionsFile);
        $status = strtolower(trim((string)($inquiry['status'] ?? '')));
        $lockedStatuses = ['converted', 'shipped', 'in_transit', 'delivered', 'completed', 'returned', 'cancelled', 'refunded', 'closed', 'received'];
        $alreadyProcessed = in_array($status, $lockedStatuses, true)
            || !empty($inquiry['inventoryReceived'])
            || !empty($inquiry['convertedOrderId'])
            || !empty($inquiry['convertedToOrderId'])
            || !empty($inquiry['shippedAt'])
            || !empty($inquiry['deliveredAt']);
        if ($alreadyProcessed) {
            respond(['ok' => false, 'error' => '已到貨、已轉單或已出貨的預購單不能由業務刪除 / Preorder yang sudah diterima, dialihkan, atau dikirim tidak dapat dihapus oleh sales.'], 409);
        }
    }
    $reason = trim((string)($payload['reason'] ?? $payload['deletedReason'] ?? ''));
    $deletedBy = trim((string)($payload['deletedBy'] ?? ''));
    if ($deletedBy === '') $deletedBy = '管理者';
    $wasReserved = !empty($inquiry['reserved']);
    if ($wasReserved) {
        adjust_stock(is_array($inquiry['items'] ?? null) ? $inquiry['items'] : [], 1, $skusFile, $stateFile);
    }
    // 已採購／已綁物流提撥的預購單仍要能從「已下單」列表刪除。
    // 解綁失敗不可留下幽靈卡片：inquiry 仍會移除，freight 再由後續 FIFO 對帳。
    $releasedFreightQty = release_preorder_freight_links($freightTrackingFile, [$inquiryId], false);
    $archive = $inquiry;
    $archive['_deleted'] = [
        'at' => date(DATE_ATOM),
        'by' => $deletedBy,
        'reason' => $reason !== '' ? $reason : '刪除此預購單',
        'releasedFreightQty' => $releasedFreightQty,
        'purchaseStatus' => $inquiry['purchaseStatus'] ?? '',
    ];
    $deletedFile = $dataDir . DIRECTORY_SEPARATOR . 'deleted-preorders.json';
    $deletedRows = read_json($deletedFile);
    if (!is_array($deletedRows)) $deletedRows = [];
    array_unshift($deletedRows, $archive);
    $archiveJson = json_encode(array_slice($deletedRows, 0, 500), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($archiveJson) && $archiveJson !== '') {
        @file_put_contents($deletedFile, $archiveJson);
    }
    array_splice($rows, $index, 1);
    write_json($inquiriesFile, array_values($rows));
    respond([
        'ok' => true,
        'deletedId' => $inquiryId,
        'releasedFreightQty' => $releasedFreightQty,
        'voidedPurchasedPreorder' => strtolower(trim((string)($inquiry['purchaseStatus'] ?? ''))) === 'ordered',
    ]);
}

// Conversion has its own durable replay checks below. Let it reach those checks so
// a request that stopped after stock deduction can recreate the missing order
// without deducting inventory a second time.
if (!in_array($action, ['convert', 'update-prices'], true) && !is_pending_inquiry_status($inquiry['status'] ?? '')) {
    respond(['ok' => false, 'error' => '這張預購單已經處理過，請改開正式出貨單繼續作業'], 409);
}

if ($action === 'cancel') {
    $wasReserved = !empty($inquiry['reserved']);
    if ($wasReserved) {
        adjust_stock(is_array($inquiry['items'] ?? null) ? $inquiry['items'] : [], 1, $skusFile, $stateFile);
    }
    $rows[$index]['status'] = 'cancelled';
    $rows[$index]['statusLabel'] = $wasReserved ? 'Dibatalkan, stok sudah dikembalikan' : 'Dibatalkan, preorder tidak memotong stok';
    $rows[$index]['reserved'] = false;
    $rows[$index]['updatedAt'] = date(DATE_ATOM);
    write_json($inquiriesFile, $rows);
    respond(['ok' => true, 'inquiry' => $rows[$index]]);
}

if ($action === 'update-prices') {
    $priceRows = is_array($payload['priceRows'] ?? null) ? $payload['priceRows'] : [];
    $hasShippingFeeUpdate = array_key_exists('shippingFee', $payload)
        || array_key_exists('shippingFeeMode', $payload)
        || array_key_exists('regularShippingFee', $payload)
        || array_key_exists('freeShipping', $payload)
        || array_key_exists('indonesiaSelfShip', $payload);
    $convertedOrderIndex = -1;
    $isConvertedInquiry = inquiry_converted_order_id($inquiry) !== ''
        || strtolower(trim((string)($inquiry['status'] ?? ''))) === 'converted';
    if ($isConvertedInquiry) {
        $convertedOrderIndex = assert_converted_order_items_editable($ordersFile, $inquiry);
        if ($convertedOrderIndex < 0) {
            respond(['ok' => false, 'error' => '找不到正式出貨單，請重新整理後再改價'], 409);
        }
    } elseif (sales_inquiry_is_preorder($inquiry)) {
        require_sales_inquiry_editable($inquiry, true);
    } else {
        require_sales_inquiry_editable($inquiry, false);
    }
    if (!$priceRows && !$hasShippingFeeUpdate) respond(['ok' => false, 'error' => '請提供要修改的商品售價或運費'], 400);
    if (!isset($rows[$index]['items']) || !is_array($rows[$index]['items']) || !$rows[$index]['items']) {
        respond(['ok' => false, 'error' => '詢價單沒有商品明細'], 409);
    }
    $updatedCount = 0;
    foreach ($priceRows as $priceRow) {
        if (!is_array($priceRow) || !array_key_exists('index', $priceRow) || !array_key_exists('price', $priceRow)) {
            respond(['ok' => false, 'error' => '商品售價資料格式不正確'], 400);
        }
        if (array_key_exists('qty', $priceRow)) {
            respond(['ok' => false, 'error' => '改價功能不允許修改商品數量'], 400);
        }
        $itemIndex = filter_var($priceRow['index'], FILTER_VALIDATE_INT);
        if ($itemIndex === false || $itemIndex < 0 || !isset($rows[$index]['items'][$itemIndex]) || !is_array($rows[$index]['items'][$itemIndex])) {
            respond(['ok' => false, 'error' => '商品明細位置已變更，請重新整理後再改價'], 409);
        }
        $incomingSkuId = trim((string)($priceRow['skuId'] ?? ''));
        $currentSkuId = trim((string)($rows[$index]['items'][$itemIndex]['skuId'] ?? $rows[$index]['items'][$itemIndex]['sku'] ?? $rows[$index]['items'][$itemIndex]['code'] ?? ''));
        if ($incomingSkuId !== '' && $currentSkuId !== '' && $incomingSkuId !== $currentSkuId) {
            respond(['ok' => false, 'error' => '商品明細已更新，請重新整理後再改價'], 409);
        }
        if (!is_numeric($priceRow['price'])) respond(['ok' => false, 'error' => '商品售價必須是數字'], 400);
        $price = (float)$priceRow['price'];
        if (!is_finite($price) || $price < 0) respond(['ok' => false, 'error' => '商品售價不可小於 0'], 400);
        $rows[$index]['items'][$itemIndex]['price'] = round($price, 2);
        $updatedCount++;
    }
    if ($updatedCount < 1 && !$hasShippingFeeUpdate) respond(['ok' => false, 'error' => '沒有可更新的商品售價'], 400);

    $oldTotal = max(0, (float)($inquiry['total'] ?? 0));
    $subtotal = 0.0;
    foreach ($rows[$index]['items'] as &$item) {
        if (!is_array($item)) continue;
        $qty = max(0, (int)($item['qty'] ?? 0));
        $lineSubtotal = round(max(0, (float)($item['price'] ?? 0)) * $qty, 2);
        $item['subtotal'] = $lineSubtotal;
        $subtotal += $lineSubtotal;
    }
    unset($item);
    $subtotal = round($subtotal, 2);
    if (array_key_exists('shippingFee', $payload)) {
        if (!is_numeric($payload['shippingFee'])) respond(['ok' => false, 'error' => '運費必須是數字'], 400);
        $shippingFee = (float)$payload['shippingFee'];
        if (!is_finite($shippingFee) || $shippingFee < 0) respond(['ok' => false, 'error' => '運費不可小於 0'], 400);
        $shippingFee = round($shippingFee, 2);
    } else {
        $shippingFee = max(0, (float)($rows[$index]['shippingFee'] ?? ($rows[$index]['customer']['shippingFee'] ?? 0)));
    }
    $customer = is_array($rows[$index]['customer'] ?? null) ? $rows[$index]['customer'] : [];
    $mode = strtolower(trim((string)($payload['shippingFeeMode'] ?? ($rows[$index]['shippingFeeMode'] ?? ($customer['shippingFeeMode'] ?? 'standard')))));
    if (!in_array($mode, ['standard', 'free', 'id_self'], true)) {
        $mode = !empty($payload['freeShipping']) ? 'free' : 'standard';
    }
    if (array_key_exists('regularShippingFee', $payload)) {
        if (!is_numeric($payload['regularShippingFee'])) respond(['ok' => false, 'error' => '原始運費必須是數字'], 400);
        $regularShippingFee = (float)$payload['regularShippingFee'];
        if (!is_finite($regularShippingFee) || $regularShippingFee < 0) respond(['ok' => false, 'error' => '原始運費不可小於 0'], 400);
        $regularShippingFee = round($regularShippingFee, 2);
    } else {
        $regularShippingFee = round(max(0, (float)($rows[$index]['regularShippingFee'] ?? ($customer['regularShippingFee'] ?? $shippingFee))), 2);
    }
    $indonesiaSelfShip = !empty($payload['indonesiaSelfShip']) || $mode === 'id_self';
    $freeShipping = !$indonesiaSelfShip && (!empty($payload['freeShipping']) || $mode === 'free');
    if ($indonesiaSelfShip) {
        $shippingFee = 0.0;
        $mode = 'id_self';
    } elseif ($freeShipping) {
        $shippingFee = 0.0;
        $mode = 'free';
    } else {
        $mode = 'standard';
        if (!array_key_exists('shippingFee', $payload)) $shippingFee = $regularShippingFee;
    }
    $newTotal = round($subtotal + $shippingFee, 2);
    $now = date(DATE_ATOM);
    $rows[$index]['subtotal'] = $subtotal;
    $rows[$index]['shippingFee'] = $shippingFee;
    $rows[$index]['regularShippingFee'] = $regularShippingFee;
    $rows[$index]['shippingFeeMode'] = $mode;
    $rows[$index]['freeShipping'] = $freeShipping;
    $rows[$index]['indonesiaSelfShip'] = $indonesiaSelfShip;
    $rows[$index]['shippingFeeWaived'] = $freeShipping ? $regularShippingFee : 0;
    if (!isset($rows[$index]['customer']) || !is_array($rows[$index]['customer'])) $rows[$index]['customer'] = [];
    $rows[$index]['customer']['shippingFee'] = $shippingFee;
    $rows[$index]['customer']['regularShippingFee'] = $regularShippingFee;
    $rows[$index]['customer']['shippingFeeMode'] = $mode;
    $rows[$index]['customer']['freeShipping'] = $freeShipping;
    $rows[$index]['customer']['indonesiaSelfShip'] = $indonesiaSelfShip;
    $rows[$index]['customer']['shippingFeeWaived'] = $freeShipping ? $regularShippingFee : 0;
    $rows[$index]['total'] = $newTotal;
    $rows[$index]['updatedAt'] = $now;

    $updatedOrder = null;
    if ($convertedOrderIndex >= 0) {
        $orders = read_json($ordersFile);
        if (isset($orders[$convertedOrderIndex]) && is_array($orders[$convertedOrderIndex])) {
            if (!isset($orders[$convertedOrderIndex]['customer']) || !is_array($orders[$convertedOrderIndex]['customer'])) {
                $orders[$convertedOrderIndex]['customer'] = [];
            }
            $orders[$convertedOrderIndex]['items'] = array_values($rows[$index]['items']);
            $orders[$convertedOrderIndex]['subtotal'] = $subtotal;
            $orders[$convertedOrderIndex]['shippingFee'] = $shippingFee;
            $orders[$convertedOrderIndex]['regularShippingFee'] = $regularShippingFee;
            $orders[$convertedOrderIndex]['shippingFeeMode'] = $mode;
            $orders[$convertedOrderIndex]['freeShipping'] = $freeShipping;
            $orders[$convertedOrderIndex]['indonesiaSelfShip'] = $indonesiaSelfShip;
            $orders[$convertedOrderIndex]['shippingFeeWaived'] = $freeShipping ? $regularShippingFee : 0;
            $orders[$convertedOrderIndex]['total'] = $newTotal;
            $orders[$convertedOrderIndex]['grandTotal'] = $newTotal;
            $orders[$convertedOrderIndex]['customer']['shippingFee'] = $shippingFee;
            $orders[$convertedOrderIndex]['customer']['regularShippingFee'] = $regularShippingFee;
            $orders[$convertedOrderIndex]['customer']['shippingFeeMode'] = $mode;
            $orders[$convertedOrderIndex]['customer']['freeShipping'] = $freeShipping;
            $orders[$convertedOrderIndex]['customer']['indonesiaSelfShip'] = $indonesiaSelfShip;
            $orders[$convertedOrderIndex]['customer']['shippingFeeWaived'] = $freeShipping ? $regularShippingFee : 0;
            $orders[$convertedOrderIndex]['updatedAt'] = $now;
            $orderHistory = is_array($orders[$convertedOrderIndex]['itemChangeHistory'] ?? null) ? $orders[$convertedOrderIndex]['itemChangeHistory'] : [];
            array_unshift($orderHistory, [
                'action' => 'update-prices',
                'at' => $now,
                'by' => '後台改價',
                'priceRows' => $priceRows,
                'shippingFee' => $shippingFee,
                'total' => $newTotal,
            ]);
            $orders[$convertedOrderIndex]['itemChangeHistory'] = array_slice($orderHistory, 0, 100);
            $updatedOrder = $orders[$convertedOrderIndex];
            write_json($ordersFile, $orders);
            $state = read_json($stateFile);
            $state['orders'] = $orders;
            $state['updatedAt'] = $now;
            write_json($stateFile, $state);
        }
    }

    $paymentRows = read_json($orderPaymentsFile);
    $legacyLogin = ['name' => '後台改價', 'account' => 'legacy-admin'];
    $orderType = sales_inquiry_is_preorder($inquiry) ? 'preorder' : 'ready-pending';
    $payment = reconcile_unshipped_inquiry_payment($rows[$index], $paymentRows, $inquiryId, $newTotal, $orderType, $oldTotal, $legacyLogin);
    write_json($orderPaymentsFile, $paymentRows);
    write_json($inquiriesFile, $rows);
    respond(['ok' => true, 'inquiry' => $rows[$index], 'order' => $updatedOrder, 'payment' => $payment]);
}

if ($action === 'convert') {
    $convertOperator = require_purchase_operator(
        $adminSessionsFile,
        $stateFile,
        ['物流集運', '預購訂單', '訂單管理', '現貨打單', '現貨訂單']
    );
    // Receipt and conversion for the same inquiry share one lock.  This prevents a
    // receive finalizer and a conversion from crossing while still allowing unrelated
    // inquiries to make progress; the SKU helper additionally serializes the shared
    // whole-file inventory read/modify/write.
    $operationLock = acquire_preorder_inventory_lock($inquiriesFile, $inquiryId);
    $rows = read_json($inquiriesFile);
    $index = find_index($rows, $inquiryId);
    if ($index < 0) respond(['ok' => false, 'error' => '找不到這筆預購單，請重新整理後再試'], 404);
    $inquiry = hydrate_preorder_items_from_freight_arrivals($rows[$index], $freightTrackingFile);
    $inquiryCustomer = is_array($inquiry['customer'] ?? null) ? $inquiry['customer'] : [];
    $blacklistGate = member_risk_gate(
        __DIR__,
        (string)($inquiryCustomer['phone'] ?? $inquiry['customerPhone'] ?? $inquiry['phone'] ?? ''),
        (string)($inquiryCustomer['name'] ?? $inquiry['customerName'] ?? $inquiry['name'] ?? ''),
        $payload,
        '轉正式出貨'
    );
    if (!empty($blacklistGate['blocked'])) {
        respond($blacklistGate['response'], 409);
    }
    $inquiryStatus = strtolower(trim((string)($inquiry['status'] ?? '')));
    if ($inquiryStatus === 'cancelled') {
        $inquiry['status'] = 'reserved';
        $inquiry['statusLabel'] = '管理者恢復出貨';
        $inquiry['reopenedFromCancelAt'] = date(DATE_ATOM);
        $inquiry['reopenedFromCancelBy'] = trim((string)($payload['transferredBy'] ?? $payload['employeeName'] ?? '管理者')) ?: '管理者';
        $inquiry['freightCustomerQueueDecision'] = '';
        $inquiry['freightCustomerQueueDecisionLabel'] = '';
        $inquiry['reserved'] = false;
    }
    $rows[$index] = $inquiry;
    $now = date(DATE_ATOM);
    if (!empty($blacklistGate['hit'])) {
        $inquiry = array_merge($inquiry, member_risk_stamp(
            $blacklistGate['hit'],
            $now,
            trim((string)($payload['transferredBy'] ?? $payload['employeeName'] ?? ''))
        ));
        $rows[$index] = $inquiry;
    }
    $warehouseMap = ['TW' => '台灣倉 / Gudang Taiwan', 'ID' => '印尼倉 / Gudang Indonesia', 'CN' => '中國倉 / Gudang China'];
    $orders = read_json($ordersFile);
    $items = is_array($inquiry['items'] ?? null) ? $inquiry['items'] : [];
    $items = array_values(array_filter($items, static function ($item) use ($orders, $inquiryId): bool {
        return is_array($item) && !inquiry_item_already_shipped_on_formal($orders, $inquiryId, $item);
    }));
    if (!$items) {
        respond(['ok' => false, 'error' => '這張預購剩餘商品已在先前正式單出過，不能再用同一張已取件單重試。請重新整理後改開新包。'], 409);
    }
    $fulfillWarehouse = strtoupper((string)($payload['fulfillWarehouse'] ?? ($inquiry['preorderFulfillWarehouse'] ?? $inquiry['arrivalWarehouse'] ?? $inquiry['preorderWarehouse'] ?? 'TW')));
    if (!isset($warehouseMap[$fulfillWarehouse])) $fulfillWarehouse = 'TW';
    $shipWarehouse = strtoupper(trim((string)($payload['shipWarehouse'] ?? '')));
    if (!isset($warehouseMap[$shipWarehouse])) $shipWarehouse = '';
    $shipWarehouseSet = [];
    $rawShipWarehouses = $payload['shipWarehouses'] ?? null;
    if (is_array($rawShipWarehouses)) {
        foreach ($rawShipWarehouses as $code) {
            $code = strtoupper(trim((string)$code));
            if (isset($warehouseMap[$code])) $shipWarehouseSet[$code] = true;
        }
    }
    if ($shipWarehouse !== '') $shipWarehouseSet[$shipWarehouse] = true;
    $remainItems = [];
    $itemWarehouseSet = [];
    foreach ($items as $probeItem) {
        if (!is_array($probeItem)) continue;
        $probeWarehouse = inquiry_item_fulfill_warehouse($probeItem);
        if ($probeWarehouse !== '') $itemWarehouseSet[$probeWarehouse] = true;
    }
    $shipTogether = count($itemWarehouseSet) > 1
        && (!$shipWarehouseSet || count(array_diff_key($itemWarehouseSet, $shipWarehouseSet)) === 0);
    if (count($itemWarehouseSet) > 1 && !$shipTogether) {
        if (!$shipWarehouseSet) {
            $fallbackWarehouse = isset($itemWarehouseSet[$fulfillWarehouse])
                ? $fulfillWarehouse
                : (string)array_key_first($itemWarehouseSet);
            $shipWarehouseSet[$fallbackWarehouse] = true;
        }
        $shipItems = [];
        foreach ($items as $probeItem) {
            if (!is_array($probeItem)) continue;
            $probeWarehouse = inquiry_item_fulfill_warehouse($probeItem);
            if ($probeWarehouse === '' || isset($shipWarehouseSet[$probeWarehouse])) $shipItems[] = $probeItem;
            else $remainItems[] = $probeItem;
        }
        if (!$shipItems) {
            respond(['ok' => false, 'error' => '選定的倉庫目前沒有可出商品，系統未扣庫存'], 409);
        }
        $items = $shipItems;
        $shipWarehouse = $shipWarehouse !== '' ? $shipWarehouse : (string)array_key_first($shipWarehouseSet);
        $fulfillWarehouse = $shipWarehouse;
    }
    $shippingChina = isset($shipWarehouseSet['CN']) || $shipWarehouse === 'CN' || $fulfillWarehouse === 'CN';
    $usingTaiwanOnHand = !empty($payload['directStockAllocation'])
        && $fulfillWarehouse === 'TW'
        && !isset($shipWarehouseSet['CN'])
        && $shipWarehouse !== 'CN';
    if ($usingTaiwanOnHand) {
        // 原單標中國倉／SKU 後綴 -CN，但本次是扣台灣倉現貨，不算中國倉轉現貨單。
        $shippingChina = false;
    } elseif (!$shippingChina) {
        foreach ($items as $chinaProbe) {
            if (is_array($chinaProbe) && inquiry_item_fulfill_warehouse($chinaProbe) === 'CN') {
                $shippingChina = true;
                break;
            }
        }
    }
    if ($shippingChina) reject_unless_admin_for_china_taiwan_live($convertOperator);
    $operationId = preorder_inventory_operation_id(
        'convert',
        $remainItems ? ($inquiryId . ':' . $fulfillWarehouse) : $inquiryId
    );
    $orderId = $remainItems ? ($inquiryId . '-' . $fulfillWarehouse) : $inquiryId;
    $directStockAllocation = !empty($payload['directStockAllocation']);
    $manualPhysicalAllocation = !empty($payload['manualPhysicalAllocation']);
    // Customer-linked receipt quantities are no longer part of company-available
    // stock.  Treat a fully received, warehouse-reserved preorder as already
    // reserved so conversion does not try to deduct the same physical units twice.
    $customerReservedReceipt = !empty($items);
    $needsPhysicalStock = false;
    foreach ($items as $reservedItem) {
        if (!is_array($reservedItem)) { $customerReservedReceipt = false; $needsPhysicalStock = true; break; }
        if (inquiry_item_needs_physical_stock($reservedItem)) $needsPhysicalStock = true;
        if (!inquiry_item_is_customer_reserved_receipt($reservedItem)) $customerReservedReceipt = false;
    }
    if ($customerReservedReceipt && !$needsPhysicalStock) {
        // Every line is already tied to this customer. A stale browser allocation
        // flag must not send them through company-available stock matching again.
        $directStockAllocation = false;
        $manualPhysicalAllocation = false;
    } elseif ($needsPhysicalStock) {
        foreach ($items as $physicalItem) {
            if (!is_array($physicalItem) || !inquiry_item_needs_physical_stock($physicalItem)) continue;
            $source = strtoupper(trim((string)($physicalItem['allocationSourceWarehouse'] ?? $physicalItem['freightReceivedWarehouse'] ?? '')));
            $wantsTaiwan = in_array($source, ['TW', 'TAIWAN'], true)
                || !empty($physicalItem['manualPriorityAllocation'])
                || in_array((string)($physicalItem['priorityAllocationStatus'] ?? ''), ['warehouse_selected', 'warehouse_reserved'], true);
            if ($wantsTaiwan) {
                // 台灣現貨在尚未出貨前可前牌；已入庫的其他品項不擋這條現貨扣庫。
                $directStockAllocation = true;
                break;
            }
        }
    }
    foreach ($items as $pendingPriorityItem) {
        if ($directStockAllocation || $manualPhysicalAllocation) continue;
        if (!is_array($pendingPriorityItem) || empty($pendingPriorityItem['priorityPending'])) continue;
        $pendingQty = max(1, (int)($pendingPriorityItem['qty'] ?? $pendingPriorityItem['requestedQty'] ?? 1));
        $receivedQty = max(0, (int)($pendingPriorityItem['freightReceivedQty'] ?? 0));
        if ($receivedQty >= $pendingQty) continue;
        respond([
            'ok' => false,
            'error' => '插隊新增的預購倉商品尚未到貨，已保留原客戶與順位；請等到貨配貨或改選有庫存的台灣／中國／印尼倉商品',
        ], 409);
    }
    $customer = is_array($inquiry['customer'] ?? null) ? $inquiry['customer'] : [];
    $wasReserved = !empty($inquiry['reserved']) || $customerReservedReceipt;
    $isPreorder = ((string)($inquiry['orderType'] ?? '') === 'preorder') || ((string)($inquiry['status'] ?? '') === 'preorder_pending');
    if (
        $directStockAllocation
        && !$needsPhysicalStock
        && ($wasReserved || !empty($inquiry['inventoryReceived']) || (string)($inquiry['purchaseStatus'] ?? '') === 'received')
    ) {
        respond(['ok' => false, 'error' => '這張預購單已有保留或驗收入庫紀錄，請使用原本的轉正式流程，避免重複扣庫存'], 409);
    }
    // Locate replay evidence before deriving the warehouse/hash. If the first request
    // stopped after writing stock or the formal order, these durable records restore
    // the exact warehouse when the browser retries without its original form payload.
    $existingOrderIndex = -1;
    $existingOrder = null;
    foreach ($orders as $candidateIndex => $candidateOrder) {
        if (!is_array($candidateOrder) || order_closed_for_convert($candidateOrder)) continue;
        $existingOrderId = trim((string)($candidateOrder['id'] ?? ''));
        $existingSourceMatches = false;
        foreach (['sourceInquiryId', 'inquiryId', 'convertedFromInquiryId'] as $sourceField) {
            if (trim((string)($candidateOrder[$sourceField] ?? '')) !== $inquiryId) continue;
            $existingSourceMatches = true;
            break;
        }
        if ($existingOrderId === $orderId) {
            $existingOrderIndex = $candidateIndex;
            $existingOrder = $candidateOrder;
            break;
        }
        if ($remainItems || $orderId !== $inquiryId || !$existingSourceMatches) continue;
        // 先前分倉出貨已取件／配送中的正式單，不能當成這一包剩餘商品的重試。
        if (order_is_closed_shipment($candidateOrder)) continue;
        $existingOrderIndex = $candidateIndex;
        $existingOrder = $candidateOrder;
        break;
    }
    $existingMarkerEntries = inventory_transaction_marker_entries(read_json($skusFile), $operationId);
    if ($directStockAllocation && $fulfillWarehouse !== 'TW' && !$shipTogether) {
        respond(['ok' => false, 'error' => '台灣現貨快速提撥只能從台灣倉執行'], 409);
    }
    if ($directStockAllocation || $manualPhysicalAllocation) {
        $catalogSkus = read_json($skusFile);
        $fulfillProfile = receipt_warehouse_profile($fulfillWarehouse);
        $remainingBySku = [];
        foreach ($catalogSkus as $catalogSku) {
            if (!is_array($catalogSku)) continue;
            $catalogSkuId = receipt_first_text([$catalogSku['id'] ?? '', $catalogSku['sku'] ?? '']);
            if ($catalogSkuId !== '') $remainingBySku[$catalogSkuId] = max(0, (int)($catalogSku['stock'] ?? 0));
        }
        $mappedItems = [];
        foreach ($items as $itemIndex => $directItem) {
            if (!is_array($directItem)) continue;
            $wantedQty = max(0, (int)($directItem['qty'] ?? $directItem['quantity'] ?? 0));
            if ($wantedQty <= 0) continue;
            $itemWarehouse = inquiry_item_fulfill_warehouse($directItem);
            if (($directStockAllocation || $manualPhysicalAllocation) && $fulfillWarehouse === 'TW') {
                $itemWarehouse = 'TW';
            } elseif (!isset($warehouseMap[$itemWarehouse])) {
                $itemWarehouse = $fulfillWarehouse;
            }
            $itemProfile = receipt_warehouse_profile($itemWarehouse);
            $itemUsesCustomerReservation = inquiry_item_is_customer_reserved_receipt($directItem)
                && (
                    strtoupper(trim((string)($directItem['freightReceivedWarehouse'] ?? ''))) === $itemWarehouse
                    || strtoupper(trim((string)($directItem['freightReceivedWarehouse'] ?? ''))) === ''
                );
            $originalSkuId = receipt_first_text([$directItem['skuId'] ?? '', $directItem['sku'] ?? '']);
            $baseSku = null;
            foreach ($catalogSkus as $catalogSku) {
                if (!is_array($catalogSku)) continue;
                if (receipt_first_text([$catalogSku['id'] ?? '', $catalogSku['sku'] ?? '']) === $originalSkuId) {
                    $baseSku = $catalogSku;
                    break;
                }
            }
            $legacyManualPriorityItem = $manualPhysicalAllocation
                && !empty($inquiry['manualQueuePriority'])
                && (int)($inquiry['manualQueuePriorityItemIndex'] ?? -1) === (int)$itemIndex;
            $itemHasManualPhysicalFlag = !empty($directItem['manualPriorityAllocation'])
                || !empty($directItem['priorityAllocation'])
                || !empty($directItem['insertedPriorityAllocation'])
                || in_array((string)($directItem['priorityAllocationStatus'] ?? ''), ['warehouse_reserved', 'warehouse_selected'], true);
            $useExactLineSku = is_array($baseSku) && receipt_sku_is_warehouse($baseSku, $itemProfile) && $originalSkuId !== '';
            if ($useExactLineSku && ($directStockAllocation || ($manualPhysicalAllocation && ($legacyManualPriorityItem || $itemHasManualPhysicalFlag)))) {
                $baseAvailableQty = max(0, (int)($remainingBySku[$originalSkuId] ?? 0));
                if ($originalSkuId !== '' && ($itemUsesCustomerReservation || $baseAvailableQty >= $wantedQty)) {
                    $mappedItem = $directItem;
                    $mappedItem['skuId'] = $originalSkuId;
                    $mappedItem['sku'] = $originalSkuId;
                    $mappedItem['qty'] = $wantedQty;
                    $mappedItem['quantity'] = $wantedQty;
                    $mappedItem['sourceWarehouseCode'] = $itemWarehouse;
                    $mappedItem['sourceWarehouse'] = $itemProfile['name'];
                    $mappedItem['allocationSourceWarehouse'] = $itemWarehouse;
                    $mappedItem['manualPriorityAllocation'] = true;
                    $mappedItem['priorityAllocationStatus'] = 'warehouse_reserved';
                    $mappedItem['priorityPending'] = false;
                    $mappedItem['freightReceivedQty'] = $wantedQty;
                    $mappedItem['freightReceivedWarehouse'] = $itemWarehouse;
                    $mappedItem['customerReservedReceipt'] = $itemUsesCustomerReservation;
                    $mappedItems[] = $mappedItem;
                    if (!$itemUsesCustomerReservation) $remainingBySku[$originalSkuId] -= $wantedQty;
                    continue;
                }
            }
            $productId = receipt_first_text([
                is_array($baseSku) ? ($baseSku['productId'] ?? '') : '',
                $directItem['productId'] ?? '',
            ]);
            $color = receipt_first_text([
                $directItem['color'] ?? '',
                $directItem['colorName'] ?? '',
                is_array($baseSku) ? ($baseSku['colorName'] ?? $baseSku['color'] ?? '') : '',
            ]);
            $size = receipt_first_text([
                $directItem['size'] ?? '',
                $directItem['sizeName'] ?? '',
                is_array($baseSku) ? ($baseSku['sizeName'] ?? $baseSku['size'] ?? '') : '',
                'NO SIZE',
            ]);
            if ($productId === '' || $color === '') {
                respond(['ok' => false, 'error' => '第 ' . ($itemIndex + 1) . ' 個商品缺少正式產品或顏色資料，不能快速提撥'], 409);
            }
            $remainingQty = $wantedQty;
            foreach ($catalogSkus as $candidateSku) {
                if (!is_array($candidateSku) || !receipt_sku_is_warehouse($candidateSku, $itemProfile)) continue;
                if (!receipt_same_variant($candidateSku, $productId, $color, $size)) continue;
                if (!empty($candidateSku['archived']) || (isset($candidateSku['active']) && !$candidateSku['active'])) continue;
                $candidateSkuId = receipt_first_text([$candidateSku['id'] ?? '', $candidateSku['sku'] ?? '']);
                $availableQty = max(0, (int)($remainingBySku[$candidateSkuId] ?? 0));
                if ($candidateSkuId === '' || (!$itemUsesCustomerReservation && $availableQty <= 0)) continue;
                $takeQty = $itemUsesCustomerReservation ? $remainingQty : min($remainingQty, $availableQty);
                $mappedItem = $directItem;
                $mappedItem['skuId'] = $candidateSkuId;
                $mappedItem['sku'] = $candidateSkuId;
                $mappedItem['qty'] = $takeQty;
                $mappedItem['quantity'] = $takeQty;
                $mappedItem['sourceWarehouseCode'] = $itemWarehouse;
                $mappedItem['sourceWarehouse'] = $itemProfile['name'];
                $mappedItem['allocationSourceWarehouse'] = $itemWarehouse;
                $mappedItem['freightReceivedWarehouse'] = $itemWarehouse;
                $mappedItem['freightReceivedQty'] = $takeQty;
                $mappedItem['waitingArrival'] = false;
                $mappedItem['customerReservedReceipt'] = $itemUsesCustomerReservation;
                $mappedItems[] = $mappedItem;
                if (!$itemUsesCustomerReservation) $remainingBySku[$candidateSkuId] -= $takeQty;
                $remainingQty -= $takeQty;
                if ($remainingQty <= 0) break;
            }
            if ($remainingQty > 0) {
                $mappedItem = $directItem;
                $mappedItem['qty'] = $remainingQty;
                $mappedItem['quantity'] = $remainingQty;
                $mappedItem['customerReservedReceipt'] = true;
                $mappedItem['priorityPending'] = true;
                $mappedItem['unfulfilledAtConvert'] = true;
                $mappedItems[] = $mappedItem;
            }
        }
        $items = $mappedItems;
    }
    $verifiedItemWarehouses = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $itemWarehouse = inquiry_item_fulfill_warehouse($item);
        if (isset($warehouseMap[$itemWarehouse])) $verifiedItemWarehouses[$itemWarehouse] = true;
    }
    $verifiedOrderWarehouse = strtoupper(trim((string)($inquiry['arrivalWarehouse'] ?? $inquiry['preorderFulfillWarehouse'] ?? '')));
    if (count($verifiedItemWarehouses) === 1) $verifiedOrderWarehouse = (string)array_key_first($verifiedItemWarehouses);
    if (!isset($warehouseMap[$verifiedOrderWarehouse])) $verifiedOrderWarehouse = '';
    if (!$remainItems && $verifiedOrderWarehouse !== '' && $fulfillWarehouse !== $verifiedOrderWarehouse) {
        $selectedIncludesVerified = isset($shipWarehouseSet[$verifiedOrderWarehouse])
            || ($shipWarehouse === '' && count($verifiedItemWarehouses) === 1);
        if ($selectedIncludesVerified) {
            // FIFO「先出中國倉」在單倉時 shipWarehouse 會是空的，舊畫面會誤把
            // fulfillWarehouse 寫成台灣倉。商品實際入庫倉才是要扣的倉。
            $fulfillWarehouse = $verifiedOrderWarehouse;
            if ($shipWarehouse === '') $shipWarehouse = $verifiedOrderWarehouse;
            $shipWarehouseSet[$verifiedOrderWarehouse] = true;
        } elseif (!$directStockAllocation && !$shipTogether) {
            respond(['ok' => false, 'error' => '選擇的出貨倉與正式驗收入庫倉不一致；此單只能從 ' . $warehouseMap[$verifiedOrderWarehouse] . ' 出貨'], 409);
        }
    }
    $durableFulfillWarehouse = strtoupper((string)($existingOrder['preorderFulfillWarehouse'] ?? ($existingMarkerEntries[0]['operationContext']['fulfillWarehouse'] ?? '')));
    if (!$shipTogether && $durableFulfillWarehouse !== '' && isset($warehouseMap[$durableFulfillWarehouse])) {
        if (array_key_exists('fulfillWarehouse', $payload) && $fulfillWarehouse !== $durableFulfillWarehouse) {
            respond(['ok' => false, 'error' => '這張預購單已開始轉單，不能用不同出貨倉重送'], 409);
        }
        $fulfillWarehouse = $durableFulfillWarehouse;
    }
    $fulfillWarehouseLabel = $warehouseMap[$fulfillWarehouse];
    $hashItems = array_map(static fn($item) => is_array($item) ? [
        'skuId' => (string)($item['skuId'] ?? $item['sku'] ?? ''),
        'productId' => (string)($item['productId'] ?? ''),
        'qty' => max(0, (int)($item['qty'] ?? 0)),
    ] : [], $items);
    $legacyPayloadHash = preorder_operation_payload_hash([
        'fulfillWarehouse' => $fulfillWarehouse,
        'items' => $hashItems,
    ]);
    $payloadHash = preorder_operation_payload_hash([
        'inquiryId' => $inquiryId,
        'fulfillWarehouse' => $fulfillWarehouse,
        'items' => $hashItems,
    ]);
    $savedConvertOperationId = (string)($inquiry['preorderConvertOperationId'] ?? ($inquiry['convertOperation']['id'] ?? ''));
    $savedConvertPayloadHash = (string)($inquiry['preorderConvertPayloadHash'] ?? ($inquiry['convertOperation']['payloadHash'] ?? ''));
    $remainingAfterPartial = (string)($inquiry['preorderWaitStatus'] ?? '') === 'waiting_other_items'
        || !empty($inquiry['lastPartialConvertedOrderId'])
        || !empty($inquiry['partialConvertedOrderIds']);
    if ($savedConvertPayloadHash !== ''
        && !hash_equals($savedConvertPayloadHash, $payloadHash)
        && !hash_equals($savedConvertPayloadHash, $legacyPayloadHash)
        && !$remainingAfterPartial
    ) {
        respond(['ok' => false, 'error' => '這張預購單已用不同商品或倉庫內容轉單，不能重複扣庫存'], 409);
    }

    // Detect a durable order without treating it as an error. A previous request may
    // have written the formal order and then stopped before finalizing the inquiry.
    $existingConversionId = '';
    foreach (['convertedToOrderId', 'convertedOrderId', 'orderId'] as $conversionField) {
        $candidateConversionId = trim((string)($inquiry[$conversionField] ?? ''));
        if ($candidateConversionId === '') continue;
        $existingConversionId = $candidateConversionId;
        break;
    }
    if (is_array($existingOrder)) {
        $existingOrderOperationId = (string)($existingOrder['preorderConvertOperationId'] ?? $existingOrder['inventoryOperationId'] ?? '');
        $existingOrderPayloadHash = (string)($existingOrder['preorderConvertPayloadHash'] ?? '');
        $hashMismatch = $existingOrderPayloadHash !== '' && !hash_equals($existingOrderPayloadHash, $payloadHash);
        $existingStatus = strtolower(trim((string)($existingOrder['status'] ?? '')));
        $existingDelivery = strtolower(trim((string)($existingOrder['deliveryState'] ?? '')));
        $sameInquiry = trim((string)($existingOrder['id'] ?? '')) === $inquiryId
            || trim((string)($existingOrder['sourceInquiryId'] ?? '')) === $inquiryId
            || trim((string)($existingOrder['convertedFromInquiryId'] ?? '')) === $inquiryId;
        $existingOpen = $sameInquiry
            && !in_array($existingStatus, ['shipped', 'in_transit', 'delivered', 'completed', 'cancelled', 'returned', 'refunded'], true)
            && !in_array($existingDelivery, ['in_transit', 'delivered', 'returned'], true);
        if ($hashMismatch && (!$existingOpen || order_is_closed_shipment($existingOrder))) {
            $orderId = next_unused_convert_order_id($orders, $orderId);
            $operationId = preorder_inventory_operation_id(
                'convert',
                ($remainItems ? ($inquiryId . ':' . $fulfillWarehouse) : $inquiryId) . ':' . substr($payloadHash, 0, 8)
            );
            $existingOrder = null;
            $existingOrderIndex = -1;
            $existingMarkerEntries = [];
            $savedConvertOperationId = '';
        } else {
            if ($existingOrderOperationId !== $operationId && !$existingMarkerEntries && $savedConvertOperationId !== $operationId) {
                respond(['ok' => false, 'error' => '正式出貨單已存在，但缺少可驗證的庫存 transaction marker；已停止自動重試', 'existingOrderId' => (string)($existingOrder['id'] ?? '')], 409);
            }
            if ($hashMismatch) {
                // 上次已寫入正式單並扣過庫存，但預購單可能還沒標成 converted。
                // 重試時台灣現貨 SKU 可能已變，不能再對不同內容扣第二次。
                $payloadHash = $existingOrderPayloadHash;
                if (is_array($existingOrder['items'] ?? null) && $existingOrder['items']) {
                    $items = $existingOrder['items'];
                }
                $existingFulfill = strtoupper(trim((string)($existingOrder['preorderFulfillWarehouse'] ?? '')));
                if (isset($warehouseMap[$existingFulfill])) {
                    $fulfillWarehouse = $existingFulfill;
                    $fulfillWarehouseLabel = $warehouseMap[$existingFulfill];
                }
            }
        }
    }
    $idempotentReplay = is_array($existingOrder) || $savedConvertOperationId === $operationId || (string)($inquiry['status'] ?? '') === 'converted' || !empty($existingMarkerEntries);
    $orphanMissingFormal = (string)($inquiry['status'] ?? '') === 'converted' && !is_array($existingOrder);
    if (!$idempotentReplay && !is_pending_inquiry_status($inquiry['status'] ?? '')) {
        respond(['ok' => false, 'error' => '這張預購單已經處理過，不能再轉正式出貨單'], 409);
    }
    if ($isPreorder && !$wasReserved && empty($inquiry['inventoryReceived']) && !$directStockAllocation && !$manualPhysicalAllocation) {
        respond(['ok' => false, 'error' => '請先完成到貨轉進貨並增加庫存，再轉正式出貨扣現貨'], 409);
    }
    if ($isPreorder && !$wasReserved && (string)($inquiry['preorderWaitStatus'] ?? '') !== 'ready_to_ship' && !$directStockAllocation && !$manualPhysicalAllocation) {
        respond(['ok' => false, 'error' => '這張預購單仍在累計等待其他貨物；請先在預購等待區確認「商品已齊／准許出貨」'], 409);
    }
    $transferBy = trim((string)($payload['transferredBy'] ?? $payload['employeeName'] ?? $payload['operatorName'] ?? ''));
    $durableTransferBy = trim((string)($existingOrder['preorderTransferOutBy'] ?? ($existingMarkerEntries[0]['operationContext']['transferredBy'] ?? '')));
    if ($transferBy === '' && $durableTransferBy !== '') $transferBy = $durableTransferBy;
    if ($transferBy === '') $transferBy = '未記錄員工';
    $preorderMoveQty = array_reduce($items, fn($sum, $item) => $sum + max(0, (int)($item['qty'] ?? 0)), 0);
    $transferLogEntry = [
        'operationId' => $operationId,
        'at' => $now,
        'by' => $transferBy,
        'action' => '轉正式出貨並扣現貨',
        'fulfillWarehouse' => $fulfillWarehouse,
        'fulfillWarehouseLabel' => $fulfillWarehouseLabel,
        'qty' => $preorderMoveQty,
        'freightTrackingNos' => array_values(array_unique(array_filter(array_merge(
            [(string)($inquiry['freightTrackingNo'] ?? ''), (string)($inquiry['supplierTrackingNo'] ?? '')],
            array_map(static fn($item) => is_array($item) ? (string)($item['freightLastTrackingNo'] ?? '') : '', $items)
        )))),
        'receivingDocumentNos' => array_values(array_unique(array_filter(array_map(
            static fn($item) => is_array($item) ? (string)($item['freightReceivingDocumentNo'] ?? '') : '',
            $items
        )))),
        'sourceInquiryId' => $inquiryId,
    ];
    if ($isPreorder && !$wasReserved) {
        // Receipt already added the goods to the selected warehouse. Converting the
        // original preorder to a formal shipment now only deducts the shipped quantity.
        if (is_array($existingOrder) && !$existingMarkerEntries && trim((string)($existingOrder['preorderTransferOutAt'] ?? '')) === '') {
            respond(['ok' => false, 'error' => '正式出貨單已存在但找不到扣庫存 marker，已停止再次扣庫存'], 409);
        }
        $skipOrphanStock = $orphanMissingFormal && empty($existingMarkerEntries);
        if (!$skipOrphanStock) {
            $deductibleItems = array_values(array_filter($items, static function ($item): bool {
                return is_array($item) && empty($item['customerReservedReceipt']);
            }));
            adjust_stock_once_for_preorder_operation($deductibleItems, -1, $skusFile, $stateFile, $operationId, $inquiryId, $payloadHash, ['fulfillWarehouse' => $fulfillWarehouse, 'transferredBy' => $transferBy]);
        }
        if (empty($inquiry['customerOrderOnly']) && !empty($inquiry['platformPublishAllowed']) && !empty($inquiry['inventoryReceived'])) {
            $publishInventoryLock = acquire_inventory_file_lock($skusFile);
            mark_preorder_items_as_ready($items, $productsFile, $skusFile, $stateFile);
            flock($publishInventoryLock, LOCK_UN);
            fclose($publishInventoryLock);
        }
    }
    $subtotal = array_reduce($items, fn($sum, $item) => $sum + ((float)($item['price'] ?? 0) * (int)($item['qty'] ?? 0)), 0);
    $customer['name'] = receipt_first_text([
        $customer['name'] ?? '',
        $inquiry['customerName'] ?? '',
        $inquiry['name'] ?? '',
    ]);
    $customer['phone'] = receipt_first_text([
        $customer['phone'] ?? '',
        $inquiry['customerPhone'] ?? '',
        $inquiry['phone'] ?? '',
    ]);
    $customer['address'] = receipt_first_text([
        $customer['address'] ?? '',
        $inquiry['customerAddress'] ?? '',
        $inquiry['address'] ?? '',
    ]);
    $keywordShipping = order_shipping_rule_from_keywords((string)($customer['address'] ?? ''));
    $regularShippingFee = $keywordShipping
        ? max(0, (float)$keywordShipping['fee'])
        : max(0, (float)($inquiry['regularShippingFee'] ?? ($customer['regularShippingFee'] ?? ($inquiry['shippingFee'] ?? ($customer['shippingFee'] ?? 0)))));
    $shippingFeeMode = strtolower(trim((string)($inquiry['shippingFeeMode'] ?? ($customer['shippingFeeMode'] ?? ''))));
    $indonesiaSelfShip = !empty($inquiry['indonesiaSelfShip'])
        || !empty($customer['indonesiaSelfShip'])
        || $shippingFeeMode === 'id_self'
        || preg_match('/印尼業務自行出貨/u', (string)($inquiry['shippingCarrier'] ?? ''))
        || preg_match('/印尼業務自行出貨/u', (string)($customer['shippingCarrier'] ?? ''));
    if ($indonesiaSelfShip) {
        $freeShipping = false;
        $shippingFee = 0.0;
        $shippingFeeMode = 'id_self';
    } else {
        $freeShipping = $isPreorder && $subtotal >= 1200;
        $shippingFee = $freeShipping ? 0 : $regularShippingFee;
        $shippingFeeMode = $freeShipping ? 'free' : 'standard';
        if ($keywordShipping) {
            $customer['deliveryType'] = $keywordShipping['deliveryType'];
            $customer['deliveryLabel'] = $keywordShipping['deliveryLabel'];
            $customer['shippingCarrier'] = $keywordShipping['shippingCarrier'];
        }
    }
    $inquiryTrackingNo = receipt_first_text([
        $inquiry['trackingNo'] ?? '',
        $customer['trackingNo'] ?? '',
        is_array($existingOrder) ? ($existingOrder['trackingNo'] ?? '') : '',
    ]);
    $exceptOrderId = is_array($existingOrder) ? receipt_text($existingOrder['id'] ?? '') : '';
    $inquiryParcels = lz_normalize_outbound_parcels(
        $inquiry['outboundParcels'] ?? ($customer['outboundParcels'] ?? null),
        is_array($existingOrder) ? (is_array($existingOrder['outboundParcels'] ?? null) ? $existingOrder['outboundParcels'] : []) : [],
        $inquiryTrackingNo,
        receipt_text($inquiry['shippingNote'] ?? ''),
        receipt_first_text([$inquiry['shippingCarrier'] ?? '', $customer['shippingCarrier'] ?? ''])
    );
    foreach ($inquiryParcels as $parcel) {
        $parcelTracking = lz_norm_tracking((string)($parcel['trackingNo'] ?? ''));
        if ($parcelTracking === '') continue;
        $trackingOwner = lz_find_tracking_owner($orders, $parcelTracking, $exceptOrderId);
        if (is_array($trackingOwner)) {
            $thisMerge = is_array($existingOrder) ? receipt_text($existingOrder['shipmentMergeGroupId'] ?? '') : '';
            $ownerMerge = receipt_text($trackingOwner['shipmentMergeGroupId'] ?? '');
            if ($thisMerge === '' || $thisMerge !== $ownerMerge) {
                $inquiryTrackingNo = '';
                $customer['trackingNo'] = '';
                $inquiryParcels = array_values(array_filter($inquiryParcels, static function ($row) use ($parcelTracking) {
                    return lz_norm_tracking((string)($row['trackingNo'] ?? '')) !== $parcelTracking;
                }));
            }
        }
    }
    if ($inquiryParcels) $inquiryTrackingNo = (string)($inquiryParcels[0]['trackingNo'] ?? $inquiryTrackingNo);
    $inquiryCarrier = receipt_first_text([
        $inquiry['shippingCarrier'] ?? '',
        $customer['shippingCarrier'] ?? '',
        is_array($existingOrder) ? ($existingOrder['shippingCarrier'] ?? '') : '',
    ]);
    $inquiryStoreAddress = receipt_first_text([
        $inquiry['storeAddress'] ?? '',
        $customer['storeAddress'] ?? '',
        is_array($existingOrder) ? ($existingOrder['storeAddress'] ?? '') : '',
    ]);
    if ($inquiryTrackingNo !== '') $customer['trackingNo'] = $inquiryTrackingNo;
    if ($inquiryCarrier !== '') $customer['shippingCarrier'] = $inquiryCarrier;
    if ($inquiryStoreAddress !== '') $customer['storeAddress'] = $inquiryStoreAddress;
    $customer['shippingFee'] = $shippingFee;
    $customer['regularShippingFee'] = $regularShippingFee;
    $customer['freeShipping'] = $freeShipping;
    $customer['shippingFeeMode'] = $shippingFeeMode;
    $customer['indonesiaSelfShip'] = $indonesiaSelfShip;
    $customer['freeShippingThreshold'] = 1200;
    $customer['freeShippingBusinessRate'] = $freeShipping ? 0.3 : 0;
    $customer['freeShippingCompanyRate'] = $freeShipping ? 0.7 : 0;
    $convertedPaymentSummary = is_array($inquiry['paymentSummary'] ?? null) ? $inquiry['paymentSummary'] : [];
    $convertedTotal = $subtotal + $shippingFee;
    $convertedPaid = max(0, min($convertedTotal, (float)($convertedPaymentSummary['paidAmount'] ?? 0)));
    $convertedBalance = max(0, $convertedTotal - $convertedPaid);
    $convertedPaymentSummary['orderType'] = 'order';
    $convertedPaymentSummary['orderTotal'] = $convertedTotal;
    $convertedPaymentSummary['paidAmount'] = $convertedPaid;
    $convertedPaymentSummary['balance'] = $convertedBalance;
    $convertedPaymentSummary['receivableBalance'] = $convertedBalance;
    $convertedPaymentSummary['unshippedBalance'] = 0;
    $convertedPaymentSummary['status'] = $convertedPaid <= 0 ? 'unpaid' : ($convertedBalance > 0 ? 'partial' : 'paid');
    $convertedPaymentSummary['convertedToReadyAt'] = $now;
    $order = [
        'id' => $orderId,
        'createdAt' => $now,
        'updatedAt' => $now,
        'status' => 'confirming',
        'statusLabel' => 'Konfirmasi order',
        'source' => 'stock-inquiry',
        'sourceInquiryId' => $inquiryId,
        'convertedFromInquiryId' => $inquiryId,
        'blacklistHit' => !empty($blacklistGate['hit']),
        'blacklistMatchType' => (string)(($blacklistGate['hit']['matchType'] ?? '')),
        'blacklistReason' => (string)(($blacklistGate['hit']['reason'] ?? '')),
        'blacklistConfirmedAt' => !empty($blacklistGate['hit']) ? $now : '',
        'blacklistAskSales' => !empty($blacklistGate['hit']),
        'blacklistConfirmedBy' => !empty($blacklistGate['hit']) ? trim((string)($payload['transferredBy'] ?? $payload['employeeName'] ?? '')) : '',
        'inventoryOperationId' => $operationId,
        'preorderConvertOperationId' => $operationId,
        'preorderConvertPayloadHash' => $payloadHash,
        // Once the preorder is actually transferred for shipment it becomes an
        // ordinary ready-stock order. Keep the preorder origin fields for audit,
        // but route the working order to the 現貨訂單 queue.
        'orderType' => $isPreorder ? 'normal' : ((string)($inquiry['orderType'] ?? 'normal')),
        'orderTypeLabel' => $isPreorder ? '現貨訂單（預購轉出貨）' : ((string)($inquiry['orderTypeLabel'] ?? '正常訂單')),
        'sourceOrderType' => $isPreorder ? 'preorder' : ((string)($inquiry['orderType'] ?? 'normal')),
        'preorderSource' => $isPreorder,
        'preorderConvertedAt' => $isPreorder ? $now : '',
        'preorderWarehouse' => (string)($inquiry['preorderWarehouse'] ?? ''),
        'preorderWarehouseLabel' => (string)($inquiry['preorderWarehouseLabel'] ?? ''),
        'preorderFulfillWarehouse' => $isPreorder ? $fulfillWarehouse : '',
        'preorderFulfillWarehouseLabel' => $isPreorder ? $fulfillWarehouseLabel : '',
        'indonesiaTrackingRequired' => !($isPreorder && $fulfillWarehouse === 'ID'),
        'trackingOptionalReason' => ($isPreorder && $fulfillWarehouse === 'ID') ? '印尼倉調撥成立，不強制物流單號' : '',
        'indonesiaOrderEstablishedAt' => ($isPreorder && $fulfillWarehouse === 'ID') ? $now : '',
        'indonesiaOrderStatus' => ($isPreorder && $fulfillWarehouse === 'ID') ? 'established' : '',
        'preorderAutoInQty' => 0,
        'preorderReceiptQty' => $isPreorder ? $preorderMoveQty : 0,
        'preorderAutoOutQty' => $isPreorder ? $preorderMoveQty : 0,
        'preorderInventoryReceived' => $isPreorder ? !empty($inquiry['inventoryReceived']) : false,
        'preorderStockFlow' => $isPreorder ? 'received_then_formal_out' : '',
        'preorderTransferOutAt' => $isPreorder ? $now : '',
        'preorderTransferOutBy' => $isPreorder ? $transferBy : '',
        'preorderTransferLogs' => $isPreorder ? [$transferLogEntry] : [],
        'liveSource' => (string)($inquiry['liveSource'] ?? ''),
        'liveSession' => (string)($inquiry['liveSession'] ?? ''),
        'orderDate' => (string)($inquiry['orderDate'] ?? ''),
        'shipMode' => (string)($inquiry['shipMode'] ?? ''),
        'shipModeLabel' => (string)($inquiry['shipModeLabel'] ?? ''),
        'warehouse' => (string)($inquiry['warehouse'] ?? ''),
        'sales' => $inquiry['sales'] ?? '',
        'salesName' => $inquiry['salesName'] ?? sales_label(valid_sales((string)($inquiry['sales'] ?? ''))),
        'resellerSettlement' => is_array($inquiry['resellerSettlement'] ?? null) ? $inquiry['resellerSettlement'] : [],
        'salesChannel' => (string)($inquiry['salesChannel'] ?? (!empty($inquiry['resellerSettlement']['enabled']) ? 'reseller' : 'direct')),
        'customer' => array_merge($customer, [
            'name' => $customer['name'] ?? '',
            'phone' => $customer['phone'] ?? '',
            'note' => $customer['note'] ?? '',
            'address' => $customer['address'] ?? '',
            'salesName' => $inquiry['salesName'] ?? '',
            'paymentProofImage' => $customer['paymentProofImage'] ?? (($inquiry['paymentSummary']['proofImage'] ?? '')),
        ]),
        'customerName' => $customer['name'],
        'customerPhone' => $customer['phone'],
        'address' => $customer['address'],
        'storeAddress' => $inquiryStoreAddress,
        'trackingNo' => $inquiryTrackingNo,
        'shippingCarrier' => $inquiryCarrier,
        'shippingNote' => receipt_text($inquiry['shippingNote'] ?? (is_array($existingOrder) ? ($existingOrder['shippingNote'] ?? '') : '')),
        'outboundParcels' => $inquiryParcels,
        'items' => $items,
        'subtotal' => $subtotal,
        'shippingFee' => $shippingFee,
        'regularShippingFee' => $regularShippingFee,
        'freeShipping' => $freeShipping,
        'shippingFeeMode' => $shippingFeeMode,
        'indonesiaSelfShip' => $indonesiaSelfShip,
        'freeShippingThreshold' => 1200,
        'freeShippingBusinessRate' => $freeShipping ? 0.3 : 0,
        'freeShippingCompanyRate' => $freeShipping ? 0.7 : 0,
        'total' => $subtotal + $shippingFee,
        'paymentSummary' => $convertedPaymentSummary,
        'inventoryDeducted' => true,
        'inventoryDeductedAt' => $now,
        'inventoryReservedFirst' => $wasReserved,
    ];
    lz_apply_outbound_parcels($order, $inquiryParcels, $inquiryCarrier);
    if (is_array($existingOrder) && $existingOrderIndex >= 0) {
        // Preserve any shipping work already written to the durable order while
        // repairing conversion metadata that may have been missed by the first try.
        $order = array_merge($order, $existingOrder, [
            'sourceInquiryId' => $inquiryId,
            'convertedFromInquiryId' => $inquiryId,
            'inventoryOperationId' => $operationId,
            'preorderConvertOperationId' => $operationId,
            'preorderConvertPayloadHash' => $payloadHash,
        ]);
        $existingCustomer = is_array($existingOrder['customer'] ?? null) ? $existingOrder['customer'] : [];
        $order['customer'] = array_merge($customer, $existingCustomer);
        if (receipt_text($order['customer']['name'] ?? '') === '') $order['customer']['name'] = $customer['name'];
        if (receipt_text($order['customer']['phone'] ?? '') === '') $order['customer']['phone'] = $customer['phone'];
        if (receipt_text($order['customer']['address'] ?? '') === '') $order['customer']['address'] = $customer['address'];
        if (receipt_text($order['trackingNo'] ?? '') === '') $order['trackingNo'] = $inquiryTrackingNo;
        if (receipt_text($order['shippingCarrier'] ?? '') === '') $order['shippingCarrier'] = $inquiryCarrier;
        if (receipt_text($order['storeAddress'] ?? '') === '') $order['storeAddress'] = $inquiryStoreAddress;
        if (empty($order['outboundParcels']) && $inquiryParcels) {
            lz_apply_outbound_parcels($order, $inquiryParcels, $inquiryCarrier);
        }
        $order['customerName'] = receipt_first_text([$order['customerName'] ?? '', $order['customer']['name'] ?? '']);
        $order['customerPhone'] = receipt_first_text([$order['customerPhone'] ?? '', $order['customer']['phone'] ?? '']);
        $order['address'] = receipt_first_text([$order['address'] ?? '', $order['customer']['address'] ?? '']);
        lz_apply_parcel_rollup_to_order($order);
        $orders[$existingOrderIndex] = $order;
    } else {
        lz_apply_parcel_rollup_to_order($order);
        array_unshift($orders, $order);
    }
    write_json($ordersFile, $orders);
    $state = read_json($stateFile);
    $state['orders'] = $orders;
    $state['updatedAt'] = date(DATE_ATOM);
    write_json($stateFile, $state);
    $paymentRows = read_json($orderPaymentsFile);
    $existingPaymentRow = isset($paymentRows[$orderId]) && is_array($paymentRows[$orderId]) ? $paymentRows[$orderId] : [];
    $paymentRows[$orderId] = array_merge(
        $convertedPaymentSummary,
        // Once a payment row exists it is the accounting authority. A later retry
        // may repair missing conversion files but must not roll back receipts that
        // finance recorded after the original conversion.
        $existingPaymentRow,
        [
            'orderId' => $orderId,
            'updatedAt' => (string)($existingPaymentRow['updatedAt'] ?? $now),
        ]
    );
    write_json($orderPaymentsFile, $paymentRows);
    $partialConvertedIds = is_array($rows[$index]['partialConvertedOrderIds'] ?? null)
        ? $rows[$index]['partialConvertedOrderIds']
        : [];
    if (!in_array($orderId, $partialConvertedIds, true)) $partialConvertedIds[] = $orderId;
    if ($remainItems) {
        $remainSubtotal = 0.0;
        $remainWarehouses = [];
        foreach ($remainItems as &$remainItem) {
            if (!is_array($remainItem)) continue;
            $remainQty = max(0, (int)($remainItem['qty'] ?? $remainItem['quantity'] ?? 0));
            $remainPrice = max(0, (float)($remainItem['price'] ?? $remainItem['salePrice'] ?? $remainItem['unitPrice'] ?? 0));
            $remainItem['qty'] = $remainQty;
            $remainItem['quantity'] = $remainQty;
            $remainItem['subtotal'] = round($remainQty * $remainPrice, 2);
            $remainSubtotal += $remainItem['subtotal'];
            $remainWarehouse = inquiry_item_fulfill_warehouse($remainItem);
            if (isset($warehouseMap[$remainWarehouse])) $remainWarehouses[$remainWarehouse] = true;
        }
        unset($remainItem);
        $remainSubtotal = round($remainSubtotal, 2);
        $remainShipping = $remainSubtotal >= 1200 ? 0.0 : max(0, (float)($inquiry['regularShippingFee'] ?? $customer['regularShippingFee'] ?? 0));
        $rows[$index]['items'] = array_values($remainItems);
        $rows[$index]['subtotal'] = $remainSubtotal;
        $rows[$index]['shippingFee'] = $remainShipping;
        $rows[$index]['freeShipping'] = $remainSubtotal >= 1200;
        $rows[$index]['total'] = round($remainSubtotal + $remainShipping, 2);
        $rows[$index]['status'] = 'preorder_pending';
        $rows[$index]['statusLabel'] = '預購待採購';
        $rows[$index]['warehouseVerificationStatus'] = count($remainWarehouses) > 1 ? 'mixed' : 'verified';
        $rows[$index]['receivedWarehouses'] = array_keys($remainWarehouses);
        if (count($remainWarehouses) === 1) {
            $remainOnlyWarehouse = (string)array_key_first($remainWarehouses);
            $rows[$index]['arrivalWarehouse'] = $remainOnlyWarehouse;
            $rows[$index]['arrivalWarehouseLabel'] = $warehouseMap[$remainOnlyWarehouse];
            $rows[$index]['preorderFulfillWarehouse'] = $remainOnlyWarehouse;
            $rows[$index]['preorderFulfillWarehouseLabel'] = $warehouseMap[$remainOnlyWarehouse];
        }
        $rows[$index]['partialConvertedOrderIds'] = $partialConvertedIds;
        $rows[$index]['lastPartialConvertedOrderId'] = $orderId;
        $rows[$index]['lastPartialConvertedWarehouse'] = $fulfillWarehouse;
        unset($rows[$index]['convertedOrderId'], $rows[$index]['convertedToOrderId'], $rows[$index]['orderId']);
    } else {
        $rows[$index]['status'] = 'converted';
        $rows[$index]['statusLabel'] = 'Sudah menjadi order resmi';
        $rows[$index]['plannedShipmentMergeGroupId'] = '';
        $rows[$index]['plannedShipmentMasterInquiryId'] = '';
        $rows[$index]['plannedShipmentMergedInquiryIds'] = [];
        $rows[$index]['orderId'] = $orderId;
        $rows[$index]['convertedOrderId'] = $orderId;
        $rows[$index]['convertedToOrderId'] = $orderId;
        $rows[$index]['preorderConvertOperationId'] = $operationId;
        $rows[$index]['preorderConvertPayloadHash'] = $payloadHash;
        $rows[$index]['convertOperation'] = ['id' => $operationId, 'payloadHash' => $payloadHash, 'status' => 'completed', 'completedAt' => (string)($rows[$index]['preorderConvertedAt'] ?? $now), 'orderId' => $orderId];
        $rows[$index]['preorderConvertedAt'] = $isPreorder ? (string)($rows[$index]['preorderConvertedAt'] ?? $now) : ($rows[$index]['preorderConvertedAt'] ?? '');
        $rows[$index]['preorderFulfillWarehouse'] = $isPreorder ? $fulfillWarehouse : ($rows[$index]['preorderFulfillWarehouse'] ?? '');
        $rows[$index]['preorderFulfillWarehouseLabel'] = $isPreorder ? $fulfillWarehouseLabel : ($rows[$index]['preorderFulfillWarehouseLabel'] ?? '');
        $rows[$index]['preorderAutoInQty'] = 0;
        $rows[$index]['preorderReceiptQty'] = $isPreorder ? $preorderMoveQty : ($rows[$index]['preorderReceiptQty'] ?? 0);
        $rows[$index]['preorderAutoOutQty'] = $isPreorder ? $preorderMoveQty : ($rows[$index]['preorderAutoOutQty'] ?? 0);
        $rows[$index]['partialConvertedOrderIds'] = $partialConvertedIds;
    }
    if ($isPreorder) {
        $rows[$index]['preorderWaitConvertedAt'] = (string)($rows[$index]['preorderWaitConvertedAt'] ?? $now);
        $rows[$index]['preorderTransferOutAt'] = (string)($rows[$index]['preorderTransferOutAt'] ?? $now);
        $rows[$index]['preorderTransferOutBy'] = $transferBy;
        $transferLogs = is_array($rows[$index]['preorderTransferLogs'] ?? null) ? $rows[$index]['preorderTransferLogs'] : [];
        $hasTransferLog = false;
        foreach ($transferLogs as $logEntry) {
            if (is_array($logEntry) && (string)($logEntry['operationId'] ?? '') === $operationId) { $hasTransferLog = true; break; }
        }
        if (!$hasTransferLog) $transferLogs[] = $transferLogEntry;
        $rows[$index]['preorderTransferLogs'] = $transferLogs;
        $progressHistory = is_array($rows[$index]['preorderProgressHistory'] ?? null) ? $rows[$index]['preorderProgressHistory'] : [];
        $hasConversionHistory = false;
        foreach ($progressHistory as $historyEntry) {
            if (is_array($historyEntry) && (string)($historyEntry['operationId'] ?? '') === $operationId) { $hasConversionHistory = true; break; }
        }
        if (!$hasConversionHistory) $progressHistory[] = [
            'operationId' => $operationId,
            'at' => $now,
            'by' => $transferBy,
            'purchaseStatus' => $remainItems ? 'partial_converted' : 'converted',
            'purchaseStatusLabel' => $remainItems ? ('已先出' . $fulfillWarehouseLabel) : '已轉正式出貨並扣現貨',
            'preorderWaitStatus' => $remainItems ? 'waiting_other_items' : 'ready_to_ship',
            'preorderWaitStatusLabel' => $remainItems ? '其他倉庫商品待分別出貨' : '商品已齊／准許出貨',
            'supplierCarrier' => (string)($rows[$index]['supplierCarrier'] ?? ''),
            'supplierTrackingNo' => (string)($rows[$index]['supplierTrackingNo'] ?? ''),
            'arrivalWarehouse' => $fulfillWarehouse,
            'arrivalWarehouseLabel' => $fulfillWarehouseLabel,
            'note' => $remainItems
                ? ('已先從' . $fulfillWarehouseLabel . '出貨，其餘倉庫商品留在原單可再分別出')
                : '由原預購單轉正式出貨，已扣除同量現貨庫存',
        ];
        $rows[$index]['preorderProgressHistory'] = array_slice($progressHistory, -50);
    }
    $rows[$index]['reserved'] = false;
    $rows[$index]['updatedAt'] = $now;
    // A preorder may already have FIFO reservations against inbound/preorder stock.
    // When the administrator explicitly switches it to Taiwan on-hand stock, free
    // those inbound reservations so the next oldest customer can receive them.
    $releasedFreightQty = 0;
    if ($directStockAllocation && !$remainItems) {
        $releasedFreightQty = release_preorder_freight_links($freightTrackingFile, [$inquiryId]);
    }

    $customers = read_json($customersFile);
    $customerOrderIndex = -1;
    foreach ($customers as $customerIndex => $customerRow) {
        if (is_array($customerRow) && (string)($customerRow['lastOrderId'] ?? '') === $orderId) { $customerOrderIndex = $customerIndex; break; }
    }
    $customerOrderRow = [
        'code' => $customerOrderIndex >= 0 ? (string)($customers[$customerOrderIndex]['code'] ?? '') : 'INQ-CUST-' . strtoupper(substr(hash('sha256', $operationId), 0, 12)),
        'name' => $customer['name'] ?? '',
        'phone' => $customer['phone'] ?? '',
        'note' => $customer['note'] ?? '',
        'salesName' => $inquiry['salesName'] ?? '',
        'sourceInquiryId' => $inquiryId,
        'lastOrderId' => $orderId,
        'createdAt' => $customerOrderIndex >= 0 ? (string)($customers[$customerOrderIndex]['createdAt'] ?? $now) : $now,
        'updatedAt' => $now,
    ];
    if ($customerOrderIndex >= 0) $customers[$customerOrderIndex] = array_merge($customers[$customerOrderIndex], $customerOrderRow);
    else array_unshift($customers, $customerOrderRow);
    write_json($customersFile, $customers);
    if (is_array($customer) && trim((string)($customer['name'] ?? '')) !== '' && strlen(digits_only_value((string)($customer['phone'] ?? ''))) >= 6) {
        sync_customer_contact_to_members($customer, $customersFile, $membersFile, $stateFile, $now);
    }
    // Inquiry completion is deliberately last. Every prior file can be reconstructed
    // from the durable order and SKU transaction marker on the next retry.
    write_json($inquiriesFile, $rows);
    respond([
        'ok' => true,
        'inquiry' => $rows[$index],
        'order' => $order,
        'operationId' => $operationId,
        'idempotentReplay' => $idempotentReplay,
        'releasedFreightQty' => $releasedFreightQty,
        'shipWarehouse' => $fulfillWarehouse,
        'partialWarehouseConvert' => !!$remainItems,
        'remainingItemCount' => count($remainItems),
    ]);
}

respond(['ok' => false, 'error' => 'Aksi tidak didukung'], 400);

