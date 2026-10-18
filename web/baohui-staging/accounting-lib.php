<?php
declare(strict_types=1);

const ACC_SCHEMA_VERSION = '1';
const ACC_COMPANY_TAX_ID = '23365425';
const ACC_DEFAULT_SYNC_MINUTES = '15';
const ACC_FULL_CAPABILITIES = [
    'invoice_view',
    'invoice_review',
    'invoice_edit',
    'invoice_book',
    'invoice_export',
    'invoice_print',
];

function acc_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('BAOHUI_ADMIN');
    ini_set('session.gc_maxlifetime', '86400');
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function acc_private_root(): string
{
    $configured = trim((string)getenv('BAOHUI_ACCOUNTING_DATA_DIR'));
    if ($configured !== '') return rtrim($configured, "\\/");
    return dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'Data' . DIRECTORY_SEPARATOR . 'BaohuiAccounting';
}

function acc_ensure_private_directories(): void
{
    $root = acc_private_root();
    foreach ([
        $root,
        $root . DIRECTORY_SEPARATOR . 'Invoices',
        $root . DIRECTORY_SEPARATOR . 'Invoices' . DIRECTORY_SEPARATOR . 'GmailInbox',
        $root . DIRECTORY_SEPARATOR . 'Invoices' . DIRECTORY_SEPARATOR . '電子檔',
        $root . DIRECTORY_SEPARATOR . 'Invoices' . DIRECTORY_SEPARATOR . '已列印',
        $root . DIRECTORY_SEPARATOR . 'Fixtures',
        $root . DIRECTORY_SEPARATOR . 'Exports',
    ] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create accounting private storage.');
        }
    }
}

function acc_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    acc_ensure_private_directories();
    $path = acc_private_root() . DIRECTORY_SEPARATOR . 'accounting.sqlite';
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    acc_migrate($pdo);
    acc_once_reset_test_prints($pdo);
    acc_once_backfill_amounts($pdo);
    return $pdo;
}

function acc_once_reset_test_prints(PDO $pdo): void
{
    $flag = acc_private_root() . DIRECTORY_SEPARATOR . 'print-test-reset-20260817.done';
    if (is_file($flag)) return;
    $pdo->exec("UPDATE electronic_invoices SET print_status='not_printed', print_count=0, first_printed_at='', last_printed_at='', last_printed_by='', last_printer_name='', updated_at=CURRENT_TIMESTAMP WHERE print_status IN ('printed_confirmed','submitted_pending_confirmation','failed')");
    @file_put_contents($flag, date('c') . " reset test prints to not_printed\n");
}

function acc_once_backfill_amounts(PDO $pdo): void
{
    $flag = acc_private_root() . DIRECTORY_SEPARATOR . 'amount-backfill-20260817.done';
    if (is_file($flag)) return;
    $pdo->exec("UPDATE electronic_invoices SET total_amount = net_amount + tax_amount, amount_check_status='balanced', updated_at=CURRENT_TIMESTAMP WHERE workflow_status<>'booked' AND total_amount<=0 AND (net_amount+tax_amount)>0");
    $pdo->exec("UPDATE electronic_invoices SET net_amount = CAST(ROUND(total_amount / 1.05) AS INTEGER), tax_amount = total_amount - CAST(ROUND(total_amount / 1.05) AS INTEGER), amount_check_status='balanced', updated_at=CURRENT_TIMESTAMP WHERE workflow_status<>'booked' AND total_amount>0 AND net_amount<=0 AND tax_amount<=0");
    $result = function_exists('acc_backfill_invoice_amounts_from_pdfs')
        ? acc_backfill_invoice_amounts_from_pdfs($pdo)
        : ['updated' => 0, 'skipped' => 0, 'errors' => []];
    @file_put_contents($flag, date('c') . ' ' . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n");
}

function acc_migrate(PDO $pdo): void
{
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS accounting_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by TEXT NOT NULL DEFAULT 'system'
);

CREATE TABLE IF NOT EXISTS gmail_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    gmail_message_id TEXT NOT NULL UNIQUE,
    gmail_thread_id TEXT NOT NULL DEFAULT '',
    internet_message_id TEXT NOT NULL DEFAULT '',
    received_at TEXT NOT NULL DEFAULT '',
    sender TEXT NOT NULL DEFAULT '',
    subject TEXT NOT NULL DEFAULT '',
    body_sha256 TEXT NOT NULL DEFAULT '',
    raw_storage_path TEXT NOT NULL DEFAULT '',
    parse_status TEXT NOT NULL DEFAULT 'pending',
    parse_error TEXT NOT NULL DEFAULT '',
    sync_run_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_gmail_messages_body_hash ON gmail_messages(body_sha256);

CREATE TABLE IF NOT EXISTS electronic_invoices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_uuid TEXT NOT NULL UNIQUE,
    seller_name TEXT NOT NULL DEFAULT '',
    seller_tax_id TEXT NOT NULL DEFAULT '',
    buyer_name TEXT NOT NULL DEFAULT '',
    buyer_tax_id TEXT NOT NULL DEFAULT '',
    invoice_number TEXT NOT NULL DEFAULT '',
    invoice_date TEXT NOT NULL DEFAULT '',
    random_code TEXT NOT NULL DEFAULT '',
    net_amount INTEGER NOT NULL DEFAULT 0,
    tax_amount INTEGER NOT NULL DEFAULT 0,
    total_amount INTEGER NOT NULL DEFAULT 0,
    source_type TEXT NOT NULL DEFAULT 'gmail',
    source_message_id INTEGER,
    company_match INTEGER NOT NULL DEFAULT 0,
    workflow_status TEXT NOT NULL DEFAULT 'pending_review',
    official_voucher_status TEXT NOT NULL DEFAULT 'not_required',
    parse_confidence REAL NOT NULL DEFAULT 0,
    amount_check_status TEXT NOT NULL DEFAULT 'unchecked',
    duplicate_of INTEGER,
    review_note TEXT NOT NULL DEFAULT '',
    reviewed_by TEXT NOT NULL DEFAULT '',
    reviewed_at TEXT NOT NULL DEFAULT '',
    booked_by TEXT NOT NULL DEFAULT '',
    booked_at TEXT NOT NULL DEFAULT '',
    row_version INTEGER NOT NULL DEFAULT 1,
    current_pdf_type TEXT NOT NULL DEFAULT '',
    current_pdf_storage_path TEXT NOT NULL DEFAULT '',
    current_pdf_sha256 TEXT NOT NULL DEFAULT '',
    current_pdf_generated_at TEXT NOT NULL DEFAULT '',
    current_pdf_version INTEGER NOT NULL DEFAULT 0,
    print_status TEXT NOT NULL DEFAULT 'not_printed',
    first_printed_at TEXT NOT NULL DEFAULT '',
    last_printed_at TEXT NOT NULL DEFAULT '',
    print_count INTEGER NOT NULL DEFAULT 0,
    last_printed_by TEXT NOT NULL DEFAULT '',
    last_printer_name TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(source_message_id) REFERENCES gmail_messages(id),
    FOREIGN KEY(duplicate_of) REFERENCES electronic_invoices(id)
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_invoice_seller_number
    ON electronic_invoices(seller_tax_id, invoice_number)
    WHERE seller_tax_id <> '' AND invoice_number <> '' AND workflow_status <> 'duplicate';
CREATE INDEX IF NOT EXISTS idx_invoice_workflow ON electronic_invoices(workflow_status, invoice_date);
CREATE INDEX IF NOT EXISTS idx_invoice_company ON electronic_invoices(company_match, buyer_tax_id);

CREATE TABLE IF NOT EXISTS invoice_line_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_id INTEGER NOT NULL,
    line_no INTEGER NOT NULL DEFAULT 1,
    item_name TEXT NOT NULL DEFAULT '',
    quantity REAL NOT NULL DEFAULT 0,
    unit TEXT NOT NULL DEFAULT '',
    unit_price INTEGER NOT NULL DEFAULT 0,
    discount_amount INTEGER NOT NULL DEFAULT 0,
    line_amount INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY(invoice_id) REFERENCES electronic_invoices(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS invoice_attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id INTEGER NOT NULL,
    gmail_attachment_id TEXT NOT NULL DEFAULT '',
    original_filename TEXT NOT NULL DEFAULT '',
    reported_mime_type TEXT NOT NULL DEFAULT '',
    detected_mime_type TEXT NOT NULL DEFAULT '',
    size_bytes INTEGER NOT NULL DEFAULT 0,
    sha256 TEXT NOT NULL,
    storage_path TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(message_id) REFERENCES gmail_messages(id) ON DELETE CASCADE,
    UNIQUE(message_id, sha256)
);
CREATE INDEX IF NOT EXISTS idx_attachment_hash ON invoice_attachments(sha256);

CREATE TABLE IF NOT EXISTS invoice_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_id INTEGER NOT NULL,
    pdf_type TEXT NOT NULL,
    pdf_storage_path TEXT NOT NULL,
    pdf_sha256 TEXT NOT NULL,
    pdf_generated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    pdf_version INTEGER NOT NULL DEFAULT 1,
    source_attachment_id INTEGER,
    is_original INTEGER NOT NULL DEFAULT 0,
    created_by TEXT NOT NULL DEFAULT 'system',
    source_path TEXT NOT NULL DEFAULT '',
    source_category TEXT NOT NULL DEFAULT '',
    source_import_key TEXT NOT NULL DEFAULT '',
    original_filename TEXT NOT NULL DEFAULT '',
    source_imported_at TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(invoice_id) REFERENCES electronic_invoices(id) ON DELETE CASCADE,
    FOREIGN KEY(source_attachment_id) REFERENCES invoice_attachments(id),
    UNIQUE(invoice_id, pdf_type, pdf_version),
    UNIQUE(pdf_sha256)
);

