<?php
if (($_GET['k'] ?? '') !== 'recv-scan-swap-20260924-1') {
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
  echo 'freightEntry=' . copy_swap($assets . '/inventory-freight-entry-20260810.js.new-recv-scan-1', $assets . '/inventory-freight-entry-20260810.js') . "\n";
  echo 'invHtml=' . copy_swap($root . '/admin-inventory-entry.html.new-recv-scan-1', $root . '/admin-inventory-entry.html') . "\n";
  $js = file_get_contents($assets . '/inventory-freight-entry-20260810.js') ?: '';
  $html = file_get_contents($root . '/admin-inventory-entry.html') ?: '';
  echo 'jsRecvScan=' . (strpos($js, 'LZ_RECV_SCAN_20260924') !== false ? 'yes' : 'no') . "\n";
  echo 'jsPrintAlias=' . (strpos($js, 'inboundStoredAliasesFromPrint') !== false ? 'yes' : 'no') . "\n";
  echo 'jsExact=' . (strpos($js, 'skuBarcodeAliasKeys') !== false ? 'yes' : 'no') . "\n";
  echo 'jsHover=' . (strpos($js, 'LZ_THUMB_HOVER_20260924') !== false ? 'yes' : 'no') . "\n";
  echo 'jsPrintQr9=' . (strpos($js, 'LZ_PRINT_QR_20260924_9') !== false ? 'yes' : 'no') . "\n";
  echo 'jsPEnd=' . (strpos($js, 'function receivedPrintBarcode') !== false ? 'yes' : 'no') . "\n";
  echo 'jsOpen=' . (strpos($js, '看單') !== false ? 'yes' : 'no') . "\n";
  echo 'jsDel=' . (strpos($js, 'LZ_RECV_DEL_20260924') !== false ? 'yes' : 'no') . "\n";
  echo 'jsSheet=' . (strpos($js, 'printReceivedBarcodeSheet') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($html, '20260924-recv-scan-1') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlNoPoVoid=' . (strpos($html, 'admin-purchase') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
