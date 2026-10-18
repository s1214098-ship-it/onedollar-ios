<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'accounting-lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'accounting-gmail-source.php';

acc_start_session();
$context = acc_user_context();
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function acc_oauth_page(string $title, string $body, bool $ok = true): never
{
    $color = $ok ? '#166534' : '#991b1b';
    echo '<!doctype html><html lang="zh-TW"><head><meta charset="utf-8"><title>'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
        . '</title><style>body{font-family:"Noto Sans TC","Microsoft JhengHei",sans-serif;padding:40px;background:#f5f7fa;color:#172033}main{max-width:520px;margin:0 auto;background:#fff;border:1px solid #d9e2ec;border-radius:10px;padding:28px}h1{font-size:1.2rem;margin:0 0 10px;color:'
        . $color
        . '}p{line-height:1.6;color:#334155}</style></head><body><main><h1>'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
        . '</h1><p>'
        . htmlspecialchars($body, ENT_QUOTES, 'UTF-8')
        . '</p><p><a href="admin.php">回後台</a>　<a href="accounting-invoices.php">回電子發票</a></p></main><script>try{if(window.opener){window.opener.postMessage({type:"baohui-gmail-oauth",ok:'
        . ($ok ? 'true' : 'false')
        . '}, window.location.origin);' . ($ok ? 'setTimeout(function(){window.close()},800)' : '') . '}}catch(e){}</script></body></html>';
    exit;
}

if (!$context['loggedIn']) acc_oauth_page('尚未登入', '請先登入寶輝後台，再連接 Gmail。', false);
if (!in_array('invoice_edit', $context['capabilities'], true)) acc_oauth_page('沒有權限', '這個帳號不能連接 Gmail。', false);

$error = trim((string)($_GET['error'] ?? ''));
if ($error !== '') {
    acc_oauth_page('Gmail 授權取消', $error === 'access_denied' ? '你沒有同意授權。' : ('Google 回傳：' . $error), false);
}

$code = trim((string)($_GET['code'] ?? ''));
$state = trim((string)($_GET['state'] ?? ''));
if ($code === '') acc_oauth_page('缺少授權碼', '請從電子發票頁面再按一次「連接 Gmail」。', false);
$expected = (string)($_SESSION['gmail_oauth_state'] ?? '');
if ($expected === '' || !hash_equals($expected, $state)) acc_oauth_page('安全驗證失敗', '授權狀態不符，請關閉視窗後重試。', false);
unset($_SESSION['gmail_oauth_state']);

try {
    $token = acc_gmail_exchange_code($code);
    $email = trim((string)($token['email'] ?? acc_gmail_refresh_profile()));
    if (str_starts_with($state, 'organize.')) {
        try {
            $result = acc_gmail_organize_cursor();
            $n = (int)($result['moved'] ?? 0);
            acc_oauth_page(
                'Cursor 信件已收進資料夾',
                ($email !== '' ? ('已授權信箱：' . $email . '。') : '')
                . '已建立「Cursor」資料夾，並處理 ' . $n . ' 封信件。之後 cursor[bot] 新信會自動進去。'
            );
        } catch (Throwable $organizeError) {
            acc_oauth_page('Gmail 已授權，但整理信件失敗', $organizeError->getMessage(), false);
        }
    }
    acc_oauth_page('Gmail 已連接', $email !== '' ? ('已授權信箱：' . $email) : '授權完成，可以回電子發票頁面抓信。', true);
} catch (Throwable $e) {
    @file_put_contents(
        acc_private_root() . DIRECTORY_SEPARATOR . 'gmail-oauth-error.log',
        date('c') . ' ' . $e->getMessage() . PHP_EOL,
        FILE_APPEND
    );
    acc_oauth_page('Gmail 授權失敗', $e->getMessage(), false);
}
