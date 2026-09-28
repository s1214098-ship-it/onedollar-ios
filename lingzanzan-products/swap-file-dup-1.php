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
  echo 'css=' . copy_swap($assets . '/admin-products-no-recog-1.css', $assets . '/admin-products-no-recog-1.css') . "\n";
  echo 'html=' . copy_swap($root . '/admin-products.html.new-file-dup-1', $root . '/admin-products.html') . "\n";
  $js = file_get_contents($assets . '/admin-product-forwarder-cost-9.js') ?: '';
  $paste = file_get_contents($assets . '/image-upload-paste.js') ?: '';
  $html = file_get_contents($root . '/admin-products.html') ?: '';
  $css = file_get_contents($assets . '/admin-products-no-recog-1.css') ?: '';
  echo 'jsMarker=' . (strpos($js, 'LZ_FILE_COLOR_DUP_20260928') !== false ? 'yes' : 'no') . "\n";
  echo 'jsNoRecog=' . (strpos($js, 'LZ_NO_RECOG_20260928') !== false ? 'yes' : 'no') . "\n";
  echo 'jsStripUi=' . (strpos($js, 'function stripProductImageRecognitionUi') !== false ? 'yes' : 'no') . "\n";
  echo 'jsDedupeImage=' . (strpos($js, 'function sameProductDraftImage') !== false ? 'yes' : 'no') . "\n";
  echo 'pasteMarker=' . (strpos($paste, 'LZ_FILE_COLOR_DUP_20260928') !== false ? 'yes' : 'no') . "\n";
  echo 'cssMarker=' . (strpos($css, 'LZ_NO_RECOG_20260928') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($html, 'admin-product-forwarder-cost-9.js?v=20260928-no-recog-1') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlCss=' . (strpos($html, 'admin-products-no-recog-1.css?v=20260928-no-recog-1') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlNoRecogBtn=' . (strpos($html, 'data-start-product-image-recognition') === false ? 'yes' : 'no') . "\n";
  echo 'htmlPasteRow=' . (strpos($html, 'product-photo-paste-row') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlNoAdd=' . (strpos($html, 'data-product-add-image') === false ? 'yes' : 'no') . "\n";
  echo 'ok';
} catch (Exception $e) {
  http_response_code(500);
  echo 'error=' . $e->getMessage();
}
