<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'accounting-lib.php';

interface AccountingVendorParser
{
    public function supports(array $message, array $attachments): bool;
    public function parse(array $message, array $attachments): array;
}

function acc_detect_document_type(string $path, string $reportedMime = ''): string
{
    if (!is_file($path)) return 'missing';
    $handle = fopen($path, 'rb');
    $magic = $handle ? (string)fread($handle, 8) : '';
    if (is_resource($handle)) fclose($handle);
    if (str_starts_with($magic, '%PDF')) return 'application/pdf';
    if (str_starts_with($magic, "\x89PNG")) return 'image/png';
    if (str_starts_with($magic, "\xFF\xD8\xFF")) return 'image/jpeg';
    return strtolower(trim($reportedMime)) ?: 'application/octet-stream';
}

function acc_clean_tax_id(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?: '';
}

function acc_clean_invoice_number(string $value): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value) ?: '');
}

function acc_candidate(array $fields, string $companyTaxId): array
{
    $fields['seller_tax_id'] = acc_clean_tax_id((string)($fields['seller_tax_id'] ?? ''));
    $fields['buyer_tax_id'] = acc_clean_tax_id((string)($fields['buyer_tax_id'] ?? ''));
    $fields['invoice_number'] = acc_clean_invoice_number((string)($fields['invoice_number'] ?? ''));
    $fields['net_amount'] = (int)($fields['net_amount'] ?? 0);
    $fields['tax_amount'] = (int)($fields['tax_amount'] ?? 0);
    $fields['total_amount'] = (int)($fields['total_amount'] ?? 0);
    $fields['company_match'] = $fields['buyer_tax_id'] !== '' && hash_equals($companyTaxId, $fields['buyer_tax_id']);
    $fields['amount_check_status'] = ($fields['net_amount'] + $fields['tax_amount'] === $fields['total_amount']) ? 'balanced' : 'mismatch';
    $fields['parse_confidence'] = max(0, min(1, (float)($fields['parse_confidence'] ?? 0)));
    $fields['source_type'] = trim((string)($fields['source_type'] ?? 'gmail')) ?: 'gmail';
    $fields['official_voucher_status'] = trim((string)($fields['official_voucher_status'] ?? 'not_required')) ?: 'not_required';
    if (!$fields['company_match']) {
        $fields['workflow_status'] = 'non_company';
    } elseif ($fields['amount_check_status'] !== 'balanced' || $fields['parse_confidence'] < 0.8) {
        $fields['workflow_status'] = 'exception';
    } elseif ($fields['official_voucher_status'] === 'required_missing') {
        $fields['workflow_status'] = 'needs_official_voucher';
    } else {
        $fields['workflow_status'] = 'pending_review';
    }
    $fields['items'] = array_values(array_filter((array)($fields['items'] ?? []), 'is_array'));
    return $fields;
}

