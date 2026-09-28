<?php
if (($_GET['k'] ?? '') !== 'recv-print-swap-20260928-1') {
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
  echo 'adminJs=' . copy_swap($assets . '/admin.js.new-recv-print-1', $assets . '/admin.js') . "\n";
  echo 'adminCss=' . copy_swap($assets . '/admin.css.new-recv-print-1', $assets . '/admin.css') . "\n";
  echo 'freightHtml=' . copy_swap($root . '/admin-freight.html.new-recv-print-1', $root . '/admin-freight.html') . "\n";
  echo 'searchApi=' . copy_swap($root . '/admin-freight-search-api.php.new-recv-print-1', $root . '/admin-freight-search-api.php') . "\n";
  $js = file_get_contents($assets . '/admin.js') ?: '';
  $css = file_get_contents($assets . '/admin.css') ?: '';
  $html = file_get_contents($root . '/admin-freight.html') ?: '';
  $php = file_get_contents($root . '/admin-freight-search-api.php') ?: '';
  echo 'jsMarker=' . (strpos($js, 'LZ_RECV_PRINT_Z_20260928') !== false ? 'yes' : 'no') . "\n";
  echo 'jsLookup=' . (strpos($js, 'LZ_RECV_LOOKUP_20260928') !== false ? 'yes' : 'no') . "\n";
  echo 'jsCollect=' . (strpos($js, 'function freightReceivingCollectMatches') !== false ? 'yes' : 'no') . "\n";
  echo 'jsZ=' . (strpos($js, 'z-index:2147483647') !== false ? 'yes' : 'no') . "\n";
  echo 'jsFallback=' . (strpos($js, 'function freightOtherWarehouseFallbackPrintPayload') !== false ? 'yes' : 'no') . "\n";
  echo 'jsPlusOne=' . (strpos($js, 'data-freight-receiving-plus-one') !== false ? 'yes' : 'no') . "\n";
  echo 'jsExtraLocal=' . (strpos($js, 'function addFreightReceivingExtraForecastLocal') !== false ? 'yes' : 'no') . "\n";
  echo 'cssMarker=' . (strpos($css, 'LZ_RECV_PRINT_Z_20260928') !== false ? 'yes' : 'no') . "\n";
  echo 'cssLookup=' . (strpos($css, 'LZ_RECV_LOOKUP_20260928') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($html, '20260928-recv-color-1') !== false ? 'yes' : 'no') . "\n";
  echo 'phpLookup=' . (strpos($php, 'LZ_RECV_LOOKUP_20260928') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
