<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ops-api-auth.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ops-product-index-lib.php';

header('Cache-Control: private, max-age=15, must-revalidate');
ops_api_require_login();

$items = ops_load_product_index();
baohui_json_send([
    'ok' => true,
    'count' => count($items),
    'items' => $items,
]);
