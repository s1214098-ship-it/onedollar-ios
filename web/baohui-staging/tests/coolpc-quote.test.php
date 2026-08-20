<?php
declare(strict_types=1);

$failed = 0;
function expect($ok, string $msg): void
{
    global $failed;
    if ($ok) {
        echo "ok  $msg\n";
        return;
    }
    $failed++;
    echo "FAIL  $msg\n";
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'coolpc-quote-lib.php';

$fixture = (string)file_get_contents(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'coolpc-evaluate-sample.html');
$parsed = coolpc_quote_parse_evaluate_html($fixture);
$byId = [];
foreach ($parsed['items'] as $item) $byId[$item['id']] = $item;
$cats = [];
foreach ($parsed['categories'] as $cat) $cats[$cat['id']] = $cat;

expect(($cats['n4']['label'] ?? '') === '處理器 CPU', 'CPU category keeps CoolPC label');
expect(($cats['n5']['label'] ?? '') === '主機板 MB', 'motherboard category keeps CoolPC label');
expect(($cats['n4']['count'] ?? 0) === 2, 'CPU category skips header and priceless rows');

$intel = $byId['n4-4'] ?? [];
expect(($intel['price'] ?? 0) === 4880, 'Intel list price is 4880');
expect(($intel['brand'] ?? '') === 'Intel', 'Intel brand is parsed');
expect(str_contains((string)($intel['name'] ?? ''), 'Core Ultra 5 225F'), 'Intel name keeps model');
expect(!str_contains((string)($intel['name'] ?? ''), '$4880'), 'price suffix is stripped from name');

$amd = $byId['n4-12'] ?? [];
expect(($amd['price'] ?? 0) === 999, 'sale price after ↘ is used');
expect(($amd['list_price'] ?? 0) === 1699, 'original list price is kept');
expect(($amd['brand'] ?? '') === 'AMD', 'AMD brand is parsed');

$asus = $byId['n5-11'] ?? [];
expect(($asus['brand'] ?? '') === '華碩', 'ASUS Chinese brand is parsed');
expect(($asus['price'] ?? 0) === 2590, 'motherboard price is 2590');
expect(($asus['spec'] ?? '') === '主機板 MB', 'spec carries CoolPC category for the quote line');

expect(!isset($byId['n4-0']), 'header option is not a product');
expect(count($parsed['items']) === 4, 'four priced products are parsed');

$jsQuote = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'admin-quotation.js');
$jsCoolpc = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'admin-coolpc-quote.js');
expect(str_contains($jsQuote, 'coolpcQuoteBox'), 'quotation page still has CoolPC box');
expect(!str_contains($jsQuote, 'coolpcQuoteFrame'), 'quotation page no longer iframes CoolPC');
expect(str_contains($jsCoolpc, 'coolpc-quote-catalog.php'), 'CoolPC UI loads live catalog API');
expect(str_contains($jsCoolpc, 'addQuoteItemRow'), 'CoolPC items insert into the quote');
expect(str_contains($jsCoolpc, '原價屋即時報價'), 'CoolPC box is labeled as live CoolPC quotes');

$jsBuilder = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'admin-quote-builder.js');
expect(str_contains($jsBuilder, '寶輝組裝估價'), 'Baohui builder stays Baohui');
expect(!str_contains($jsBuilder, '原價屋即時報價'), 'Baohui builder is not relabeled as CoolPC');

if ($failed > 0) {
    fwrite(STDERR, $failed . " assertion(s) failed\n");
    exit(1);
}
echo "all passed\n";
