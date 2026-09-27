const fs = require('fs');
const js = fs.readFileSync(process.argv[2] || '/tmp/lz-pda-cam/scanner-ygf-v59.js', 'utf8');
const html = fs.readFileSync(process.argv[3] || '/tmp/lz-pda-cam/scanner.html', 'utf8');
const php = fs.readFileSync(process.argv[4] || '/workspace/lingzanzan-inbound/scanner-photo-shrink.php', 'utf8');
const checks = [
  ['js-marker', js.includes('LZ_PDA_CAM_20260927')],
  ['js-capture', js.includes('function scannerCaptureVideoFrame')],
  ['js-shrink', js.includes('scanner-photo-shrink.php')],
  ['js-constrain', js.includes('scannerConstrainPhotoStream')],
  ['js-no-full-video', !js.includes('getUserMedia({ video: true')],
  ['js-no-full-draw', !/scannerCanvasDataUrl\(video, video\.videoWidth/.test(js)],
  ['js-server-big', js.includes('tooBigForDevice')],
  ['html-bust', html.includes('20260927-pda-cam-1')],
  ['html-v156', html.includes('版本 v156')],
  ['php-marker', php.includes('LZ_PDA_CAM_20260927')],
  ['php-gd', php.includes('imagecopyresampled')],
];
const failed = checks.filter(([, ok]) => !ok).map(([name]) => name);
if (failed.length) {
  console.error('FAIL', failed.join(','));
  process.exit(1);
}
console.log('ok', checks.map(([name]) => name).join(','));
