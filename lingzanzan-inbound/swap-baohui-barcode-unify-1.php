<?php
if (($_GET['k'] ?? '') !== 'baohui-barcode-unify-swap-20260926-1') {
  http_response_code(403);
  echo 'no';
  exit;
}
header('Content-Type: text/plain; charset=utf-8');
$root = __DIR__;

function copy_swap($src, $dest) {
  if (!is_file($src)) throw new Exception('missing ' . $src);
  $tmp = $dest . '.tmp-' . bin2hex(random_bytes(3));
  if (!copy($src, $tmp)) throw new Exception('copy fail ' . $src);
  if (is_file($dest) && !unlink($dest)) { @unlink($tmp); throw new Exception('unlink fail ' . $dest); }
  if (!rename($tmp, $dest)) { @unlink($tmp); throw new Exception('rename fail ' . $dest); }
  return filesize($dest);
}

try {
  echo 'ops=' . copy_swap($root . '/operations.php.new-barcode-unify-print-1', $root . '/operations.php') . "\n";
  $ops = file_get_contents($root . '/operations.php') ?: '';
  echo 'opsMarker=' . (strpos($ops, 'LZ_BARCODE_UNIFY_20260926') !== false ? 'yes' : 'no') . "\n";
  echo 'opsCsGone=' . (strpos($ops, ". 'P' . \$costPart . 'C' . \$colorCode . 'S'") === false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
