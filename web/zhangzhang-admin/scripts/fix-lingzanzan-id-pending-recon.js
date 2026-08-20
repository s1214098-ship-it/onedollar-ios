#!/usr/bin/env node
"use strict";

/**
 * 印尼收款：業務說「用印尼付款、不要收錢」但客戶還沒給時，
 * 出貨欄先登記對帳；收到錢後行政或業務在清單點「已收款」。
 *
 * Cache-bust: admin.js ?v=20260820-id-pending-1  (freight pages, latin1)
 *             finance.js ?v=20260820-id-pending-1 (admin-finance.html, utf8)
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const FIN_JS = path.join(ROOT, "assets", "finance.js");
const FIN_API = path.join(ROOT, "finance-api.php");
const FIN_HTML = path.join(ROOT, "admin-finance.html");
const STAMP = "20260820-id-pending-1";
const JS_MARKER = "function freightFifoIndonesiaPendingSectionHtml(";
const API_MARKER = "list-indonesia-pending";

function backup(file, tag) {
  const dir = path.join(ROOT, "data", "audit");
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(
    dir,
    path.basename(file) + "." + tag + "-" + new Date().toISOString().replace(/[:.]/g, "-")
  );
  fs.copyFileSync(file, dest);
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

function stampFreight(dir) {
  ["admin-freight.html", "admin-reserved-shipping.html"].forEach((name) => {
    const file = path.join(dir, name);
    if (!fs.existsSync(file)) return;
    const html = fs.readFileSync(file, "latin1");
    if (html.indexOf("admin.js") === -1) return;
    const next = html.replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP);
    if (next === html) return;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    console.log("stamped", name);
  });
}

function stampFinanceHtml() {
  if (!fs.existsSync(FIN_HTML)) return;
  const html = fs.readFileSync(FIN_HTML, "utf8");
  const next = html.replace(/finance\.js(?:\?v=[^"']+)?/g, "finance.js?v=" + STAMP);
  if (next !== html) {
    fs.writeFileSync(FIN_HTML, next, "utf8");
    console.log("stamped admin-finance.html");
  }
}

const MODE_OLD = `  function isIndonesiaSalesCollectionMode(mode) {
    return mode === 'id_sales_full' || mode === 'id_sales_partial';
  }`;

const MODE_NEW = `  function isIndonesiaSalesCollectionMode(mode) {
    return mode === 'id_sales_full' || mode === 'id_sales_partial';
  }
  function isIndonesiaSalesPendingMode(mode) {
    return mode === 'id_sales_pending';
  }
  function isIndonesiaSalesAnyMode(mode) {
    return isIndonesiaSalesCollectionMode(mode) || isIndonesiaSalesPendingMode(mode);
  }`;

const LABEL_OLD = `      id_sales_full: '印尼業務全數代收／Staf Indonesia terima penuh',
      id_sales_partial: '印尼業務部分代收／Staf Indonesia terima sebagian',`;

const LABEL_NEW = `      id_sales_full: '印尼業務全數代收／Staf Indonesia terima penuh',
      id_sales_pending: '印尼收款，客戶還沒給（先登記對帳）／Dicatat dulu, pelanggan belum bayar',
      id_sales_partial: '印尼業務部分代收／Staf Indonesia terima sebagian',`;

const SELECT_OLD = `<option value="id_sales_full">印尼業務全數代收</option><option value="id_sales_partial">印尼業務部分代收</option>`;

const SELECT_NEW = `<option value="id_sales_full">印尼業務全數代收</option><option value="id_sales_pending">印尼收款，客戶還沒給（先登記對帳）</option><option value="id_sales_partial">印尼業務部分代收</option>`;

const VALUES_OLD = `    var idRemainderStage = mode === 'id_sales_full' ? 'none' : (mode === 'id_sales_partial' ? remainder : '');`;

const VALUES_NEW = `    if (mode === 'id_sales_pending') {
      current = 0;
      paid = previousPaid;
      balance = Math.max(0, total - paid);
    }
    var idRemainderStage = mode === 'id_sales_full' ? 'none' : (mode === 'id_sales_pending' ? 'id_later' : (mode === 'id_sales_partial' ? remainder : ''));`;

const RETURN_OLD = `      indonesiaCovered: indonesiaCovered,
      codAmount: taiwanCod ? balance : 0,`;

const RETURN_NEW = `      indonesiaCovered: mode === 'id_sales_pending' ? false : indonesiaCovered,
      indonesiaPending: mode === 'id_sales_pending',
      codAmount: mode === 'id_sales_pending' ? 0 : (taiwanCod ? balance : 0),`;

const STORECOD_OLD = `    var storeIdCod = isIndonesiaSalesCollectionMode(mode) && !indonesiaSelfShip && freightFifoStoreIdCodChecked(box);`;

const STORECOD_NEW = `    var storeIdCod = isIndonesiaSalesCollectionMode(mode) && !isIndonesiaSalesPendingMode(mode) && !indonesiaSelfShip && freightFifoStoreIdCodChecked(box);`;

const SETTLE_SHOW_OLD = `    var isId = isIndonesiaSalesCollectionMode(mode);
    if (section) section.hidden = !isId;`;

const SETTLE_SHOW_NEW = `    var isId = isIndonesiaSalesAnyMode(mode);
    if (section) section.hidden = !isId;`;

const SETTLE_FILL_OLD = `    var currentInput = box.querySelector('[data-freight-fifo-payment-current]');
    if (currentInput && idr.amount > 0) {`;

const SETTLE_FILL_NEW = `    var currentInput = box.querySelector('[data-freight-fifo-payment-current]');
    if (currentInput && idr.amount > 0 && !isIndonesiaSalesPendingMode(mode)) {`;

const SYNC_ANY_OLD = `    if (remainderField) remainderField.hidden = mode !== 'id_sales_partial';
    if (box._lastIndonesiaMode === mode) return;
    box._lastIndonesiaMode = mode;
    if (!isIndonesiaSalesCollectionMode(mode)) return;`;

const SYNC_ANY_NEW = `    if (remainderField) remainderField.hidden = mode !== 'id_sales_partial';
    if (box._lastIndonesiaMode === mode) return;
    box._lastIndonesiaMode = mode;
    if (!isIndonesiaSalesAnyMode(mode)) return;`;

const SAVE_FULL_OLD = `    if (values.mode === 'id_sales_full' && values.balance > 0) return Promise.reject(new Error('印尼全數代收時，本次收款需補足全部尾款'));`;

const SAVE_FULL_NEW = `    if (values.mode === 'id_sales_full' && values.balance > 0) return Promise.reject(new Error('印尼全數代收時，本次收款需補足全部尾款'));
    if (values.mode === 'id_sales_pending' && values.balance <= 0) return Promise.reject(new Error('這張已沒有尾款，請改選「印尼業務全數代收」'));`;

const SAVE_COLLECTOR_OLD = `    if (isIndonesiaSalesCollectionMode(values.mode)) {
      collectionParty = 'sales';
      collectionRegion = 'ID';
      if (!collectorName) return Promise.reject(new Error('印尼代收請填代收業務姓名'));
    }`;

const SAVE_COLLECTOR_NEW = `    if (isIndonesiaSalesAnyMode(values.mode)) {
      collectionParty = 'sales';
      collectionRegion = 'ID';
      if (!collectorName) return Promise.reject(new Error('印尼代收請填代收業務姓名'));
    }`;

const SAVE_JSON_OLD = `        settlementMode: values.mode,
        idRemainderStage: values.idRemainderStage || 'none',`;

const SAVE_JSON_NEW = `        settlementMode: values.mode,
        indonesiaPending: !!values.indonesiaPending,
        indonesiaPendingNote: values.indonesiaPending ? note : '',
        indonesiaPendingBy: values.indonesiaPending ? (collectorName || (currentLogin().name || currentLogin().account || '')) : '',
        idRemainderStage: values.idRemainderStage || 'none',`;

const SAVE_STAGE_OLD = `        paymentStage: values.current > 0 ? (isIndonesiaSalesCollectionMode(values.mode) ? 'balance' : 'deposit') : '',`;

const SAVE_STAGE_NEW = `        paymentStage: values.current > 0 ? (isIndonesiaSalesAnyMode(values.mode) ? 'balance' : 'deposit') : '',`;

const BADGE_OLD = `      if (values.mode === 'id_sales_full' && values.indonesiaSelfShip) badge.textContent = '印尼自出已收清：運費業務自理，台灣不代寄';
      else if (values.mode === 'id_sales_full') badge.textContent = values.storeIdCod`;

const BADGE_NEW = `      if (values.mode === 'id_sales_pending') badge.textContent = '印尼收款已登記，客戶還沒給業務。先出貨不代收，對帳後再點已收款';
      else if (values.mode === 'id_sales_full' && values.indonesiaSelfShip) badge.textContent = '印尼自出已收清：運費業務自理，台灣不代寄';
      else if (values.mode === 'id_sales_full') badge.textContent = values.storeIdCod`;

const LIST_HELPERS = `
  var indonesiaPendingCache = { at: 0, rows: [] };
  function freightFifoEnsureIndonesiaPendingStyle() {
    if (typeof document === 'undefined' || document.getElementById('freight-fifo-id-pending-style')) return;
    var style = document.createElement('style');
    style.id = 'freight-fifo-id-pending-style';
    style.textContent = '.freight-fifo-id-pending-card{display:grid;gap:8px;padding:12px 14px;border:1px solid #f0bd54;border-radius:14px;background:#2a1a16;margin:0 0 10px}'
      + '.freight-fifo-id-pending-card b{color:#fff8ed;font-size:18px}'
      + '.freight-fifo-id-pending-card small,.freight-fifo-id-pending-card p{margin:0;color:#eadde8;font-weight:700}'
      + '.freight-fifo-id-pending-card .is-paid-id{border:0;background:#f0bd54;color:#1a1214;border-radius:12px;padding:10px 14px;font-weight:900;cursor:pointer}';
    if (document.head) document.head.appendChild(style);
  }
  function indonesiaPendingCardsHtml(rows) {
    rows = Array.isArray(rows) ? rows : [];
    if (!rows.length) return '<p class="freight-fifo-empty">目前沒有「印尼收款、客戶還沒給」的對帳單。</p>';
    return rows.map(function (row) {
      return '<article class="freight-fifo-id-pending-card">'
        + '<div><b>' + escapeHtml(row.customerName || '未填客戶') + '</b><small>' + escapeHtml(row.phone || '未填電話') + '／' + escapeHtml(row.orderId || '-') + '</small></div>'
        + '<p>應收 ' + ('NT$' + Math.round(Number(row.balance || 0)).toLocaleString()) + '　已收 ' + ('NT$' + Math.round(Number(row.paid || 0)).toLocaleString()) + '　業務 ' + escapeHtml(row.salesName || '-') + '</p>'
        + (row.note ? '<p>' + escapeHtml(row.note) + '</p>' : '')
        + '<p>' + escapeHtml(String(row.pendingAt || '').slice(0, 16).replace('T', ' ')) + (row.pendingBy ? '　登記人 ' + escapeHtml(row.pendingBy) : '') + (row.trackingNo ? '　物流 ' + escapeHtml(row.trackingNo) : '') + '</p>'
        + '<div><button type="button" class="is-paid-id" data-indonesia-pending-paid="' + escapeHtml(row.orderId || '') + '">已收款</button>'
        + '<button type="button" class="ghost-button" data-freight-fifo-order="' + escapeHtml(row.orderId || '') + '">開啟出貨單</button></div>'
        + '</article>';
    }).join('');
  }
  function freightFifoIndonesiaPendingSectionHtml() {
    freightFifoEnsureIndonesiaPendingStyle();
    var rows = indonesiaPendingCache.rows || [];
    return '<details class="freight-fifo-stage freight-fifo-collapsible is-id-pending" data-freight-fifo-collapsible="id_pending" data-indonesia-pending-section open>'
      + '<summary class="freight-fifo-section-title"><div><span class="freight-fifo-stage-number">印</span><h4>印尼收款對帳</h4><small>業務說印尼收款、客戶還沒給的單。對到錢再點已收款。</small></div><strong><b data-indonesia-pending-count>' + rows.length + '</b> 張待收</strong><span class="freight-fifo-collapse-label" aria-hidden="true"></span></summary>'
      + '<div class="freight-fifo-ready-list" data-indonesia-pending-list>' + indonesiaPendingCardsHtml(rows) + '</div></details>';
  }
  function refreshIndonesiaPendingSection() {
    return fetch('./finance-api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ action: 'list-indonesia-pending' })
    }).then(function (response) { return response.json(); }).then(function (data) {
      indonesiaPendingCache = { at: Date.now(), rows: (data && data.ok && Array.isArray(data.rows)) ? data.rows : [] };
      var host = document.querySelector('[data-indonesia-pending-list]');
      if (host) host.innerHTML = indonesiaPendingCardsHtml(indonesiaPendingCache.rows);
      var count = document.querySelector('[data-indonesia-pending-count]');
      if (count) count.textContent = String(indonesiaPendingCache.rows.length);
      return indonesiaPendingCache.rows;
    }).catch(function () { return indonesiaPendingCache.rows || []; });
  }
  function markIndonesiaPendingReceived(button) {
    var orderId = String(button && button.getAttribute('data-indonesia-pending-paid') || '').trim();
    if (!orderId) return;
    if (!window.confirm('確認這張印尼收款已經收到了？\\n會記入對帳並從待收清單拿掉。')) return;
    button.disabled = true;
    button.textContent = '寫入中…';
    var who = '';
    try { who = String((currentLogin() || {}).name || (currentLogin() || {}).account || ''); } catch (error) {}
    fetch('./finance-api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        action: 'mark-indonesia-received',
        orderId: orderId,
        receivedBy: who
      })
    }).then(function (response) { return response.json().then(function (data) { return { ok: response.ok, data: data }; }); }).then(function (result) {
      if (!result.ok || !result.data || !result.data.ok) throw new Error((result.data && result.data.error) || '寫入失敗');
      toast(result.data.message || '已標成印尼已收款');
      return refreshIndonesiaPendingSection();
    }).catch(function (error) {
      button.disabled = false;
      button.textContent = '已收款';
      toast(error.message || '標已收款失敗');
    });
  }
  if (!window.__indonesiaPendingPaidBound) {
    window.__indonesiaPendingPaidBound = true;
    document.addEventListener('click', function (event) {
      var button = event.target && event.target.closest ? event.target.closest('[data-indonesia-pending-paid]') : null;
      if (!button) return;
      event.preventDefault();
      event.stopPropagation();
      markIndonesiaPendingReceived(button);
    }, true);
  }

`;

const SECTION_OLD = `    var formalSection = '<details class="freight-fifo-stage freight-fifo-collapsible is-formal"`;

const SECTION_NEW = `    var idPendingSection = freightFifoIndonesiaPendingSectionHtml();
    var formalSection = '<details class="freight-fifo-stage freight-fifo-collapsible is-formal"`;

const HOST_OLD = `+ waitNotifySection + formalSection + transitSection`;
const HOST_NEW = `+ waitNotifySection + idPendingSection + formalSection + transitSection`;

const REFRESH_OLD = `    if (window.LingzanzanMemberRisk && typeof window.LingzanzanMemberRisk.refresh === 'function') {
      window.LingzanzanMemberRisk.refresh();
    }
  }`;

const REFRESH_NEW = `    if (window.LingzanzanMemberRisk && typeof window.LingzanzanMemberRisk.refresh === 'function') {
      window.LingzanzanMemberRisk.refresh();
    }
    refreshIndonesiaPendingSection();
  }`;

const PHP_WHITELIST_OLD = `if (!in_array($action, ['review-order-payment', 'save-order-payment'], true) && $sessionRole !== 'admin') {
    fin_out(['ok' => false, 'error' => '行政專員不能查看財報'], 403);
}`;

const PHP_WHITELIST_NEW = `if (!in_array($action, ['review-order-payment', 'save-order-payment', 'list-indonesia-pending', 'mark-indonesia-received'], true) && $sessionRole !== 'admin') {
    fin_out(['ok' => false, 'error' => '行政專員不能查看財報'], 403);
}`;

const PHP_AFTER_LOAD_OLD = `$payments = fin_read($paymentFile);

if ($action === 'review-order-payment') {`;

const PHP_FIELDS_OLD = `        'indonesiaCovered' => array_key_exists('indonesiaCovered', $data) ? !empty($data['indonesiaCovered']) : !empty($old['indonesiaCovered']),`;

const PHP_FIELDS_NEW = `        'indonesiaCovered' => array_key_exists('indonesiaCovered', $data) ? !empty($data['indonesiaCovered']) : !empty($old['indonesiaCovered']),
        'indonesiaPending' => (fin_text($data['settlementMode'] ?? '', 40) === 'id_sales_pending') || !empty($data['indonesiaPending']),
        'indonesiaPendingAt' => (fin_text($data['settlementMode'] ?? '', 40) === 'id_sales_pending' || !empty($data['indonesiaPending']))
            ? (fin_text($old['indonesiaPendingAt'] ?? '', 40) ?: $now)
            : fin_text($old['indonesiaPendingAt'] ?? '', 40),
        'indonesiaPendingBy' => fin_text($data['indonesiaPendingBy'] ?? ($old['indonesiaPendingBy'] ?? ''), 100),
        'indonesiaPendingNote' => fin_text($data['indonesiaPendingNote'] ?? ($old['indonesiaPendingNote'] ?? ($data['note'] ?? '')), 500),
        'indonesiaReceivedAt' => fin_text($old['indonesiaReceivedAt'] ?? '', 40),
        'indonesiaReceivedBy' => fin_text($old['indonesiaReceivedBy'] ?? '', 100),`;

const PHP_ACTIONS = `
if ($action === 'list-indonesia-pending') {
    $orderFile = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'orders.json';
    $orders = fin_read($orderFile);
    if (!is_array($orders)) $orders = [];
    $byId = [];
    foreach ($orders as $order) {
        if (!is_array($order)) continue;
        $oid = fin_text($order['id'] ?? '', 120);
        if ($oid !== '') $byId[$oid] = $order;
    }
    $rows = [];
    foreach ($payments as $id => $pay) {
        if (!is_array($pay)) continue;
        $mode = fin_text($pay['settlementMode'] ?? '', 40);
        $pending = !empty($pay['indonesiaPending']) || $mode === 'id_sales_pending';
        if (!$pending) continue;
        $total = max(0, (float)($pay['orderTotal'] ?? 0));
        $paid = max(0, (float)($pay['paidAmount'] ?? 0));
        $balance = max(0, (float)($pay['receivableBalance'] ?? ($total - $paid)));
        if ($balance <= 0) continue;
        $order = $byId[$id] ?? [];
        $customer = isset($order['customer']) && is_array($order['customer']) ? $order['customer'] : [];
        $rows[] = [
            'orderId' => (string)$id,
            'customerName' => fin_text($pay['customerName'] ?? ($customer['name'] ?? ($order['customerName'] ?? '')), 120),
            'phone' => fin_text($pay['customerPhone'] ?? ($customer['phone'] ?? ($order['customerPhone'] ?? '')), 40),
            'salesName' => fin_text($pay['salesName'] ?? ($pay['collectorName'] ?? ($order['salesName'] ?? '')), 100),
            'total' => $total,
            'paid' => $paid,
            'balance' => $balance,
            'note' => fin_text($pay['indonesiaPendingNote'] ?? ($pay['note'] ?? ''), 500),
            'pendingAt' => fin_text($pay['indonesiaPendingAt'] ?? ($pay['updatedAt'] ?? ''), 40),
            'pendingBy' => fin_text($pay['indonesiaPendingBy'] ?? '', 100),
            'trackingNo' => fin_text($order['trackingNo'] ?? ($order['shippingTrackingNo'] ?? ''), 80),
        ];
    }
    usort($rows, static function(array $a, array $b): int {
        return strcmp((string)($b['pendingAt'] ?? ''), (string)($a['pendingAt'] ?? ''));
    });
    fin_out(['ok' => true, 'rows' => $rows, 'count' => count($rows)]);
}

if ($action === 'mark-indonesia-received') {
    $id = fin_text($data['orderId'] ?? '', 120);
    if ($id === '') fin_out(['ok' => false, 'error' => '缺少訂單編號'], 400);
    if (!isset($payments[$id]) || !is_array($payments[$id])) fin_out(['ok' => false, 'error' => '找不到這張印尼對帳單'], 404);
    $payment = $payments[$id];
    $total = max(0, (float)($payment['orderTotal'] ?? 0));
    $paid = max(0, (float)($payment['paidAmount'] ?? 0));
    $remain = max(0, $total - $paid);
    $now = date(DATE_ATOM);
    $who = fin_text($data['receivedBy'] ?? ($session['name'] ?? ($session['account'] ?? '')), 100);
    if ($who === '') $who = $sessionRole === 'admin' ? '管理者' : ($sessionRole === 'sales' ? '業務' : '行政人員');
    $history = isset($payment['history']) && is_array($payment['history']) ? $payment['history'] : [];
    if ($remain > 0) {
        $history[] = [
            'amount' => $remain,
            'date' => substr($now, 0, 10),
            'note' => '印尼收款對帳：已收款',
            'recordedBy' => $who,
            'collectionParty' => 'sales',
            'collectionRegion' => 'ID',
            'paymentStage' => 'balance',
            'settlementMode' => 'id_sales_full',
            'collectorName' => fin_text($payment['collectorName'] ?? ($payment['salesName'] ?? $who), 100),
            'salesName' => fin_text($payment['salesName'] ?? $who, 100),
            'recordedAt' => $now
        ];
    }
    $payment['history'] = $history;
    $payment['paidAmount'] = $total;
    $payment['receivableBalance'] = 0;
    $payment['settlementMode'] = 'id_sales_full';
    $payment['indonesiaPending'] = false;
    $payment['indonesiaCovered'] = true;
    $payment['indonesiaReceivedAt'] = $now;
    $payment['indonesiaReceivedBy'] = $who;
    $payment['collectionParty'] = 'sales';
    $payment['collectionRegion'] = 'ID';
    $payment['updatedAt'] = $now;
    $payment['note'] = trim(fin_text($payment['note'] ?? '', 500) . ' ／對帳已收款 ' . $who);
    if ($sessionRole === 'admin') {
        $payment['reviewStatus'] = 'approved';
        $payment['reviewedAt'] = $now;
        $payment['reviewedBy'] = $who;
        $payment['reviewerRole'] = 'admin';
        $payment['reviewNote'] = '印尼收款對帳已收款';
    } else {
        $payment['reviewStatus'] = 'pending';
        $payment['reviewSubmittedAt'] = $now;
        $payment['reviewSubmittedBy'] = $who;
    }
    $payments[$id] = $payment;
    if (!fin_write($paymentFile, $payments)) fin_out(['ok' => false, 'error' => 'NAS 寫入失敗'], 500);
    fin_out(['ok' => true, 'payment' => $payment, 'message' => $sessionRole === 'admin' ? '已標成印尼已收款' : '已登記已收款，等管理者核對']);
}

`;

const FIN_LABEL_OLD = `      id: '印尼收款未收',
      deposit: '已收訂金、尾款未收',`;

const FIN_LABEL_NEW = `      id: '印尼收款未收',
      id_pending: '印尼已登記還沒給',
      deposit: '已收訂金、尾款未收',`;

const FIN_ISID_OLD = `    var isId = mode === 'id_sales_full' || mode === 'id_sales_partial' || remainder === 'id_later' || region === 'ID';`;

const FIN_ISID_NEW = `    var isId = mode === 'id_sales_full' || mode === 'id_sales_partial' || mode === 'id_sales_pending' || remainder === 'id_later' || region === 'ID' || payment.indonesiaPending;`;

const FIN_FLAGS_OLD = `        id: balance > 0 && isId && remainder !== 'tw_cod' && remainder !== 'tw_store_id_cod',
        deposit: deposit > 0 && paid > 0 && balance > 0,`;

const FIN_FLAGS_NEW = `        id: balance > 0 && isId && remainder !== 'tw_cod' && remainder !== 'tw_store_id_cod',
        idPending: balance > 0 && (mode === 'id_sales_pending' || !!payment.indonesiaPending),
        deposit: deposit > 0 && paid > 0 && balance > 0,`;

const FIN_PRIMARY_OLD = `    if (info.flags.signed) return 'signed';
    if (info.flags.deposit) return 'deposit';
    if (info.flags.id) return 'id';`;

const FIN_PRIMARY_NEW = `    if (info.flags.signed) return 'signed';
    if (info.flags.idPending) return 'id_pending';
    if (info.flags.deposit) return 'deposit';
    if (info.flags.id) return 'id';`;

const FIN_MATCH_OLD = `    if (kind === 'id') return info.flags.id;
    if (kind === 'deposit') return info.flags.deposit;`;

const FIN_MATCH_NEW = `    if (kind === 'id') return info.flags.id;
    if (kind === 'id_pending') return info.flags.idPending;
    if (kind === 'deposit') return info.flags.deposit;`;

const FIN_CHIPS_OLD = `      { id: 'id', danger: false, amount: sumFlag(classified, 'id'), count: countFlag(classified, 'id') },
      { id: 'deposit', danger: false, amount: sumFlag(classified, 'deposit'), count: countFlag(classified, 'deposit') },`;

const FIN_CHIPS_NEW = `      { id: 'id', danger: false, amount: sumFlag(classified, 'id'), count: countFlag(classified, 'id') },
      { id: 'id_pending', danger: true, amount: sumFlag(classified, 'idPending'), count: countFlag(classified, 'idPending') },
      { id: 'deposit', danger: false, amount: sumFlag(classified, 'deposit'), count: countFlag(classified, 'deposit') },`;

const FIN_BTN_OLD = `      '<strong>' + label + '</strong>' + (extra > 0 && balance <= 0 ? '' : '<div class="finance-settle">`;

const FIN_BTN_NEW = `      '<strong>' + label + '</strong>' + (info.flags.idPending ? '<button type="button" class="finance-customer-toggle" data-indonesia-pending-paid="' + escapeHtml(id) + '">已收款</button>' : '') + (extra > 0 && balance <= 0 ? '' : '<div class="finance-settle">`;

const FIN_CLICK_OLD = `    if (event.target && event.target.matches && event.target.matches('[data-fin-chase-sales]')) {`;

const FIN_CLICK_NEW = `    var idPaid = event.target && event.target.closest && event.target.closest('[data-indonesia-pending-paid]');
    if (idPaid) {
      event.preventDefault();
      var pendingId = String(idPaid.getAttribute('data-indonesia-pending-paid') || '').trim();
      if (!pendingId) return;
      if (!window.confirm('確認這張印尼收款已經收到了？')) return;
      idPaid.disabled = true;
      fetch('./finance-api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          action: 'mark-indonesia-received',
          orderId: pendingId,
          receivedBy: login().name || login().account || ''
        })
      }).then(function (response) { return response.json(); }).then(function (data) {
        if (!data || !data.ok) throw new Error((data && data.error) || '寫入失敗');
        window.location.reload();
      }).catch(function (error) {
        idPaid.disabled = false;
        alert(error.message || '標已收款失敗');
      });
      return;
    }
    if (event.target && event.target.matches && event.target.matches('[data-fin-chase-sales]')) {`;

if (!fs.existsSync(ADMIN_JS) || !fs.existsSync(FIN_API)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(ADMIN_JS, "id-pending"));
console.log("backup finance js", backup(FIN_JS, "id-pending"));
console.log("backup finance api", backup(FIN_API, "id-pending"));

let js = fs.readFileSync(ADMIN_JS, "utf8");
if (js.indexOf(JS_MARKER) === -1) {
  js = replaceOnce(js, MODE_OLD, MODE_NEW, "pending mode helpers");
  js = replaceOnce(js, LABEL_OLD, LABEL_NEW, "mode label");
  js = replaceOnce(js, SELECT_OLD, SELECT_NEW, "mode select option");
  js = replaceOnce(js, VALUES_OLD, VALUES_NEW, "pending zeros current paid");
  js = replaceOnce(js, STORECOD_OLD, STORECOD_NEW, "pending no store COD");
  js = replaceOnce(js, RETURN_OLD, RETURN_NEW, "pending flags on values");
  js = replaceOnce(js, SETTLE_SHOW_OLD, SETTLE_SHOW_NEW, "show IDR box for pending");
  js = replaceOnce(js, SETTLE_FILL_OLD, SETTLE_FILL_NEW, "do not autofill pending amount");
  js = replaceOnce(js, SYNC_ANY_OLD, SYNC_ANY_NEW, "sync collector for pending");
  js = replaceOnce(js, SAVE_FULL_OLD, SAVE_FULL_NEW, "allow pending with balance");
  js = replaceOnce(js, SAVE_COLLECTOR_OLD, SAVE_COLLECTOR_NEW, "pending requires sales name");
  js = replaceOnce(js, SAVE_STAGE_OLD, SAVE_STAGE_NEW, "pending payment stage");
  js = replaceOnce(js, SAVE_JSON_OLD, SAVE_JSON_NEW, "save pending fields");
  js = replaceOnce(js, BADGE_OLD, BADGE_NEW, "pending badge");
  js = replaceOnce(js, MODE_NEW, MODE_NEW + LIST_HELPERS, "fifo pending list helpers");
  js = replaceOnce(js, SECTION_OLD, SECTION_NEW, "fifo pending section var");
  js = replaceOnce(js, HOST_OLD, HOST_NEW, "fifo pending section host");
  js = replaceOnce(js, REFRESH_OLD, REFRESH_NEW, "refresh pending list after render");
} else {
  console.log("admin.js already patched");
}
if (js.indexOf(JS_MARKER) === -1) throw new Error("admin pending list helper missing");
fs.writeFileSync(ADMIN_JS, js, "utf8");
console.log("admin.js written", js.length);

let api = fs.readFileSync(FIN_API, "utf8");
if (api.indexOf(API_MARKER) === -1) {
  api = replaceOnce(api, PHP_WHITELIST_OLD, PHP_WHITELIST_NEW, "api whitelist");
  api = replaceOnce(api, PHP_FIELDS_OLD, PHP_FIELDS_NEW, "persist pending fields");
  api = replaceOnce(
    api,
    PHP_AFTER_LOAD_OLD,
    `$payments = fin_read($paymentFile);\n\n` + PHP_ACTIONS + `if ($action === 'review-order-payment') {`,
    "list/mark actions"
  );
} else {
  console.log("finance-api already patched");
}
if (api.indexOf(API_MARKER) === -1) throw new Error("list-indonesia-pending missing");
fs.writeFileSync(FIN_API, api, "utf8");
console.log("finance-api written", api.length);

let fin = fs.readFileSync(FIN_JS, "utf8");
if (fin.indexOf("id_pending: '印尼已登記還沒給'") === -1) {
  fin = replaceOnce(fin, FIN_LABEL_OLD, FIN_LABEL_NEW, "finance chase label");
  fin = replaceOnce(fin, FIN_ISID_OLD, FIN_ISID_NEW, "finance isId pending");
  fin = replaceOnce(fin, FIN_FLAGS_OLD, FIN_FLAGS_NEW, "finance idPending flag");
  fin = replaceOnce(fin, FIN_PRIMARY_OLD, FIN_PRIMARY_NEW, "finance primary kind");
  fin = replaceOnce(fin, FIN_MATCH_OLD, FIN_MATCH_NEW, "finance kind match");
  fin = replaceOnce(fin, FIN_CHIPS_OLD, FIN_CHIPS_NEW, "finance chase chip");
  fin = replaceOnce(fin, FIN_BTN_OLD, FIN_BTN_NEW, "finance 已收款 button");
  fin = replaceOnce(fin, FIN_CLICK_OLD, FIN_CLICK_NEW, "finance 已收款 click");
} else {
  console.log("finance.js already patched");
}
if (fin.indexOf("id_pending") === -1) throw new Error("finance id_pending missing");
fs.writeFileSync(FIN_JS, fin, "utf8");
console.log("finance.js written", fin.length);

stampFreight(ROOT);
stampFinanceHtml();
console.log("LINGZANZAN indonesia pending recon ok", STAMP);
