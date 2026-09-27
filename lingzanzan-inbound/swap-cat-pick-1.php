<?php
if (($_GET['k'] ?? '') !== 'cat-pick-swap-20260927-1') {
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
  echo 'adminJs=' . copy_swap($assets . '/admin.js.new-cat-pick-1', $assets . '/admin.js') . "\n";
  echo 'adminCss=' . copy_swap($assets . '/admin.css.new-cat-pick-1', $assets . '/admin.css') . "\n";
  echo 'invJs=' . copy_swap($assets . '/admin-inventory-color-auto-10.js.new-cat-pick-1', $assets . '/admin-inventory-color-auto-10.js') . "\n";
  echo 'invHtml=' . copy_swap($root . '/admin-inventory.html.new-cat-pick-1', $root . '/admin-inventory.html') . "\n";
  echo 'freightHtml=' . copy_swap($root . '/admin-freight.html.new-cat-pick-1', $root . '/admin-freight.html') . "\n";
  $extras = ['admin-orders.html', 'admin-live.html', 'admin-preorders.html', 'admin-preorder-waiting.html', 'admin-preorder-demand.html', 'admin-reserved-shipping.html', 'admin-sales.html'];
  foreach ($extras as $name) {
    $src = $root . '/' . $name . '.new-cat-pick-1';
    if (is_file($src)) echo $name . '=' . copy_swap($src, $root . '/' . $name) . "\n";
  }
  $js = file_get_contents($assets . '/admin.js') ?: '';
  $inv = file_get_contents($assets . '/admin-inventory-color-auto-10.js') ?: '';
  $css = file_get_contents($assets . '/admin.css') ?: '';
  $html = file_get_contents($root . '/admin-inventory.html') ?: '';
  $freight = file_get_contents($root . '/admin-freight.html') ?: '';
  echo 'jsMarker=' . (strpos($js, 'LZ_CAT_PICK_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'jsBind=' . (strpos($js, 'bindCatalogCategoryPicker') !== false ? 'yes' : 'no') . "\n";
  echo 'jsFifoBtn=' . (strpos($js, 'data-catalog-category-picker="fifo"') !== false ? 'yes' : 'no') . "\n";
  echo 'invMarker=' . (strpos($inv, 'LZ_CAT_PICK_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'cssMarker=' . (strpos($css, 'LZ_CAT_PICK_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'invHtmlBtn=' . (strpos($html, 'data-catalog-category-picker="inventory"') !== false ? 'yes' : 'no') . "\n";
  echo 'invHtmlBust=' . (strpos($html, '20260927-cat-pick-1') !== false ? 'yes' : 'no') . "\n";
  echo 'freightHtmlBtn=' . (strpos($freight, 'data-catalog-category-picker="freight-existing"') !== false ? 'yes' : 'no') . "\n";
  echo 'freightHtmlBust=' . (strpos($freight, '20260927-cat-pick-1') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
