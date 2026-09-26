<?php
if (($_GET['k'] ?? '') !== 'quick-inbound-swap-20260926-1') {
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
  echo 'js=' . copy_swap($assets . '/inventory-freight-entry-20260810.js.new-quick-inb-1', $assets . '/inventory-freight-entry-20260810.js') . "\n";
  echo 'css=' . copy_swap($assets . '/inventory-freight-entry.css.new-quick-inb-1', $assets . '/inventory-freight-entry.css') . "\n";
  echo 'html=' . copy_swap($root . '/admin-inventory-entry.html.new-quick-inb-1', $root . '/admin-inventory-entry.html') . "\n";
  echo 'api=' . copy_swap($root . '/stock-inquiry-api.php.new-quick-inb-1', $root . '/stock-inquiry-api.php') . "\n";
  $js = file_get_contents($assets . '/inventory-freight-entry-20260810.js') ?: '';
  $css = file_get_contents($assets . '/inventory-freight-entry.css') ?: '';
  $html = file_get_contents($root . '/admin-inventory-entry.html') ?: '';
  $api = file_get_contents($root . '/stock-inquiry-api.php') ?: '';
  echo 'jsMark=' . (strpos($js, 'LZ_QUICK_INBOUND_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'cssMark=' . (strpos($css, 'LZ_QUICK_INBOUND_KEEP_CAT_COLOR_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlMark=' . (strpos($html, 'quick-inb-9') !== false ? 'yes' : 'no') . "\n";
  echo 'oldBarcode=' . (strpos($js, 'LZ_OLD_BARCODE_PCOST_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'typedCode=' . (strpos($js, 'LZ_TYPED_CODE_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'colorAutoId=' . (strpos($js, 'LZ_COLOR_AUTO_ID_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'replaceImgs=' . (strpos($js, 'LZ_REPLACE_IMGS_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'printOnPage=' . (strpos($js, 'LZ_PRINT_ONPAGE_20260926') !== false && strpos($css, 'LZ_PRINT_ONPAGE_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'apiMark=' . (strpos($api, 'LZ_REPLACE_IMGS_20260926') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
