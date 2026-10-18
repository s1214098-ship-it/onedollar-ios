<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function money($v): string { return number_format((float)($v ?? 0)) . ' 元'; }
function filled($v): bool { return trim((string)($v ?? '')) !== ''; }
function read_data(): array {
    $db = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'baohui.sqlite';
    if (!is_file($db)) return [];
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $stmt = $pdo->query('SELECT json_data FROM app_data WHERE id = 1');
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    if (!is_array($row)) return [];
    $data = json_decode((string)($row['json_data'] ?? ''), true);
    return is_array($data) ? $data : [];
}
function item_calc(array $it): array {
    $line = (float)($it['qty'] ?? 0) * (float)($it['price'] ?? 0);
    $mode = (string)($it['taxMode'] ?? 'none');
    $tax = 0.0; $total = $line;
    if ($mode === 'external') { $tax = round($line * 0.05); $total = $line + $tax; }
    if ($mode === 'included') { $tax = round($line - ($line / 1.05)); $total = $line; }
    return ['line'=>$line, 'tax'=>$tax, 'total'=>$total, 'mode'=>$mode];
}
function tax_label(string $mode): string { return $mode === 'external' ? '外加 5%' : ($mode === 'included' ? '內含 5%' : '稅金另計'); }
function deposit_status_label($status): string { return $status === 'paid' ? '要收訂金（已收）' : ($status === 'none' ? '不收訂金' : '要收訂金（未收）'); }
function is_optional_item(array $it): bool {
    if (!empty($it['optional'])) return true;
    $text = (string)($it['name'] ?? '') . (string)($it['spec'] ?? '');
    return mb_strpos($text, '可加可不加') !== false;
}
function is_computer_quote(array $items): bool {
    $keywords = ['電腦','主機','組裝','windows','microsoft','cpu','處理器','主機板','顯示卡','記憶體','硬碟','ssd','電源供應器','機殼','散熱','intel','amd','nvidia','geforce','radeon'];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $text = mb_strtolower(implode(' ', [
            (string)($item['name'] ?? ''),
            (string)($item['brand'] ?? ''),
            (string)($item['spec'] ?? ''),
        ]), 'UTF-8');
        foreach ($keywords as $keyword) {
            if (mb_strpos($text, mb_strtolower($keyword, 'UTF-8')) !== false) return true;
        }
    }
    return false;
}
function calc(array $q): array {
    $sub = 0; $externalTax = 0; $includedTax = 0; $itemTotal = 0;
    $hasExternal = false; $hasIncluded = false; $hasNone = false;
    $optionalTotal = 0.0;
    foreach (($q['items'] ?? []) as $it) {
        if (is_array($it)) {
            $c = item_calc($it);
            if (is_optional_item($it)) { $optionalTotal += $c['total']; continue; }
            $sub += $c['line'];
            $itemTotal += $c['total'];
            if ($c['mode'] === 'external') { $hasExternal = true; $externalTax += $c['tax']; }
            elseif ($c['mode'] === 'included') { $hasIncluded = true; $includedTax += $c['tax']; }
            else { $hasNone = true; }
        }
    }
    $discount = (float)($q['discount'] ?? 0);
    $shipping = (float)($q['shipping'] ?? 0);
    $total = max(0, $itemTotal + $shipping - $discount);
    $taxNotes = [];
    if ($hasIncluded) $taxNotes[] = '稅金內含 5%';
    if ($hasExternal) $taxNotes[] = '外加 5% 已列入總計';
    if ($hasNone) $taxNotes[] = '稅金另計';
    $depositStatus = (string)($q['depositStatus'] ?? 'unpaid');
    $depositPercent = $depositStatus === 'none' ? 0 : (float)($q['depositPercent'] ?? 30);
    $depositDue = $depositStatus === 'none' ? 0 : round($total * $depositPercent / 100);
    $depositPaid = $depositStatus === 'paid' ? (float)($q['depositReceived'] ?? $depositDue) : (float)($q['depositReceived'] ?? 0);
    if ($depositStatus === 'none') $depositPaid = 0;
    $depositUnpaid = max(0, $depositDue - $depositPaid);
    $penaltyPercent = (float)($q['penaltyPercent'] ?? 30);
    $penalty = $penaltyPercent > 0 ? round($total * $penaltyPercent / 100) : 0;
    return ['sub'=>$sub, 'tax'=>$externalTax, 'externalTax'=>$externalTax, 'includedTax'=>$includedTax, 'hasExternal'=>$hasExternal, 'hasIncluded'=>$hasIncluded, 'hasNone'=>$hasNone, 'taxNote'=>implode(' / ', $taxNotes), 'itemTotal'=>$itemTotal, 'optionalTotal'=>$optionalTotal, 'grandTotal'=>$total + $optionalTotal, 'discount'=>$discount, 'shipping'=>$shipping, 'total'=>$total, 'deposit'=>$depositDue, 'depositDue'=>$depositDue, 'depositPaid'=>$depositPaid, 'depositUnpaid'=>$depositUnpaid, 'depositStatus'=>$depositStatus, 'balance'=>max(0, $total - $depositPaid), 'penaltyPercent'=>$penaltyPercent, 'penalty'=>$penalty];
}
$token = trim((string)($_GET['token'] ?? ''));
$data = read_data();
$quote = null;
foreach (($data['quotations'] ?? []) as $q) {
    if (is_array($q) && hash_equals((string)($q['token'] ?? ''), $token)) { $quote = $q; break; }
}
if (!$quote) { http_response_code(404); echo '<!doctype html><meta charset="utf-8"><title>估價單不存在</title><body style="font-family:Arial, sans-serif;padding:32px"><h2>估價單不存在或連結已失效</h2></body>'; exit; }
$officialNo = trim((string)(($quote['officialNo'] ?? '') !== '' ? $quote['officialNo'] : ($quote['deliveryNo'] ?? '')));
$deliveryOrder = null;
$quoteId = trim((string)($quote['id'] ?? ''));
$quoteToken = trim((string)($quote['token'] ?? ''));
foreach ((array)($data['deliveryOrders'] ?? []) as $o) {
    if (!is_array($o)) continue;
    if ($quoteId !== '' && (string)($o['quoteId'] ?? '') === $quoteId) { $deliveryOrder = $o; break; }
    if ($quoteToken !== '' && (string)($o['quoteToken'] ?? '') === $quoteToken) { $deliveryOrder = $o; break; }
    if ($officialNo !== '' && ((string)($o['no'] ?? '') === $officialNo || (string)($o['officialNo'] ?? '') === $officialNo)) { $deliveryOrder = $o; break; }
}
$convertedToDelivery = is_array($deliveryOrder) || $officialNo !== '';
$keepQuotePrint = isset($_GET['quote_print']);
$print = isset($_GET['print']);
$deliveryToken = is_array($deliveryOrder) ? trim((string)($deliveryOrder['token'] ?? '')) : '';
if ($convertedToDelivery && $print && !$keepQuotePrint) {
    $params = ['print' => '1'];
    if ($deliveryToken !== '') $params['token'] = $deliveryToken;
    elseif ($quoteToken !== '') $params['quote_token'] = $quoteToken;
    elseif ($quoteId !== '') $params['quote_id'] = $quoteId;
    elseif ($officialNo !== '') $params['no'] = $officialNo;
    header('Location: delivery-view.php?' . http_build_query($params));
    exit;
}
$deliveryPrintQuery = [];
if ($deliveryToken !== '') $deliveryPrintQuery['token'] = $deliveryToken;
elseif ($quoteToken !== '') $deliveryPrintQuery['quote_token'] = $quoteToken;
elseif ($quoteId !== '') $deliveryPrintQuery['quote_id'] = $quoteId;
elseif ($officialNo !== '') $deliveryPrintQuery['no'] = $officialNo;
$deliveryViewUrl = $deliveryPrintQuery ? ('delivery-view.php?' . http_build_query($deliveryPrintQuery)) : '';
$deliveryPrintUrl = $deliveryViewUrl !== '' ? ($deliveryViewUrl . (strpos($deliveryViewUrl, '?') === false ? '?' : '&') . 'print=1') : '';
$c = calc($quote);
$sealImage = (string)($quote['sealImage'] ?? '');
if (!filled($sealImage)) { $sealImage = (string)(($data['quoteSettings'] ?? [])['companySealImage'] ?? ''); }
$sealSrc = $sealImage;
if (filled($sealSrc) && !preg_match('/^https?:\/\//i', $sealSrc)) {
    $sealFile = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sealSrc);
    if (is_file($sealFile)) {
        $sealSrc .= (strpos($sealSrc, '?') === false ? '?' : '&') . 'v=' . filemtime($sealFile);
    }
}
$items = is_array($quote['items'] ?? null) ? $quote['items'] : [];
$computerServiceTerms = empty($quote['hideComputerTerms']) && is_computer_quote($items);
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h($quote['headerTitle'] ?? '寶輝科技正式估價單')?> <?=h($quote['no'] ?? '')?></title>
<style>
body{margin:0;background:#f3f6fb;color:#122033;font-family:"Noto Sans TC","Microsoft JhengHei",Arial,sans-serif}.sheet{max-width:980px;margin:28px auto;background:#fff;border:1px solid #d8e0ea;border-radius:12px;padding:28px;box-shadow:0 18px 40px rgba(15,23,42,.08)}.top{display:flex;justify-content:space-between;gap:16px;border-bottom:3px solid #1d4ed8;padding-bottom:16px;margin-bottom:20px}.brand h1{margin:0;font-size:30px}.brand p,.meta p{margin:5px 0;color:#526176}.meta{text-align:right}.info{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:18px 0}.box{border:1px solid #d8e0ea;background:#f8fafc;border-radius:8px;padding:12px;line-height:1.7}table{width:100%;border-collapse:collapse;margin-top:12px}th,td{border:1px solid #d8e0ea;padding:9px;text-align:left;vertical-align:top}th{background:#eaf1fb}.right{text-align:right}.total{font-size:24px;font-weight:800;color:#0f5132}.note,.contract{margin-top:18px;padding:14px;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;white-space:pre-wrap}.contract{background:#f8fafc;border-color:#cbd5e1}.sign{margin-top:24px}.sign-table{width:100%;border-collapse:collapse;margin-top:0}.sign-table th{background:#eaf1fb;text-align:center;font-size:16px}.sign-table td{height:156px;vertical-align:top}.sign-line{height:36px;border-bottom:1px solid #475569;margin:10px 0}.seal-box{height:40mm;border:1px dashed #94a3b8;border-radius:8px;display:flex;align-items:center;justify-content:center;background:#fff;margin:8px 0;padding:0}.seal{width:60mm;height:28mm;max-width:60mm;max-height:28mm;object-fit:contain;display:block;border:0!important;outline:0!important;background:transparent!important;box-shadow:none!important}.seal-placeholder{color:#64748b;font-size:14px}.estimate-items{table-layout:fixed}.estimate-items th{white-space:nowrap;text-align:center}.estimate-items .col-brand{width:10%}.estimate-items .col-item{width:27%}.estimate-items .col-qty{width:7%}.estimate-items .col-price{width:12%}.estimate-items .col-warranty{width:24%}.estimate-items .col-tax{width:8%}.estimate-items .col-total{width:12%}.brand-cell,.tax-cell,.nowrap{white-space:nowrap}.item-cell{line-height:1.35}.spec-line{margin-top:3px;color:#475569;font-size:.92em}.warranty-cell{font-size:.93em;line-height:1.35}.optional-row td{color:#b91c1c}.optional-row .spec-line{color:#b91c1c}.actions{max-width:980px;margin:18px auto;text-align:right}.btn{background:#2563eb;color:#fff;border:0;border-radius:8px;padding:10px 16px;font-size:16px;cursor:pointer}.muted{color:#64748b}.company{margin-top:18px;padding-top:12px;border-top:1px solid #d8e0ea;white-space:pre-wrap;color:#334155}
@media print{
  @page{size:A4 portrait;margin:8mm}
  html,body{width:210mm;background:#fff!important;font-size:11px;line-height:1.35;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .actions{display:none!important}
  .sheet{box-shadow:none!important;border:0!important;margin:0!important;max-width:none!important;width:auto!important;border-radius:0!important;padding:0!important}
  .top{gap:10px;border-bottom:2px solid #1d4ed8;padding-bottom:10px;margin-bottom:12px}
  .brand h1{font-size:22px;line-height:1.2}
  .brand p,.meta p{margin:3px 0}
  .info{gap:8px;margin:10px 0}
  .box{padding:8px 10px;border-radius:6px;line-height:1.45}
  table{margin-top:8px;font-size:11px;page-break-inside:auto}
  th,td{padding:6px 8px;line-height:1.35;word-break:normal}
  tr{page-break-inside:avoid;break-inside:avoid}
  th{background:#eaf1fb!important}
  .total{font-size:18px}
  .note,.contract{margin-top:10px;padding:8px 10px;border-radius:6px;line-height:1.4;page-break-inside:avoid;break-inside:avoid}
  .sign{margin-top:14px;padding-top:8px;page-break-inside:avoid;break-inside:avoid}
  .sign-table th{font-size:13px;padding:6px}.sign-table td{height:90px;padding:8px}
  .sign-line{height:22px;margin:6px 0}
  .sign p{margin:3px 0}
  .seal-box{height:34mm;margin:6px 0;border-radius:6px;padding:0}
  .company{margin-top:10px;padding-top:8px;line-height:1.4;page-break-inside:avoid;break-inside:avoid}
  .seal{width:60mm;height:28mm;max-width:60mm;max-height:28mm;object-fit:contain;border:0!important;outline:0!important;background:transparent!important;box-shadow:none!important}.seal-placeholder{font-size:12px}body.paper-print .seal-box{display:none!important}.estimate-items th,.estimate-items td{vertical-align:middle}.estimate-items th{white-space:nowrap}.estimate-items .brand-cell,.estimate-items .tax-cell,.estimate-items .nowrap{white-space:nowrap}.estimate-items .warranty-cell{font-size:11px}.item-cell{line-height:1.35}.spec-line{margin-top:2px}
}
</style>
<script>
function printPaperQuote(){
  document.body.classList.add("paper-print");
  window.print();
}
function printElectronicQuote(){
  document.body.classList.remove("paper-print");
  window.print();
}
</script>
<?php if ($print): ?><script>window.addEventListener('load',function(){setTimeout(function(){printPaperQuote();},500);});</script><?php endif; ?>
</head>
<body class="<?= $print ? 'paper-print' : '' ?>">
<div class="actions">
  <?php if ($convertedToDelivery && $deliveryPrintUrl !== '' && !$keepQuotePrint): ?>
    <a class="btn" style="background:#0f766e;text-decoration:none" href="<?=h($deliveryPrintUrl)?>">紙本列印出貨單</a>
    <a class="btn" style="background:#115e59;text-decoration:none" href="<?=h($deliveryViewUrl)?>">先看出貨單再列印</a>
    <button class="btn" onclick="printPaperQuote()">仍列印估價單（不含發票章）</button>
    <button class="btn" style="background:#1d4ed8" onclick="printElectronicQuote()">估價單電子檔（含發票章）</button>
  <?php else: ?>
    <button class="btn" onclick="printPaperQuote()">紙本列印（不含發票章）</button>
    <button class="btn" style="background:#0f766e" onclick="printElectronicQuote()">電子檔 / PDF（含發票章）</button>
    <?php if ($convertedToDelivery && $deliveryPrintUrl !== ''): ?><a class="btn" style="background:#0f766e;text-decoration:none" href="<?=h($deliveryPrintUrl)?>">改列印出貨單</a><?php endif; ?>
  <?php endif; ?>
</div>
<main class="sheet">
  <section class="top">
    <div class="brand"><h1><?=h($quote['headerTitle'] ?? '寶輝科技正式估價單')?></h1><p>報價內容以本單明細為準；簽名後雙方視同確認本估價單內容。</p><?php if ($convertedToDelivery): ?><p class="muted">已轉出現貨出貨單 <?=h($officialNo !== '' ? $officialNo : (is_array($deliveryOrder) ? (string)($deliveryOrder['no'] ?? '') : ''))?>，按綠色按鈕會列印出貨單紙張。</p><?php endif; ?></div>
    <div class="meta"><p>估價單號：<b><?=h($quote['no'] ?? '')?></b></p><?php if (filled($officialNo) || filled($quote['officialNo'] ?? '')): ?><p>正式 / 出貨單號：<b><?=h($officialNo !== '' ? $officialNo : ($quote['officialNo'] ?? ''))?></b></p><?php endif; ?><p>開單日期：<?=h($quote['date'] ?? '')?></p><p>有效天數：<?=h($quote['validDays'] ?? '7')?> 天</p><?php if (filled($quote['approvedAt'] ?? '')): ?><p class="muted">已核准生效：<?=h($quote['approvedAt'])?><br>核准人：<?=h($quote['approvedBy'] ?? '')?></p><?php endif; ?></div>
  </section>
  <section class="info">
    <div class="box"><b>客戶資料</b><br>
      <?php if (filled($quote['customerName'] ?? $quote['customer'] ?? '')): ?>客戶名稱：<?=h($quote['customerName'] ?? $quote['customer'])?><br><?php endif; ?>
      <?php if (filled($quote['title'] ?? '')): ?>抬頭：<?=h($quote['title'])?><br><?php endif; ?>
      <?php if (filled($quote['contact'] ?? '')): ?>聯絡人：<?=h($quote['contact'])?><br><?php endif; ?>
      <?php if (filled($quote['phone'] ?? '')): ?>電話：<?=h($quote['phone'])?><br><?php endif; ?>
      <?php if (filled($quote['fax'] ?? '')): ?>傳真：<?=h($quote['fax'])?><br><?php endif; ?>
      <?php if (filled($quote['email'] ?? '')): ?>信箱：<?=h($quote['email'])?><br><?php endif; ?>
      <?php if (filled($quote['address'] ?? '')): ?>地址：<?=h($quote['address'])?><?php endif; ?>
    </div>
    <div class="box"><b>交付 / 保固</b><br>
      <?php if (filled($quote['completionDate'] ?? '')): ?>預計完成日期：<?=h($quote['completionDate'])?><br><?php endif; ?>
      <?php if (filled($quote['balanceDueDate'] ?? '')): ?>尾款期限：<?=h($quote['balanceDueDate'])?><br><?php endif; ?>
      <?php if (filled($quote['warranty'] ?? '')): ?>保固模式：<?=h($quote['warranty'])?><br><?php endif; ?>
      訂金收取：<?=h(deposit_status_label($c['depositStatus']))?><br>
      <?php if (($quote['depositStatus'] ?? 'unpaid') !== 'none'): ?>
        訂金比例：<?=h($quote['depositPercent'] ?? 30)?>%，訂金應收 <?=money($c['depositDue'])?>，已收 <?=money($c['depositPaid'])?>，未收 <?=money($c['depositUnpaid'])?><br>
        手寫確認：□ 已收訂金　□ 未收訂金　訂金實收：__________ 元 / 經手：__________
      <?php endif; ?>
    </div>
  </section>
  <table class="estimate-items"><colgroup><col class="col-brand"><col class="col-item"><col class="col-qty"><col class="col-price"><col class="col-warranty"><col class="col-tax"><col class="col-total"></colgroup><thead><tr><th>廠牌</th><th>品項 / 規格</th><th class="right">數量</th><th class="right">單價</th><th>保固</th><th>稅別</th><th class="right">小計</th></tr></thead><tbody>
  <?php foreach ($items as $it): if (!is_array($it)) continue; $ic=item_calc($it); ?>
    <tr<?= is_optional_item($it) ? ' class="optional-row"' : '' ?>><td class="brand-cell"><?=h($it['brand'] ?? '')?></td><td class="item-cell"><b><?=h($it['name'] ?? '')?></b><?php if (filled($it['spec'] ?? '')): ?><div class="spec-line"><?=h($it['spec'])?></div><?php endif; ?></td><td class="right nowrap"><?=h($it['qty'] ?? '')?></td><td class="right nowrap"><?=money($it['price'] ?? 0)?></td><td class="warranty-cell"><?=h($it['warranty'] ?? '')?></td><td class="tax-cell"><?=h(tax_label((string)($it['taxMode'] ?? 'none')))?></td><td class="right nowrap"><?=money($ic['line'])?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <table class="quote-summary"><tbody>
    <tr><th>品項合計</th><td class="right"><?=money($c['sub'])?></td></tr>
    <?php if ($c['hasExternal']): ?><tr><th>稅金外加 5%</th><td class="right"><?=money($c['externalTax'])?></td></tr><?php endif; ?>
    <?php if (filled($c['taxNote'] ?? '')): ?><tr><th>稅金說明</th><td class="right"><?=h($c['taxNote'])?></td></tr><?php endif; ?>
    <?php if ($c['shipping'] > 0): ?><tr><th>運費</th><td class="right"><?=money($c['shipping'])?></td></tr><?php endif; ?>
    <?php if ($c['discount'] > 0): ?><tr><th>折扣</th><td class="right">- <?=money($c['discount'])?></td></tr><?php endif; ?>
    <?php if (($c['optionalTotal'] ?? 0) > 0): ?><tr><th>選購合計（可加可不加）</th><td class="right"><?=money($c['optionalTotal'])?></td></tr>
    <tr><th>含選購總計</th><td class="right"><?=money($c['grandTotal'])?></td></tr><?php endif; ?>
    <tr><th>總計</th><td class="right total"><?=money($c['total'])?></td></tr>
    <?php if (($quote['depositStatus'] ?? 'unpaid') !== 'none'): ?>
      <tr><th>訂金收取</th><td class="right"><?=h(deposit_status_label($c['depositStatus']))?></td></tr>
      <tr><th>訂金應收（<?=h($quote['depositPercent'] ?? 30)?>%）</th><td class="right"><?=money($c['depositDue'])?></td></tr>
      <tr><th>已收 / 未收訂金</th><td class="right">已收 <?=money($c['depositPaid'])?>｜未收 <?=money($c['depositUnpaid'])?></td></tr>
      <tr><th>列印手寫訂金確認</th><td>□ 已收訂金　□ 未收訂金　訂金實收：__________ 元 / 經手：__________</td></tr>
      <tr><th>剩餘應收</th><td class="right"><?=money($c['balance'])?></td></tr>
    <?php endif; ?>
  </tbody></table>
  <?php if (($quote['penaltyPercent'] ?? 30) > 0): ?><div class="note"><b>特別提醒</b><br>如有上述違約情形，會面臨之罰款金額如下：總計 <?=money($c['total'])?> × <?=h($quote['penaltyPercent'] ?? 30)?>% = <?=money($c['penalty'])?></div><?php endif; ?>
  <?php if (strpos((string)($quote['note'] ?? '') . (string)($quote['contract'] ?? ''), '估價單生效與訂金規則') === false && strpos((string)($quote['note'] ?? '') . (string)($quote['contract'] ?? ''), '本估價單經客戶口頭確認') === false): ?>
  <div class="note"><b>估價單生效與訂金規則</b><br>本估價單經客戶口頭確認、書面確認，或支付訂金／現金並經本公司確認收款後，即視同同意本估價單內容；本估價單轉為正式出貨／施工依據並生效，雙方應依本單品項、金額、付款、交付、保固及違約條款履行。若未填寫或未實際收取訂金，視同未收訂金。</div>
  <?php endif; ?>
  <div class="note"><b>價格與供貨聲明</b><br>本估價單價格僅為開立當下之參考價，實際售價與供貨狀況以寶輝科技最終確認為準；本公司保留調整售價、接受訂單及缺貨取消之最終權利。</div>
  <?php if ($computerServiceTerms): ?><div class="contract"><b>電腦組裝、作業系統與硬體維護說明</b><br>1. 電腦組裝費用為每台 1,500 元，屬一次性整機組裝服務，不含作業系統授權及其他軟體安裝。<br>2. 如需安裝 Microsoft Windows 作業系統，須另購合法授權版本；實際版本與金額以本估價單品項為準。<br>3. 其他軟體安裝工資每次 800 元，軟體授權、訂閱或購買費用另計。<br>4. 第一年硬體代送費 800 元：銷售日起一年內，非人為故障之硬體代送處理。人為損壞、外力、進水、燒毀、零件更換、原廠維修及資料救援等費用另計。<br>5. 第二年硬體維護 800 元、第三年硬體維護 500 元，均為選購項目，可加可不加。硬體維護不等同延長原廠保固；零組件保固期限與範圍仍依本估價單、產品原廠及供應商規定辦理。</div><?php endif; ?>
  <?php if (filled($quote['note'] ?? '')): ?><div class="note"><b>備註</b><br><?=h($quote['note'])?></div><?php endif; ?>
  <?php if (filled($quote['contract'] ?? '')): ?><div class="contract"><b>合約內容</b><br><?=h($quote['contract'])?></div><?php endif; ?>
  <section class="sign">
    <table class="sign-table">
      <tr>
        <th>客戶簽收欄</th>
        <th>寶輝科技用印欄</th>
      </tr>
      <tr>
        <td>
          <p>簽收確認：</p>
          <div class="sign-line"></div>
          <p>單位 / 姓名：</p>
          <p>日期：</p>
        </td>
        <td>
          <p>經辦確認：</p>
          <div class="sign-line"></div>
          <div class="seal-box">
            <?php if (filled($sealImage)): ?><img class="seal" src="<?=h($sealSrc)?>" alt="寶輝科技公司章"><?php else: ?><span class="seal-placeholder">公司章 / 發票章用印欄</span><?php endif; ?>
          </div>
          <p>經辦：　　　　　日期：</p>
        </td>
      </tr>
    </table>
  </section>
  <?php if (filled($quote['fileSavedAt'] ?? '') || filled($quote['approvedAt'] ?? '')): ?><div class="company">存檔時間：<?=h($quote['fileSavedAt'] ?? '')?><?php if (filled($quote['approvedAt'] ?? '')): ?><br>完成生效核准：<?=h($quote['approvedAt'])?>　核准人：<?=h($quote['approvedBy'] ?? '')?><?php endif; ?></div><?php endif; ?><?php if (filled($quote['companyInfo'] ?? '')): ?><div class="company"><?=h($quote['companyInfo'])?></div><?php endif; ?>
</main>
</body>
</html>
