/* LZ_PDA_CAM_20260927 excerpt */
function scannerCaptureVideoFrame(video) {
  var target = scannerPhotoTargetSize(video.videoWidth, video.videoHeight);
  return createImageBitmap(video, { resizeWidth: target.width, resizeHeight: target.height, resizeQuality: 'low' })
    .then(function (bitmap) { return scannerCanvasDataUrl(bitmap, bitmap.width, bitmap.height); });
}
function scannerShrinkPhotoOnServer(file) {
  var body = new FormData();
  body.append('photo', file, file.name || 'photo.jpg');
  return fetch('./scanner-photo-shrink.php', { method: 'POST', body: body, credentials: 'same-origin' })
    .then(function (response) { return response.json(); })
    .then(function (payload) { return payload.dataUrl; });
}
