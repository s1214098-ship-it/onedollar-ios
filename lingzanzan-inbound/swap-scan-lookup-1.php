<?php
if (($_GET['k'] ?? '') !== 'scan-lookup-swap-20260924-1') {
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
  echo 'scannerApi=' . copy_swap($root . '/scanner-api.php.new-scan-lookup-1', $root . '/scanner-api.php') . "\n";
  echo 'scannerJs=' . copy_swap($assets . '/scanner-ygf-v59.js.new-scan-lookup-1', $assets . '/scanner-ygf-v59.js') . "\n";
  echo 'scannerHtml=' . copy_swap($root . '/scanner.html.new-scan-lookup-1', $root . '/scanner.html') . "\n";
  $api = file_get_contents($root . '/scanner-api.php') ?: '';
  $js = file_get_contents($assets . '/scanner-ygf-v59.js') ?: '';
  $html = file_get_contents($root . '/scanner.html') ?: '';
  echo 'apiLookup=' . (strpos($api, 'LZ_SCAN_LOOKUP_20260924') !== false ? 'yes' : 'no') . "\n";
  echo 'apiPend=' . (strpos($api, 'scanner_generated_pend_label_barcodes') !== false ? 'yes' : 'no') . "\n";
  echo 'apiIntegrity=' . (strpos($api, 'LZ_BARCODE_SERIAL_PEND_20260924') !== false ? 'yes' : 'no') . "\n";
  echo 'jsLookup=' . (strpos($js, 'LZ_SCAN_LOOKUP_20260924') !== false ? 'yes' : 'no') . "\n";
  echo 'jsApk=' . (strpos($js, 'LZ_SCANNER_APK_ERR_FAILED_20260924') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($html, '20260924-scan-lookup-1') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlStocktake=' . (strpos($html, '全體盤點草稿夾') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
