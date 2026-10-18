<?php
declare(strict_types=1);

$failed = 0;
function expect($ok, string $msg): void
{
    global $failed;
    if ($ok) {
        echo "ok  $msg\n";
        return;
    }
    $failed++;
    echo "FAIL  $msg\n";
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'schedule-lifecycle-lib.php';

expect(schedule_lifecycle_requires_facebook_lead(['listing_source' => 'codex']) === true, 'CODEX lots still need the Facebook 3-hour lead');
expect(schedule_lifecycle_requires_facebook_lead(['listing_source' => 'staff']) === false, '員工人工排程 does not use the 3-hour lead');
expect(schedule_lifecycle_requires_facebook_lead(['listing_source' => 'self']) === false, '自行上架 does not use the 3-hour lead');
expect(schedule_lifecycle_requires_facebook_lead(['listing_source' => 'unassigned']) === false, '尚未指定 is treated as 人工');
expect(schedule_lifecycle_requires_facebook_lead(['listing_source' => '人工']) === false, '人工 label does not use the 3-hour lead');
expect(schedule_lifecycle_facebook_lead_seconds() === 10800, 'CODEX Facebook lead remains 3 hours');

if ($failed > 0) {
    fwrite(STDERR, "$failed failed\n");
    exit(1);
}
echo "all facebook lead tests passed\n";
