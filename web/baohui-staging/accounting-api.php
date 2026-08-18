<?php
declare(strict_types=1);
require __DIR__ . '/accounting-lib.php';

$auth = accounting_require_user();
$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'status');
$pdo = accounting_db();
$rules = accounting_rules();
$paths = accounting_paths();

function gmail_access_token(): string {
    $token = accounting_load_token();
    if (!$token) {
        throw new RuntimeException('尚未連接 Gmail');
    }
    $exp = (int)($token['created'] ?? 0) + (int)($token['expires_in'] ?? 0) - 60;
    if (!empty($token['saved_at'])) {
        $exp = strtotime((string)$token['saved_at']) + (int)($token['expires_in'] ?? 3500) - 60;
    }
    if (!empty($token['access_token']) && time() < $exp) return $token['access_token'];
    $client = accounting_oauth_client();
    if (empty($token['refresh_token'])) throw new RuntimeException('Gmail token 沒有 refresh_token，請重新連接');
    $res = accounting_http('https://oauth2.googleapis.com/token', [
        'post' => [
            'client_id' => $client['client_id'] ?? '',
            'client_secret' => $client['client_secret'] ?? '',
            'refresh_token' => $token['refresh_token'],
            'grant_type' => 'refresh_token',
        ],
    ]);
    if (empty($res['ok']) || empty($res['json']['access_token'])) {
        throw new RuntimeException('Gmail token 更新失敗');
    }
    $token = array_merge($token, $res['json']);
    $token['saved_at'] = date('c');
    accounting_save_token($token);
    return $token['access_token'];
}

