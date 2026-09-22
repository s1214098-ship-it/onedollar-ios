<?php
declare(strict_types=1);

/**
 * Parent-company + branch matching for HQ ops.
 * 管家婆 imported 達文西 as four truncated members; documents also stuffed
 * the department into buyer.name as Name(分店)(代碼). This lib canonicalizes
 * those into one company with named branches so 出貨／請款／對帳 can group.
 */

function ops_customer_parse_party_name($raw): array
{
    $raw = trim((string)$raw);
    $company = $raw;
    $branch = '';
    $code = '';
    if ($raw !== '' && preg_match('/^(.+?)\s*[（(]([^）)]+)[）)](?:\s*[（(]([^）)]+)[）)])?\s*$/u', $raw, $match)) {
        $company = trim((string)$match[1]);
        $first = trim((string)$match[2]);
        $second = trim((string)($match[3] ?? ''));
        if ($second !== '') {
            $branch = $first;
            $code = $second;
        } elseif (preg_match('/^\d{2,}$/', $first)) {
            $code = $first;
        } else {
            $branch = $first;
        }
    }
    return [
        'raw' => $raw,
        'company' => $company,
        'branch' => $branch,
        'code' => $code,
    ];
}

function ops_customer_known_companies(): array
{
    return [
        [
            'key' => 'daowenxi',
            'name' => '宜蘭縣私立達文西幼兒園',
            'canonical_member_id' => 'gjp_mem_D161',
            'branches' => [
                ['name' => '雪山村', 'code' => '209', 'source_unit_code' => 'D161'],
                ['name' => '幼兒園', 'code' => '', 'source_unit_code' => 'D163'],
                ['name' => '托嬰中心', 'code' => '', 'source_unit_code' => 'D164'],
            ],
            'aliases' => [
                '宜蘭縣私立達文西幼兒園',
                '達文西幼兒園',
                '達文西幼',
                '達文西',
            ],
            'source_unit_codes' => ['D161', 'D163', 'D164', 'S05'],
        ],
    ];
}

function ops_customer_norm($value): string
{
    $value = preg_replace('/\s+/u', '', (string)$value) ?? (string)$value;
    return mb_strtolower(trim($value), 'UTF-8');
}

function ops_customer_alias_hits(string $hay, string $aliasNorm): bool
{
    if ($hay === '' || $aliasNorm === '') {
        return false;
    }
    if ($hay === $aliasNorm) {
        return true;
    }
    // Require the alias itself to be distinctive so truncated GJP names
    // like「宜蘭縣私」do not swallow every 宜蘭縣私立* customer.
    if (mb_strlen($aliasNorm, 'UTF-8') < 3) {
        return false;
    }
    return str_starts_with($hay, $aliasNorm) || mb_strpos($hay, $aliasNorm) !== false;
}

function ops_customer_company_by_text($text): ?array
{
    $parsed = ops_customer_parse_party_name($text);
    $hay = ops_customer_norm($parsed['company'] !== '' ? $parsed['company'] : $parsed['raw']);
    $rawHay = ops_customer_norm($parsed['raw']);
    if ($hay === '' && $rawHay === '') {
        return null;
    }
    foreach (ops_customer_known_companies() as $company) {
        foreach (array_merge([(string)$company['name']], $company['aliases']) as $alias) {
            $aliasNorm = ops_customer_norm($alias);
            if (ops_customer_alias_hits($hay, $aliasNorm) || ops_customer_alias_hits($rawHay, $aliasNorm)) {
                return $company;
            }
        }
        if (($company['key'] ?? '') === 'daowenxi'
            && (mb_strpos($hay, '達文西') !== false || mb_strpos($rawHay, '達文西') !== false)) {
            return $company;
        }
    }
    return null;
}

function ops_customer_company_by_unit_code($code): ?array
{
    $code = trim((string)$code);
    if ($code === '') {
        return null;
    }
    foreach (ops_customer_known_companies() as $company) {
        if (in_array($code, $company['source_unit_codes'] ?? [], true)) {
            return $company;
        }
        foreach ((array)($company['branches'] ?? []) as $branch) {
            if (trim((string)($branch['source_unit_code'] ?? '')) === $code) {
                return $company;
            }
        }
    }
    return null;
}