CREATE TABLE IF NOT EXISTS invoice_print_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_id INTEGER NOT NULL,
    document_id INTEGER NOT NULL,
    user_name TEXT NOT NULL,
    printed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    printer_name TEXT NOT NULL DEFAULT '',
    copies INTEGER NOT NULL DEFAULT 1,
    pdf_hash TEXT NOT NULL,
    result TEXT NOT NULL,
    reason TEXT NOT NULL DEFAULT '',
    reprint_note TEXT NOT NULL DEFAULT '',
    print_job_id TEXT NOT NULL DEFAULT '',
    FOREIGN KEY(invoice_id) REFERENCES electronic_invoices(id),
    FOREIGN KEY(document_id) REFERENCES invoice_documents(id)
);

CREATE TABLE IF NOT EXISTS invoice_audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_id INTEGER,
    actor TEXT NOT NULL,
    actor_role TEXT NOT NULL DEFAULT '',
    action TEXT NOT NULL,
    detail_json TEXT NOT NULL DEFAULT '{}',
    ip_address TEXT NOT NULL DEFAULT '',
    user_agent TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(invoice_id) REFERENCES electronic_invoices(id)
);
CREATE INDEX IF NOT EXISTS idx_invoice_audit ON invoice_audit_logs(invoice_id, created_at);

CREATE TABLE IF NOT EXISTS invoice_vendor_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    vendor_key TEXT NOT NULL UNIQUE,
    vendor_name TEXT NOT NULL,
    seller_tax_id TEXT NOT NULL DEFAULT '',
    sender_pattern TEXT NOT NULL DEFAULT '',
    subject_pattern TEXT NOT NULL DEFAULT '',
    parser_class TEXT NOT NULL,
    requires_official_voucher INTEGER NOT NULL DEFAULT 0,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS invoice_exports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    export_uuid TEXT NOT NULL UNIQUE,
    export_format TEXT NOT NULL,
    storage_path TEXT NOT NULL DEFAULT '',
    content_sha256 TEXT NOT NULL DEFAULT '',
    record_count INTEGER NOT NULL DEFAULT 0,
    exported_by TEXT NOT NULL,
    exported_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS invoice_export_items (
    export_id INTEGER NOT NULL,
    invoice_id INTEGER NOT NULL,
    PRIMARY KEY(export_id, invoice_id),
    FOREIGN KEY(export_id) REFERENCES invoice_exports(id) ON DELETE CASCADE,
    FOREIGN KEY(invoice_id) REFERENCES electronic_invoices(id)
);

