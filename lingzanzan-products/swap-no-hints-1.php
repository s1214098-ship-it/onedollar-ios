<?php
if (($_GET['k'] ?? '') !== 'no-hints-swap-20260926-1') {
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
  echo 'productsHtml=' . copy_swap($root . '/admin-products.html.new-no-hints-1', $root . '/admin-products.html') . "\n";
  echo 'inboundHtml=' . copy_swap($root . '/admin-inventory-entry.html.new-no-hints-1', $root . '/admin-inventory-entry.html') . "\n";
  echo 'inboundJs=' . copy_swap($assets . '/inventory-freight-entry-20260810.js.new-no-hints-1', $assets . '/inventory-freight-entry-20260810.js') . "\n";
  echo 'inboundCss=' . copy_swap($assets . '/inventory-freight-entry.css.new-no-hints-1', $assets . '/inventory-freight-entry.css') . "\n";
  echo 'productsCss=' . (is_file($assets . '/admin-products-no-hints.css') ? filesize($assets . '/admin-products-no-hints.css') : 0) . "\n";
  echo 'productsJs=' . (is_file($assets . '/admin-products-no-hints.js') ? filesize($assets . '/admin-products-no-hints.js') : 0) . "\n";
  $phtml = file_get_contents($root . '/admin-products.html') ?: '';
  $ihtml = file_get_contents($root . '/admin-inventory-entry.html') ?: '';
  $ijs = file_get_contents($assets . '/inventory-freight-entry-20260810.js') ?: '';
  echo 'productsBust=' . (strpos($phtml, '20260926-no-hints-1') !== false ? 'yes' : 'no') . "\n";
  echo 'inboundBust=' . (strpos($ihtml, '20260926-no-hints-1') !== false ? 'yes' : 'no') . "\n";
  echo 'imageMix=' . (strpos($ijs, 'LZ_IMAGE_MIX_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'noHints=' . (strpos($ijs, 'LZ_NO_HINTS_20260926') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
