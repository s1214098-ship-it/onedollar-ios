#!/usr/bin/env node
"use strict";

/**
 * 查貨頁列出配送中貨態，官網查不到另外一欄給老闆看。
 * Cache-bust: cek-kirim.js/css ?v=20260821-cek-ship-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const PAGES = process.env.CEK_PAGES || [
  path.join(__dirname, "..", "lingzanzan-pages"),
  path.join(__dirname, "lingzanzan-pages"),
].find((dir) => fs.existsSync(path.join(dir, "cek-kirim.js")));
const STAMP = "20260821-cek-ship-1";
const LOOKUP = path.join(ROOT, "customer-shipping-lookup-api.php");
const JS_MARKER = "tabTransit";
const PHP_MARKER = "'inTransit' =>";

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

const TRACKING_HELPER_OLD = `function returning_soon(array $queue, ?int $remaining): bool {
    $msg = text($queue['message'] ?? ($queue['sourceStatusText'] ?? ''), 300);
    if (preg_match('/將退回|今日.{0,12}23/u', $msg)) return true;
    return $remaining !== null && $remaining <= 1;
}`;

const TRACKING_HELPER_NEW = `function returning_soon(array $queue, ?int $remaining): bool {
    $msg = text($queue['message'] ?? ($queue['sourceStatusText'] ?? ''), 300);
    if (preg_match('/將退回|今日.{0,12}23/u', $msg)) return true;
    return $remaining !== null && $remaining <= 1;
}
function cek_has_carrier_tracking(string $tracking): bool {
    $t = strtoupper((string)(preg_replace('/\\s+/', '', $tracking) ?? ''));
    if ($t === '') return false;
    if (preg_match('/^E\\d{8,}$/', $t)) return true;
    return (bool)preg_match('/^\\d{10,}$/', $t);
}`; `        'latestReport' => text($progress['note'] ?? ($queue['message'] ?? ($legacy['note'] ?? '')), 300),
        'latestReportAt' => text($progress['at'] ?? ($queue['checkedAt'] ?? ($order['updatedAt'] ?? '')), 80),
        'suggestedStatus' => text($queue['suggestedStatus'] ?? '', 40),`;

const REPORT_NEW = `        'latestReport' => text($queue['sourceStatusText'] ?? ($queue['message'] ?? ($progress['note'] ?? ($legacy['note'] ?? ''))), 300),
        'latestReportAt' => text($queue['lastOfficialResultAt'] ?? ($queue['checkedAt'] ?? ($progress['at'] ?? ($order['updatedAt'] ?? ''))), 80),
        'suggestedStatus' => text($queue['suggestedStatus'] ?? '', 40),
        'resultType' => text($queue['resultType'] ?? '', 40),`;

const BOARD_OLD = `    $returning = [];
    $waiting = [];
    foreach ($orders as $order) {
        if (!is_array($order)) continue;
        $status = strtolower(text($order['status'] ?? '', 40));
        if (in_array($status, ['cancelled', 'canceled'], true)) continue;
        $orderId = text($order['id'] ?? '', 120);
        $trackingKey = compact_key($order['trackingNo'] ?? ($order['shippingTrackingNo'] ?? ''));
        $queue = $queueByOrder[$orderId] ?? ($queueByTracking[$trackingKey] ?? []);
        if (!is_array($queue)) $queue = [];
        $legacy = $logisticsByTracking[$trackingKey] ?? [];
        if (!is_array($legacy)) $legacy = [];
        if (effective_state($order, $queue) !== 'arrived_store') continue;
        try {
            $row = cek_stamp_return($cek_row($order, $queue, $legacy), $returnByKey);
        } catch (Throwable $e) {
            continue;
        }
        $remaining = $row['remainingPickupDays'];
        if (returning_soon($queue, $remaining)) {
            $returning[] = $row;
            continue;
        }
        if ($remaining === null || ($remaining <= 7 && $remaining >= -7)) $waiting[] = $row;
    }
    usort($returning, static function(array $a, array $b): int {
        return ((int)($a['remainingPickupDays'] ?? 999)) <=> ((int)($b['remainingPickupDays'] ?? 999));
    });
    usort($waiting, static function(array $a, array $b): int {
        $ra = $a['remainingPickupDays'];
        $rb = $b['remainingPickupDays'];
        if ($ra === null && $rb === null) return 0;
        if ($ra === null) return 1;
        if ($rb === null) return -1;
        return (int)$ra <=> (int)$rb;
    });`;

const BOARD_NEW = `    $returning = [];
    $waiting = [];
    $inTransit = [];
    $pendingShip = [];
    $noData = [];
    foreach ($orders as $order) {
        if (!is_array($order)) continue;
        $status = strtolower(text($order['status'] ?? '', 40));
        $delivery = strtolower(text($order['deliveryState'] ?? '', 40));
        if (in_array($status, ['cancelled', 'canceled', 'void'], true) || str_contains($delivery, 'cancel')) continue;
        $orderId = text($order['id'] ?? '', 120);
        $trackingKey = compact_key($order['trackingNo'] ?? ($order['shippingTrackingNo'] ?? ''));
        $queue = $queueByOrder[$orderId] ?? ($queueByTracking[$trackingKey] ?? []);
        if (!is_array($queue)) $queue = [];
        $legacy = $logisticsByTracking[$trackingKey] ?? [];
        if (!is_array($legacy)) $legacy = [];
        $state = effective_state($order, $queue);
        if (in_array($state, ['delivered', 'returned'], true) || in_array($status, ['delivered', 'completed', 'returned'], true) || in_array($delivery, ['delivered', 'returned'], true)) continue;
        try {
            $row = cek_stamp_return($cek_row($order, $queue, $legacy), $returnByKey);
        } catch (Throwable $e) {
            continue;
        }
        $remaining = $row['remainingPickupDays'];
        $resultType = strtolower(text($queue['resultType'] ?? ($row['resultType'] ?? ''), 40));
        if ($state === 'arrived_store') {
            if (returning_soon($queue, $remaining)) {
                $returning[] = $row;
                continue;
            }
            if ($remaining === null || ($remaining <= 7 && $remaining >= -7)) $waiting[] = $row;
            continue;
        }
        if ($resultType === 'official_no_data') {
            $row['kind'] = 'no_data';
            $row['stateLabel'] = '官網無資料';
            $noData[] = $row;
            continue;
        }
        $officialMsg = text($queue['sourceStatusText'] ?? ($queue['message'] ?? ($row['latestReport'] ?? '')), 300);
        $hasTracking = cek_has_carrier_tracking(text($row['trackingNo'] ?? '', 160));
        if ($hasTracking && preg_match('/尚未至門市寄件|訂單成立未寄件/u', $officialMsg)) {
            $row['stateLabel'] = '尚未寄件';
            $pendingShip[] = $row;
            continue;
        }
        if (in_array($state, ['in_transit', 'shipped'], true)) {
            $inTransit[] = $row;
            continue;
        }
        if ($hasTracking && (in_array($state, ['pending', 'ready'], true) || $resultType === 'pending_lookup')) {
            $row['stateLabel'] = '尚未寄件';
            $pendingShip[] = $row;
        }
    }
    usort($returning, static function(array $a, array $b): int {
        return ((int)($a['remainingPickupDays'] ?? 999)) <=> ((int)($b['remainingPickupDays'] ?? 999));
    });
    usort($waiting, static function(array $a, array $b): int {
        $ra = $a['remainingPickupDays'];
        $rb = $b['remainingPickupDays'];
        if ($ra === null && $rb === null) return 0;
        if ($ra === null) return 1;
        if ($rb === null) return -1;
        return (int)$ra <=> (int)$rb;
    });
    usort($inTransit, static function(array $a, array $b): int {
        return strcmp((string)($b['latestReportAt'] ?? ''), (string)($a['latestReportAt'] ?? ''));
    });
    usort($pendingShip, static function(array $a, array $b): int {
        return strcmp((string)($a['customerName'] ?? ''), (string)($b['customerName'] ?? ''));
    });
    usort($noData, static function(array $a, array $b): int {
        return strcmp((string)($a['customerName'] ?? ''), (string)($b['customerName'] ?? ''));
    });`;

const RESPOND_OLD = `        'returning' => $returning,
        'waiting' => $waiting,
        'blacklist' => array_slice($blacklist, 0, 300),
        'returnedCustomers' => array_slice($returnedCustomers, 0, 300),`;

const RESPOND_NEW = `        'returning' => $returning,
        'waiting' => $waiting,
        'inTransit' => array_slice($inTransit, 0, 300),
        'pendingShip' => array_slice($pendingShip, 0, 300),
        'noData' => array_slice($noData, 0, 300),
        'blacklist' => array_slice($blacklist, 0, 300),
        'returnedCustomers' => array_slice($returnedCustomers, 0, 300),`;

if (!fs.existsSync(LOOKUP)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

copyUtf8("cek.html", "cek.html");
copyUtf8("cek.html", "sales-phone.html");
copyUtf8("cek-kirim.css", "assets/cek-kirim.css");
copyUtf8("cek-kirim.js", "assets/cek-kirim.js");

console.log("backup lookup", backup(LOOKUP, "cek-ship"));
let php = fs.readFileSync(LOOKUP, "utf8");
if (php.indexOf("function cek_has_carrier_tracking(") === -1) {
  php = replaceOnce(php, TRACKING_HELPER_OLD, TRACKING_HELPER_NEW, "carrier tracking helper");
} else {
  console.log("already: carrier tracking helper");
}
if (php.indexOf("'resultType' => text($queue['resultType'] ?? '', 40)") === -1) {
  php = replaceOnce(php, REPORT_OLD, REPORT_NEW, "prefer official queue status text");
} else {
  console.log("already: official queue status text");
}
if (php.indexOf("$inTransit = [];") === -1) {
  php = replaceOnce(php, BOARD_OLD, BOARD_NEW, "board transit/pending/nodata");
} else if (php.indexOf("cek_has_carrier_tracking(text($row['trackingNo']") === -1) {
  php = replaceOnce(php, `        if ($resultType === 'official_no_data') {
            $row['kind'] = 'no_data';
            $row['stateLabel'] = '官網無資料';
            $noData[] = $row;
            continue;
        }
        if (in_array($state, ['in_transit', 'shipped'], true)) {
            $inTransit[] = $row;
            continue;
        }
        if (in_array($state, ['pending', 'ready'], true) || $resultType === 'pending_lookup') {
            $row['stateLabel'] = '尚未寄件';
            $pendingShip[] = $row;
        }`, `        if ($resultType === 'official_no_data') {
            $row['kind'] = 'no_data';
            $row['stateLabel'] = '官網無資料';
            $noData[] = $row;
            continue;
        }
        $officialMsg = text($queue['sourceStatusText'] ?? ($queue['message'] ?? ($row['latestReport'] ?? '')), 300);
        $hasTracking = cek_has_carrier_tracking(text($row['trackingNo'] ?? '', 160));
        if ($hasTracking && preg_match('/尚未至門市寄件|訂單成立未寄件/u', $officialMsg)) {
            $row['stateLabel'] = '尚未寄件';
            $pendingShip[] = $row;
            continue;
        }
        if (in_array($state, ['in_transit', 'shipped'], true)) {
            $inTransit[] = $row;
            continue;
        }
        if ($hasTracking && (in_array($state, ['pending', 'ready'], true) || $resultType === 'pending_lookup')) {
            $row['stateLabel'] = '尚未寄件';
            $pendingShip[] = $row;
        }`, "board pending tracking filter");
} else {
  console.log("already: board transit buckets");
}
if (php.indexOf(PHP_MARKER) === -1) {
  php = replaceOnce(php, RESPOND_OLD, RESPOND_NEW, "board respond transit lists");
} else {
  console.log("already: board respond transit lists");
}
if (php.indexOf("cek_has_carrier_tracking(text($row['trackingNo']") === -1) throw new Error("tracking filter missing");
if (php.indexOf(PHP_MARKER) === -1) throw new Error("inTransit missing");
if (php.indexOf("$noData = [];") === -1) throw new Error("noData missing");
fs.writeFileSync(LOOKUP, php, "utf8");

const html = fs.readFileSync(path.join(ROOT, "cek.html"), "utf8");
const js = fs.readFileSync(path.join(ROOT, "assets", "cek-kirim.js"), "utf8");
if (html.indexOf("cek-kirim.js?v=" + STAMP) === -1) throw new Error("stamp missing");
if (js.indexOf(JS_MARKER) === -1) throw new Error("tabTransit missing");
if (html.indexOf("\uFFFD") !== -1) throw new Error("cek html has FFFD");
console.log("LINGZANZAN cek transit board ok", STAMP);