CREATE TABLE IF NOT EXISTS gmail_sync_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'running',
    scanned_count INTEGER NOT NULL DEFAULT 0,
    imported_count INTEGER NOT NULL DEFAULT 0,
    exception_count INTEGER NOT NULL DEFAULT 0,
    cursor_value TEXT NOT NULL DEFAULT '',
    error_summary TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS invoice_partners (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    partner_name TEXT NOT NULL,
    tax_id TEXT NOT NULL DEFAULT '',
    inbound_tax_rate REAL NOT NULL DEFAULT 2,
    outbound_vat_rate REAL NOT NULL DEFAULT 5,
    outbound_income_tax_rate REAL NOT NULL DEFAULT 3,
    note TEXT NOT NULL DEFAULT '',
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS invoice_partner_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    partner_id INTEGER NOT NULL,
    entry_type TEXT NOT NULL,
    purpose TEXT NOT NULL DEFAULT '',
    entry_date TEXT NOT NULL DEFAULT '',
    invoice_number TEXT NOT NULL DEFAULT '',
    face_amount INTEGER NOT NULL DEFAULT 0,
    tax_rate REAL NOT NULL DEFAULT 0,
    vat_amount INTEGER NOT NULL DEFAULT 0,
    income_tax_amount INTEGER NOT NULL DEFAULT 0,
    tax_amount INTEGER NOT NULL DEFAULT 0,
    parks_money INTEGER NOT NULL DEFAULT 0,
    credit_delta INTEGER NOT NULL DEFAULT 0,
    settle_amount INTEGER NOT NULL DEFAULT 0,
    tax_spread INTEGER NOT NULL DEFAULT 0,
    revenue_delta INTEGER NOT NULL DEFAULT 0,
    revenue_deduction INTEGER NOT NULL DEFAULT 0,
    item_note TEXT NOT NULL DEFAULT '',
    created_by TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(partner_id) REFERENCES invoice_partners(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_partner_entries_partner ON invoice_partner_entries(partner_id, entry_date, id);
SQL;
    $pdo->exec($sql);

    $documentColumns = [];
    foreach ($pdo->query('PRAGMA table_info(invoice_documents)') as $column) {
        $documentColumns[(string)$column['name']] = true;
    }
    foreach ([
        'source_path' => "TEXT NOT NULL DEFAULT ''",
        'source_category' => "TEXT NOT NULL DEFAULT ''",
        'source_import_key' => "TEXT NOT NULL DEFAULT ''",
        'original_filename' => "TEXT NOT NULL DEFAULT ''",
        'source_imported_at' => "TEXT NOT NULL DEFAULT ''",
    ] as $column => $definition) {
        if (!isset($documentColumns[$column])) {
            $pdo->exec("ALTER TABLE invoice_documents ADD COLUMN {$column} {$definition}");
        }
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_invoice_document_source_key ON invoice_documents(source_import_key)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_invoice_document_source_category ON invoice_documents(source_category)');

    $invoiceColumns = [];
    foreach ($pdo->query('PRAGMA table_info(electronic_invoices)') as $column) {
        $invoiceColumns[(string)$column['name']] = true;
    }
    foreach ([
        'po_match_status' => "TEXT NOT NULL DEFAULT ''",
        'po_document_no' => "TEXT NOT NULL DEFAULT ''",
        'po_match_note' => "TEXT NOT NULL DEFAULT ''",
        'jieyuan_order_no' => "TEXT NOT NULL DEFAULT ''",
    ] as $column => $definition) {
        if (!isset($invoiceColumns[$column])) {
            $pdo->exec("ALTER TABLE electronic_invoices ADD COLUMN {$column} {$definition}");
        }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS jieyuan_purchase_docs (
        document_no TEXT PRIMARY KEY,
        document_date TEXT NOT NULL DEFAULT '',
        total_amount INTEGER NOT NULL DEFAULT 0,
        products_json TEXT NOT NULL DEFAULT '[]',
        source_mtime INTEGER NOT NULL DEFAULT 0
    )");

    $seed = $pdo->prepare('INSERT OR IGNORE INTO accounting_settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ([
        ['schema_version', ACC_SCHEMA_VERSION],
        ['company_tax_id', ACC_COMPANY_TAX_ID],
        ['company_name', '寶輝電腦'],
        ['gmail_sync_minutes', ACC_DEFAULT_SYNC_MINUTES],
        ['jieyuan_po_match_from', '2026-08-18'],
    ] as $row) $seed->execute($row);

    $vendor = $pdo->prepare('INSERT OR IGNORE INTO invoice_vendor_rules
        (vendor_key, vendor_name, seller_tax_id, sender_pattern, subject_pattern, parser_class, requires_official_voucher)
        VALUES (?, ?, ?, ?, ?, ?, ?)');
    $vendor->execute(['jieyuan', '捷元', '23134543', '捷元ebill@gcnc-group.com', '捷元電子對帳單', 'JieYuanInvoiceParser', 0]);
    $vendor->execute(['foodpanda', 'Foodpanda', '53926705', 'info@mail.foodpanda.com.tw', '電子發票', 'FoodpandaInvoiceParser', 1]);
    $vendor->execute(['pchome', 'PChome 24h購物', '16606102', 'shoppingsc@pchome.com.tw', '電子發票', 'ShoppingMallInvoiceParser', 0]);
    $pdo->prepare("UPDATE invoice_vendor_rules SET sender_pattern=?, subject_pattern=?, seller_tax_id=?, updated_at=CURRENT_TIMESTAMP WHERE vendor_key='jieyuan'")
        ->execute(['捷元ebill@gcnc-group.com', '捷元電子對帳單', '23134543']);
    $pdo->prepare("UPDATE invoice_vendor_rules SET vendor_name=?, sender_pattern=?, subject_pattern=?, seller_tax_id=?, updated_at=CURRENT_TIMESTAMP WHERE vendor_key='foodpanda'")
        ->execute(['Foodpanda', 'info@mail.foodpanda.com.tw', '電子發票', '53926705']);
    $pdo->prepare("UPDATE invoice_vendor_rules SET vendor_name=?, sender_pattern=?, subject_pattern=?, seller_tax_id=?, updated_at=CURRENT_TIMESTAMP WHERE vendor_key='pchome'")
        ->execute(['PChome 24h購物', 'shoppingsc@pchome.com.tw', '電子發票', '16606102']);

    $fillTax = $pdo->prepare('UPDATE invoice_vendor_rules SET seller_tax_id=?, updated_at=CURRENT_TIMESTAMP WHERE vendor_key=? AND seller_tax_id=""');
    $fillTax->execute(['23134543', 'jieyuan']);
    $fillTax->execute(['53926705', 'foodpanda']);
    $fillTax->execute(['16606102', 'pchome']);
    $pdo->exec("UPDATE electronic_invoices SET seller_tax_id='23134543', updated_at=CURRENT_TIMESTAMP WHERE trim(seller_tax_id)='' AND (seller_name LIKE '%捷元%' OR seller_name='購物中心－捷元')");
    $pdo->exec("UPDATE electronic_invoices SET seller_name='捷元股份有限公司', seller_tax_id='23134543', source_type='jieyuan_pdf', updated_at=CURRENT_TIMESTAMP
        WHERE workflow_status <> 'booked'
          AND (trim(seller_name)='' OR trim(seller_tax_id)='')
          AND id IN (
            SELECT invoice_id FROM invoice_documents
            WHERE original_filename LIKE '%電子發票證明聯%'
              AND original_filename NOT LIKE '%70537075%'
          )");
    $pdo->exec("UPDATE electronic_invoices SET seller_tax_id='24951752', updated_at=CURRENT_TIMESTAMP WHERE trim(seller_tax_id)='' AND seller_name LIKE '%捷豐%'");

    $partnerColumns = [];
    foreach ($pdo->query('PRAGMA table_info(invoice_partners)') as $column) {
        $partnerColumns[(string)$column['name']] = true;
    }
    if (!isset($partnerColumns['company_name'])) {
        $pdo->exec("ALTER TABLE invoice_partners ADD COLUMN company_name TEXT NOT NULL DEFAULT ''");
    }
    $entryColumns = [];
    foreach ($pdo->query('PRAGMA table_info(invoice_partner_entries)') as $column) {
        $entryColumns[(string)$column['name']] = true;
    }
    foreach ([
        'issuer_name' => "TEXT NOT NULL DEFAULT ''",
        'issuer_tax_id' => "TEXT NOT NULL DEFAULT ''",
        'receiver_name' => "TEXT NOT NULL DEFAULT ''",
        'receiver_tax_id' => "TEXT NOT NULL DEFAULT ''",
        'source_invoice_id' => 'INTEGER NOT NULL DEFAULT 0',
    ] as $column => $definition) {
        if (!isset($entryColumns[$column])) {
            $pdo->exec("ALTER TABLE invoice_partner_entries ADD COLUMN {$column} {$definition}");
        }
    }
}

function acc_main_data(): array
{
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'baohui.sqlite';
    if (!is_file($path)) return [];
    try {
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $row = $pdo->query('SELECT json_data FROM app_data WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $data = json_decode((string)($row['json_data'] ?? ''), true);
        return is_array($data) ? $data : [];
    } catch (Throwable $e) {
        return [];
    }
}

function acc_user_context(): array
{
    acc_start_session();
    $loggedIn = !empty($_SESSION['baohui_logged_in']);
    $user = trim((string)($_SESSION['baohui_user'] ?? ''));
    $isAdmin = $loggedIn && (!empty($_SESSION['baohui_is_admin']) || strtolower($user) === 'admin');
    $department = '';
    $role = $isAdmin ? '管理員' : '員工';
    $permissions = [];
    if ($loggedIn && !$isAdmin && $user !== '') {
        $data = acc_main_data();
        foreach ((array)($data['employees'] ?? []) as $employee) {
            if (trim((string)($employee['name'] ?? '')) !== $user) continue;
            $department = trim((string)($employee['department'] ?? ''));
            $role = trim((string)($employee['role'] ?? '員工')) ?: '員工';
            break;
        }
        $permissions = (array)(($data['permissions'] ?? [])[$user] ?? []);
    }
    $isAdministrative = !$isAdmin && (strpos($department, '行政') !== false || strpos($role, '行政') !== false);
    $capabilities = [];
    if ($isAdmin || $isAdministrative || in_array('electronicInvoices', $permissions, true)) {
        $capabilities = ACC_FULL_CAPABILITIES;
    } else {
        $capabilities = array_values(array_intersect(ACC_FULL_CAPABILITIES, $permissions));
    }
    return compact('loggedIn', 'user', 'isAdmin', 'department', 'role', 'isAdministrative', 'capabilities');
}

function acc_require_capability(string $capability): array
{
    $context = acc_user_context();
    if (!$context['loggedIn']) acc_json(['ok' => false, 'error' => '尚未登入'], 401);
    if (!in_array($capability, $context['capabilities'], true)) acc_json(['ok' => false, 'error' => '沒有電子發票帳務權限'], 403);
    return $context;
}

function acc_csrf_token(): string
{
    acc_start_session();
    if (empty($_SESSION['accounting_csrf'])) $_SESSION['accounting_csrf'] = bin2hex(random_bytes(24));
    return (string)$_SESSION['accounting_csrf'];
}

function acc_request_raw(): string
{
    static $raw = null;
    if ($raw !== null) return $raw;
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    $raw = str_contains($contentType, 'application/json') ? (string)file_get_contents('php://input') : '';
    return $raw;
}

function acc_provided_csrf(): string
{
    $candidates = [
        (string)($_SERVER['HTTP_X_CSRFTOKEN'] ?? ''),
        (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''),
        (string)($_POST['csrf'] ?? ''),
        (string)($_GET['csrf'] ?? ''),
    ];
    if (acc_request_raw() !== '') {
        $json = json_decode(acc_request_raw(), true);
        if (is_array($json)) $candidates[] = (string)($json['csrf'] ?? '');
    }
    foreach ($candidates as $value) {
        $value = trim($value);
        if ($value !== '') return $value;
    }
    return '';
}

function acc_require_csrf(): void
{
    $provided = acc_provided_csrf();
    if ($provided === '' || !hash_equals(acc_csrf_token(), $provided)) {
        acc_json(['ok' => false, 'error' => '安全驗證失敗，請重新整理頁面'], 419);
    }
}

function acc_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function acc_request_json(): array
{
    $input = json_decode(acc_request_raw(), true);
    if (!is_array($input)) acc_json(['ok' => false, 'error' => '無效的 JSON'], 400);
    return $input;
}

function acc_audit(PDO $pdo, array $context, string $action, ?int $invoiceId = null, array $detail = []): void
{
    foreach (array_keys($detail) as $key) {
        if (preg_match('/token|password|secret|query_url/i', (string)$key)) $detail[$key] = '[REDACTED]';
    }
    $stmt = $pdo->prepare('INSERT INTO invoice_audit_logs
        (invoice_id, actor, actor_role, action, detail_json, ip_address, user_agent)
        VALUES (:invoice_id, :actor, :actor_role, :action, :detail_json, :ip, :ua)');
    $stmt->execute([
        ':invoice_id' => $invoiceId,
        ':actor' => (string)$context['user'],
        ':actor_role' => (string)($context['isAdmin'] ? '管理員' : ($context['department'] ?: $context['role'])),
        ':action' => $action,
        ':detail_json' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        ':ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
    ]);
}

function acc_setting(PDO $pdo, string $key, string $fallback = ''): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM accounting_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $fallback : (string)$value;
}

function acc_our_company(PDO $pdo): array
{
    return [
        'name' => acc_setting($pdo, 'company_name', '寶輝電腦'),
        'tax_id' => acc_setting($pdo, 'company_tax_id', ACC_COMPANY_TAX_ID),
    ];
}

function acc_partner_company(array $partner): array
{
    $name = trim((string)($partner['company_name'] ?? ''));
    if ($name === '') $name = trim((string)($partner['partner_name'] ?? ''));
    return [
        'name' => $name,
        'tax_id' => preg_replace('/\D+/', '', (string)($partner['tax_id'] ?? '')) ?? '',
        'partner_name' => trim((string)($partner['partner_name'] ?? '')),
    ];
}

function acc_partner_parties(PDO $pdo, array $partner, string $direction): array
{
    $ours = acc_our_company($pdo);
    $theirs = acc_partner_company($partner);
    if ($direction === 'outbound') {
        return ['issuer' => $ours, 'receiver' => $theirs, 'ours' => $ours, 'theirs' => $theirs];
    }
    return ['issuer' => $theirs, 'receiver' => $ours, 'ours' => $ours, 'theirs' => $theirs];
}

function acc_partner_invoice_face(array $row): int
{
    $total = (int)($row['total_amount'] ?? 0);
    if ($total > 0) return $total;
    return max(0, (int)($row['net_amount'] ?? 0) + (int)($row['tax_amount'] ?? 0));
}

function acc_partner_electronic_invoices(PDO $pdo, array $partner): array
{
    $theirs = acc_partner_company($partner);
    $ours = acc_our_company($pdo);
    $params = [];
    $where = [];
    if ($theirs['tax_id'] !== '') {
        $where[] = 'seller_tax_id = ? OR buyer_tax_id = ?';
        $params[] = $theirs['tax_id'];
        $params[] = $theirs['tax_id'];
    }
    $names = [];
    foreach ([$theirs['name'] ?? '', $theirs['partner_name'] ?? ''] as $name) {
        $name = trim((string)$name);
        if ($name !== '' && !in_array($name, $names, true)) $names[] = $name;
    }
    foreach ($names as $name) {
        $where[] = 'seller_name LIKE ? OR buyer_name LIKE ?';
        $like = '%' . $name . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if (!$where) return [];
    $stmt = $pdo->prepare("SELECT id, invoice_date, invoice_number, seller_name, seller_tax_id, buyer_name, buyer_tax_id, net_amount, tax_amount, total_amount, workflow_status
        FROM electronic_invoices
        WHERE ((" . implode(') OR (', $where) . "))
          AND workflow_status NOT IN ('exception','duplicate','non_company','failed')
        ORDER BY invoice_date DESC, id DESC LIMIT 200");
    $stmt->execute($params);
    $records = [];
    foreach ($stmt->fetchAll() as $row) {
        $sellerTax = preg_replace('/\D+/', '', (string)($row['seller_tax_id'] ?? '')) ?? '';
        $direction = ($sellerTax !== '' && $sellerTax === $ours['tax_id']) ? 'outbound' : 'inbound';
        $total = acc_partner_invoice_face($row);
        if ($total <= 0) continue;
        $records[] = [
            'key' => 'inv-' . (int)$row['id'],
            'source' => 'electronic',
            'source_invoice_id' => (int)$row['id'],
            'direction' => $direction,
            'entry_date' => (string)($row['invoice_date'] ?? ''),
            'invoice_number' => (string)($row['invoice_number'] ?? ''),
            'face_amount' => $total,
            'net_amount' => (int)($row['net_amount'] ?? 0),
            'paper_vat' => (int)($row['tax_amount'] ?? 0),
            'issuer_name' => (string)($row['seller_name'] ?? ''),
            'issuer_tax_id' => (string)($row['seller_tax_id'] ?? ''),
            'receiver_name' => (string)($row['buyer_name'] ?? ''),
            'receiver_tax_id' => (string)($row['buyer_tax_id'] ?? ''),
            'workflow_status' => (string)($row['workflow_status'] ?? ''),
        ];
    }
    return $records;
}

function acc_partner_invoice_records(PDO $pdo, array $partner): array
{
    $records = acc_partner_electronic_invoices($pdo, $partner);
    $entryStmt = $pdo->prepare("SELECT id, entry_type, purpose, entry_date, invoice_number, face_amount, issuer_name, issuer_tax_id, receiver_name, receiver_tax_id, source_invoice_id
        FROM invoice_partner_entries
        WHERE partner_id = ? AND entry_type IN ('inbound','outbound')
        ORDER BY entry_date DESC, id DESC LIMIT 80");
    $entryStmt->execute([(int)($partner['id'] ?? 0)]);
    foreach ($entryStmt->fetchAll() as $row) {
        $direction = (string)($row['entry_type'] ?? 'inbound');
        $parties = acc_partner_parties($pdo, $partner, $direction);
        $records[] = [
            'key' => 'entry-' . (int)$row['id'],
            'source' => 'entry',
            'source_invoice_id' => (int)($row['source_invoice_id'] ?? 0),
            'direction' => $direction,
            'entry_date' => (string)($row['entry_date'] ?? ''),
            'invoice_number' => (string)($row['invoice_number'] ?? ''),
            'face_amount' => (int)($row['face_amount'] ?? 0),
            'issuer_name' => (string)($row['issuer_name'] ?? '') ?: $parties['issuer']['name'],
            'issuer_tax_id' => (string)($row['issuer_tax_id'] ?? '') ?: $parties['issuer']['tax_id'],
            'receiver_name' => (string)($row['receiver_name'] ?? '') ?: $parties['receiver']['name'],
            'receiver_tax_id' => (string)($row['receiver_tax_id'] ?? '') ?: $parties['receiver']['tax_id'],
        ];
    }
    return $records;
}

function acc_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function acc_is_private_document_path(string $path): bool
{
    $root = realpath(acc_private_root());
    $real = realpath($path);
    return $root !== false && $real !== false && str_starts_with(strtolower($real), strtolower($root . DIRECTORY_SEPARATOR));
}

function acc_safe_folder_name(string $name): string
{
    $name = trim(preg_replace('/[\\\\\/\:\*\?\"<>\|]+/u', '_', $name) ?? '');
    $name = trim($name, " ._");
    if ($name === '') $name = '未分類廠家';
    if (function_exists('mb_substr')) return mb_substr($name, 0, 60);
    return substr($name, 0, 60);
}

function acc_invoice_vendor_label(array $invoice): string
{
    $name = trim((string)($invoice['seller_name'] ?? ''));
    if ($name !== '') return $name;
    $tax = trim((string)($invoice['seller_tax_id'] ?? ''));
    return $tax !== '' ? ('統編 ' . $tax) : '未分類廠家';
}

function acc_is_ignored_vendor(array $meta): bool
{
    $hay = strtolower(
        (string)($meta['seller_name'] ?? '') . ' '
        . (string)($meta['seller_tax_id'] ?? '') . ' '
        . (string)($meta['sender'] ?? '') . ' '
        . (string)($meta['subject'] ?? '') . ' '
        . (string)($meta['filename'] ?? '')
    );
    return str_contains($hay, 'agoda') || str_contains($hay, '42519879');
}

function acc_invoice_month_key(array $invoice): string
{
    $date = trim((string)($invoice['invoice_date'] ?? ''));
    if (preg_match('/^(\d{4}-\d{2})/', $date, $matches)) return $matches[1];
    return 'undated';
}

function acc_invoice_month_label(array $invoice): string
{
    $key = acc_invoice_month_key($invoice);
    if ($key === 'undated') return '未標日期';
    [$year, $month] = explode('-', $key);
    return $year . '年' . ltrim($month, '0') . '月';
}

function acc_invoice_number_from_filename(string $filename): string
{
    $base = strtoupper(pathinfo($filename, PATHINFO_FILENAME));
    if (preg_match('/[A-Z]{2}\d{8}/', $base, $matches)) return $matches[0];
    return acc_clean_invoice_number($base);
}

function acc_invoice_dir(string $bucket, array $invoice): string
{
    $vendor = acc_safe_folder_name(acc_invoice_vendor_label($invoice));
    $dir = acc_private_root() . DIRECTORY_SEPARATOR . 'Invoices' . DIRECTORY_SEPARATOR . $bucket . DIRECTORY_SEPARATOR . $vendor;
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create invoice folder.');
    }
    return $dir;
}

function acc_current_document(PDO $pdo, int $invoiceId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM invoice_documents WHERE invoice_id = ? ORDER BY is_original DESC, pdf_version DESC, id DESC LIMIT 1');
    $stmt->execute([$invoiceId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function acc_find_invoice_by_number(PDO $pdo, string $invoiceNumber, string $sellerTaxId = ''): ?array
{
    if ($invoiceNumber === '') return null;
    if ($sellerTaxId !== '') {
        $stmt = $pdo->prepare("SELECT * FROM electronic_invoices WHERE invoice_number = ? AND seller_tax_id = ? AND workflow_status <> 'duplicate' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$invoiceNumber, $sellerTaxId]);
        $row = $stmt->fetch();
        if ($row) return $row;
    }
    $stmt = $pdo->prepare("SELECT * FROM electronic_invoices WHERE invoice_number = ? AND workflow_status <> 'duplicate' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$invoiceNumber]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function acc_copy_private_file(string $sourcePath, string $destinationPath): string
{
    $dir = dirname($destinationPath);
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create document folder.');
    }
    if (!copy($sourcePath, $destinationPath)) {
        throw new RuntimeException('Unable to store invoice PDF.');
    }
    return $destinationPath;
}

function acc_attach_invoice_pdf(PDO $pdo, array $invoice, string $sourcePath, array $meta = []): array
{
    if (!is_file($sourcePath)) throw new InvalidArgumentException('PDF 檔案不存在');
    $detected = acc_detect_document_type($sourcePath, (string)($meta['mime'] ?? 'application/pdf'));
    if ($detected !== 'application/pdf') throw new InvalidArgumentException('只接受 PDF 電子檔');
    $sha = hash_file('sha256', $sourcePath);
    $existing = $pdo->prepare('SELECT * FROM invoice_documents WHERE pdf_sha256 = ? LIMIT 1');
    $existing->execute([$sha]);
    $found = $existing->fetch();
    if ($found) {
        $electronic = acc_copy_to_electronic_folder($invoice, $sourcePath, (string)($meta['original_filename'] ?? $found['original_filename']));
        if ((int)$found['invoice_id'] !== (int)$invoice['id']) {
            throw new InvalidArgumentException('此 PDF 已歸檔到其他發票');
        }
        return ['document' => $found, 'created' => false, 'electronic_path' => $electronic];
    }
    $pdfType = trim((string)($meta['pdf_type'] ?? 'gmail_attachment')) ?: 'gmail_attachment';
    $versionStmt = $pdo->prepare('SELECT COALESCE(MAX(pdf_version), 0) FROM invoice_documents WHERE invoice_id = ? AND pdf_type = ?');
    $versionStmt->execute([(int)$invoice['id'], $pdfType]);
    $version = (int)$versionStmt->fetchColumn() + 1;
    $original = basename((string)($meta['original_filename'] ?? $sourcePath));
    $number = acc_clean_invoice_number((string)($invoice['invoice_number'] ?? '')) ?: ('ID' . (int)$invoice['id']);
    $storedName = $number . ($version > 1 ? ('-v' . $version) : '') . '.pdf';
    $storedPath = acc_invoice_dir('電子檔', $invoice) . DIRECTORY_SEPARATOR . $storedName;
    if (strtolower(realpath($sourcePath) ?: '') !== strtolower($storedPath)) {
        acc_copy_private_file($sourcePath, $storedPath);
    }
    $insert = $pdo->prepare('INSERT INTO invoice_documents
        (invoice_id, pdf_type, pdf_storage_path, pdf_sha256, pdf_generated_at, pdf_version, is_original, created_by,
         source_category, source_import_key, original_filename, source_imported_at, source_path)
        VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)');
    $insert->execute([
        (int)$invoice['id'], $pdfType, $storedPath, $sha, $version,
        !empty($meta['is_original']) ? 1 : 0,
        trim((string)($meta['created_by'] ?? 'gmail')),
        trim((string)($meta['source_category'] ?? 'gmail_attachment')),
        trim((string)($meta['source_import_key'] ?? $sha)),
        $original, $sourcePath,
    ]);
    $documentId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE electronic_invoices SET current_pdf_type=?, current_pdf_storage_path=?, current_pdf_sha256=?, current_pdf_generated_at=CURRENT_TIMESTAMP, current_pdf_version=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')
        ->execute([$pdfType, $storedPath, $sha, $version, (int)$invoice['id']]);
    $document = $pdo->query('SELECT * FROM invoice_documents WHERE id = ' . $documentId)->fetch();
    return ['document' => $document, 'created' => true, 'electronic_path' => $storedPath];
}

function acc_copy_to_electronic_folder(array $invoice, string $sourcePath, string $originalFilename = ''): string
{
    $number = acc_clean_invoice_number((string)($invoice['invoice_number'] ?? '')) ?: ('ID' . (int)($invoice['id'] ?? 0));
    $name = $number . '.pdf';
    if ($originalFilename !== '' && strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION)) === 'pdf') {
        $safe = acc_safe_folder_name(pathinfo($originalFilename, PATHINFO_FILENAME));
        if ($safe !== '未分類廠家') $name = $number . '.pdf';
    }
    $destination = acc_invoice_dir('電子檔', $invoice) . DIRECTORY_SEPARATOR . $name;
    if (strtolower(realpath($sourcePath) ?: '') === strtolower($destination)) return $destination;
    return acc_copy_private_file($sourcePath, $destination);
}

function acc_archive_printed_pdf(array $invoice, string $sourcePath): string
{
    $number = acc_clean_invoice_number((string)($invoice['invoice_number'] ?? '')) ?: ('ID' . (int)($invoice['id'] ?? 0));
    $destination = acc_invoice_dir('已列印', $invoice) . DIRECTORY_SEPARATOR . $number . '.pdf';
    if (strtolower(realpath($sourcePath) ?: '') === strtolower($destination)) return $destination;
    return acc_copy_private_file($sourcePath, $destination);
}

function acc_invoice_payload_from_parse(array $meta, string $invoiceNumber): array
{
    $buyerTaxId = acc_clean_tax_id((string)($meta['buyer_tax_id'] ?? ''));
    if ($buyerTaxId === '') $buyerTaxId = ACC_COMPANY_TAX_ID;
    $buyerName = trim((string)($meta['buyer_name'] ?? ''));
    if ($buyerName === '') $buyerName = '寶輝電腦';
    return [
        'seller_name' => trim((string)($meta['seller_name'] ?? '')),
        'seller_tax_id' => (string)($meta['seller_tax_id'] ?? ''),
        'buyer_name' => $buyerName,
        'buyer_tax_id' => $buyerTaxId,
        'invoice_number' => $invoiceNumber,
        'invoice_date' => trim((string)($meta['invoice_date'] ?? '')),
        'random_code' => trim((string)($meta['random_code'] ?? '')),
        'net_amount' => (int)($meta['net_amount'] ?? 0),
        'tax_amount' => (int)($meta['tax_amount'] ?? 0),
        'total_amount' => (int)($meta['total_amount'] ?? 0),
        'source_type' => trim((string)($meta['source_type'] ?? 'gmail_pdf_archive')) ?: 'gmail_pdf_archive',
        'parse_confidence' => (float)($meta['parse_confidence'] ?? 0.4),
        'official_voucher_status' => trim((string)($meta['official_voucher_status'] ?? 'available')) ?: 'available',
        'items' => (array)($meta['items'] ?? []),
    ];
}

function acc_apply_parsed_invoice_fields(PDO $pdo, array $invoice, array $meta): array
{
    $id = (int)($invoice['id'] ?? 0);
    if ($id <= 0) return $invoice;
    if (($invoice['workflow_status'] ?? '') === 'booked') return $invoice;
    $existingName = trim((string)($invoice['seller_name'] ?? ''));
    $nameLooksLikeTaxId = preg_match('/^\d{8}$/', $existingName) === 1;
    $needs = (int)($invoice['total_amount'] ?? 0) <= 0
        || (int)($invoice['tax_amount'] ?? 0) <= 0
        || (int)($invoice['net_amount'] ?? 0) <= 0
        || $existingName === ''
        || $nameLooksLikeTaxId
        || trim((string)($invoice['seller_tax_id'] ?? '')) === ''
        || trim((string)($invoice['invoice_date'] ?? '')) === '';
    if (!$needs) return $invoice;
    $parsedTotal = (int)($meta['total_amount'] ?? 0);
    $parsedName = trim((string)($meta['seller_name'] ?? ''));
    $parsedTax = trim((string)($meta['seller_tax_id'] ?? ''));
    $parsedDate = trim((string)($meta['invoice_date'] ?? ''));
    if ($parsedTotal <= 0 && $parsedName === '' && $parsedTax === '' && $parsedDate === '') {
        if ((int)($invoice['total_amount'] ?? 0) > 0 && (int)($invoice['tax_amount'] ?? 0) <= 0) {
            $meta['total_amount'] = (int)$invoice['total_amount'];
            $meta['net_amount'] = (int)($invoice['net_amount'] ?? 0);
            $meta['tax_amount'] = (int)($invoice['tax_amount'] ?? 0);
        } else {
            return $invoice;
        }
    }
    if ($existingName !== '' && !$nameLooksLikeTaxId) $meta['seller_name'] = $existingName;
    elseif ($parsedName !== '') $meta['seller_name'] = $parsedName;
    else $meta['seller_name'] = $existingName;
    $meta['seller_tax_id'] = trim((string)($invoice['seller_tax_id'] ?? '')) !== '' ? $invoice['seller_tax_id'] : $parsedTax;
    $meta['invoice_date'] = trim((string)($invoice['invoice_date'] ?? '')) !== '' ? $invoice['invoice_date'] : $parsedDate;
    if ((int)($invoice['total_amount'] ?? 0) > 0 && $parsedTotal <= 0) {
        $meta['total_amount'] = (int)$invoice['total_amount'];
    }
    if ((int)($invoice['net_amount'] ?? 0) > 0 && (int)($meta['net_amount'] ?? 0) <= 0) {
        $meta['net_amount'] = (int)$invoice['net_amount'];
    }
    if ((int)($invoice['tax_amount'] ?? 0) > 0 && (int)($meta['tax_amount'] ?? 0) <= 0) {
        $meta['tax_amount'] = (int)$invoice['tax_amount'];
    }
    if (function_exists('acc_complete_invoice_amounts')) {
        $meta = acc_complete_invoice_amounts($meta, '');
    }
    $number = acc_clean_invoice_number((string)($invoice['invoice_number'] ?? ''));
    $payload = acc_invoice_payload_from_parse($meta, $number);
    $candidate = acc_candidate($payload, acc_setting($pdo, 'company_tax_id', ACC_COMPANY_TAX_ID));
    $pdo->prepare('UPDATE electronic_invoices SET seller_name=?, seller_tax_id=?, buyer_name=?, buyer_tax_id=?, invoice_date=?, random_code=?, net_amount=?, tax_amount=?, total_amount=?, source_type=?, company_match=?, workflow_status=?, official_voucher_status=?, parse_confidence=?, amount_check_status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')
        ->execute([
            $candidate['seller_name'], $candidate['seller_tax_id'], $candidate['buyer_name'], $candidate['buyer_tax_id'],
            $candidate['invoice_date'], trim((string)($candidate['random_code'] ?? '')),
            $candidate['net_amount'], $candidate['tax_amount'], $candidate['total_amount'],
            $candidate['source_type'], $candidate['company_match'] ? 1 : 0, $candidate['workflow_status'],
            $candidate['official_voucher_status'], $candidate['parse_confidence'], $candidate['amount_check_status'], $id,
        ]);
    $stmt = $pdo->prepare('SELECT * FROM electronic_invoices WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: $invoice;
}

function acc_resolve_pdf_path(string $path): string
{
    if ($path !== '' && is_file($path)) return $path;
    if ($path === '') return '';
    if (preg_match('/BaohuiAccounting[\/\\\\]+(.+)$/i', $path, $m)) {
        $alt = acc_private_root() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $m[1]);
        if (is_file($alt)) return $alt;
    }
    return $path;
}

function acc_backfill_invoice_amounts_from_pdfs(PDO $pdo): array
{
    $updated = 0;
    $skipped = 0;
    $errors = [];
    if (!function_exists('acc_parse_invoice_from_pdf')) return compact('updated', 'skipped', 'errors');
    $rows = $pdo->query("SELECT i.*, d.pdf_storage_path, d.original_filename
        FROM electronic_invoices i
        JOIN invoice_documents d ON d.id = (
            SELECT d2.id FROM invoice_documents d2
            WHERE d2.invoice_id = i.id
            ORDER BY d2.is_original DESC, d2.pdf_version DESC, d2.id DESC
            LIMIT 1
        )
        WHERE i.workflow_status <> 'booked'
          AND (i.total_amount <= 0 OR i.tax_amount <= 0 OR i.net_amount <= 0
               OR trim(i.seller_name) = '' OR trim(i.seller_tax_id) = '' OR trim(i.invoice_date) = '')")->fetchAll();
    foreach ($rows as $row) {
        $path = acc_resolve_pdf_path((string)($row['pdf_storage_path'] ?? ''));
        if (!is_file($path)) {
            $skipped++;
            continue;
        }
        try {
            $parsed = acc_parse_invoice_from_pdf($path, (string)($row['original_filename'] ?: basename($path)));
            $beforeTotal = (int)($row['total_amount'] ?? 0);
            $beforeTax = (int)($row['tax_amount'] ?? 0);
            $beforeName = trim((string)($row['seller_name'] ?? ''));
            $beforeDate = trim((string)($row['invoice_date'] ?? ''));
            $beforeSellerTax = trim((string)($row['seller_tax_id'] ?? ''));
            $invoice = acc_apply_parsed_invoice_fields($pdo, $row, $parsed);
            if (
                (int)($invoice['total_amount'] ?? 0) > $beforeTotal
                || (int)($invoice['tax_amount'] ?? 0) > $beforeTax
                || ($beforeName === '' && trim((string)($invoice['seller_name'] ?? '')) !== '')
                || ($beforeDate === '' && trim((string)($invoice['invoice_date'] ?? '')) !== '')
                || ($beforeSellerTax === '' && trim((string)($invoice['seller_tax_id'] ?? '')) !== '')
            ) {
                $updated++;
            }
        } catch (Throwable $e) {
            $skipped++;
            $errors[] = ((string)($row['invoice_number'] ?: $row['id'])) . '：' . $e->getMessage();
        }
    }
    return compact('updated', 'skipped', 'errors');
}

function acc_ensure_invoice_for_pdf(PDO $pdo, string $invoiceNumber, array $meta = []): array
{
    $existing = acc_find_invoice_by_number($pdo, $invoiceNumber, (string)($meta['seller_tax_id'] ?? ''));
    if ($existing) return acc_apply_parsed_invoice_fields($pdo, $existing, $meta);
    $stored = acc_store_candidate($pdo, acc_invoice_payload_from_parse($meta, $invoiceNumber));
    $stmt = $pdo->prepare('SELECT * FROM electronic_invoices WHERE id = ?');
    $stmt->execute([(int)$stored['invoice_id']]);
    return $stmt->fetch() ?: [];
}

function acc_gmail_inbox_dir(): string
{
    acc_ensure_private_directories();
    return acc_private_root() . DIRECTORY_SEPARATOR . 'Invoices' . DIRECTORY_SEPARATOR . 'GmailInbox';
}

function acc_collect_pdf_files(string $dir): array
{
    if (!is_dir($dir)) return [];
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        if (strtolower($file->getExtension()) !== 'pdf') continue;
        $files[] = $file->getPathname();
    }
    sort($files);
    return $files;
}

function acc_collect_ingest_pdf_paths(): array
{
    $root = acc_private_root() . DIRECTORY_SEPARATOR . 'Invoices';
    $dirs = [
        acc_gmail_inbox_dir(),
        $root . DIRECTORY_SEPARATOR . '待確認',
        $root . DIRECTORY_SEPARATOR . '電子檔',
        $root . DIRECTORY_SEPARATOR . 'Imported',
    ];
    foreach (glob($root . DIRECTORY_SEPARATOR . '20*', GLOB_ONLYDIR) ?: [] as $yearDir) {
        $dirs[] = $yearDir;
    }
    $files = [];
    foreach ($dirs as $dir) $files = array_merge($files, acc_collect_pdf_files($dir));
    return array_values(array_unique($files));
}

function acc_ingest_gmail_pdfs(PDO $pdo, array $paths, string $actor = 'gmail', array $metaByPath = []): array
{
    $imported = 0;
    $attached = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];
    foreach ($paths as $path) {
        $mailMeta = $metaByPath[(string)$path] ?? [];
        $filename = (string)($mailMeta['filename'] ?? basename((string)$path));
        try {
            $parsed = function_exists('acc_parse_invoice_from_pdf')
                ? acc_parse_invoice_from_pdf((string)$path, $filename, $mailMeta)
                : [];
            $invoiceNumber = acc_clean_invoice_number((string)($parsed['invoice_number'] ?? ''));
            if ($invoiceNumber === '') $invoiceNumber = acc_invoice_number_from_filename($filename);
            if ($invoiceNumber === '') {
                $skipped++;
                $errors[] = $filename . '：PDF 與檔名都找不到發票號碼';
                continue;
            }
            if (acc_is_ignored_vendor($parsed + $mailMeta)) {
                $skipped++;
                continue;
            }
            $before = acc_find_invoice_by_number($pdo, $invoiceNumber, (string)($parsed['seller_tax_id'] ?? ''));
            $invoice = acc_ensure_invoice_for_pdf($pdo, $invoiceNumber, $parsed);
            if (!$invoice) {
                $skipped++;
                continue;
            }
            if ($before && (int)($before['total_amount'] ?? 0) <= 0 && (int)($invoice['total_amount'] ?? 0) > 0) {
                $updated++;
            }
            $result = acc_attach_invoice_pdf($pdo, $invoice, (string)$path, [
                'pdf_type' => 'gmail_attachment',
                'source_category' => 'gmail_attachment',
                'original_filename' => $filename,
                'created_by' => $actor,
                'source_import_key' => 'gmail:' . $invoiceNumber . ':' . hash_file('sha256', (string)$path),
                'is_original' => 1,
            ]);
            if ($result['created']) $imported++;
            else $attached++;
        } catch (Throwable $e) {
            $skipped++;
            $errors[] = $filename . '：' . $e->getMessage();
        }
    }
    $backfill = acc_backfill_invoice_amounts_from_pdfs($pdo);
    $updated += (int)($backfill['updated'] ?? 0);
    if (!empty($backfill['errors'])) $errors = array_merge($errors, $backfill['errors']);
    return compact('imported', 'attached', 'updated', 'skipped', 'errors');
}

function acc_mark_invoices_printed(PDO $pdo, array $context, array $invoiceIds, string $printer = '瀏覽器列印', string $note = '大量列印'): array
{
    $groups = [];
    $printed = [];
    $missing = [];
    foreach ($invoiceIds as $id) {
        $id = (int)$id;
        if ($id <= 0) continue;
        $stmt = $pdo->prepare('SELECT * FROM electronic_invoices WHERE id = ?');
        $stmt->execute([$id]);
        $invoice = $stmt->fetch();
        if (!$invoice) {
            $missing[] = $id;
            continue;
        }
        $document = acc_current_document($pdo, $id);
        if (!$document || !is_file((string)$document['pdf_storage_path'])) {
            $missing[] = $id;
            continue;
        }
        $archivePath = acc_archive_printed_pdf($invoice, (string)$document['pdf_storage_path']);
        acc_copy_to_electronic_folder($invoice, (string)$document['pdf_storage_path'], (string)$document['original_filename']);
        $reprintNote = ((string)$invoice['print_status'] !== 'not_printed' || (int)$invoice['print_count'] > 0) ? $note : '';
        $log = $pdo->prepare("INSERT INTO invoice_print_logs (invoice_id, document_id, user_name, printer_name, copies, pdf_hash, result, reprint_note) VALUES (?, ?, ?, ?, 1, ?, 'printed_confirmed', ?)");
        $log->execute([$id, (int)$document['id'], $context['user'], $printer, $document['pdf_sha256'], $reprintNote]);
        $pdo->prepare("UPDATE electronic_invoices SET print_status='printed_confirmed', first_printed_at=CASE WHEN first_printed_at='' THEN CURRENT_TIMESTAMP ELSE first_printed_at END, last_printed_at=CURRENT_TIMESTAMP, print_count=print_count+1, last_printed_by=?, last_printer_name=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$context['user'], $printer, $id]);
        acc_audit($pdo, $context, 'invoice_print_batch', $id, [
            'document_id' => (int)$document['id'],
            'archive_path' => basename(dirname($archivePath)) . '/' . basename($archivePath),
            'printer' => $printer,
        ]);
        $vendor = acc_invoice_vendor_label($invoice);
        $groups[$vendor] ??= ['vendor' => $vendor, 'items' => []];
        $item = [
            'invoice_id' => $id,
            'document_id' => (int)$document['id'],
            'invoice_number' => (string)$invoice['invoice_number'],
            'invoice_date' => (string)$invoice['invoice_date'],
            'seller_name' => (string)$invoice['seller_name'],
            'total_amount' => (int)$invoice['total_amount'],
            'print_url' => 'accounting-api.php?action=download&document_id=' . (int)$document['id'],
        ];
        $groups[$vendor]['items'][] = $item;
        $printed[] = $item;
    }
    ksort($groups, SORT_STRING);
    return ['groups' => array_values($groups), 'printed' => $printed, 'missing' => $missing];
}

function acc_unmark_invoices_printed(PDO $pdo, array $context, array $invoiceIds): int
{
    $count = 0;
    foreach ($invoiceIds as $id) {
        $id = (int)$id;
        if ($id <= 0) continue;
        $pdo->prepare("UPDATE electronic_invoices SET print_status='not_printed', print_count=0, first_printed_at='', last_printed_at='', last_printed_by='', last_printer_name='', updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$id]);
        acc_audit($pdo, $context, 'invoice_print_unmark', $id);
        $count += 1;
    }
    return $count;
}

function acc_rate_amount(int $face, float $rate): int
{
    return (int)round($face * $rate / 100);
}

function acc_paper_split(int $total): array
{
    $total = max(0, $total);
    $net = (int)round($total / 1.05);
    return [
        'net_amount' => $net,
        'paper_vat' => $total - $net,
        'total' => $total,
    ];
}

function acc_partner_conversion(int $face, float $inRate, float $outRate, float $vatRate = 5.0, float $incomeRate = 3.0): array
{
    $paper = acc_paper_split($face);
    $buyback = acc_rate_amount($face, $inRate);
    $issueVat = acc_rate_amount($face, $vatRate);
    $issueIncome = acc_rate_amount($face, $incomeRate);
    $issueTax = $issueVat + $issueIncome;
    return [
        'face_amount' => $face,
        'net_amount' => $paper['net_amount'],
        'paper_vat' => $paper['paper_vat'],
        'buyback_amount' => $buyback,
        'issue_vat' => $issueVat,
        'issue_income' => $issueIncome,
        'outbound_tax' => $issueTax,
        'receipt_due' => $buyback,
        'pnl_before_receipt' => -$issueTax,
        'inbound_tax_rate' => $inRate,
        'outbound_tax_rate' => $outRate,
        'outbound_vat_rate' => $vatRate,
        'outbound_income_tax_rate' => $incomeRate,
        'spread_rate' => max(0, $outRate - $inRate),
    ];
}

function acc_partner_entry_conversion(array $row, float $inRate, float $outRate, float $vatRate = 5.0, float $incomeRate = 3.0): array
{
    $type = (string)($row['entry_type'] ?? '');
    $purpose = (string)($row['purpose'] ?? '');
    $face = (int)($row['face_amount'] ?? 0);
    $settle = (int)($row['settle_amount'] ?? 0);
    $convFace = $type === 'settle' ? $settle : $face;
    $conv = acc_partner_conversion($convFace, $inRate, $outRate, $vatRate, $incomeRate);
    $buyback = 0;
    $receipt = 0;
    $issueVat = 0;
    $issueIncome = 0;
    $issueTax = 0;
    if ($type === 'inbound') {
        $buyback = (int)($row['tax_amount'] ?? 0) ?: $conv['buyback_amount'];
        $receipt = 0;
        $conv['issue_vat'] = 0;
        $conv['issue_income'] = 0;
        $conv['outbound_tax'] = 0;
    } elseif ($type === 'outbound' && $purpose === 'reciprocal') {
        $issueVat = $conv['issue_vat'];
        $issueIncome = $conv['issue_income'];
        $issueTax = $conv['outbound_tax'];
        $buyback = 0;
        $receipt = 0;
    } elseif ($type === 'outbound') {
        $issueVat = $conv['issue_vat'];
        $issueIncome = $conv['issue_income'];
        $issueTax = $conv['outbound_tax'];
    } elseif ($type === 'settle') {
        $buyback = $conv['buyback_amount'];
        $receipt = 0;
        $issueVat = $conv['issue_vat'];
        $issueIncome = $conv['issue_income'];
        $issueTax = $conv['outbound_tax'];
    } elseif ($type === 'receipt') {
        $receipt = $face;
        $conv['net_amount'] = 0;
        $conv['paper_vat'] = 0;
    }
    $row['net_amount'] = $conv['net_amount'];
    $row['paper_vat'] = $conv['paper_vat'];
    $row['buyback_amount'] = $buyback;
    $row['issue_vat'] = $issueVat;
    $row['issue_income'] = $issueIncome;
    $row['outbound_tax'] = $issueTax;
    $row['receipt_due_amount'] = $receipt;
    $row['receipt_use'] = $type === 'receipt' ? ($purpose === 'reserve' ? 'reserve' : 'expense') : '';
    return $row;
}

function acc_partner_get(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM invoice_partners WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function acc_partner_summary(PDO $pdo, array $partner): array
{
    $id = (int)$partner['id'];
    $inRate = (float)$partner['inbound_tax_rate'];
    $vatRate = (float)$partner['outbound_vat_rate'];
    $incomeRate = (float)$partner['outbound_income_tax_rate'];
    $outRate = $vatRate + $incomeRate;
    $entries = $pdo->prepare('SELECT * FROM invoice_partner_entries WHERE partner_id = ? ORDER BY entry_date DESC, id DESC');
    $entries->execute([$id]);
    $rows = $entries->fetchAll();
    $inboundTrade = 0;
    $inboundParked = 0;
    $outboundSale = 0;
    $outboundReciprocal = 0;
    $settled = 0;
    $taxSpread = 0;
    $revenue = 0;
    $deduction = 0;
    $credit = 0;
    $offsetUsed = 0;
    $receiptReceived = 0;
    $receiptExpense = 0;
    $receiptReserve = 0;
    foreach ($rows as $row) {
        $type = (string)$row['entry_type'];
        $purpose = (string)$row['purpose'];
        $face = (int)$row['face_amount'];
        if ($type === 'inbound' && $purpose === 'park') $inboundParked += $face;
        if ($type === 'inbound') $inboundTrade += $face;
        if ($type === 'outbound' && $purpose === 'sale') $outboundSale += $face;
        if ($type === 'outbound' && $purpose !== 'sale') $outboundReciprocal += $face;
        if ($type === 'settle') {
            $settled += (int)$row['settle_amount'];
            $taxSpread += (int)$row['tax_spread'];
        }
        if ($type === 'receipt') {
            $receiptReceived += $face;
            if ($purpose === 'reserve') $receiptReserve += $face;
            else $receiptExpense += $face;
        }
        $revenue += (int)$row['revenue_delta'];
        $deduction += (int)$row['revenue_deduction'];
        $credit += (int)$row['credit_delta'];
        if ($type === 'offset') $offsetUsed += $face;
    }
    $linkedIds = [];
    $linkedNumbers = [];
    foreach ($rows as $row) {
        $type = (string)($row['entry_type'] ?? '');
        if ($type !== 'inbound' && $type !== 'outbound') continue;
        $sourceId = (int)($row['source_invoice_id'] ?? 0);
        if ($sourceId > 0) $linkedIds[$sourceId] = true;
        $number = strtoupper(trim((string)($row['invoice_number'] ?? '')));
        if ($number !== '') $linkedNumbers[$number] = true;
    }
    $autoInvoices = [];
    foreach (acc_partner_electronic_invoices($pdo, $partner) as $invoice) {
        $sourceId = (int)($invoice['source_invoice_id'] ?? 0);
        $number = strtoupper(trim((string)($invoice['invoice_number'] ?? '')));
        if ($sourceId > 0 && isset($linkedIds[$sourceId])) continue;
        if ($number !== '' && isset($linkedNumbers[$number])) continue;
        $direction = (string)($invoice['direction'] ?? 'inbound');
        $face = (int)($invoice['face_amount'] ?? 0);
        if ($direction === 'outbound') $outboundReciprocal += $face;
        else $inboundTrade += $face;
        $autoInvoices[] = [
            'id' => 0,
            'partner_id' => $id,
            'entry_type' => $direction,
            'purpose' => $direction === 'outbound' ? 'reciprocal' : 'purchase',
            'entry_date' => $invoice['entry_date'] ?? '',
            'invoice_number' => $invoice['invoice_number'] ?? '',
            'face_amount' => $face,
            'issuer_name' => $invoice['issuer_name'] ?? '',
            'issuer_tax_id' => $invoice['issuer_tax_id'] ?? '',
            'receiver_name' => $invoice['receiver_name'] ?? '',
            'receiver_tax_id' => $invoice['receiver_tax_id'] ?? '',
            'source_invoice_id' => $sourceId,
            'item_note' => '已入帳電子發票，自動帶入往來',
            'auto_from_invoice' => 1,
            'tax_amount' => 0,
            'vat_amount' => 0,
            'income_tax_amount' => 0,
            'settle_amount' => 0,
            'tax_spread' => 0,
            'revenue_delta' => 0,
            'revenue_deduction' => 0,
            'credit_delta' => 0,
        ];
    }
    $rows = array_merge($autoInvoices, $rows);
    $inboundOpen = max(0, $inboundTrade - $settled);
    $outboundOpen = max(0, $outboundReciprocal - $settled);
    $matchable = min($inboundOpen, $outboundOpen);
    $buybackAmount = acc_rate_amount($inboundTrade, $inRate);
    $issueVat = acc_rate_amount($outboundReciprocal, $vatRate);
    $issueIncome = acc_rate_amount($outboundReciprocal, $incomeRate);
    $issueTax = $issueVat + $issueIncome;
    $paper = acc_paper_split($inboundTrade);
    $pendingSpread = acc_rate_amount($matchable, max(0, $outRate - $inRate));
    $receiptDue = max(0, $issueTax - $buybackAmount - $receiptReceived);
    $receiptOpen = $receiptDue;
    $spread = $buybackAmount + $receiptReceived - $issueTax;
    $pnl = $issueTax > 0 ? $spread : 0;
    $pnlStatus = $issueTax <= 0 ? 'pending' : ($pnl > 0 ? 'profit' : ($pnl < 0 ? 'loss' : 'even'));
    foreach ($rows as &$row) {
        $row = acc_partner_entry_conversion($row, $inRate, $outRate, $vatRate, $incomeRate);
        $type = (string)($row['entry_type'] ?? '');
        if ($type === 'inbound' || $type === 'outbound') {
            $direction = $type === 'outbound' ? 'outbound' : 'inbound';
            $parties = acc_partner_parties($pdo, $partner, $direction);
            if (trim((string)($row['issuer_name'] ?? '')) === '') $row['issuer_name'] = $parties['issuer']['name'];
            if (trim((string)($row['issuer_tax_id'] ?? '')) === '') $row['issuer_tax_id'] = $parties['issuer']['tax_id'];
            if (trim((string)($row['receiver_name'] ?? '')) === '') $row['receiver_name'] = $parties['receiver']['name'];
            if (trim((string)($row['receiver_tax_id'] ?? '')) === '') $row['receiver_tax_id'] = $parties['receiver']['tax_id'];
        } else {
            $row['issuer_name'] = '';
            $row['issuer_tax_id'] = '';
            $row['receiver_name'] = '';
            $row['receiver_tax_id'] = '';
        }
    }
    unset($row);
    return [
        'inbound_tax_rate' => $inRate,
        'outbound_tax_rate' => $outRate,
        'outbound_vat_rate' => $vatRate,
        'outbound_income_tax_rate' => $incomeRate,
        'inbound_trade' => $inboundTrade,
        'inbound_net' => $paper['net_amount'],
        'inbound_paper_vat' => $paper['paper_vat'],
        'inbound_parked' => $inboundParked,
        'outbound_sale' => $outboundSale,
        'outbound_reciprocal' => $outboundReciprocal,
        'buyback_amount' => $buybackAmount,
        'issue_vat' => $issueVat,
        'issue_income' => $issueIncome,
        'issue_tax' => $issueTax,
        'settled_amount' => $settled,
        'inbound_open' => $inboundOpen,
        'outbound_open' => $outboundOpen,
        'matchable' => $matchable,
        'tax_spread' => $taxSpread,
        'pending_tax_spread' => $pendingSpread,
        'receipt_due' => $receiptDue,
        'receipt_received' => $receiptReceived,
        'receipt_expense' => $receiptExpense,
        'receipt_reserve' => $receiptReserve,
        'receipt_open' => $receiptOpen,
        'spread' => $spread,
        'pnl' => $pnl,
        'pnl_status' => $pnlStatus,
        'revenue' => $revenue,
        'revenue_deduction' => $deduction,
        'net_revenue' => $revenue - $deduction,
        'credit_balance' => $credit,
        'offset_used' => $offsetUsed,
        'auto_invoice_count' => count($autoInvoices),
        'entries' => $rows,
    ];
}

function acc_partner_insert_entry(PDO $pdo, array $context, array $partner, array $input): array
{
    $type = trim((string)($input['entry_type'] ?? ''));
    if ($type === 'deal' || $type === 'reciprocal_deal') {
        return acc_partner_insert_deal($pdo, $context, $partner, $input);
    }
    $purpose = trim((string)($input['purpose'] ?? ''));
    $face = max(0, (int)($input['face_amount'] ?? 0));
    $date = trim((string)($input['entry_date'] ?? date('Y-m-d')));
    $rawNumber = trim((string)($input['invoice_number'] ?? ''));
    $number = $type === 'receipt'
        ? (function_exists('mb_substr') ? mb_substr($rawNumber, 0, 80) : substr($rawNumber, 0, 80))
        : strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $rawNumber) ?? '');
    $note = trim((string)($input['item_note'] ?? ''));
    $sourceInvoiceId = max(0, (int)($input['source_invoice_id'] ?? 0));
    $direction = $type === 'outbound' ? 'outbound' : ($type === 'inbound' ? 'inbound' : '');
    $parties = $direction !== '' ? acc_partner_parties($pdo, $partner, $direction) : ['issuer' => ['name' => '', 'tax_id' => ''], 'receiver' => ['name' => '', 'tax_id' => '']];
    $issuerName = trim((string)($input['issuer_name'] ?? '')) ?: $parties['issuer']['name'];
    $issuerTax = preg_replace('/\D+/', '', (string)($input['issuer_tax_id'] ?? $parties['issuer']['tax_id'])) ?? '';
    $receiverName = trim((string)($input['receiver_name'] ?? '')) ?: $parties['receiver']['name'];
    $receiverTax = preg_replace('/\D+/', '', (string)($input['receiver_tax_id'] ?? $parties['receiver']['tax_id'])) ?? '';
    $inRate = (float)$partner['inbound_tax_rate'];
    $vatRate = (float)$partner['outbound_vat_rate'];
    $incomeRate = (float)$partner['outbound_income_tax_rate'];
    $outRate = $vatRate + $incomeRate;
    $vat = 0;
    $income = 0;
    $tax = 0;
    $rate = 0;
    $parks = 0;
    $credit = 0;
    $settle = 0;
    $spread = 0;
    $revenue = 0;
    $deduction = 0;
    if ($type === 'inbound') {
        if ($face <= 0) throw new InvalidArgumentException('請輸入發票金額');
        if (!in_array($purpose, ['purchase', 'park'], true)) $purpose = 'purchase';
        $rate = $inRate;
        $tax = acc_rate_amount($face, $inRate);
        if ($purpose === 'park') {
            $parks = 1;
            $credit = $face;
        }
        if ($note === '') {
            $note = $purpose === 'park'
                ? '代管款：這張是他開給我們，不收 8%。'
                : ('收購 2% 已設定為 ' . $tax . '。這張是他開給我們，不收 8%。空白收據金額另外自己填。');
        }
    } elseif ($type === 'outbound') {
        if ($face <= 0) throw new InvalidArgumentException('請輸入發票金額');
        if (!in_array($purpose, ['sale', 'reciprocal'], true)) $purpose = 'sale';
        $rate = $outRate;
        $vat = acc_rate_amount($face, $vatRate);
        $income = acc_rate_amount($face, $incomeRate);
        $tax = $vat + $income;
        if ($purpose === 'sale') {
            $revenue = $face;
            $deduction = $tax;
        } else {
            $deduction = $tax;
        }
        if ($note === '') {
            $note = $purpose === 'sale'
                ? ('銷貨開立負擔 8%＝' . $tax . '（發票稅金 5% ＋ 所得稅 3%）。')
                : ('我們開出去才算 8%＝' . $tax . '（發票稅金 5% ＋ 所得稅 3%）。不是他開給我們的發票跟他收 8%。');
        }
    } elseif ($type === 'offset') {
        $purpose = 'credit_purchase';
        $summary = acc_partner_summary($pdo, $partner);
        if ($face <= 0) throw new InvalidArgumentException('請輸入要扣除的金額');
        if ($face > (int)$summary['credit_balance']) throw new InvalidArgumentException('餘額不足，目前可扣 ' . (int)$summary['credit_balance'] . ' 元');
        $credit = -$face;
        $revenue = $face;
        $note = $note !== '' ? $note : '不開發票，從放在我這邊的發票餘額扣除';
    } elseif ($type === 'receipt') {
        if ($face <= 0) throw new InvalidArgumentException('請填空白收據金額');
        if (!in_array($purpose, ['expense_report', 'reserve'], true)) $purpose = 'expense_report';
        $revenue = $face;
        if ($note === '') {
            $note = $purpose === 'reserve'
                ? '空白收據留底：帳面開銷，日後可再開給別人'
                : '空白收據報會計事務所開銷';
        }
    } else {
        throw new InvalidArgumentException('不支援的往來類型');
    }
    $insert = $pdo->prepare('INSERT INTO invoice_partner_entries
        (partner_id, entry_type, purpose, entry_date, invoice_number, face_amount, tax_rate, vat_amount, income_tax_amount, tax_amount, parks_money, credit_delta, settle_amount, tax_spread, revenue_delta, revenue_deduction, item_note, created_by, issuer_name, issuer_tax_id, receiver_name, receiver_tax_id, source_invoice_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $insert->execute([
        (int)$partner['id'], $type, $purpose, $date, $number, $face, $rate, $vat, $income, $tax,
        $parks, $credit, $settle, $spread, $revenue, $deduction, $note, $context['user'],
        $issuerName, $issuerTax, $receiverName, $receiverTax, $sourceInvoiceId,
    ]);
    return ['entry_id' => (int)$pdo->lastInsertId()];
}

function acc_partner_settle(PDO $pdo, array $context, array $partner, ?int $amount = null, string $entryDate = ''): array
{
    $summary = acc_partner_summary($pdo, $partner);
    $matchable = (int)$summary['matchable'];
    if ($matchable <= 0) throw new InvalidArgumentException('目前沒有可沖帳的對開面額');
    $match = $amount === null ? $matchable : min($matchable, max(0, $amount));
    if ($match <= 0) throw new InvalidArgumentException('目前沒有可沖帳的對開面額');
    $outRate = (float)$summary['outbound_tax_rate'];
    $inRate = (float)$summary['inbound_tax_rate'];
    $spread = acc_rate_amount($match, max(0, $outRate - $inRate));
    $buyback = acc_rate_amount($match, $inRate);
    $date = trim($entryDate) !== '' ? trim($entryDate) : date('Y-m-d');
    $insert = $pdo->prepare('INSERT INTO invoice_partner_entries
        (partner_id, entry_type, purpose, entry_date, invoice_number, face_amount, tax_rate, vat_amount, income_tax_amount, tax_amount, parks_money, credit_delta, settle_amount, tax_spread, revenue_delta, revenue_deduction, item_note, created_by)
        VALUES (?, "settle", "reciprocal", ?, "", ?, ?, 0, 0, ?, 0, 0, ?, ?, 0, 0, ?, ?)');
    $insert->execute([
        (int)$partner['id'], $date, $match, $inRate, $buyback, $match, $spread,
        '對開沖帳：面額 ' . $match . '。收購 2%＝' . $buyback . '。開出去 ' . $outRate . '% 已在銷項認列，不是跟他收 8%。空白收據另計。',
        $context['user'],
    ]);
    return [
        'settle_amount' => $match,
        'buyback_amount' => $buyback,
        'tax_spread' => $spread,
        'receipt_due' => $buyback,
        'entry_id' => (int)$pdo->lastInsertId(),
    ];
}

function acc_partner_insert_deal(PDO $pdo, array $context, array $partner, array $input): array
{
    $legacy = max(0, (int)($input['face_amount'] ?? 0));
    $inFace = max(0, (int)($input['inbound_face_amount'] ?? 0));
    $outFace = max(0, (int)($input['outbound_face_amount'] ?? 0));
    if ($inFace <= 0 && $outFace <= 0 && $legacy > 0) {
        $inFace = $legacy;
        $outFace = $legacy;
    }
    if ($inFace <= 0 && $outFace <= 0) throw new InvalidArgumentException('請填我收到多少，或我開出去多少');
    $date = trim((string)($input['entry_date'] ?? date('Y-m-d')));
    $inNumber = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($input['invoice_number'] ?? '')) ?? '');
    $outNumber = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($input['outbound_invoice_number'] ?? $input['invoice_number'] ?? '')) ?? '');
    $note = trim((string)($input['item_note'] ?? ''));
    $inRate = (float)$partner['inbound_tax_rate'];
    $vatRate = (float)$partner['outbound_vat_rate'];
    $incomeRate = (float)$partner['outbound_income_tax_rate'];
    $outRate = $vatRate + $incomeRate;
    $inConv = $inFace > 0 ? acc_partner_conversion($inFace, $inRate, $outRate, $vatRate, $incomeRate) : null;
    $outConv = $outFace > 0 ? acc_partner_conversion($outFace, $inRate, $outRate, $vatRate, $incomeRate) : null;
    $started = !$pdo->inTransaction();
    if ($started) $pdo->beginTransaction();
    $inbound = ['entry_id' => 0];
    $outbound = ['entry_id' => 0];
    $settle = [];
    try {
        if ($inFace > 0) {
            $inbound = acc_partner_insert_entry($pdo, $context, $partner, [
                'entry_type' => 'inbound',
                'purpose' => 'purchase',
                'entry_date' => $date,
                'invoice_number' => $inNumber,
                'face_amount' => $inFace,
                'issuer_name' => $input['inbound_issuer_name'] ?? '',
                'issuer_tax_id' => $input['inbound_issuer_tax_id'] ?? '',
                'receiver_name' => $input['inbound_receiver_name'] ?? '',
                'receiver_tax_id' => $input['inbound_receiver_tax_id'] ?? '',
                'source_invoice_id' => $input['inbound_source_invoice_id'] ?? 0,
                'item_note' => $note !== '' ? $note : ('實際未稅 ' . $inConv['net_amount'] . '＋稅金 ' . $inConv['paper_vat'] . '，收購 2% 已設定為 ' . $inConv['buyback_amount'] . '。空白收據另外自己填。'),
            ]);
        }
        if ($outFace > 0) {
            $outbound = acc_partner_insert_entry($pdo, $context, $partner, [
                'entry_type' => 'outbound',
                'purpose' => 'reciprocal',
                'entry_date' => $date,
                'invoice_number' => $outNumber,
                'face_amount' => $outFace,
                'issuer_name' => $input['outbound_issuer_name'] ?? '',
                'issuer_tax_id' => $input['outbound_issuer_tax_id'] ?? '',
                'receiver_name' => $input['outbound_receiver_name'] ?? '',
                'receiver_tax_id' => $input['outbound_receiver_tax_id'] ?? '',
                'source_invoice_id' => $input['outbound_source_invoice_id'] ?? 0,
                'item_note' => $note !== '' ? $note : ('我們開出去才負擔 8%＝' . $outConv['outbound_tax'] . '（營業稅 ' . $outConv['issue_vat'] . '＋所得稅 ' . $outConv['issue_income'] . '）'),
            ]);
        }
        if ($inFace > 0 && $outFace > 0) {
            try {
                $settle = acc_partner_settle($pdo, $context, $partner, null, $date);
            } catch (InvalidArgumentException $e) {
                $settle = [];
            }
        }
        if ($started) $pdo->commit();
    } catch (Throwable $error) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return [
        'deal' => true,
        'inbound_face_amount' => $inFace,
        'outbound_face_amount' => $outFace,
        'face_amount' => $inFace ?: $outFace,
        'buyback_amount' => $inConv['buyback_amount'] ?? 0,
        'receipt_due' => $inConv['receipt_due'] ?? 0,
        'issue_tax' => $outConv['outbound_tax'] ?? 0,
        'inbound_id' => $inbound['entry_id'],
        'outbound_id' => $outbound['entry_id'],
    ] + $settle;
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'accounting-parser.php';

