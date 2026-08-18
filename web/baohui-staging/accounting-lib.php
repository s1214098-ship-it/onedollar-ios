<?php
declare(strict_types=1);

function accounting_rules(): array {
    static $rules;
    if ($rules) return $rules;
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'accounting-rules.json';
    $rules = json_decode((string)file_get_contents($path), true) ?: [];
    return $rules;
}

function accounting_data_dir(): string {
    $env = getenv('BAOHUI_ACCOUNTING_DIR');
    if ($env) return rtrim($env, "/\\");
    return 'F:\\Data\\BaohuiAccounting';
}

function accounting_web_dir(): string {
    $env = getenv('BAOHUI_WEB_DIR');
    if ($env) return rtrim($env, "/\\");
    return 'F:\\Web\\baohui-staging';
}

function accounting_paths(): array {
    $data = accounting_data_dir();
    $web = accounting_web_dir();
    return [
        'data' => $data,
        'sqlite' => $data . DIRECTORY_SEPARATOR . 'accounting.sqlite',
        'oauthClient' => $data . DIRECTORY_SEPARATOR . 'gmail-oauth-client.json',
        'oauthToken' => $data . DIRECTORY_SEPARATOR . 'gmail-oauth-token.bin',
        'invoices' => $data . DIRECTORY_SEPARATOR . 'Invoices',
        'inbox' => $data . DIRECTORY_SEPARATOR . 'Invoices' . DIRECTORY_SEPARATOR . 'GmailInbox',
        'printed' => $data . DIRECTORY_SEPARATOR . 'Invoices' . DIRECTORY_SEPARATOR . '已列印',
        'files' => $data . DIRECTORY_SEPARATOR . 'Invoices' . DIRECTORY_SEPARATOR . '電子檔',
        'stock' => $web . DIRECTORY_SEPARATOR . 'one-dollar-auction' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'stock_movements.json',
        'cacert' => $web . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . 'cacert.pem',
    ];
}

function accounting_ensure_dirs(): void {
    $p = accounting_paths();
    foreach (['data', 'invoices', 'inbox', 'printed', 'files'] as $key) {
        if (!is_dir($p[$key])) @mkdir($p[$key], 0775, true);
    }
}

function accounting_db(): PDO {
    accounting_ensure_dirs();
    $pdo = new PDO('sqlite:' . accounting_paths()['sqlite']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            gmail_id TEXT UNIQUE,
            filename TEXT,
            path TEXT,
            invoice_no TEXT,
            invoice_date TEXT,
            seller_name TEXT,
            seller_tax_id TEXT,
            buyer_tax_id TEXT,
            amount REAL,
            tax REAL,
            total REAL,
            subject TEXT,
            from_email TEXT,
            status TEXT DEFAULT 'inbox',
            match_doc_no TEXT,
            match_status TEXT DEFAULT 'unmatched',
            match_note TEXT,
            printed_at TEXT,
            posted_at TEXT,
            created_at TEXT,
            updated_at TEXT
        );
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        );
    ");
    return $pdo;
}

function accounting_current_user(): array {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (!empty($_COOKIE['BAOHUI_ADMIN']) && session_name() !== 'BAOHUI_ADMIN') {
            session_name('BAOHUI_ADMIN');
        }
        session_start();
    }
    $user = trim((string)($_SESSION['user'] ?? $_SESSION['login_user'] ?? $_SESSION['uid'] ?? $_SESSION['admin_user'] ?? ''));
    $isAdmin = !empty($_SESSION['isAdmin']) || $user === 'admin';
    if ($user === '' && !empty($_SESSION['loggedIn'])) {
        $user = 'admin';
        $isAdmin = true;
    }
    return ['user' => $user, 'isAdmin' => $isAdmin, 'ok' => $user !== ''];
}

function accounting_require_user(): array {
    $auth = accounting_current_user();
    if (!$auth['ok']) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => '尚未登入'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $auth;
}

