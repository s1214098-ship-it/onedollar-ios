<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'accounting-lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'accounting-jieyuan-po.php';

interface AccountingMailSource
{
    public function fetchAfter(string $cursor, int $limit = 100): iterable;
    public function nextCursor(): string;
}

final class GmailInvoiceSourceConfiguration
{
    public const READONLY_SCOPE = 'https://www.googleapis.com/auth/gmail.readonly';
    public const MODIFY_SCOPE = 'https://www.googleapis.com/auth/gmail.modify';
    public const SETTINGS_SCOPE = 'https://www.googleapis.com/auth/gmail.settings.basic';
    public const ORGANIZE_SCOPES = self::READONLY_SCOPE . ' ' . self::MODIFY_SCOPE . ' ' . self::SETTINGS_SCOPE;
    public const CURSOR_LABEL = 'Cursor';
    public const CURSOR_QUERY = '(from:notifications@github.com (cursor[bot] OR "cursor[bot]")) OR from:cursor[bot] OR from:(noreply@cursor.com OR mail.cursor.com OR notifications@cursor.sh OR cursor.com)';
    public const JIEYUAN_SENDER = '捷元ebill@gcnc-group.com';
    public const JIEYUAN_SUBJECT = '捷元電子對帳單';
    public const FOODPANDA_SENDER = 'info@mail.foodpanda.com.tw';
    public const PCHOME_SENDER = 'shoppingsc@pchome.com.tw';
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const GMAIL_API = 'https://gmail.googleapis.com/gmail/v1/users/me';

    public function __construct(
        public readonly int $syncMinutes,
        public readonly string $credentialReference,
    ) {
        if ($syncMinutes < 1 || $syncMinutes > 1440) throw new InvalidArgumentException('Invalid sync interval.');
        if ($credentialReference === '') throw new InvalidArgumentException('Credential reference is required.');
    }

    public static function fromDatabase(PDO $pdo): self
    {
        return new self(
            (int)acc_setting($pdo, 'gmail_sync_minutes', ACC_DEFAULT_SYNC_MINUTES),
            'windows-dpapi:baohui-accounting-gmail'
        );
    }

    public static function searchQuery(): string
    {
        return 'has:attachment filename:pdf newer_than:730d ('
            . 'from:ebill@gcnc-group.com'
            . ' OR from:' . self::JIEYUAN_SENDER
            . ' OR from:EInvoice@gcnc-group.com'
            . ' OR from:' . self::FOODPANDA_SENDER
            . ' OR from:' . self::PCHOME_SENDER
            . ' OR subject:"' . self::JIEYUAN_SUBJECT . '"'
            . ' OR subject:電子發票 OR filename:電子發票證明聯'
            . ') -from:agoda.com -subject:Agoda';
    }
}

function acc_gmail_client_path(): string
{
    return acc_private_root() . DIRECTORY_SEPARATOR . 'gmail-oauth-client.json';
}

function acc_gmail_token_path(): string
{
    return acc_private_root() . DIRECTORY_SEPARATOR . 'gmail-oauth-token.bin';
}

function acc_gmail_key_path(): string
{
    return acc_private_root() . DIRECTORY_SEPARATOR . '.gmail-key';
}

function acc_gmail_secret_key(): string
{
    $path = acc_gmail_key_path();
    if (is_file($path)) {
        $key = (string)file_get_contents($path);
        if (strlen($key) >= 32) return substr($key, 0, 32);
    }
    $key = random_bytes(32);
    file_put_contents($path, $key);
    if (strncasecmp(PHP_OS, 'WIN', 3) !== 0) {
        @chmod($path, 0600);
    }
    return $key;
}

function acc_gmail_encrypt(string $plain): string
{
    $key = acc_gmail_secret_key();
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('無法加密 Gmail 憑證');
    return base64_encode($iv . $tag . $cipher);
}

function acc_gmail_decrypt(string $packed): string
{
    $raw = base64_decode($packed, true);
    if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Gmail 憑證損壞');
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', acc_gmail_secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) throw new RuntimeException('無法解密 Gmail 憑證');
    return $plain;
}

