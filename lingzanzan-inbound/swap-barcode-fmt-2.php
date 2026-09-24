<?php
if (($_GET['k'] ?? '') !== 'barcode-fmt-swap-20260924-2') {
  http_response_code(403);
  echo 'no';
  exit;
}
header('Content-Type: text/plain; charset=utf-8');
$root = __DIR__;
$assets = $root . DIRECTORY_SEPARATOR . 'assets';

function put_swap($src, $dest) {
  if (!is_file($src)) throw new Exception('missing ' . $src);
  $raw = file_get_contents($src);
  if ($raw === false) throw new Exception('read fail ' . $src);
  $ok = @file_put_contents($dest, $raw);
  if ($ok === false) {
    $tmp = $dest . '.tmp-' . bin2hex(random_bytes(3));
    if (file_put_contents($tmp, $raw) === false) throw new Exception('write tmp fail ' . $dest);
    @unlink($dest);
    if (!@rename($tmp, $dest)) {
      @unlink($tmp);
      throw new Exception('replace fail ' . $dest);
    }
    return filesize($dest);
  }
  return $ok;
}

$errors = [];
try { echo 'freightEntry=' . put_swap($assets . '/inventory-freight-entry-20260810.js.new-barcode-fmt-2', $assets . '/inventory-freight-entry-20260810.js') . "\n"; }
catch (Exception $e) { echo 'freightEntry=ERR ' . $e->getMessage() . "\n"; $errors[] = $e->getMessage(); }
try { echo 'invHtml=' . put_swap($root . '/admin-inventory-entry.html.new-barcode-fmt-2', $root . '/admin-inventory-entry.html') . "\n"; }
catch (Exception $e) { echo 'invHtml=ERR ' . $e->getMessage() . "\n"; $errors[] = $e->getMessage(); }
try { echo 'api=' . put_swap($root . '/stock-inquiry-api.php.new-barcode-fmt-2', $root . '/stock-inquiry-api.php') . "\n"; }
catch (Exception $e) { echo 'api=ERR ' . $e->getMessage() . "\n"; $errors[] = $e->getMessage(); }

$js = @file_get_contents($assets . '/inventory-freight-entry-20260810.js') ?: '';
$html = @file_get_contents($root . '/admin-inventory-entry.html') ?: '';
$api = @file_get_contents($root . '/stock-inquiry-api.php') ?: '';
echo 'jsFmt=' . (strpos($js, 'LZ_BARCODE_FMT_20260924') !== false ? 'yes' : 'no') . "\n";
echo 'jsPad00=' . (strpos($js, 'function padReceiptSizeCode') !== false ? 'yes' : 'no') . "\n";
echo 'jsWh=' . (strpos($js, 'LZ_WH_STOCK_20260924') !== false ? 'yes' : 'no') . "\n";
echo 'jsRecvScan2=' . (strpos($js, 'LZ_RECV_SCAN_20260924_2') !== false ? 'yes' : 'no') . "\n";
echo 'jsPrint=' . (strpos($js, 'window.print();') !== false ? 'yes' : 'no') . "\n";
echo 'jsHint=' . (strpos($js, '編號+色碼+尺碼+P成本') !== false ? 'yes' : 'no') . "\n";
echo 'apiCanonical=' . (strpos($api, 'function receipt_is_canonical_company_barcode') !== false ? 'yes' : 'no') . "\n";
echo 'htmlBust=' . (strpos($html, '20260924-barcode-fmt-2') !== false ? 'yes' : 'no') . "\n";
echo 'htmlHint=' . (strpos($html, '編號色尺P成本') !== false ? 'yes' : 'no') . "\n";
echo 'htmlCat=' . (strpos($html, 'LZ_CAT_SELECT_20260924') !== false ? 'yes' : 'no') . "\n";
echo empty($errors) ? "ok\n" : ("partial\n");
