<?php
if (($_GET['k'] ?? '') !== 'olan72-swap-20260924-1') {
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
  echo 'freightEntry=' . copy_swap($assets . '/inventory-freight-entry-20260810.js.gz-olan72-1', $assets . '/inventory-freight-entry-20260810.js', true) . "\n";
  echo 'invHtml=' . copy_swap($root . '/admin-inventory-entry.html.new-olan72-1', $root . '/admin-inventory-entry.html', false) . "\n";
  $js = file_get_contents($assets . '/inventory-freight-entry-20260810.js') ?: '';
  $html = file_get_contents($root . '/admin-inventory-entry.html') ?: '';
  echo 'jsOlan72=' . (strpos($js, 'LZ_OLAN72_SNOOPY_20260924') !== false ? 'yes' : 'no') . "\n";
  echo 'jsCatalogColor=' . (strpos($js, 'function catalogHasMatchingColor') !== false ? 'yes' : 'no') . "\n";
  echo 'jsAliases=' . (strpos($js, 'barcodeAliases') !== false ? 'yes' : 'no') . "\n";
  echo 'jsHover=' . (strpos($js, 'LZ_THUMB_HOVER_20260924') !== false ? 'yes' : 'no') . "\n";
  echo 'jsFmt=' . (strpos($js, 'LZ_BARCODE_FMT_20260924') !== false ? 'yes' : 'no') . "\n";
  echo 'jsRecvScan=' . (strpos($js, 'skuBarcodeAliasKeys') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($html, '20260924-olan72-1') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
