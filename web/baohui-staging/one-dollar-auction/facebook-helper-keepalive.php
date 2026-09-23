<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'schedule-lifecycle-lib.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ops-embed-auth-lib.php';

baohui_ops_boot_hq_session();
if (empty($_SESSION['baohui_logged_in']) && empty($_SESSION['user'])) {
    http_response_code(401);
    echo '<!doctype html><meta charset="utf-8"><div style="font-family:Arial,sans-serif;padding:32px"><h2>請先登入寶輝後台</h2><p><a href="/admin.php">回寶輝後台登入</a></p></div>';
    exit;
}

$status = schedule_lifecycle_helper_status();
$connected = !empty($status['connected']);
$version = htmlspecialchars((string)($status['helper_version'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$age = $status['age_seconds'];
$ageLabel = $age === null ? '尚未連線過' : ((int)$age < 60 ? ((int)$age . ' 秒前') : (int)floor((int)$age / 60) . ' 分鐘前');
?>
<!doctype html>
<html lang="zh-Hant">
<head>
  <meta charset="utf-8">
  <meta http-equiv="refresh" content="30">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>峰志 Chrome 發文助手連線</title>
  <style>
    body { font-family: Arial, "Noto Sans TC", sans-serif; margin: 0; background: #f8fafc; color: #0f172a; }
    main { max-width: 720px; margin: 40px auto; padding: 28px; background: #fff; border-radius: 16px; box-shadow: 0 10px 30px rgba(15,23,42,.08); }
    h1 { margin: 0 0 12px; font-size: 22px; }
    .ok { color: #047857; font-weight: 800; }
    .wait { color: #c2410c; font-weight: 800; }
    p { line-height: 1.7; }
    a { color: #0f766e; }
  </style>
</head>
<body>
  <main>
    <h1>峰志 Chrome 發文助手</h1>
    <p class="<?= $connected ? 'ok' : 'wait' ?>">
      <?= $connected ? '已連線' . ($version !== '' ? '　版本 ' . $version : '') : '未連線' ?>
      ／上次心跳：<?= htmlspecialchars($ageLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </p>
    <p>真正發 Facebook 社團一定走這台電腦已登入的 Chrome。雲端 Cursor 登不了你的 Facebook，也不能改用官方 API 幫社團預約（Meta 已關閉社團發文 API）。</p>
    <p>請把這個分頁留在「峰志 Chrome」：Facebook 保持登入、助手保持啟用。雲端或小姐在後台入隊之後，助手會自動領取，不必再按「啟動上架」。</p>
    <p><a href="operations.php?tab=schedule">回競標排程</a></p>
  </main>
</body>
</html>
