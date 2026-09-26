<?php
if (($_GET['k'] ?? '') !== 'color-photo-swap-20260926-1') {
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
  echo 'css=' . copy_swap($assets . '/inventory-freight-entry.css.new-color-photo-1', $assets . '/inventory-freight-entry.css') . "\n";
  echo 'js=' . copy_swap($assets . '/inventory-freight-entry-20260810.js.new-color-photo-1', $assets . '/inventory-freight-entry-20260810.js') . "\n";
  echo 'html=' . copy_swap($root . '/admin-inventory-entry.html.new-color-photo-1', $root . '/admin-inventory-entry.html') . "\n";
  $js = file_get_contents($assets . '/inventory-freight-entry-20260810.js') ?: '';
  $html = file_get_contents($root . '/admin-inventory-entry.html') ?: '';
  echo 'jsMarker=' . (strpos($js, 'LZ_COLOR_PHOTO_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'jsWhiteMerge=' . (strpos($js, 'LZ_WHITE_MERGE_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'jsColorMem=' . (strpos($js, 'LZ_COLOR_MEM_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($html, '20260926-color-photo-1') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
