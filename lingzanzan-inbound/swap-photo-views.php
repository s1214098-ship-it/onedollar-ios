<?php
if (($_GET['k'] ?? '') !== 'photo-views-swap-20260926-1') {
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
  echo 'js=' . copy_swap($assets . '/inventory-freight-entry-20260810.js.new-photo-views-1', $assets . '/inventory-freight-entry-20260810.js') . "\n";
  echo 'css=' . copy_swap($assets . '/inventory-freight-entry.css.new-photo-views-1', $assets . '/inventory-freight-entry.css') . "\n";
  echo 'html=' . copy_swap($root . '/admin-inventory-entry.html.new-photo-views-1', $root . '/admin-inventory-entry.html') . "\n";
  echo 'api=' . copy_swap($root . '/stock-inquiry-api.php.new-photo-views-1', $root . '/stock-inquiry-api.php') . "\n";
  $js = file_get_contents($assets . '/inventory-freight-entry-20260810.js') ?: '';
  $css = file_get_contents($assets . '/inventory-freight-entry.css') ?: '';
  $html = file_get_contents($root . '/admin-inventory-entry.html') ?: '';
  $api = file_get_contents($root . '/stock-inquiry-api.php') ?: '';
  echo 'jsMark=' . (strpos($js, 'LZ_PHOTO_VIEWS_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'cssMark=' . (strpos($css, 'LZ_PHOTO_VIEWS_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlMark=' . (strpos($html, 'photo-views-1') !== false ? 'yes' : 'no') . "\n";
  echo 'apiMark=' . (strpos($api, 'LZ_PHOTO_VIEWS_20260926') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
