<?php
if (($_GET['k'] ?? '') !== 'dup-color-swap-20260926-1') {
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
  echo 'js=' . copy_swap($assets . '/admin-product-forwarder-cost-9.js.new-dup-color-1', $assets . '/admin-product-forwarder-cost-9.js') . "\n";
  echo 'html=' . copy_swap($root . '/admin-products.html.new-dup-color-1', $root . '/admin-products.html') . "\n";
  $js = file_get_contents($assets . '/admin-product-forwarder-cost-9.js') ?: '';
  $html = file_get_contents($root . '/admin-products.html') ?: '';
  echo 'jsMarker=' . (strpos($js, 'LZ_DUP_COLOR_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($html, '20260926-dup-color-1') !== false ? 'yes' : 'no') . "\n";
  echo 'noHints=' . (strpos($html, '20260926-no-hints-1') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
