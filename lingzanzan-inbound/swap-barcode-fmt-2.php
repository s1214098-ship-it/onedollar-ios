<?php
if (($_GET['k'] ?? '') !== 'barcode-fmt-swap-20260924-2') {
  http_response_code(403);
  echo 'no';
  exit;
}
header('Content-Type: text/plain; charset=utf-8');
$root = __DIR__;
$assets = $root . DIRECTORY_SEPARATOR . 'assets';

function copy_swap($src, $dest) {
  if (!is_file($src)) throw new Exception('missing ' . $src);
  $tmp = $dest . '.tmp-' . bin2hex(random_bytes(3));
  if (!copy($src, $tmp)) throw new Exception('copy fail ' . $src);
  if (is_file($dest) && !unlink($dest)) { @unlink($tmp); throw new Exception('unlink fail ' . $dest); }
  if (!rename($tmp, $dest)) { @unlink($tmp); throw new Exception('rename fail ' . $dest); }
  return filesize($dest);
}

try {
  echo 'freightEntry=' . copy_swap($assets . '/inventory-freight-entry-20260810.js.new-barcode-fmt-2', $assets . '/inventory-freight-entry-20260810.js') . "\n";
  echo 'invHtml=' . copy_swap($root . '/admin-inventory-entry.html.new-barcode-fmt-2', $root . '/admin-inventory-entry.html') . "\n";
  echo 'api=' . copy_swap($root . '/stock-inquiry-api.php.new-barcode-fmt-2', $root . '/stock-inquiry-api.php') . "\n";
  $js = file_get_contents($assets . '/inventory-freight-entry-20260810.js') ?: '';
  $html = file_get_contents($root . '/admin-inventory-entry.html') ?: '';
  $api = file_get_contents($root . '/stock-inquiry-api.php') ?: '';
  echo 'jsFmt=' . (strpos($js, 'LZ_BARCODE_FMT_20260924') !== false ? 'yes' : 'no') . "\n";
  echo 'jsCanonical=' . (strpos($js, 'function composeCanonicalCompanyBarcode') !== false ? 'yes' : 'no') . "\n";
  echo 'jsPad00=' . (strpos($js, 'function padReceiptSizeCode') !== false ? 'yes' : 'no') . "\n";
  echo 'jsNoCS=' . (strpos($js, "productCode + 'P' + cost + 'C' + colorCode + 'S'") === false ? 'yes' : 'no') . "\n";
  echo 'jsNoOmit=' . (strpos($js, 'NOSIZE omits 00') === false ? 'yes' : 'no') . "\n";
  echo 'jsWh=' . (strpos($js, 'LZ_WH_STOCK_20260924') !== false ? 'yes' : 'no') . "\n";
  echo 'jsRecvScan2=' . (strpos($js, 'LZ_RECV_SCAN_20260924_2') !== false ? 'yes' : 'no') . "\n";
  echo 'jsPrint=' . (strpos($js, 'window.print();') !== false ? 'yes' : 'no') . "\n";
  echo 'jsHint=' . (strpos($js, '編號+色碼+尺碼+P成本') !== false ? 'yes' : 'no') . "\n";
  echo 'apiCanonical=' . (strpos($api, 'function receipt_is_canonical_company_barcode') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($html, '20260924-barcode-fmt-2') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlHint=' . (strpos($html, '編號色尺P成本') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
