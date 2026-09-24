<?php
if (($_GET['k'] ?? '') !== 'barcode-fmt-swap-20260924-1') {
  http_response_code(403);
  echo 'no';
  exit;
}
header('Content-Type: text/plain; charset=utf-8');
$root = __DIR__;
$assets = $root . DIRECTORY_SEPARATOR . 'assets';

function copy_swap($src, $dest, $gzipped = false) {
  if (!is_file($src)) throw new Exception('missing ' . $src);
  $raw = file_get_contents($src);
  if ($raw === false) throw new Exception('read fail ' . $src);
  if ($gzipped) {
    $decoded = @gzdecode($raw);
    if ($decoded === false) throw new Exception('gunzip fail ' . $src . ' gz=' . strlen($raw));
    $raw = $decoded;
  }
  $tmp = $dest . '.swapping-' . bin2hex(random_bytes(3));
  if (file_put_contents($tmp, $raw) === false) throw new Exception('write tmp fail ' . $dest);
  if (!@rename($tmp, $dest)) {
    if (is_file($dest) && @copy($tmp, $dest)) {
      @unlink($tmp);
      return filesize($dest);
    }
    @unlink($tmp);
    throw new Exception('rename/copy fail ' . $dest);
  }
  return filesize($dest);
}

try {
  echo 'freightEntry=' . copy_swap($assets . '/inventory-freight-entry-20260810.js.gz-barcode-fmt-1', $assets . '/inventory-freight-entry-20260810.js', true) . "\n";
  echo 'invHtml=' . copy_swap($root . '/admin-inventory-entry.html.new-barcode-fmt-1', $root . '/admin-inventory-entry.html', false) . "\n";
  $js = file_get_contents($assets . '/inventory-freight-entry-20260810.js') ?: '';
  $html = file_get_contents($root . '/admin-inventory-entry.html') ?: '';
  echo 'jsBytes=' . strlen($js) . "\n";
  echo 'jsFmt=' . (strpos($js, 'LZ_BARCODE_FMT_20260924') !== false ? 'yes' : 'no') . "\n";
  echo 'jsCanonical=' . (strpos($js, 'function composeCanonicalCompanyBarcode') !== false ? 'yes' : 'no') . "\n";
  echo 'jsNoCS=' . (strpos($js, "productCode + 'P' + cost + 'C' + colorCode + 'S'") === false ? 'yes' : 'no') . "\n";
  echo 'jsPrint=' . (strpos($js, 'window.print();') !== false ? 'yes' : 'no') . "\n";
  echo 'jsSheet=' . (strpos($js, 'printReceivedBarcodeSheet') !== false ? 'yes' : 'no') . "\n";
  echo 'jsHover=' . (strpos($js, 'LZ_THUMB_HOVER_20260924') !== false ? 'yes' : 'no') . "\n";
  echo 'jsPrintQr9=' . (strpos($js, 'LZ_PRINT_QR_20260924_9') !== false ? 'yes' : 'no') . "\n";
  echo 'jsRecvScan=' . (strpos($js, 'skuBarcodeAliasKeys') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($html, '20260924-barcode-fmt-1') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlHint=' . (strpos($html, '編號+顏色+尺碼+P(成本)') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
