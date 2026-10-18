<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'accounting-lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'accounting-gmail-source.php';

acc_start_session();
$context = acc_user_context();
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function acc_org_page(string $title, string $html, bool $ok = true): never
{
    $color = $ok ? '#166534' : '#991b1b';
    echo '<!doctype html><html lang="zh-Hant"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
        . '</title><style>body{font-family:"Noto Sans TC","Microsoft JhengHei",sans-serif;padding:32px;background:#f5f7fa;color:#172033}main{max-width:640px;margin:0 auto;background:#fff;border:1px solid #d9e2ec;border-radius:12px;padding:28px}h1{font-size:1.25rem;margin:0 0 12px;color:'
        . $color
        . '}p,li{line-height:1.65;color:#334155}.btn{display:inline-block;margin:8px 8px 0 0;padding:10px 16px;border-radius:8px;background:#0f766e;color:#fff;text-decoration:none;font-weight:700}.btn.secondary{background:#2563eb}</style></head><body><main><h1>'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
        . '</h1>'
        . $html
        . '</main></body></html>';
    exit;
}

if (!$context['loggedIn']) acc_org_page('尚未登入', '<p>請先登入寶輝後台，再整理 Cursor 信件。</p><p><a class="btn" href="admin.php">去登入</a></p>', false);
if (!in_array('invoice_edit', $context['capabilities'], true) && empty($_SESSION['baohui_is_admin'])) {
    acc_org_page('沒有權限', '<p>這個帳號不能整理 Gmail 資料夾。</p>', false);
}

$wantAuth = isset($_GET['authorize']);
$wantRun = isset($_GET['run']);
if ($wantAuth) {
    $state = 'organize.' . bin2hex(random_bytes(12));
    $_SESSION['gmail_oauth_state'] = $state;
    header('Location: ' . acc_gmail_authorize_url($state, GmailInvoiceSourceConfiguration::ORGANIZE_SCOPES));
    exit;
}

try {
    $can = acc_gmail_can_organize();
} catch (Throwable $e) {
    $can = false;
}

if ($wantRun || ($can && isset($_GET['go']))) {
    try {
        $result = acc_gmail_organize_cursor();
        $n = (int)($result['moved'] ?? 0);
        if (!empty($result['filter_created'])) {
            $filter = '並已設好以後自動歸檔。';
        } elseif (trim((string)($result['filter_error'] ?? '')) !== '') {
            $filter = '舊信已歸進去；自動篩選沒設成，之後新信可再按一次整理，或匯入 gmail-filter-cursor.xml。';
        } else {
            $filter = '篩選規則已存在，舊信也已歸進去。';
        }
        acc_org_page('Cursor 信件已收進同一個資料夾', '<p>信箱：'
            . htmlspecialchars((string)($result['email'] ?? ''), ENT_QUOTES, 'UTF-8')
            . '</p><p>資料夾名稱：<b>Cursor</b></p><p>已處理 <b>'
            . htmlspecialchars((string)$n, ENT_QUOTES, 'UTF-8')
            . '</b> 封。' . htmlspecialchars($filter, ENT_QUOTES, 'UTF-8')
            . '</p><p>之後 cursor[bot]、GitHub Cursor 通知、Cursor 官方信都會進這個資料夾，不再堆在收件匣。</p><p><a class="btn" href="https://mail.google.com/mail/u/0/#label/Cursor" target="_blank" rel="noopener">打開 Cursor 資料夾</a> <a class="btn secondary" href="admin.php">回後台</a></p>', true);
    } catch (Throwable $e) {
        acc_org_page('整理失敗', '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p><p><a class="btn" href="accounting-gmail-organize.php?authorize=1">重新授權</a></p>', false);
    }
}

if ($can) {
    acc_org_page('可以把 Cursor 信件收進同一個資料夾', '<p>目前 Gmail 已有整理權限。按下面就會建立 <b>Cursor</b> 資料夾，把 cursor[bot]／GitHub Cursor 通知從收件匣移進去，並設好以後自動分類。</p><p><a class="btn" href="accounting-gmail-organize.php?go=1">立刻整理</a> <a class="btn secondary" href="admin.php">回後台</a></p>', true);
}

acc_org_page('授權後就能把 Cursor 信件收進同一個資料夾', '<p>現在的 Gmail 連線只能讀發票，不能改資料夾。請按一次 Google 授權（同一個 <code>s1214098@gmail.com</code>），允許「修改郵件」與「篩選設定」。</p><p>授權回來後會自動：</p><ul><li>建立資料夾 <b>Cursor</b></li><li>把現有 cursor[bot]／Cursor 信件移進去（約 200 封）</li><li>以後新信自動進這個資料夾，不再留在收件匣</li></ul><p>7-11、郵局、PHT-SR 警報不會被搬走。</p><p><a class="btn" href="accounting-gmail-organize.php?authorize=1">授權並建立 Cursor 資料夾</a></p>', true);