function acc_gmail_public_base(): string
{
    return 'https://baohui.paohui.org';
}

function acc_gmail_redirect_uri(): string
{
    return 'https://baohui.paohui.org/accounting-gmail-oauth.php';
}

function acc_gmail_cainfo(): string
{
    foreach ([
        __DIR__ . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . 'cacert.pem',
        acc_private_root() . DIRECTORY_SEPARATOR . 'cacert.pem',
    ] as $path) {
        if (is_file($path)) return $path;
    }
    return '';
}

function acc_gmail_http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('無法連線 Google');
    $headerLines = [];
    foreach ($headers as $name => $value) $headerLines[] = $name . ': ' . $value;
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => $headerLines,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    $ca = acc_gmail_cainfo();
    if ($ca !== '') $opts[CURLOPT_CAINFO] = $ca;
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('Google 連線失敗：' . $error);
    $json = json_decode((string)$raw, true);
    if ($status >= 400) {
        $message = is_array($json) ? (string)(($json['error_description'] ?? $json['error']['message'] ?? $json['error'] ?? 'HTTP ' . $status)) : ('HTTP ' . $status);
        throw new RuntimeException('Google API：' . $message);
    }
    return is_array($json) ? $json : ['raw' => $raw];
}

function acc_gmail_load_client(): array
{
    $path = acc_gmail_client_path();
    if (!is_file($path) || !is_readable($path)) return [];
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') return [];
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
    $data = json_decode($raw, true);
    if (!is_array($data)) return [];
    $web = is_array($data['web'] ?? null) ? $data['web'] : $data;
    $id = trim((string)($web['client_id'] ?? $data['client_id'] ?? ''));
    $secret = trim((string)($web['client_secret'] ?? $data['client_secret'] ?? ''));
    if ($id === '' || $secret === '') return [];
    return ['client_id' => $id, 'client_secret' => $secret];
}

