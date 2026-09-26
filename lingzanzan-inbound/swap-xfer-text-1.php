<?php
if (($_GET['k'] ?? '') !== 'xfer-text-swap-20260926-1') {
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
  echo 'css=' . copy_swap($assets . '/scanner-ygf-v59.css.new-xfer-text-1', $assets . '/scanner-ygf-v59.css') . "\n";
  echo 'html=' . copy_swap($root . '/scanner.html.new-xfer-text-1', $root . '/scanner.html') . "\n";
  $css = file_get_contents($assets . '/scanner-ygf-v59.css') ?: '';
  $html = file_get_contents($root . '/scanner.html') ?: '';
  echo 'cssMarker=' . (strpos($css, 'LZ_XFER_TEXT_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'cssCream=' . (strpos($css, '#fff8ed') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($html, '20260926-xfer-text-1') !== false ? 'yes' : 'no') . "\n";
  echo 'xferSelectKept=' . (strpos($css, 'LZ_XFER_SELECT_20260926') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
