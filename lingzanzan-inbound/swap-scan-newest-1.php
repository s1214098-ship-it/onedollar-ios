<?php
if (($_GET['k'] ?? '') !== 'ygf-scan-newest-swap-20260927-1') {
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
  echo 'js=' . copy_swap($assets . '/scanner-ygf-v59.js.new-scan-newest-1', $assets . '/scanner-ygf-v59.js') . "\n";
  echo 'css=' . copy_swap($assets . '/scanner-ygf-v59.css.new-scan-newest-1', $assets . '/scanner-ygf-v59.css') . "\n";
  echo 'html=' . copy_swap($root . '/scanner.html.new-scan-newest-1', $root . '/scanner.html') . "\n";
  echo 'api=' . copy_swap($root . '/scanner-api.php.new-scan-newest-1', $root . '/scanner-api.php') . "\n";
  $js = file_get_contents($assets . '/scanner-ygf-v59.js') ?: '';
  $css = file_get_contents($assets . '/scanner-ygf-v59.css') ?: '';
  $html = file_get_contents($root . '/scanner.html') ?: '';
  $api = file_get_contents($root . '/scanner-api.php') ?: '';
  echo 'jsMarker=' . (strpos($js, 'LZ_YGF_SCAN_NEWEST_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'jsUnshift=' . (strpos($js, 'transferDraft.unshift({') !== false ? 'yes' : 'no') . "\n";
  echo 'jsTouch=' . (strpos($js, "['pointerup', 'touchend']") !== false ? 'yes' : 'no') . "\n";
  echo 'jsTab=' . (strpos($js, 'lingzanzan-scanner-tab-v1') !== false ? 'yes' : 'no') . "\n";
  echo 'cssMarker=' . (strpos($css, 'is-match-picker-open') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($html, '20260927-scan-newest-2') !== false ? 'yes' : 'no') . "\n";
  echo 'jsQty=' . (strpos($js, '不必先選貨架') !== false ? 'yes' : 'no') . "\n";
  echo 'apiUnshift=' . (strpos($api, "array_unshift(\$sessions[\$found]['lines'],\$line)") !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
