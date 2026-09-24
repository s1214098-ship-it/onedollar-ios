<?php
if (($_GET['k'] ?? '') !== 'olan70-white-swap-20260924-1') {
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
    $decoded = gzdecode($raw);
    if ($decoded === false) throw new Exception('gunzip fail ' . $src . ' gz=' . strlen($raw));
    $raw = $decoded;
  }
  $tmp = $dest . '.tmp-' . bin2hex(random_bytes(3));
  if (file_put_contents($tmp, $raw) === false) throw new Exception('write tmp fail ' . $dest);
  if (is_file($dest) && !unlink($dest)) { @unlink($tmp); throw new Exception('unlink fail ' . $dest); }
  if (!rename($tmp, $dest)) { @unlink($tmp); throw new Exception('rename fail ' . $dest); }
  return filesize($dest);
}

try {
  echo 'freightEntry=' . copy_swap($assets . '/inventory-freight-entry-20260810.js.gz-olan70-white-1', $assets . '/inventory-freight-entry-20260810.js', true) . "\n";
  echo 'invHtml=' . copy_swap($root . '/admin-inventory-entry.html.new-olan70-white-1', $root . '/admin-inventory-entry.html', false) . "\n";
  $js = file_get_contents($assets . '/inventory-freight-entry-20260810.js') ?: '';
  $html = file_get_contents($root . '/admin-inventory-entry.html') ?: '';
  echo 'jsOlan70=' . (strpos($js, 'LZ_OLAN70_WHITE_20260924') !== false ? 'yes' : 'no') . "\n";
  echo 'jsCombo=' . (strpos($js, 'function inboundQueryHitsSku') !== false ? 'yes' : 'no') . "\n";
  echo 'jsStock0=' . (strpos($js, 'never hide stock 0') !== false ? 'yes' : 'no') . "\n";
  echo 'jsStoredBc=' . (strpos($js, 'search card shows catalog barcode OLAN70P35592') !== false ? 'yes' : 'no') . "\n";
  echo 'jsWh=' . (strpos($js, 'LZ_WH_STOCK_20260924') !== false ? 'yes' : 'no') . "\n";
  echo 'jsConfirm=' . (strpos($js, 'LZ_CONFIRM_FILL_20260924') !== false ? 'yes' : 'no') . "\n";
  echo 'jsHover=' . (strpos($js, 'LZ_THUMB_HOVER_20260924') !== false ? 'yes' : 'no') . "\n";
  echo 'jsScan=' . (strpos($js, 'skuBarcodeAliasKeys') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($html, '20260924-olan70-white-1') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
