<?php
declare(strict_types=1);
require __DIR__ . '/accounting-lib.php';

$auth = accounting_current_user();
$client = accounting_oauth_client();
$rules = accounting_rules();
$redirect = $rules['oauthRedirect'] ?? 'https://baohui.paohui.org/accounting-gmail-oauth.php';

function oauth_html(string $title, string $body, bool $ok): void {
    $payload = json_encode(['type' => 'baohui-gmail-oauth', 'ok' => $ok], JSON_UNESCAPED_UNICODE);
    echo '<!doctype html><html lang="zh-TW"><head><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title>';
    echo '<style>body{font-family:"Noto Sans TC","Microsoft JhengHei",sans-serif;padding:40px;background:#f5f7fa;color:#172033}main{max-width:520px;margin:0 auto;background:#fff;border:1px solid #d9e2ec;border-radius:10px;padding:28px}h1{font-size:1.2rem;margin:0 0 10px}p{line-height:1.6;color:#334155}.bad{color:#991b1b}.ok{color:#166534}</style></head><body><main>';
    echo '<h1 class="' . ($ok ? 'ok' : 'bad') . '">' . htmlspecialchars($title) . '</h1>';
    echo '<p>' . $body . '</p><p><a href="admin.php">回後台</a>　<a href="accounting-invoices.php">回電子發票</a></p></main>';
    echo '<script>try{if(window.opener){window.opener.postMessage(' . $payload . ', window.location.origin);}}catch(e){}</script></body></html>';
}

if (!$auth['ok']) {
    oauth_html('尚未登入', '請先登入寶輝後台，再連接 Gmail。', false);
    exit;
}

$code = trim((string)($_GET['code'] ?? ''));
$err = trim((string)($_GET['error'] ?? ''));
if ($err !== '') {
    oauth_html('Gmail 授權取消', htmlspecialchars($err), false);
    exit;
}

if ($code === '') {
    if (!$client) {
        oauth_html('缺少 OAuth 設定', '請把 Google 用戶端放到 <code>F:\\Data\\BaohuiAccounting\\gmail-oauth-client.json</code>。', false);
        exit;
    }
    $qs = http_build_query([
        'client_id' => $client['client_id'] ?? '',
        'redirect_uri' => $redirect,
        'response_type' => 'code',
        'scope' => 'https://www.googleapis.com/auth/gmail.readonly',
        'access_type' => 'offline',
        'prompt' => 'consent',
        'login_hint' => $rules['gmailAccount'] ?? 's1214098@gmail.com',
    ]);
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $qs);
    exit;
}

$res = accounting_http('https://oauth2.googleapis.com/token', [
    'post' => [
        'code' => $code,
        'client_id' => $client['client_id'] ?? '',
        'client_secret' => $client['client_secret'] ?? '',
        'redirect_uri' => $redirect,
        'grant_type' => 'authorization_code',
    ],
]);
if (empty($res['ok']) || empty($res['json']['access_token'])) {
    oauth_html('換 token 失敗', htmlspecialchars($res['body'] ?? $res['error'] ?? 'unknown'), false);
    exit;
}
$existing = accounting_load_token();
$token = $res['json'];
if (empty($token['refresh_token']) && !empty($existing['refresh_token'])) {
    $token['refresh_token'] = $existing['refresh_token'];
}
$token['saved_at'] = date('c');
$token['account'] = $rules['gmailAccount'] ?? 's1214098@gmail.com';
accounting_save_token($token);
oauth_html('Gmail 已連接', '可以用 ' . htmlspecialchars($token['account']) . ' 抓捷元電子對帳單了。這個視窗可以關掉。', true);
