<?php
if (($_GET['k'] ?? '') !== 'vendor-blank-swap-20261024-1') {
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
  echo 'html=' . copy_swap($root . '/admin-inventory-entry.html.new-vendor-blank-1', $root . '/admin-inventory-entry.html') . "\n";
  echo 'js=' . copy_swap($assets . '/inventory-freight-entry-20260810.js.new-vendor-blank-1', $assets . '/inventory-freight-entry-20260810.js') . "\n";
  $html = file_get_contents($root . '/admin-inventory-entry.html') ?: '';
  $js = file_get_contents($assets . '/inventory-freight-entry-20260810.js') ?: '';
  echo 'htmlBlank=' . (strpos($html, 'LZ_VENDOR_BLANK_20261024') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlPlaceholder=' . (strpos($html, '空白可直接打入新增') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlAddBtn=' . (strpos($html, 'data-purchase-receipt-add-supplier') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlPlatformBtn=' . (strpos($html, 'data-purchase-receipt-add-platform') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($html, '20261024-vendor-blank-1') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlNoHidePlatform=' . (strpos($html, 'LZ_NO_PLATFORM_20261008') === false ? 'yes' : 'no') . "\n";
  echo 'jsBlank=' . (strpos($js, 'LZ_VENDOR_BLANK_20261024') !== false ? 'yes' : 'no') . "\n";
  echo 'jsEnsure=' . (strpos($js, 'ensureBlankPurchaseFields') !== false ? 'yes' : 'no') . "\n";
  echo 'jsConvertSelect=' . (strpos($js, "el.tagName === 'SELECT'") !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
