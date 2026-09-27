<?php
if (($_GET['k'] ?? '') !== 'recv-close-swap-20260927-3') {
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
  echo 'adminJs=' . copy_swap($assets . '/admin.js.new-recv-close-3', $assets . '/admin.js') . "\n";
  echo 'adminCss=' . copy_swap($assets . '/admin.css.new-recv-close-3', $assets . '/admin.css') . "\n";
  echo 'freightHtml=' . copy_swap($root . '/admin-freight.html.new-recv-close-3', $root . '/admin-freight.html') . "\n";
  $js = file_get_contents($assets . '/admin.js') ?: '';
  $css = file_get_contents($assets . '/admin.css') ?: '';
  $html = file_get_contents($root . '/admin-freight.html') ?: '';
  echo 'jsMarker=' . (strpos($js, 'LZ_RECV_CLOSE_AGAIN_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'jsHead=' . (strpos($js, 'freight-received-print-head') !== false ? 'yes' : 'no') . "\n";
  echo 'jsAllClose=' . (strpos($js, "querySelectorAll('[data-freight-print-ready-close]')") !== false ? 'yes' : 'no') . "\n";
  echo 'cssSticky=' . (strpos($css, 'LZ_RECV_CLOSE_AGAIN_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($html, '20260927-recv-close-3') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