function acc_store_candidate(PDO $pdo, array $candidate): array
{
    $companyTaxId = acc_setting($pdo, 'company_tax_id', ACC_COMPANY_TAX_ID);
    $candidate = acc_candidate($candidate, $companyTaxId);
    $duplicateOf = null;
    if ($candidate['seller_tax_id'] !== '' && $candidate['invoice_number'] !== '') {
        $find = $pdo->prepare("SELECT id FROM electronic_invoices WHERE seller_tax_id=? AND invoice_number=? AND workflow_status<>'duplicate' LIMIT 1");
        $find->execute([$candidate['seller_tax_id'], $candidate['invoice_number']]);
        $duplicateOf = $find->fetchColumn();
        if ($duplicateOf !== false) $candidate['workflow_status'] = 'duplicate';
    }
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare('INSERT INTO electronic_invoices
            (invoice_uuid, seller_name, seller_tax_id, buyer_name, buyer_tax_id, invoice_number, invoice_date,
             random_code, net_amount, tax_amount, total_amount, source_type, company_match, workflow_status,
             official_voucher_status, parse_confidence, amount_check_status, duplicate_of)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([
            acc_uuid(), trim((string)($candidate['seller_name'] ?? '')), $candidate['seller_tax_id'],
            trim((string)($candidate['buyer_name'] ?? '')), $candidate['buyer_tax_id'], $candidate['invoice_number'],
            trim((string)($candidate['invoice_date'] ?? '')), trim((string)($candidate['random_code'] ?? '')),
            $candidate['net_amount'], $candidate['tax_amount'], $candidate['total_amount'], $candidate['source_type'],
            $candidate['company_match'] ? 1 : 0, $candidate['workflow_status'], $candidate['official_voucher_status'],
            $candidate['parse_confidence'], $candidate['amount_check_status'], $duplicateOf === false ? null : $duplicateOf,
        ]);
        $invoiceId = (int)$pdo->lastInsertId();
        $lineInsert = $pdo->prepare('INSERT INTO invoice_line_items (invoice_id, line_no, item_name, quantity, unit, unit_price, discount_amount, line_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($candidate['items'] as $index => $item) {
            $lineInsert->execute([
                $invoiceId, $index + 1, trim((string)($item['item_name'] ?? '')),
                (float)($item['quantity'] ?? 0), trim((string)($item['unit'] ?? '')),
                (int)($item['unit_price'] ?? 0), (int)($item['discount_amount'] ?? 0), (int)($item['line_amount'] ?? 0),
            ]);
        }
        $pdo->commit();
        return ['invoice_id' => $invoiceId, 'workflow_status' => $candidate['workflow_status'], 'duplicate_of' => $duplicateOf === false ? null : $duplicateOf];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function acc_parser_haystack(array $message): string
{
    return (string)($message['sender'] ?? '') . ' ' . (string)($message['subject'] ?? '') . ' '
        . (string)($message['filename'] ?? '') . ' ' . (string)($message['extracted_text'] ?? '') . ' '
        . (string)($message['html_text'] ?? '');
}

function acc_jieyuan_sender_email(): string
{
    return '捷元ebill@gcnc-group.com';
}

function acc_foodpanda_sender_email(): string
{
    return 'info@mail.foodpanda.com.tw';
}

function acc_pchome_sender_email(): string
{
    return 'shoppingsc@pchome.com.tw';
}

function acc_sender_has_email(array $message, string $email): bool
{
    $sender = strtolower((string)($message['sender'] ?? ''));
    $needle = strtolower(trim($email));
    return $needle !== '' && str_contains($sender, $needle);
}

function acc_is_jieyuan_mail(array $message): bool
{
    $sender = strtolower((string)($message['sender'] ?? ''));
    $subject = (string)($message['subject'] ?? '');
    $filename = (string)($message['filename'] ?? '');
    if (acc_sender_has_email($message, acc_jieyuan_sender_email())
        || str_contains($sender, 'ebill@gcnc-group.com')
        || str_contains($sender, 'einvoice@gcnc-group.com')) {
        return true;
    }
    if (str_contains($subject, '捷元電子對帳單') || str_contains($filename, '捷元電子對帳單')) {
        return true;
    }
    return str_contains($subject, '電子對帳單') && (str_contains($subject, '捷元') || str_contains($sender, '捷元'));
}

function acc_is_foodpanda_mail(array $message): bool
{
    $sender = strtolower((string)($message['sender'] ?? ''));
    $hay = strtolower(acc_parser_haystack($message));
    if (acc_sender_has_email($message, acc_foodpanda_sender_email()) || str_contains($sender, 'mail.foodpanda.com.tw')) {
        return true;
    }
    return str_contains($hay, 'foodpanda') || str_contains($hay, '53926705');
}

function acc_is_pchome_mail(array $message): bool
{
    if (acc_is_jieyuan_mail($message)) return false;
    $hay = strtolower(acc_parser_haystack($message));
    if (acc_sender_has_email($message, acc_pchome_sender_email())) return true;
    return str_contains($hay, 'pchome')
        || str_contains($hay, '購物中心')
        || str_contains($hay, '網路家庭')
        || str_contains($hay, '24h購物')
        || str_contains($hay, '16606102');
}

function acc_is_presinet_document(array $message, string $haystack = ''): bool
{
    $filename = (string)($message['filename'] ?? $message['subject'] ?? '');
    if (str_starts_with($filename, '70537075_') || str_contains($filename, '70537075_23365425')) return true;
    if (acc_is_jieyuan_mail($message)) return false;
    if (str_contains($filename, '電子發票證明聯')) return false;
    return str_contains($haystack, '70537075') || str_contains($haystack, '統一數網');
}

final class JieYuanInvoiceParser implements AccountingVendorParser
{
    public function supports(array $message, array $attachments): bool
    {
        if (acc_is_jieyuan_mail($message)) return true;
        $haystack = acc_parser_haystack($message);
        if (acc_is_presinet_document($message, $haystack)) return false;
        return str_contains($haystack, '捷元')
            || str_contains($haystack, '23134543')
            || str_contains($haystack, '電子發票證明聯')
            || preg_match('/捷.{0,3}元股份/u', $haystack) === 1;
    }

    public function parse(array $message, array $attachments): array
    {
        $parsed = acc_parse_taiwan_einvoice_text((string)($message['extracted_text'] ?? ''));
        $parsed['source_type'] = 'jieyuan_pdf';
        $parsed['official_voucher_status'] = 'available';
        $parsed['parse_confidence'] = max(0.9, (float)$parsed['parse_confidence']);
        $name = trim((string)$parsed['seller_name']);
        if ($name === '' || preg_match('/捷.{0,3}元/u', $name) === 1 || str_contains($name, '股份,限')) {
            $parsed['seller_name'] = '捷元股份有限公司';
        }
        $parsed['seller_tax_id'] = $parsed['seller_tax_id'] !== '' ? $parsed['seller_tax_id'] : '23134543';
        $parsed['items'] = (array)($message['items'] ?? []);
        return $parsed;
    }
}

final class FoodpandaInvoiceParser implements AccountingVendorParser
{
    public function supports(array $message, array $attachments): bool
    {
        return acc_is_foodpanda_mail($message);
    }

    public function parse(array $message, array $attachments): array
    {
        $html = preg_replace('/\s+/u', ' ', strip_tags((string)($message['html_text'] ?? ''))) ?: '';
        $parsed = acc_parse_taiwan_einvoice_text($html . "\n" . (string)($message['extracted_text'] ?? ''));
        $parsed['seller_name'] = 'Foodpanda 電子發票平台';
        $parsed['seller_tax_id'] = $parsed['seller_tax_id'] !== '' ? $parsed['seller_tax_id'] : '53926705';
        $parsed['source_type'] = 'foodpanda_email';
        $parsed['official_voucher_status'] = 'required_missing';
        $parsed['pdf_type'] = 'internal_backup_only';
        $parsed['parse_confidence'] = max(0.82, (float)$parsed['parse_confidence']);
        $parsed['items'] = (array)($message['items'] ?? []);
        return $parsed;
    }
}

final class ShoppingMallInvoiceParser implements AccountingVendorParser
{
    public function supports(array $message, array $attachments): bool
    {
        $haystack = strtolower(acc_parser_haystack($message));
        if (acc_is_jieyuan_mail($message) || str_contains($haystack, '捷元') || str_contains($haystack, '23134543') || str_contains($haystack, '電子發票證明聯')) {
            return false;
        }
        if (acc_is_foodpanda_mail($message)) return false;
        return acc_is_pchome_mail($message)
            || str_contains($haystack, '發票明細')
            || str_contains($haystack, '示意圖');
    }

    public function parse(array $message, array $attachments): array
    {
        $text = (string)($message['extracted_text'] ?? '') . "\n" . strip_tags((string)($message['html_text'] ?? ''));
        $parsed = acc_parse_taiwan_einvoice_text($text);
        $isJiefeng = str_contains($text, '捷豐') || str_contains($text, '24951752');
        if ($isJiefeng) {
            if (trim((string)$parsed['seller_name']) === '') $parsed['seller_name'] = '捷豐國際物流股份有限公司';
            $parsed['seller_tax_id'] = $parsed['seller_tax_id'] !== '' ? $parsed['seller_tax_id'] : '24951752';
            $parsed['source_type'] = 'jiefeng_invoice';
        } else {
            if (trim((string)$parsed['seller_name']) === '') $parsed['seller_name'] = '網路家庭國際資訊股份有限公司';
            $parsed['seller_tax_id'] = $parsed['seller_tax_id'] !== '' ? $parsed['seller_tax_id'] : '16606102';
            $parsed['source_type'] = 'pchome_invoice';
        }
        $parsed['official_voucher_status'] = 'available';
        $parsed['parse_confidence'] = max(0.86, (float)$parsed['parse_confidence']);
        $parsed['items'] = (array)($message['items'] ?? []);
        return $parsed;
    }
}

function acc_vendor_parsers(): array
{
    return [new FoodpandaInvoiceParser(), new JieYuanInvoiceParser(), new ShoppingMallInvoiceParser()];
}

function acc_pdf_ascii85_decode(string $data): string
{
    $data = preg_replace('/^<~/', '', $data) ?? $data;
    $data = preg_replace('/~>$/', '', $data) ?? $data;
    $data = preg_replace('/\s+/', '', $data) ?? '';
    $out = '';
    $len = strlen($data);
    $i = 0;
    while ($i < $len) {
        if ($data[$i] === 'z') {
            $out .= "\x00\x00\x00\x00";
            $i++;
            continue;
        }
        $chunk = substr($data, $i, 5);
        $i += strlen($chunk);
        $pad = 5 - strlen($chunk);
        $chunk .= str_repeat('u', $pad);
        $v = 0.0;
        for ($j = 0; $j < 5; $j++) {
            $v = $v * 85.0 + (ord($chunk[$j]) - 33);
        }
        $bytes = chr(((int)($v / 16777216.0)) & 255)
            . chr(((int)($v / 65536.0)) & 255)
            . chr(((int)($v / 256.0)) & 255)
            . chr(((int)$v) & 255);
        if ($pad > 0) $bytes = substr($bytes, 0, 4 - $pad);
        $out .= $bytes;
    }
    return $out;
}

function acc_pdf_inflate(string $data): string
{
    $decoded = @gzuncompress($data);
    if ($decoded !== false) return $decoded;
    if (strlen($data) > 2) {
        $decoded = @gzinflate(substr($data, 2));
        if ($decoded !== false) return $decoded;
    }
    $decoded = @gzinflate($data);
    return $decoded === false ? '' : $decoded;
}

function acc_pdf_unescape_literal(string $value): string
{
    $out = '';
    $len = strlen($value);
    for ($i = 0; $i < $len; $i++) {
        if ($value[$i] !== '\\') {
            $out .= $value[$i];
            continue;
        }
        $next = $value[$i + 1] ?? '';
        if ($next >= '0' && $next <= '7') {
            $oct = $next;
            $i++;
            for ($k = 0; $k < 2; $k++) {
                $c = $value[$i + 1] ?? '';
                if ($c < '0' || $c > '7') break;
                $oct .= $c;
                $i++;
            }
            $out .= chr(octdec($oct) & 255);
            continue;
        }
        $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0c", '(' => '(', ')' => ')', '\\' => '\\'];
        $out .= $map[$next] ?? $next;
        $i++;
    }
    return $out;
}

function acc_pdf_utf16be_to_utf8(string $bin): string
{
    if ($bin === '') return '';
    if (function_exists('mb_convert_encoding')) {
        $utf8 = @mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE');
        if (is_string($utf8) && $utf8 !== '') return str_replace("\xEF\xBB\xBF", '', $utf8);
    }
    $out = '';
    $len = strlen($bin);
    for ($i = 0; $i + 1 < $len; $i += 2) {
        $cp = (ord($bin[$i]) << 8) + ord($bin[$i + 1]);
        if ($cp === 0xFEFF || $cp === 0) continue;
        if ($cp < 0x80) $out .= chr($cp);
        elseif ($cp < 0x800) $out .= chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        else $out .= chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    }
    return $out;
}

function acc_pdf_hex_to_utf8(string $hex): string
{
    $hex = preg_replace('/[^0-9A-Fa-f]/', '', $hex) ?? '';
    if ($hex === '') return '';
    if (strlen($hex) % 2 === 1) $hex = '0' . $hex;
    $dest = @hex2bin($hex);
    if ($dest === false || $dest === '') return '';
    if (strlen($dest) === 1) {
        $code = ord($dest);
        return ($code >= 32 && $code < 127) ? $dest : acc_pdf_utf16be_to_utf8("\x00" . $dest);
    }
    return acc_pdf_utf16be_to_utf8($dest);
}

function acc_pdf_parse_tounicode(string $stream): array
{
    $map = [];
    $add = static function (int $src, string $utf8) use (&$map): void {
        if ($src < 0 || $utf8 === '' || $utf8 === "\x00") return;
        $map[$src] = $utf8;
    };
    if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $stream, $charBlocks)) {
        foreach ($charBlocks[1] as $block) {
            if (!preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $pairs, PREG_SET_ORDER)) continue;
            foreach ($pairs as $pair) $add(hexdec($pair[1]), acc_pdf_hex_to_utf8($pair[2]));
        }
    }
    if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $stream, $rangeBlocks)) {
        foreach ($rangeBlocks[1] as $block) {
            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*\[(.*?)\]/s', $block, $arrays, PREG_SET_ORDER)) {
                foreach ($arrays as $row) {
                    $start = hexdec($row[1]);
                    preg_match_all('/<([0-9A-Fa-f]+)>/', $row[3], $dests);
                    foreach ($dests[1] as $offset => $destHex) $add($start + $offset, acc_pdf_hex_to_utf8($destHex));
                }
            }
            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $ranges, PREG_SET_ORDER)) {
                foreach ($ranges as $row) {
                    $start = hexdec($row[1]);
                    $end = hexdec($row[2]);
                    $destStart = hexdec($row[3]);
                    $destWidth = max(2, strlen($row[3]));
                    if ($end < $start || ($end - $start) > 4096) continue;
                    for ($code = $start; $code <= $end; $code++) {
                        $add($code, acc_pdf_hex_to_utf8(sprintf('%0' . $destWidth . 'X', $destStart + ($code - $start))));
                    }
                }
            }
        }
    }
    return $map;
}

