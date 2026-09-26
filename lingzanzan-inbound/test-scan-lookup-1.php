<?php
declare(strict_types=1);
$libDir = sys_get_temp_dir() . '/lz-scan-lookup-lib';
@mkdir($libDir, 0775, true);
foreach (['scanner-api.php'] as $name) {
    if (!copy(__DIR__ . '/' . $name, $libDir . '/' . $name)) fwrite(STDERR, "copy $name failed\n");
}
foreach (['image-storage.php', 'cn-tw-hold-lib.php', 'barcode-integrity-lib.php'] as $name) {
    $src = '/tmp/lz-scan/staging/' . $name;
    if (is_file($src)) copy($src, $libDir . '/' . $name);
}
define('LINGZANZAN_SCANNER_API_LIB', true);
require $libDir . '/scanner-api.php';

function fail(string $msg): void { fwrite(STDERR, $msg . "\n"); exit(1); }
function assertTrue($cond, string $msg): void { if (!$cond) fail($msg); }

$src = file_get_contents(__DIR__ . '/scanner-api.php') ?: '';
assertTrue(strpos($src, 'LZ_SCAN_LOOKUP_20260924') !== false, 'lookup marker missing');
assertTrue(strpos($src, 'scanner_generated_pend_label_barcodes') !== false, 'pend helper missing');

$js = file_get_contents(__DIR__ . '/scanner-ygf-v59.js') ?: '';
assertTrue(strpos($js, 'LZ_SCAN_LOOKUP_20260924') !== false, 'js lookup marker missing');
assertTrue(strpos($js, '/^OLAN\\d{1,4}P\\d{1,3}$/i') !== false, 'js incomplete OLAN still old regex');

$html = file_get_contents(__DIR__ . '/scanner.html') ?: '';
assertTrue(strpos($html, '20260924-scan-lookup-1') !== false, 'html cache tag missing');
assertTrue(strpos($html, 'confirm-click-1') === false, 'do not touch inbound html');

$skusFile = '/tmp/lz-scan/data/skus.json';
$productsFile = '/tmp/lz-scan/data/products.json';
if (!is_file($skusFile) || !is_file($productsFile)) fail('catalog snapshots missing');
$skus = json_decode((string)file_get_contents($skusFile), true);
$products = json_decode((string)file_get_contents($productsFile), true);
if (!is_array($skus) || !is_array($products)) fail('catalog decode failed');

function lookup_titles(array $rows): array {
    $out = [];
    foreach ($rows as $row) {
        if (empty($row['exact'])) continue;
        $out[] = ($row['productCode'] ?? '') . '|' . ($row['title'] ?? '') . '|' . ($row['color'] ?? '') . '|' . ($row['size'] ?? '') . '|stock=' . ($row['stock'] ?? '');
    }
    return $out;
}

$cases = [
    // New inbound sticker P-at-end vs V2 stored 集運 9/24
    ['TOY00392P149', 'TOY003', '衣架'],
    ['TOY0039200P149', 'TOY003', '衣架'],
    ['TOY003P149C92S00', 'TOY003', '衣架'],
    ['BA5692P400', 'BA56', '原生密碼'],
    ['BA56P400C92S00', 'BA56', '原生密碼'],
    ['K4069044P137', 'K406', 'SUP'],
    ['K406P137C904S4', 'K406', 'SUP'],
    ['JA3309404P430', 'JA330', '北巔峰'],
    ['JA330P4309404', 'JA330', '北巔峰'],
    // Jacket recode: printed OLAN93 sticker, catalog JA334
    ['OLAN939024P338', 'JA334', '三葉草'],
    ['JA3349024P338', 'JA334', '三葉草'],
    ['JA334P3389024', 'JA334', '三葉草'],
    ['OLAN93P338C902S4', 'JA334', '三葉草'],
    // Old water-bottle aliases must keep working
    ['OLAN66P34098', 'OLAN66', ''],
    ['OLAN6698P340', 'OLAN66', ''],
    ['OLAN71-93-NO-SIZE', 'OLAN71', ''],
    ['OLAN7193P345', 'OLAN71', ''],
    ['OLAN72P350923', 'OLAN72', ''],
    ['OLAN72923P350', 'OLAN72', ''],
    // Zero-stock 預購 still shows name
    ['CUP00190P174', 'CUP001', '保溫杯'],
    ['CUP001P174C90S00', 'CUP001', '保溫杯'],
];

foreach ($cases as [$query, $expectCode, $titleHint]) {
    $rows = scanner_rows($products, $skus, $query, [], []);
    $exact = array_values(array_filter($rows, static fn($row) => !empty($row['exact'])));
    if (!$exact) {
        fail('EMPTY lookup for ' . $query . ' expected ' . $expectCode . ' rows=' . count($rows));
    }
    $hit = null;
    foreach ($exact as $row) {
        if (scanner_norm($row['productCode'] ?? '') === scanner_norm($expectCode) || scanner_norm($row['productId'] ?? '') === scanner_norm($expectCode)) {
            $hit = $row; break;
        }
    }
    if (!$hit) fail('wrong product for ' . $query . ' got ' . implode('; ', lookup_titles($exact)));
    if (trim((string)($hit['title'] ?? '')) === '') fail('empty title for ' . $query);
    if ($titleHint !== '' && strpos((string)$hit['title'], $titleHint) === false) fail('title hint missing for ' . $query . ' title=' . $hit['title']);
    echo 'ok ' . $query . ' -> ' . $hit['productCode'] . ' ' . $hit['color'] . '/' . $hit['size'] . ' stock=' . $hit['stock'] . "\n";
}

// Incomplete OLAN JS contract mirrored
$incomplete = ['OLAN72', 'OLAN66', 'OLAN72P350'];
$complete = ['OLAN66P34098', 'OLAN939024P338', 'OLAN7193NOSIZE', 'OLAN72P350923', 'OLAN71-93-NO-SIZE'];
foreach ($complete as $code) {
    $n = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '');
    $bad = (bool)preg_match('/^OLAN\d+$/i', $n) || (bool)preg_match('/^OLAN\d{1,4}P\d{1,3}$/i', $n);
    if ($bad) fail('complete code wrongly incomplete: ' . $code);
}

echo "all lookup tests passed\n";
