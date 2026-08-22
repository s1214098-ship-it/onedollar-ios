<?php
declare(strict_types=1);

$ticketDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'baohui-ops-tickets-' . bin2hex(random_bytes(4));
putenv('BAOHUI_OPS_TICKET_DIR=' . $ticketDir);
$_SERVER['HTTPS'] = 'on';

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ops-embed-auth-lib.php';

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

$ticket = baohui_ops_issue_embed_ticket([
    'user' => 'admin',
    'isAdmin' => true,
    'role' => '最高管理員',
]);
expect(strlen($ticket) === 32, 'ticket is 32 hex chars');
expect(is_file(baohui_ops_ticket_path($ticket)), 'ticket file exists after issue');

$consumed = baohui_ops_consume_embed_ticket($ticket);
expect(is_array($consumed) && ($consumed['user'] ?? '') === 'admin', 'consume returns HQ user');
expect(!empty($consumed['isAdmin']), 'consume keeps admin flag');
expect(!is_file(baohui_ops_ticket_path($ticket)), 'ticket is one-time and deleted');
expect(baohui_ops_consume_embed_ticket($ticket) === null, 'second consume fails');

expect(baohui_ops_consume_embed_ticket('not-a-ticket') === null, 'reject malformed ticket');
expect(baohui_ops_consume_embed_ticket('') === null, 'reject empty ticket');

$expiredTicket = baohui_ops_issue_embed_ticket(['user' => '王小明', 'isAdmin' => false, 'role' => '店員']);
$expiredPath = baohui_ops_ticket_path($expiredTicket);
$payload = json_decode((string)file_get_contents($expiredPath), true);
$payload['expiresAt'] = time() - 10;
file_put_contents($expiredPath, json_encode($payload));
expect(baohui_ops_consume_embed_ticket($expiredTicket) === null, 'expired ticket is rejected');

$_SESSION = [];
baohui_ops_apply_ticket_to_session(['user' => '李建宏', 'isAdmin' => false, 'role' => '管理總監']);
expect(!empty($_SESSION['baohui_logged_in']), 'apply sets HQ login flag');
expect(($_SESSION['baohui_user'] ?? '') === '李建宏', 'apply sets HQ user');
expect(empty($_SESSION['baohui_is_admin']), 'employee ticket is not admin');

foreach (glob($ticketDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($ticketDir);

if ($failed > 0) {
    fwrite(STDERR, $failed . " assertion(s) failed\n");
    exit(1);
}
echo "all passed\n";
