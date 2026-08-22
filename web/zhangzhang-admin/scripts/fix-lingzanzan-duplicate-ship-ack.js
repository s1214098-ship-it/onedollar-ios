#!/usr/bin/env node
"use strict";

/**
 * 強制重複相同訂單核對：已出貨或未出貨都要行政先按「確定」。
 * 未核對且系統查到疏忽 → 記 1 點、扣款 NT$100 一次。
 *
 * Cache-bust: admin.js / finance.js file mtime (asset-boot) + stamp fallback
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const FIN_JS = path.join(ROOT, "assets", "finance.js");
const API_PHP = path.join(ROOT, "order-admin-api-v6.php");
const LOSS_PHP = path.join(ROOT, "shipping-loss.php");
const DUP_PHP = path.join(ROOT, "duplicate-ship-check.php");
const SRC_CANDIDATES = [
  path.join(__dirname, "..", "lingzanzan-pages", "duplicate-ship-check.php"),
  path.join(__dirname, "duplicate-ship-check.php"),
  DUP_PHP,
];
const SRC_PHP = SRC_CANDIDATES.find(function (file) { return fs.existsSync(file); });
const STAMP = "20260822-dup-ack-1";
const JS_MARKER = "data-similar-ship-ack";
const API_MARKER = "ack-duplicate-check";

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

function stampHtml(dir) {
  const names = [
    "admin-orders.html",
    "admin-live.html",
    "admin-preorders.html",
    "admin-freight.html",
    "admin-reserved-shipping.html",
    "admin-order-tracking.html",
    "admin-preorder-waiting.html",
    "admin-preorder-demand.html",
    "admin.html",
  ];
  let n = 0;
  names.forEach(function (name) {
    const file = path.join(dir, name);
    if (!fs.existsSync(file)) return;
    const html = fs.readFileSync(file, "latin1");
    if (html.indexOf("admin.js") === -1) return;
    const next = html.replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP);
    if (next === html) return;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n += 1;
    console.log("stamped", name);
  });
  const finHtml = path.join(dir, "admin-finance.html");
  if (fs.existsSync(finHtml)) {
    let html = fs.readFileSync(finHtml, "utf8");
    const next = html.replace(/finance\.js(?:\?v=[^"']+)?/g, "finance.js?v=" + STAMP);
    if (next !== html) {
      fs.writeFileSync(finHtml, next, "utf8");
      n += 1;
      console.log("stamped admin-finance.html");
    }
  }
  console.log("html stamped", n);
}

const KIND_OLD = `  function freightFifoSimilarShipKindLabel(kind) {
    return {
      shipped: '已出過／配送中或已取件',
      returned: '出過但已退回',
      open: '還沒出，同一商品還有一張',
      closed: '已結束'
    }[kind] || '請比對';
  }`;

const KIND_NEW = `  function freightFifoSimilarShipKindLabel(kind) {
    return {
      shipped: '已出貨／配送中或已取件',
      returned: '出過但已退回',
      open: '未出貨，同一商品還有一張',
      closed: '已結束'
    }[kind] || '請比對';
  }`;

const DIALOG_OLD = `  function freightFifoShowSimilarShipDialog(id, matches, onContinue) {
    freightFifoEnsureSimilarShipStyle();
    var existing = document.querySelector('[data-freight-similar-ship-dialog]');
    if (existing) existing.remove();
    var source = freightFifoSimilarSourceRow(id) || {};
    var customer = source.customer || {};
    var dialog = document.createElement('div');
    dialog.className = 'freight-similar-ship-dialog';
    dialog.setAttribute('data-freight-similar-ship-dialog', '');
    dialog.innerHTML = '<section class="freight-similar-ship-card">'
      + '<h3>一個月內有同樣出貨紀錄，請先比對</h3>'
      + '<p>客人 ' + escapeHtml(customer.name || source.customerName || source.name || '-') + '／' + escapeHtml(customer.phone || source.customerPhone || source.phone || '-') + '。下面是 31 天內同一商品的單，先看有沒有出過再決定要不要出。</p>'
      + matches.map(function (row) {
        return '<article class="freight-similar-ship-item is-' + escapeHtml(row.kind) + '"><b>' + escapeHtml(freightFifoSimilarShipKindLabel(row.kind)) + '｜' + escapeHtml(row.dateKey || '-') + '</b>'
          + '<span>' + escapeHtml(row.id) + (row.tracking ? '／物流 ' + row.tracking : '／還沒有物流單號') + (row.carrier ? '／' + row.carrier : '') + '</span>'
          + '<small>' + escapeHtml(row.overlap.map(freightFifoSimilarItemLine).join('；')) + '</small></article>';
      }).join('')
      + '<div class="freight-similar-ship-actions"><button type="button" class="is-stop" data-similar-ship-stop>先不要出，我去核對</button><button type="button" class="is-go" data-similar-ship-go>確認沒重複，繼續出貨</button></div>'
      + '</section>';
    function close() { if (dialog.parentNode) dialog.parentNode.removeChild(dialog); }
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog || event.target.closest('[data-similar-ship-stop]')) {
        close();
        return;
      }
      if (event.target.closest('[data-similar-ship-go]')) {
        close();
        if (typeof onContinue === 'function') onContinue();
      }
    });
    document.body.appendChild(dialog);
  }
  function freightFifoGuardSimilarShip(id, trigger, onContinue) {
    if (trigger && trigger.getAttribute && trigger.getAttribute('data-similar-ship-ok') === '1') return false;
    var matches = freightFifoSimilarShipRecords(id);
    if (!matches.length) return false;
    freightFifoShowSimilarShipDialog(id, matches, function () {
      if (trigger && trigger.setAttribute) trigger.setAttribute('data-similar-ship-ok', '1');
      if (typeof onContinue === 'function') onContinue();
    });
    return true;
  }`;

const DIALOG_NEW = `  function freightFifoDuplicatePenaltyHint() {
    return '行政出貨人員必須先按「確定」核對。未核對者，若之後系統查詢確認疏忽，會記 1 點並扣款 NT$100（一次）。';
  }
  function freightFifoRecordDuplicateAck(id, matches, trigger) {
    var source = freightFifoSimilarSourceRow(id) || {};
    var login = (typeof currentLogin === 'function' ? currentLogin() : {}) || {};
    if (trigger && trigger.setAttribute) {
      trigger.setAttribute('data-similar-ship-ok', '1');
      trigger.setAttribute('data-duplicate-check-ack', '1');
    }
    var payload = {
      action: 'ack-duplicate-check',
      orderId: String(source.id || (typeof id === 'string' ? id : '') || ''),
      customerName: (source.customer && source.customer.name) || source.customerName || source.name || '',
      customerPhone: (source.customer && source.customer.phone) || source.customerPhone || source.phone || '',
      matchIds: (matches || []).map(function (row) { return String(row.id || ''); }).filter(Boolean),
      matchKinds: (matches || []).map(function (row) { return String(row.kind || ''); }),
      ackedBy: login.name || login.account || '行政出貨人員'
    };
    if (typeof callOrderAdmin === 'function') {
      return callOrderAdmin(payload).catch(function (error) {
        if (typeof toast === 'function') toast((error && error.message) || '核對紀錄寫入失敗，請再按一次確定');
        return { ok: false };
      });
    }
    return Promise.resolve({ ok: true, localOnly: true });
  }
  function freightFifoShowSimilarShipDialog(id, matches, onContinue) {
    freightFifoEnsureSimilarShipStyle();
    var existing = document.querySelector('[data-freight-similar-ship-dialog]');
    if (existing) existing.remove();
    var source = freightFifoSimilarSourceRow(id) || {};
    var customer = source.customer || {};
    var dialog = document.createElement('div');
    dialog.className = 'freight-similar-ship-dialog';
    dialog.setAttribute('data-freight-similar-ship-dialog', '');
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.innerHTML = '<section class="freight-similar-ship-card">'
      + '<h3>發現重複相同訂單，請先核對</h3>'
      + '<p>客人 ' + escapeHtml(customer.name || source.customerName || source.name || '-') + '／' + escapeHtml(customer.phone || source.customerPhone || source.phone || '-') + '。系統查出同一客戶 31 天內有已出貨或未出貨的相同商品訂單。</p>'
      + '<p class="freight-similar-ship-penalty">' + escapeHtml(freightFifoDuplicatePenaltyHint()) + '</p>'
      + matches.map(function (row) {
        return '<article class="freight-similar-ship-item is-' + escapeHtml(row.kind) + '"><b>' + escapeHtml(freightFifoSimilarShipKindLabel(row.kind)) + '｜' + escapeHtml(row.dateKey || '-') + '</b>'
          + '<span>' + escapeHtml(row.id) + (row.tracking ? '／物流 ' + row.tracking : '／還沒有物流單號') + (row.carrier ? '／' + row.carrier : '') + '</span>'
          + '<small>' + escapeHtml((row.overlap || []).map(freightFifoSimilarItemLine).join('；')) + '</small></article>';
      }).join('')
      + '<div class="freight-similar-ship-actions"><button type="button" class="is-stop" data-similar-ship-stop>先不要出</button><button type="button" class="is-go" data-similar-ship-ack>確定（已核對）</button></div>'
      + '</section>';
    function close() { if (dialog.parentNode) dialog.parentNode.removeChild(dialog); }
    dialog.addEventListener('click', function (event) {
      if (event.target.closest('[data-similar-ship-stop]')) {
        close();
        return;
      }
      var ackButton = event.target.closest('[data-similar-ship-ack]');
      if (!ackButton) return;
      if (ackButton.disabled) return;
      ackButton.disabled = true;
      ackButton.textContent = '核對中…';
      freightFifoRecordDuplicateAck(id, matches, null).then(function (data) {
        if (data && data.ok === false) {
          ackButton.disabled = false;
          ackButton.textContent = '確定（已核對）';
          return;
        }
        close();
        if (typeof onContinue === 'function') onContinue();
      });
    });
    document.body.appendChild(dialog);
  }
  function freightFifoGuardSimilarShip(id, trigger, onContinue) {
    if (trigger && trigger.getAttribute && (trigger.getAttribute('data-similar-ship-ok') === '1' || trigger.getAttribute('data-duplicate-check-ack') === '1')) return false;
    var matches = freightFifoSimilarShipRecords(id);
    if (!matches.length) return false;
    freightFifoShowSimilarShipDialog(id, matches, function () {
      if (trigger && trigger.setAttribute) {
        trigger.setAttribute('data-similar-ship-ok', '1');
        trigger.setAttribute('data-duplicate-check-ack', '1');
      }
      if (typeof onContinue === 'function') onContinue();
    });
    return true;
  }`;

const STYLE_OLD = `      + '.freight-similar-ship-item.is-shipped,.freight-similar-ship-item.is-returned{border-color:#ffadb0}'
      + '.freight-similar-ship-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px}'`;

const STYLE_NEW = `      + '.freight-similar-ship-item.is-shipped,.freight-similar-ship-item.is-returned{border-color:#ffadb0}'
      + '.freight-similar-ship-item.is-open{border-color:#f0bd54}'
      + '.freight-similar-ship-penalty{color:#ffd36a;font-size:15px}'
      + '.freight-similar-ship-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px}'`;

const WAIT_OLD = `data-similar-ship-ok="1" data-freight-fifo-wait-notify-open="`;
const WAIT_NEW = `data-freight-fifo-wait-notify-open="`;

const ACK_OLD = `      if (acknowledgeShipment) {
        var acknowledgeId = String(acknowledgeShipment.getAttribute('data-admin-acknowledge-shipment') || '');
        var acknowledgeIndex = state.orders.findIndex(function (row) { return String(row.id || '') === acknowledgeId; });
        if (acknowledgeIndex < 0) return;
        var adminLogin = currentLogin();
        acknowledgeShipment.disabled = true;`;

const ACK_NEW = `      if (acknowledgeShipment) {
        var acknowledgeId = String(acknowledgeShipment.getAttribute('data-admin-acknowledge-shipment') || '');
        var acknowledgeIndex = state.orders.findIndex(function (row) { return String(row.id || '') === acknowledgeId; });
        if (acknowledgeIndex < 0) return;
        if (freightFifoGuardSimilarShip(acknowledgeId, acknowledgeShipment, function () {
          acknowledgeShipment.click();
        })) return;
        var adminLogin = currentLogin();
        acknowledgeShipment.disabled = true;`;

const BANNER_OLD = `    return {
      amount: amount,
      reason: String(error.reason || error.note || ''),
      orderId: String(error.orderId || row.id || ''),
      financeLedgerId: String(error.financeLedgerId || ''),
      opLabel: opLabel,
      id: String(error.id || '')
    };
  }`;

const BANNER_NEW = `    var scorePoints = Math.max(0, Number(error.scorePoints || 0));
    return {
      amount: amount,
      reason: String(error.reason || error.note || ''),
      orderId: String(error.orderId || row.id || ''),
      financeLedgerId: String(error.financeLedgerId || ''),
      opLabel: opLabel,
      scorePoints: scorePoints,
      penaltyKind: String(error.penaltyKind || ''),
      id: String(error.id || '')
    };
  }`;

const BANNER_HTML_OLD = `    return '<aside class="staff-error-banner"><b>人工出錯 NT$' + Number(error.amount).toLocaleString('zh-TW') + '（尚未完成乘除更正）</b><span>原因：' + escapeHtml(error.reason || '未填原因') + '。' + escapeHtml(error.opLabel) + '。這筆只先記錄，不會自動改成本。</span><span class="company-owes-customer-links">' + (error.orderId ? '<a class="company-owes-customer-link" href="' + shipmentHref + '">出錯出貨單 ' + escapeHtml(error.orderId) + '</a>' : '') + '<a class="company-owes-customer-link" href="' + financeHref + '">' + ledgerBit + '</a></span></aside>';`;

const BANNER_HTML_NEW = `    var scoreBit = error.scorePoints > 0 || error.penaltyKind === 'duplicate_unacked' ? '記 ' + Math.max(1, error.scorePoints || 1) + ' 點・' : '';
    return '<aside class="staff-error-banner"><b>' + scoreBit + '人工出錯／扣款 NT$' + Number(error.amount).toLocaleString('zh-TW') + '（尚未完成乘除更正）</b><span>原因：' + escapeHtml(error.reason || '未填原因') + '。' + escapeHtml(error.opLabel) + '。這筆只先記錄，不會自動改成本。</span><span class="company-owes-customer-links">' + (error.orderId ? '<a class="company-owes-customer-link" href="' + shipmentHref + '">出錯出貨單 ' + escapeHtml(error.orderId) + '</a>' : '') + '<a class="company-owes-customer-link" href="' + financeHref + '">' + ledgerBit + '</a></span></aside>';`;

const FIN_CARD_OLD = `          '<span class="finance-hit-tag">人工出錯</span>'`;
const FIN_CARD_NEW = `          '<span class="finance-hit-tag">' + (Number(row.scorePoints || 0) > 0 || row.penaltyKind === 'duplicate_unacked' ? '記' + Math.max(1, Number(row.scorePoints || 1)) + '點・扣款' : '人工出錯') + '</span>'`;

const API_REQUIRE_OLD = `require_once __DIR__ . DIRECTORY_SEPARATOR . 'shipping-loss.php';`;
const API_REQUIRE_NEW = `require_once __DIR__ . DIRECTORY_SEPARATOR . 'shipping-loss.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'duplicate-ship-check.php';`;

const API_ACTION_OLD = `$action = (string)($payload['action'] ?? '');
$orderId = (string)($payload['orderId'] ?? '');

if ($action === 'list') respond($orders);`;

const API_ACTION_NEW = `$action = (string)($payload['action'] ?? '');
$orderId = (string)($payload['orderId'] ?? '');

if ($action === 'ack-duplicate-check') {
    $result = dup_ship_handle_ack($payload, $orders, read_json($inquiriesFile));
    respond($result, !empty($result['ok']) ? 200 : 500);
}
if ($action === 'audit-duplicate-neglect') {
    $result = dup_ship_handle_audit($payload, $orders, read_json($inquiriesFile));
    if (!empty($result['penalized'])) {
        write_json($ordersFile, $orders);
        sync_state_orders($stateFile, $orders);
    }
    respond($result);
}

if ($action === 'list') respond($orders);`;

const API_SHIP_OLD = `    write_json($ordersFile, $orders);
    sync_state_orders($stateFile, $orders);
    $response = [
        'ok' => true,
        'order' => $orders[$index],
        'shipmentMergeGroupId' => $shipmentMergeGroupId,
    ];
    if (!empty($payload['compactResponse'])) {`;

const API_SHIP_NEW = `    write_json($ordersFile, $orders);
    sync_state_orders($stateFile, $orders);
    $duplicateNeglectPenalty = null;
    if (!empty($advancesShipping)) {
        $shipStaff = trim((string)($payload['operator'] ?? ($payload['adminName'] ?? ($payload['updatedBy'] ?? '行政出貨人員')))) ?: '行政出貨人員';
        $duplicateNeglectPenalty = dup_ship_audit_order($orders, read_json($inquiriesFile), $index, $shipStaff);
        if ($duplicateNeglectPenalty) {
            write_json($ordersFile, $orders);
            sync_state_orders($stateFile, $orders);
        }
    }
    $response = [
        'ok' => true,
        'order' => $orders[$index],
        'shipmentMergeGroupId' => $shipmentMergeGroupId,
    ];
    if ($duplicateNeglectPenalty) $response['duplicateNeglectPenalty'] = $duplicateNeglectPenalty;
    if (!empty($payload['compactResponse'])) {`;

const LOSS_OLD = `        'staff_goods_error' => ['label' => '小姐出錯貨物', 'party' => 'staff', 'score' => true],
        'other' => ['label' => '其他', 'party' => 'customer', 'score' => false],`;

const LOSS_NEW = `        'staff_goods_error' => ['label' => '小姐出錯貨物', 'party' => 'staff', 'score' => true],
        'duplicate_unacked' => ['label' => '未核對重複相同訂單', 'party' => 'staff', 'score' => true],
        'other' => ['label' => '其他', 'party' => 'customer', 'score' => false],`;

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

if (!SRC_PHP) {
  throw new Error("missing source php duplicate-ship-check.php");
}
if (path.resolve(SRC_PHP) !== path.resolve(DUP_PHP)) {
  fs.copyFileSync(SRC_PHP, DUP_PHP);
  console.log("copied php", DUP_PHP);
} else {
  console.log("php already at", DUP_PHP);
}

console.log("backup js", backup(ADMIN_JS, "dup-ack"));
let js = fs.readFileSync(ADMIN_JS, "utf8");
if (js.indexOf(JS_MARKER) !== -1 && js.indexOf("freightFifoDuplicatePenaltyHint") !== -1) {
  console.log("js dialog already patched");
} else {
  js = replaceOnce(js, KIND_OLD, KIND_NEW, "kind labels 已出貨/未出貨");
  js = replaceOnce(js, STYLE_OLD, STYLE_NEW, "dialog open/penalty styles");
  js = replaceOnce(js, DIALOG_OLD, DIALOG_NEW, "mandatory 確定 dialog");
}
if (js.indexOf(WAIT_OLD) !== -1) {
  js = replaceOnce(js, WAIT_OLD, WAIT_NEW, "wait-notify cannot skip 確定");
} else {
  console.log("already: wait-notify skip removed");
}
if (js.indexOf("freightFifoGuardSimilarShip(acknowledgeId, acknowledgeShipment") !== -1) {
  console.log("already: acknowledge shipment guard");
} else {
  js = replaceOnce(js, ACK_OLD, ACK_NEW, "guard 確認接手出貨");
}
if (js.indexOf("penaltyKind: String(error.penaltyKind || '')") !== -1) {
  console.log("already: staff error score fields");
} else {
  js = replaceOnce(js, BANNER_OLD, BANNER_NEW, "staff error scorePoints");
}
if (js.indexOf("記 ' + Math.max(1, error.scorePoints || 1) + ' 點") !== -1) {
  console.log("already: staff error banner copy");
} else if (js.indexOf(BANNER_HTML_OLD) !== -1 || js.indexOf(BANNER_HTML_OLD.replace(/\n/g, "\r\n")) !== -1) {
  js = replaceOnce(js, BANNER_HTML_OLD, BANNER_HTML_NEW, "staff error banner 扣款");
} else {
  console.log("skip: staff error banner html (encoding mismatch, dialog still required)");
}
if (js.indexOf(JS_MARKER) === -1) throw new Error("ack button missing after patch");
if (js.indexOf("發現重複相同訂單，請先核對") === -1) throw new Error("dialog title missing");
fs.writeFileSync(ADMIN_JS, js, "utf8");
console.log("js written", ADMIN_JS, "len", js.length);

if (fs.existsSync(FIN_JS)) {
  console.log("backup finance", backup(FIN_JS, "dup-ack"));
  let fin = fs.readFileSync(FIN_JS, "utf8");
  if (fin.indexOf("duplicate_unacked") !== -1) {
    console.log("finance card already patched");
  } else {
    fin = replaceOnce(fin, FIN_CARD_OLD, FIN_CARD_NEW, "finance staff-error tag");
    fs.writeFileSync(FIN_JS, fin, "utf8");
  }
}

if (fs.existsSync(LOSS_PHP)) {
  console.log("backup loss", backup(LOSS_PHP, "dup-ack"));
  let loss = fs.readFileSync(LOSS_PHP, "utf8");
  if (loss.indexOf("'duplicate_unacked'") !== -1) {
    console.log("shipping-loss reason already patched");
  } else {
    loss = replaceOnce(loss, LOSS_OLD, LOSS_NEW, "shipping-loss duplicate reason");
    fs.writeFileSync(LOSS_PHP, loss, "utf8");
  }
}

if (fs.existsSync(API_PHP)) {
  console.log("backup api", backup(API_PHP, "dup-ack"));
  let api = fs.readFileSync(API_PHP, "utf8");
  if (api.indexOf(API_MARKER) !== -1 && api.indexOf("dup_ship_audit_order") !== -1) {
    console.log("api already patched");
  } else {
    api = replaceOnce(api, API_REQUIRE_OLD, API_REQUIRE_NEW, "require duplicate-ship-check.php");
    api = replaceOnce(api, API_ACTION_OLD, API_ACTION_NEW, "ack/audit actions");
    api = replaceOnce(api, API_SHIP_OLD, API_SHIP_NEW, "audit on ship");
    fs.writeFileSync(API_PHP, api, "utf8");
  }
  if (api.indexOf(API_MARKER) === -1) throw new Error("api ack action missing");
}

stampHtml(ROOT);
console.log("LINGZANZAN duplicate-ship ack ok", STAMP);
