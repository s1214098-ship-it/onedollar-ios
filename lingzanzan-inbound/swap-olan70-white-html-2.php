<?php
if (($_GET['k'] ?? '') !== 'olan70-white-html-20260924-2') {
  http_response_code(403);
  echo 'no';
  exit;
}
header('Content-Type: text/plain; charset=utf-8');
$src = __DIR__ . '/admin-inventory-entry.html.new-olan70-white-1';
$dest = __DIR__ . '/admin-inventory-entry.html';
$raw = file_get_contents($src);
if ($raw === false) {
  http_response_code(500);
  echo "ERR read src\n";
  exit;
}
$ok = false;
$last = '';
for ($i = 0; $i < 12; $i++) {
  if (@file_put_contents($dest, $raw, LOCK_EX) !== false) { $ok = true; break; }
  $err = error_get_last();
  $last = $err['message'] ?? 'write fail';
  usleep(250000);
}
if (!$ok) {
  http_response_code(500);
  echo 'ERR ' . $last . "\n";
  exit;
}
$html = file_get_contents($dest) ?: '';
echo 'htmlBytes=' . strlen($html) . "\n";
echo 'htmlBust=' . (strpos($html, '20260924-olan70-white-2') !== false ? 'yes' : 'no') . "\n";
echo "ok\n";
