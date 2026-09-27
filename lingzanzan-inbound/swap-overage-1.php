<?php
if (($_GET['k'] ?? '') !== 'overage-swap-20260927-1') {
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
  echo 'adminJs=' . copy_swap($assets . '/admin.js.new-overage-1', $assets . '/admin.js') . "\n";
  echo 'freightHtml=' . copy_swap($root . '/admin-freight.html.new-overage-1', $root . '/admin-freight.html') . "\n";
  echo 'freightApi=' . copy_swap($root . '/freight-tracking-api.php.new-overage-1', $root . '/freight-tracking-api.php') . "\n";
  $admin = file_get_contents($assets . '/admin.js') ?: '';
  $html = file_get_contents($root . '/admin-freight.html') ?: '';
  $api = file_get_contents($root . '/freight-tracking-api.php') ?: '';
  echo 'jsMarker=' . (strpos($admin, 'LZ_RECV_OVERAGE_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'jsAdjusted=' . (substr_count($admin, 'adjustedExpectedQty:') >= 4 ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($html, '20260927-overage-1') !== false ? 'yes' : 'no') . "\n";
  echo 'phpMarker=' . (strpos($api, 'LZ_RECV_OVERAGE_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'phpNoBlock=' . (strpos($api, '更正後應到數量不能少於') === false ? 'yes' : 'no') . "\n";
  echo 'phpBump=' . (strpos($api, 'if (!$lineVoided && $expected < $actual)') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
