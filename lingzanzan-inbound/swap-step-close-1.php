<?php
if (($_GET['k'] ?? '') !== 'step-close-swap-20260927-1') {
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
  echo 'js=' . copy_swap($assets . '/admin.js.new-step-close-1', $assets . '/admin.js') . "\n";
  echo 'css=' . copy_swap($assets . '/admin.css.new-step-close-1', $assets . '/admin.css') . "\n";
  echo 'bb=' . copy_swap($assets . '/admin-black-buttons.css.new-step-close-1', $assets . '/admin-black-buttons.css') . "\n";
  echo 'freightHtml=' . copy_swap($root . '/admin-freight.html.new-step-close-1', $root . '/admin-freight.html') . "\n";
  $js = file_get_contents($assets . '/admin.js') ?: '';
  $css = file_get_contents($assets . '/admin.css') ?: '';
  $bb = file_get_contents($assets . '/admin-black-buttons.css') ?: '';
  $freight = file_get_contents($root . '/admin-freight.html') ?: '';
  echo 'jsKeep=' . (strpos($js, 'LZ_RECV_CLOSE_KEEP_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'jsStep=' . (strpos($js, 'LZ_SIZE_STEP_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'jsPlus=' . (strpos($js, 'data-freight-size-matrix-plus>＋</button>') !== false ? 'yes' : 'no') . "\n";
  echo 'jsNoAuto=' . (strpos($js, 'openInventoryBarcodePrint(readyPrintPayloads[0])') === false ? 'yes' : 'no') . "\n";
  echo 'jsPdaCam=' . (strpos($js, 'LZ_PDA_CAM_20260927') !== false ? 'kept-or-absent' : 'absent') . "\n";
  echo 'cssZ=' . (strpos($css, 'z-index: 11040') !== false ? 'yes' : 'no') . "\n";
  echo 'bbStep=' . (strpos($bb, 'LZ_SIZE_STEP_20260927') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlBust=' . (strpos($freight, '20260927-step-close-1') !== false ? 'yes' : 'no') . "\n";
  echo 'htmlHintGone=' . (strpos($freight, '先選上方顏色，再直接填各尺寸數量') === false ? 'yes' : 'no') . "\n";
  echo "ok\n";
} catch (Exception $e) {
  http_response_code(500);
  echo 'ERR ' . $e->getMessage() . "\n";
}
