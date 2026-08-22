<?php
declare(strict_types=1);

/**
 * Current JS/CSS file times for 領讚讚 assets. No-store JSON.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . DIRECTORY_SEPARATOR . 'asset-version-lib.php';

$manifest = lz_asset_manifest(__DIR__);
// Keep poll quiet so already-open tabs do not flash-reload when JS/CSS mtime changes.
unset($manifest['v']);
$manifest['auto'] = false;
echo json_encode($manifest, JSON_UNESCAPED_SLASHES);
