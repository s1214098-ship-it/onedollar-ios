<?php
if (($_GET['k'] ?? '') !== 'color-first-swap-20260927-1') {
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
  echo 'adminJs=' . copy_swap($assets . '/admin.js.new-color-first-1', $assets . '/admin.js') . "\n";
  echo 'adminCss=' . copy_swap($assets . '/admin.css.new-color-first-1', $assets . '/admin.css') . "\n";
  echo 'bbCss=' . copy_swap($assets . '/admin-black-buttons.css.new-color-first-1', $assets . '/admin-black-buttons.css') . "\n";
  echo 'freightHtml=' . copy_swap($root . '/admin-freight.html.new-color-first-1', $root . '/admin-freight.html') . "\n";
  $admin = file_get_contents($assets . '/admin.js') ?: '';
  $css = file_get_contents($assets . '/admin.css') ?: '';
  $bb = file_get_contents($assets . '/admin-black-buttons.css') ?: '';
  $freight = file_get_contents($root . '/admin-freight.html') ?: '';
  echo 'colorFirst=' . (strpos($admin, 'LZ_RECV_COLOR_FIRST_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'keepForm=' . (strpos($admin, 'LZ_RECV_KEEP_FORM_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'chipPhoto=' . (strpos($admin, 'imageInfo.isColorImage') !== false ? 'yes' : 'no') . "\n";
  echo 'noFirstPick=' . (strpos($admin, '請先點選要帶入的顏色／尺寸') !== false ? 'yes' : 'no') . "\n";
  echo 'cssGrid=' . (strpos($css, 'LZ_RECV_COLOR_FIRST_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'bbChip=' . (strpos($bb, 'LZ_RECV_COLOR_FIRST_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'freightBust=' . (strpos($freight, '20260927-color-first-1') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
