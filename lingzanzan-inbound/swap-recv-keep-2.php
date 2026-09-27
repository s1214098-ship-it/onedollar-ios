<?php
if (($_GET['k'] ?? '') !== 'recv-keep-swap-20260927-2') {
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
  echo 'adminJs=' . copy_swap($assets . '/admin.js.new-recv-keep-2', $assets . '/admin.js') . "\n";
  echo 'adminCss=' . copy_swap($assets . '/admin.css.new-recv-keep-2', $assets . '/admin.css') . "\n";
  echo 'freightHtml=' . copy_swap($root . '/admin-freight.html.new-recv-keep-2', $root . '/admin-freight.html') . "\n";
  $admin = file_get_contents($assets . '/admin.js') ?: '';
  $css = file_get_contents($assets . '/admin.css') ?: '';
  $freight = file_get_contents($root . '/admin-freight.html') ?: '';
  echo 'keepMarker=' . (strpos($admin, 'LZ_RECV_KEEP_FORM_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'onclick=' . (strpos($admin, 'lzDismissFreightReceivedOverlay(event)') !== false ? 'yes' : 'no') . "\n";
  echo 'noWorkbenchWipe=' . (strpos($admin, "loadAdminFreightWorkbenchServerSearch('')") === false || strpos($admin, 'LZ_RECV_KEEP_FORM_20260927: 只清下一張物流單掃描格') !== false ? 'yes' : 'no') . "\n";
  echo 'zCss=' . (strpos($css, '2147483646') !== false ? 'yes' : 'no') . "\n";
  echo 'freightBust=' . (strpos($freight, '20260927-recv-keep-2') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
