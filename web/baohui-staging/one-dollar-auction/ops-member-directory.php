<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ops-api-auth.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ops-data-lib.php';

header('Cache-Control: private, max-age=30, must-revalidate');
ops_api_require_login();

$members = read_data('members');
$out = [];
foreach (is_array($members) ? $members : [] as $member) {
    if (!is_array($member)) continue;
    $name = trim((string)($member['name'] ?? $member['customer_name'] ?? $member['customer'] ?? ''));
    $aliases = [];
    foreach (['name', 'customer_name', 'customer', 'organization_name', 'title'] as $key) {
        $value = trim((string)($member[$key] ?? ''));
        if ($value !== '') $aliases[$value] = $value;
    }
    $phone = '';
    foreach (['phone', 'tel', 'mobile', 'contact_phone'] as $key) {
        $value = trim((string)($member[$key] ?? ''));
        if ($value !== '') { $phone = $value; break; }
    }
    $address = '';
    foreach (['address', 'addr', 'company_address', 'ship_address'] as $key) {
        $value = trim((string)($member[$key] ?? ''));
        if ($value !== '') { $address = $value; break; }
    }
    if ($name === '' && !$aliases) continue;
    $out[] = [
        'id' => (string)($member['id'] ?? ''),
        'name' => $name !== '' ? $name : (array_values($aliases)[0] ?? ''),
        'aliases' => array_values($aliases),
        'facebook' => trim((string)($member['facebook'] ?? '')),
        'phone' => $phone,
        'address' => $address,
    ];
}

baohui_json_send([
    'ok' => true,
    'count' => count($out),
    'items' => $out,
]);
