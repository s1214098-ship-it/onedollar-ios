<?php
declare(strict_types=1);
require __DIR__ . '/accounting-lib.php';
$auth = accounting_current_user();
$embed = isset($_GET['embed']);
if (!$auth['ok']) {
    if ($embed || (($_SERVER['HTTP_ACCEPT'] ?? '') && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
        accounting_json(['ok' => false, 'error' => '尚未登入'], 401);
        exit;
    }
    echo '<!doctype html><html lang="zh-TW"><head><meta charset="utf-8"><title>尚未登入</title></head><body><p>請先登入寶輝後台。</p><p><a href="admin.php">回後台</a></p></body></html>';
    exit;
}
$rules = accounting_rules();
?>
<!doctype html>
<html lang="zh-TW">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>電子發票記帳</title>
  <style>
    :root { --deep:#10233f; --blue:#1f5fae; --line:#dbe3ef; --muted:#657286; }
    * { box-sizing:border-box; }
    body { margin:0; font-family:"Noto Sans TC","Microsoft JhengHei",sans-serif; background:#f5f7fa; color:#172033; }
    .bar { background:linear-gradient(90deg,#0f172a,#1e3a8a); color:#fff; padding:14px 18px; display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center; }
    .wrap { padding:16px; }
    .row { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
    button, .btn { border:0; border-radius:8px; padding:9px 12px; font-weight:800; cursor:pointer; text-decoration:none; }
    .primary { background:var(--deep); color:#fff; }
    .good { background:#138a55; color:#fff; }
    .warn { background:#b54708; color:#fff; }
    .ghost { background:#fff; border:1px solid #cbd5e1; }
    .card { background:#fff; border:1px solid var(--line); border-radius:10px; padding:14px; margin-bottom:14px; }
    table { width:100%; border-collapse:collapse; font-size:13px; }
    th, td { border-bottom:1px solid #e2e8f0; padding:8px; text-align:left; vertical-align:top; }
    th { background:#f1f5f9; }
    .muted { color:var(--muted); }
    .ok { color:#166534; font-weight:800; }
    .bad { color:#991b1b; font-weight:800; }
    .notice { padding:10px 12px; border-radius:8px; margin-bottom:12px; display:none; }
    .notice.on { display:block; }
    .notice.ok { background:#dcfce7; color:#166534; }
    .notice.err { background:#fee2e2; color:#991b1b; }
  </style>
</head>
<body>
  <div class="bar">
    <div>
      <b>電子發票記帳</b>
      <div class="muted" style="color:#cbd5e1;font-size:12px">捷元對帳單　買方 <?= htmlspecialchars($rules['buyerTaxId']) ?>　從 <?= htmlspecialchars($rules['matchFrom']) ?> 起比進貨單</div>
    </div>
    <div class="row" style="margin:0">
      <button class="ghost" onclick="loadAll()">重新整理</button>
      <button class="primary" onclick="connectGmail()">連接 Gmail</button>
      <button class="good" onclick="syncGmail()">從信箱抓捷元對帳單</button>
      <button class="warn" onclick="runMatch()">重跑進貨比對</button>
    </div>
  </div>
  <div class="wrap">
    <div id="msg" class="notice"></div>
    <div class="card" id="statusBox">讀取狀態中…</div>
    <div class="card">
      <h3 style="margin-top:0">發票</h3>
      <div id="invoiceBox">尚未載入</div>
    </div>
    <div class="card">
      <h3 style="margin-top:0">進貨單據（<?= htmlspecialchars($rules['matchFrom']) ?> 起）</h3>
      <div id="purchaseBox">尚未載入</div>
    </div>
  </div>
<script>
async function api(action, payload) {
  const opts = { credentials: 'same-origin', cache: 'no-store' };
  if (payload) {
    opts.method = 'POST';
    opts.headers = { 'Content-Type': 'application/json' };
    opts.body = JSON.stringify({ action, ...payload });
  }
  const res = await fetch('accounting-api.php?action=' + encodeURIComponent(action), opts);
  const data = await res.json().catch(() => ({}));
  if (!res.ok || data.ok === false) throw new Error(data.error || ('HTTP ' + res.status));
  return data;
}
function show(text, ok) {
  const el = document.getElementById('msg');
  el.className = 'notice on ' + (ok ? 'ok' : 'err');
  el.textContent = text;
}
function badge(status) {
  if (status === 'matched') return '<span class="ok">已對到進貨單</span>';
  if (status === 'ignored') return '<span class="muted">不比對</span>';
  return '<span class="bad">未對到</span>';
}
async function loadAll() {
  try {
    const status = await api('status');
    const paths = Object.entries(status.paths).map(([k,v]) => k + (v ? '✓' : '✗')).join('　');
    document.getElementById('statusBox').innerHTML =
      'Gmail：' + (status.gmailConnected ? ('已連接 ' + status.gmailAccount) : '尚未連接') +
      '<div class="muted">路徑檢查：' + paths + '</div>';
    const list = await api('list');
    const rows = (list.invoices || []).map(inv => `<tr>
      <td>${inv.invoice_date || ''}</td>
      <td>${inv.invoice_no || ''}<div class="muted">${inv.filename || ''}</div></td>
      <td>${inv.seller_name || ''}<div class="muted">${inv.seller_tax_id || ''}</div></td>
      <td>${inv.total || 0}</td>
      <td>${badge(inv.match_status)}<div class="muted">${inv.match_note || ''}</div></td>
      <td>${inv.status || ''}</td>
      <td>
        <button class="ghost" onclick="mark(${inv.id},'printed')">已列印</button>
        <button class="ghost" onclick="mark(${inv.id},'posted')">入帳</button>
      </td>
    </tr>`).join('') || '<tr><td colspan="7" class="muted">還沒有發票。請先連接 Gmail 再抓對帳單。</td></tr>';
    document.getElementById('invoiceBox').innerHTML = `<table><thead><tr><th>日期</th><th>發票</th><th>賣方</th><th>金額</th><th>比對</th><th>狀態</th><th></th></tr></thead><tbody>${rows}</tbody></table>`;
    const purchases = await api('purchases');
    const docs = (purchases.docs || []).map(d => `<tr><td>${d.date}</td><td>${d.document_no}</td><td>${d.supplier || '（進貨入庫單未填廠商）'}</td><td>${d.amount}</td><td>${d.lines}</td></tr>`).join('') || '<tr><td colspan="5" class="muted">沒有 ' + status.matchFrom + ' 以後的進貨單</td></tr>';
    document.getElementById('purchaseBox').innerHTML = `<table><thead><tr><th>日期</th><th>單號</th><th>廠商</th><th>金額</th><th>項次</th></tr></thead><tbody>${docs}</tbody></table>`;
  } catch (e) {
    show(e.message, false);
  }
}
async function connectGmail() {
  const w = window.open('accounting-gmail-oauth.php', 'baohui-gmail', 'width=520,height=720');
  window.addEventListener('message', function onMsg(ev) {
    if (!ev.data || ev.data.type !== 'baohui-gmail-oauth') return;
    window.removeEventListener('message', onMsg);
    if (w) w.close();
    show(ev.data.ok ? 'Gmail 已連接' : 'Gmail 未連接', !!ev.data.ok);
    loadAll();
  });
}
async function syncGmail() {
  try {
    show('正在讀 Gmail…', true);
    const res = await api('sync_gmail');
    show('抓到 ' + res.imported + ' 張，略過 ' + res.skipped + ' 封', true);
    loadAll();
  } catch (e) { show(e.message, false); }
}
async function runMatch() {
  try {
    const res = await api('match');
    show('已重跑 ' + res.updated + ' 筆比對', true);
    loadAll();
  } catch (e) { show(e.message, false); }
}
async function mark(id, kind) {
  try { await api('mark', { id, kind }); loadAll(); }
  catch (e) { show(e.message, false); }
}
loadAll();
</script>
</body>
</html>
