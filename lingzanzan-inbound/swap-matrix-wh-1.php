<?php
if (($_GET['k'] ?? '') !== 'matrix-wh-swap-20260926-1') {
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
  echo 'wh=' . copy_swap($assets . '/admin-warehouse-authority-1.js.new-matrix-wh-1', $assets . '/admin-warehouse-authority-1.js') . "\n";
  echo 'fc=' . copy_swap($assets . '/admin-product-forwarder-cost-9.js.new-matrix-wh-1', $assets . '/admin-product-forwarder-cost-9.js') . "\n";
  echo 'css=' . copy_swap($assets . '/admin-products-horizontal-9.css.new-matrix-wh-1', $assets . '/admin-products-horizontal-9.css') . "\n";
  echo 'html=' . copy_swap($root . '/admin-products.html.new-matrix-wh-1', $root . '/admin-products.html') . "\n";
  $wh = file_get_contents($assets . '/admin-warehouse-authority-1.js') ?: '';
  $fc = file_get_contents($assets . '/admin-product-forwarder-cost-9.js') ?: '';
  $html = file_get_contents($root . '/admin-products.html') ?: '';
  echo 'whMarker=' . (strpos($wh, 'LZ_MATRIX_WH_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'fcMarker=' . (strpos($fc, 'LZ_MATRIX_WH_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'printKept=' . (strpos($wh, 'LZ_SKU_PRINT_ADD_SIZE_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($html, '20260926-matrix-wh-1') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
