<?php
if (($_GET['k'] ?? '') !== 'ygf-pick-del-swap-20260927-1') {
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
  echo 'scannerJs=' . copy_swap($assets . '/scanner-ygf-v59.js.new-pick-del-1', $assets . '/scanner-ygf-v59.js') . "\n";
  echo 'scannerCss=' . copy_swap($assets . '/scanner-ygf-v59.css.new-pick-del-1', $assets . '/scanner-ygf-v59.css') . "\n";
  echo 'scannerApi=' . copy_swap($root . '/scanner-api.php.new-pick-del-1', $root . '/scanner-api.php') . "\n";
  echo 'scannerHtml=' . copy_swap($root . '/scanner.html.new-pick-del-1', $root . '/scanner.html') . "\n";
  $js = file_get_contents($assets . '/scanner-ygf-v59.js') ?: '';
  $css = file_get_contents($assets . '/scanner-ygf-v59.css') ?: '';
  $api = file_get_contents($root . '/scanner-api.php') ?: '';
  $html = file_get_contents($root . '/scanner.html') ?: '';
  echo 'jsMarker=' . (strpos($js, 'LZ_YGF_PICK_DEL_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'jsConfirm=' . (strpos($js, 'data-match-confirm') !== false ? 'yes' : 'no') . "\n";
  echo 'jsKeep=' . (strpos($js, 'keepPicker') !== false ? 'yes' : 'no') . "\n";
  echo 'jsDeleteOwn=' . (strpos($js, 'ownSession') !== false ? 'yes' : 'no') . "\n";
  echo 'cssMarker=' . (strpos($css, 'LZ_YGF_PICK_DEL_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'apiMarker=' . (strpos($api, 'LZ_YGF_PICK_DEL_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($html, '20260927-pick-del-1') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
