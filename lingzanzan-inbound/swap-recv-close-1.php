<?php
if (($_GET['k'] ?? '') !== 'recv-close-swap-20260926-1') {
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
  echo 'js=' . copy_swap($assets . '/admin.js.new-recv-close-1', $assets . '/admin.js') . "\n";
  echo 'css=' . copy_swap($assets . '/admin.css.new-recv-close-1', $assets . '/admin.css') . "\n";
  echo 'freightHtml=' . copy_swap($root . '/admin-freight.html.new-recv-close-1', $root . '/admin-freight.html') . "\n";
  echo 'ordersHtml=' . copy_swap($root . '/admin-orders.html.new-recv-close-1', $root . '/admin-orders.html') . "\n";
  $js = file_get_contents($assets . '/admin.js') ?: '';
  $css = file_get_contents($assets . '/admin.css') ?: '';
  $freight = file_get_contents($root . '/admin-freight.html') ?: '';
  echo 'jsMarker=' . (strpos($js, 'LZ_RECV_CLOSE_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'jsScroller=' . (strpos($js, 'freight-received-print-scroller') !== false ? 'yes' : 'no') . "\n";
  echo 'jsPrintBtnKept=' . (strpos($js, 'LZ_PRINT_BTN_TEXT_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'cssZ=' . (strpos($css, 'z-index:10050') !== false ? 'yes' : 'no') . "\n";
  echo 'cssScroller=' . (strpos($css, 'freight-received-print-scroller') !== false ? 'yes' : 'no') . "\n";
  echo 'freightBust=' . (strpos($freight, '20260926-recv-close-1') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
