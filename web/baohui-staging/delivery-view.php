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
function quote_official_no(array $quote): string {
    foreach (['officialNo', 'deliveryNo'] as $key) {
        $no = trim((string)($quote[$key] ?? ''));
        if ($no !== '') return $no;
    }
    return '';
}
function quote_as_delivery(array $quote): array {
    $items = [];
    foreach ((array)($quote['items'] ?? []) as $it) {
        if (!is_array($it)) continue;
        $name = trim((string)($it['name'] ?? ''));
        $spec = trim((string)($it['spec'] ?? ''));
        if (!empty($it['optional']) || mb_strpos($name . $spec, '可加可不加') !== false) continue;
        $items[] = [
            'brand' => (string)($it['brand'] ?? ''),
            'name' => $name,
            'spec' => $spec,
            'qty' => $it['qty'] ?? 1,
            'warranty' => (string)($it['warranty'] ?? ''),
            'price' => $it['price'] ?? 0,
        ];
    }
    return [
        'no' => quote_official_no($quote),
        'quoteNo' => (string)($quote['no'] ?? ''),
        'date' => (string)($quote['date'] ?? ''),
        'status' => (string)($quote['status'] ?? '待出貨'),
        'customerName' => (string)($quote['customerName'] ?? ($quote['customer'] ?? '')),
        'title' => (string)($quote['title'] ?? ''),
        'contact' => (string)($quote['contact'] ?? ''),
        'phone' => (string)($quote['phone'] ?? ''),
        'address' => (string)($quote['address'] ?? ''),
        'logisticsCompany' => (string)($quote['logisticsCompany'] ?? ''),
        'trackingNo' => (string)($quote['trackingNo'] ?? ''),
        'shippedAt' => (string)($quote['shippedAt'] ?? ''),
        'note' => (string)($quote['note'] ?? ''),
        'items' => $items,
        'fromQuoteFallback' => true,
    ];
}

$token = trim((string)($_GET['token'] ?? ''));
$quoteToken = trim((string)($_GET['quote_token'] ?? ''));
$quoteId = trim((string)($_GET['quote_id'] ?? ''));
$no = trim((string)($_GET['no'] ?? ''));
$data = read_data();
$order = null;
$matchedQuote = null;

foreach ((array)($data['deliveryOrders'] ?? []) as $o) {
    if (!is_array($o)) continue;
    $oToken = (string)($o['token'] ?? '');
    if ($token !== '' && $oToken !== '' && hash_equals($oToken, $token)) { $order = $o; break; }
}
if (!$order) {
    foreach ((array)($data['deliveryOrders'] ?? []) as $o) {
        if (!is_array($o)) continue;
        if ($quoteToken !== '' && (string)($o['quoteToken'] ?? '') === $quoteToken) { $order = $o; break; }
        if ($quoteId !== '' && (string)($o['quoteId'] ?? '') === $quoteId) { $order = $o; break; }
        if ($no !== '' && ((string)($o['no'] ?? '') === $no || (string)($o['officialNo'] ?? '') === $no)) { $order = $o; break; }
    }
}

$lookupToken = $quoteToken !== '' ? $quoteToken : $token;
if ($lookupToken !== '') {
    foreach ((array)($data['quotations'] ?? []) as $q) {
        if (!is_array($q)) continue;
        $qToken = (string)($q['token'] ?? '');
        if ($qToken !== '' && hash_equals($qToken, $lookupToken)) { $matchedQuote = $q; break; }
    }
}
if (!$matchedQuote && $quoteId !== '') {
    foreach ((array)($data['quotations'] ?? []) as $q) {
        if (!is_array($q)) continue;
        if ((string)($q['id'] ?? '') === $quoteId) { $matchedQuote = $q; break; }
    }
}
if (!$order && $matchedQuote && quote_official_no($matchedQuote) !== '') {
    $order = quote_as_delivery($matchedQuote);
}
if (!$order) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><title>出貨單不存在</title><body style="font-family:Arial,sans-serif;padding:32px"><h2>出貨單不存在或連結已失效</h2><p>這張估價單若還沒轉現貨出貨單，請先在估價單列表按「轉現貨出貨單」。</p></body>';
    exit;
}
$items = is_array($order['items'] ?? null) ? $order['items'] : [];
require_once __DIR__ . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'document-print-lib.php';
$print = isset($_GET['print']);
$quoteView = $matchedQuote && filled($matchedQuote['token'] ?? '')
    ? ('quote-view.php?token=' . rawurlencode((string)$matchedQuote['token']) . '&quote_print=1')
    : '';
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>寶輝科技出貨單 <?=h($order['no'] ?? '')?></title>
<style><?= baohui_ops_print_sheet_css() ?></style>
<?php if ($print): ?><script>window.addEventListener('load',function(){setTimeout(function(){window.print();},500);});</script><?php endif; ?>
</head>
<body>
<div class="actions">
  <button class="btn" onclick="window.print()">列印半張出貨單</button>
  <?php if ($quoteView): ?><a class="btn secondary" href="<?=h($quoteView)?>">改列印估價單</a><?php endif; ?>
  <span class="hint">半張 A4，印在紙張上半部</span>
</div>
<main class="sheet">
  <section class="top"><div class="brand"><h1>寶輝科技出貨單</h1><p>本單由估價單轉出，供出貨、簽收與後續進銷存串聯使用。</p></div><div class="meta"><p>出貨單號：<b><?=h($order['no'] ?? '')?></b></p><p>來源估價單：<?=h($order['quoteNo'] ?? ($matchedQuote['no'] ?? ''))?></p><p>建立日期：<?=h($order['date'] ?? '')?></p><p>狀態：<?=h($order['status'] ?? '待出貨')?></p></div></section>
  <section class="info"><div class="box"><b>客戶資料</b><br>客戶：<?=h($order['customerName'] ?? '')?><br>抬頭：<?=h($order['title'] ?? '')?><br>聯絡人：<?=h($order['contact'] ?? '')?><br>電話：<?=h($order['phone'] ?? '')?><br>地址：<?=h($order['address'] ?? '')?></div><div class="box"><b>物流 / 出貨</b><br>物流公司：<?=h($order['logisticsCompany'] ?? '')?><br>物流單號：<?=h($order['trackingNo'] ?? '')?><br>出貨時間：<?=h($order['shippedAt'] ?? '')?><br>備註：<?=h($order['note'] ?? '')?></div></section>
  <table><thead><tr><th>廠牌</th><th>品項</th><th>規格</th><th class="right">數量</th><th>保固</th></tr></thead><tbody><?php foreach ($items as $it): if (!is_array($it)) continue; ?><tr><td><?=h($it['brand'] ?? '')?></td><td><?=h($it['name'] ?? ($it['product_title'] ?? ''))?></td><td><?=h($it['spec'] ?? '')?></td><td class="right"><?=h($it['qty'] ?? ($it['quantity'] ?? ''))?></td><td><?=h($it['warranty'] ?? '')?></td></tr><?php endforeach; ?></tbody></table>
  <section class="sign"><div><b>客戶簽收</b><div class="sign-line"></div><p>單位 / 姓名：</p><p>日期：</p></div><div><b>寶輝科技經辦</b><div class="sign-line"></div><p>經辦：</p><p>日期：</p></div></section>
  <div class="company">寶輝科技有限公司　公司電話：039-773280　公司傳真：039-773669　聯絡人：郭先生、李先生、曾小姐</div>
</main>
</body>
</html>
