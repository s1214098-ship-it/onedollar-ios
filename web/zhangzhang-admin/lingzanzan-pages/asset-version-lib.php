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
    ];
}