try {
    switch ($action) {
        case 'status':
            $token = accounting_load_token();
            accounting_json([
                'ok' => true,
                'user' => $auth['user'],
                'gmailConnected' => !empty($token),
                'gmailAccount' => $token['account'] ?? ($rules['gmailAccount'] ?? ''),
                'matchFrom' => $rules['matchFrom'],
                'buyerTaxId' => $rules['buyerTaxId'],
                'jieyuanTaxId' => $rules['jieyuanTaxId'],
                'paths' => [
                    'sqlite' => is_file($paths['sqlite']),
                    'oauthClient' => is_file($paths['oauthClient']),
                    'oauthToken' => is_file($paths['oauthToken']),
                    'stock' => is_file($paths['stock']),
                    'cacert' => is_file($paths['cacert']),
                ],
            ]);
            break;

        case 'purchases':
            accounting_json(['ok' => true, 'docs' => accounting_purchase_docs()]);
            break;

        case 'list':
            $rows = $pdo->query('SELECT * FROM invoices ORDER BY invoice_date DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
            accounting_json(['ok' => true, 'invoices' => $rows]);
            break;

        case 'match':
            $docs = accounting_purchase_docs();
            $updated = 0;
            foreach ($pdo->query('SELECT * FROM invoices')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result = accounting_match_invoice($row, $docs);
                $pdo->prepare('UPDATE invoices SET match_status=?, match_doc_no=?, match_note=?, updated_at=? WHERE id=?')->execute([
                    $result['match_status'], $result['match_doc_no'], $result['match_note'], date('c'), $row['id']
                ]);
                $updated++;
            }
            accounting_json(['ok' => true, 'updated' => $updated]);
            break;

        case 'mark':
            $input = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
            $id = (int)($input['id'] ?? 0);
            $kind = (string)($input['kind'] ?? '');
            if (!$id || !in_array($kind, ['printed', 'posted', 'inbox'], true)) {
                accounting_json(['ok' => false, 'error' => '參數錯誤'], 400);
                break;
            }
            $field = $kind === 'inbox' ? 'status' : ($kind === 'printed' ? 'printed_at' : 'posted_at');
            if ($kind === 'inbox') {
                $pdo->prepare('UPDATE invoices SET status=?, updated_at=? WHERE id=?')->execute(['inbox', date('c'), $id]);
            } else {
                $pdo->prepare("UPDATE invoices SET $field=?, status=?, updated_at=? WHERE id=?")->execute([date('c'), $kind === 'printed' ? 'printed' : 'posted', date('c'), $id]);
            }
            accounting_json(['ok' => true]);
            break;

        case 'oauth_url':
            accounting_json(['ok' => true, 'url' => 'accounting-gmail-oauth.php']);
            break;

        case 'disconnect_gmail':
            @unlink($paths['oauthToken']);
            accounting_json(['ok' => true]);
            break;

        case 'sync_gmail':
            $token = gmail_access_token();
            $q = sprintf(
                'from:%s subject:%s after:%s',
                $rules['gmailFrom'],
                $rules['gmailSubject'],
                str_replace('-', '/', $rules['matchFrom'])
            );
            $list = accounting_http('https://gmail.googleapis.com/gmail/v1/users/me/messages?' . http_build_query(['q' => $q, 'maxResults' => 50]), [
                'headers' => ['Authorization: Bearer ' . $token],
            ]);
            if (empty($list['ok'])) throw new RuntimeException('讀 Gmail 失敗：' . ($list['body'] ?? ''));
            $imported = 0;
            $skipped = 0;
            foreach (($list['json']['messages'] ?? []) as $msg) {
                $detail = accounting_http('https://gmail.googleapis.com/gmail/v1/users/me/messages/' . rawurlencode($msg['id']) . '?format=full', [
                    'headers' => ['Authorization: Bearer ' . $token],
                ]);
                if (empty($detail['ok'])) continue;
                $headers = [];
                foreach ($detail['json']['payload']['headers'] ?? [] as $h) {
                    $headers[strtolower($h['name'])] = $h['value'];
                }
                $subject = $headers['subject'] ?? '';
                $from = $headers['from'] ?? '';
                if (accounting_should_skip_invoice(['subject' => $subject, 'from_email' => $from, 'seller_name' => ''])) {
                    $skipped++;
                    continue;
                }
                $parts = [];
                $stack = [$detail['json']['payload'] ?? []];
                while ($stack) {
                    $part = array_pop($stack);
                    if (!empty($part['parts']) && is_array($part['parts'])) {
                        foreach ($part['parts'] as $child) $stack[] = $child;
                    }
                    $parts[] = $part;
                }
                foreach ($parts as $part) {
                    $filename = $part['filename'] ?? '';
                    $mime = strtolower((string)($part['mimeType'] ?? ''));
                    $attId = $part['body']['attachmentId'] ?? '';
                    if ($filename === '' || $attId === '') continue;
                    if (!str_ends_with(strtolower($filename), '.pdf') && $mime !== 'application/pdf') continue;
                    $att = accounting_http(
                        'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . rawurlencode($msg['id']) . '/attachments/' . rawurlencode($attId),
                        ['headers' => ['Authorization: Bearer ' . $token]]
                    );
                    if (empty($att['ok']) || empty($att['json']['data'])) continue;
                    $bin = base64_decode(strtr($att['json']['data'], '-_', '+/'));
                    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: ('invoice-' . $msg['id'] . '.pdf');
                    $dest = $paths['inbox'] . DIRECTORY_SEPARATOR . date('Ymd') . '-' . $msg['id'] . '-' . $safe;
                    file_put_contents($dest, $bin);
                    $parsed = accounting_parse_invoice_text(accounting_extract_pdf_text($dest), [
                        'subject' => $subject,
                        'from_email' => $from,
                        'filename' => $filename,
                    ]);
                    if (accounting_should_skip_invoice($parsed)) {
                        $skipped++;
                        continue;
                    }
                    $docs = accounting_purchase_docs();
                    $match = accounting_match_invoice($parsed, $docs);
                    $now = date('c');
                    $pdo->prepare('INSERT INTO invoices(gmail_id, filename, path, invoice_no, invoice_date, seller_name, seller_tax_id, buyer_tax_id, amount, tax, total, subject, from_email, status, match_doc_no, match_status, match_note, created_at, updated_at)
                        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                        ON CONFLICT(gmail_id) DO UPDATE SET
                          filename=excluded.filename, path=excluded.path, invoice_no=excluded.invoice_no, invoice_date=excluded.invoice_date,
                          seller_name=excluded.seller_name, seller_tax_id=excluded.seller_tax_id, total=excluded.total,
                          match_doc_no=excluded.match_doc_no, match_status=excluded.match_status, match_note=excluded.match_note, updated_at=excluded.updated_at
                    ')->execute([
                        $msg['id'] . ':' . $safe,
                        $filename,
                        $dest,
                        $parsed['invoice_no'],
                        $parsed['invoice_date'],
                        $parsed['seller_name'],
                        $parsed['seller_tax_id'],
                        $parsed['buyer_tax_id'] ?: $rules['buyerTaxId'],
                        $parsed['amount'],
                        $parsed['tax'],
                        $parsed['total'],
                        $subject,
                        $from,
                        'inbox',
                        $match['match_doc_no'],
                        $match['match_status'],
                        $match['match_note'],
                        $now,
                        $now,
                    ]);
                    $imported++;
                }
            }
            accounting_json(['ok' => true, 'imported' => $imported, 'skipped' => $skipped]);
            break;

        default:
            accounting_json(['ok' => false, 'error' => '未知 action'], 400);
    }
} catch (Throwable $e) {
    accounting_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
