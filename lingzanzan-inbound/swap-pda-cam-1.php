<?php
if (($_GET['k'] ?? '') !== 'pda-cam-swap-20260927-1') {
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
  echo 'js=' . copy_swap($assets . '/scanner-ygf-v59.js.new-pda-cam-1', $assets . '/scanner-ygf-v59.js') . "\n";
  echo 'html=' . copy_swap($root . '/scanner.html.new-pda-cam-1', $root . '/scanner.html') . "\n";
  echo 'php=' . copy_swap($root . '/scanner-photo-shrink.php.new-pda-cam-1', $root . '/scanner-photo-shrink.php') . "\n";
  $js = file_get_contents($assets . '/scanner-ygf-v59.js') ?: '';
  $html = file_get_contents($root . '/scanner.html') ?: '';
  $php = file_get_contents($root . '/scanner-photo-shrink.php') ?: '';
  echo 'jsMarker=' . (strpos($js, 'LZ_PDA_CAM_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'jsCapture=' . (strpos($js, 'scannerCaptureVideoFrame') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($html, '20260927-pda-cam-1') !== false ? 'yes' : 'no') . "\n";
  echo 'phpMarker=' . (strpos($php, 'LZ_PDA_CAM_20260927') !== false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
