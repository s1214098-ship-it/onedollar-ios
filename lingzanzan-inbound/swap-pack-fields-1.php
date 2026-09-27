<?php
if (($_GET['k'] ?? '') !== 'pack-fields-swap-20260927-1') {
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
  echo 'css=' . copy_swap($assets . '/admin.css.new-pack-fields-1', $assets . '/admin.css') . "\n";
  echo 'js=' . copy_swap($assets . '/admin.js.new-pack-fields-1', $assets . '/admin.js') . "\n";
  echo 'freight=' . copy_swap($root . '/admin-freight.html.new-pack-fields-1', $root . '/admin-freight.html') . "\n";
  echo 'inv=' . copy_swap($root . '/admin-inventory.html.new-pack-fields-1', $root . '/admin-inventory.html') . "\n";
  $css = file_get_contents($assets . '/admin.css') ?: '';
  $js = file_get_contents($assets . '/admin.js') ?: '';
  $freight = file_get_contents($root . '/admin-freight.html') ?: '';
  echo 'cssMarker=' . (strpos($css, 'LZ_PACK_FIELDS_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'jsMarker=' . (strpos($js, 'LZ_PACK_FIELDS_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'jsGrid=' . (strpos($js, 'installFreightPackagePoolGrid') !== false ? 'yes' : 'no') . "\n";
  echo 'freightGrid=' . (strpos($freight, 'data-freight-package-pool-grid') !== false ? 'yes' : 'no') . "\n";
  echo 'freightBust=' . (strpos($freight, '20260927-pack-fields-1') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
