<?php
declare(strict_types=1);

const BAOHUI_OPS_TICKET_TTL_SECONDS = 120;
const BAOHUI_OPS_TICKET_BYTES = 16;

function baohui_ops_ticket_dir(): string
{
    $configured = trim((string)getenv('BAOHUI_OPS_TICKET_DIR'));
    if ($configured !== '') return rtrim($configured, "\\/");

    $private = trim((string)getenv('BAOHUI_ACCOUNTING_DATA_DIR'));
    if ($private !== '') {
        return rtrim($private, "\\/") . DIRECTORY_SEPARATOR . 'ops-embed-tickets';
    }

    $livePrivate = 'F:' . DIRECTORY_SEPARATOR . 'Data' . DIRECTORY_SEPARATOR . 'BaohuiAccounting' . DIRECTORY_SEPARATOR . 'ops-embed-tickets';
    if (is_dir(dirname($livePrivate)) || is_dir($livePrivate)) return $livePrivate;

    return __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'ops-embed-tickets';
}

function baohui_ops_ticket_ensure_dir(): string
{
    $dir = baohui_ops_ticket_dir();
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create ops embed ticket storage.');
    }
    $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }
    return $dir;
}

function baohui_ops_ticket_path(string $ticket): string
{
    $clean = preg_replace('/[^a-f0-9]/', '', strtolower($ticket)) ?? '';
    if ($clean === '' || strlen($clean) !== (BAOHUI_OPS_TICKET_BYTES * 2)) {
        throw new InvalidArgumentException('Invalid ticket.');
    }
    return baohui_ops_ticket_ensure_dir() . DIRECTORY_SEPARATOR . $clean . '.json';
}

function baohui_ops_issue_embed_ticket(array $identity): string
{
    $user = trim((string)($identity['user'] ?? $identity['name'] ?? $identity['account'] ?? ''));
    if ($user === '') {
        throw new InvalidArgumentException('Ticket identity is missing a user.');
    }
    $ticket = bin2hex(random_bytes(BAOHUI_OPS_TICKET_BYTES));
    $payload = [
        'ticket' => $ticket,
        'user' => $user,
        'isAdmin' => !empty($identity['isAdmin']),
        'role' => trim((string)($identity['role'] ?? ($identity['isAdmin'] ? '最高管理員' : ''))),
        'issuedAt' => time(),
        'expiresAt' => time() + BAOHUI_OPS_TICKET_TTL_SECONDS,
    ];
    $path = baohui_ops_ticket_path($ticket);
    $tmp = $path . '.tmp';
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write ops embed ticket.');
    }
    if (!@rename($tmp, $path)) {
        @unlink($path);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to store ops embed ticket.');
        }
    }
    return $ticket;
}

function baohui_ops_read_ticket_file(string $path): ?array
{
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function baohui_ops_ticket_expired(?array $payload): bool
{
    if (!is_array($payload)) return true;
    $expires = (int)($payload['expiresAt'] ?? 0);
    return $expires < time();
}

function baohui_ops_consume_embed_ticket(string $ticket): ?array
{
    $ticket = trim($ticket);
    if ($ticket === '') return null;
    try {
        $path = baohui_ops_ticket_path($ticket);
    } catch (Throwable $e) {
        return null;
    }
    if (!is_file($path)) return null;

    $payload = baohui_ops_read_ticket_file($path);
    @unlink($path);
    if (baohui_ops_ticket_expired($payload)) return null;
    $user = trim((string)($payload['user'] ?? ''));
    if ($user === '') return null;
    return $payload;
}

function baohui_ops_apply_ticket_to_session(array $payload): void
{
    $user = trim((string)($payload['user'] ?? ''));
    if ($user === '') return;
    $isAdmin = !empty($payload['isAdmin']);
    $role = trim((string)($payload['role'] ?? ''));
    if ($role === '') $role = $isAdmin ? '最高管理員' : '管理總監';
    $_SESSION['baohui_logged_in'] = true;
    $_SESSION['baohui_user'] = $user;
    $_SESSION['baohui_is_admin'] = $isAdmin;
    $_SESSION['baohui_role'] = $role;
}

function baohui_ops_request_ticket(): string
{
    foreach (['baohui_ticket', 'embed_ticket'] as $key) {
        $fromGet = trim((string)($_GET[$key] ?? ''));
        if ($fromGet !== '') return $fromGet;
        $fromPost = trim((string)($_POST[$key] ?? ''));
        if ($fromPost !== '') return $fromPost;
    }
    return '';
}

function baohui_ops_boot_hq_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    session_name('BAOHUI_ADMIN');
    ini_set('session.gc_maxlifetime', '86400');
    $params = [
        'lifetime' => 86400,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    session_set_cookie_params($params);

    $isEmbed = (($_GET['embed'] ?? '') === '1')
        || (stripos((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''), 'iframe') !== false);
    $hasCookie = !empty($_COOKIE[session_name()]);
    $ticket = baohui_ops_request_ticket();

    // Empty iframe hits must not mint a blank BAOHUI_ADMIN cookie that overwrites HQ login.
    if ($isEmbed && !$hasCookie && $ticket === '') {
        ini_set('session.use_cookies', '0');
    }

    session_start();

    if ($ticket !== '') {
        $payload = baohui_ops_consume_embed_ticket($ticket);
        if (is_array($payload)) {
            baohui_ops_apply_ticket_to_session($payload);
        }
    }
}
