<?php
declare(strict_types=1);
/**
 * LZ_PDA_CAM_20260927
 * YGF F20 WebView dies if it decodes a 13MP camera JPEG locally.
 * Shrink on the server, return a small JPEG data URL.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => '請用拍照上傳'], JSON_UNESCAPED_UNICODE);
  exit;
}

if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => '伺服器無法縮小照片'], JSON_UNESCAPED_UNICODE);
  exit;
}

$file = $_FILES['photo'] ?? null;
$tmp = is_array($file) ? strval($file['tmp_name'] ?? '') : '';
$size = is_array($file) ? intval($file['size'] ?? 0) : 0;
if ($tmp === '' || !is_uploaded_file($tmp)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => '沒有收到照片'], JSON_UNESCAPED_UNICODE);
  exit;
}
if ($size <= 0 || $size > 10 * 1024 * 1024) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => '照片太大，請靠近重拍'], JSON_UNESCAPED_UNICODE);
  exit;
}

@ini_set('memory_limit', '256M');
$raw = file_get_contents($tmp);
if ($raw === false || $raw === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => '照片讀取失敗'], JSON_UNESCAPED_UNICODE);
  exit;
}

$src = @imagecreatefromstring($raw);
if ($src === false) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => '這張照片格式盤點機無法縮小'], JSON_UNESCAPED_UNICODE);
  exit;
}

$width = imagesx($src);
$height = imagesy($src);
$maxEdge = 640;
$scale = min(1, $maxEdge / max(1, max($width, $height)));
$newW = max(1, (int) round($width * $scale));
$newH = max(1, (int) round($height * $scale));
$dst = imagecreatetruecolor($newW, $newH);
if ($dst === false) {
  imagedestroy($src);
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => '無法建立縮圖'], JSON_UNESCAPED_UNICODE);
  exit;
}
imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
imagedestroy($src);
ob_start();
imagejpeg($dst, null, 62);
imagedestroy($dst);
$jpeg = ob_get_clean();
if (!is_string($jpeg) || $jpeg === '') {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => '縮圖輸出失敗'], JSON_UNESCAPED_UNICODE);
  exit;
}

echo json_encode([
  'ok' => true,
  'dataUrl' => 'data:image/jpeg;base64,' . base64_encode($jpeg),
  'width' => $newW,
  'height' => $newH,
], JSON_UNESCAPED_UNICODE);
