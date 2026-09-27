<?php
if (($_GET['k'] ?? '') !== 'chip-pm-swap-20260927-1') {
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
  echo 'adminJs=' . copy_swap($assets . '/admin.js.new-chip-pm-1', $assets . '/admin.js') . "\n";
  echo 'adminCss=' . copy_swap($assets . '/admin.css.new-chip-pm-1', $assets . '/admin.css') . "\n";
  echo 'bbCss=' . copy_swap($assets . '/admin-black-buttons.css.new-chip-pm-1', $assets . '/admin-black-buttons.css') . "\n";
  echo 'freightHtml=' . copy_swap($root . '/admin-freight.html.new-chip-pm-1', $root . '/admin-freight.html') . "\n";
  $admin = file_get_contents($assets . '/admin.js') ?: '';
  $css = file_get_contents($assets . '/admin.css') ?: '';
  $bb = file_get_contents($assets . '/admin-black-buttons.css') ?: '';
  echo 'chipPm=' . (strpos($admin, 'LZ_RECV_CHIP_PM_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'divChip=' . (strpos($admin, "class=\"freight-receiving-color-chip") !== false && strpos($admin, '<button type="button" class="freight-receiving-color-chip') === false ? 'yes' : 'no') . "\n";
  echo 'qtyPlus=' . (strpos($css, 'content:"＋"') !== false || strpos($css, 'content: "＋"') !== false ? 'yes' : 'no') . "\n";
  echo 'creamChip=' . (strpos($css, '#fffaf2') !== false ? 'yes' : 'no') . "\n";
  echo 'bbPlus=' . (strpos($bb, 'LZ_RECV_CHIP_PM_20260927') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