function acc_pdf_ascii_keep(string $bytes): string
{
    return trim((string)preg_replace('/[^\x20-\x7E]+/', ' ', $bytes));
}

function acc_pdf_collect_show_strings(string $decoded, array $cmap): array
{
    $mapped = [];
    $ascii = [];
    $addBytes = static function (string $bytes) use (&$mapped, &$ascii, $cmap): void {
        if ($bytes === '') return;
        $asciiText = acc_pdf_ascii_keep($bytes);
        if ($asciiText !== '') $ascii[] = $asciiText;
        $text = trim(acc_pdf_apply_cmap($bytes, $cmap));
        if ($text !== '') $mapped[] = $text;
        if ($cmap !== [] && preg_match_all('/(?:\x01.){2,}/s', $bytes, $runs)) {
            foreach ($runs[0] as $run) {
                $cidText = trim(acc_pdf_apply_cmap($run, $cmap));
                if ($cidText !== '') $mapped[] = $cidText;
            }
        }
    };
    $add = static function (string $raw) use ($addBytes): void {
        if ($raw === '') return;
        $addBytes(acc_pdf_unescape_literal($raw));
    };
    if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/', $decoded, $literals)) {
        foreach ($literals[0] as $literal) $add(substr($literal, 1, -1));
    }
    if (preg_match_all('/<([0-9A-Fa-f]+)>/', $decoded, $hexes)) {
        foreach ($hexes[1] as $hex) {
            if (strlen($hex) > 1024) continue;
            if (strlen($hex) % 2 === 1) $hex = '0' . $hex;
            $bytes = @hex2bin($hex);
            if (is_string($bytes) && $bytes !== '') $addBytes($bytes);
        }
    }
    if ($cmap !== [] && preg_match_all('/(?:\x01.){2,}/s', $decoded, $cidRuns)) {
        foreach ($cidRuns[0] as $run) {
            $cidText = trim(acc_pdf_apply_cmap($run, $cmap));
            if ($cidText !== '') $mapped[] = $cidText;
        }
    }
    $mapped = array_values(array_filter($mapped, static function (string $text): bool {
        $trim = trim($text);
        return $trim !== '' && preg_match('/^年+$/u', $trim) !== 1;
    }));
    return ['mapped' => $mapped, 'ascii' => $ascii];
}