function accounting_json($payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function accounting_norm_date(?string $text): string {
    $text = trim(str_replace(['/', '.'], '-', (string)$text));
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $text, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    return '';
}

function accounting_should_skip_invoice(array $inv): bool {
    $rules = accounting_rules();
    $blob = strtolower(($inv['seller_name'] ?? '') . ' ' . ($inv['from_email'] ?? '') . ' ' . ($inv['subject'] ?? '') . ' ' . ($inv['filename'] ?? ''));
    foreach ($rules['skipKeywords'] ?? [] as $word) {
        if ($word !== '' && mb_stripos($blob, strtolower($word)) !== false) return true;
    }
    $seller = preg_replace('/\D+/', '', (string)($inv['seller_tax_id'] ?? ''));
    foreach ($rules['skipTaxIds'] ?? [] as $taxId) {
        if ($seller !== '' && $seller === preg_replace('/\D+/', '', (string)$taxId)) return true;
    }
    return false;
}

function accounting_load_purchases(): array {
    $path = accounting_paths()['stock'];
    if (!is_file($path)) return [];
    $raw = json_decode((string)file_get_contents($path), true);
    return is_array($raw) ? $raw : [];
}

function accounting_purchase_docs(): array {
    $rules = accounting_rules();
    $from = $rules['matchFrom'] ?? '2026-08-18';
    $docs = [];
    foreach (accounting_load_purchases() as $row) {
        $type = (string)($row['type'] ?? $row['source_doc_type'] ?? '');
        if (!preg_match('/進貨/', $type)) continue;
        $date = accounting_norm_date((string)($row['date'] ?? $row['document_date'] ?? ''));
        if ($date === '' || $date < $from) continue;
        $docNo = (string)($row['document_no'] ?? $row['source_doc_no'] ?? '');
        if ($docNo === '') continue;
        if (!isset($docs[$docNo])) {
            $docs[$docNo] = [
                'document_no' => $docNo,
                'date' => $date,
                'supplier' => $row['supplier_name'] ?? $row['party_name'] ?? '',
                'type' => $type,
                'amount' => 0,
                'lines' => 0,
                'titles' => [],
            ];
        }
        $docs[$docNo]['amount'] += (float)($row['amount'] ?? $row['total_amount'] ?? $row['line_subtotal'] ?? 0);
        $docs[$docNo]['lines']++;
        if (!empty($row['product_title'])) $docs[$docNo]['titles'][] = $row['product_title'];
        if ($docs[$docNo]['supplier'] === '' && !empty($row['supplier_name'])) $docs[$docNo]['supplier'] = $row['supplier_name'];
    }
    return array_values($docs);
}

function accounting_amounts_match(float $invoiceTotal, float $docAmount, float $tolerance = 1.0): bool {
    $candidates = [
        $docAmount,
        round($docAmount * 1.05, 0),
        round($docAmount / 1.05, 0),
        round($docAmount * 1.05, 2),
        round($docAmount / 1.05, 2),
    ];
    foreach ($candidates as $value) {
        if (abs($invoiceTotal - (float)$value) <= $tolerance) return true;
    }
    return false;
}

function accounting_match_invoice(array $invoice, array $docs): array {
    $rules = accounting_rules();
    $from = $rules['matchFrom'] ?? '2026-08-18';
    $date = accounting_norm_date((string)($invoice['invoice_date'] ?? ''));
    if ($date !== '' && $date < $from) {
        return ['match_status' => 'ignored', 'match_doc_no' => '', 'match_note' => '早於 ' . $from . '，不比對'];
    }
    if (accounting_should_skip_invoice($invoice)) {
        return ['match_status' => 'ignored', 'match_doc_no' => '', 'match_note' => '排除 Agoda／統一數網'];
    }
    $total = (float)($invoice['total'] ?? $invoice['amount'] ?? 0);
    $best = null;
    foreach ($docs as $doc) {
        if (!accounting_amounts_match($total, (float)$doc['amount'], (float)($rules['amountTolerance'] ?? 1))) continue;
        $score = 0;
        if (($invoice['seller_tax_id'] ?? '') === ($rules['jieyuanTaxId'] ?? '')) $score += 5;
        if (mb_strpos((string)$doc['supplier'], '捷元') !== false) $score += 5;
        if ($date !== '' && $doc['date'] === $date) $score += 3;
        $row = $doc + ['score' => $score];
        if (!$best || $row['score'] > $best['score']) $best = $row;
    }
    if (!$best) {
        return ['match_status' => 'unmatched', 'match_doc_no' => '', 'match_note' => '找不到同金額進貨單'];
    }
    return [
        'match_status' => 'matched',
        'match_doc_no' => $best['document_no'],
        'match_note' => '對到 ' . $best['document_no'] . '（' . $best['date'] . '／' . $best['amount'] . '）',
    ];
}

function accounting_extract_pdf_text(string $path): string {
    $raw = (string)@file_get_contents($path);
    if ($raw === '') return '';
    $chunks = [];
    if (preg_match_all('/stream\r?\n(.+?)endstream/s', $raw, $matches)) {
        foreach ($matches[1] as $blob) {
            $decoded = @gzuncompress($blob);
            if ($decoded === false) $decoded = @gzinflate($blob);
            $chunks[] = $decoded !== false ? $decoded : $blob;
        }
    }
    $chunks[] = $raw;
    $text = implode("\n", $chunks);
    $text = preg_replace('/[^\P{C}\n]+/u', ' ', $text) ?: $text;
    return $text;
}

function accounting_parse_invoice_text(string $text, array $meta = []): array {
    $rules = accounting_rules();
    $invoiceNo = '';
    if (preg_match('/\b([A-Z]{2}\d{8})\b/u', $text, $m)) $invoiceNo = $m[1];
    $taxIds = [];
    if (preg_match_all('/(?<!\d)(\d{8})(?!\d)/', $text, $m)) $taxIds = $m[1];
    $buyer = $rules['buyerTaxId'];
    $seller = '';
    foreach ($taxIds as $id) {
        if ($id === $buyer) continue;
        $seller = $id;
        if ($id === ($rules['jieyuanTaxId'] ?? '')) break;
    }
    $total = 0.0;
    foreach (['總計', '應稅銷售額', '銷售額合計', '合計', '總金額', 'Amount'] as $label) {
        if (preg_match('/' . preg_quote($label, '/') . '[^\d]{0,12}([\d,]+(?:\.\d{1,2})?)/u', $text, $m)) {
            $total = (float)str_replace(',', '', $m[1]);
            if ($total > 0) break;
        }
    }
    $date = '';
    if (preg_match('/(20\d{2}[\/.-]\d{1,2}[\/.-]\d{1,2})/', $text, $m)) $date = accounting_norm_date($m[1]);
    $name = '捷元股份有限公司';
    if (preg_match('/捷元[^\\s]{0,8}/u', $text, $m)) $name = $m[0];
    return [
        'invoice_no' => $invoiceNo,
        'invoice_date' => $date,
        'seller_name' => $name,
        'seller_tax_id' => $seller,
        'buyer_tax_id' => in_array($buyer, $taxIds, true) ? $buyer : ($meta['buyer_tax_id'] ?? ''),
        'amount' => $total,
        'tax' => $total > 0 ? round($total - ($total / 1.05), 2) : 0,
        'total' => $total,
        'subject' => $meta['subject'] ?? '',
        'from_email' => $meta['from_email'] ?? '',
        'filename' => $meta['filename'] ?? '',
    ];
}

function accounting_oauth_client(): array {
    $path = accounting_paths()['oauthClient'];
    if (!is_file($path)) return [];
    $json = json_decode((string)file_get_contents($path), true);
    if (!is_array($json)) return [];
    return $json['web'] ?? $json['installed'] ?? $json;
}

function accounting_token_key(array $client): string {
    return hash('sha256', (string)($client['client_secret'] ?? 'baohui-accounting'), true);
}

function accounting_save_token(array $token): void {
    $client = accounting_oauth_client();
    $payload = json_encode($token, JSON_UNESCAPED_UNICODE);
    $iv = random_bytes(16);
    $enc = openssl_encrypt($payload, 'AES-256-CBC', accounting_token_key($client), OPENSSL_RAW_DATA, $iv);
    file_put_contents(accounting_paths()['oauthToken'], $iv . $enc);
}

function accounting_load_token(): array {
    $path = accounting_paths()['oauthToken'];
    if (!is_file($path)) return [];
    $bin = (string)file_get_contents($path);
    if (strlen($bin) < 17) return [];
    $client = accounting_oauth_client();
    $plain = openssl_decrypt(substr($bin, 16), 'AES-256-CBC', accounting_token_key($client), OPENSSL_RAW_DATA, substr($bin, 0, 16));
    $json = json_decode((string)$plain, true);
    return is_array($json) ? $json : [];
}

function accounting_http(string $url, array $opts = []): array {
    $paths = accounting_paths();
    $ch = curl_init($url);
    $headers = $opts['headers'] ?? [];
    $curl = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if (is_file($paths['cacert'])) $curl[CURLOPT_CAINFO] = $paths['cacert'];
    if (!empty($opts['post'])) {
        $curl[CURLOPT_POST] = true;
        $curl[CURLOPT_POSTFIELDS] = $opts['post'];
    }
    curl_setopt_array($ch, $curl);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) return ['ok' => false, 'error' => $err, 'status' => $code];
    $json = json_decode($body, true);
    return ['ok' => $code >= 200 && $code < 300, 'status' => $code, 'json' => $json, 'body' => $body];
}