function ops_customer_match_branch_name($company, $branchText): string
{
    $wanted = trim((string)$branchText);
    if ($wanted === '' || !is_array($company)) {
        return $wanted;
    }
    if ($wanted === '（未分店）' || $wanted === '(未分店)' || $wanted === '全公司') {
        return '';
    }
    $wantedNorm = ops_customer_norm($wanted);
    foreach ((array)($company['branches'] ?? []) as $branch) {
        $name = trim((string)($branch['name'] ?? ''));
        $code = trim((string)($branch['code'] ?? ''));
        $unit = trim((string)($branch['source_unit_code'] ?? ''));
        if ($name !== '' && (ops_customer_norm($name) === $wantedNorm || $wantedNorm === ops_customer_norm($code))) {
            return $name;
        }
        if ($code !== '' && ($wanted === $code || $wantedNorm === ops_customer_norm($code))) {
            return $name;
        }
        if ($unit !== '' && strcasecmp($wanted, $unit) === 0) {
            return $name;
        }
    }
    return $wanted;
}

function ops_customer_resolve_party($rawName, $explicitBranch = ''): array
{
    $parsed = ops_customer_parse_party_name($rawName);
    $company = ops_customer_company_by_text($rawName) ?: ops_customer_company_by_text($parsed['company']);
    $branch = trim((string)$explicitBranch);
    if ($branch === '（未分店）' || $branch === '(未分店)' || $branch === '全公司') {
        $branch = '';
    }
    if ($branch === '') {
        $branch = $parsed['branch'];
    }
    if ($company) {
        $branch = ops_customer_match_branch_name($company, $branch);
        if ($branch === '' && $parsed['code'] !== '') {
            $branch = ops_customer_match_branch_name($company, $parsed['code']);
        }
        return [
            'company' => (string)$company['name'],
            'branch' => $branch,
            'code' => $parsed['code'],
            'raw' => $parsed['raw'],
            'key' => (string)($company['key'] ?? ''),
            'known' => true,
            'canonical_member_id' => (string)($company['canonical_member_id'] ?? ''),
        ];
    }
    return [
        'company' => $parsed['company'] !== '' ? $parsed['company'] : $parsed['raw'],
        'branch' => $branch,
        'code' => $parsed['code'],
        'raw' => $parsed['raw'],
        'key' => '',
        'known' => false,
        'canonical_member_id' => '',
    ];
}

function ops_customer_same_company($left, $right): bool
{
    $a = ops_customer_resolve_party($left);
    $b = ops_customer_resolve_party($right);
    if ($a['known'] && $b['known']) {
        return $a['key'] !== '' && $a['key'] === $b['key'];
    }
    $leftCompany = ops_customer_norm($a['company']);
    $rightCompany = ops_customer_norm($b['company']);
    if ($leftCompany === '' || $rightCompany === '') {
        return false;
    }
    if ($leftCompany === $rightCompany) {
        return true;
    }
    return str_starts_with($leftCompany, $rightCompany) || str_starts_with($rightCompany, $leftCompany);
}

function ops_customer_document_matches($row, $customerQuery, $branchQuery = '', $scope = 'company'): bool
{
    $rowName = is_array($row)
        ? (string)($row['customer'] ?? $row['customer_name'] ?? $row['name'] ?? '')
        : (string)$row;
    $rowBranch = is_array($row)
        ? trim((string)($row['branch'] ?? $row['customer_branch'] ?? ''))
        : '';
    $resolvedRow = ops_customer_resolve_party($rowName, $rowBranch);
    $resolvedQuery = ops_customer_resolve_party($customerQuery, $branchQuery);
    if (!ops_customer_same_company($resolvedRow['company'] ?: $rowName, $resolvedQuery['company'] ?: $customerQuery)
        && !ops_customer_same_company($rowName, $customerQuery)) {
        return false;
    }
    $scope = trim((string)$scope);
    if ($scope === '' || $scope === 'company' || $scope === 'all') {
        return true;
    }
    $wantedBranch = ops_customer_match_branch_name(
        ops_customer_company_by_text($resolvedQuery['company']) ?: [],
        $branchQuery !== '' ? $branchQuery : $resolvedQuery['branch']
    );
    if ($wantedBranch === '' || $wantedBranch === '（未分店）') {
        return true;
    }
    $actual = $resolvedRow['branch'] !== '' ? $resolvedRow['branch'] : ops_customer_match_branch_name(
        ops_customer_company_by_text($resolvedRow['company']) ?: [],
        $resolvedRow['branch']
    );
    return ops_customer_norm($actual) === ops_customer_norm($wantedBranch);
}

