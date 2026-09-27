<?php
if (($_GET['k'] ?? '') !== 'print-wh-swap-20260927-1') {
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
  echo 'colorJs=' . copy_swap($assets . '/admin-inventory-color-auto-10.js.new-print-wh-1', $assets . '/admin-inventory-color-auto-10.js') . "\n";
  echo 'invHtml=' . copy_swap($root . '/admin-inventory.html.new-print-wh-1', $root . '/admin-inventory.html') . "\n";
  $js = file_get_contents($assets . '/admin-inventory-color-auto-10.js') ?: '';
  $html = file_get_contents($root . '/admin-inventory.html') ?: '';
  echo 'jsMarker=' . (strpos($js, 'LZ_PRINT_WH_PICK_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'jsStamp=' . (strpos($js, 'inventoryStampPrintWarehouse') !== false ? 'yes' : 'no') . "\n";
  echo 'jsButtons=' . (strpos($js, 'inventoryPrintWarehouseButtonsHtml') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($html, '20260927-print-wh-1') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
