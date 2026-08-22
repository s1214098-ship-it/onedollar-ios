<?php
declare(strict_types=1);

function lz_asset_manifest(string $root): array {
    $dir = $root . DIRECTORY_SEPARATOR . 'assets';
    $files = [];
    $max = 0;
    if (is_dir($dir)) {
        foreach (['js', 'css'] as $ext) {
            $list = glob($dir . DIRECTORY_SEPARATOR . '*.' . $ext);
            if (!is_array($list)) continue;
            foreach ($list as $path) {
                if (!is_file($path)) continue;
                $mtime = (int)filemtime($path);
                $files[basename($path)] = (string)$mtime;
                if ($mtime > $max) $max = $mtime;
            }
        }
    }
    ksort($files);
    return [
        'ok' => true,
        'v' => (string)$max,
        'files' => $files,
        'auto' => true,
    ];
}

function lz_asset_cookie_name(): string {
    return 'lz_asset_v';
}

function lz_asset_set_cookie(string $version): void {
    $version = preg_replace('/[^0-9]/', '', $version) ?? '';
    if ($version === '') return;
    header('Set-Cookie: ' . lz_asset_cookie_name() . '=' . $version . '; Path=/; SameSite=Lax', false);
}

function lz_asset_client_version(): string {
    $name = lz_asset_cookie_name();
    return isset($_COOKIE[$name]) ? preg_replace('/[^0-9]/', '', (string)$_COOKIE[$name]) ?? '' : '';
}

function lz_asset_client_is_current(string $version): bool {
    $client = lz_asset_client_version();
    return $client !== '' && $client === preg_replace('/[^0-9]/', '', $version);
}

function lz_asset_stale_reload_headers(string $version): void {
    if (lz_asset_client_is_current($version)) return;
    if (!empty($_COOKIE['lz_asset_tried'])) return;
    header('Set-Cookie: lz_asset_tried=1; Path=/; Max-Age=120; SameSite=Lax', false);
    header('Refresh: 0');
}