function acc_pdf_apply_cmap(string $bytes, array $cmap): string
{
    if ($cmap === []) return $bytes;
    $out = '';
    $len = strlen($bytes);
    $i = 0;
    while ($i < $len) {
        if ($i + 1 < $len) {
            $two = (ord($bytes[$i]) << 8) + ord($bytes[$i + 1]);
            if (isset($cmap[$two])) {
                $out .= $cmap[$two];
                $i += 2;
                continue;
            }
        }
        $one = ord($bytes[$i]);
        if (isset($cmap[$one])) $out .= $cmap[$one];
        elseif ($one >= 32 && $one < 127) $out .= $bytes[$i];
        $i++;
    }
    return str_replace("\u{FEFF}", '', $out);
}

function acc_pdf_decode_filters(string $payload, array $filters): string
{
    $data = $payload;
    foreach ($filters as $filter) {
        if ($filter === 'ASCII85Decode') $data = acc_pdf_ascii85_decode($data);
        elseif ($filter === 'ASCIIHexDecode') {
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', $data) ?? '';
            $data = hex2bin($hex) ?: '';
        } elseif ($filter === 'FlateDecode') {
            $data = acc_pdf_inflate($data);
        }
    }
    return $data;
}

function acc_pdf_rc4(string $key, string $data): string
{
    if ($key === '' || $data === '') return $data;
    $s = range(0, 255);
    $j = 0;
    $keyLen = strlen($key);
    for ($i = 0; $i < 256; $i++) {
        $j = ($j + $s[$i] + ord($key[$i % $keyLen])) & 255;
        $tmp = $s[$i];
        $s[$i] = $s[$j];
        $s[$j] = $tmp;
    }
    $i = 0;
    $j = 0;
    $out = '';
    $len = strlen($data);
    for ($k = 0; $k < $len; $k++) {
        $i = ($i + 1) & 255;
        $j = ($j + $s[$i]) & 255;
        $tmp = $s[$i];
        $s[$i] = $s[$j];
        $s[$j] = $tmp;
        $out .= chr(ord($data[$k]) ^ $s[($s[$i] + $s[$j]) & 255]);
    }
    return $out;
}

function acc_pdf_literal_from_dict(string $dict, string $key): string
{
    if (!preg_match('/\/' . preg_quote($key, '/') . '\s*\((?:\\\\.|[^\\\\)])*\)/', $dict, $match)) return '';
    $open = strpos($match[0], '(');
    return acc_pdf_unescape_literal(substr($match[0], $open + 1, -1));
}