function acc_gmail_save_client(string $clientId, string $clientSecret): void
{
    $clientId = trim($clientId);
    $clientSecret = trim($clientSecret);
    if ($clientId === '' || $clientSecret === '') throw new InvalidArgumentException('請填 Google Client ID 與 Client Secret');
    $payload = [
        'web' => [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'auth_uri' => GmailInvoiceSourceConfiguration::AUTH_URL,
            'token_uri' => GmailInvoiceSourceConfiguration::TOKEN_URL,
            'redirect_uris' => [acc_gmail_redirect_uri(), 'https://baohui.paohui.org/accounting-gmail-oauth.php'],
        ],
    ];
    acc_ensure_private_directories();
    file_put_contents(acc_gmail_client_path(), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if (strncasecmp(PHP_OS, 'WIN', 3) !== 0) {
        @chmod(acc_gmail_client_path(), 0600);
    }
}

function acc_gmail_load_token(): array
{
    $path = acc_gmail_token_path();
    if (!is_file($path)) return [];
    try {
        $data = json_decode(acc_gmail_decrypt((string)file_get_contents($path)), true);
        return is_array($data) ? $data : [];
    } catch (Throwable $e) {
        return [];
    }
}

function acc_gmail_save_token(array $token): void
{
    acc_ensure_private_directories();
    $current = acc_gmail_load_token();
    if (!empty($current['refresh_token']) && empty($token['refresh_token'])) {
        $token['refresh_token'] = $current['refresh_token'];
    }
    $token['saved_at'] = date('c');
    if (!isset($token['expires_at']) && isset($token['expires_in'])) {
        $token['expires_at'] = time() + (int)$token['expires_in'] - 60;
    }
    file_put_contents(acc_gmail_token_path(), acc_gmail_encrypt(json_encode($token, JSON_UNESCAPED_UNICODE)));
    if (strncasecmp(PHP_OS, 'WIN', 3) !== 0) {
        @chmod(acc_gmail_token_path(), 0600);
    }
}

function acc_gmail_clear_token(): void
{
    $path = acc_gmail_token_path();
    if (is_file($path)) @unlink($path);
}

function acc_gmail_oauth_status(): array
{
    $client = acc_gmail_load_client();
    $token = acc_gmail_load_token();
    $connected = trim((string)($token['refresh_token'] ?? $token['access_token'] ?? '')) !== '';
    return [
        'has_client' => $client !== [],
        'connected' => $connected,
        'email' => (string)($token['email'] ?? ''),
        'redirect_uri' => acc_gmail_redirect_uri(),
        'scope' => GmailInvoiceSourceConfiguration::READONLY_SCOPE,
        'search_query' => GmailInvoiceSourceConfiguration::searchQuery(),
        'jieyuan_sender' => GmailInvoiceSourceConfiguration::JIEYUAN_SENDER,
        'jieyuan_subject' => GmailInvoiceSourceConfiguration::JIEYUAN_SUBJECT,
        'foodpanda_sender' => GmailInvoiceSourceConfiguration::FOODPANDA_SENDER,
        'pchome_sender' => GmailInvoiceSourceConfiguration::PCHOME_SENDER,
        'last_sync' => acc_gmail_last_sync(),
        'sync_minutes' => (int)acc_setting(acc_db(), 'gmail_sync_minutes', ACC_DEFAULT_SYNC_MINUTES),
    ];
}

function acc_gmail_last_sync(): array
{
    try {
        $row = acc_db()->query("SELECT started_at, finished_at, status, scanned_count, imported_count, exception_count, error_summary FROM gmail_sync_runs ORDER BY id DESC LIMIT 1")->fetch();
        return is_array($row) ? $row : [];
    } catch (Throwable $e) {
        return [];
    }
}

function acc_gmail_sync_lock_path(): string
{
    return acc_private_root() . DIRECTORY_SEPARATOR . 'gmail-sync.lock';
}

function acc_gmail_sync_running(): bool
{
    $path = acc_gmail_sync_lock_path();
    return is_file($path) && (time() - (int)filemtime($path)) < 900;
}

function acc_gmail_sync_enqueue(): array
{
    if (acc_gmail_sync_running()) {
        return [
            'queued' => false,
            'running' => true,
            'message' => '正在抓信，請稍候再重新整理。',
        ];
    }
    $php = 'C:\\PHP82\\php.exe';
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'gmail-sync.php';
    if (!is_file($php) || !is_file($script)) {
        throw new RuntimeException('找不到抓信程式');
    }
    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        $cmd = 'cmd /c start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --force';
        pclose(popen($cmd, 'r'));
    } else {
        exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --force >/dev/null 2>&1 &');
    }
    return [
        'queued' => true,
        'running' => true,
        'message' => '已在背景抓信，約一兩分鐘後按重新整理即可看到捷元發票。',
    ];
}

function acc_gmail_exchange_code(string $code): array
{
    $client = acc_gmail_load_client();
    if ($client === []) throw new RuntimeException('尚未設定 Google OAuth 用戶端');
    $token = acc_gmail_http('POST', GmailInvoiceSourceConfiguration::TOKEN_URL, [
        'Content-Type' => 'application/x-www-form-urlencoded',
    ], http_build_query([
        'code' => $code,
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'],
        'redirect_uri' => acc_gmail_redirect_uri(),
        'grant_type' => 'authorization_code',
    ]));
    if (empty($token['access_token'])) throw new RuntimeException('Google 沒有回傳 access token');
    acc_gmail_save_token($token);
    acc_gmail_refresh_profile();
    return acc_gmail_load_token();
}

