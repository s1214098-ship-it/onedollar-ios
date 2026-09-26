<?php
if (($_GET['k'] ?? '') !== 'cat-mem-swap-20260926-1') {
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
  echo 'css=' . copy_swap($assets . '/inventory-freight-entry.css.new-cat-mem-1', $assets . '/inventory-freight-entry.css') . "\n";
  echo 'js=' . copy_swap($assets . '/inventory-freight-entry-20260810.js.new-cat-mem-1', $assets . '/inventory-freight-entry-20260810.js') . "\n";
  echo 'html=' . copy_swap($root . '/admin-inventory-entry.html.new-cat-mem-1', $root . '/admin-inventory-entry.html') . "\n";
  $css = file_get_contents($assets . '/inventory-freight-entry.css') ?: '';
  $js = file_get_contents($assets . '/inventory-freight-entry-20260810.js') ?: '';
  $html = file_get_contents($root . '/admin-inventory-entry.html') ?: '';
  echo 'cssMarker=' . (strpos($css, 'LZ_CAT_MEM_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'jsMarker=' . (strpos($js, 'LZ_CAT_MEM_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'jsScan=' . (strpos($js, 'LZ_SCAN_ANYWHERE_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($html, '20260926-cat-mem-1') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
