<?php
if (($_GET['k'] ?? '') !== 'workline-palette-swap-20260926-1') {
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
  echo 'css=' . copy_swap($assets . '/admin-black-buttons.css.new-workline-palette-1', $assets . '/admin-black-buttons.css') . "\n";
  $css = file_get_contents($assets . '/admin-black-buttons.css') ?: '';
  echo 'marker=' . (strpos($css, 'LZ_WORKLINE_PALETTE_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'blackKept=' . (strpos($css, 'LZ_BLACK_BUTTONS_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'noGoldFill=' . (strpos($css, 'button.freight-workline-row.is-active') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
