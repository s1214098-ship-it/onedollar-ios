<?php
if (($_GET['k'] ?? '') !== 'ygf-qty-swap-20260927-13') {
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
  echo 'scannerJs=' . copy_swap($assets . '/scanner-ygf-v59.js.new-ygf-qty-13', $assets . '/scanner-ygf-v59.js') . "\n";
  echo 'scannerCss=' . copy_swap($assets . '/scanner-ygf-v59.css.new-ygf-qty-13', $assets . '/scanner-ygf-v59.css') . "\n";
  echo 'scannerHtml=' . copy_swap($root . '/scanner.html.new-ygf-qty-13', $root . '/scanner.html') . "\n";
  $js = file_get_contents($assets . '/scanner-ygf-v59.js') ?: '';
  $html = file_get_contents($root . '/scanner.html') ?: '';
  $css = file_get_contents($assets . '/scanner-ygf-v59.css') ?: '';
  echo 'jsMarker=' . (strpos($js, 'LZ_YGF_QTY_SAVE13_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'jsSync=' . (strpos($js, 'function syncStocktakeOnlyChrome') !== false ? 'yes' : 'no') . "\n";
  echo 'cssHide=' . (strpos($css, '調撥頁不要出現盤點送出') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($html, '20260927-ygf-qty-13') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlV174=' . (strpos($html, '版本 v174') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
