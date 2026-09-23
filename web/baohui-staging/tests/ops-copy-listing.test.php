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

$patcher = dirname(__DIR__) . '/one-dollar-auction/tools/patch-live-copy-listing-ops.py';
expect(is_file($patcher), 'live copy-listing patcher exists');

$fixture = <<<'JS'
document.querySelectorAll('.copy-facebook-listing').forEach((btn) => {
  btn.addEventListener('click', async () => {
    const text = btn.closest('form')?.querySelector('.schedule-listing-draft')?.value || '';
    try { await navigator.clipboard.writeText(text); btn.textContent = '已複製'; setTimeout(() => btn.textContent = '複製上架文案', 1200); }
    catch (e) { btn.closest('form')?.querySelector('.schedule-listing-draft')?.select(); document.execCommand('copy'); }
  });
});
document.querySelectorAll('.copy-fb-playbook').forEach((btn) => {
  btn.addEventListener('click', async () => {
    const text = document.getElementById('facebookDailyPlaybook')?.value || '';
    try { await navigator.clipboard.writeText(text); btn.textContent = '已複製'; setTimeout(() => btn.textContent = '複製整份日報', 1200); }
    catch (e) { document.getElementById('facebookDailyPlaybook')?.select(); document.execCommand('copy'); }
  });
});
document.querySelectorAll('.copy-schedule-qa').forEach((btn) => {
  btn.addEventListener('click', async () => {
    const text = btn.closest('form')?.querySelector('.schedule-qa-draft')?.value || '';
    try { await navigator.clipboard.writeText(text); btn.textContent = '已複製'; setTimeout(() => btn.textContent = '複製問答包', 1200); }
    catch (e) { btn.closest('form')?.querySelector('.schedule-qa-draft')?.select(); document.execCommand('copy'); }
  });
});

JS;

$tmp = sys_get_temp_dir() . '/ops-copy-listing-fixture.js';
file_put_contents($tmp, $fixture);
$cmd = escapeshellarg('python3') . ' ' . escapeshellarg($patcher) . ' ' . escapeshellarg($tmp);
exec($cmd . ' 2>&1', $out, $code);
expect($code === 0, 'patcher applies to the live copy needles: ' . implode(' ', $out));
$patched = (string)file_get_contents($tmp);
@unlink($tmp);

expect(!str_contains($patched, "querySelectorAll('.copy-facebook-listing')"), 'one-shot listing querySelectorAll is gone');
expect(!str_contains($patched, "querySelectorAll('.copy-schedule-qa')"), 'one-shot QA querySelectorAll is gone');
expect(str_contains($patched, 'bindOpsCopyListingButtons'), 'delegated copy helper is installed');
expect(str_contains($patched, "document.addEventListener('click'"), 'copy uses document click delegation');
expect(str_contains($patched, "closest?.('.copy-facebook-listing')"), 'listing button is matched on click');
expect(str_contains($patched, "closest?.('.copy-schedule-qa')"), 'QA button is matched on the same click');
expect(str_contains($patched, "opsCopyTextSync"), 'sync execCommand copy runs before clipboard');
expect(str_contains($patched, "document.execCommand('copy')"), 'keeps execCommand fallback');
expect(str_contains($patched, '已複製'), 'success still shows 已複製');
expect(str_contains($patched, '複製失敗'), 'failure is visible instead of a dead button');
expect(str_contains($patched, '沒有文案'), 'empty draft shows 沒有文案');
expect(str_contains($patched, '.schedule-work-card'), 'textarea is resolved from the card, not only closest form');
expect(str_contains($patched, "querySelectorAll('.copy-fb-playbook')"), 'unrelated copy-fb-playbook handler is left alone');

$overlay = dirname(__DIR__) . '/one-dollar-auction/operations.php';
expect(is_file($overlay), 'git overlay operations.php exists');
$ops = (string)file_get_contents($overlay);
expect(str_contains($ops, 'class="secondary copy-facebook-listing"'), 'schedule card still has 複製上架文案');
expect(str_contains($ops, 'class="secondary copy-schedule-qa"'), 'schedule card still has 複製問答包');
expect(str_contains($ops, 'class="schedule-listing-draft"'), 'listing textarea class is unchanged');
expect(str_contains($ops, 'bindOpsCopyListingButtons'), 'git overlay operations.php uses delegated copy');
expect(!str_contains($ops, "querySelectorAll('.copy-facebook-listing')"), 'overlay no longer binds listing copy once at parse time');

if ($failed > 0) {
    fwrite(STDERR, "$failed failed\n");
    exit(1);
}
echo "all copy listing tests passed\n";
