<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ops-embed-auth-lib.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ops-json-response.php';

function ops_api_boot(): void
{
    baohui_ops_boot_hq_session();
}

function ops_api_logged_in(): bool
{
    if (!empty($_SESSION['baohui_logged_in']) && trim((string)($_SESSION['baohui_user'] ?? '')) !== '') return true;
    if (!empty($_SESSION['user'])) return true;
    if (!empty($_SESSION['logged_in']) || !empty($_SESSION['admin_logged_in'])) return true;
    return false;
}

function ops_api_require_login(): void
{
    ops_api_boot();
    if (ops_api_logged_in()) return;
    baohui_json_send(['ok' => false, 'error' => '請先登入寶輝後台'], 401);
}
