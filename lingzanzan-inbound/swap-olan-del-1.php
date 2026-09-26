<?php
if (($_GET['k'] ?? '') !== 'olan-del-swap-20260926-1') {
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
  echo 'php=' . copy_swap($root . '/stock-inquiry-api.php.new-olan-del-1', $root . '/stock-inquiry-api.php') . "\n";
  echo 'js=' . copy_swap($assets . '/inventory-freight-entry-20260810.js.new-olan-del-1', $assets . '/inventory-freight-entry-20260810.js') . "\n";
  echo 'html=' . copy_swap($root . '/admin-inventory-entry.html.new-olan-del-1', $root . '/admin-inventory-entry.html') . "\n";
  $php = file_get_contents($root . '/stock-inquiry-api.php') ?: '';
  $js = file_get_contents($assets . '/inventory-freight-entry-20260810.js') ?: '';
  $html = file_get_contents($root . '/admin-inventory-entry.html') ?: '';
  echo 'phpMarker=' . (strpos($php, 'LZ_RECV_DEL_20260926B') !== false ? 'yes' : 'no') . "\n";
  echo 'jsMarker=' . (strpos($js, 'LZ_RECV_DEL_20260926B') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($html, '20260926-olan-del-1') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
