<?php
if (($_GET['k'] ?? '') !== 'recv-row-swap-20260927-1') {
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
  echo 'css=' . copy_swap($assets . '/admin.css.new-recv-row-1', $assets . '/admin.css') . "\n";
  echo 'freight=' . copy_swap($root . '/admin-freight.html.new-recv-row-1', $root . '/admin-freight.html') . "\n";
  echo 'inv=' . copy_swap($root . '/admin-inventory.html.new-recv-row-1', $root . '/admin-inventory.html') . "\n";
  $css = file_get_contents($assets . '/admin.css') ?: '';
  $freight = file_get_contents($root . '/admin-freight.html') ?: '';
  $inv = file_get_contents($root . '/admin-inventory.html') ?: '';
  echo 'cssMarker=' . (strpos($css, 'LZ_RECV_ROW_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'freightBust=' . (strpos($freight, '20260927-recv-row-1') !== false ? 'yes' : 'no') . "\n";
  echo 'invBust=' . (strpos($inv, '20260927-recv-row-1') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
