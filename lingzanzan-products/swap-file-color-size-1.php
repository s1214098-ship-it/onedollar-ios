<?php
if (($_GET['k'] ?? '') !== 'color-size-swap-20260928-1') {
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
  echo 'overlay=' . copy_swap($assets . '/admin-product-forwarder-cost-9.js.new-color-size-1', $assets . '/admin-product-forwarder-cost-9.js') . "\n";
  echo 'css=' . copy_swap($assets . '/admin-products-horizontal-9.css.new-color-size-1', $assets . '/admin-products-horizontal-9.css') . "\n";
  echo 'html=' . copy_swap($root . '/admin-products.html.new-color-size-1', $root . '/admin-products.html') . "\n";
  $js = file_get_contents($assets . '/admin-product-forwarder-cost-9.js') ?: '';
  $html = file_get_contents($root . '/admin-products.html') ?: '';
  $css = file_get_contents($assets . '/admin-products-horizontal-9.css') ?: '';
  echo 'jsMarker=' . (strpos($js, 'LZ_FILE_COLOR_SIZE_20260928') !== false ? 'yes' : 'no') . "\n";
  echo 'jsPending=' . (strpos($js, 'function pendingProductColorName') !== false ? 'yes' : 'no') . "\n";
  echo 'jsMatrix=' . (strpos($js, 'function ensureProductDraftMatrixCombinations') !== false ? 'yes' : 'no') . "\n";
  echo 'jsSizeMod=' . (strpos($js, 'function applyProductSizeModuleFromSelect') !== false ? 'yes' : 'no') . "\n";
  echo 'jsCode911=' . (strpos($js, '淺藍色永遠 911') !== false ? 'yes' : 'no') . "\n";
  echo 'cssMarker=' . (strpos($css, 'LZ_FILE_COLOR_SIZE_20260928') !== false ? 'yes' : 'no') . "\n";
  echo 'jsMainImg=' . (strpos($js, 'function applySelectedProductColorMainImage') !== false ? 'yes' : 'no') . "\n";
  echo 'jsDraftPick=' . (strpos($js, 'data-product-draft-image') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($html, 'admin-product-forwarder-cost-9.js?v=20260928-color-size-2') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlCss=' . (strpos($html, 'admin-products-horizontal-9.css?v=20260928-color-size-2') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlAddBtn=' . (strpos($html, '>新增顏色<') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlMatrix=' . (strpos($html, '6. 顏色尺碼矩陣') !== false ? 'yes' : 'no') . "\n";
  echo 'ok';
} catch (Exception $e) {
  http_response_code(500);
  echo 'error=' . $e->getMessage();
}
