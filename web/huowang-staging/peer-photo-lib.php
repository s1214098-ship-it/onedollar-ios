<?php
declare(strict_types=1);

function peerPhotoMediaSrc($value): string {
    if (is_string($value)) return trim($value);
    if (is_array($value)) {
        foreach (['data', 'src', 'url', 'imageUrl', 'href'] as $key) {
            if (!empty($value[$key]) && is_string($value[$key])) return trim($value[$key]);
        }
    }
    return '';
}

function peerAllImageSrcs(array $item): array {
    $srcs = [];
    foreach (['images', 'photos', 'photoList', 'gallery'] as $key) {
        $value = $item[$key] ?? null;
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }
        if (!is_array($value)) continue;
        foreach ($value as $img) {
            $src = peerPhotoMediaSrc($img);
            if ($src !== '' && !in_array($src, $srcs, true)) $srcs[] = $src;
        }
    }
    foreach (['image', 'imageUrl', 'photo', 'cover', 'thumb', 'thumbnail'] as $key) {
        $src = peerPhotoMediaSrc($item[$key] ?? '');
        if ($src !== '' && !in_array($src, $srcs, true)) $srcs[] = $src;
    }
    return $srcs;
}

function peerPhotoShardName(string $id): string {
    return 'photos-' . substr(sha1($id), 0, 2) . '.json';
}

function peerPhotoCacheDir(): string {
    return __DIR__ . '/../data/cache';
}

function peerPhotoLookup(array $item): array {
    $keys = [];
    foreach (['id', 'externalId', 'publicNo'] as $field) {
        $value = trim((string)($item[$field] ?? ''));
        if ($value !== '') $keys[] = $value;
    }
    $host = trim((string)($item['sourceHost'] ?? ''));
    $external = trim((string)($item['externalId'] ?? ''));
    if ($host !== '' && $external !== '') $keys[] = $host . '|' . $external;
    return array_values(array_unique($keys));
}
