#!/usr/bin/env node
"use strict";

/**
 * 查貨頁列出黑名單、退貨過的客戶，業務打字就能搜。
 * 不把 7-11「今日23:59後將退回物流中心」算成已退貨。
 *
 * Cache-bust: cek-kirim.js/css ?v=20260820-cek-risk-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const PAGES = process.env.CEK_PAGES || [
  path.join(__dirname, "..", "lingzanzan-pages"),
  path.join(__dirname, "lingzanzan-pages"),
].find((dir) => fs.existsSync(path.join(dir, "cek-kirim.js")));
const STAMP = "20260820-cek-risk-1";
const LOOKUP = path.join(ROOT, "customer-shipping-lookup-api.php");
const JS_MARKER = "function riskCardHtml(";
const PHP_MARKER = "function cek_is_returned_order(";

if (!PAGES) throw new Error("missing lingzanzan-pages/cek-kirim.js");

function backup(file, tag) {
  const dir = path.join(ROOT, "data", "audit");
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(
    dir,
    path.basename(file) + "." + tag + "-" + new Date().toISOString().replace(/[:.]/g, "-")
  );
  if (fs.existsSync(file)) fs.copyFileSync(file, dest);
  return dest;
}

function copyUtf8(srcName, destRel) {
  const src = path.join(PAGES, srcName);
  const dest = path.join(ROOT, destRel);
  fs.copyFileSync(src, dest);
  console.log("copied", destRel, fs.statSync(dest).size);
}

function replaceOnce(src, oldStr, newStr, label) {
  if (src.indexOf(newStr) !== -1 && src.indexOf(oldStr) === -1) {
    console.log("already:", label);
    return src;
  }
  let from = oldStr;
  let to = newStr;
  let i = src.indexOf(from);
  if (i < 0) {
    from = oldStr.replace(/\n/g, "\r\n");
    to = newStr.replace(/\n/g, "\r\n");
    i = src.indexOf(from);
  }
  if (i < 0) throw new Error("missing snippet: " + label);
  if (src.indexOf(from, i + from.length) !== -1) throw new Error("not unique: " + label);
  console.log("patched:", label);
  return src.slice(0, i) + to + src.slice(i + from.length);
}

const HELPERS_OLD = `function returning_soon(array $queue, ?int $remaining): bool {
    $msg = text($queue['message'] ?? ($queue['sourceStatusText'] ?? ''), 300);
    if (preg_match('/將退回|今日.{0,12}23/u', $msg)) return true;
    return $remaining !== null && $remaining <= 1;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {`;

const HELPERS_NEW = `function returning_soon(array $queue, ?int $remaining): bool {
    $msg = text($queue['message'] ?? ($queue['sourceStatusText'] ?? ''), 300);
    if (preg_match('/將退回|今日.{0,12}23/u', $msg)) return true;
    return $remaining !== null && $remaining <= 1;
}
function cek_is_returned_order(array $order, array $queue): bool {
    $state = effective_state($order, $queue);
    if ($state === 'returned') return true;
    $status = strtolower(text($order['status'] ?? '', 40));
    $delivery = strtolower(text($order['deliveryState'] ?? '', 40));
    return $status === 'returned' || $delivery === 'returned';
}
function cek_date_text(array $row, array $keys): string {
    foreach ($keys as $key) {
        $value = text($row[$key] ?? '', 40);
        if ($value !== '') return substr($value, 0, 10);
    }
    return '';
}
function cek_person_card(string $name, string $phone, array $extra = []): array {
    $state = text($extra['state'] ?? 'pending', 40);
    $base = [
        'orderId' => '',
        'customerId' => '',
        'customerName' => $name,
        'phone' => $phone,
        'customerImage' => '',
        'customerPrefix' => mb_strtoupper(mb_substr(compact_key($name), 0, 2, 'UTF-8'), 'UTF-8'),
        'orderDate' => '',
        'carrier' => '',
        'trackingNo' => '',
        'store' => '',
        'products' => '',
        'state' => $state,
        'stateLabel' => $state === 'blacklist' ? '黑名單' : status_label($state),
        'latestReport' => '',
        'latestReportAt' => '',
        'suggestedStatus' => '',
        'manualRequired' => false,
        'arrivedStoreAt' => '',
        'pickupDeadline' => '',
        'pickupDeadlineSource' => '',
        'shippedDate' => '',
        'remainingPickupDays' => null,
        'deadlineMissing' => false,
        'items' => [],
        'codAmount' => 0,
        'blacklisted' => false,
        'blacklistReason' => '',
        'returnCount' => 0,
        'lastReturnAt' => '',
        'returnedBefore' => false,
        'kind' => 'person',
        'createdBy' => '',
        'lockedAt' => '',
    ];
    return array_merge($base, $extra);
}
function cek_return_lookup(array $returnByKey, string $phone, string $name): ?array {
    if ($phone !== '' && isset($returnByKey['p:' . $phone])) return $returnByKey['p:' . $phone];
    $nk = compact_key($name);
    if ($nk !== '' && isset($returnByKey['n:' . $nk])) return $returnByKey['n:' . $nk];
    return null;
}
function cek_stamp_return(array $cek, array $returnByKey): array {
    $info = cek_return_lookup($returnByKey, (string)($cek['phone'] ?? ''), (string)($cek['customerName'] ?? ''));
    $cek['returnCount'] = (int)($info['count'] ?? 0);
    $cek['lastReturnAt'] = (string)($info['latestDate'] ?? '');
    $cek['returnedBefore'] = $cek['returnCount'] > 0
        || (($cek['state'] ?? '') === 'returned')
        || (($cek['kind'] ?? '') === 'returned_customer');
    return $cek;
}
function cek_bump_return(array &$returnByKey, string $phone, string $name, string $date, array $meta = []): void {
    if ($phone === '' && compact_key($name) === '') return;
    $key = $phone !== '' ? 'p:' . $phone : 'n:' . compact_key($name);
    if (!isset($returnByKey[$key])) {
        $returnByKey[$key] = [
            'phone' => $phone,
            'name' => $name,
            'count' => 0,
            'latestDate' => '',
            'orderId' => '',
            'trackingNo' => '',
            'products' => '',
            'customerImage' => '',
            'codAmount' => 0,
            'order' => null,
        ];
    }
    $returnByKey[$key]['count']++;
    if ($name !== '') $returnByKey[$key]['name'] = $name;
    if ($phone !== '') $returnByKey[$key]['phone'] = $phone;
    if ($date >= $returnByKey[$key]['latestDate']) {
        $returnByKey[$key]['latestDate'] = $date;
        foreach (['orderId', 'trackingNo', 'products', 'customerImage', 'codAmount', 'order'] as $field) {
            if (array_key_exists($field, $meta)) $returnByKey[$key][$field] = $meta[$field];
        }
    }
}
function cek_query_hits_person(string $phone, string $name, string $queryDigits, string $queryKey, string $queryType): bool {
    if ($queryDigits !== '' && mb_strlen($queryDigits) >= 3 && query_hits_phone($phone, $queryDigits)) return true;
    if ($queryKey !== '' && $queryType === 'name' && str_contains(compact_key($name), $queryKey)) return true;
    return false;
}
function cek_try_row(callable $cekRow, array $order, array $queueByOrder, array $queueByTracking, array $logisticsByTracking): ?array {
    $orderId = text($order['id'] ?? '', 120);
    $trackingKey = compact_key($order['trackingNo'] ?? ($order['shippingTrackingNo'] ?? ''));
    $queue = $queueByOrder[$orderId] ?? ($queueByTracking[$trackingKey] ?? []);
    $legacy = $logisticsByTracking[$trackingKey] ?? [];
    try {
        return $cekRow($order, is_array($queue) ? $queue : [], is_array($legacy) ? $legacy : []);
    } catch (Throwable $e) {
        return null;
    }
}
function cek_build_indexes(array $orders, array $queueByOrder, array $queueByTracking): array {
    $latestByPhone = [];
    $returnByKey = [];
    $formalOrderIds = [];
    $formalRoot = read_json(__DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'return-orders.json');
    $formalRows = [];
    if (isset($formalRoot['rows']) && is_array($formalRoot['rows'])) $formalRows = $formalRoot['rows'];
    elseif (isset($formalRoot['items']) && is_array($formalRoot['items'])) $formalRows = $formalRoot['items'];
    elseif ($formalRoot !== [] && array_is_list($formalRoot)) $formalRows = $formalRoot;
    foreach ($formalRows as $formalRow) {
        if (!is_array($formalRow) || text($formalRow['type'] ?? '', 40) !== 'customer') continue;
        $status = strtolower(text($formalRow['status'] ?? '', 40));
        if (in_array($status, ['draft', 'awaiting_return', 'cancelled', 'canceled', 'void'], true)) continue;
        if (empty($formalRow['inventoryOperation']) && !in_array($status, ['received', 'pending_refund', 'completed'], true)) continue;
        $customer = isset($formalRow['customer']) && is_array($formalRow['customer']) ? $formalRow['customer'] : [];
        $name = text($customer['name'] ?? ($formalRow['customerName'] ?? ''));
        $phone = digits($customer['phone'] ?? ($formalRow['customerPhone'] ?? ($formalRow['normalizedPhone'] ?? ($formalRow['phone'] ?? ''))));
        $date = cek_date_text($formalRow, ['returnedAt', 'documentDate', 'updatedAt', 'createdAt']);
        $sourceId = text($formalRow['sourceOrderId'] ?? '', 120);
        if ($sourceId !== '') $formalOrderIds[$sourceId] = true;
        cek_bump_return($returnByKey, $phone, $name, $date, [
            'orderId' => $sourceId !== '' ? $sourceId : text($formalRow['id'] ?? ($formalRow['returnNo'] ?? ''), 120),
            'trackingNo' => text($formalRow['vendorTrackingNo'] ?? ($formalRow['trackingNo'] ?? ''), 160),
        ]);
    }
    foreach ($orders as $order) {
        if (!is_array($order)) continue;
        $customer = isset($order['customer']) && is_array($order['customer']) ? $order['customer'] : [];
        $name = text($customer['name'] ?? ($order['customerName'] ?? ''));
        $phone = digits($customer['phone'] ?? ($order['customerPhone'] ?? ($order['phone'] ?? '')));
        $orderId = text($order['id'] ?? '', 120);
        $date = cek_date_text($order, ['updatedAt', 'orderDate', 'createdAt']);
        if ($phone !== '') {
            $prev = $latestByPhone[$phone]['date'] ?? '';
            if ($date >= $prev) $latestByPhone[$phone] = ['order' => $order, 'date' => $date];
        }
        if ($orderId !== '' && isset($formalOrderIds[$orderId])) continue;
        $trackingKey = compact_key($order['trackingNo'] ?? ($order['shippingTrackingNo'] ?? ''));
        $queue = $queueByOrder[$orderId] ?? ($queueByTracking[$trackingKey] ?? []);
        if (!is_array($queue)) $queue = [];
        if (!cek_is_returned_order($order, $queue)) continue;
        cek_bump_return($returnByKey, $phone, $name, cek_date_text($order, ['returnedAt', 'returnedStoreAt', 'updatedAt', 'orderDate', 'createdAt']), [
            'orderId' => $orderId,
            'trackingNo' => text($order['trackingNo'] ?? ($order['shippingTrackingNo'] ?? ''), 160),
            'products' => product_line($order),
            'customerImage' => customer_photo($order, $customer),
            'codAmount' => collection_amount($order),
            'order' => $order,
        ]);
    }
    return ['latestByPhone' => $latestByPhone, 'returnByKey' => $returnByKey];
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {`;

const ROW_FIELDS_OLD = `        'blacklisted' => false,
        'blacklistReason' => '',
    ];`;

const ROW_FIELDS_NEW = `        'blacklisted' => false,
        'blacklistReason' => '',
        'returnCount' => 0,
        'lastReturnAt' => '',
        'returnedBefore' => false,
        'kind' => 'order',
        'createdBy' => '',
        'lockedAt' => '',
    ];`;

const INDEX_OLD = `    return $cek;
};

if ($board) {`;

const INDEX_NEW = `    return $cek;
};

$cekIndex = cek_build_indexes($orders, $queueByOrder, $queueByTracking);
$latestByPhone = $cekIndex['latestByPhone'];
$returnByKey = $cekIndex['returnByKey'];

if ($board) {`;

const BOARD_STAMP_OLD = `        try {
            $row = $cek_row($order, $queue, $legacy);
        } catch (Throwable $e) {
            continue;
        }
        $remaining = $row['remainingPickupDays'];`;

const BOARD_STAMP_NEW = `        try {
            $row = cek_stamp_return($cek_row($order, $queue, $legacy), $returnByKey);
        } catch (Throwable $e) {
            continue;
        }
        $remaining = $row['remainingPickupDays'];`;

const BOARD_LISTS_OLD = `    respond([
        'ok' => true,
        'board' => true,
        'days' => 7,
        'checkedAt' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei')))->format(DATE_ATOM),
        'returning' => $returning,
        'waiting' => $waiting,
        'writesOrder' => false,
    ]);
}`;

const BOARD_LISTS_NEW = `    $blacklist = [];
    $seenRisk = [];
    if (function_exists('member_risk_rows')) {
        foreach (member_risk_rows(__DIR__) as $risk) {
            if (!is_array($risk)) continue;
            $phone = digits($risk['phone'] ?? '');
            $name = text($risk['name'] ?? '');
            if ($phone === '' && $name === '') continue;
            $dup = $phone !== '' ? 'p:' . $phone : 'n:' . compact_key($name);
            if (isset($seenRisk[$dup])) continue;
            $seenRisk[$dup] = true;
            $row = null;
            if ($phone !== '' && isset($latestByPhone[$phone]['order']) && is_array($latestByPhone[$phone]['order'])) {
                $row = cek_try_row($cek_row, $latestByPhone[$phone]['order'], $queueByOrder, $queueByTracking, $logisticsByTracking);
            }
            if (!is_array($row)) {
                $row = cek_person_card($name, $phone, [
                    'kind' => 'blacklist',
                    'state' => 'blacklist',
                    'stateLabel' => '黑名單',
                    'blacklisted' => true,
                    'blacklistReason' => text($risk['reason'] ?? '', 200),
                    'latestReportAt' => text($risk['createdAt'] ?? '', 80),
                    'createdBy' => text($risk['createdBy'] ?? '', 80),
                    'lockedAt' => text($risk['createdAt'] ?? '', 80),
                ]);
            } else {
                $row['blacklisted'] = true;
                $row['blacklistReason'] = text($risk['reason'] ?? '', 200);
                $row['createdBy'] = text($risk['createdBy'] ?? '', 80);
                $row['lockedAt'] = text($risk['createdAt'] ?? '', 80);
            }
            $row = cek_stamp_return($row, $returnByKey);
            $blacklist[] = $row;
        }
    }
    usort($blacklist, static function(array $a, array $b): int {
        return strcmp((string)($b['lockedAt'] ?? ''), (string)($a['lockedAt'] ?? ''));
    });
    $returnedCustomers = [];
    foreach ($returnByKey as $info) {
        if (!is_array($info)) continue;
        $row = null;
        if (isset($info['order']) && is_array($info['order'])) {
            $row = cek_try_row($cek_row, $info['order'], $queueByOrder, $queueByTracking, $logisticsByTracking);
        }
        if (!is_array($row)) {
            $row = cek_person_card((string)($info['name'] ?? ''), (string)($info['phone'] ?? ''), [
                'kind' => 'returned_customer',
                'state' => 'returned',
                'orderId' => (string)($info['orderId'] ?? ''),
                'trackingNo' => (string)($info['trackingNo'] ?? ''),
                'products' => (string)($info['products'] ?? ''),
                'customerImage' => (string)($info['customerImage'] ?? ''),
                'codAmount' => (int)($info['codAmount'] ?? 0),
                'orderDate' => (string)($info['latestDate'] ?? ''),
            ]);
        } else {
            $row['kind'] = 'returned_customer';
        }
        $row = cek_stamp_return($row, $returnByKey);
        $row['returnCount'] = (int)($info['count'] ?? 0);
        $row['lastReturnAt'] = (string)($info['latestDate'] ?? '');
        $row['returnedBefore'] = true;
        $returnedCustomers[] = $row;
    }
    usort($returnedCustomers, static function(array $a, array $b): int {
        return strcmp((string)($b['lastReturnAt'] ?? ''), (string)($a['lastReturnAt'] ?? ''));
    });
    respond([
        'ok' => true,
        'board' => true,
        'days' => 7,
        'checkedAt' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei')))->format(DATE_ATOM),
        'returning' => $returning,
        'waiting' => $waiting,
        'blacklist' => array_slice($blacklist, 0, 80),
        'returnedCustomers' => array_slice($returnedCustomers, 0, 80),
        'writesOrder' => false,
    ]);
}`;

const SEARCH_STAMP_OLD = `    try {
        $results[] = $cek_row($order, $queue, $legacy);
    } catch (Throwable $e) {
        continue;
    }
    if (count($results) >= 80) break;
}

usort($results, static function(array $a, array $b): int {`;

const SEARCH_STAMP_NEW = `    try {
        $results[] = cek_stamp_return($cek_row($order, $queue, $legacy), $returnByKey);
    } catch (Throwable $e) {
        continue;
    }
    if (count($results) >= 80) break;
}

$seenPhone = [];
$seenName = [];
foreach ($results as $row) {
    if (($row['phone'] ?? '') !== '') $seenPhone[$row['phone']] = true;
    $nk = compact_key($row['customerName'] ?? '');
    if ($nk !== '') $seenName[$nk] = true;
}
if (function_exists('member_risk_rows') && count($results) < 80) {
    foreach (member_risk_rows(__DIR__) as $risk) {
        if (!is_array($risk) || count($results) >= 80) break;
        $phone = digits($risk['phone'] ?? '');
        $name = text($risk['name'] ?? '');
        if (!cek_query_hits_person($phone, $name, $queryDigits, $queryKey, $queryType)) continue;
        if ($phone !== '' && isset($seenPhone[$phone])) continue;
        if ($phone === '' && isset($seenName[compact_key($name)])) continue;
        $row = null;
        if ($phone !== '' && isset($latestByPhone[$phone]['order']) && is_array($latestByPhone[$phone]['order'])) {
            $row = cek_try_row($cek_row, $latestByPhone[$phone]['order'], $queueByOrder, $queueByTracking, $logisticsByTracking);
        }
        if (!is_array($row)) {
            $row = cek_person_card($name, $phone, [
                'kind' => 'blacklist',
                'state' => 'blacklist',
                'stateLabel' => '黑名單',
                'blacklisted' => true,
                'blacklistReason' => text($risk['reason'] ?? '', 200),
                'latestReportAt' => text($risk['createdAt'] ?? '', 80),
                'createdBy' => text($risk['createdBy'] ?? '', 80),
                'lockedAt' => text($risk['createdAt'] ?? '', 80),
            ]);
        } else {
            $row['blacklisted'] = true;
            $row['blacklistReason'] = text($risk['reason'] ?? '', 200);
            $row['kind'] = 'blacklist';
        }
        $row = cek_stamp_return($row, $returnByKey);
        $results[] = $row;
        if ($phone !== '') $seenPhone[$phone] = true;
        $nk = compact_key($name);
        if ($nk !== '') $seenName[$nk] = true;
    }
}
if (count($results) < 80) {
    foreach ($returnByKey as $info) {
        if (!is_array($info) || count($results) >= 80) break;
        $phone = (string)($info['phone'] ?? '');
        $name = (string)($info['name'] ?? '');
        if (!cek_query_hits_person($phone, $name, $queryDigits, $queryKey, $queryType)) continue;
        if ($phone !== '' && isset($seenPhone[$phone])) continue;
        if ($phone === '' && isset($seenName[compact_key($name)])) continue;
        $row = null;
        if (isset($info['order']) && is_array($info['order'])) {
            $row = cek_try_row($cek_row, $info['order'], $queueByOrder, $queueByTracking, $logisticsByTracking);
        }
        if (!is_array($row)) {
            $row = cek_person_card($name, $phone, [
                'kind' => 'returned_customer',
                'state' => 'returned',
                'orderId' => (string)($info['orderId'] ?? ''),
                'trackingNo' => (string)($info['trackingNo'] ?? ''),
                'products' => (string)($info['products'] ?? ''),
                'customerImage' => (string)($info['customerImage'] ?? ''),
                'codAmount' => (int)($info['codAmount'] ?? 0),
            ]);
        } else {
            $row['kind'] = 'returned_customer';
        }
        $row = cek_stamp_return($row, $returnByKey);
        $row['returnCount'] = (int)($info['count'] ?? 0);
        $row['lastReturnAt'] = (string)($info['latestDate'] ?? '');
        $row['returnedBefore'] = true;
        $results[] = $row;
        if ($phone !== '') $seenPhone[$phone] = true;
    }
}

usort($results, static function(array $a, array $b): int {`;

if (!fs.existsSync(LOOKUP)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

copyUtf8("cek.html", "cek.html");
copyUtf8("cek.html", "sales-phone.html");
copyUtf8("cek-kirim.css", "assets/cek-kirim.css");
copyUtf8("cek-kirim.js", "assets/cek-kirim.js");

console.log("backup lookup", backup(LOOKUP, "cek-risk"));
let php = fs.readFileSync(LOOKUP, "utf8");
if (php.indexOf(PHP_MARKER) === -1) {
  php = replaceOnce(php, HELPERS_OLD, HELPERS_NEW, "cek risk helpers");
} else {
  console.log("already: cek risk helpers");
}
if (php.indexOf("'kind' => 'order'") === -1) {
  php = replaceOnce(php, ROW_FIELDS_OLD, ROW_FIELDS_NEW, "cek_row risk fields");
} else {
  console.log("already: cek_row risk fields");
}
if (php.indexOf("$cekIndex = cek_build_indexes(") === -1) {
  php = replaceOnce(php, INDEX_OLD, INDEX_NEW, "build risk indexes");
} else {
  console.log("already: build risk indexes");
}
if (php.indexOf("cek_stamp_return($cek_row($order, $queue, $legacy), $returnByKey)") === -1) {
  php = replaceOnce(php, BOARD_STAMP_OLD, BOARD_STAMP_NEW, "board stamp return");
  php = replaceOnce(php, SEARCH_STAMP_OLD, SEARCH_STAMP_NEW, "search risk matches");
} else {
  console.log("already: board/search return stamp");
}
if (php.indexOf("'returnedCustomers'") === -1) {
  php = replaceOnce(php, BOARD_LISTS_OLD, BOARD_LISTS_NEW, "board blacklist+returned lists");
} else {
  console.log("already: board risk lists");
}
if (php.indexOf(PHP_MARKER) === -1) throw new Error("cek_is_returned_order missing");
if (php.indexOf("'returnedCustomers'") === -1) throw new Error("returnedCustomers missing");
if (php.indexOf("cek_query_hits_person") === -1) throw new Error("search person match missing");
fs.writeFileSync(LOOKUP, php, "utf8");

const html = fs.readFileSync(path.join(ROOT, "cek.html"), "utf8");
const js = fs.readFileSync(path.join(ROOT, "assets", "cek-kirim.js"), "utf8");
if (html.indexOf("cek-kirim.js?v=" + STAMP) === -1) throw new Error("stamp missing");
if (js.indexOf(JS_MARKER) === -1) throw new Error("riskCardHtml missing");
if (html.indexOf("\uFFFD") !== -1) throw new Error("cek html has FFFD");
console.log("LINGZANZAN cek risk lists ok", STAMP);
