'use strict';

/**
 * Windows orders.json save: rename() Access denied (code 5).
 * Patch order-admin-api-v6.php write_json to retry + copy overwrite.
 */

const fs = require('fs');
const path = require('path');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const api = path.join(root, 'order-admin-api-v6.php');
const helperName = 'json-atomic-write.php';
const helperDest = path.join(root, helperName);

const OLD = `function write_json(string $file, array $payload): void {
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) respond(['ok' => false, 'error' => 'JSON 編碼失敗'], 500);
    if (file_put_contents($tmp, $json, LOCK_EX) === false) respond(['ok' => false, 'error' => 'NAS 暫存寫入失敗'], 500);
    if (!rename($tmp, $file)) {
        @unlink($tmp);
        respond(['ok' => false, 'error' => 'NAS 檔案更新失敗'], 500);
    }
}`;

const NEW = `function write_json(string $file, array $payload): void {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'json-atomic-write.php';
    $err = lz_atomic_write_json($file, $payload);
    if ($err !== '') respond(['ok' => false, 'error' => $err], 500);
}`;

if (!fs.existsSync(api)) {
  console.log('Not on PHT-SR');
  process.exit(0);
}

const helperSrcCandidates = [
  path.join(__dirname, '..', 'live-iis', helperName),
  path.join(__dirname, helperName),
  path.join('C:/Temp', helperName),
];
const helperSrc = helperSrcCandidates.find(function (p) { return fs.existsSync(p); });
if (!helperSrc) throw new Error('missing helper json-atomic-write.php');
fs.copyFileSync(helperSrc, helperDest);

const audit = path.join(root, 'data', 'audit');
if (!fs.existsSync(audit)) fs.mkdirSync(audit, { recursive: true });
fs.copyFileSync(api, path.join(audit, 'order-admin-api-v6.php.save-lock-' + new Date().toISOString().replace(/[:.]/g, '-')));

let s = fs.readFileSync(api, 'utf8');
if (s.indexOf('lz_atomic_write_json') !== -1) {
  console.log('already patched', api);
  process.exit(0);
}
if (s.indexOf(OLD) === -1) throw new Error('write_json snippet missing');
s = s.replace(OLD, NEW);
fs.writeFileSync(api, s);
console.log('patched', api);
console.log('helper', helperDest, fs.existsSync(helperDest));
