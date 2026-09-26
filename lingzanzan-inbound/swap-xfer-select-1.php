<?php
if (($_GET['k'] ?? '') !== 'xfer-select-swap-20260926-1') {
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
  echo 'scannerApi=' . copy_swap($root . '/scanner-api.php.new-xfer-select-1', $root . '/scanner-api.php') . "\n";
  echo 'scannerJs=' . copy_swap($assets . '/scanner-ygf-v59.js.new-xfer-select-1', $assets . '/scanner-ygf-v59.js') . "\n";
  echo 'scannerCss=' . copy_swap($assets . '/scanner-ygf-v59.css.new-xfer-select-1', $assets . '/scanner-ygf-v59.css') . "\n";
  echo 'scannerHtml=' . copy_swap($root . '/scanner.html.new-xfer-select-1', $root . '/scanner.html') . "\n";
  $api = file_get_contents($root . '/scanner-api.php') ?: '';
  $js = file_get_contents($assets . '/scanner-ygf-v59.js') ?: '';
  $css = file_get_contents($assets . '/scanner-ygf-v59.css') ?: '';
  $html = file_get_contents($root . '/scanner.html') ?: '';
  echo 'apiMarker=' . (strpos($api, 'LZ_XFER_SELECT_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'jsMarker=' . (strpos($js, 'LZ_XFER_SELECT_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'jsConflict=' . (strpos($js, 'lookupHasProductConflict') !== false ? 'yes' : 'no') . "\n";
  echo 'cssPhoto=' . (strpos($css, 'match-card-photo') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($html, '20260926-xfer-select-1') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