function ops_customer_apply_to_delivery_buyer(array $buyer): array
{
    $resolved = ops_customer_resolve_party(
        (string)($buyer['name'] ?? ''),
        (string)($buyer['branch'] ?? $buyer['customer_branch'] ?? '')
    );
    if ($resolved['company'] !== '') {
        $original = trim((string)($buyer['original_name'] ?? $buyer['name'] ?? ''));
        if ($original !== '' && $original !== $resolved['company']) {
            $buyer['original_name'] = $original;
        }
        $buyer['name'] = $resolved['company'];
        $buyer['company_name'] = $resolved['company'];
    }
    if ($resolved['branch'] !== '') {
        $buyer['branch'] = $resolved['branch'];
    }
    if ($resolved['code'] !== '' && trim((string)($buyer['branch_code'] ?? '')) === '') {
        $buyer['branch_code'] = $resolved['code'];
    }
    if ($resolved['canonical_member_id'] !== '' && trim((string)($buyer['member_id'] ?? '')) === '') {
        $buyer['member_id'] = $resolved['canonical_member_id'];
    }
    return $buyer;
}

function ops_customer_member_is_merged(array $member): bool
{
    return trim((string)($member['status'] ?? '')) === 'merged'
        || trim((string)($member['merged_into'] ?? '')) !== '';
}

function ops_customer_follow_merged_member(array $members, array $member): array
{
    $target = trim((string)($member['merged_into'] ?? ''));
    if ($target === '') {
        return $member;
    }
    foreach ($members as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (trim((string)($row['id'] ?? '')) === $target && !ops_customer_member_is_merged($row)) {
            return $row;
        }
    }
    return $member;
}

function ops_customer_company_by_member(array $member): ?array
{
    $code = trim((string)($member['source_unit_code'] ?? $member['unit_code'] ?? $member['gjp_code'] ?? ''));
    $byCode = ops_customer_company_by_unit_code($code);
    if ($byCode) {
        return $byCode;
    }
    foreach (['organization_name', 'name', 'customer_name', 'title'] as $key) {
        $found = ops_customer_company_by_text((string)($member[$key] ?? ''));
        if ($found) {
            return $found;
        }
    }
    foreach ((array)($member['aliases'] ?? []) as $alias) {
        $value = is_array($alias) ? (string)($alias['name'] ?? $alias['value'] ?? '') : (string)$alias;
        $found = ops_customer_company_by_text($value);
        if ($found) {
            return $found;
        }
    }
    return null;
}

function ops_customer_parse_branches_text($text): array
{
    $parts = preg_split('/[,，、;；\n]+/u', (string)$text) ?: [];
    $out = [];
    foreach ($parts as $name) {
        $name = trim((string)$name);
        if ($name === '' || $name === '（未分店）') {
            continue;
        }
        $out[] = [
            'id' => '',
            'name' => $name,
            'phone' => '',
            'address' => '',
            'note' => '',
        ];
    }
    return $out;
}

