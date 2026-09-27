<?php
if (($_GET['k'] ?? '') !== 'wh-barcode-swap-20260927-1') {
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
  echo 'adminJs=' . copy_swap($assets . '/admin.js.new-wh-barcode-1', $assets . '/admin.js') . "\n";
  echo 'whJs=' . copy_swap($assets . '/admin-warehouse-authority-1.js.new-wh-barcode-1', $assets . '/admin-warehouse-authority-1.js') . "\n";
  echo 'colorJs=' . copy_swap($assets . '/admin-inventory-color-auto-10.js.new-wh-barcode-1', $assets . '/admin-inventory-color-auto-10.js') . "\n";
  echo 'inboundJs=' . copy_swap($assets . '/inventory-freight-entry-20260810.js.new-wh-barcode-1', $assets . '/inventory-freight-entry-20260810.js') . "\n";
  echo 'scannerJs=' . copy_swap($assets . '/scanner-ygf-v59.js.new-wh-barcode-1', $assets . '/scanner-ygf-v59.js') . "\n";
  echo 'scannerApi=' . copy_swap($root . '/scanner-api.php.new-wh-barcode-1', $root . '/scanner-api.php') . "\n";
  echo 'freightHtml=' . copy_swap($root . '/admin-freight.html.new-wh-barcode-1', $root . '/admin-freight.html') . "\n";
  echo 'productsHtml=' . copy_swap($root . '/admin-products.html.new-wh-barcode-1', $root . '/admin-products.html') . "\n";
  echo 'invHtml=' . copy_swap($root . '/admin-inventory.html.new-wh-barcode-1', $root . '/admin-inventory.html') . "\n";
  echo 'entryHtml=' . copy_swap($root . '/admin-inventory-entry.html.new-wh-barcode-1', $root . '/admin-inventory-entry.html') . "\n";
  echo 'scannerHtml=' . copy_swap($root . '/scanner.html.new-wh-barcode-1', $root . '/scanner.html') . "\n";
  $admin = file_get_contents($assets . '/admin.js') ?: '';
  $wh = file_get_contents($assets . '/admin-warehouse-authority-1.js') ?: '';
  $color = file_get_contents($assets . '/admin-inventory-color-auto-10.js') ?: '';
  $scan = file_get_contents($assets . '/scanner-ygf-v59.js') ?: '';
  $api = file_get_contents($root . '/scanner-api.php') ?: '';
  $freight = file_get_contents($root . '/admin-freight.html') ?: '';
  echo 'adminConcat=' . (strpos($admin, "return base + normalizedColor + normalizedSize + 'P' + normalizedCost;") !== false ? 'yes' : 'no') . "\n";
  echo 'adminNoCS=' . (strpos($admin, "return base + 'C' + normalizedColor + 'S' + normalizedSize + 'P' + normalizedCost;") === false ? 'yes' : 'no') . "\n";
  echo 'adminLabel=' . (strpos($admin, '產品條碼') !== false ? 'yes' : 'no') . "\n";
  echo 'adminWhPick=' . (strpos($admin, 'data-label-print-warehouse') !== false ? 'yes' : 'no') . "\n";
  echo 'adminClose=' . (strpos($admin, 'LZ_RECV_CLOSE_KEEP_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'whConcat=' . (strpos($wh, 'LZ_WH_BARCODE_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'colorSerial=' . (strpos($color, 'LZ_WH_BARCODE_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'scanSerial=' . (strpos($scan, 'LZ_WH_BARCODE_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'scanPda=' . (strpos($scan, 'LZ_PDA_CAM') !== false ? 'yes' : 'no') . "\n";
  echo 'apiMarker=' . (strpos($api, 'LZ_WH_BARCODE_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'freightBust=' . (strpos($freight, '20260927-wh-barcode-1') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
