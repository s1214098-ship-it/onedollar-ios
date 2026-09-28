<?php
if (($_GET['k'] ?? '') !== 'cat-vendor-swap-20260928-1') {
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

function bump_admin_js($file) {
  if (!is_file($file)) return 'missing';
  $raw = file_get_contents($file);
  $next = preg_replace('/admin\.js\?v=[^"\']+/', 'admin.js?v=20260928-cat-vendor-1', $raw, 1, $count);
  if ($count) file_put_contents($file, $next);
  return $count ? 'yes' : 'no';
}

try {
  echo 'adminJs=' . copy_swap($assets . '/admin.js.new-cat-vendor-1', $assets . '/admin.js') . "\n";
  echo 'adminCss=' . copy_swap($assets . '/admin.css.new-cat-vendor-1', $assets . '/admin.css') . "\n";
  echo 'inboundJs=' . copy_swap($assets . '/inventory-freight-entry-20260810.js.new-cat-vendor-1', $assets . '/inventory-freight-entry-20260810.js') . "\n";
  echo 'inboundCss=' . copy_swap($assets . '/inventory-freight-entry.css.new-cat-vendor-1', $assets . '/inventory-freight-entry.css') . "\n";
  echo 'inboundHtml=' . copy_swap($root . '/admin-inventory-entry.html.new-cat-vendor-1', $root . '/admin-inventory-entry.html') . "\n";
  echo 'freightHtml=' . copy_swap($root . '/admin-freight.html.new-cat-vendor-1', $root . '/admin-freight.html') . "\n";
  echo 'ordersBust=' . bump_admin_js($root . '/admin-orders.html') . "\n";
  echo 'preordersBust=' . bump_admin_js($root . '/admin-preorders.html') . "\n";
  echo 'liveBust=' . bump_admin_js($root . '/admin-live.html') . "\n";
  $js = file_get_contents($assets . '/admin.js') ?: '';
  $inbound = file_get_contents($assets . '/inventory-freight-entry-20260810.js') ?: '';
  $html = file_get_contents($root . '/admin-inventory-entry.html') ?: '';
  echo 'jsMarker=' . (strpos($js, 'LZ_CAT_VENDOR_PICK_20260928') !== false ? 'yes' : 'no') . "\n";
  echo 'jsVendorTab=' . (strpos($js, 'data-catalog-category-pick-mode="vendor"') !== false ? 'yes' : 'no') . "\n";
  echo 'jsApplySelected=' . (strpos($js, 'function catalogCategoryPickerApplySelected') !== false ? 'yes' : 'no') . "\n";
  echo 'jsShipmentBrowse=' . (strpos($js, 'adminShipmentBrowseIds') !== false ? 'yes' : 'no') . "\n";
  echo 'inboundMarker=' . (strpos($inbound, 'LZ_CAT_VENDOR_PICK_20260928') !== false ? 'yes' : 'no') . "\n";
  echo 'inboundBrowse=' . (strpos($inbound, 'function renderInboundCatalogBrowse') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlVendor=' . (strpos($html, 'data-purchase-receipt-browse-vendor') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlCatLabel=' . (strpos($html, '依分類找產品') !== false ? 'yes' : 'no') . "\n";
  echo 'ok';
} catch (Exception $e) {
  http_response_code(500);
  echo 'error=' . $e->getMessage();
}
