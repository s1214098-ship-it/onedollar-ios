<?php
if (($_GET['k'] ?? '') !== 'recv-keep-cat-swap-20260926-2') {
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
  echo 'js=' . copy_swap($assets . '/admin.js.new-recv-cat-2', $assets . '/admin.js') . "\n";
  echo 'freightHtml=' . copy_swap($root . '/admin-freight.html.new-recv-cat-2', $root . '/admin-freight.html') . "\n";
  echo 'ordersHtml=' . copy_swap($root . '/admin-orders.html.new-recv-cat-2', $root . '/admin-orders.html') . "\n";
  $js = file_get_contents($assets . '/admin.js') ?: '';
  $freight = file_get_contents($root . '/admin-freight.html') ?: '';
  $orders = file_get_contents($root . '/admin-orders.html') ?: '';
  echo 'jsMarker=' . (strpos($js, 'LZ_RECV_KEEP_CLOTHING_CAT_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'jsRemember=' . (strpos($js, 'function rememberFreightReceivingChoice') !== false ? 'yes' : 'no') . "\n";
  echo 'jsKeepFn=' . (strpos($js, 'function freightIsClothingCategoryName') !== false ? 'yes' : 'no') . "\n";
  echo 'freightBust=' . (strpos($freight, '20260926-recv-cat-2') !== false ? 'yes' : 'no') . "\n";
  echo 'ordersBust=' . (strpos($orders, '20260926-recv-cat-2') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