function acc_pdf_file_id(string $raw): string
{
    if (!preg_match('/\/ID\s*\[\s*\((?:\\\\.|[^\\\\)])*\)/', $raw, $match)) return '';
    $open = strpos($match[0], '(');
    return acc_pdf_unescape_literal(substr($match[0], $open + 1, -1));
}

function acc_pdf_encryption_key(string $raw, string $password = ''): ?string
{
    if (!preg_match('/\/Encrypt\s+(\d+)\s+\d+\s+R/', $raw, $ref)) return null;
    $obj = (int)$ref[1];
    if (!preg_match('/(?:^|[\r\n])' . $obj . '\s+0\s+obj\s*(<<[\s\S]*?>>)/', $raw, $dictMatch)) return null;
    $dict = $dictMatch[1];
    $revision = preg_match('/\/R\s+(\d+)/', $dict, $m) ? (int)$m[1] : 2;
    if ($revision > 3) return null;
    $owner = acc_pdf_literal_from_dict($dict, 'O');
    $perm = preg_match('/\/P\s+(-?\d+)/', $dict, $m) ? (int)$m[1] : 0;
    $id = acc_pdf_file_id($raw);
    if (strlen($owner) < 32) $owner = str_pad($owner, 32, "\0");
    $pad = hex2bin('28BF4E5E4E758A4164004E56FFFA01082E2E00B6D0683E802F0CA9FE6453697A') ?: '';
    $padded = substr($password . $pad, 0, 32);
    $permBytes = pack('V', $perm);
    $hash = md5($padded . $owner . $permBytes . $id, true);
    $keyLen = 5;
    if ($revision >= 3) {
        $length = preg_match('/\/Length\s+(\d+)/', $dict, $m) ? (int)$m[1] : 128;
        $keyLen = max(5, min(16, (int)floor($length / 8)));
        for ($i = 0; $i < 50; $i++) $hash = md5(substr($hash, 0, $keyLen), true);
    }
    return substr($hash, 0, $keyLen);
}

function acc_pdf_decrypt_object(string $encKey, int $objNum, int $gen, string $data): string
{
    if ($encKey === '' || $data === '') return $data;
    $extra = chr($objNum & 255) . chr(($objNum >> 8) & 255) . chr(($objNum >> 16) & 255)
        . chr($gen & 255) . chr(($gen >> 8) & 255);
    $key = substr(md5($encKey . $extra, true), 0, min(strlen($encKey) + 5, 16));
    return acc_pdf_rc4($key, $data);
}

function acc_pdf_object_num_before(string $raw, int $pos): int
{
    $window = substr($raw, max(0, $pos - 900), min(900, $pos));
    if (!preg_match_all('/(\d+)\s+(\d+)\s+obj/', $window, $matches, PREG_SET_ORDER)) return 0;
    $last = $matches[count($matches) - 1];
    return (int)$last[1];
}

function acc_extract_pdf_text(string $path): string
{
    if (!is_file($path)) return '';
    $raw = (string)file_get_contents($path);
    if ($raw === '') return '';
    $encKey = acc_pdf_encryption_key($raw, '');
    $decodedStreams = [];
    $offset = 0;
    $rawLen = strlen($raw);
    while (($pos = strpos($raw, 'stream', $offset)) !== false) {
        $after = $pos + 6;
        if ($after < $rawLen && $raw[$after] === "\r") $after++;
        if ($after < $rawLen && $raw[$after] === "\n") $after++;
        else {
            $offset = $pos + 6;
            continue;
        }
        $dictStart = strrpos(substr($raw, max(0, $pos - 400), min(400, $pos)), '<<');
        $dict = '';
        if ($dictStart !== false) {
            $abs = max(0, $pos - 400) + $dictStart;
            $dict = substr($raw, $abs, $pos - $abs);
        }
        if (!preg_match('/\/Length\s+(\d+)/', $dict, $lengthMatch)) {
            $offset = $after;
            continue;
        }
        $length = (int)$lengthMatch[1];
        if (
            str_contains($dict, '/Subtype /Image') || str_contains($dict, '/Subtype/Image')
            || str_contains($dict, '/Length1')
        ) {
            $offset = $after + $length;
            continue;
        }
        $payload = substr($raw, $after, $length);
        if ($encKey !== null) {
            $objNum = acc_pdf_object_num_before($raw, $pos);
            if ($objNum > 0) $payload = acc_pdf_decrypt_object($encKey, $objNum, 0, $payload);
        }
        $filters = [];
        if (preg_match('/\/Filter\s*\[\s*([^\]]+)\]/', $dict, $filterMatch)) {
            preg_match_all('/\/(ASCII85Decode|FlateDecode|ASCIIHexDecode)/', $filterMatch[1], $found);
            $filters = $found[1];
        } elseif (preg_match('/\/Filter\s*\/(ASCII85Decode|FlateDecode|ASCIIHexDecode)/', $dict, $filterMatch)) {
            $filters = [$filterMatch[1]];
        }
        $decoded = $filters === [] ? $payload : acc_pdf_decode_filters($payload, $filters);
        $offset = $after + $length;
        if ($decoded !== '') $decodedStreams[] = $decoded;
    }
    $cmap = [];
    foreach ($decodedStreams as $decoded) {
        if (str_contains($decoded, 'beginbfchar') || str_contains($decoded, 'beginbfrange')) {
            $cmap += acc_pdf_parse_tounicode($decoded);
        }
    }
    $mappedChunks = [];
    $asciiChunks = [];
    foreach ($decodedStreams as $decoded) {
        if (str_contains($decoded, 'beginbfchar') || str_contains($decoded, 'begincmap')) continue;
        $hasShow = preg_match('/Tj|TJ/', $decoded) === 1
            || preg_match('/\\([A-Z]{2}\\d{8}\\)/i', $decoded)
            || str_contains($decoded, '總計')
            || str_contains($decoded, '發票');
        if (!$hasShow) continue;
        $found = acc_pdf_collect_show_strings($decoded, $cmap);
        foreach ($found['mapped'] as $piece) $mappedChunks[] = $piece;
        foreach ($found['ascii'] as $piece) $asciiChunks[] = $piece;
    }
    $mappedText = trim(implode("\n", $mappedChunks));
    $asciiText = trim(implode("\n", $asciiChunks));
    $text = trim($mappedText . "\n" . $asciiText);
    $text = str_replace("\xEF\xBB\xBF", '', $text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;
    $text = preg_replace('/\x{FEFF}/u', '', $text) ?? $text;
    if ($text !== '') return $text;
    $bin = acc_find_pdftotext_bin();
    if ($bin === null) return '';
    $tmp = $path . '.acc-text.txt';
    $cmd = escapeshellarg($bin) . ' -enc UTF-8 -layout ' . escapeshellarg($path) . ' ' . escapeshellarg($tmp) . ' 2>&1';
    exec($cmd, $output, $code);
    if ($code !== 0 || !is_file($tmp)) return '';
    $extracted = (string)file_get_contents($tmp);
    @unlink($tmp);
    return trim($extracted);
}

function acc_find_pdftotext_bin(): ?string
{
    static $resolved = false;
    static $bin = null;
    if ($resolved) return $bin;
    $resolved = true;
    $isWin = strncasecmp(PHP_OS, 'WIN', 3) === 0;
    foreach (['pdftotext', 'pdftotext.exe'] as $name) {
        $paths = [];
        $code = 1;
        if ($isWin) {
            exec('where ' . escapeshellarg($name) . ' 2>NUL', $paths, $code);
        } else {
            exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $paths, $code);
        }
        $found = trim((string)($paths[0] ?? ''));
        if ($code === 0 && $found !== '' && is_file($found)) {
            $bin = $found;
            return $bin;
        }
    }
    return null;
}