function acc_gmail_access_token(): string
{
    $token = acc_gmail_load_token();
    $access = trim((string)($token['access_token'] ?? ''));
    $expires = (int)($token['expires_at'] ?? 0);
    if ($expires === 0 && isset($token['expires_in'], $token['saved_at'])) {
        $expires = strtotime((string)$token['saved_at']) + (int)$token['expires_in'] - 60;
    }
    if ($access !== '' && $expires > time()) return $access;
    $refresh = trim((string)($token['refresh_token'] ?? ''));
    if ($refresh === '') throw new RuntimeException('Gmail 尚未授權，請先連接 Gmail');
    $client = acc_gmail_load_client();
    if ($client === []) throw new RuntimeException('尚未設定 Google OAuth 用戶端');
    $next = acc_gmail_http('POST', GmailInvoiceSourceConfiguration::TOKEN_URL, [
        'Content-Type' => 'application/x-www-form-urlencoded',
    ], http_build_query([
        'refresh_token' => $refresh,
        'client_id' => $client['client_id'],
        'client_secret' => $client['client_secret'],
        'grant_type' => 'refresh_token',
    ]));
    $next['refresh_token'] = $refresh;
    $next['expires_at'] = time() + (int)($next['expires_in'] ?? 3500) - 60;
    acc_gmail_save_token($next);
    return (string)$next['access_token'];
}

function acc_gmail_refresh_profile(): string
{
    $token = acc_gmail_access_token();
    $profile = acc_gmail_http('GET', GmailInvoiceSourceConfiguration::GMAIL_API . '/profile', [
        'Authorization' => 'Bearer ' . $token,
    ]);
    $email = trim((string)($profile['emailAddress'] ?? ''));
    if ($email !== '') {
        $stored = acc_gmail_load_token();
        $stored['email'] = $email;
        acc_gmail_save_token($stored);
    }
    return $email;
}

function acc_gmail_authorize_url(string $state, string $scope = ''): string
{
    $client = acc_gmail_load_client();
    if ($client === []) throw new RuntimeException('尚未設定 Google OAuth 用戶端');
    if ($scope === '') $scope = GmailInvoiceSourceConfiguration::READONLY_SCOPE;
    return GmailInvoiceSourceConfiguration::AUTH_URL . '?' . http_build_query([
        'client_id' => $client['client_id'],
        'redirect_uri' => acc_gmail_redirect_uri(),
        'response_type' => 'code',
        'scope' => $scope,
        'access_type' => 'offline',
        'include_granted_scopes' => 'true',
        'prompt' => 'consent',
        'login_hint' => 's1214098@gmail.com',
        'state' => $state,
    ]);
}

function acc_gmail_header(array $payload, string $name): string
{
    $headers = $payload['payload']['headers'] ?? [];
    if (!is_array($headers)) return '';
    foreach ($headers as $header) {
        if (strcasecmp((string)($header['name'] ?? ''), $name) === 0) {
            return trim((string)($header['value'] ?? ''));
        }
    }
    return '';
}

function acc_gmail_collect_parts(array $payload): array
{
    $parts = [];
    $stack = [$payload['payload'] ?? []];
    while ($stack) {
        $part = array_pop($stack);
        if (!is_array($part)) continue;
        $parts[] = $part;
        foreach ((array)($part['parts'] ?? []) as $child) $stack[] = $child;
    }
    return $parts;
}

