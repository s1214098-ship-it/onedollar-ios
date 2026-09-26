<?php
declare(strict_types=1);

$failed = 0;
function expect($ok, string $msg): void
{
    global $failed;
    if ($ok) {
        echo "ok  $msg\n";
        return;
    }
    $failed++;
    echo "FAIL  $msg\n";
}

$root = dirname(__DIR__);
$js = (string)file_get_contents($root . DIRECTORY_SEPARATOR . 'admin-hide-codex-report.js');
expect($js !== '', 'overlay script exists');
expect(str_contains($js, '#codexReportDashboardCard'), 'hides dashboard 回報通報中心 card');
expect(str_contains($js, '#codexChangeNoticeDashboardCard'), 'hides dashboard 功能異動提醒 card');
expect(str_contains($js, '#page-codexReport'), 'hides CODEX 回報 page');
expect(str_contains($js, 'a[data-page="codexReport"]'), 'hides sidebar nav');
expect(str_contains($js, '#perm_codexReport'), 'hides permission checkbox');
expect(str_contains($js, 'display: none !important'), 'uses !important so admin-main unhide cannot win');
expect(str_contains($js, 'wrapGoPage') || str_contains($js, '__baohuiHideCodex'), 'wraps goPage');
expect(str_contains($js, 'codexReport') && str_contains($js, 'dashboard'), 'bounces codexReport to dashboard');
expect(!str_contains($js, 'data.codexReports = []') && !str_contains($js, 'delete data.codexReports'), 'does not wipe stored reports');

$fixture = <<<'HTML'
<!doctype html><html><head></head><body>
<a class="nav-link" data-page="codexReport">CODEX 回報</a>
<a class="nav-link" data-page="dashboard">控制台</a>
<div class="form-card" id="codexChangeNoticeDashboardCard"><h5>CODEX 功能異動提醒</h5></div>
<div class="form-card" id="codexReportDashboardCard"><h5>CODEX 回報通報中心</h5></div>
<div id="page-codexReport" class="page-content">page</div>
<div class="col-md-4"><div class="form-check"><input id="perm_codexReport" type="checkbox"><label>CODEX 回報</label></div></div>
<script>
function goPage(pg) { window.__lastPage = pg; document.getElementById('page-codexReport').classList.toggle('open', pg === 'codexReport'); }
</script>
HTML;

$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hide-codex-' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0777, true);
$admin = $tmpDir . DIRECTORY_SEPARATOR . 'admin.php';
$scriptBlock = <<<'HTML'
<script src="admin-main.js?v=test" charset="UTF-8"></script>
<script src="admin-customs-duty-verify.js?v=customs-collapse-20260926b" charset="UTF-8"></script>
HTML;
file_put_contents($admin, $fixture . "\n" . $scriptBlock . "\n");

$patcher = $root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'patch-live-hide-codex-admin.php';
exec('php ' . escapeshellarg($patcher) . ' ' . escapeshellarg($admin) . ' hide-codex-test', $out, $code);
expect($code === 0, 'patcher exits 0');
$patched = (string)file_get_contents($admin);
expect(str_contains($patched, 'admin-hide-codex-report.js?v=hide-codex-test'), 'patcher injects cache-busted script tag');
expect(substr_count($patched, 'admin-hide-codex-report.js') === 1, 'patcher injects the tag once');

exec('php ' . escapeshellarg($patcher) . ' ' . escapeshellarg($admin) . ' hide-codex-test2', $out2, $code2);
$patched2 = (string)file_get_contents($admin);
expect($code2 === 0, 'second patcher run exits 0');
expect(str_contains($patched2, 'admin-hide-codex-report.js?v=hide-codex-test2'), 'patcher updates cache-bust version');
expect(substr_count($patched2, 'admin-hide-codex-report.js') === 1, 'second run still one tag');

$node = trim((string)shell_exec('command -v node'));
if ($node !== '') {
    $htmlFile = $tmpDir . DIRECTORY_SEPARATOR . 'fixture.html';
    $html = $fixture . "\n<script>\n" . $js . "\n</script>\n<script>window.goPage('codexReport');</script>";
    file_put_contents($htmlFile, $html);
    $runner = $tmpDir . DIRECTORY_SEPARATOR . 'run.js';
    file_put_contents($runner, <<<'JS'
const fs = require('fs');
const { JSDOM } = require('jsdom');
const html = fs.readFileSync(process.argv[2], 'utf8');
const dom = new JSDOM(html, { runScripts: 'dangerously', url: 'https://baohui.paohui.org/admin.php#codexReport' });
const doc = dom.window.document;
function hidden(sel) {
  const el = doc.querySelector(sel);
  if (!el) return false;
  const display = (el.style && el.style.display) || '';
  return el.hasAttribute('hidden') || display.indexOf('none') >= 0;
}
const style = doc.getElementById('baohui-hide-codex-report-style');
const checks = {
  style: !!(style && /display:\s*none\s*!important/.test(style.textContent)),
  card: hidden('#codexReportDashboardCard'),
  notice: hidden('#codexChangeNoticeDashboardCard'),
  page: hidden('#page-codexReport'),
  nav: hidden('a[data-page="codexReport"]'),
  bounced: dom.window.__lastPage === 'dashboard',
};
console.log(JSON.stringify(checks));
JS
    );
    $jsdomDir = '/tmp/cd-dom';
    $cmd = 'node ' . escapeshellarg($runner) . ' ' . escapeshellarg($htmlFile);
    if (is_dir($jsdomDir)) {
        $cmd = 'NODE_PATH=' . escapeshellarg($jsdomDir . '/node_modules') . ' ' . $cmd;
    }
    $raw = [];
    exec($cmd, $raw, $nodeCode);
    $result = json_decode($raw[0] ?? '', true);
    if (!is_array($result)) {
        expect(false, 'jsdom runner returned JSON (' . implode("\n", $raw) . ')');
    } else {
        expect(!empty($result['style']), 'jsdom injects hide CSS');
        expect(!empty($result['card']), 'jsdom hides 回報通報中心');
        expect(!empty($result['notice']), 'jsdom hides 功能異動提醒');
        expect(!empty($result['page']), 'jsdom hides page');
        expect(!empty($result['nav']), 'jsdom hides nav');
        expect(!empty($result['bounced']), 'jsdom goPage(codexReport) goes to dashboard');
    }
} else {
    echo "skip  jsdom (no node)\n";
}

foreach ([$tmpDir] as $dir) {
    $files = glob($dir . DIRECTORY_SEPARATOR . '*') ?: [];
    foreach ($files as $file) @unlink($file);
    @rmdir($dir);
}

if ($failed > 0) {
    fwrite(STDERR, $failed . " assertion(s) failed\n");
    exit(1);
}
echo "all passed\n";
