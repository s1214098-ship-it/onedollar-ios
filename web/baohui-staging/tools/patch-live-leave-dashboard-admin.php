<?php
declare(strict_types=1);

$target = $argv[1] ?? '';
$version = $argv[2] ?? 'leave-dashboard-20260926';
if ($target === '' || !is_file($target)) {
    fwrite(STDERR, "usage: php patch-live-leave-dashboard-admin.php <admin.php> [version]\n");
    exit(1);
}

$src = (string)file_get_contents($target);
if ($src === '') {
    fwrite(STDERR, "empty file: {$target}\n");
    exit(1);
}

$tag = '<script src="admin-leave-dashboard.js?v=' . $version . '" charset="UTF-8"></script>';
$updated = preg_replace(
    '/<script src="admin-leave-dashboard\.js\?v=[^"]+"[^>]*><\/script>/',
    $tag,
    $src,
    1,
    $replaced
);
if ($replaced === 1) {
    $out = $updated;
} else {
    $anchors = [
        '<script src="admin-hide-codex-report.js',
        '<script src="admin-customs-duty-verify.js',
        '<script src="admin-main.js',
    ];
    $pos = false;
    foreach ($anchors as $anchor) {
        $pos = strpos($src, $anchor);
        if ($pos !== false) break;
    }
    if ($pos === false) {
        fwrite(STDERR, "could not find overlay insertion point in {$target}\n");
        exit(1);
    }
    $end = strpos($src, '</script>', $pos);
    if ($end === false) {
        fwrite(STDERR, "could not find script end in {$target}\n");
        exit(1);
    }
    $end += strlen('</script>');
    $out = substr($src, 0, $end) . "\n" . $tag . substr($src, $end);
}

if (strpos($out, 'admin-leave-dashboard.js?v=' . $version) === false) {
    fwrite(STDERR, "patched file missing leave-dashboard script tag\n");
    exit(1);
}

if (file_put_contents($target, $out) === false) {
    fwrite(STDERR, "write failed: {$target}\n");
    exit(1);
}

echo "patched {$target} v={$version}\n";
