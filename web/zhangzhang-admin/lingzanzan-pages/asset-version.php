<?php
declare(strict_types=1);

/**
 * Current JS/CSS file times for 領讚讚 assets. No-store JSON.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . DIRECTORY_SEPARATOR . 'asset-version-lib.php';

echo json_encode(lz_asset_manifest(__DIR__), JSON_UNESCAPED_SLASHES);