function acc_gmail_decode_data(string $data): string
{
    $data = strtr($data, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    $out = base64_decode($data, true);
    return $out === false ? '' : $out;
}

function acc_gmail_sync(PDO $pdo, string $actor = 'gmail'): array
{
    $run = $pdo->prepare("INSERT INTO gmail_sync_runs (status) VALUES ('running')");
    $run->execute();
    $runId = (int)$pdo->lastInsertId();
    $imported = 0;
    $attached = 0;
    $skipped = 0;
    $scanned = 0;
    $errors = [];
    $paths = [];
    $metaByPath = [];
    try {
        $access = acc_gmail_access_token();
        $email = acc_gmail_refresh_profile();
        $pageToken = '';
        $seen = 0;
        do {
            $query = [
                'q' => GmailInvoiceSourceConfiguration::searchQuery(),
                'maxResults' => 50,
            ];
            if ($pageToken !== '') $query['pageToken'] = $pageToken;
            $list = acc_gmail_http('GET', GmailInvoiceSourceConfiguration::GMAIL_API . '/messages?' . http_build_query($query), [
                'Authorization' => 'Bearer ' . $access,
            ]);
            foreach ((array)($list['messages'] ?? []) as $row) {
                $messageId = trim((string)($row['id'] ?? ''));
                if ($messageId === '') continue;
                $scanned++;
                $exists = $pdo->prepare('SELECT id FROM gmail_messages WHERE gmail_message_id = ?');
                $exists->execute([$messageId]);
                if ($exists->fetchColumn()) continue;
                $seen++;
                $full = acc_gmail_http('GET', GmailInvoiceSourceConfiguration::GMAIL_API . '/messages/' . rawurlencode($messageId) . '?format=full', [
                    'Authorization' => 'Bearer ' . $access,
                ]);
                $sender = acc_gmail_header($full, 'From');
                $subject = acc_gmail_header($full, 'Subject');
                if (acc_is_ignored_vendor(['sender' => $sender, 'subject' => $subject])) {
                    $pdo->prepare('INSERT OR IGNORE INTO gmail_messages
                        (gmail_message_id, gmail_thread_id, internet_message_id, received_at, sender, subject, parse_status, sync_run_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([
                            $messageId,
                            trim((string)($full['threadId'] ?? '')),
                            acc_gmail_header($full, 'Message-Id'),
                            '',
                            $sender,
                            $subject,
                            'ignored',
                            $runId,
                        ]);
                    $skipped++;
                    continue;
                }
                $received = '';
                $internal = (int)($full['internalDate'] ?? 0);
                if ($internal > 0) $received = date('c', (int)floor($internal / 1000));
                $pdo->prepare('INSERT OR IGNORE INTO gmail_messages
                    (gmail_message_id, gmail_thread_id, internet_message_id, received_at, sender, subject, parse_status, sync_run_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([
                        $messageId,
                        trim((string)($full['threadId'] ?? '')),
                        acc_gmail_header($full, 'Message-Id'),
                        $received,
                        $sender,
                        $subject,
                        'pending',
                        $runId,
                    ]);
                $savedPdfs = 0;
                foreach (acc_gmail_collect_parts($full) as $part) {
                    $filename = basename((string)($part['filename'] ?? ''));
                    $mime = strtolower((string)($part['mimeType'] ?? ''));
                    $attId = trim((string)($part['body']['attachmentId'] ?? ''));
                    $isPdf = str_ends_with(strtolower($filename), '.pdf') || $mime === 'application/pdf';
                    if (!$isPdf || $attId === '') continue;
                    $att = acc_gmail_http('GET', GmailInvoiceSourceConfiguration::GMAIL_API . '/messages/' . rawurlencode($messageId) . '/attachments/' . rawurlencode($attId), [
                        'Authorization' => 'Bearer ' . $access,
                    ]);
                    $bytes = acc_gmail_decode_data((string)($att['data'] ?? ''));
                    if ($bytes === '' || !str_starts_with($bytes, '%PDF')) continue;
                    $safe = acc_safe_folder_name(pathinfo($filename !== '' ? $filename : ('gmail-' . $messageId), PATHINFO_FILENAME)) . '.pdf';
                    $dir = acc_gmail_inbox_dir() . DIRECTORY_SEPARATOR . 'oauth';
                    if (!is_dir($dir)) mkdir($dir, 0770, true);
                    $path = $dir . DIRECTORY_SEPARATOR . $messageId . '-' . $safe;
                    file_put_contents($path, $bytes);
                    $paths[] = $path;
                    $metaByPath[$path] = [
                        'sender' => $sender,
                        'subject' => $subject,
                        'filename' => $filename,
                    ];
                    $savedPdfs++;
                }
                $pdo->prepare("UPDATE gmail_messages SET parse_status=? WHERE gmail_message_id=?")
                    ->execute([$savedPdfs > 0 ? 'imported' : 'no_pdf', $messageId]);
                if ($savedPdfs === 0) $skipped++;
                if ($seen >= 80) break 2;
            }
            $pageToken = trim((string)($list['nextPageToken'] ?? ''));
        } while ($pageToken !== '');

        $result = $paths === []
            ? ['imported' => 0, 'attached' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []]
            : acc_ingest_gmail_pdfs($pdo, $paths, $actor, $metaByPath);
        $imported = (int)($result['imported'] ?? 0);
        $attached = (int)($result['attached'] ?? 0);
        $skipped += (int)($result['skipped'] ?? 0);
        $errors = (array)($result['errors'] ?? []);
        $pdo->prepare("UPDATE gmail_sync_runs SET finished_at=CURRENT_TIMESTAMP, status='ok', scanned_count=?, imported_count=?, exception_count=?, cursor_value=? WHERE id=?")
            ->execute([$scanned, $imported, $skipped, $email, $runId]);
        if (function_exists('acc_jieyuan_reconcile')) {
            try { acc_jieyuan_reconcile($pdo); } catch (Throwable $e) {}
        }
        return [
            'imported' => $imported,
            'attached' => $attached,
            'skipped' => $skipped,
            'scanned' => $scanned,
            'errors' => $errors,
            'email' => $email,
            'updated' => (int)($result['updated'] ?? 0),
        ];
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE gmail_sync_runs SET finished_at=CURRENT_TIMESTAMP, status='error', scanned_count=?, imported_count=?, exception_count=?, error_summary=? WHERE id=?")
            ->execute([$scanned, $imported, $skipped, $e->getMessage(), $runId]);
        throw $e;
    }
}

function acc_normalize_gmail_attachment(array $attachment, string $privatePath): array
{
    $reported = trim((string)($attachment['mime_type'] ?? ''));
    $detected = acc_detect_document_type($privatePath, $reported);
    return [
        'gmail_attachment_id' => trim((string)($attachment['attachment_id'] ?? '')),
        'original_filename' => basename((string)($attachment['filename'] ?? 'attachment')),
        'reported_mime_type' => $reported,
        'detected_mime_type' => $detected,
        'size_bytes' => is_file($privatePath) ? filesize($privatePath) : 0,
        'sha256' => is_file($privatePath) ? hash_file('sha256', $privatePath) : '',
        'storage_path' => $privatePath,
        'is_pdf' => $detected === 'application/pdf',
    ];
}

function acc_select_vendor_parser(array $message, array $attachments): ?AccountingVendorParser
{
    foreach (acc_vendor_parsers() as $parser) {
        if ($parser->supports($message, $attachments)) return $parser;
    }
    return null;
}

function acc_gmail_granted_scopes(): array
{
    $scope = '';
    try {
        $access = acc_gmail_access_token();
        $info = acc_gmail_http('GET', 'https://oauth2.googleapis.com/tokeninfo?access_token=' . rawurlencode($access));
        $scope = trim((string)($info['scope'] ?? ''));
    } catch (Throwable $e) {
        $scope = '';
    }
    if ($scope === '') {
        $scope = trim((string)(acc_gmail_load_token()['scope'] ?? ''));
    }
    if ($scope === '') {
        return [];
    }
    $parts = preg_split('/\s+/', $scope);
    return is_array($parts) ? $parts : [];
}

function acc_gmail_has_scope(string $scope): bool
{
    $scopes = acc_gmail_granted_scopes();
    return in_array($scope, $scopes, true) || in_array('https://mail.google.com/', $scopes, true);
}

function acc_gmail_can_organize(): bool
{
    return acc_gmail_has_scope(GmailInvoiceSourceConfiguration::MODIFY_SCOPE);
}

function acc_gmail_api_get(string $path, array $query = []): array
{
    $url = GmailInvoiceSourceConfiguration::GMAIL_API . $path;
    if ($query) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    return acc_gmail_http('GET', $url, ['Authorization' => 'Bearer ' . acc_gmail_access_token()]);
}

function acc_gmail_api_post(string $path, array $body): array
{
    return acc_gmail_http('POST', GmailInvoiceSourceConfiguration::GMAIL_API . $path, [
        'Authorization' => 'Bearer ' . acc_gmail_access_token(),
        'Content-Type' => 'application/json',
    ], json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function acc_gmail_find_or_create_label(string $name): string
{
    $list = acc_gmail_api_get('/labels');
    foreach ((array)($list['labels'] ?? []) as $label) {
        if (!is_array($label)) continue;
        if (strcasecmp((string)($label['name'] ?? ''), $name) === 0) {
            return (string)($label['id'] ?? '');
        }
    }
    $created = acc_gmail_api_post('/labels', [
        'name' => $name,
        'labelListVisibility' => 'labelShow',
        'messageListVisibility' => 'show',
    ]);
    $id = trim((string)($created['id'] ?? ''));
    if ($id === '') throw new RuntimeException('無法建立 Gmail 資料夾：' . $name);
    return $id;
}

function acc_gmail_list_message_ids(string $query): array
{
    $ids = [];
    $page = '';
    do {
        $params = ['q' => $query, 'maxResults' => 500];
        if ($page !== '') $params['pageToken'] = $page;
        $list = acc_gmail_api_get('/messages', $params);
        foreach ((array)($list['messages'] ?? []) as $row) {
            $id = trim((string)($row['id'] ?? ''));
            if ($id !== '') $ids[] = $id;
        }
        $page = trim((string)($list['nextPageToken'] ?? ''));
    } while ($page !== '');
    return array_values(array_unique($ids));
}

function acc_gmail_ensure_cursor_filter(string $labelId): bool
{
    $query = GmailInvoiceSourceConfiguration::CURSOR_QUERY;
    $existing = acc_gmail_api_get('/settings/filters');
    $filters = $existing['filter'] ?? [];
    if (isset($filters['id']) || isset($filters['criteria'])) {
        $filters = [$filters];
    }
    foreach ((array)$filters as $filter) {
        if (!is_array($filter)) continue;
        $criteria = is_array($filter['criteria'] ?? null) ? $filter['criteria'] : [];
        $action = is_array($filter['action'] ?? null) ? $filter['action'] : [];
        $q = trim((string)($criteria['query'] ?? ''));
        $adds = (array)($action['addLabelIds'] ?? []);
        if ($q === $query && in_array($labelId, $adds, true)) return false;
    }
    acc_gmail_api_post('/settings/filters', [
        'criteria' => ['query' => $query],
        'action' => [
            'addLabelIds' => [$labelId],
            'removeLabelIds' => ['INBOX'],
        ],
    ]);
    return true;
}

function acc_gmail_organize_cursor(): array
{
    if (!acc_gmail_can_organize()) {
        throw new RuntimeException('Gmail 目前只有讀信權限，請先授權「整理 Cursor 信件」。');
    }
    $labelId = acc_gmail_find_or_create_label(GmailInvoiceSourceConfiguration::CURSOR_LABEL);
    $filterCreated = false;
    $filterError = '';
    if (acc_gmail_has_scope(GmailInvoiceSourceConfiguration::SETTINGS_SCOPE)) {
        try {
            $filterCreated = acc_gmail_ensure_cursor_filter($labelId);
        } catch (Throwable $e) {
            $filterError = $e->getMessage();
        }
    }
    $ids = acc_gmail_list_message_ids(GmailInvoiceSourceConfiguration::CURSOR_QUERY);
    $moved = 0;
    foreach (array_chunk($ids, 1000) as $chunk) {
        acc_gmail_api_post('/messages/batchModify', [
            'ids' => $chunk,
            'addLabelIds' => [$labelId],
            'removeLabelIds' => ['INBOX'],
        ]);
        $moved += count($chunk);
    }
    return [
        'ok' => true,
        'email' => acc_gmail_refresh_profile(),
        'label' => GmailInvoiceSourceConfiguration::CURSOR_LABEL,
        'label_id' => $labelId,
        'filter_created' => $filterCreated,
        'filter_error' => $filterError,
        'moved' => $moved,
    ];
}