function ops_customer_directory_item(array $member): ?array
{
    if (ops_customer_member_is_merged($member)) {
        return null;
    }
    $name = trim((string)($member['name'] ?? $member['customer_name'] ?? $member['customer'] ?? ''));
    $company = ops_customer_company_by_member($member);
    $aliases = [];
    foreach (['name', 'customer_name', 'customer', 'organization_name', 'title', 'facebook'] as $key) {
        $value = trim((string)($member[$key] ?? ''));
        if ($value !== '') {
            $aliases[$value] = $value;
        }
    }
    foreach ((array)($member['aliases'] ?? []) as $alias) {
        $value = is_array($alias) ? trim((string)($alias['name'] ?? $alias['value'] ?? '')) : trim((string)$alias);
        if ($value !== '') {
            $aliases[$value] = $value;
        }
    }
    $branches = [];
    foreach ((array)($member['branches'] ?? []) as $branch) {
        if (!is_array($branch)) {
            continue;
        }
        $branchName = trim((string)($branch['name'] ?? ''));
        if ($branchName === '') {
            continue;
        }
        $branches[$branchName] = [
            'id' => trim((string)($branch['id'] ?? '')),
            'name' => $branchName,
            'code' => trim((string)($branch['code'] ?? '')),
            'phone' => trim((string)($branch['phone'] ?? '')),
            'address' => trim((string)($branch['address'] ?? '')),
            'note' => trim((string)($branch['note'] ?? '')),
        ];
    }
    if ($company) {
        $name = (string)$company['name'];
        foreach (array_merge([(string)$company['name']], $company['aliases']) as $alias) {
            $alias = trim((string)$alias);
            if ($alias !== '') {
                $aliases[$alias] = $alias;
            }
        }
        foreach ((array)($company['branches'] ?? []) as $branch) {
            $branchName = trim((string)($branch['name'] ?? ''));
            if ($branchName === '') {
                continue;
            }
            if (!isset($branches[$branchName])) {
                $branches[$branchName] = [
                    'id' => '',
                    'name' => $branchName,
                    'code' => (string)($branch['code'] ?? ''),
                    'phone' => '',
                    'address' => '',
                    'note' => '',
                ];
            } elseif ($branches[$branchName]['code'] === '' && trim((string)($branch['code'] ?? '')) !== '') {
                $branches[$branchName]['code'] = (string)$branch['code'];
            }
            $aliases[$company['name'] . '(' . $branchName . ')'] = $company['name'] . '(' . $branchName . ')';
            $code = trim((string)($branch['code'] ?? ''));
            if ($code !== '') {
                $aliases[$company['name'] . '(' . $branchName . ')(' . $code . ')'] = $company['name'] . '(' . $branchName . ')(' . $code . ')';
            }
        }
    }
    if ($name === '' && !$aliases) {
        return null;
    }
    $phone = '';
    foreach (['phone', 'tel', 'mobile', 'contact_phone'] as $key) {
        $value = trim((string)($member[$key] ?? ''));
        if ($value !== '') {
            $phone = $value;
            break;
        }
    }
    $address = '';
    foreach (['address', 'addr', 'company_address', 'ship_address'] as $key) {
        $value = trim((string)($member[$key] ?? ''));
        if ($value !== '') {
            $address = $value;
            break;
        }
    }
    return [
        'id' => (string)($member['id'] ?? ''),
        'name' => $name !== '' ? $name : (array_values($aliases)[0] ?? ''),
        'aliases' => array_values($aliases),
        'facebook' => trim((string)($member['facebook'] ?? '')),
        'phone' => $phone,
        'address' => $address,
        'branches' => array_values($branches),
        'company_key' => (string)($company['key'] ?? ''),
        'company_name' => (string)($company['name'] ?? ''),
    ];
}

function ops_customer_known_companies_for_js(): array
{
    $out = [];
    foreach (ops_customer_known_companies() as $company) {
        $out[] = [
            'key' => (string)($company['key'] ?? ''),
            'name' => (string)($company['name'] ?? ''),
            'aliases' => array_values($company['aliases'] ?? []),
            'branches' => array_map(static function ($branch) {
                return [
                    'name' => (string)($branch['name'] ?? ''),
                    'code' => (string)($branch['code'] ?? ''),
                ];
            }, $company['branches'] ?? []),
        ];
    }
    return $out;
}
