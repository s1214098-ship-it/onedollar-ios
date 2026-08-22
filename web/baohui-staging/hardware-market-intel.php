<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Taipei');
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . DIRECTORY_SEPARATOR . 'hardware-market-intel-lib.php';

$force = isset($_GET['refresh']);
$intel = hm_intel_payload($force);
$asOf = hm_intel_h($intel['asOf']);
$generated = hm_intel_h($intel['generatedAt']);
$fetched = hm_intel_h($intel['fetchedAt'] !== '' ? $intel['fetchedAt'] : '尚未連上');
$liveNote = $intel['liveOk']
    ? '已連 TrendForce 新聞中心，資料基準 ' . $asOf . '。'
    : 'TrendForce 暫時連不上，先用內建判讀，基準仍是今天 ' . $asOf . '。';
?>
<!doctype html>
<html lang="zh-Hant">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>記憶體與儲存行情判讀</title>
  <style>
    :root { --ink:#172033; --muted:#667085; --line:#d9e1eb; --paper:#f5f7fb; --blue:#155eef; --red:#b42318; --amber:#b54708; --green:#067647; }
    * { box-sizing:border-box; }
    body { margin:0; font-family:Arial,"Microsoft JhengHei",sans-serif; color:var(--ink); background:var(--paper); }
    .wrap { max-width:1280px; margin:0 auto; padding:24px; }
    .head { display:flex; justify-content:space-between; gap:18px; align-items:flex-start; padding:4px 0 20px; }
    h1 { font-size:25px; margin:0 0 7px; letter-spacing:0; }
    .sub { color:var(--muted); line-height:1.6; font-size:14px; }
    .stamp { white-space:nowrap; font-size:13px; color:var(--muted); padding:9px 12px; background:#fff; border:1px solid var(--line); border-radius:6px; text-align:right; }
    .stamp b { color:var(--ink); }
    .stamp a { display:inline-block; margin-top:6px; }
    .grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; }
    .card { background:#fff; border:1px solid var(--line); border-radius:7px; padding:18px; }
    .card h2 { font-size:17px; margin:0 0 12px; }
    .signal { font-size:27px; font-weight:700; margin:5px 0 8px; }
    .up { color:var(--red); } .watch { color:var(--amber); } .steady { color:var(--green); }
    .label { display:inline-block; border-radius:4px; padding:4px 8px; font-size:12px; font-weight:700; }
    .label.up { background:#fff0ee; } .label.watch { background:#fff7e8; } .label.steady { background:#ecfdf3; }
    .detail { color:var(--muted); font-size:14px; line-height:1.65; }
    .section { margin-top:16px; }
    .section h2 { font-size:18px; margin:0 0 10px; }
    .actions { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; }
    .action { border-left:4px solid var(--blue); }
    .action strong { display:block; margin-bottom:7px; }
    .news { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
    .news article { background:#fff; border:1px solid var(--line); border-radius:7px; padding:15px; }
    .news h3 { font-size:15px; line-height:1.45; margin:0 0 8px; }
    .news p { font-size:13px; line-height:1.6; color:var(--muted); margin:0 0 10px; }
    a { color:var(--blue); text-decoration:none; } a:hover { text-decoration:underline; }
    .rule { margin-top:16px; padding:13px 15px; border:1px solid #b2ddff; background:#eff8ff; color:#1849a9; border-radius:6px; font-size:13px; line-height:1.6; }
    .small { color:var(--muted); font-size:12px; }
    @media (max-width:780px) { .wrap { padding:15px; } .head { display:block; } .stamp { display:inline-block; margin-top:10px; text-align:left; } .grid,.actions,.news { grid-template-columns:1fr; } h1 { font-size:22px; } }
  </style>
</head>
<body>
  <main class="wrap">
    <header class="head">
      <div>
        <h1>記憶體與儲存行情判讀</h1>
        <div class="sub">給採購、報價與備貨使用。聚焦 DRAM、SSD/NAND 與 HDD。打開本頁會抓 TrendForce 當日公開消息，不再停在兩天前。</div>
      </div>
      <div class="stamp">
        資料基準：<b><?= $asOf ?></b><br>
        頁面產生：<?= $generated ?><br>
        連線時間：<?= $fetched ?>
        <div><a href="?refresh=1">立刻重新抓取</a></div>
      </div>
    </header>

    <section class="grid">
      <?php foreach ($intel['cards'] as $card): ?>
        <article class="card">
          <span class="label <?= hm_intel_h($card['tone']) ?>"><?= hm_intel_h($card['label']) ?></span>
          <h2><?= hm_intel_h($card['title']) ?></h2>
          <div class="signal <?= hm_intel_h($card['tone']) ?>"><?= hm_intel_h($card['signal']) ?></div>
          <p class="detail"><?= hm_intel_h($card['detail']) ?></p>
        </article>
      <?php endforeach; ?>
    </section>

    <section class="section"><h2>現在怎麼報價與備貨</h2>
      <div class="actions">
        <?php foreach ($intel['actions'] as $action): ?>
          <article class="card action">
            <strong><?= hm_intel_h($action['title']) ?></strong>
            <div class="detail"><?= hm_intel_h($action['detail']) ?></div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="section"><h2>判讀依據與最新消息</h2>
      <p class="small" style="margin-top:0"><?= $liveNote ?></p>
      <div class="news">
        <?php foreach ($intel['news'] as $row): ?>
          <article>
            <h3><a href="<?= hm_intel_h($row['url']) ?>" target="_blank" rel="noopener"><?= hm_intel_h($row['title']) ?></a></h3>
            <p><?= hm_intel_h(($row['date'] ?? '') . '　' . ($row['summary'] ?? '')) ?></p>
            <span class="small"><?= hm_intel_h($row['read'] ?? '') ?></span>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
    <div class="rule"><strong>使用原則：</strong>本頁是採購風險與報價提醒，不是價格保證或投資建議。實際進貨價仍要以供應商當日報價、庫存、交期、品牌與規格為準。快取最多三小時；要最新請按「立刻重新抓取」。</div>
  </main>
</body>
</html>
