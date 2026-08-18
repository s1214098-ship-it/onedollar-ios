<?php
declare(strict_types=1);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'accounting-lib.php';
$context = acc_require_capability('invoice_view');
acc_db();
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src https://cdn.jsdelivr.net; img-src 'self' data:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
?>
<!doctype html>
<html lang="zh-TW">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>電子發票記帳</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{--ink:#172033;--muted:#64748b;--line:#d9e2ec;--panel:#fff;--blue:#155eef;--green:#16803c;--amber:#b45309;--red:#b42318;--bg:#f5f7fa}
    *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:"Noto Sans TC","Microsoft JhengHei",sans-serif;letter-spacing:0}
    .workspace{max-width:1500px;margin:0 auto;padding:20px}.invoice-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:16px}
    h1{font-size:1.45rem;margin:0 0 6px;font-weight:800}.subtext{color:var(--muted);font-size:.9rem}.head-actions{display:flex;gap:8px;flex-wrap:wrap}
    .summary{display:grid;grid-template-columns:repeat(6,minmax(140px,1fr));gap:10px;margin-bottom:14px}.metric{background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:13px 15px}.metric span{display:block;color:var(--muted);font-size:.8rem}.metric strong{font-size:1.45rem}
    .surface{background:var(--panel);border:1px solid var(--line);border-radius:8px;overflow:hidden}.tabs{display:flex;gap:2px;padding:10px 10px 0;border-bottom:1px solid var(--line);overflow:auto}.tab{white-space:nowrap;border:0;background:transparent;color:#475569;padding:10px 13px;border-bottom:3px solid transparent;font-weight:700}.tab.active{color:var(--blue);border-color:var(--blue)}
    .toolbar{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid var(--line)}.filter-set{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.toolbar input,.toolbar select{min-height:38px;border:1px solid #cbd5e1;border-radius:6px;padding:6px 10px;background:#fff}.toolbar input{min-width:230px}
    .batch-bar{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 14px;background:#f8fafc;border-bottom:1px solid var(--line);flex-wrap:wrap}
    .table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:1320px}th,td{padding:11px 12px;border-bottom:1px solid #e7edf3;text-align:left;vertical-align:middle}th{font-size:.82rem;color:#475569;background:#f8fafc;position:sticky;top:0}td{font-size:.9rem}.money{text-align:right;font-variant-numeric:tabular-nums;font-weight:700}.muted{color:var(--muted)}
    tfoot td{background:#f8fafc;font-weight:800;border-top:1px solid var(--line)}
    .badge-status{display:inline-flex;align-items:center;min-height:26px;padding:3px 8px;border-radius:999px;font-size:.76rem;font-weight:800;background:#eef2ff;color:#3730a3}.status-booked{background:#dcfce7;color:#166534}.status-exception{background:#fee2e2;color:#991b1b}.status-wait{background:#fef3c7;color:#92400e}.status-noncompany{background:#e2e8f0;color:#475569}.print-unprinted{background:#fff1f2;color:#be123c}.print-done{background:#dcfce7;color:#166534}
    .empty{padding:54px 20px;text-align:center;color:var(--muted)}.empty i{display:block;font-size:2rem;margin-bottom:8px;color:#94a3b8}.notice{margin:12px 14px;padding:10px 12px;border-left:4px solid #d97706;background:#fffbeb;color:#78450a;font-size:.86rem}
    .gmail-setup{display:grid;gap:8px;margin-top:10px}.gmail-setup label{display:flex;flex-direction:column;gap:4px;font-size:.8rem;font-weight:700}.gmail-setup input{min-height:38px;border:1px solid #f3d9a8;border-radius:6px;padding:6px 10px;background:#fff}.gmail-setup .row-btns{display:flex;gap:8px;flex-wrap:wrap}
    .vendor-pane,.export-pane,.partner-pane{padding:16px}.partner-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:12px 0}.partner-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;align-items:end;margin:12px 0;padding:12px;border:1px solid var(--line);border-radius:8px;background:#f8fafc}.partner-form h6{grid-column:1/-1;margin:0}.partner-form .wide{grid-column:1/-1}.partner-form label{display:flex;flex-direction:column;gap:4px;font-size:.8rem;color:#475569;font-weight:700}.partner-form input,.partner-form select{min-height:38px;border:1px solid #cbd5e1;border-radius:6px;padding:6px 10px;background:#fff;font-weight:500;color:var(--ink)}.ledger-intro{margin:0 0 14px;padding:12px 14px;border:1px solid #dbe4ee;border-radius:8px;background:#f8fafc;color:#334155;font-size:.88rem;line-height:1.55}.ledger-intro code{font-family:inherit;font-weight:800;color:#0f172a}.rate-formula{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 0}.rate-chip{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:.78rem;font-weight:800}.rate-chip.warn{background:#fff7ed;color:#9a3412}.rate-chip.ok{background:#dcfce7;color:#166534}.partner-form.receipt-form{background:#fff7ed;border-color:#fed7aa}.partner-form.deal-form{background:#eef4ff;border-color:#93c5fd}.convert-preview{grid-column:1/-1;padding:10px 12px;border-radius:6px;background:#fff;border:1px dashed #93c5fd;color:#1e3a8a;font-size:.86rem;line-height:1.55}.deal-step{display:flex;gap:8px;margin:0 0 6px}.deal-step b{min-width:6.2rem;color:#334155}.partner-form input.calc-jump{background:#eef2ff;font-weight:800;color:#1e3a8a}.pnl-box{grid-column:1/-1;padding:12px 14px;border-radius:8px;font-weight:800}.pnl-box.profit{background:#dcfce7;color:#166534;border:1px solid #86efac}.pnl-box.loss{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}.pnl-box.even{background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe}.pnl-box span{display:block;font-size:.8rem;font-weight:600;opacity:.85}.month-bar{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:8px 0 12px}.month-bar select{min-height:38px;border:1px solid #cbd5e1;border-radius:6px;padding:6px 10px;background:#fff;font-weight:700}.month-bar .partner-search{flex:1;min-width:min(100%,280px);min-height:38px;border:1px solid #cbd5e1;border-radius:6px;padding:6px 10px}.th-net,.td-net{background:#f8fafc}.th-buy,.td-buy{background:#ecfdf5}.th-issue,.td-issue{background:#fff7ed}.th-receipt,.td-receipt{background:#fefce8}.empty-ledger{color:var(--muted);font-size:.8rem;font-weight:500}.vendor-row{display:grid;grid-template-columns:1.1fr .8fr 1fr .7fr;gap:12px;padding:12px 0;border-bottom:1px solid var(--line)}button.btn{border-radius:6px}.detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.detail-item{padding:10px;background:#f8fafc;border:1px solid var(--line);border-radius:6px}.detail-item span{display:block;font-size:.76rem;color:var(--muted)}
    .vendor-head td{background:#eef4ff;font-weight:800;color:#1e3a8a;border-bottom:1px solid #dbeafe}.check-col{width:42px;text-align:center}.row-actions{display:flex;gap:6px;flex-wrap:wrap}
    .vendor-pick{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border:1px solid var(--line);border-radius:8px;margin-bottom:8px;background:#f8fafc;cursor:pointer}
    .vendor-pick:has(input:checked){background:#eef4ff;border-color:#93c5fd}.vendor-pick b{display:block}.vendor-pick small{color:var(--muted)}
    .vendor-pick-toolbar{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:10px}
    @media(max-width:900px){.workspace{padding:12px}.invoice-head{display:block}.head-actions{margin-top:12px}.summary{grid-template-columns:repeat(2,minmax(0,1fr))}.toolbar,.batch-bar{align-items:stretch;flex-direction:column}.toolbar input{min-width:0;width:100%}.table-wrap{overflow:visible}table{min-width:0}thead{display:none}tbody,tfoot,tr,td{display:block}tr{padding:12px;border-bottom:1px solid var(--line)}td{border:0;padding:4px 0;display:grid;grid-template-columns:112px minmax(0,1fr);gap:8px}td:before{content:attr(data-label);color:var(--muted);font-size:.78rem;font-weight:700}.money{text-align:left}.vendor-row,.partner-form,.partner-grid{grid-template-columns:1fr}.detail-grid{grid-template-columns:1fr}.vendor-head td{display:block;grid-template-columns:1fr}.check-col{grid-template-columns:112px auto}}
  </style>
</head>
<body>
<main class="workspace">
  <header class="invoice-head">
    <div><h1><i class="bi bi-receipt-cutoff me-2"></i>電子發票記帳</h1><div class="subtext" id="accountingIdentity">讀取中</div></div>
    <div class="head-actions">
      <label class="btn btn-outline-primary btn-sm mb-0">
        <i class="bi bi-envelope-paper me-1"></i>匯入 Gmail 電子檔
        <input id="gmailUpload" type="file" accept="application/pdf,.pdf" multiple hidden>
      </label>
      <button class="btn btn-outline-primary btn-sm" id="connectGmailButton"><i class="bi bi-google me-1"></i>連接 Gmail</button>
      <button class="btn btn-outline-primary btn-sm d-none" id="syncGmailButton"><i class="bi bi-cloud-download me-1"></i>從 Gmail 抓信</button>
      <button class="btn btn-outline-secondary btn-sm d-none" id="gmailDisconnect" type="button">解除 Gmail 連線</button>
      <button class="btn btn-outline-secondary btn-sm" id="scanGmailButton"><i class="bi bi-folder2-open me-1"></i>掃描收件夾</button>
      <button class="btn btn-outline-secondary btn-sm" id="showUnprinted"><i class="bi bi-printer me-1"></i>未列印</button>
      <button class="btn btn-primary btn-sm" id="refreshButton"><i class="bi bi-arrow-clockwise me-1"></i>重新整理</button>
    </div>
  </header>
  <section class="summary" aria-label="電子發票摘要">
    <div class="metric"><span>待審核</span><strong id="countPending">0</strong></div><div class="metric"><span>已入帳</span><strong id="countBooked">0</strong></div><div class="metric"><span>異常／重複</span><strong id="countException">0</strong></div><div class="metric"><span>非公司憑證</span><strong id="countNonCompany">0</strong></div><div class="metric"><span>未列印</span><strong id="countUnprinted">0</strong></div><div class="metric"><span>往來戶</span><strong id="countPartners">0</strong></div>
  </section>
  <section class="surface">
    <nav class="tabs" aria-label="電子發票分類">
      <button class="tab active" data-tab="pending">待審核</button><button class="tab" data-tab="booked">已入帳</button><button class="tab" data-tab="exception">異常／重複</button><button class="tab" data-tab="non_company">非公司憑證</button><button class="tab" data-tab="partners">進銷項往來</button><button class="tab" data-tab="vendors">供應商規則</button><button class="tab" data-tab="exports">匯出紀錄</button>
    </nav>
    <div class="notice" id="syncNotice">Gmail 電子檔可直接匯入夾帶 PDF；列印後會依廠家存到「已列印」電子檔。</div>
    <div class="notice d-none" id="gmailSetup">
      <strong>第一次連接 Gmail</strong>
      <div class="subtext mt-1 mb-2">到 Google Cloud 建立「網頁應用程式」OAuth 用戶端，啟用 Gmail API，測試使用者加 <code>s1214098@gmail.com</code>。重新導向 URI 必須完全一樣：</div>
      <code id="gmailRedirectUri">https://baohui.paohui.org/accounting-gmail-oauth.php</code>
      <div class="gmail-setup">
        <label>Client ID<input id="gmailClientId" autocomplete="off" placeholder="xxxx.apps.googleusercontent.com"></label>
        <label>Client Secret<input id="gmailClientSecret" type="password" autocomplete="off"></label>
        <div class="row-btns">
          <button class="btn btn-sm btn-primary" id="gmailSaveClient" type="button">儲存用戶端</button>
        </div>
      </div>
    </div>
    <div id="invoicePane">
      <div class="toolbar">
        <div class="filter-set">
          <input id="invoiceSearch" type="search" placeholder="搜尋發票號碼、供應商或統編">
          <select id="vendorFilter"><option value="">全部廠家</option></select>
          <select id="printFilter"><option value="">全部列印狀態</option><option value="not_printed">未列印</option><option value="submitted_pending_confirmation">已送出／待確認</option><option value="printed_confirmed">已列印</option><option value="failed">失敗</option></select>
          <select id="poFilter"><option value="">全部進貨比對</option><option value="unmatched">捷元未對上</option><option value="matched">捷元已對上</option><option value="skip_before_cutoff">8/18前不比對</option></select>
        </div>
        <span class="subtext" id="resultCount">0 筆</span>
      </div>
      <div class="batch-bar">
        <label class="mb-0"><input type="checkbox" id="selectAllVisible"> 全選目前列表</label>
        <div class="filter-set">
          <span class="subtext" id="selectedCount">已選 0 筆</span>
          <button class="btn btn-sm btn-primary" id="batchPrintButton"><i class="bi bi-printer-fill me-1"></i>大量列印</button>
          <button class="btn btn-sm btn-outline-primary" id="vendorPrintButton"><i class="bi bi-building me-1"></i>依廠家分類列印</button>
          <button class="btn btn-sm btn-outline-primary" id="monthPrintButton"><i class="bi bi-calendar3 me-1"></i>依月份列印</button>
          <button class="btn btn-sm btn-outline-secondary" id="unmarkPrintButton">改回未列印</button>
          <button class="btn btn-sm btn-outline-primary" id="jieyuanPoMatchButton" type="button">比對捷元進貨單</button>
        </div>
      </div>
      <div class="table-wrap"><table><thead><tr><th class="check-col"></th><th>發票日期</th><th>發票號碼</th><th>賣方</th><th>進貨單</th><th>買方統編</th><th>發票金額</th><th>稅金 5%外加</th><th>合計</th><th>憑證狀態</th><th>列印</th><th>電子檔</th><th>操作</th></tr></thead><tbody id="invoiceRows"></tbody><tfoot id="invoiceTotals"></tfoot></table><div class="empty" id="emptyState"><i class="bi bi-inbox"></i>目前沒有資料</div></div>
    </div>
    <div id="vendorPane" class="vendor-pane d-none"></div><div id="partnerPane" class="partner-pane d-none"></div><div id="exportPane" class="export-pane d-none"></div>
  </section>
</main>

<div class="modal fade" id="invoiceDetailModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">發票明細</h5><button class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button></div><div class="modal-body" id="invoiceDetailBody"></div><div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal">關閉</button></div></div></div></div>
<div class="modal fade" id="vendorPrintModal" tabindex="-1"><div class="modal-dialog modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">依廠家勾選後產出</h5><button class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button></div><div class="modal-body"><div class="vendor-pick-toolbar"><span class="subtext" id="vendorPickCount">0 家廠家</span><span><button type="button" class="btn btn-sm btn-outline-secondary" id="vendorPickAll">全選</button> <button type="button" class="btn btn-sm btn-outline-secondary" id="vendorPickNone">取消全選</button></span></div><div id="vendorPrintBody"></div></div><div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button><button class="btn btn-primary" id="vendorPrintConfirm"><i class="bi bi-printer-fill me-1"></i>產出並列印</button></div></div></div></div>
<div class="modal fade" id="monthPrintModal" tabindex="-1"><div class="modal-dialog modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">依月份勾選後產出</h5><button class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button></div><div class="modal-body"><div class="vendor-pick-toolbar"><span class="subtext" id="monthPickCount">0 個月份</span><span><button type="button" class="btn btn-sm btn-outline-secondary" id="monthPickAll">全選</button> <button type="button" class="btn btn-sm btn-outline-secondary" id="monthPickNone">取消全選</button></span></div><div id="monthPrintBody"></div></div><div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button><button class="btn btn-primary" id="monthPrintConfirm"><i class="bi bi-printer-fill me-1"></i>產出並列印</button></div></div></div></div>
<div class="modal fade" id="partnerDetailModal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="partnerDetailTitle">進銷項分錄</h5><button class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button></div><div class="modal-body" id="partnerDetailBody"></div></div></div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const state={tab:'pending',rows:[],partners:[],partnerLedger:[],partnerQuery:'',partnerDirection:'all',csrf:'',capabilities:[],selected:new Set()};
const labels={pending_review:'待審核',needs_official_voucher:'待取得正式會計憑證',ready_to_book:'待入帳',booked:'已入帳',exception:'異常',duplicate:'重複',non_company:'非公司憑證',not_printed:'未列印',submitted_pending_confirmation:'已送出／待確認',printed_confirmed:'已列印',failed:'失敗'};
const esc=(value)=>String(value??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
const money=(value)=>new Intl.NumberFormat('zh-TW').format(Number(value||0))+' 元';
function partnerConversion(face,inRate,vatRate,incomeRate){
  const amount=Math.max(0,Math.round(Number(face||0)));
  const inbound=Number(inRate||0);
  const vat=Number(vatRate||5);
  const income=Number(incomeRate||3);
  const outbound=vat+income;
  const net=Math.round(amount/1.05);
  const paperVat=amount-net;
  const buyback=Math.round(amount*inbound/100);
  const issueVat=Math.round(amount*vat/100);
  const issueIncome=Math.round(amount*income/100);
  const issueTax=issueVat+issueIncome;
  const receipt=buyback;
  return {face:amount,net,paperVat,buyback,issueVat,issueIncome,issueTax,receipt,inbound,vat,income,outbound,spread:Math.max(0,outbound-inbound)};
}
function pnlLabel(value){
  const n=Number(value||0);
  if(n>0) return {cls:'profit',text:'賺錢',hint:'收購 2% ＋ 已填收據 超過開出去 8%'};
  if(n<0) return {cls:'loss',text:'虧錢',hint:'開出去 8% 大於收購 2% ＋ 已填收據'};
  return {cls:'even',text:'打平',hint:'收購 2% ＋ 已填收據 已對上開出去 8%'};
}
function partnerPnlMark(s){
  if(Number(s.issue_tax||0)<=0) return {cls:'even',text:'尚未開出去',hint:'8% 還不會跳。收購 2% 已依收到發票設定；空白收據照你填的金額列。'};
  return pnlLabel(Number(s.pnl||0));
}
function conversionPreviewHtml(face,inRate,vatRate,incomeRate,receiptFilled,mode){
  const amount=Math.max(0,Math.round(Number(face||0)));
  const view=mode||'deal';
  if(!amount){
    if(view==='inbound') return '填我收到多少，收購 2% 會自動跳。空白收據金額另外自己填，不是自動帶 2%。';
    if(view==='outbound') return '填我開出去多少，8% 會自動跳到欄位上。不是他開給我們的發票跟他收 8%。';
    return '只要填我收到多少、我開出去多少。收購 2%、開出去 8% 會自動跳。空白收據金額另外自己填。';
  }
  const c=partnerConversion(amount,inRate,vatRate,incomeRate);
  if(view==='inbound'){
    return `<div class="deal-step"><b>我收到</b><span>未稅 ${money(c.net)} ＋ 稅金 5% ${money(c.paperVat)} ＝ 合計 ${money(c.face)}</span></div>
    <div class="deal-step"><b>收購 2%</b><span>已設定 ${money(c.buyback)}，不用再填。</span></div>`;
  }
  if(view==='outbound'){
    return `<div class="deal-step"><b>我開出去</b><span>未稅 ${money(c.net)} ＋ 稅金 5% ${money(c.paperVat)} ＝ 合計 ${money(c.face)}</span></div>
    <div class="deal-step"><b>開出去 8%</b><span>已設定 ${money(c.issueTax)}（發票稅金 5% ${money(c.issueVat)} ＋ 所得稅 3% ${money(c.issueIncome)}）</span></div>`;
  }
  return dealPreviewHtml(amount,amount,inRate,vatRate,incomeRate);
}
function dealPreviewHtml(inFace,outFace,inRate,vatRate,incomeRate){
  const rec=partnerConversion(inFace,inRate,vatRate,incomeRate);
  const iss=partnerConversion(outFace,inRate,vatRate,incomeRate);
  if(!rec.face && !iss.face) return '只要填我收到多少、我開出去多少。收購 2%、開出去 8% 會自動跳。空白收據另外自己填。';
  const parts=[];
  if(rec.face){
    parts.push(`<div class="deal-step"><b>我收到</b><span>未稅 ${money(rec.net)} ＋ 稅金 5% ${money(rec.paperVat)} ＝ ${money(rec.face)}</span></div>`);
  }
  if(iss.face){
    parts.push(`<div class="deal-step"><b>我開出去</b><span>未稅 ${money(iss.net)} ＋ 稅金 5% ${money(iss.paperVat)} ＝ ${money(iss.face)}</span></div>`);
  }
  if(rec.face && iss.face){
    const gap=Math.max(0,iss.issueTax-rec.buyback);
    const mark=pnlLabel(rec.buyback-iss.issueTax);
    parts.push(`<div class="deal-step"><b>這筆對開</b><span>${mark.text} ${money(Math.abs(rec.buyback-iss.issueTax))}（收購 2% ${money(rec.buyback)} − 開出去 8% ${money(iss.issueTax)}）。空白收據${gap>0?`至少再填 ${money(gap)} 才打平`:'再填就是多賺'}。</span></div>`);
  }else if(rec.face){
    parts.push(`<div class="deal-step"><b>還沒開出去</b><span>8% 還不會跳。收購 2% 已設定 ${money(rec.buyback)}。空白收據另外自己填。</span></div>`);
  }else{
    parts.push(`<div class="deal-step"><b>還沒填收到</b><span>收購 2% 還不會跳。開出去 8% 已設定 ${money(iss.issueTax)}。</span></div>`);
  }
  return parts.join('');
}
function fillCalcJump(id,value,show){
  const el=document.getElementById(id);
  if(!el) return;
  el.value=show?String(value):'';
}
function bindInvoiceConversion(inputId,previewId,inRate,vatRate,incomeRate,receiptId,mode){
  const input=document.getElementById(inputId);
  const box=document.getElementById(previewId);
  if(!input||!box) return ()=>{};
  const modeOf=()=>typeof mode==='function'?mode():(mode||'deal');
  const refresh=()=>{
    const view=modeOf();
    const c=partnerConversion(input.value,inRate,vatRate,incomeRate);
    fillCalcJump('recBuyback',c.buyback,view==='inbound'&&c.face);
    fillCalcJump('recOffset','',false);
    fillCalcJump('recIssue',c.issueTax,view==='outbound'&&c.face);
    box.innerHTML=conversionPreviewHtml(input.value,inRate,vatRate,incomeRate,'',view);
  };
  input.addEventListener('input',refresh);
  refresh();
  return refresh;
}
function bindDealConversion(inRate,vatRate,incomeRate){
  const inEl=document.getElementById('dealInFace');
  const outEl=document.getElementById('dealOutFace');
  const box=document.getElementById('dealPreview');
  if(!inEl||!outEl||!box) return;
  const refresh=()=>{
    const rec=partnerConversion(inEl.value,inRate,vatRate,incomeRate);
    const iss=partnerConversion(outEl.value,inRate,vatRate,incomeRate);
    fillCalcJump('dealBuyback',rec.buyback,!!rec.face);
    fillCalcJump('dealOffset','',false);
    fillCalcJump('dealIssueTax',iss.issueTax,!!iss.face);
    box.innerHTML=dealPreviewHtml(inEl.value,outEl.value,inRate,vatRate,incomeRate);
  };
  inEl.addEventListener('input',refresh);
  outEl.addEventListener('input',refresh);
  refresh();
}
function partnerQueryMatch(query,textParts,amounts){
  const raw=String(query||'').trim().toLowerCase();
  if(!raw) return true;
  const digits=raw.replace(/[,\s元]/g,'');
  if(/^\d+$/.test(digits)){
    return (amounts||[]).some(value=>String(Math.round(Number(value||0)))===digits);
  }
  return (textParts||[]).join(' ').toLowerCase().includes(raw);
}
function vatBreakdown(row){
  let net=Number(row.net_amount||0);
  let tax=Number(row.tax_amount||0);
  let total=Number(row.total_amount||0);
  if(total>0 && net===0 && tax===0){
    net=Math.round(total/1.05);
    tax=total-net;
  }else if(net>0 && total===0){
    if(tax===0) tax=Math.round(net*0.05);
    total=net+tax;
  }else if(total>0 && net>0 && tax===0){
    tax=total-net;
  }else if(net>0 && tax>0 && total===0){
    total=net+tax;
  }
  const extra=Math.round(net*0.05);
  return {net,tax,total,extra,sum:net+tax};
}
function statusClass(value){if(value==='printed_confirmed')return 'print-done';if(value==='booked')return 'status-booked';if(value==='exception'||value==='duplicate'||value==='failed')return 'status-exception';if(value==='non_company')return 'status-noncompany';if(value==='not_printed')return 'print-unprinted';return 'status-wait'}
function printLabel(row){
  if(row.print_status==='printed_confirmed'){
    const n=Math.max(1, Number(row.print_count||1));
    return n>1 ? `已列印 ${n} 次` : '已列印';
  }
  return labels[row.print_status]||row.print_status;
}
function canPrint(){return state.capabilities.includes('invoice_print')}
function canEdit(){return state.capabilities.includes('invoice_edit')}
async function api(action,options={}){
  const headers={'X-CSRFToken':state.csrf,'X-CSRF-Token':state.csrf};
  const method=(options.method||'GET').toUpperCase();
  if(method!=='GET'&&!options.form) headers['Content-Type']='application/json';
  let query=options.query||'';
  if(method==='POST'&&state.csrf) query+='&csrf='+encodeURIComponent(state.csrf);
  let body=undefined;
  if(options.form){
    options.form.append('csrf',state.csrf||'');
    body=options.form;
  }else if(options.body){
    body=JSON.stringify(Object.assign({csrf:state.csrf||''},options.body));
  }
  const response=await fetch('accounting-api.php?action='+encodeURIComponent(action)+query,{
    method,
    headers,
    body,
    cache:'no-store',
    credentials:'same-origin'
  });
  const text=await response.text();
  let payload={};
  try{payload=JSON.parse(text)}catch(e){}
  if(!response.ok||!payload.ok){
    let hint=String(payload.error||'');
    if(!hint){
      hint=/FastCGI|活動逾時|activity timeout|500\.0|Internal Server Error/i.test(text)
        ? '伺服器處理逾時。抓信已改在背景執行，請重新整理頁面。'
        : text.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim();
    }
    throw new Error((hint||('讀取失敗 HTTP '+response.status)).slice(0,160));
  }
  return payload;
}
async function loadStatus(){
  const payload=await api('status');
  state.csrf=payload.csrf;
  state.capabilities=payload.capabilities||[];
  state.isAdmin=!!payload.isAdmin;
  state.gmail=payload.gmail||{};
  const settings=payload.settings||{};
  const gmail=state.gmail;
  const gmailLabel=gmail.connected?(gmail.email?`｜Gmail ${gmail.email}`:'｜Gmail 已連接'):'｜Gmail 未連接';
  document.getElementById('accountingIdentity').textContent=`目前登入：${payload.user}｜公司統編 ${settings.company_tax_id}${gmailLabel}`;
  const minutes=Number(gmail.sync_minutes||settings.gmail_sync_minutes||10);
  const last=gmail.last_sync||{};
  const lastHint=last.finished_at?`上次抓信 ${last.finished_at}（掃描 ${last.scanned_count||0}、新增 ${last.imported_count||0}）。`:'尚未自動抓過。';
  const oauthHint=gmail.connected
    ? `已連接 Gmail，系統約每 ${minutes} 分鐘自動抓捷元電子對帳單，不必每次手按。也可按「從 Gmail 抓信」立刻補抓。${lastHint}`
    : (gmail.has_client ? 'OAuth 用戶端已存好，請按「連接 Gmail」（會開新視窗，不要在 iframe 裡授權）。' : '正式 OAuth 已接上：管理員先貼 Google Client ID／Secret，再按「連接 Gmail」。');
  document.getElementById('syncNotice').textContent=`發票金額是未稅，稅金是營業稅 5% 外加，合計＝發票金額＋稅金。捷元發票從 ${settings.jieyuan_po_match_from||'2026-08-18'} 起會跟進貨入庫單對上（供應商空白的進貨單也算，金額可對未稅或含稅 5%）；那天之前的不比對。匯入 PDF 會分開抽出未稅／稅金／合計。列印後依廠家存到「${settings.printed_folder||'Invoices/已列印'}」。${oauthHint}`;
  document.getElementById('gmailRedirectUri').textContent=gmail.redirect_uri||'https://baohui.paohui.org/accounting-gmail-oauth.php';
  document.getElementById('gmailSetup').classList.toggle('d-none', !(canEdit() && state.isAdmin && !gmail.has_client));
  document.getElementById('gmailDisconnect').classList.toggle('d-none', !(state.isAdmin && gmail.connected));
  document.getElementById('connectGmailButton').classList.toggle('d-none', !canEdit());
  document.getElementById('connectGmailButton').innerHTML=gmail.connected?'<i class="bi bi-google me-1"></i>重新連接 Gmail':'<i class="bi bi-google me-1"></i>連接 Gmail';
  document.getElementById('syncGmailButton').classList.toggle('d-none', !(canEdit() && gmail.connected));
  document.getElementById('scanGmailButton').classList.toggle('d-none', !canEdit());
  document.getElementById('gmailUpload').closest('label').classList.toggle('d-none', !canEdit());
  document.getElementById('batchPrintButton').disabled=!canPrint();
  document.getElementById('vendorPrintButton').disabled=!canPrint();
  for(const [key,id] of Object.entries({pending:'countPending',booked:'countBooked',exception:'countException',non_company:'countNonCompany',unprinted:'countUnprinted',partners:'countPartners'}))document.getElementById(id).textContent=payload.counts[key]||0;
}
function filteredRows(){
  const query=document.getElementById('invoiceSearch').value.trim().toLowerCase();
  const print=document.getElementById('printFilter').value;
  const vendor=document.getElementById('vendorFilter').value;
  const po=document.getElementById('poFilter').value;
  return state.rows.filter(row=>(!print||row.print_status===print)&&(!vendor||row.vendor_name===vendor)&&(!po||row.po_match_status===po)&&(!query||[row.invoice_number,row.seller_name,row.seller_tax_id,row.buyer_tax_id,row.vendor_name,row.po_document_no].join(' ').toLowerCase().includes(query)));
}
function refreshVendorFilter(){
  const select=document.getElementById('vendorFilter');
  const current=select.value;
  const vendors=[...new Set(state.rows.map(row=>row.vendor_name||'未分類廠家'))].sort((a,b)=>a.localeCompare(b,'zh-Hant'));
  select.innerHTML='<option value="">全部廠家</option>'+vendors.map(name=>`<option value="${esc(name)}">${esc(name)}</option>`).join('');
  if(vendors.includes(current)) select.value=current;
}
function poMatchCell(row){
  const status=row.po_match_status||'';
  if(status==='matched') return `<span class="badge-status status-booked">已對上</span><div class="muted">${esc(row.po_document_no||'')}</div>`;
  if(status==='unmatched') return '<span class="badge-status status-exception">未對上進貨單</span>';
  if(status==='skip_before_cutoff') return '<span class="muted">8/18前不比對</span>';
  const jieyuan=(row.seller_tax_id==='23134543')||String(row.seller_name||'').includes('捷元');
  return jieyuan?'<span class="muted">尚未比對</span>':'-';
}
function renderRows(){
  const rows=filteredRows();
  const body=document.getElementById('invoiceRows');
  let html='';
  let lastVendor='';
  rows.forEach(row=>{
    const vendor=row.vendor_name||'未分類廠家';
    if(vendor!==lastVendor){
      html+=`<tr class="vendor-head"><td colspan="13">${esc(vendor)}</td></tr>`;
      lastVendor=vendor;
    }
    const checked=state.selected.has(Number(row.id))?'checked':'';
    const pdf=row.has_pdf
      ? `<a class="btn btn-sm btn-outline-success" href="accounting-api.php?action=download&document_id=${Number(row.document_id)}" target="_blank" rel="noopener">開啟</a>`
      : '<span class="muted">尚未匯入</span>';
    const printBtn=canPrint() && row.has_pdf ? `<button class="btn btn-sm btn-outline-primary" onclick="printOne(${Number(row.id)})">列印</button>` : '';
    const unmarkBtn=canPrint() && row.print_status!=='not_printed' ? `<button class="btn btn-sm btn-outline-secondary" onclick="unmarkOne(${Number(row.id)})">改回未列印</button>` : '';
    const amt=vatBreakdown(row);
    html+=`<tr>
      <td class="check-col" data-label="選取"><input type="checkbox" class="row-check" data-id="${Number(row.id)}" ${checked} ${row.has_pdf?'':'disabled'}></td>
      <td data-label="發票日期">${esc(row.invoice_date||'-')}</td>
      <td data-label="發票號碼"><strong>${esc(row.invoice_number||'待辨識')}</strong><div class="muted">${esc(row.source_type)}</div></td>
      <td data-label="賣方">${esc(row.seller_name||'-')}<div class="muted">${esc(row.seller_tax_id)}</div></td>
      <td data-label="進貨單">${poMatchCell(row)}</td>
      <td data-label="買方統編">${esc(row.buyer_tax_id||'-')}</td>
      <td data-label="發票金額" class="money">${money(amt.net)}</td>
      <td data-label="稅金 5%外加" class="money">${money(amt.tax)}${amt.net && amt.extra!==amt.tax?`<div class="muted">5%應計 ${money(amt.extra)}</div>`:''}</td>
      <td data-label="合計" class="money">${money(amt.total)}<div class="muted">${money(amt.net)}＋${money(amt.tax)}</div></td>
      <td data-label="憑證狀態"><span class="badge-status ${statusClass(row.workflow_status)}">${esc(labels[row.workflow_status]||row.workflow_status)}</span></td>
      <td data-label="列印"><span class="badge-status ${statusClass(row.print_status)}">${esc(printLabel(row))}</span>${row.last_printed_at?`<div class="muted">${esc(String(row.last_printed_at).slice(0,16))}</div>`:''}</td>
      <td data-label="電子檔">${pdf}</td>
      <td data-label="操作"><div class="row-actions">${printBtn}${unmarkBtn}<button class="btn btn-sm btn-outline-primary" onclick="showDetail(${Number(row.id)})">檢視</button></div></td>
    </tr>`;
  });
  body.innerHTML=html;
  const sums=rows.reduce((acc,row)=>{const amt=vatBreakdown(row);acc.net+=amt.net;acc.tax+=amt.tax;acc.total+=amt.total;return acc;},{net:0,tax:0,total:0});
  document.getElementById('invoiceTotals').innerHTML=rows.length?`<tr>
    <td colspan="6" data-label="本頁合計">本頁合計</td>
    <td class="money" data-label="發票金額">${money(sums.net)}</td>
    <td class="money" data-label="稅金 5%外加">${money(sums.tax)}</td>
    <td class="money" data-label="合計">${money(sums.total)}<div class="muted">${money(sums.net)}＋${money(sums.tax)}</div></td>
    <td colspan="4"></td>
  </tr>`:'';
  document.getElementById('emptyState').classList.toggle('d-none',rows.length>0);
  document.getElementById('resultCount').textContent=rows.length+' 筆';
  document.getElementById('selectedCount').textContent='已選 '+state.selected.size+' 筆';
  const printable=rows.filter(row=>row.has_pdf);
  const allChecked=printable.length>0 && printable.every(row=>state.selected.has(Number(row.id)));
  document.getElementById('selectAllVisible').checked=allChecked;
  body.querySelectorAll('.row-check').forEach(box=>box.addEventListener('change',()=>{
    const id=Number(box.dataset.id);
    if(box.checked) state.selected.add(id); else state.selected.delete(id);
    document.getElementById('selectedCount').textContent='已選 '+state.selected.size+' 筆';
  }));
}
async function loadRows(){
  if(state.tab==='vendors')return loadVendors();
  if(state.tab==='exports')return loadExports();
  if(state.tab==='partners')return loadPartners();
  document.getElementById('invoicePane').classList.remove('d-none');
  document.getElementById('vendorPane').classList.add('d-none');
  document.getElementById('partnerPane').classList.add('d-none');
  document.getElementById('exportPane').classList.add('d-none');
  document.getElementById('syncNotice').classList.remove('d-none');
  const payload=await api('list',{query:'&tab='+encodeURIComponent(state.tab)});
  state.rows=payload.rows||[];
  const valid=new Set(state.rows.map(row=>Number(row.id)));
  state.selected=new Set([...state.selected].filter(id=>valid.has(id)));
  refreshVendorFilter();
  renderRows();
}
async function showDetail(id){
  try{
    const payload=await api('detail',{query:'&id='+id});
    const i=payload.invoice;
    const amt=vatBreakdown(i);
    const fields=[['發票號碼',i.invoice_number],['發票日期',i.invoice_date],['賣方',i.seller_name],['賣方統編',i.seller_tax_id],['進貨單',i.po_document_no||(i.po_match_note||'-')],['買方',i.buyer_name],['買方統編',i.buyer_tax_id],['發票金額',money(amt.net)],['稅金 5%外加',money(amt.tax)],['5%外加應計',money(amt.extra)],['合計（發票金額＋稅金）',money(amt.total)],['流程狀態',labels[i.workflow_status]||i.workflow_status],['正式憑證',i.official_voucher_status],['列印狀態',labels[i.print_status]||i.print_status]];
    const documents=payload.documents||[];
    const documentHtml=documents.length?documents.map(doc=>`<div class="mb-2"><a class="btn btn-sm btn-outline-primary me-2" href="accounting-api.php?action=download&document_id=${Number(doc.id)}" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf me-1"></i>查看 PDF v${Number(doc.pdf_version)||1}</a><span class="muted">${esc(doc.source_category||'未分類')}${doc.original_filename?'｜'+esc(doc.original_filename):''}</span></div>`).join(''):'<div class="muted">目前沒有可查看的 PDF，請先匯入 Gmail 電子檔。</div>';
    document.getElementById('invoiceDetailBody').innerHTML=`<div class="detail-grid">${fields.map(([k,v])=>`<div class="detail-item"><span>${esc(k)}</span><strong>${esc(v||'-')}</strong></div>`).join('')}</div><h6 class="mt-4">歸檔憑證</h6><div>${documentHtml}</div><h6 class="mt-4">品項明細</h6>${payload.items.length?`<div class="table-responsive"><table class="table table-sm"><thead><tr><th>品項</th><th>數量</th><th>單價</th><th>金額</th></tr></thead><tbody>${payload.items.map(x=>`<tr><td>${esc(x.item_name)}</td><td>${esc(x.quantity)} ${esc(x.unit)}</td><td>${money(x.unit_price)}</td><td>${money(x.line_amount)}</td></tr>`).join('')}</tbody></table></div>`:'<div class="muted">無品項明細</div>'}`;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('invoiceDetailModal')).show();
  }catch(error){alert(error.message)}
}
async function printIds(ids,note,vendors=[],extra={}){
  if(!ids.length) throw new Error('請先勾選要列印的發票');
  const months=extra.months||[];
  const groupBy=extra.groupBy||(months.length?'month':(vendors.length?'vendor':''));
  const payload=await api('print_batch',{method:'POST',body:{ids,reprint_note:note,vendors,months,group_by:groupBy}});
  if(payload.print_view) window.open(payload.print_view,'_blank','noopener');
  const missing=Number(payload.missing_count||0);
  const filed=groupBy==='month'?'並依月份分組列印。':'並依廠家存到電子檔「已列印」。';
  alert(`已列印 ${payload.printed_count} 張，狀態改為「已列印」，${filed}`+(missing?`\n另有 ${missing} 張還沒有 PDF。`:''));
  await Promise.all([loadStatus(),loadRows()]);
}
async function printOne(id){
  const row=state.rows.find(item=>Number(item.id)===Number(id));
  if(row && row.print_status!=='not_printed' && !confirm('這張已列印過。確定再印一次？')) return;
  try{await printIds([id],'單張列印')}catch(error){alert(error.message)}
}
async function unmarkIds(ids){
  if(!ids.length) throw new Error('請先勾選要改回未列印的發票');
  const payload=await api('print_unmark',{method:'POST',body:{ids}});
  alert(`已把 ${payload.unmarked} 張改回未列印。`);
  state.selected.clear();
  await Promise.all([loadStatus(),loadRows()]);
}
async function unmarkOne(id){
  if(!confirm('把這張改回未列印？之後再按列印才會顯示已列印。')) return;
  try{await unmarkIds([id])}catch(error){alert(error.message)}
}
async function batchUnmark(){
  try{
    const ids=[...state.selected];
    if(!ids.length) return alert('請先勾選要改回未列印的發票');
    if(!confirm(`把已選 ${ids.length} 張改回未列印？`)) return;
    await unmarkIds(ids);
  }catch(error){alert(error.message)}
}
async function batchPrint(mode){
  try{
    if(mode==='vendor') return openVendorPrintMenu();
    if(mode==='month') return openMonthPrintMenu();
    const visible=filteredRows().filter(row=>row.has_pdf).map(row=>Number(row.id));
    const ids=[...state.selected].filter(id=>visible.includes(id));
    const target=ids.length?ids:visible;
    if(!target.length) return alert('目前沒有可列印的電子檔。請先匯入 Gmail PDF。');
    const already=target.filter(id=>{
      const row=state.rows.find(item=>Number(item.id)===Number(id));
      return row && row.print_status!=='not_printed';
    });
    if(already.length && !confirm(`其中 ${already.length} 張已列印過。確定再印一次？`)) return;
    await printIds(target, '大量列印');
  }catch(error){alert(error.message)}
}
function vendorPrintGroups(){
  const visible=filteredRows().filter(row=>row.has_pdf);
  const selectedIds=[...state.selected].filter(id=>visible.some(row=>Number(row.id)===id));
  const source=selectedIds.length?visible.filter(row=>selectedIds.includes(Number(row.id))):visible;
  const map=new Map();
  source.forEach(row=>{
    const name=row.vendor_name||'未分類廠家';
    if(!map.has(name)) map.set(name,{vendor:name,ids:[],unprinted:0,printed:0});
    const group=map.get(name);
    group.ids.push(Number(row.id));
    if(row.print_status==='not_printed') group.unprinted+=1; else group.printed+=1;
  });
  return [...map.values()].sort((a,b)=>a.vendor.localeCompare(b.vendor,'zh-Hant'));
}
function openVendorPrintMenu(){
  const groups=vendorPrintGroups();
  if(!groups.length) return alert('目前沒有可列印的電子檔。請先匯入 Gmail PDF。');
  document.getElementById('vendorPrintBody').innerHTML=groups.map(group=>`
    <label class="vendor-pick">
      <input type="checkbox" class="vendor-pick-box" value="${esc(group.vendor)}" data-ids="${group.ids.join(',')}" checked>
      <span><b>${esc(group.vendor)}</b><small>${group.ids.length} 張｜未列印 ${group.unprinted}｜已列印 ${group.printed}</small></span>
    </label>`).join('');
  document.getElementById('vendorPickCount').textContent=groups.length+' 家廠家';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('vendorPrintModal')).show();
}
function setVendorPickAll(checked){
  document.querySelectorAll('.vendor-pick-box').forEach(box=>{box.checked=checked});
}
async function confirmVendorPrint(){
  const boxes=[...document.querySelectorAll('.vendor-pick-box:checked')];
  if(!boxes.length) return alert('請至少勾選一個廠家再產出');
  const ids=[...new Set(boxes.flatMap(box=>String(box.dataset.ids||'').split(',').map(Number).filter(Boolean)))];
  const vendors=boxes.map(box=>box.value);
  const already=ids.filter(id=>{
    const row=state.rows.find(item=>Number(item.id)===Number(id));
    return row && row.print_status!=='not_printed';
  });
  if(already.length && !confirm(`其中 ${already.length} 張已列印過。確定再產出？`)) return;
  try{
    await printIds(ids,'依廠家分類列印',vendors,{groupBy:'vendor'});
    bootstrap.Modal.getOrCreateInstance(document.getElementById('vendorPrintModal')).hide();
  }catch(error){alert(error.message)}
}
function invoiceMonthKey(row){
  const date=String(row.invoice_date||'');
  const match=date.match(/^(\d{4}-\d{2})/);
  return match?match[1]:'undated';
}
function invoiceMonthLabel(key){
  if(key==='undated') return '未標日期';
  const [year,month]=key.split('-');
  return year+'年'+String(Number(month))+'月';
}
function monthPrintGroups(){
  const visible=filteredRows().filter(row=>row.has_pdf);
  const selectedIds=[...state.selected].filter(id=>visible.some(row=>Number(row.id)===id));
  const source=selectedIds.length?visible.filter(row=>selectedIds.includes(Number(row.id))):visible;
  const map=new Map();
  source.forEach(row=>{
    const key=invoiceMonthKey(row);
    if(!map.has(key)) map.set(key,{month:key,label:invoiceMonthLabel(key),ids:[],unprinted:0,printed:0});
    const group=map.get(key);
    group.ids.push(Number(row.id));
    if(row.print_status==='not_printed') group.unprinted+=1; else group.printed+=1;
  });
  return [...map.values()].sort((a,b)=>a.month.localeCompare(b.month));
}
function openMonthPrintMenu(){
  const groups=monthPrintGroups();
  if(!groups.length) return alert('目前沒有可列印的電子檔。請先匯入 Gmail PDF。');
  document.getElementById('monthPrintBody').innerHTML=groups.map(group=>`
    <label class="vendor-pick">
      <input type="checkbox" class="month-pick-box" value="${esc(group.month)}" data-ids="${group.ids.join(',')}" checked>
      <span><b>${esc(group.label)}</b><small>${group.ids.length} 張｜未列印 ${group.unprinted}｜已列印 ${group.printed}</small></span>
    </label>`).join('');
  document.getElementById('monthPickCount').textContent=groups.length+' 個月份';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('monthPrintModal')).show();
}
function setMonthPickAll(checked){
  document.querySelectorAll('.month-pick-box').forEach(box=>{box.checked=checked});
}
async function confirmMonthPrint(){
  const boxes=[...document.querySelectorAll('.month-pick-box:checked')];
  if(!boxes.length) return alert('請至少勾選一個月份再產出');
  const ids=[...new Set(boxes.flatMap(box=>String(box.dataset.ids||'').split(',').map(Number).filter(Boolean)))];
  const months=boxes.map(box=>box.value);
  const already=ids.filter(id=>{
    const row=state.rows.find(item=>Number(item.id)===Number(id));
    return row && row.print_status!=='not_printed';
  });
  if(already.length && !confirm(`其中 ${already.length} 張已列印過。確定再產出？`)) return;
  try{
    await printIds(ids,'依月份列印',[],{months,groupBy:'month'});
    bootstrap.Modal.getOrCreateInstance(document.getElementById('monthPrintModal')).hide();
  }catch(error){alert(error.message)}
}
async function ingestGmail(files){
  try{
    let payload;
    if(files&&files.length){
      const form=new FormData();
      for(const file of files) form.append('files[]',file);
      payload=await api('ingest_gmail',{method:'POST',form});
    }else{
      payload=await api('ingest_gmail',{method:'POST',body:{}});
    }
    const extra=(payload.errors||[]).slice(0,5).join('\n');
    const updated=payload.updated?`、回填金額 ${payload.updated}`:'';
    alert(`Gmail 電子檔已匯入：新增 ${payload.imported}、已存在 ${payload.attached}${updated}、略過 ${payload.skipped}。`+(extra?'\n'+extra:''));
    document.getElementById('gmailUpload').value='';
    await Promise.all([loadStatus(),loadRows()]);
  }catch(error){alert(error.message)}
}
async function saveGmailClient(){
  try{
    await api('gmail_save_client',{method:'POST',body:{
      client_id:document.getElementById('gmailClientId').value.trim(),
      client_secret:document.getElementById('gmailClientSecret').value.trim()
    }});
    document.getElementById('gmailClientSecret').value='';
    await loadStatus();
    alert('OAuth 用戶端已儲存在伺服器，不會進 git。接著按「連接 Gmail」。');
  }catch(error){alert(error.message)}
}
async function connectGmail(){
  try{
    if(!state.csrf) await loadStatus();
    const payload=await api('gmail_oauth_start',{method:'POST',body:{}});
    const url=payload.url;
    const popup=window.open(url,'baohui-gmail-oauth','popup=yes,width=520,height=720');
    if(!popup){
      if(window.top) window.top.location.href=url;
      else location.href=url;
    }
  }catch(error){alert(error.message)}
}
async function syncGmailInbox(){
  try{
    document.getElementById('syncGmailButton').disabled=true;
    const payload=await api('gmail_sync',{method:'POST',body:{}});
    alert(payload.message||'已開始在背景抓信。');
    await Promise.all([loadStatus(),loadRows()]);
  }catch(error){alert(error.message)}
  finally{document.getElementById('syncGmailButton').disabled=false}
}
async function disconnectGmail(){
  if(!confirm('確定解除 Gmail 連線？之後要再授權一次。')) return;
  try{
    await api('gmail_disconnect',{method:'POST',body:{}});
    await loadStatus();
  }catch(error){alert(error.message)}
}
function partnerEntryLabel(row){
  if(row.auto_from_invoice) return row.entry_type==='outbound'?'已入帳發票（我們開給他）':'已入帳發票（他開給我們）';
  if(row.entry_type==='inbound'&&row.purpose==='park')return '進項發票（代管款）';
  if(row.entry_type==='inbound')return '進項發票（進貨）';
  if(row.entry_type==='outbound'&&row.purpose==='reciprocal')return '銷項發票（對開）';
  if(row.entry_type==='outbound')return '銷項發票（銷貨）';
  if(row.entry_type==='offset')return '沖減代管款';
  if(row.entry_type==='settle')return '對開沖帳';
  if(row.entry_type==='receipt'&&row.purpose==='reserve')return '空白收據（留底）';
  if(row.entry_type==='receipt')return '空白收據（報開銷）';
  return row.entry_type;
}
function partnerEntryMonth(row){
  const d=String(row.entry_date||'').trim();
  return /^\d{4}-\d{2}/.test(d)?d.slice(0,7):'undated';
}
function monthLabel(key){
  if(!key||key==='all') return '全部月份';
  if(key==='undated') return '未標日期';
  const [y,m]=String(key).split('-');
  return `${y}年${Number(m)}月`;
}
function partnerMonthOptions(entries){
  const set=new Set();
  (entries||[]).forEach(row=>{set.add(partnerEntryMonth(row));});
  return [...set].sort().reverse();
}
function filterPartnerEntries(entries,month,direction){
  let list=entries||[];
  if(month&&month!=='all') list=list.filter(row=>partnerEntryMonth(row)===month);
  if(direction==='inbound'||direction==='outbound') list=list.filter(row=>row.entry_type===direction);
  return list;
}
function partnerDirectionLabel(direction,ours,theirs){
  const ourName=ours?.name||'寶輝電腦';
  const theirName=theirs?.name||theirs?.partner_name||'往來戶';
  if(direction==='inbound') return `${theirName} → ${ourName}（他開給我）`;
  if(direction==='outbound') return `${ourName} → ${theirName}（我開給他）`;
  return '全部（我開給他／他開給我）';
}
function partnerMonthStats(entries,inRate,vatRate,incomeRate){
  let inbound=0,outbound=0,receipt=0;
  (entries||[]).forEach(row=>{
    const type=row.entry_type;
    const face=Number(row.face_amount||0);
    if(type==='inbound') inbound+=face;
    else if(type==='outbound'&&row.purpose!=='sale') outbound+=face;
    else if(type==='receipt') receipt+=face;
  });
  const inboundNet=inbound?Math.round(inbound/1.05):0;
  const inboundVat=inbound-inboundNet;
  const buyback=Math.round(inbound*Number(inRate||0)/100);
  const issueVat=Math.round(outbound*Number(vatRate||5)/100);
  const issueIncome=Math.round(outbound*Number(incomeRate||3)/100);
  const issueTax=issueVat+issueIncome;
  const spread=issueTax>0?(buyback+receipt-issueTax):0;
  const breakEven=Math.max(0,issueTax-buyback-receipt);
  return {inbound,inboundNet,inboundVat,outbound,buyback,issueVat,issueIncome,issueTax,receipt,spread,breakEven};
}
function pct(value){return Number(value||0).toLocaleString('zh-TW',{maximumFractionDigits:2})+'%'}
function partnerMonthSummaryHtml(p,st,month){
  const mark=st.issueTax<=0
    ?{cls:'even',text:'本月尚未開出去',hint:'8% 還不會跳。收購 2% 已依收到發票合計設定。'}
    :pnlLabel(st.spread);
  const receiptHint=st.issueTax<=0
    ?`本月還沒幫他開出去，8% 還不會跳。${st.receipt>0?'已填空白收據 '+money(st.receipt)+'，等開出去後才拿來折抵。':'空白收據金額另外自己填。'}`
    :(st.breakEven>0
      ?`空白收據至少填 ${money(st.breakEven)} 才能打平，填超過 ${money(st.breakEven)} 才賺錢。`
      :'本月攤提已打平或已賺錢。收據再填就是多賺。');
  return `<div class="pnl-box ${mark.cls}"><span>${esc(p.partner_name)}｜${esc(monthLabel(month))}</span>${mark.text} ${st.issueTax>0?money(Math.abs(Number(st.spread||0))):''}<div class="muted" style="font-weight:600;margin-top:4px">${esc(mark.hint)} ${receiptHint}</div></div>
    <div class="partner-grid">
      <div class="detail-item"><span>本月收到（發票合計）</span><strong>${money(st.inbound)}</strong><div class="muted">未稅 ${money(st.inboundNet)}｜稅金 5% ${money(st.inboundVat)}</div></div>
      <div class="detail-item"><span>收購價稅金 2%</span><strong>${money(st.buyback)}</strong><div class="muted">合計 × 2%，例如 50,000 → 1,000</div></div>
      <div class="detail-item"><span>本月我開出去</span><strong>${money(st.outbound)}</strong><div class="muted">幫他開出去才算 8%</div></div>
      <div class="detail-item"><span>開出去發票稅金 8%</span><strong>${money(st.issueTax)}</strong><div class="muted">例如 40,000 → 3,200｜營業稅 ${money(st.issueVat)} ＋ 所得稅 ${money(st.issueIncome)}</div></div>
      <div class="detail-item"><span>本月攤提</span><strong>${st.issueTax<=0?'尚未開出去':(st.spread>0?'賺 ':'虧 ')+money(Math.abs(st.spread))}</strong><div class="muted">收購 2% ${money(st.buyback)} ＋ 收據 ${money(st.receipt)} − 開出去 8% ${money(st.issueTax)}</div></div>
      <div class="detail-item"><span>收據要填多少才賺錢</span><strong>${st.issueTax<=0?'本月還不用填':(st.breakEven>0?`打平 ${money(st.breakEven)}`:'已賺錢')}</strong><div class="muted">${st.breakEven>0?'多填 1 元才賺錢':'填了就是多賺'}</div></div>
    </div>`;
}
function partnerEntryTableHtml(entries,direction){
  if(!(entries||[]).length) return `<div class="muted">${direction==='inbound'?'這個範圍沒有他開給我的發票。':(direction==='outbound'?'這個範圍沒有我開給他的發票。':'這個月份沒有分錄。')}</div>`;
  const totals=entries.reduce((acc,row)=>{
    if(row.entry_type==='receipt') return acc;
    acc.net+=Number(row.net_amount||0);
    acc.vat+=Number(row.paper_vat||0);
    acc.face+=Number(row.face_amount||0);
    acc.buy+=Number(row.buyback_amount||0);
    acc.issue+=Number(row.outbound_tax||0);
    acc.receipt+=row.entry_type==='receipt'?Number(row.face_amount||0):0;
    return acc;
  },{net:0,vat:0,face:0,buy:0,issue:0,receipt:0});
  entries.forEach(row=>{if(row.entry_type==='receipt') totals.receipt+=Number(row.face_amount||0);});
  return `<div class="table-wrap"><table><thead><tr>
      <th>日期</th><th>分錄</th><th>開立 → 收受</th><th>號碼</th>
      <th class="th-net">未稅</th><th class="th-net">稅金 5%</th><th class="th-net">發票合計</th>
      <th class="th-buy">收購 2%</th><th class="th-issue">開出去 8%</th><th class="th-receipt">收據</th><th>備註</th>
    </tr></thead><tbody>${entries.map(row=>`<tr>
      <td data-label="日期">${esc(row.entry_date||'-')}</td>
      <td data-label="分錄">${esc(partnerEntryLabel(row))}</td>
      <td data-label="開立 → 收受">${['inbound','outbound'].includes(row.entry_type)?`${esc(row.issuer_name||'-')} → ${esc(row.receiver_name||'-')}<div class="muted">${row.entry_type==='outbound'?'我開給他':'他開給我'}</div>`:'-'}</td>
      <td data-label="號碼">${esc(row.invoice_number||'-')}</td>
      <td class="money td-net" data-label="未稅">${row.entry_type==='receipt'?'-':money(row.net_amount||0)}</td>
      <td class="money td-net" data-label="稅金 5%">${row.entry_type==='receipt'?'-':money(row.paper_vat||0)}</td>
      <td class="money td-net" data-label="發票合計">${row.entry_type==='receipt'?'-':money(row.face_amount||0)}</td>
      <td class="money td-buy" data-label="收購 2%">${row.buyback_amount?money(row.buyback_amount):'-'}</td>
      <td class="money td-issue" data-label="開出去 8%">${row.outbound_tax?money(row.outbound_tax):'-'}</td>
      <td class="money td-receipt" data-label="收據">${row.entry_type==='receipt'?money(row.face_amount||0):'-'}${row.receipt_use?`<div class="muted">${row.receipt_use==='reserve'?'留底':'報開銷'}</div>`:''}</td>
      <td data-label="備註">${esc(row.item_note||'')}</td>
    </tr>`).join('')}</tbody>
    <tfoot><tr>
      <td colspan="4" data-label="查詢合計">${direction==='inbound'?'他開給我合計':(direction==='outbound'?'我開給他合計':'本月合計')}</td>
      <td class="money td-net" data-label="未稅">${money(totals.net)}</td>
      <td class="money td-net" data-label="稅金 5%">${money(totals.vat)}</td>
      <td class="money td-net" data-label="發票合計">${money(totals.face)}</td>
      <td class="money td-buy" data-label="收購 2%">${money(totals.buy)}</td>
      <td class="money td-issue" data-label="開出去 8%">${money(totals.issue)}</td>
      <td class="money td-receipt" data-label="收據">${money(totals.receipt)}</td>
      <td></td>
    </tr></tfoot></table></div>`;
}
function applyPartnerMonthView(){
  const view=state.partnerView;
  if(!view) return;
  const month=document.getElementById('partnerMonthFilter')?.value||'all';
  const direction=document.getElementById('partnerDirectionFilter')?.value||'all';
  view.direction=direction;
  const monthEntries=filterPartnerEntries(view.s.entries||[],month,'all');
  const entries=filterPartnerEntries(monthEntries,'all',direction);
  const st=partnerMonthStats(monthEntries,view.s.inbound_tax_rate,view.s.outbound_vat_rate,view.s.outbound_income_tax_rate);
  const p=view.p;
  const summaryMount=document.getElementById('partnerMonthSummary');
  const tableMount=document.getElementById('partnerTableMount');
  if(summaryMount) summaryMount.innerHTML=partnerMonthSummaryHtml(p,st,month);
  if(tableMount) tableMount.innerHTML=partnerEntryTableHtml(entries,direction);
  const rcptFace=document.getElementById('rcptFace');
  const rcptBox=document.getElementById('rcptPreview');
  if(rcptFace && document.activeElement!==rcptFace){
    rcptFace.value=String(st.breakEven>0?st.breakEven:0);
    rcptFace.readOnly=false;
  }
  const refreshRcpt=()=>{
    if(!rcptBox) return;
    const filled=Math.max(0,Math.round(Number(rcptFace?.value||0)));
    const nextSpread=st.issueTax>0?(st.buyback+st.receipt+filled-st.issueTax):filled;
    const next=st.issueTax<=0?{text:'本月尚未開出去',cls:'even'}:pnlLabel(nextSpread);
    rcptBox.innerHTML=st.issueTax<=0
      ?'本月還沒開出去，8% 還不會跳。空白收據金額你自己帶。'
      :`他給你空白收據，金額自己帶。本月開出去 8% ${money(st.issueTax)}，收購 2% ${money(st.buyback)}，已填收據 ${money(st.receipt)}。這張再填 ${money(filled)} 後，攤提變成 <b>${next.text} ${money(Math.abs(nextSpread))}</b>。${st.breakEven>0?`打平至少 ${money(st.breakEven)}，多填才賺錢。`:'填了就是多賺。'}`;
  };
  rcptFace?.removeEventListener('input',view._rcptHandler||(()=>{}));
  view._rcptHandler=refreshRcpt;
  rcptFace?.addEventListener('input',refreshRcpt);
  refreshRcpt();
  const rcptBtn=document.querySelector('[onclick^="savePartnerEntry"][onclick*="receipt"]');
  if(rcptBtn) rcptBtn.disabled=false;
}
function recordOptionLabel(rec){
  const dir=rec.direction==='outbound'?'我們開給他':'他開給我們';
  const src=rec.source==='electronic'?'電子發票':'既有分錄';
  return `${dir}｜${src}｜${rec.entry_date||''}｜${rec.invoice_number||'無號碼'}｜${rec.issuer_name||''} → ${rec.receiver_name||''}｜${money(rec.face_amount)}`;
}
function fillRecordOptions(select,records,direction){
  if(!select) return;
  const list=(records||[]).filter(row=>!direction||row.direction===direction);
  const current=select.value;
  select.innerHTML='<option value="">手動新增一筆</option>'+list.map(row=>`<option value="${esc(row.key)}">${esc(recordOptionLabel(row))}</option>`).join('');
  if([...select.options].some(option=>option.value===current)) select.value=current;
}
function bindPartnerRecordMenu(records,ours,theirs,inRate,vatRate,incomeRate){
  const dirSel=document.getElementById('recDirection');
  const recSel=document.getElementById('recPick');
  const issuer=document.getElementById('recIssuer');
  const receiver=document.getElementById('recReceiver');
  const purpose=document.getElementById('recPurpose');
  if(!dirSel||!recSel) return;
  const applyDirection=()=>{
    const inbound=dirSel.value!=='outbound';
    if(issuer) issuer.value=inbound?(theirs.name||''):(ours.name||'');
    if(receiver) receiver.value=inbound?(ours.name||''):(theirs.name||'');
    if(purpose){
      purpose.innerHTML=inbound
        ? '<option value="purchase">進貨／對開</option><option value="park">代管款</option>'
        : '<option value="reciprocal">對開</option><option value="sale">銷貨開立</option>';
    }
    fillRecordOptions(recSel,records,dirSel.value);
  };
  recSel.addEventListener('change',()=>{
    const rec=(records||[]).find(row=>row.key===recSel.value);
    if(!rec){
      document.getElementById('recSourceId').value='0';
      return;
    }
    dirSel.value=rec.direction||dirSel.value;
    applyDirection();
    recSel.value=rec.key;
    const date=document.getElementById('recDate');
    const number=document.getElementById('recNumber');
    const face=document.getElementById('recFace');
    if(date && rec.entry_date) date.value=rec.entry_date;
    if(number) number.value=rec.invoice_number||'';
    if(face && rec.face_amount){
      face.value=String(rec.face_amount);
      face.dispatchEvent(new Event('input',{bubbles:true}));
    }
    if(issuer) issuer.value=rec.issuer_name||issuer.value;
    if(receiver) receiver.value=rec.receiver_name||receiver.value;
    document.getElementById('recSourceId').value=String(rec.source_invoice_id||0);
  });
  const refreshRec=bindInvoiceConversion('recFace','recPreview',inRate,vatRate,incomeRate,null,()=>dirSel.value);
  dirSel.addEventListener('change',()=>{
    applyDirection();
    refreshRec();
  });
  applyDirection();
  refreshRec();
}
function bindPartnerRatePreview(){
  const inEl=document.getElementById('newPartnerIn');
  const vatEl=document.getElementById('newPartnerVat');
  const incomeEl=document.getElementById('newPartnerIncome');
  const outEl=document.getElementById('newPartnerOutTotal');
  const spreadEl=document.getElementById('newPartnerSpread');
  if(!inEl||!vatEl||!incomeEl||!outEl)return;
  const refresh=()=>{
    const inbound=Number(inEl.value||0);
    const outbound=Number(vatEl.value||0)+Number(incomeEl.value||0);
    outEl.textContent=pct(outbound);
    if(spreadEl) spreadEl.textContent=pct(Math.max(0, outbound-inbound));
  };
  [inEl,vatEl,incomeEl].forEach(el=>el.addEventListener('input',refresh));
  refresh();
}
async function loadPartners(){
  document.getElementById('invoicePane').classList.add('d-none');
  document.getElementById('vendorPane').classList.add('d-none');
  document.getElementById('partnerPane').classList.remove('d-none');
  document.getElementById('exportPane').classList.add('d-none');
  document.getElementById('syncNotice').classList.add('d-none');
  const payload=await api('partners');
  state.partners=payload.rows||[];
  state.partnerLedger=payload.ledger||[];
  state.invoicePoolCount=Number(payload.invoice_pool_count||0);
  document.getElementById('partnerPane').innerHTML=`<h5>進銷項往來</h5>
    <div class="ledger-intro">
      已入帳發票會依統編／公司名稱自動帶入，並用月份分開看。
      發票合計 50,000 → 收購 2%＝1,000。我幫他開出去 40,000 → 8%＝3,200。攤提＝收購 2% ＋ 你填的空白收據 − 開出去 8%。
      空白收據不是 2%，金額你自己帶。例如已填 40,000，就用 40,000 折抵，不會改成收購 2% 的數字。
      例如收到 20,000：未稅 19,048 ＋ 稅金 952；收購 2%＝400。開出去 20,000 才跳出 8%＝1,600；打平還差 1,200，收據至少填 1,200。
      <div class="rate-formula"><span class="rate-chip">1 填收到／開出去</span><span class="rate-chip">2 收購 2% 自動跳</span><span class="rate-chip ok">3 空白收據自己填</span><span class="rate-chip warn">4 開出去才跳 8%</span></div>
    </div>
    <div class="month-bar">
      <input id="partnerSearch" class="partner-search" type="search" value="${esc(state.partnerQuery||'')}" placeholder="搜尋往來戶、發票號碼、未稅、稅金、收購價格、收據金額">
      <strong>開立方向</strong>
      <select id="partnerLedgerDirection">
        <option value="all" ${state.partnerDirection==='all'||!state.partnerDirection?'selected':''}>全部</option>
        <option value="inbound" ${state.partnerDirection==='inbound'?'selected':''}>他開給我</option>
        <option value="outbound" ${state.partnerDirection==='outbound'?'selected':''}>我開給他</option>
      </select>
    </div>
    <div class="partner-form">
      <h6>新增往來戶</h6>
      <label>往來戶名稱<input id="newPartnerName" placeholder="例如：李孟哲"></label>
      <label>公司名稱<input id="newPartnerCompany" placeholder="對方發票上的公司名稱"></label>
      <label>統一編號<input id="newPartnerTax" placeholder="8 碼統編"></label>
      <label>收購進項稅費率 %<input id="newPartnerIn" type="number" step="0.01" value="2"></label>
      <label>銷項營業稅 %<input id="newPartnerVat" type="number" step="0.01" value="5"></label>
      <label>銷項營所稅 %<input id="newPartnerIncome" type="number" step="0.01" value="3"></label>
      <label>銷項稅費合計<strong id="newPartnerOutTotal">8%</strong></label>
      <label>開出去後淨負擔<strong id="newPartnerSpread">6%</strong></label>
      <label class="wide">備註<input id="newPartnerNote" placeholder="例如：對開往來、銷項代管"></label>
      <button class="btn btn-primary" type="button" onclick="savePartner()">建立往來戶</button>
    </div>
    <div id="partnerResultMount"></div>`;
  document.getElementById('partnerSearch')?.addEventListener('input',event=>{
    state.partnerQuery=event.target.value;
    renderPartnerResults();
  });
  document.getElementById('partnerLedgerDirection')?.addEventListener('change',event=>{
    state.partnerDirection=event.target.value||'all';
    renderPartnerResults();
  });
  bindPartnerRatePreview();
  renderPartnerResults();
}
function renderPartnerResults(){
  const mount=document.getElementById('partnerResultMount');
  if(!mount) return;
  const rows=state.partners||[];
  const query=state.partnerQuery||'';
  const visible=rows.filter(row=>{
    const s=row.summary||{};
    return partnerQueryMatch(query,
      [row.partner_name,row.tax_id,row.note],
      [s.inbound_trade,s.inbound_net,s.inbound_paper_vat,s.outbound_reciprocal,s.buyback_amount,s.receipt_due,s.receipt_received,s.receipt_open,s.issue_tax,s.pnl]
    );
  });
  const table=visible.length?`<div class="table-wrap"><table><thead><tr><th>往來戶</th><th>實際發票</th><th>收購價格 2%</th><th>開出去 8%</th><th>空白收據</th><th>對開損益</th><th>待沖帳</th><th></th></tr></thead><tbody>${visible.map(row=>{
    const s=row.summary||{};
    const hasLedger=Number(s.inbound_trade||0)+Number(s.inbound_parked||0)+Number(s.outbound_sale||0)+Number(s.outbound_reciprocal||0)+Number(s.credit_balance||0)+Number(s.receipt_received||0)>0;
    const mark=hasLedger?partnerPnlMark(s):{cls:'even',text:'尚無對開',hint:'先填發票合計'};
    const received=Number(s.receipt_received||0);
    const due=Number(s.receipt_due||0);
    const receiptHint=received>0
      ? `已填 ${money(received)}${Number(s.receipt_expense||0)||Number(s.receipt_reserve||0)?`｜報開銷 ${money(s.receipt_expense)}／留底 ${money(s.receipt_reserve)}`:''}`
      : '還沒填空白收據';
    const unmatched=!hasLedger && Number(state.invoicePoolCount||0)>0;
    return `<tr>
      <td data-label="往來戶"><strong>${esc(row.partner_name)}</strong><div class="muted">${esc(row.company_name||'未填公司名稱')}｜${esc(row.tax_id||'未填統編')}</div>${hasLedger?'':(unmatched?'<div class="empty-ledger">已入帳發票沒對到這個往來戶。請填對方公司名稱或統編，才會自動帶入。</div>':'<div class="empty-ledger">尚無已入帳發票對到這戶。請填公司名稱或統編，或用登錄往來手動填。</div>')}</td>
      <td data-label="實際發票">${money(s.inbound_trade)}<div class="muted">未稅 ${money(s.inbound_net)} ＋ 稅金 ${money(s.inbound_paper_vat)}</div></td>
      <td data-label="收購價格 2%">${money(s.buyback_amount)}<div class="muted">發票合計 × 2%</div></td>
      <td data-label="開出去 8%">${money(s.issue_tax)}<div class="muted">${Number(s.issue_tax||0)?'已依開出去金額設定':'填開出去才會跳'}｜營業稅 ${money(s.issue_vat)} ＋ 所得稅 ${money(s.issue_income)}</div></td>
      <td data-label="空白收據">${money(received)}<div class="muted">${receiptHint}${Number(s.issue_tax||0)>0?(due>0?`｜打平還差 ${money(due)}`:'｜已打平或已賺錢'):''}</div></td>
      <td data-label="對開損益"><span class="badge-status ${mark.cls==='profit'?'status-booked':(mark.cls==='loss'?'status-exception':'status-wait')}">${mark.text}${Number(s.issue_tax||0)>0?' '+money(Math.abs(Number(s.pnl||0))):''}</span></td>
      <td data-label="待沖帳">${money(s.matchable)}</td>
      <td data-label="操作"><button class="btn btn-sm btn-outline-primary" onclick="openPartner(${Number(row.id)})">登錄往來</button></td>
    </tr>`;
  }).join('')}</tbody></table></div>`:'<div class="empty"><i class="bi bi-journal-text"></i>'+(rows.length?'沒有符合搜尋的往來戶。':'尚未建立往來戶。先新增一戶，再輸入發票合計換算收購與收據。')+'</div>';
  const ledger=(state.partnerLedger||[]).filter(row=>partnerQueryMatch(query,
    [row.partner_name,row.tax_id,row.invoice_number,row.item_note,row.issuer_name,row.receiver_name,partnerEntryLabel(row)],
    [row.face_amount,row.net_amount,row.paper_vat,row.buyback_amount,row.receipt_due_amount]
  )).filter(row=>{
    const direction=state.partnerDirection||'all';
    if(direction==='outbound') return row.entry_type==='outbound';
    if(direction==='inbound') return row.entry_type==='inbound';
    return true;
  });
  const ledgerTable=ledger.length?`<h6 class="mt-3">換算明細（可查公司名稱、未稅、稅金、收購 2%、空白收據）</h6><div class="table-wrap"><table><thead><tr><th>日期</th><th>往來戶</th><th>分錄</th><th>開立 → 收受</th><th>號碼</th><th>未稅＋稅金</th><th>收購價格 2%</th><th>空白收據</th><th>備註</th></tr></thead><tbody>${ledger.map(row=>`<tr>
    <td>${esc(row.entry_date||'-')}</td>
    <td><button class="btn btn-sm btn-outline-primary" onclick="openPartner(${Number(row.partner_id)})">${esc(row.partner_name)}</button></td>
    <td>${esc(partnerEntryLabel(row))}</td>
    <td>${['inbound','outbound'].includes(row.entry_type)?`${esc(row.issuer_name||'-')} → ${esc(row.receiver_name||'-')}<div class="muted">${row.entry_type==='outbound'?'我開給他':'他開給我'}</div>`:'-'}</td>
    <td>${esc(row.invoice_number||'-')}</td>
    <td class="money">${row.entry_type==='receipt'?'-':`${money(row.net_amount||0)} ＋ ${money(row.paper_vat||0)}<div class="muted">合計 ${money(row.face_amount)}</div>`}</td>
    <td class="money">${row.buyback_amount?money(row.buyback_amount):'-'}</td>
    <td class="money">${row.entry_type==='receipt'?money(row.face_amount||0):'-'}${row.receipt_use?`<div class="muted">${row.receipt_use==='reserve'?'留底':'報開銷'}</div>`:''}</td>
    <td>${esc(row.item_note||'')}</td>
  </tr>`).join('')}</tbody></table></div>`:((query||(state.partnerDirection&&state.partnerDirection!=='all'))?`<div class="muted mt-3">${query?`沒有符合「${esc(query)}」的發票、收購或收據。`:((state.partnerDirection==='outbound')?'目前沒有我開給他的發票。':'目前沒有他開給我的發票。')}</div>`:'');
  mount.innerHTML=table+ledgerTable;
}
async function savePartner(){
  try{
    await api('partner_save',{method:'POST',body:{
      partner_name:document.getElementById('newPartnerName').value,
      company_name:document.getElementById('newPartnerCompany').value,
      tax_id:document.getElementById('newPartnerTax').value,
      inbound_tax_rate:Number(document.getElementById('newPartnerIn').value||2),
      outbound_vat_rate:Number(document.getElementById('newPartnerVat').value||5),
      outbound_income_tax_rate:Number(document.getElementById('newPartnerIncome').value||3),
      note:document.getElementById('newPartnerNote').value
    }});
    await Promise.all([loadStatus(),loadPartners()]);
  }catch(error){alert(error.message)}
}
async function openPartner(id){
  try{
    const payload=await api('partner_detail',{query:'&id='+id});
    const p=payload.partner;
    const s=payload.summary;
    const today=new Date().toISOString().slice(0,10);
    const ours=payload.our_company||{name:'寶輝電腦',tax_id:''};
    const theirs=payload.partner_company||{name:p.company_name||p.partner_name,tax_id:p.tax_id||''};
    const records=payload.records||[];
    const months=partnerMonthOptions(s.entries||[]);
    const defaultMonth=months[0]||'all';
    state.partnerView={id,p,s,ours,theirs};
    document.getElementById('partnerDetailTitle').textContent=p.partner_name+'｜進銷項分錄';
    document.getElementById('partnerDetailBody').innerHTML=`
      <div id="partnerMonthSummary"></div>
      <div class="ledger-intro">
        未稅、收購 2%、開出去 8% 分開看。發票合計 50,000 → 收購 2%＝1,000；我開出去 40,000 → 8%＝3,200。
        攤提＝收購 2% ＋ 你填的收據 − 開出去 8%。他給空白收據讓你自己帶金額：虧多少就至少填多少才打平，多填才賺錢。
        用月份、開立方向過濾：可只看他開給我，或只看我開給他。
      </div>
      <div class="month-bar">
        <strong>月份</strong>
        <select id="partnerMonthFilter">
          <option value="all">全部月份</option>
          ${months.map(key=>`<option value="${esc(key)}" ${key===defaultMonth?'selected':''}>${esc(monthLabel(key))}</option>`).join('')}
        </select>
        <strong>開立方向</strong>
        <select id="partnerDirectionFilter" title="查詢是我開給他，還是他開給我">
          <option value="all">全部</option>
          <option value="inbound">${esc(partnerDirectionLabel('inbound',ours,theirs))}</option>
          <option value="outbound">${esc(partnerDirectionLabel('outbound',ours,theirs))}</option>
        </select>
      </div>
      <div class="partner-form deal-form">
        <h6>發票紀錄選單</h6>
        <label>方向<select id="recDirection"><option value="inbound">他開給我們</option><option value="outbound">我們開給他</option></select></label>
        <label class="wide">帶入紀錄<select id="recPick"><option value="">手動新增一筆</option></select></label>
        <label>開立公司<input id="recIssuer" readonly></label>
        <label>收受公司<input id="recReceiver" readonly></label>
        <label>日期<input id="recDate" type="date" value="${today}"></label>
        <label>發票號碼<input id="recNumber" placeholder="可從紀錄帶入"></label>
        <label>我收到／開出去多少<input id="recFace" type="number" min="0" step="1" placeholder="例如 20000"></label>
        <label>收購 2%<input id="recBuyback" class="calc-jump" readonly placeholder="填收到才會跳"></label>
        <label>收據折抵 2%<input id="recOffset" class="calc-jump" readonly placeholder="空白收據請到下方自己填"></label>
        <label>開出去 8%<input id="recIssue" class="calc-jump" readonly placeholder="填開出去才會跳"></label>
        <label>會計用途<select id="recPurpose"><option value="purchase">進貨／對開</option><option value="park">代管款</option></select></label>
        <input type="hidden" id="recSourceId" value="0">
        <label class="wide">備註<input id="recNote" placeholder="可空白"></label>
        <div class="convert-preview" id="recPreview"></div>
        <button class="btn btn-primary" type="button" onclick="savePartnerRecord(${id})">登錄這筆紀錄</button>
      </div>
      <div class="partner-form">
        <h6>一次登錄：填收到多少、開出去多少</h6>
        <label>日期<input id="dealDate" type="date" value="${today}"></label>
        <label>進項發票號碼<input id="dealInNumber" placeholder="他開給 ${esc(ours.name)}"></label>
        <label>銷項發票號碼<input id="dealOutNumber" placeholder="${esc(ours.name)} 開給他"></label>
        <label>我收到多少<input id="dealInFace" type="number" min="0" step="1" placeholder="例如 20000"></label>
        <label>我開出去多少<input id="dealOutFace" type="number" min="0" step="1" placeholder="例如 20000"></label>
        <label>收購 2%<input id="dealBuyback" class="calc-jump" readonly placeholder="填收到才會跳"></label>
        <label>空白收據<input id="dealOffset" class="calc-jump" readonly placeholder="請到下方空白收據自己填"></label>
        <label>開出去 8%<input id="dealIssueTax" class="calc-jump" readonly placeholder="填開出去才會跳"></label>
        <label class="wide">他開給我們<input value="${esc(theirs.name||p.partner_name)} → ${esc(ours.name)}" readonly></label>
        <label class="wide">我們開給他<input value="${esc(ours.name)} → ${esc(theirs.name||p.partner_name)}" readonly></label>
        <label class="wide">備註<input id="dealNote" placeholder="可空白"></label>
        <div class="convert-preview" id="dealPreview"></div>
        <button class="btn btn-primary" type="button" onclick="savePartnerDeal(${id})">登錄並自動換算</button>
        <button class="btn btn-outline-primary" type="button" onclick="settlePartner(${id})" ${s.matchable>0?'':'disabled'}>對開沖帳 ${money(s.matchable)}</button>
      </div>
      <div class="partner-form receipt-form">
        <h6>空白收據（金額自己帶，看要填多少才賺錢）</h6>
        <label>日期<input id="rcptDate" type="date" value="${today}"></label>
        <label>收據號碼<input id="rcptNumber" placeholder="可空白"></label>
        <label>我自己帶的金額<input id="rcptFace" type="number" min="0" step="1" value="0" placeholder="打平金額會自動跳"></label>
        <label>用途<select id="rcptUse"><option value="expense_report">報會計事務所開銷</option><option value="reserve">留底（帳面開銷／日後開給別人）</option></select></label>
        <label class="wide">備註<input id="rcptNote" placeholder="可註明這張收據要報帳還是留底"></label>
        <div class="convert-preview" id="rcptPreview"></div>
        <button class="btn btn-warning" type="button" onclick="savePartnerEntry(${id},'receipt')">登錄空白收據</button>
      </div>
      <div class="partner-form">
        <h6>沖減代管款（不開立發票）</h6>
        <label>日期<input id="offDate" type="date" value="${today}"></label>
        <label>沖減金額<input id="offFace" type="number" min="0" step="1" max="${Number(s.credit_balance||0)}"></label>
        <label class="wide">品項／備註<input id="offNote" placeholder="例如：主機零件一批"></label>
        <button class="btn btn-success" type="button" onclick="savePartnerEntry(${id},'offset')" ${s.credit_balance>0?'':'disabled'}>沖減代管餘額</button>
      </div>
      <h6 class="mt-3">分錄明細</h6>
      <div id="partnerTableMount"></div>`;
    bindPartnerRecordMenu(records,ours,theirs,s.inbound_tax_rate,s.outbound_vat_rate,s.outbound_income_tax_rate);
    bindDealConversion(s.inbound_tax_rate,s.outbound_vat_rate,s.outbound_income_tax_rate);
    document.getElementById('partnerMonthFilter')?.addEventListener('change',applyPartnerMonthView);
    document.getElementById('partnerDirectionFilter')?.addEventListener('change',applyPartnerMonthView);
    applyPartnerMonthView();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('partnerDetailModal')).show();
  }catch(error){alert(error.message)}
}
async function savePartnerRecord(partnerId){
  try{
    const direction=document.getElementById('recDirection')?.value||'inbound';
    await api('partner_entry',{method:'POST',body:{
      partner_id:partnerId,
      entry_type:direction,
      purpose:document.getElementById('recPurpose')?.value||(direction==='outbound'?'reciprocal':'purchase'),
      entry_date:document.getElementById('recDate').value,
      invoice_number:document.getElementById('recNumber').value,
      face_amount:Number(document.getElementById('recFace').value||0),
      issuer_name:document.getElementById('recIssuer').value,
      receiver_name:document.getElementById('recReceiver').value,
      source_invoice_id:Number(document.getElementById('recSourceId').value||0),
      item_note:document.getElementById('recNote').value
    }});
    await openPartner(partnerId);
    await Promise.all([loadStatus(),loadPartners()]);
  }catch(error){alert(error.message)}
}
async function savePartnerDeal(partnerId){
  try{
    const payload=await api('partner_entry',{method:'POST',body:{
      partner_id:partnerId,
      entry_type:'deal',
      entry_date:document.getElementById('dealDate').value,
      invoice_number:document.getElementById('dealInNumber').value,
      outbound_invoice_number:document.getElementById('dealOutNumber').value,
      inbound_face_amount:Number(document.getElementById('dealInFace').value||0),
      outbound_face_amount:Number(document.getElementById('dealOutFace').value||0),
      item_note:document.getElementById('dealNote').value
    }});
    alert(`已登錄收到 ${payload.inbound_face_amount||0}、開出去 ${payload.outbound_face_amount||0}。收購 2% 已設定 ${payload.buyback_amount||0} 元；開出去 8% ${payload.issue_tax||0} 元。空白收據另外自己填。`);
    await openPartner(partnerId);
    await Promise.all([loadStatus(),loadPartners()]);
  }catch(error){alert(error.message)}
}
async function savePartnerEntry(partnerId,entryType){
  try{
    const body={partner_id:partnerId,entry_type:entryType};
    if(entryType==='inbound'){
      body.entry_date=document.getElementById('inDate').value;
      body.invoice_number=document.getElementById('inNumber').value;
      body.face_amount=Number(document.getElementById('inFace').value||0);
      body.purpose=document.getElementById('inPurpose').value;
      body.item_note=document.getElementById('inNote').value;
    }else if(entryType==='outbound'){
      body.entry_date=document.getElementById('outDate').value;
      body.invoice_number=document.getElementById('outNumber').value;
      body.face_amount=Number(document.getElementById('outFace').value||0);
      body.purpose=document.getElementById('outPurpose').value;
      body.item_note=document.getElementById('outNote').value;
    }else if(entryType==='receipt'){
      body.entry_date=document.getElementById('rcptDate').value;
      body.invoice_number=document.getElementById('rcptNumber').value;
      body.face_amount=Number(document.getElementById('rcptFace').value||0);
      body.purpose=document.getElementById('rcptUse')?.value||'expense_report';
      body.item_note=document.getElementById('rcptNote').value;
    }else{
      body.entry_date=document.getElementById('offDate').value;
      body.face_amount=Number(document.getElementById('offFace').value||0);
      body.item_note=document.getElementById('offNote').value;
    }
    await api('partner_entry',{method:'POST',body});
    await openPartner(partnerId);
    await Promise.all([loadStatus(),loadPartners()]);
  }catch(error){alert(error.message)}
}
async function settlePartner(partnerId){
  try{
    const payload=await api('partner_settle',{method:'POST',body:{partner_id:partnerId}});
    alert(`已沖帳面額 ${payload.settle_amount} 元。收購 2%＝${payload.buyback_amount||0} 元。開出去 8% 已在銷項認列，不是跟他收 8%。空白收據另計。`);
    await openPartner(partnerId);
    await Promise.all([loadStatus(),loadPartners()]);
  }catch(error){alert(error.message)}
}
async function loadVendors(){
  document.getElementById('invoicePane').classList.add('d-none');
  document.getElementById('vendorPane').classList.remove('d-none');
  document.getElementById('partnerPane').classList.add('d-none');
  document.getElementById('exportPane').classList.add('d-none');
  document.getElementById('syncNotice').classList.add('d-none');
  const payload=await api('vendors');
  document.getElementById('vendorPane').innerHTML='<h5>供應商解析規則</h5><div class="subtext mb-2">正式憑證與內部備存文件依供應商規則分流。</div>'+payload.rows.map(row=>`<div class="vendor-row"><strong>${esc(row.vendor_name)}</strong><span>${esc(row.seller_tax_id||'未指定統編')}</span><span>${esc(row.sender_pattern||'未指定寄件信箱')}</span><span>${esc(row.subject_pattern||'未指定主旨')}</span><span>${esc(row.parser_class)}</span><span class="badge-status ${row.enabled?'status-booked':'status-noncompany'}">${row.enabled?'啟用':'停用'}</span></div>`).join('');
}
async function loadExports(){
  document.getElementById('invoicePane').classList.add('d-none');
  document.getElementById('vendorPane').classList.add('d-none');
  document.getElementById('partnerPane').classList.add('d-none');
  document.getElementById('exportPane').classList.remove('d-none');
  document.getElementById('syncNotice').classList.add('d-none');
  if(!state.capabilities.includes('invoice_export')){document.getElementById('exportPane').innerHTML='<div class="empty">沒有匯出權限</div>';return}
  const payload=await api('exports');
  document.getElementById('exportPane').innerHTML='<h5>匯出紀錄</h5>'+(payload.rows.length?payload.rows.map(row=>`<div class="vendor-row"><strong>${esc(row.export_format)}</strong><span>${esc(row.record_count)} 筆</span><span>${esc(row.exported_by)}</span><span>${esc(row.exported_at)}</span></div>`).join(''):'<div class="empty"><i class="bi bi-file-earmark-arrow-down"></i>目前沒有匯出紀錄</div>');
}
document.querySelectorAll('.tab').forEach(button=>button.addEventListener('click',()=>{
  document.querySelectorAll('.tab').forEach(x=>x.classList.remove('active'));
  button.classList.add('active');
  state.tab=button.dataset.tab;
  loadRows().catch(error=>alert(error.message));
}));
document.getElementById('invoiceSearch').addEventListener('input',renderRows);
document.getElementById('printFilter').addEventListener('change',renderRows);
document.getElementById('poFilter').addEventListener('change',renderRows);
document.getElementById('vendorFilter').addEventListener('change',renderRows);
document.getElementById('jieyuanPoMatchButton').addEventListener('click',async()=>{
  try{
    const payload=await api('jieyuan_po_match',{method:'POST',body:{}});
    const waiting=payload.unmatched_doc_list&&payload.unmatched_doc_list.length
      ? '\n尚未對上發票的進貨單：'+payload.unmatched_doc_list.map(d=>`${d.document_no} ${d.document_date} ${d.total_amount}元`).join('、')
      : '';
    alert(`捷元進貨比對完成：從 ${payload.from} 起，進貨單 ${payload.purchase_docs} 張（其中 ${payload.unmatched_docs||0} 張還沒對到發票），發票已對上 ${payload.matched}、尚未對上 ${payload.unmatched}。8/18 之前不比對。${waiting}`);
    await loadRows();
  }catch(error){alert(error.message)}
});
document.getElementById('selectAllVisible').addEventListener('change',event=>{
  const rows=filteredRows().filter(row=>row.has_pdf);
  if(event.target.checked) rows.forEach(row=>state.selected.add(Number(row.id)));
  else rows.forEach(row=>state.selected.delete(Number(row.id)));
  renderRows();
});
document.getElementById('batchPrintButton').addEventListener('click',()=>batchPrint(false));
document.getElementById('vendorPrintButton').addEventListener('click',()=>batchPrint('vendor'));
document.getElementById('monthPrintButton').addEventListener('click',()=>batchPrint('month'));
document.getElementById('unmarkPrintButton').addEventListener('click',()=>batchUnmark());
document.getElementById('vendorPickAll').addEventListener('click',()=>setVendorPickAll(true));
document.getElementById('vendorPickNone').addEventListener('click',()=>setVendorPickAll(false));
document.getElementById('vendorPrintConfirm').addEventListener('click',()=>confirmVendorPrint());
document.getElementById('monthPickAll').addEventListener('click',()=>setMonthPickAll(true));
document.getElementById('monthPickNone').addEventListener('click',()=>setMonthPickAll(false));
document.getElementById('monthPrintConfirm').addEventListener('click',()=>confirmMonthPrint());
document.getElementById('gmailUpload').addEventListener('change',event=>ingestGmail(event.target.files));
document.getElementById('scanGmailButton').addEventListener('click',()=>ingestGmail([]));
document.getElementById('connectGmailButton').addEventListener('click',()=>connectGmail());
document.getElementById('syncGmailButton').addEventListener('click',()=>syncGmailInbox());
document.getElementById('gmailSaveClient').addEventListener('click',()=>saveGmailClient());
document.getElementById('gmailDisconnect').addEventListener('click',()=>disconnectGmail());
window.addEventListener('message',event=>{
  if(event.origin!==location.origin) return;
  if(!event.data||event.data.type!=='baohui-gmail-oauth') return;
  Promise.all([loadStatus(),loadRows()]).catch(error=>alert(error.message));
});
document.getElementById('showUnprinted').addEventListener('click',()=>{
  state.tab='unprinted';
  document.querySelectorAll('.tab').forEach(x=>x.classList.remove('active'));
  document.getElementById('printFilter').value='not_printed';
  loadRows().catch(error=>alert(error.message));
});
document.getElementById('refreshButton').addEventListener('click',()=>Promise.all([loadStatus(),loadRows()]).catch(error=>alert(error.message)));
Promise.all([loadStatus(),loadRows()]).catch(error=>{document.getElementById('emptyState').innerHTML='<i class="bi bi-exclamation-triangle"></i>'+esc(error.message)});
</script>
</body></html>
