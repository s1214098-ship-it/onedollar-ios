<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'quote-vendor-tax-lib.php';

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

expect(quote_vendor_cost_tax_mode() === 'external', '捷元／原價屋 cost uses 外加 5%');

$coolpcCpu = [
    'name' => '｛Intel i3-14100｝【4核/8緒】',
    'brand' => 'Intel',
    'spec' => '處理器 CPU',
    'qty' => 2,
    'price' => 4800,
    'warranty' => '依產品或原廠保固條件辦理',
    'taxMode' => 'included',
];
$jieyuan = [
    'name' => 'ASUS PRIME B760M',
    'brand' => '華碩 ASUS',
    'spec' => 'J123456｜B760',
    'qty' => 1,
    'price' => 3200,
    'productSource' => 'genb2b',
    'taxMode' => 'none',
];
$assembly = [
    'name' => '電腦組裝費用',
    'brand' => '寶輝科技',
    'spec' => '單次整機組裝；不含作業系統授權及其他軟體安裝',
    'qty' => 2,
    'price' => 1500,
    'taxMode' => 'included',
];
$optional = [
    'name' => '第二年硬體維護',
    'brand' => '寶輝科技',
    'spec' => '選購項目，可加可不加',
    'qty' => 1,
    'price' => 800,
    'optional' => true,
    'taxMode' => 'none',
];
$wage = [
    'name' => '保固一年服務費 (包含軟體基本排除即送修硬體一年免費，不包含人為)',
    'brand' => '',
    'spec' => '工資費用',
    'qty' => 2,
    'price' => 1500,
    'warranty' => '依產品或原廠保固條件辦理',
    'taxMode' => 'included',
];
$psu = [
    'name' => '松聖DUKE BR550 POWER',
    'brand' => '松聖',
    'spec' => '',
    'qty' => 2,
    'price' => 1800,
    'warranty' => '依產品或原廠保固條件辦理',
    'taxMode' => 'included',
];

expect(quote_item_is_vendor_cost_line($coolpcCpu), 'CoolPC CPU with fullwidth braces is vendor cost');
expect(quote_item_is_vendor_cost_line($jieyuan), '捷元 J-number spec is vendor cost');
expect(quote_item_is_vendor_cost_line($psu), 'CoolPC PSU with factory warranty is vendor cost');
expect(!quote_item_is_vendor_cost_line($assembly), '寶輝組裝費 is not vendor cost');
expect(!quote_item_is_vendor_cost_line($optional), '選購維護 is not vendor cost');
expect(!quote_item_is_vendor_cost_line($wage), '保固一年服務費 is not vendor cost');

$q = ['no' => 'VAL-20260923-001', 'date' => '2026-09-23', 'items' => [$coolpcCpu, $jieyuan, $psu, $assembly, $optional, $wage]];
expect(quote_is_on_day($q, '2026-09-23'), 'today quote matches VAL date code');
expect(!quote_is_on_day($q, '2026-09-22'), 'yesterday is not today');

$patched = quote_apply_vendor_cost_tax($q);
expect($patched['changed'] === 3, 'three vendor lines are switched to 外加 5%');
expect($patched['quote']['items'][0]['taxMode'] === 'external', 'CoolPC CPU becomes 外加 5%');
expect($patched['quote']['items'][1]['taxMode'] === 'external', '捷元 line becomes 外加 5%');
expect($patched['quote']['items'][2]['taxMode'] === 'external', 'CoolPC PSU becomes 外加 5%');
expect($patched['quote']['items'][3]['taxMode'] === 'included', '組裝費 stays 內含');
expect($patched['quote']['items'][4]['taxMode'] === 'none', '選購 stays 未稅');
expect($patched['quote']['items'][5]['taxMode'] === 'included', '保固一年服務費 stays 內含');

$payload = quote_patch_vendor_tax_in_payload(['quotations' => [$q, ['no' => 'VAL-20260922-009', 'date' => '2026-09-22', 'items' => [$coolpcCpu]]]], '2026-09-23');
expect($payload['quotes'] === 1, 'only today quotes are rewritten');
expect($payload['items'] === 3, 'today vendor items are counted');
expect($payload['nos'] === ['VAL-20260923-001'], 'reports the today quote number');
expect($payload['data']['quotations'][1]['items'][0]['taxMode'] === 'included', 'older quote is left alone');

$tmpIn = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quote-vendor-tax-in.json';
$tmpOut = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quote-vendor-tax-out.json';
file_put_contents($tmpIn, json_encode(['quotations' => [$q]], JSON_UNESCAPED_UNICODE));
$filePatch = quote_patch_vendor_tax_json_file($tmpIn, $tmpOut, '2026-09-23');
$outData = json_decode((string)file_get_contents($tmpOut), true);
expect($filePatch['quotes'] === 1, 'JSON file patch rewrites today quote');
expect(($outData['quotations'][0]['items'][0]['taxMode'] ?? '') === 'external', 'JSON file patch writes 外加 5%');
@unlink($tmpIn);
@unlink($tmpOut);

$jsCoolpc = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'admin-coolpc-quote.js');
expect(str_contains($jsCoolpc, 'taxMode: "external"'), 'CoolPC insert auto-selects 外加 5%');
expect(!str_contains($jsCoolpc, 'taxMode: "none"'), 'CoolPC insert no longer defaults to 未稅');
expect(str_contains($jsCoolpc, 'productSource: "coolpc"'), 'CoolPC insert tags productSource');

$jsGen = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'admin-genb2b-quote.js');
expect(is_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'admin-genb2b-quote.js'), '捷元 quote UI is in git overlay');
expect(str_contains($jsGen, 'taxMode: "external"'), '捷元 insert auto-selects 外加 5%');
expect(str_contains($jsGen, 'productSource: "genb2b"'), '捷元 insert tags productSource');

$jsQuote = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'admin-quotation.js');
expect(str_contains($jsQuote, 'genb2bQuoteBox'), 'quotation page has 捷元 box');
expect(str_contains($jsQuote, 'data-product-source='), 'quotation rows persist productSource');

if ($failed > 0) {
    fwrite(STDERR, "$failed failed\n");
    exit(1);
}
echo "all vendor quote tax tests passed\n";
