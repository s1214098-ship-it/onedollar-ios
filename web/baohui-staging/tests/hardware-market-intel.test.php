<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'hardware-market-intel-lib.php';

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

date_default_timezone_set('Asia/Taipei');
expect(hm_intel_today() === (new DateTimeImmutable('now', new DateTimeZone('Asia/Taipei')))->format('Y-m-d'), 'as-of date is Taipei today');
expect(hm_intel_today() !== '2026-08-18', 'as-of date is not frozen on 2026-08-18');

$html = <<<HTML
Daily Express Agu.19,2026 Spot Market Today
the average price of DDR4 8G (1Gx8) 3200 rises to USD 43.070 and more
Daily Express Agu.18,2026 Spot Market Today
the average price of DDR4 8G (1Gx8) 3200 stays at USD 42.895 and more
<h2><a class="text-m" href="/presscenter/news/20260818-13186.html">Combined Revenue of Top Five NAND Flash Brands Rises 77% QoQ in 2Q26; Micron Moves Up to Third Place, Says TrendForce</a></h2>
<h2><a href="/presscenter/news/20260819-13189.html">China’s Humanoid Robot Market to Reach CNY 15 Billion in 2026</a></h2>
HTML;

$spot = hm_intel_parse_spot($html);
expect(($spot[0]['usd'] ?? '') === '43.070', 'parses Aug 19 DDR4 spot 43.070');
expect(($spot[0]['move'] ?? '') === '小漲', 'Aug 19 spot is marked up');
expect(($spot[1]['usd'] ?? '') === '42.895', 'parses Aug 18 DDR4 spot 42.895');

$news = hm_intel_parse_news($html);
expect(count($news) === 1, 'filters out non-memory robot headline');
expect(str_contains($news[0]['title'] ?? '', 'NAND Flash'), 'keeps NAND revenue headline');

$cards = hm_intel_curated_cards();
$blob = json_encode($cards, JSON_UNESCAPED_UNICODE);
expect(str_contains($blob, '8/19') || str_contains($blob, '43.07') || str_contains($blob, '77%'), 'cards mention the new Aug 18-19 facts');

$page = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'hardware-market-intel.php');
expect(str_contains($page, '立刻重新抓取'), 'page has a manual refresh');
expect(!str_contains($page, '資料基準：2026-08-18'), 'page stamp is not hardcoded to Aug 18');

$js = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'admin-hardware-market-intel.js');
expect(str_contains($js, 'hardware-market-intel.php?v='), 'iframe cache-busts by current time');

if ($failed > 0) {
    fwrite(STDERR, $failed . " assertion(s) failed\n");
    exit(1);
}
echo "all passed\n";