function acc_parse_money_value(string $value): int
{
    return (int)str_replace([',', ' ', '$', '元'], '', $value);
}

function acc_parse_einvoice_amount_block(string $text): array
{
    $norm = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    $norm = preg_replace('/\R+/u', "\n", $norm) ?? $norm;
    if (preg_match_all('/\)\s*\n\s*([\d,]+)\s*\n\s*([\d,]+)\s*\n\s*([\d,]+)/u', $norm, $matches, PREG_SET_ORDER) !== false) {
        foreach (array_reverse($matches) as $m) {
            $a = acc_parse_money_value($m[1]);
            $b = acc_parse_money_value($m[2]);
            $c = acc_parse_money_value($m[3]);
            if ($a > 0 && $b >= 0 && $c >= 0 && $a === $b + $c) {
                return ['total_amount' => $a, 'net_amount' => $b, 'tax_amount' => $c];
            }
            if ($b > 0 && $a >= 0 && $c >= 0 && $b === $a + $c) {
                return ['total_amount' => $b, 'net_amount' => $a, 'tax_amount' => $c];
            }
        }
    }
    return [];
}

function acc_parse_jieyuan_order_date(string $text): string
{
    if (preg_match('/21-(\d{2})(\d{2})TW/i', $text, $m) !== 1) return '';
    $year = 2000 + (int)$m[1];
    $month = (int)$m[2];
    if ($month < 1 || $month > 12) return '';
    $day = 1;
    if (preg_match('/21-\d{4}TW[^\n]*\n\s*(\d{1,2})\s*\n\s*(\d{1,2})/i', $text, $d) === 1) {
        $first = (int)$d[1];
        $second = (int)$d[2];
        if ($first === $month && $second >= 1 && $second <= 31) $day = $second;
        elseif ($second >= 1 && $second <= 31) $day = $second;
    }
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function acc_parse_chinese_money(string $text): int
{
    if (!preg_match('/([壹貳參叁肆伍陸柒捌玖][零壹貳參叁肆伍陸柒捌玖拾佰仟萬]*)元/u', $text, $match)) return 0;
    $digits = ['零' => 0, '壹' => 1, '貳' => 2, '參' => 3, '叁' => 3, '肆' => 4, '伍' => 5, '陸' => 6, '柒' => 7, '捌' => 8, '玖' => 9];
    $units = ['拾' => 10, '佰' => 100, '仟' => 1000, '萬' => 10000];
    $total = 0;
    $section = 0;
    $num = 0;
    $len = function_exists('mb_strlen') ? mb_strlen($match[1]) : strlen($match[1]);
    $charAt = static function (string $s, int $i): string {
        return function_exists('mb_substr') ? mb_substr($s, $i, 1) : substr($s, $i, 1);
    };
    for ($i = 0; $i < $len; $i++) {
        $ch = $charAt($match[1], $i);
        if (array_key_exists($ch, $digits)) {
            $num = $digits[$ch];
            continue;
        }
        if (!isset($units[$ch])) continue;
        $unit = $units[$ch];
        if ($unit === 10000) {
            $total += ($section + $num) * $unit;
            $section = 0;
            $num = 0;
            continue;
        }
        $section += ($num === 0 ? 1 : $num) * $unit;
        $num = 0;
    }
    return $total + $section + $num;
}

function acc_normalize_invoice_date(string $value): string
{
    $value = trim(str_replace(['.', '年', '月', '日'], ['-', '-', '-', ''], $value), '-/ ');
    if (preg_match('/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})/', $value, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    if (preg_match('/^(\d{2,3})[\/-](\d{1,2})[\/-](\d{1,2})/', $value, $m)) {
        $year = (int)$m[1];
        if ($year < 1911) $year += 1911;
        return sprintf('%04d-%02d-%02d', $year, (int)$m[2], (int)$m[3]);
    }
    return $value;
}

function acc_parse_taiwan_einvoice_text(string $text): array
{
    $plain = trim($text);
    $plain = preg_replace('/\x{FEFF}/u', '', $plain) ?? $plain;
    $compact = preg_replace('/\s+/u', ' ', $plain) ?: '';
    $read = static function (string $pattern) use ($plain, $compact): string {
        if (preg_match($pattern, $plain, $matches)) return trim((string)($matches[1] ?? ''));
        if (preg_match($pattern, $compact, $matches)) return trim((string)($matches[1] ?? ''));
        return '';
    };
    $invoiceNumber = acc_clean_invoice_number($read('/(?:發票號碼)[：:]\s*([A-Z]{2}\s*\d{8})/iu'));
    if ($invoiceNumber === '' && preg_match('/\b([A-Z]{2}\d{8})\b/i', $compact, $m)) {
        $invoiceNumber = acc_clean_invoice_number($m[1]);
    }
    $sellerName = $read('/賣方(?:名稱)?[：:]\s*([^統\d]{2,40})/u');
    $sellerTaxId = acc_clean_tax_id($read('/賣方(?:統編|稅籍)?[：:]\s*(\d{8})/u'));
    if ($sellerName === '' && preg_match('/開立\s*(\d{8})\s*([^\d\r\n發票]{2,40})/u', $plain, $m)) {
        $sellerName = trim($m[2]);
        if ($sellerTaxId === '') $sellerTaxId = acc_clean_tax_id($m[1]);
    }
    if ($sellerName === '' && preg_match('/([\p{Han}]{2,24}(?:股份有限公司|有限公司|行號|商行))/u', $plain, $m)) {
        if (!str_contains($m[1], '寶輝')) $sellerName = trim($m[1]);
    }
    if ($sellerTaxId === '') $sellerTaxId = acc_clean_tax_id($read('/賣方[：:]\s*(\d{8})/u'));
    $buyerName = $read('/買方(?:名稱)?[：:]\s*([^統\d]{2,40})/u');
    $buyerTaxId = acc_clean_tax_id($read('/買方(?:統編|稅籍)?[：:]\s*(\d{8})/u'));
    if ($buyerTaxId === '') $buyerTaxId = acc_clean_tax_id($read('/買方[：:]\s*(\d{8})/u'));
    $date = acc_normalize_invoice_date($read('/(?:發票日期|開立日期|日期)[：:]\s*(\d{4}[\/.\-年]\d{1,2}[\/.\-月]\d{1,2})/u'));
    if ($date === '' && preg_match('/\b(\d{4}[\/.-]\d{1,2}[\/.-]\d{1,2})\b/', $compact, $m)) {
        $date = acc_normalize_invoice_date($m[1]);
    }
    $net = acc_parse_money_value($read('/(?:銷售額合計|銷售額|未稅(?:金額)?|應稅銷售額)[：:]?\s*\$?\s*([\d,]+)/u'));
    $tax = acc_parse_money_value($read('/(?:營業稅稅額|營業稅|稅額)[：:]?\s*\$?\s*([\d,]+)/u'));
    $total = acc_parse_money_value($read('/(?:發票總金額|含稅總額|總計|總額)[：:]?\s*\$?\s*([\d,]+)/u'));
    if ($total <= 0) $total = acc_parse_money_value($read('/總計[^\d]{0,40}([\d,]{1,10})/u'));
    if ($net <= 0) $net = acc_parse_money_value($read('/銷售額合計[^\d]{0,40}([\d,]{1,10})/u'));
    $random = $read('/隨機碼[：:]\s*(\d{4})/u');
    if ($invoiceNumber === '' && preg_match('/\b([A-Z]{2}\d{8})\b/i', $compact, $m)) {
        $invoiceNumber = acc_clean_invoice_number($m[1]);
    }
    if (($random === '' || $total <= 0 || $sellerTaxId === '') && preg_match('/:(\d{4})\s*:(\d{1,7})\s*:(\d{8})/', $compact, $layout)) {
        if ($random === '') $random = $layout[1];
        if ($total <= 0) $total = acc_parse_money_value($layout[2]);
        if ($sellerTaxId === '') $sellerTaxId = acc_clean_tax_id($layout[3]);
    }
    if ($total <= 0 && preg_match('/總計[:：]?\s*\$?\s*([\d,]+)/u', $compact, $m)) {
        $total = acc_parse_money_value($m[1]);
    }
    if ($total <= 0) $total = acc_parse_chinese_money($plain);
    $block = acc_parse_einvoice_amount_block($plain);
    if ($total <= 0 && $block !== []) {
        $total = (int)$block['total_amount'];
        if ($net <= 0) $net = (int)$block['net_amount'];
        if ($tax <= 0) $tax = (int)$block['tax_amount'];
    }
    if ($date === '') $date = acc_parse_jieyuan_order_date($plain);
    if ($sellerTaxId === '' && preg_match('/(?<!買)方[:：]\s*(\d{8})/u', $compact, $m)) {
        $sellerTaxId = acc_clean_tax_id($m[1]);
    }
    $fields = acc_complete_invoice_amounts([
        'seller_name' => $sellerName,
        'seller_tax_id' => $sellerTaxId,
        'buyer_name' => $buyerName,
        'buyer_tax_id' => $buyerTaxId,
        'invoice_number' => $invoiceNumber,
        'invoice_date' => $date,
        'random_code' => $random,
        'net_amount' => $net,
        'tax_amount' => $tax,
        'total_amount' => $total,
        'parse_confidence' => 0.0,
        'source_type' => 'einvoice_pdf',
        'official_voucher_status' => 'available',
        'items' => [],
    ], $plain);
    $score = 0.4;
    if ($fields['invoice_number'] !== '') $score += 0.2;
    if ((int)$fields['total_amount'] > 0) $score += 0.25;
    if ($fields['seller_tax_id'] !== '') $score += 0.1;
    if ($fields['invoice_date'] !== '') $score += 0.05;
    $fields['parse_confidence'] = min(0.95, $score);
    return $fields;
}

function acc_complete_invoice_amounts(array $fields, string $text = ''): array
{
    $net = (int)($fields['net_amount'] ?? 0);
    $tax = (int)($fields['tax_amount'] ?? 0);
    $total = (int)($fields['total_amount'] ?? 0);
    $exempt = preg_match('/課稅別\s*[:：]?\s*(免稅|零稅率)/u', $text)
        || preg_match('/(?:免稅|零稅率)\s*[:：]\s*[1-9]/u', $text);
    if ($total > 0 && $net === 0 && $tax === 0 && !$exempt) {
        $net = (int)round($total / 1.05);
        $tax = $total - $net;
    } elseif ($net > 0 && $tax === 0 && $total === 0 && !$exempt) {
        $tax = (int)round($net * 0.05);
        $total = $net + $tax;
    } elseif ($net > 0 && $total === 0) {
        $total = $net + $tax;
    } elseif ($total > 0 && $net > 0 && $tax === 0) {
        $tax = $total - $net;
    }
    $fields['net_amount'] = $net;
    $fields['tax_amount'] = $tax;
    $fields['total_amount'] = $total;
    return $fields;
}

function acc_merge_parsed_invoice(array $base, array $over): array
{
    foreach ($over as $key => $value) {
        if ($value === null || $value === '') continue;
        if (is_int($value) && $value === 0 && (int)($base[$key] ?? 0) > 0) continue;
        if ($key === 'items' && $value === []) continue;
        $base[$key] = $value;
    }
    $baseConfidence = (float)($base['parse_confidence'] ?? 0);
    $overConfidence = (float)($over['parse_confidence'] ?? 0);
    $base['parse_confidence'] = max($baseConfidence, $overConfidence);
    return acc_complete_invoice_amounts($base, (string)($over['extracted_text'] ?? ''));
}

function acc_parse_invoice_message(array $message, array $attachments = []): array
{
    $text = trim((string)($message['extracted_text'] ?? '') . "\n" . strip_tags((string)($message['html_text'] ?? '')));
    $generic = acc_parse_taiwan_einvoice_text($text);
    foreach (acc_vendor_parsers() as $parser) {
        if (!$parser->supports($message, $attachments)) continue;
        return acc_merge_parsed_invoice($generic, $parser->parse($message, $attachments));
    }
    return $generic;
}

function acc_apply_filename_invoice_hints(array $fields, string $filename, string $text = ''): array
{
    $hay = $filename . "\n" . $text;
    if (trim((string)($fields['invoice_number'] ?? '')) === '') {
        $fields['invoice_number'] = acc_invoice_number_from_filename($filename);
    }
    if (trim((string)($fields['invoice_date'] ?? '')) === ''
        && preg_match('/[A-Z]{2}\d{8}-(\d{4})(\d{2})(\d{2})-\d{6}/i', $filename, $m)
    ) {
        $fields['invoice_date'] = sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    $looksJieyuanMail = acc_is_jieyuan_mail(['sender' => '', 'subject' => $filename, 'filename' => $filename]);
    $looksTongyi = !$looksJieyuanMail && (
        str_starts_with($filename, '70537075_')
        || str_contains($filename, '70537075_23365425')
        || (
            !str_contains($filename, '電子發票證明聯')
            && (str_contains($hay, '70537075') || str_contains($hay, '統一數網'))
        )
    );
    $looksJieyuan = !$looksTongyi && (
        $looksJieyuanMail
        || str_contains($filename, '電子發票證明聯')
        || str_contains($hay, '23134543')
        || str_contains($hay, '捷元電子對帳單')
        || str_contains($hay, '捷元股份')
        || preg_match('/捷.{0,3}元股份/u', $hay) === 1
    );
    if ($looksJieyuan) {
        $name = trim((string)($fields['seller_name'] ?? ''));
        if ($name === '' || preg_match('/捷.{0,3}元/u', $name) === 1) {
            $fields['seller_name'] = '捷元股份有限公司';
        }
        if (trim((string)($fields['seller_tax_id'] ?? '')) === '') $fields['seller_tax_id'] = '23134543';
        $fields['source_type'] = 'jieyuan_pdf';
    }
    $looksMall = !$looksJieyuan && !$looksTongyi && (
        preg_match('/pchome|購物中心|網路家庭|24h購物|16606102|shoppingsc@pchome\.com\.tw/iu', $hay) === 1
    );
    if ($looksMall && trim((string)($fields['seller_tax_id'] ?? '')) === '') {
        if (trim((string)($fields['seller_name'] ?? '')) === '') $fields['seller_name'] = '網路家庭國際資訊股份有限公司';
        $fields['seller_tax_id'] = '16606102';
        $fields['source_type'] = 'pchome_invoice';
    }
    return acc_complete_invoice_amounts($fields, $text);
}

function acc_parse_invoice_from_pdf(string $path, string $filename = '', array $messageMeta = []): array
{
    $filename = $filename !== '' ? $filename : basename($path);
    $text = acc_extract_pdf_text($path);
    $parsed = acc_parse_invoice_message([
        'subject' => (string)($messageMeta['subject'] ?? $filename),
        'sender' => (string)($messageMeta['sender'] ?? ''),
        'filename' => $filename,
        'extracted_text' => $text,
        'html_text' => $text,
    ]);
    return acc_apply_filename_invoice_hints($parsed, $filename, $text);
}

