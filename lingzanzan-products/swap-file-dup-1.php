<?php
if (($_GET['k'] ?? '') !== 'file-dup-swap-20260928-1') {
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
  echo 'overlay=' . copy_swap($assets . '/admin-product-forwarder-cost-9.js.new-file-dup-1', $assets . '/admin-product-forwarder-cost-9.js') . "\n";
  echo 'paste=' . copy_swap($assets . '/image-upload-paste.js.new-file-dup-1', $assets . '/image-upload-paste.js') . "\n";
  echo 'html=' . copy_swap($root . '/admin-products.html.new-file-dup-1', $root . '/admin-products.html') . "\n";
  $js = file_get_contents($assets . '/admin-product-forwarder-cost-9.js') ?: '';
  $paste = file_get_contents($assets . '/image-upload-paste.js') ?: '';
  $html = file_get_contents($root . '/admin-products.html') ?: '';
  echo 'jsMarker=' . (strpos($js, 'LZ_FILE_COLOR_DUP_20260928') !== false ? 'yes' : 'no') . "\n";
  echo 'jsStrip=' . (strpos($js, 'function stripProductColorCodePrefix') !== false ? 'yes' : 'no') . "\n";
  echo 'jsDedupeImage=' . (strpos($js, 'function sameProductDraftImage') !== false ? 'yes' : 'no') . "\n";
  echo 'jsDropBlack=' . (strpos($js, 'function dropUntouchedDefaultProductColor') !== false ? 'yes' : 'no') . "\n";
  echo 'jsLightBlue=' . (strpos($js, "'淺藍色': '911'") !== false ? 'yes' : 'no') . "\n";
  echo 'pasteMarker=' . (strpos($paste, 'LZ_FILE_COLOR_DUP_20260928') !== false ? 'yes' : 'no') . "\n";
  echo 'pasteSkipCard=' . (strpos($paste, "closest('.product-image-card, [data-product-paste-image]')") !== false ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($html, 'admin-product-forwarder-cost-9.js?v=20260928-file-dup-1') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlPasteBust=' . (strpos($html, 'image-upload-paste.js?v=20260928-file-dup-1') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlNoAuto=' . (strpos($html, 'data-no-auto-paste') !== false ? 'yes' : 'no') . "\n";
  echo 'ok';
} catch (Exception $e) {
  http_response_code(500);
  echo 'error=' . $e->getMessage();
}
