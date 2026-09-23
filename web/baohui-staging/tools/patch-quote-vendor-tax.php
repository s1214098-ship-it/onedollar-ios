<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'quote-vendor-tax-lib.php';

$day = (string)($argv[1] ?? '');
$in = (string)($argv[2] ?? '');
$out = (string)($argv[3] ?? '');
if ($day === '' || $in === '' || $out === '') {
    fwrite(STDERR, "usage: php patch-quote-vendor-tax.php YYYY-MM-DD in.json out.json\n");
    exit(2);
}

$result = quote_patch_vendor_tax_json_file($in, $out, $day);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
