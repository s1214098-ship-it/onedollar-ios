<?php
if (($_GET['k'] ?? '') !== 'barcode-unify-print-swap-20260926-1') {
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
  echo 'invjs=' . copy_swap($assets . '/admin-inventory-color-auto-10.js.new-barcode-unify-print-1', $assets . '/admin-inventory-color-auto-10.js') . "\n";
  echo 'invhtml=' . copy_swap($root . '/admin-inventory.html.new-barcode-unify-print-1', $root . '/admin-inventory.html') . "\n";
  echo 'scannerApi=' . copy_swap($root . '/scanner-api.php.new-barcode-unify-print-1', $root . '/scanner-api.php') . "\n";
  $inv = file_get_contents($assets . '/admin-inventory-color-auto-10.js') ?: '';
  $invhtml = file_get_contents($root . '/admin-inventory.html') ?: '';
  $api = file_get_contents($root . '/scanner-api.php') ?: '';
  echo 'invMarker=' . (strpos($inv, 'LZ_BARCODE_UNIFY_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'invCsGone=' . (strpos($inv, "base + 'C' + normalizedColor + 'S'") === false ? 'yes' : 'no') . "\n";
  echo 'invHtmlBust=' . (strpos($invhtml, '20260926-barcode-unify-1') !== false ? 'yes' : 'no') . "\n";
  echo 'apiMarker=' . (strpos($api, 'LZ_BARCODE_UNIFY_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'apiCsGone=' . (strpos($api, ". 'C' . \$parts['color'] . 'S'") === false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
