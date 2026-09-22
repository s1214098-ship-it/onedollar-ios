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

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'ops-customer-company-lib.php';

$parsed = ops_customer_parse_party_name('宜蘭縣私立達文西幼兒園(雪山村)(209)');
expect($parsed['company'] === '宜蘭縣私立達文西幼兒園', 'parses company out of Name(branch)(code)');
expect($parsed['branch'] === '雪山村', 'parses 雪山村 branch');
expect($parsed['code'] === '209', 'parses branch code 209');

$resolved = ops_customer_resolve_party('宜蘭縣私立達文西幼兒園(雪山村)(209)');
expect($resolved['known'] === true, '達文西 with branch suffix is a known company');
expect($resolved['company'] === '宜蘭縣私立達文西幼兒園', 'canonical company name');
expect($resolved['branch'] === '雪山村', 'canonical branch from suffix');

expect(ops_customer_same_company('達文西幼', '宜蘭縣私立達文西幼兒園(托嬰中心)') === true, 'legacy 達文西幼 matches 托嬰中心 docs');
expect(ops_customer_same_company('宜蘭縣私立達文西幼兒園', '築上設計') === false, 'unrelated customers stay separate');
expect(ops_customer_company_by_text('宜蘭縣私') === null, 'truncated 宜蘭縣私 does not swallow other 宜蘭縣私立 customers');
expect(ops_customer_company_by_text('宜蘭縣私立某某幼稚園') === null, 'other 宜蘭縣私立 names are not 達文西');
expect(ops_customer_company_by_unit_code('D163')['key'] === 'daowenxi', 'GJP unit D163 maps to 達文西');

$buyer = ops_customer_apply_to_delivery_buyer([
    'name' => '宜蘭縣私立達文西幼兒園(雪山村)(209)',
    'branch' => '',
]);
expect($buyer['name'] === '宜蘭縣私立達文西幼兒園', 'delivery buyer name is canonical company');
expect($buyer['branch'] === '雪山村', 'delivery buyer branch is split out of the name');
expect(($buyer['branch_code'] ?? '') === '209', 'branch code 209 is kept');

$snow = ['customer' => '宜蘭縣私立達文西幼兒園(雪山村)(209)', 'branch' => ''];
$core = ['customer' => '宜蘭縣私立達文西幼兒園', 'branch' => ''];
$infant = ['customer' => '宜蘭縣私立達文西幼兒園', 'branch' => '托嬰中心'];
expect(ops_customer_document_matches($snow, '宜蘭縣私立達文西幼兒園', '', 'company') === true, 'company-scope 請款 includes 雪山村');
expect(ops_customer_document_matches($core, '達文西幼', '', 'company') === true, 'company-scope 請款 includes the parent name');
expect(ops_customer_document_matches($infant, '宜蘭縣私立達文西幼兒園', '雪山村', 'branch') === false, 'branch-scope 請款 excludes other departments');
expect(ops_customer_document_matches($snow, '宜蘭縣私立達文西幼兒園', '雪山村', 'branch') === true, 'branch-scope 請款 keeps 雪山村');

$merged = ops_customer_directory_item([
    'id' => 'gjp_mem_D163',
    'name' => '宜蘭縣私',
    'organization_name' => '宜蘭縣私立達文西幼兒園(幼兒園)',
    'source_unit_code' => 'D163',
    'status' => '',
    'branches' => [],
]);
expect(is_array($merged), 'directory still exposes the truncated GJP row as the parent company');
expect($merged['name'] === '宜蘭縣私立達文西幼兒園', 'directory name is canonical');
expect(in_array('達文西幼', $merged['aliases'], true), 'directory aliases include 達文西幼');
$branchNames = array_map(static fn($row) => $row['name'], $merged['branches']);
expect($branchNames === ['雪山村', '幼兒園', '托嬰中心'], 'directory fills the three 達文西 departments');

$skipped = ops_customer_directory_item([
    'id' => 'gjp_mem_D164',
    'name' => '宜蘭縣私',
    'status' => 'merged',
    'merged_into' => 'gjp_mem_D161',
]);
expect($skipped === null, 'merged department rows stay out of the picker');

$followed = ops_customer_follow_merged_member([
    ['id' => 'gjp_mem_D164', 'status' => 'merged', 'merged_into' => 'gjp_mem_D161'],
    ['id' => 'gjp_mem_D161', 'name' => '宜蘭縣私立達文西幼兒園'],
], ['id' => 'gjp_mem_D164', 'merged_into' => 'gjp_mem_D161']);
expect(($followed['id'] ?? '') === 'gjp_mem_D161', 'member_id on an old department follows the parent');

$js = file_get_contents(dirname(__DIR__) . '/one-dollar-auction/ops-customer-company.js');
expect($js !== false && str_contains((string)$js, 'opsFillBranchSelect'), 'company overlay fills 分店 selects');
expect(str_contains((string)$js, '全公司'), 'billing branch empty option means the whole company');

$dir = file_get_contents(dirname(__DIR__) . '/one-dollar-auction/ops-member-directory.php');
expect(str_contains((string)$dir, 'ops_customer_directory_item'), 'member directory uses the company linker');

if ($failed > 0) {
    fwrite(STDERR, $failed . " assertion(s) failed\n");
    exit(1);
}
echo "all passed\n";
