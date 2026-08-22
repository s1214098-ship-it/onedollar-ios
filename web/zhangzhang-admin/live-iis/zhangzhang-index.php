<?php
declare(strict_types=1);
session_name('BAOHUI_ADMIN');
ini_set('session.gc_maxlifetime', '86400');
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
    header('Location: /one-dollar-auction/operations.php');
    exit;
}

header('Location: /one-dollar-auction/');
exit;
